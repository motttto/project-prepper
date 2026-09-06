<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use ProjectPrepper\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Verleih-Service: Header + Positionen, Status-Maschine, Verfügbarkeits-Guard.
 *
 * Status-Flow (wie App): reserved → active → returned; cancelled aus reserved/active.
 *
 * Sets (v0.40.0, docs/07 §6): Ein Set wird nie selbst als Position verliehen —
 * die Auswahl wird in {@see MemberRentals::expand_sets} in Teil-Positionen
 * expandiert (Marker `bundle_item_id`). Dadurch greifen Verfügbarkeits-Guard und
 * Abrechnung hier unverändert auf echte Artikel.
 */
class Rentals {

	const STATUSES = [ 'reserved', 'active', 'returned', 'cancelled' ];

	const TRANSITIONS = [
		'reserved' => [ 'active', 'returned', 'cancelled' ],
		'active'   => [ 'returned', 'cancelled' ],
		'returned' => [],
		'cancelled' => [],
	];

	public static function all( array $args = [] ): array {
		global $wpdb;
		$rentals = Schema::table( 'rentals' );
		$lines   = Schema::table( 'rental_items' );

		$where  = [ '1=1' ];
		$params = [];
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'r.status = %s';
			$params[] = $args['status'];
		}
		// Owner-Scoping fürs Member-Portal (RLS-Ersatz): Solo = eigene Verleihe,
		// Gruppe = Verleihe der Gruppe. Ohne owner-Filter = alle (Site-Backend).
		if ( array_key_exists( 'owner_group_id', $args ) && (int) $args['owner_group_id'] > 0 ) {
			$where[]  = 'r.owner_group_id = %d';
			$params[] = (int) $args['owner_group_id'];
		} elseif ( array_key_exists( 'owner_user_id', $args ) ) {
			// `any_workspace` = alles, was diese Person angelegt hat, auch im Namen
			// eines Kollektivs. Ohne das Flag nur die rein persönlichen Verleihe.
			$where[]  = ! empty( $args['any_workspace'] )
				? 'r.owner_user_id = %d'
				: 'r.owner_user_id = %d AND r.owner_group_id IS NULL';
			$params[] = (int) $args['owner_user_id'];
		}

		array_unshift( $params, $lines, $rentals );
		$sql = $wpdb->prepare(
			'SELECT r.*, (SELECT COUNT(*) FROM %i ri WHERE ri.rental_id = r.id) AS item_count
			 FROM %i r
			 WHERE ' . implode( ' AND ', $where ) . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE-Bedingungen sind statische Strings mit Platzhaltern.
			' ORDER BY r.date_from DESC, r.id DESC',
			$params
		);
		return $wpdb->get_results( $sql ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ist oben via prepare() aufgebaut.
	}

	/**
	 * Verleihe, in denen Equipment von $owner_id steckt — unabhängig davon, WER
	 * den Vorgang angelegt hat oder in welchem Arbeitsbereich er hängt.
	 *
	 * Grundlage der Eigentümer-Sicht: Wer einen Artikel beisteuert,
	 * soll sehen, dass sein Gerät außer Haus geht — auch wenn seine Freigabe
	 * keine Zustimmung verlangt und ihn deshalb nie jemand gefragt hat. Ohne das
	 * war ein Verleih fremden Equipments für den Eigentümer komplett unsichtbar,
	 * bis der Artikel am Ausleihtag im eigenen Inventar als „unterwegs" auftauchte.
	 *
	 * @return array<object>
	 */
	public static function for_item_owner( int $owner_id ): array {
		global $wpdb;
		if ( $owner_id <= 0 ) {
			return [];
		}
		$sql = $wpdb->prepare(
			'SELECT r.*, (SELECT COUNT(*) FROM %i ri2 WHERE ri2.rental_id = r.id) AS item_count
			 FROM %i r
			 WHERE EXISTS (
				SELECT 1 FROM %i ri
				JOIN %i i ON i.id = ri.item_id
				WHERE ri.rental_id = r.id AND i.owner_user_id = %d
			 )
			 ORDER BY r.date_from DESC, r.id DESC',
			Schema::table( 'rental_items' ),
			Schema::table( 'rentals' ),
			Schema::table( 'rental_items' ),
			Schema::table( 'items' ),
			$owner_id
		);
		return $wpdb->get_results( $sql ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ist oben via prepare() aufgebaut.
	}

	/**
	 * Einmalige Nachzuordnung von Alt-Verleihen an ihr Kollektiv (v0.42.0).
	 *
	 * Vor v0.139.0 hing JEDER externe Verleih ausschließlich an seinem Anleger —
	 * `owner_group_id` wurde nie geschrieben. Ein Vorgang, der ersichtlich für ein
	 * Kollektiv lief (Equipment aus dem geteilten Pool, verliehen von einem
	 * Mitglied), blieb dadurch für alle anderen unsichtbar. Genau das war der
	 * Anlass für das Update, und Bestandsdaten sollen nicht schlechter dastehen
	 * als alles, was ab jetzt entsteht.
	 *
	 * Zugeordnet wird nur bei EINDEUTIGER Beleglage — alle vier Bedingungen:
	 *   1. der Verleih hat noch gar kein Kollektiv,
	 *   2. der Anleger ist Mitglied der Gruppe,
	 *   3. JEDE Position ist ein Artikel, der mit dieser Gruppe geteilt ist,
	 *   4. mindestens eine Position gehört jemand ANDEREM als dem Anleger.
	 *
	 * Bedingung 4 trennt den Kollektiv-Vorgang vom privaten: Wer nur eigenes
	 * Equipment verliehen hat, behält seinen persönlichen Verleih. Passen mehrere
	 * Gruppen gleich gut, bleibt der Vorgang unangetastet — lieber unzugeordnet
	 * als falsch zugeordnet. Jede Änderung landet im Aktivitätsprotokoll.
	 *
	 * Warum der HEUTIGE Freigabestand als Beleg taugt, obwohl die Vorgänge alt
	 * sind: Fremdes Equipment konnte überhaupt nur über den Kollektiv-Pool in
	 * einen Verleih kommen — im Solo-Arbeitsbereich standen immer ausschließlich
	 * eigene Artikel zur Wahl ({@see MemberRentals::lendable_items}). Eine
	 * fremde Position IST also der Beleg für den Kollektiv-Kontext; die Freigabe
	 * bestätigt nur, welches Kollektiv es war.
	 *
	 * Die Migration ist bewusst strenger als der laufende Betrieb: Seit v0.139.0
	 * wird JEDER im Gruppen-Arbeitsbereich angelegte Verleih zugeordnet, auch
	 * einer mit lauter eigenen Artikeln. Für Altdaten fehlt dieser Kontext — dort
	 * ist fremdes Equipment die einzige belastbare Spur.
	 *
	 * @return int Zahl der zugeordneten Verleihe.
	 */
	public static function backfill_group_owner(): int {
		global $wpdb;

		// Der Betreiber-Schalter entscheidet: Wer die Kollektiv-Sichtbarkeit
		// abgeschaltet hat, bekommt auch keine nachträglichen Zuordnungen.
		if ( ! Settings::collective_rentals_visible() ) {
			return 0;
		}

		$rentals = Schema::table( 'rentals' );
		$lines   = Schema::table( 'rental_items' );
		$items   = Schema::table( 'items' );
		$members = Schema::table( 'group_members' );
		$shares  = Schema::table( 'item_group_shares' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- einmalige Daten-Migration auf Plugin-eigenen Tabellen, Caching nicht anwendbar.
		$candidates = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, owner_user_id FROM %i WHERE owner_group_id IS NULL AND owner_user_id IS NOT NULL',
			$rentals
		) ) ?: [];
		if ( ! $candidates ) {
			return 0;
		}

		$moved = 0;
		foreach ( $candidates as $rental ) {
			$author = (int) $rental->owner_user_id;
			// LEFT JOIN wie in get(): Eine Artikel-Löschung räumt `rental_items`
			// NICHT auf, die Position bleibt Teil des Verleihs (und seiner
			// Abrechnung). Mit INNER JOIN fiele sie hier still heraus und
			// „JEDE Position ist geteilt" wäre gar nicht mehr geprüft.
			//
			// Set-Positionen tragen in `item_id` das TEIL und in `bundle_item_id`
			// das Set. Geteilt wird das SET — die Teile müssen es laut docs/07 §4.4
			// ausdrücklich nicht sein. Für die Freigabe-Prüfung zählt deshalb
			// COALESCE(bundle_item_id, item_id), genau wie im Live-Guard
			// {@see MemberRentals::guard_items_lendable}.
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT ri.item_id,
						COALESCE(ri.bundle_item_id, ri.item_id) AS share_item_id,
						i.id AS item_row,
						i.owner_user_id AS item_owner
				 FROM %i ri LEFT JOIN %i i ON i.id = ri.item_id
				 WHERE ri.rental_id = %d',
				$lines,
				$items,
				(int) $rental->id
			) ) ?: [];
			if ( ! $rows ) {
				continue;
			}
			$share_ids = [];
			$foreign   = false;
			$orphan    = false;
			foreach ( $rows as $row ) {
				if ( null === $row->item_row ) {
					// Verwaiste Position: Der Artikel ist weg, seine Freigaben sind es
					// auch — die Beleglage ist unvollständig, also nicht zuordnen.
					$orphan = true;
					break;
				}
				$share_ids[ (int) $row->share_item_id ] = true;
				if ( (int) $row->item_owner !== $author ) {
					$foreign = true;
				}
			}
			if ( $orphan || ! $foreign ) {
				// Reiner Eigenbedarf bleibt ein persönlicher Verleih (Bedingung 4).
				continue;
			}
			$groups = $wpdb->get_col( $wpdb->prepare(
				'SELECT group_id FROM %i WHERE user_id = %d',
				$members,
				$author
			) ) ?: [];

			$match = [];
			foreach ( $groups as $gid ) {
				$shared = $wpdb->get_col( $wpdb->prepare(
					'SELECT item_id FROM %i WHERE group_id = %d',
					$shares,
					(int) $gid
				) ) ?: [];
				$shared = array_flip( array_map( 'intval', $shared ) );
				$all    = true;
				foreach ( array_keys( $share_ids ) as $item_id ) {
					if ( ! isset( $shared[ $item_id ] ) ) {
						$all = false;
						break;
					}
				}
				if ( $all ) {
					$match[] = (int) $gid;
				}
			}
			if ( 1 !== count( $match ) ) {
				// Keine oder mehrere passende Gruppen → nicht raten.
				continue;
			}
			// Nur zählen und protokollieren, was die Datenbank auch geschrieben hat.
			$ok = $wpdb->update( $rentals, [ 'owner_group_id' => $match[0] ], [ 'id' => (int) $rental->id ], [ '%d' ], [ '%d' ] );
			if ( ! $ok ) {
				continue;
			}
			ActivityLog::log( 'rental_group_backfilled', 'rental', (int) $rental->id, [
				'group_id' => $match[0],
				'owner'    => $author,
				'source'   => 'migration',
			] );
			++$moved;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return $moved;
	}

	/**
	 * Storno-Schlüssel für den Link in der Reservierungs-Mail (v0.141.0).
	 *
	 * Gleiches Schema wie der Beitritts-Link ({@see GroupGovernance::invite_token}):
	 * kein Datenbankfeld, sondern ein HMAC aus Verleih-ID und Leiher-Adresse.
	 * Ändert der Anleger die Adresse, ist der alte Link wertlos — genau richtig,
	 * denn dann hat ihn die falsche Person.
	 */
	public static function cancel_token( object $rental ): string {
		return substr( wp_hash( 'pp_rental_cancel|' . (int) $rental->id . '|' . strtolower( (string) $rental->borrower_email ), 'auth' ), 0, 20 );
	}

	/** Storno-Link für den externen Leiher — leer, wenn keine Mailadresse hinterlegt ist. */
	public static function cancel_url( object $rental ): string {
		if ( empty( $rental->borrower_email ) ) {
			return '';
		}
		return add_query_arg( [
			'action' => 'pp_rental_cancel',
			'rental' => (int) $rental->id,
			'key'    => self::cancel_token( $rental ),
		], admin_url( 'admin-post.php' ) );
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$rental = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'rentals' ),
			$id
		) );
		if ( ! $rental ) {
			return null;
		}
		$rental->items = $wpdb->get_results( $wpdb->prepare(
			'SELECT ri.*, i.name AS item_name, i.inventory_number, i.owner_user_id AS item_owner_id
			 FROM %i ri
			 LEFT JOIN %i i ON i.id = ri.item_id
			 WHERE ri.rental_id = %d
			 ORDER BY ri.id ASC',
			Schema::table( 'rental_items' ),
			Schema::table( 'items' ),
			$id
		) ) ?: [];
		$rental->billing = self::billing( $rental );
		return $rental;
	}

	/**
	 * Kostenrechnung (§9.4): Brutto = Leihgebühr, Fallback Σ Tagessatz × Tage × Menge;
	 * Netto/USt ausgewiesen; Kaution = durchlaufender Posten (steuerfrei).
	 */
	private static function billing( object $rental ): array {
		$days = max( 1, (int) ( ( strtotime( $rental->date_to ) - strtotime( $rental->date_from ) ) / DAY_IN_SECONDS ) + 1 );

		$gross = null !== $rental->rental_fee ? (float) $rental->rental_fee : 0.0;
		if ( ! $gross ) {
			foreach ( $rental->items as $line ) {
				$gross += (float) ( $line->daily_rate ?? 0 ) * $days * (int) $line->quantity;
			}
		}

		$vat_rate = null !== $rental->vat_rate ? (float) $rental->vat_rate : 19.0;
		$net      = $gross / ( 1 + $vat_rate / 100 );

		return [
			'days'     => $days,
			'gross'    => round( $gross, 2 ),
			'net'      => round( $net, 2 ),
			'vat'      => round( $gross - $net, 2 ),
			'vat_rate' => $vat_rate,
			'deposit'  => null !== $rental->deposit_amount ? (float) $rental->deposit_amount : 0.0,
		];
	}

	/**
	 * Verleih anlegen — prüft Verfügbarkeit aller Positionen im Zeitraum.
	 *
	 * @param array $data  Header-Felder.
	 * @param array $items [ ['item_id' => 1, 'quantity' => 2, 'daily_rate' => 5.0], … ]
	 * @return int|WP_Error
	 */
	public static function create( array $data, array $items ) {
		global $wpdb;

		if ( empty( $data['borrower_name'] ) ) {
			return new WP_Error( 'pp_missing_borrower', __( 'Borrower name is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		if ( ! Availability::is_valid_range( $data['date_from'] ?? '', $data['date_to'] ?? '' ) ) {
			return new WP_Error( 'pp_invalid_dates', __( 'Invalid date range.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		if ( ! $items ) {
			return new WP_Error( 'pp_no_items', __( 'At least one line item is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		// Verfügbarkeits-Guard über alle Positionen (gleiche Items zusammenzählen).
		$wanted = [];
		foreach ( $items as $line ) {
			$item_id = (int) ( $line['item_id'] ?? 0 );
			$qty     = max( 1, (int) ( $line['quantity'] ?? 1 ) );
			if ( ! $item_id ) {
				return new WP_Error( 'pp_invalid_line', __( 'Invalid line item.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$wanted[ $item_id ] = ( $wanted[ $item_id ] ?? 0 ) + $qty;
		}
		foreach ( $wanted as $item_id => $qty ) {
			$available = Availability::available_quantity( $item_id, $data['date_from'], $data['date_to'] );
			if ( $qty > $available ) {
				$item = Inventory::get_item( $item_id );
				return new WP_Error(
					'pp_not_available',
					sprintf(
						/* translators: 1: item name, 2: available quantity */
						__( '"%1$s" is only available %2$d× in this period.', 'project-prepper' ),
						$item ? $item->name : "#{$item_id}",
						$available
					),
					[ 'status' => 409 ]
				);
			}
		}

		$now = current_time( 'mysql' );
		$wpdb->insert( Schema::table( 'rentals' ), [
			'rental_number'  => Numbering::next_rental_number(),
			'borrower_name'  => $data['borrower_name'],
			'borrower_email' => $data['borrower_email'] ?? '',
			'borrower_phone' => $data['borrower_phone'] ?? '',
			'borrower_address' => $data['borrower_address'] ?? '',
			'date_from'      => $data['date_from'],
			'date_to'        => $data['date_to'],
			'status'         => 'reserved',
			'deposit_amount' => isset( $data['deposit_amount'] ) && '' !== $data['deposit_amount'] ? (float) $data['deposit_amount'] : null,
			'rental_fee'     => isset( $data['rental_fee'] ) && '' !== $data['rental_fee'] ? (float) $data['rental_fee'] : null,
			'vat_rate'       => isset( $data['vat_rate'] ) && '' !== $data['vat_rate'] ? (float) $data['vat_rate'] : null,
			'notes'          => $data['notes'] ?? '',
			'owner_user_id'  => ! empty( $data['owner_user_id'] ) ? (int) $data['owner_user_id'] : null,
			'owner_group_id' => ! empty( $data['owner_group_id'] ) ? (int) $data['owner_group_id'] : null,
			'created_by'     => get_current_user_id() ?: null,
			'created_at'     => $now,
			'updated_at'     => $now,
		] );
		$rental_id = (int) $wpdb->insert_id;

		foreach ( $items as $line ) {
			$wpdb->insert( Schema::table( 'rental_items' ), [
				'rental_id'      => $rental_id,
				'item_id'        => (int) $line['item_id'],
				'unit_id'        => ! empty( $line['unit_id'] ) ? (int) $line['unit_id'] : null,
				'quantity'       => max( 1, (int) ( $line['quantity'] ?? 1 ) ),
				'daily_rate'     => isset( $line['daily_rate'] ) && '' !== $line['daily_rate'] ? (float) $line['daily_rate'] : null,
				// Set-Herkunft (v0.40.0): gesetzt, wenn die Zeile aus einer
				// Set-Auswahl expandiert wurde — reiner Gruppierungs-Marker.
				'bundle_item_id' => ! empty( $line['bundle_item_id'] ) ? (int) $line['bundle_item_id'] : null,
				// Freigabe (v0.41.0): fremde Artikel aus dem Kollektiv-Pool starten
				// als 'pending', eigene bleiben beim DEFAULT 'approved'.
				'approval_status' => ! empty( $line['approval_status'] ) ? (string) $line['approval_status'] : 'approved',
				'requested_by'    => ! empty( $line['requested_by'] ) ? (int) $line['requested_by'] : null,
			] );
		}

		ActivityLog::log( 'rental_created', 'rental', $rental_id, [
			'borrower' => $data['borrower_name'],
			'from'     => $data['date_from'],
			'to'       => $data['date_to'],
		] );

		/**
		 * Hook-Punkt (ersetzt DB-Trigger): E-Mail-Bestätigung an den Leiher etc.
		 */
		do_action( 'pp_rental_created', $rental_id );

		return $rental_id;
	}

	/**
	 * Verleih bearbeiten (§9.4) — Header-Felder + Positionen mit Diff-Logik.
	 *
	 * Nur in Status reserved/active erlaubt. Die Verfügbarkeit wird für den
	 * (ggf. geänderten) Zeitraum neu geprüft; der eigene Verleih wird dabei
	 * über exclude_rental_id ausgenommen.
	 *
	 * @param int        $id    Verleih.
	 * @param array      $data  Header-Felder — nur übergebene Keys werden geändert.
	 * @param array|null $items Neue Positionsliste oder null (= Positionen unverändert).
	 *                          Zeilen mit `id` werden aktualisiert, ohne `id` eingefügt,
	 *                          nicht mehr enthaltene gelöscht.
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data, ?array $items = null ) {
		global $wpdb;

		$rental = self::get( $id );
		if ( ! $rental ) {
			return new WP_Error( 'pp_not_found', __( 'Rental not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( ! in_array( $rental->status, [ 'reserved', 'active' ], true ) ) {
			return new WP_Error(
				'pp_locked',
				__( 'Only reserved or active rentals can be edited.', 'project-prepper' ),
				[ 'status' => 409 ]
			);
		}
		if ( array_key_exists( 'borrower_name', $data ) && '' === trim( (string) $data['borrower_name'] ) ) {
			return new WP_Error( 'pp_missing_borrower', __( 'Borrower name is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		// Effektiver Zeitraum = neue Werte, Fallback auf Bestand.
		$date_from = ! empty( $data['date_from'] ) ? $data['date_from'] : $rental->date_from;
		$date_to   = ! empty( $data['date_to'] ) ? $data['date_to'] : $rental->date_to;
		if ( ! Availability::is_valid_range( $date_from, $date_to ) ) {
			return new WP_Error( 'pp_invalid_dates', __( 'Invalid date range.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		// Effektive Positionen = neue Liste, Fallback auf Bestand.
		if ( null === $items ) {
			$effective = array_map( static function ( $line ) {
				return [ 'item_id' => (int) $line->item_id, 'quantity' => (int) $line->quantity ];
			}, $rental->items );
		} else {
			if ( ! $items ) {
				return new WP_Error( 'pp_no_items', __( 'At least one line item is required.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$effective = $items;
		}

		// Verfügbarkeits-Guard (eigener Verleih ausgenommen).
		$wanted = [];
		foreach ( $effective as $line ) {
			$item_id = (int) ( $line['item_id'] ?? 0 );
			$qty     = max( 1, (int) ( $line['quantity'] ?? 1 ) );
			if ( ! $item_id ) {
				return new WP_Error( 'pp_invalid_line', __( 'Invalid line item.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$wanted[ $item_id ] = ( $wanted[ $item_id ] ?? 0 ) + $qty;
		}
		foreach ( $wanted as $item_id => $qty ) {
			$available = Availability::available_quantity( $item_id, $date_from, $date_to, $id );
			if ( $qty > $available ) {
				$item = Inventory::get_item( $item_id );
				return new WP_Error(
					'pp_not_available',
					sprintf(
						/* translators: 1: item name, 2: available quantity */
						__( '"%1$s" is only available %2$d× in this period.', 'project-prepper' ),
						$item ? $item->name : "#{$item_id}",
						$available
					),
					[ 'status' => 409 ]
				);
			}
		}

		// Header-Diff: nur übergebene Felder schreiben.
		$fields = [];
		foreach ( [ 'borrower_name', 'borrower_email', 'borrower_phone', 'borrower_address', 'date_from', 'date_to', 'notes' ] as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$fields[ $key ] = (string) $data[ $key ];
			}
		}
		foreach ( [ 'deposit_amount', 'rental_fee', 'vat_rate' ] as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$fields[ $key ] = '' !== $data[ $key ] && null !== $data[ $key ] ? (float) $data[ $key ] : null;
			}
		}
		$fields['updated_at'] = current_time( 'mysql' );
		$wpdb->update( Schema::table( 'rentals' ), $fields, [ 'id' => $id ] );

		// Positions-Diff: vorhandene Zeilen (per id) aktualisieren, neue einfügen, fehlende löschen.
		if ( null !== $items ) {
			$existing = [];
			foreach ( $rental->items as $line ) {
				$existing[ (int) $line->id ] = $line;
			}
			$kept = [];
			foreach ( $items as $line ) {
				$row = [
					'item_id'        => (int) $line['item_id'],
					'unit_id'        => ! empty( $line['unit_id'] ) ? (int) $line['unit_id'] : null,
					'quantity'       => max( 1, (int) ( $line['quantity'] ?? 1 ) ),
					'daily_rate'     => isset( $line['daily_rate'] ) && '' !== $line['daily_rate'] ? (float) $line['daily_rate'] : null,
					'bundle_item_id' => ! empty( $line['bundle_item_id'] ) ? (int) $line['bundle_item_id'] : null,
				];
				// Freigabe-Status nur setzen, wenn der Aufrufer ihn mitgibt — sonst
				// würde ein Speichern des Formulars eine schon erteilte Freigabe
				// überschreiben (der Diff aktualisiert bestehende Zeilen).
				if ( isset( $line['approval_status'] ) ) {
					$row['approval_status'] = (string) $line['approval_status'];
					$row['requested_by']    = ! empty( $line['requested_by'] ) ? (int) $line['requested_by'] : null;
					$row['decided_at']      = null;
				}
				$line_id = (int) ( $line['id'] ?? 0 );
				if ( $line_id && isset( $existing[ $line_id ] ) ) {
					$wpdb->update( Schema::table( 'rental_items' ), $row, [ 'id' => $line_id, 'rental_id' => $id ] );
					$kept[] = $line_id;
				} else {
					$row['rental_id'] = $id;
					$wpdb->insert( Schema::table( 'rental_items' ), $row );
					$kept[] = (int) $wpdb->insert_id;
				}
			}
			foreach ( array_keys( $existing ) as $line_id ) {
				if ( ! in_array( $line_id, $kept, true ) ) {
					$wpdb->delete( Schema::table( 'rental_items' ), [ 'id' => $line_id, 'rental_id' => $id ], [ '%d', '%d' ] );
				}
			}
		}

		ActivityLog::log( 'rental_updated', 'rental', $id, [
			'fields'         => array_values( array_diff( array_keys( $fields ), [ 'updated_at' ] ) ),
			'items_replaced' => null !== $items,
		] );

		/**
		 * Hook-Punkt (ersetzt DB-Trigger): z. B. aktualisierte Bestätigung an den Leiher.
		 */
		do_action( 'pp_rental_updated', $id );

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function set_status( int $id, string $status ) {
		global $wpdb;

		$rental = self::get( $id );
		if ( ! $rental ) {
			return new WP_Error( 'pp_not_found', __( 'Rental not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( ! in_array( $status, self::TRANSITIONS[ $rental->status ] ?? [], true ) ) {
			return new WP_Error(
				'pp_invalid_transition',
				sprintf(
					/* translators: 1: current status, 2: target status */
					__( 'Status change from "%1$s" to "%2$s" is not allowed.', 'project-prepper' ),
					$rental->status,
					$status
				),
				[ 'status' => 409 ]
			);
		}

		// Bedingt auf den GELESENEN Status: Zwei gleichzeitige Aufrufe (Doppelklick,
		// paralleler Storno-POST) lesen sonst beide „reserved", schreiben beide und
		// feuern Log und Hooks doppelt — der Leiher bekäme jede Mail zweimal. Mit
		// der Bedingung gewinnt genau einer, der andere sieht 0 betroffene Zeilen.
		$changed = $wpdb->update(
			Schema::table( 'rentals' ),
			[ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id, 'status' => $rental->status ],
			[ '%s', '%s' ],
			[ '%d', '%s' ]
		);
		if ( 1 !== (int) $changed ) {
			return new WP_Error(
				'pp_invalid_transition',
				__( 'The rental was changed by someone else in the meantime. Please reload.', 'project-prepper' ),
				[ 'status' => 409 ]
			);
		}

		ActivityLog::log( 'rental_status_changed', 'rental', $id, [ 'from' => $rental->status, 'to' => $status ] );

		/**
		 * Hook-Punkt (ersetzt DB-Trigger): E-Mail-Benachrichtigung etc.
		 */
		do_action( 'pp_rental_status_changed', $id, $rental->status, $status );

		return true;
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		$wpdb->delete( Schema::table( 'rental_items' ), [ 'rental_id' => $id ], [ '%d' ] );
		$ok = false !== $wpdb->delete( Schema::table( 'rentals' ), [ 'id' => $id ], [ '%d' ] );
		if ( $ok ) {
			ActivityLog::log( 'rental_deleted', 'rental', $id );
		}
		return $ok;
	}
}
