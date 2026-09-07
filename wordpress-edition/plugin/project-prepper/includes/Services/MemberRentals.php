<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Schema;
use ProjectPrepper\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Externe Verleihe (Rentals) im Member-Portal — App-Pendant `rentals`.
 *
 * Mitglieder verleihen Equipment an Externe (Personen außerhalb der Plattform).
 * Diese Schicht prüft selbst, welche Artikel dabei überhaupt wählbar sind
 * (RLS-Ersatz) — im Solo-Arbeitsbereich die eigenen, im Gruppen-Arbeitsbereich
 * der Kollektiv-Pool.
 *
 * Seit v0.41.0 (User-Wunsch) darf im GRUPPEN-Arbeitsbereich auch Equipment aus
 * dem Kollektiv-Pool verliehen werden — derselbe Pool, der im Projekt buchbar
 * ist (mit der Gruppe geteilte Artikel). Die Multi-Owner-Frage ist damit nicht
 * verschwunden, sondern beantwortet wie bei Projekt-Buchungen: Fremde Artikel
 * mit `requires_approval` landen als Position mit `approval_status = 'pending'`,
 * und nur der EIGENTÜMER gibt sie frei ({@see RentalApprovals}). Solange eine
 * Position offen ist, lässt sich der Verleih nicht ausgeben.
 *
 * Ein im GRUPPEN-Arbeitsbereich angelegter Verleih hängt außerdem am Kollektiv
 * (`owner_group_id`), solange der Betreiber {@see Settings::collective_rentals_visible}
 * nicht abschaltet — davor war er ausschließlich persönlich und
 * damit für alle anderen unsichtbar: weder in ihrer Verleih-Liste noch im
 * Kalender noch im iCal-Abo tauchte auf, dass Kollektiv-Equipment außer Haus
 * geht. Verantwortlich bleibt trotzdem der Anleger (`owner_user_id`): nur er
 * bearbeitet, gibt aus und storniert; die übrigen Mitglieder sehen den Vorgang
 * lesend ({@see visible}).
 *
 * Zweiter Sichtbarkeitsweg, unabhängig vom Arbeitsbereich: Wer einen Artikel
 * BEISTEUERT, sieht den Verleih ebenfalls ({@see Rentals::for_item_owner}) —
 * auch wenn seine Freigabe keine Zustimmung verlangt und ihn deshalb niemand
 * gefragt hat.
 *
 * Offen und bewusst nicht mitentschieden: die Verteilung der Einnahmen — der
 * Tagessatz je Position kommt aus der Freigabe des Eigentümers, wohin das Geld
 * fließt, klärt das Kollektiv außerhalb der Software.
 *
 * Die eigentliche Verfügbarkeits-/Status-/Abrechnungslogik liegt im
 * {@see Rentals}-Service.
 */
class MemberRentals {

	/** Persönliche Verleihe des Mitglieds (Solo-Arbeitsbereich). */
	public static function for_owner( int $user_id ): array {
		return Rentals::all( [ 'owner_user_id' => $user_id ] );
	}

	/**
	 * Alle vom Mitglied selbst angelegten Verleihe — persönliche UND im Namen
	 * eines Kollektivs. Sie bleiben in jedem Arbeitsbereich sichtbar: sonst
	 * verschwände ein Gruppen-Verleih beim Umschalten auf Solo und niemand
	 * könnte ihn mehr zurückbuchen.
	 */
	public static function authored_by( int $user_id ): array {
		return Rentals::all( [ 'owner_user_id' => $user_id, 'any_workspace' => true ] );
	}

	/**
	 * Verleihe des aktiven Arbeitsbereichs: Solo die persönlichen, im
	 * Gruppen-Arbeitsbereich die des Kollektivs (auch die der Mitglieder).
	 * Die Mitgliedschaft wird geprüft — eine fremde Gruppen-ID liefert nichts.
	 */
	public static function for_workspace( int $user_id, int $group_id ): array {
		if ( $group_id > 0 ) {
			// Abgeschalteter Betreiber-Schalter: Verleihe bleiben privat, auch
			// bereits angelegte Kollektiv-Vorgänge verschwinden aus der Gruppensicht.
			return ( Settings::collective_rentals_visible() && Groups::is_member( $group_id, $user_id ) )
				? Rentals::all( [ 'owner_group_id' => $group_id ] )
				: [];
		}
		return self::for_owner( $user_id );
	}

	/**
	 * Alles, was das Mitglied sehen darf — Grundlage für Liste, Kalender und
	 * iCal-Abo. Drei Wege, in dieser Rangfolge (der erste gewinnt je Verleih):
	 *
	 *   `own`        eigener Vorgang → bearbeiten/ausgeben/stornieren erlaubt
	 *   `group`      Verleih des aktiven Kollektivs, angelegt von jemand anderem
	 *   `item_owner` fremder Verleih, in dem eigenes Equipment steckt
	 *
	 * Jede Zeile trägt `pp_can_edit` (bool) und `pp_relation` (einer der drei
	 * Schlüssel); nur `own` darf schreiben.
	 *
	 * @return array<object>
	 */
	public static function visible( int $user_id, int $group_id = 0 ): array {
		return self::collect( $user_id, $group_id > 0 ? [ $group_id ] : [] );
	}

	/**
	 * Wie {@see visible}, aber über ALLE Kollektive des Mitglieds — für Ausgaben
	 * ohne Arbeitsbereich-Umschalter, allen voran das iCal-Abo: Ein Abonnement
	 * kann nicht wissen, welchen Arbeitsbereich jemand gerade offen hat.
	 *
	 * @return array<object>
	 */
	public static function visible_everywhere( int $user_id ): array {
		return self::collect( $user_id, Groups::user_group_ids( $user_id ) );
	}

	/**
	 * Sammelt die drei Sichtbarkeitswege für die übergebenen Kollektive,
	 * dedupliziert (der erste Weg gewinnt) und sortiert wie die Listen-Queries.
	 *
	 * @param int[] $group_ids Kollektive, deren Verleihe mitgelesen werden dürfen.
	 * @return array<object>
	 */
	private static function collect( int $user_id, array $group_ids ): array {
		$seen = [];
		$out  = [];
		$add  = static function ( array $rows, string $relation ) use ( &$seen, &$out, $user_id ): void {
			foreach ( $rows as $row ) {
				$id = (int) $row->id;
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ]      = true;
				$own              = (int) ( $row->owner_user_id ?? 0 ) === $user_id;
				$row->pp_relation = $own ? 'own' : $relation;
				$row->pp_can_edit = $own;
				$out[]            = $row;
			}
		};

		$add( self::authored_by( $user_id ), 'own' );
		foreach ( $group_ids as $gid ) {
			$add( self::for_workspace( $user_id, (int) $gid ), 'group' );
		}
		$add( Rentals::for_item_owner( $user_id ), 'item_owner' );

		usort( $out, static function ( $a, $b ) {
			return [ (string) $b->date_from, (int) $b->id ] <=> [ (string) $a->date_from, (int) $a->id ];
		} );
		return $out;
	}

	/**
	 * Darf das Mitglied den Verleih ÄNDERN? Nur der Anleger — auch bei einem
	 * Kollektiv-Verleih: Wer den Vorgang aufgesetzt hat, haftet gegenüber dem
	 * externen Leiher und kassiert die Kaution.
	 */
	public static function owns( ?object $rental, int $user_id ): bool {
		if ( ! $rental ) {
			return false;
		}
		return (int) ( $rental->owner_user_id ?? 0 ) === $user_id;
	}

	/** Darf das Mitglied den Verleih SEHEN (einer der drei Wege aus {@see visible})? */
	public static function may_view( ?object $rental, int $user_id, int $group_id = 0 ): bool {
		if ( ! $rental ) {
			return false;
		}
		if ( self::owns( $rental, $user_id ) ) {
			return true;
		}
		$rental_group = (int) ( $rental->owner_group_id ?? 0 );
		if ( Settings::collective_rentals_visible() && $rental_group > 0 && $rental_group === $group_id
			&& Groups::is_member( $rental_group, $user_id ) ) {
			return true;
		}
		foreach ( (array) ( $rental->items ?? [] ) as $line ) {
			if ( (int) ( $line->item_owner_id ?? 0 ) === $user_id ) {
				return true;
			}
		}
		return false;
	}

	/** Verleih (inkl. Positionen + Abrechnung) nur, wenn er dem Member gehört. */
	public static function get_owned( int $id, int $user_id ): ?object {
		$rental = Rentals::get( $id );
		return self::owns( $rental, $user_id ) ? $rental : null;
	}

	/** Verleih zum Anzeigen — eigener oder mitlesend (Kollektiv/eigenes Equipment). */
	public static function get_visible( int $id, int $user_id, int $group_id = 0 ): ?object {
		$rental = Rentals::get( $id );
		if ( ! self::may_view( $rental, $user_id, $group_id ) ) {
			return null;
		}
		$rental->pp_can_edit = self::owns( $rental, $user_id );
		return $rental;
	}

	/**
	 * KPI-Zähler wie die App-Verleihseite: Reserviert/Ausgegeben/Zurück +
	 * offene Kaution.
	 *
	 * Gezählt wird GENAU die Menge, die auch in der Liste steht ({@see visible}) —
	 * sonst widersprechen sich Kachel und Liste direkt untereinander: die Kachel
	 * sagte „1 reserviert", darunter standen drei Karten.
	 *
	 * Die KAUTION ist die Ausnahme und zählt nur eigene Vorgänge: Geld eines
	 * fremden Verleihs hält jemand anderes, es gehört nicht in die eigene Bilanz.
	 */
	public static function kpis( int $user_id, int $group_id = 0 ): array {
		$rentals = self::visible( $user_id, $group_id );
		$counts  = [ 'reserved' => 0, 'active' => 0, 'returned' => 0, 'cancelled' => 0 ];
		$deposit = 0.0;
		foreach ( $rentals as $r ) {
			if ( isset( $counts[ $r->status ] ) ) {
				++$counts[ $r->status ];
			}
			$mine = (int) ( $r->owner_user_id ?? 0 ) === $user_id;
			if ( $mine && ! in_array( $r->status, [ 'returned', 'cancelled' ], true ) ) {
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
	public static function create( int $user_id, array $data, array $items, array $sets = [], int $group_id = 0 ) {
		if ( ! $user_id || ! current_user_can( Capabilities::COLLECTIVES ) ) {
			return new WP_Error( 'pp_forbidden', __( 'You are not allowed to add rentals.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$items = self::expand_sets( $user_id, $sets, $items, (string) ( $data['date_from'] ?? '' ), (string) ( $data['date_to'] ?? '' ), 0, $group_id );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		// Der Guard gibt die Positionen ANGEREICHERT zurück (approval_status/
		// requested_by je Zeile) — deshalb das Ergebnis weiterverwenden.
		$items = self::guard_items_lendable( $user_id, $group_id, $items );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$data['owner_user_id'] = $user_id;
		// Im Gruppen-Arbeitsbereich gehört der Vorgang AUCH dem Kollektiv, damit
		// die anderen Mitglieder ihn in Liste, Kalender und iCal-Abo sehen. Das
		// Schreibrecht bleibt trotzdem beim Anleger ({@see owns}). Der Betreiber
		// kann das instanzweit abschalten — dann bleibt jeder Verleih privat.
		$data['owner_group_id'] = ( Settings::collective_rentals_visible() && $group_id > 0
			&& Groups::is_member( $group_id, $user_id ) ) ? $group_id : null;
		return Rentals::create( $data, $items );
	}

	/**
	 * Eigenen Verleih bearbeiten (Header + Positionen) — App-Pendant rentals/[id].
	 * Positionen müssen weiterhin dem Mitglied gehören; Status-/Verfügbarkeits-
	 * Regeln (nur reserved/active, exclude_rental_id) macht {@see Rentals::update}.
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, int $user_id, array $data, ?array $items = null, array $sets = [], int $group_id = 0, ?string $expect = null ) {
		$rental = self::get_owned( $id, $user_id );
		if ( ! $rental ) {
			return new WP_Error( 'pp_forbidden', __( 'This rental is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		// Maßgeblich ist der Arbeitsbereich des VERLEIHS, nicht der gerade offene:
		// Ein Kollektiv-Verleih, der aus dem Solo-Arbeitsbereich bearbeitet wird,
		// würde sonst gegen den Solo-Pool geprüft — die Kollektiv-Positionen
		// gälten plötzlich als fremd und jedes Speichern schlüge fehl.
		// Maßgeblich ist der Arbeitsbereich des VERLEIHS, nie der gerade offene.
		// Sonst passiert beides: Ein persönlicher Verleih, aus dem Gruppen-
		// Arbeitsbereich gespeichert, würde gegen den Kollektiv-Pool geprüft und
		// verlöre seine Positionen; und wer ein Kollektiv verlassen hat, könnte
		// über seinen alten Vorgang Equipment eines ANDEREN Kollektivs einbuchen.
		// Abwickeln (zurückgeben, stornieren) bleibt in jedem Fall möglich — der
		// Anleger haftet weiter.
		$rental_group = (int) ( $rental->owner_group_id ?? 0 );
		$group_id     = ( $rental_group > 0 && Groups::is_member( $rental_group, $user_id ) ) ? $rental_group : 0;
		if ( null !== $items ) {
			// Zeitraum für die Set-Prüfung: neue Werte, sonst der bestehende
			// Verleih-Zeitraum; der eigene Verleih wird dabei ausgenommen.
			$items = self::expand_sets(
				$user_id,
				$sets,
				$items,
				(string) ( ! empty( $data['date_from'] ) ? $data['date_from'] : $rental->date_from ),
				(string) ( ! empty( $data['date_to'] ) ? $data['date_to'] : $rental->date_to ),
				$id,
				$group_id
			);
			if ( is_wp_error( $items ) ) {
				return $items;
			}
			// Bisherige Mengen je Positions-ID + ob sich der Zeitraum ändert — beides
			// entscheidet, ob eine bereits erteilte Freigabe erneut eingeholt wird.
			$existing_qty = [];
			foreach ( (array) $rental->items as $ex ) {
				$existing_qty[ (int) $ex->id ] = (int) $ex->quantity;
			}
			$period_changed = ( ! empty( $data['date_from'] ) && (string) $data['date_from'] !== (string) $rental->date_from )
				|| ( ! empty( $data['date_to'] ) && (string) $data['date_to'] !== (string) $rental->date_to );
			$items = self::guard_items_lendable( $user_id, $group_id, $items, $existing_qty, $period_changed );
			if ( is_wp_error( $items ) ) {
				return $items;
			}
		}
		// Owner-Felder sind nicht editierbar.
		unset( $data['owner_user_id'], $data['owner_group_id'] );
		return Rentals::update( $id, $data, $items, $expect );
	}

	/** Status setzen — nur am eigenen Verleih. */
	public static function set_status( int $id, int $user_id, string $status ) {
		if ( ! self::get_owned( $id, $user_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This rental is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		// Ausgabe erst, wenn ALLE Eigentümer zugestimmt haben — sonst würde fremdes
		// Equipment das Haus verlassen, bevor sein Eigentümer gefragt wurde. Andere
		// Übergänge (zurückgeben, stornieren) bleiben jederzeit möglich.
		if ( 'active' === $status && RentalApprovals::has_pending( $id ) ) {
			return new WP_Error(
				'pp_rental_pending',
				__( 'This rental still has items waiting for their owner’s approval. You can hand it out once every owner has decided.', 'project-prepper' ),
				[ 'status' => 409 ]
			);
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
	public static function lendable_items( int $user_id, int $group_id = 0 ): array {
		$out = [];
		if ( $group_id > 0 ) {
			// Guard an der Quelle: items_shared_with_group() filtert nur nach
			// group_id und verlässt sich darauf, dass der Aufrufer die
			// Mitgliedschaft prüft. Diese Prüfung hier zu wiederholen kostet
			// nichts und macht jeden künftigen Aufrufer sicher.
			if ( ! Groups::is_member( $group_id, $user_id ) ) {
				return [];
			}
			// Gruppen-Arbeitsbereich: derselbe Pool wie die Projekt-Buchung — alle
			// mit dem Kollektiv geteilten Artikel, inklusive der eigenen. Die Zeilen
			// tragen shared_by/requires_approval/share_daily_rate, daraus ergibt sich
			// unten die Freigabepflicht.
			foreach ( MemberInventory::items_shared_with_group( $group_id ) as $row ) {
				$out[ (int) $row->id ] = $row;
			}
			return $out;
		}
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
	public static function lendable_sets( int $user_id, int $group_id = 0 ): array {
		$ids = array_keys( self::lendable_items( $user_id, $group_id ) );
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
	private static function expand_sets( int $user_id, array $sets, array $items, string $from, string $to, int $exclude_rental_id = 0, int $group_id = 0 ) {
		// Wählbare Sets: im Solo-Modus die eigenen, im Gruppen-Modus der Pool.
		$pool = $group_id > 0 ? self::lendable_items( $user_id, $group_id ) : [];
		foreach ( $sets as $set_id => $count ) {
			$set_id = (int) $set_id;
			$count  = max( 1, (int) $count );
			if ( $set_id <= 0 ) {
				continue;
			}
			$allowed = $group_id > 0 ? isset( $pool[ $set_id ] ) : MemberInventory::owns( $user_id, $set_id );
			if ( ! $allowed ) {
				return new WP_Error( 'pp_not_your_item', __( 'You can only lend out your own items or equipment shared with your collective.', 'project-prepper' ), [ 'status' => 403 ] );
			}
			// Freigabepflicht des SETS auf die Teil-Zeilen vererben (docs/07 §4.4:
			// der Set-Share entscheidet, die Teile müssen nicht geteilt sein).
			$set_owner  = $group_id > 0 ? (int) ( $pool[ $set_id ]->owner_user_id ?? $pool[ $set_id ]->shared_by ?? 0 ) : $user_id;
			$set_needs  = $set_owner > 0 && $set_owner !== $user_id && ! empty( $pool[ $set_id ]->requires_approval );
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
				if ( $set_needs ) {
					$line['approval_status'] = 'pending';
					$line['requested_by']    = $user_id;
					// Marker für den Guard: die Teil-Zeile gehört zwar ggf. nicht zum
					// Pool (Teile müssen nicht einzeln geteilt sein), ist aber über das
					// Set legitimiert.
					$line['pp_via_bundle'] = $set_id;
				} elseif ( $group_id > 0 ) {
					$line['pp_via_bundle'] = $set_id;
				}
				$items[] = $line;
			}
		}
		return $items;
	}

	/** Stellt sicher, dass jede Position dem Mitglied gehört. */
	private static function guard_items_lendable( int $user_id, int $group_id, array $items, array $existing = [], bool $period_changed = false ) {
		if ( ! $items ) {
			return new WP_Error( 'pp_no_items', __( 'Pick at least one item.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$lendable = self::lendable_items( $user_id, $group_id );
		$out      = [];
		foreach ( $items as $line ) {
			$item_id = (int) ( $line['item_id'] ?? 0 );
			// Harte Grenze (IDOR): nur Artikel aus dem erlaubten Pool. Im Solo-Modus
			// sind das die eigenen, im Gruppen-Modus die mit dem Kollektiv geteilten.
			// Teil-Zeilen einer Set-Auswahl sind über das SET legitimiert (docs/07 §4.4)
			// — sie müssen selbst nicht im Pool stehen. Freigabe-Status hat expand_sets
			// bereits gesetzt.
			$via_bundle = (int) ( $line['pp_via_bundle'] ?? 0 );
			unset( $line['pp_via_bundle'] );
			if ( $via_bundle > 0 && isset( $lendable[ $via_bundle ] ) ) {
				$out[] = $line;
				continue;
			}
			if ( ! $item_id || ! isset( $lendable[ $item_id ] ) ) {
				return new WP_Error( 'pp_not_your_item', __( 'You can only lend out your own items or equipment shared with your collective.', 'project-prepper' ), [ 'status' => 403 ] );
			}
			$pool_item = $lendable[ $item_id ];
			$owner_id  = (int) ( $pool_item->owner_user_id ?? $pool_item->shared_by ?? 0 );
			// Freigabepflicht exakt wie bei der Projekt-Buchung: fremder Artikel UND
			// die Freigabe des Eigentümers trägt `requires_approval`. Eigene Artikel
			// und frei geteilte gehen sofort durch.
			$needs   = $owner_id > 0 && $owner_id !== $user_id && ! empty( $pool_item->requires_approval );
			$line_id = (int) ( $line['id'] ?? 0 );
			if ( ! $needs ) {
				if ( ! $line_id ) {
					// Neue Zeile ohne Freigabepflicht → ausdrücklich freigegeben.
					$line['approval_status'] = 'approved';
				}
				$out[] = $line;
				continue;
			}
			if ( ! $line_id ) {
				// NEUE freigabepflichtige Position → offen, Eigentümer wird gefragt.
				$line['approval_status'] = 'pending';
				$line['requested_by']    = $user_id;
				$out[]                   = $line;
				continue;
			}
			// BESTEHENDE Position: eine erteilte Freigabe bleibt bestehen — sonst
			// würde jedes Speichern (z. B. Name des Leihers korrigieren) den
			// Eigentümer erneut fragen. Erneut gefragt wird nur bei einer
			// „materiellen" Änderung, die ihn schlechter stellt: mehr Stück oder ein
			// anderer Zeitraum (dieselbe Regel wie BookingApprovals::is_material_change).
			$old_qty  = (int) ( $existing[ $line_id ] ?? 0 );
			$new_qty  = max( 1, (int) ( $line['quantity'] ?? 1 ) );
			if ( $period_changed || ( $old_qty > 0 && $new_qty > $old_qty ) ) {
				$line['approval_status'] = 'pending';
				$line['requested_by']    = $user_id;
			}
			$out[] = $line;
			continue;
		}
		return $out;
	}
}
