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

		$sql = "SELECT r.*, (SELECT COUNT(*) FROM {$lines} ri WHERE ri.rental_id = r.id) AS item_count
				FROM {$rentals} r
				WHERE " . implode( ' AND ', $where ) . '
				ORDER BY r.date_from DESC, r.id DESC';
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}
		return $wpdb->get_results( $sql ) ?: [];
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$rental = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . Schema::table( 'rentals' ) . ' WHERE id = %d',
			$id
		) );
		if ( ! $rental ) {
			return null;
		}
		$rental->items = $wpdb->get_results( $wpdb->prepare(
			'SELECT ri.*, i.name AS item_name, i.inventory_number
			 FROM ' . Schema::table( 'rental_items' ) . ' ri
			 LEFT JOIN ' . Schema::table( 'items' ) . ' i ON i.id = ri.item_id
			 WHERE ri.rental_id = %d
			 ORDER BY ri.id ASC',
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
			return new WP_Error( 'pp_missing_borrower', __( 'Name des Leihers fehlt.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		if ( ! Availability::is_valid_range( $data['date_from'] ?? '', $data['date_to'] ?? '' ) ) {
			return new WP_Error( 'pp_invalid_dates', __( 'Ungültiger Zeitraum.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		if ( ! $items ) {
			return new WP_Error( 'pp_no_items', __( 'Mindestens eine Position erforderlich.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		// Verfügbarkeits-Guard über alle Positionen (gleiche Items zusammenzählen).
		$wanted = [];
		foreach ( $items as $line ) {
			$item_id = (int) ( $line['item_id'] ?? 0 );
			$qty     = max( 1, (int) ( $line['quantity'] ?? 1 ) );
			if ( ! $item_id ) {
				return new WP_Error( 'pp_invalid_line', __( 'Ungültige Position.', 'project-prepper' ), [ 'status' => 400 ] );
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
						/* translators: 1: Artikelname, 2: verfügbare Menge */
						__( '"%1$s" ist im Zeitraum nur %2$d× verfügbar.', 'project-prepper' ),
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
	 * @return true|WP_Error
	 */
	public static function set_status( int $id, string $status ) {
		global $wpdb;

		$rental = self::get( $id );
		if ( ! $rental ) {
			return new WP_Error( 'pp_not_found', __( 'Verleih nicht gefunden.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( ! in_array( $status, self::TRANSITIONS[ $rental->status ] ?? [], true ) ) {
			return new WP_Error(
				'pp_invalid_transition',
				sprintf(
					/* translators: 1: aktueller Status, 2: Zielstatus */
					__( 'Statuswechsel von "%1$s" zu "%2$s" ist nicht erlaubt.', 'project-prepper' ),
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
