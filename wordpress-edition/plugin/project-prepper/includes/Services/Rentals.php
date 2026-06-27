<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Verleih-Service: Header + Positionen, Status-Maschine, Verfügbarkeits-Guard.
 *
 * Status-Flow (wie App): reserved → active → returned; cancelled aus reserved/active.
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
			$where[]  = 'r.owner_user_id = %d AND r.owner_group_id IS NULL';
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
			'SELECT ri.*, i.name AS item_name, i.inventory_number
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
				'rental_id'  => $rental_id,
				'item_id'    => (int) $line['item_id'],
				'unit_id'    => ! empty( $line['unit_id'] ) ? (int) $line['unit_id'] : null,
				'quantity'   => max( 1, (int) ( $line['quantity'] ?? 1 ) ),
				'daily_rate' => isset( $line['daily_rate'] ) && '' !== $line['daily_rate'] ? (float) $line['daily_rate'] : null,
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
					'item_id'    => (int) $line['item_id'],
					'unit_id'    => ! empty( $line['unit_id'] ) ? (int) $line['unit_id'] : null,
					'quantity'   => max( 1, (int) ( $line['quantity'] ?? 1 ) ),
					'daily_rate' => isset( $line['daily_rate'] ) && '' !== $line['daily_rate'] ? (float) $line['daily_rate'] : null,
				];
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

		$wpdb->update(
			Schema::table( 'rentals' ),
			[ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

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
