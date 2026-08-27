<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Externe Verleihe (Rentals) im Member-Portal — App-Pendant `rentals`.
 *
 * Mitglieder verleihen ihr EIGENES Equipment (owner_user_id) an Externe
 * (Personen außerhalb der Plattform). Diese Schicht prüft Eigentum selbst
 * (RLS-Ersatz) und beschränkt die Positionen auf eigene Artikel.
 *
 * Bewusste Abgrenzung: Portal-Verleihe sind SOLO (persönlich). Ein Kollektiv
 * extern verleihen zu lassen ist eine Multi-Owner-/Governance-Frage (wessen
 * Item zu wessen Konditionen, wer haftet) — im WP-Modell hat Inventar keinen
 * `owner_group_id`, Gruppen-Inventar = geteilte Mitglieder-Items. Daher bleibt
 * der externe Gruppen-Verleih dem Site-Backend vorbehalten. Die eigentliche
 * Verfügbarkeits-/Status-/Abrechnungslogik liegt im {@see Rentals}-Service.
 */
class MemberRentals {

	/** Verleihe des Mitglieds (Solo — persönliche externe Verleihe). */
	public static function for_owner( int $user_id ): array {
		return Rentals::all( [ 'owner_user_id' => $user_id ] );
	}

	/** Gehört der Verleih diesem Mitglied (persönlich)? */
	public static function owns( ?object $rental, int $user_id ): bool {
		if ( ! $rental ) {
			return false;
		}
		return (int) ( $rental->owner_user_id ?? 0 ) === $user_id && empty( $rental->owner_group_id );
	}

	/** Verleih (inkl. Positionen + Abrechnung) nur, wenn er dem Member gehört. */
	public static function get_owned( int $id, int $user_id ): ?object {
		$rental = Rentals::get( $id );
		return self::owns( $rental, $user_id ) ? $rental : null;
	}

	/**
	 * KPI-Zähler wie die App-Verleihseite: Reserviert/Ausgegeben/Zurück +
	 * offene Kaution (Summe nicht zurückgegebener/stornierter Verleihe).
	 */
	public static function kpis( int $user_id ): array {
		$rentals = self::for_owner( $user_id );
		$counts  = [ 'reserved' => 0, 'active' => 0, 'returned' => 0, 'cancelled' => 0 ];
		$deposit = 0.0;
		foreach ( $rentals as $r ) {
			if ( isset( $counts[ $r->status ] ) ) {
				++$counts[ $r->status ];
			}
			if ( ! in_array( $r->status, [ 'returned', 'cancelled' ], true ) ) {
				$deposit += (float) ( $r->deposit_amount ?? 0 );
			}
		}
		$counts['deposit_open'] = round( $deposit, 2 );
		$counts['total']        = count( $rentals );
		return $counts;
	}

	/**
	 * Persönlichen Verleih anlegen. Die Positionen MÜSSEN dem Mitglied gehören.
	 * Verfügbarkeit/Status/Nummer macht {@see Rentals::create}.
	 *
	 * @return int|WP_Error
	 */
	public static function create( int $user_id, array $data, array $items, array $sets = [] ) {
		if ( ! $user_id || ! current_user_can( Capabilities::COLLECTIVES ) ) {
			return new WP_Error( 'pp_forbidden', __( 'You are not allowed to add rentals.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$items = self::expand_sets( $user_id, $sets, $items, (string) ( $data['date_from'] ?? '' ), (string) ( $data['date_to'] ?? '' ) );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$guard = self::guard_items_owned( $user_id, $items );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$data['owner_user_id']  = $user_id;
		$data['owner_group_id'] = null;
		return Rentals::create( $data, $items );
	}

	/**
	 * Eigenen Verleih bearbeiten (Header + Positionen) — App-Pendant rentals/[id].
	 * Positionen müssen weiterhin dem Mitglied gehören; Status-/Verfügbarkeits-
	 * Regeln (nur reserved/active, exclude_rental_id) macht {@see Rentals::update}.
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, int $user_id, array $data, ?array $items = null, array $sets = [] ) {
		$rental = self::get_owned( $id, $user_id );
		if ( ! $rental ) {
			return new WP_Error( 'pp_forbidden', __( 'This rental is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( null !== $items ) {
			// Zeitraum für die Set-Prüfung: neue Werte, sonst der bestehende
			// Verleih-Zeitraum; der eigene Verleih wird dabei ausgenommen.
			$items = self::expand_sets(
				$user_id,
				$sets,
				$items,
				(string) ( ! empty( $data['date_from'] ) ? $data['date_from'] : $rental->date_from ),
				(string) ( ! empty( $data['date_to'] ) ? $data['date_to'] : $rental->date_to ),
				$id
			);
			if ( is_wp_error( $items ) ) {
				return $items;
			}
			$guard = self::guard_items_owned( $user_id, $items );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
		}
		// Owner-Felder sind nicht editierbar.
		unset( $data['owner_user_id'], $data['owner_group_id'] );
		return Rentals::update( $id, $data, $items );
	}

	/** Status setzen — nur am eigenen Verleih. */
	public static function set_status( int $id, int $user_id, string $status ) {
		if ( ! self::get_owned( $id, $user_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This rental is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		return Rentals::set_status( $id, $status );
	}

	/** Verleih löschen — nur am eigenen. */
	public static function delete( int $id, int $user_id ) {
		if ( ! self::get_owned( $id, $user_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This rental is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		return Rentals::delete( $id ) ? true : new WP_Error( 'pp_delete_failed', __( 'The rental could not be deleted.', 'project-prepper' ) );
	}

	/**
	 * Artikel, die das Mitglied extern verleihen darf = die eigenen. Ausgemusterte
	 * bleiben draußen; defekte/in Wartung/verschollene stehen weiter in der Liste
	 * (mit Grund-Chip, aber nicht wählbar — {@see Availability::available_quantity}
	 * liefert für sie 0).
	 *
	 * @return array<int,object>
	 */
	public static function lendable_items( int $user_id ): array {
		$out = [];
		foreach ( Inventory::items( [ 'owner_user_id' => $user_id, 'hide_retired' => true ] ) as $row ) {
			$out[ (int) $row->id ] = $row;
		}
		return $out;
	}

	/**
	 * Eigene SETS (Artikel mit Stückliste) → Stückliste. Sets sind seit v0.40.0
	 * regulär verleihbar: ausgewählt wird das Set, verliehen werden serverseitig
	 * seine Teile (docs/07 §6).
	 *
	 * @return array<int,array<object>> set_item_id => Teil-Zeilen.
	 */
	public static function lendable_sets( int $user_id ): array {
		$ids = array_keys( self::lendable_items( $user_id ) );
		return $ids ? Bundles::for_items( $ids ) : [];
	}

	/**
	 * Set-Auswahl in Teil-Positionen expandieren (Verleih-Makro, docs/07 §6):
	 * „2× Lichterkette 30 m" wird zu „6× Glied, 2× Einspeiser", jede Zeile mit
	 * `bundle_item_id`. Das Set selbst wird nie zur Position.
	 *
	 * Alles-oder-nichts: Reicht die Set-Verfügbarkeit im Zeitraum nicht, bricht
	 * der ganze Vorgang mit einer Meldung AUF SET-EBENE ab (statt später an einer
	 * expandierten Teil-Zeile). Die Teile gehören per Definition demselben
	 * Eigentümer wie das Set (Bundles::set_parts) — der Eigentums-Guard greift
	 * anschließend trotzdem über guard_items_owned.
	 *
	 * @param array<int,int> $sets  set_item_id => Anzahl Sets.
	 * @param array          $items Bereits gesammelte Einzel-Positionen.
	 * @return array|WP_Error Zusammengeführte Positionsliste.
	 */
	private static function expand_sets( int $user_id, array $sets, array $items, string $from, string $to, int $exclude_rental_id = 0 ) {
		foreach ( $sets as $set_id => $count ) {
			$set_id = (int) $set_id;
			$count  = max( 1, (int) $count );
			if ( $set_id <= 0 ) {
				continue;
			}
			if ( ! MemberInventory::owns( $user_id, $set_id ) ) {
				return new WP_Error( 'pp_not_your_item', __( 'You can only lend out your own items.', 'project-prepper' ), [ 'status' => 403 ] );
			}
			$parts = Bundles::parts( $set_id );
			if ( ! $parts ) {
				// Stückliste zwischenzeitlich geleert — das „Set" ist keins mehr.
				return new WP_Error( 'pp_bundle_empty', __( 'This set has no parts (any more). Please check its contents.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$free = Availability::is_valid_range( $from, $to )
				? Bundles::available_sets( $parts, $from, $to, 0, $exclude_rental_id )
				: $count;
			if ( $free < $count ) {
				$set = Inventory::get_item( $set_id );
				return new WP_Error(
					'pp_bundle_unavailable',
					sprintf(
						/* translators: 1: set name, 2: number of available sets */
						__( 'Only %2$d× "%1$s" can be assembled from free parts in this period.', 'project-prepper' ),
						$set ? $set->name : "#{$set_id}",
						$free
					),
					[ 'status' => 409 ]
				);
			}
			foreach ( Bundles::expand( $parts, $count, $set_id ) as $line ) {
				$items[] = $line;
			}
		}
		return $items;
	}

	/** Stellt sicher, dass jede Position dem Mitglied gehört. */
	private static function guard_items_owned( int $user_id, array $items ) {
		if ( ! $items ) {
			return new WP_Error( 'pp_no_items', __( 'Pick at least one of your items.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$lendable = self::lendable_items( $user_id );
		foreach ( $items as $line ) {
			$item_id = (int) ( $line['item_id'] ?? 0 );
			if ( ! $item_id || ! isset( $lendable[ $item_id ] ) ) {
				return new WP_Error( 'pp_not_your_item', __( 'You can only lend out your own items.', 'project-prepper' ), [ 'status' => 403 ] );
			}
		}
		return true;
	}
}
