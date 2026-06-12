<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Anfragen (light, Dok 01 §11): öffentliche Leih-/Projektanfragen aus dem
 * Frontend-Formular, Pipeline-Status im Admin.
 */
class Inquiries {

	// Pipeline wie die App (§11): new → contacted → offer → won | lost.
	// 'closed' bleibt als Legacy-Wert gültig (Bestandsdaten vor v0.7.0), ist aber Endstatus.
	const STATUSES = [ 'new', 'contacted', 'offer', 'won', 'lost', 'closed' ];

	// Erlaubte Status-Übergänge — won/lost/closed sind Endstati.
	const TRANSITIONS = [
		'new'       => [ 'contacted', 'offer', 'won', 'lost' ],
		'contacted' => [ 'offer', 'won', 'lost' ],
		'offer'     => [ 'won', 'lost' ],
		'won'       => [],
		'lost'      => [],
		'closed'    => [],
	];

	public static function all( array $args = [] ): array {
		global $wpdb;
		$where  = [ '1=1' ];
		$params = [];
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		$sql = 'SELECT * FROM ' . Schema::table( 'inquiries' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC';
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}
		return array_map( [ self::class, 'decode' ], $wpdb->get_results( $sql ) ?: [] );
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . Schema::table( 'inquiries' ) . ' WHERE id = %d',
			$id
		) );
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * @param array $data  name (Pflicht), email, phone, message, date_from/to, items [[item_id, quantity, name], …]
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		if ( empty( $data['name'] ) ) {
			return new WP_Error( 'pp_missing_name', __( 'Name fehlt.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$now = current_time( 'mysql' );
		$wpdb->insert( Schema::table( 'inquiries' ), [
			'name'       => $data['name'],
			'email'      => $data['email'] ?? '',
			'phone'      => $data['phone'] ?? '',
			'message'    => $data['message'] ?? '',
			'date_from'  => ! empty( $data['date_from'] ) ? $data['date_from'] : null,
			'date_to'    => ! empty( $data['date_to'] ) ? $data['date_to'] : null,
			'items'      => wp_json_encode( $data['items'] ?? [] ),
			'status'     => 'new',
			'created_at' => $now,
			'updated_at' => $now,
		] );
		$inquiry_id = (int) $wpdb->insert_id;

		ActivityLog::log( 'inquiry_created', 'inquiry', $inquiry_id, [ 'name' => $data['name'] ] );

		/**
		 * Hook-Punkt: E-Mail an den Betreiber etc.
		 */
		do_action( 'pp_inquiry_created', $inquiry_id );

		return $inquiry_id;
	}

	/**
	 * Anfrage → Verleih konvertieren (§11 Konvertierung).
	 *
	 * Übernimmt Name/E-Mail/Telefon/Zeitraum/Items in einen neuen Verleih
	 * (Status reserved, Tagessatz pro Position aus dem Artikel-Stammdatensatz),
	 * setzt die Anfrage auf won und verlinkt beides im Activity-Log.
	 *
	 * @return int|WP_Error Neue Verleih-ID.
	 */
	public static function convert_to_rental( int $id ) {
		$inquiry = self::get( $id );
		if ( ! $inquiry ) {
			return new WP_Error( 'pp_not_found', __( 'Anfrage nicht gefunden.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( in_array( $inquiry->status, [ 'won', 'lost', 'closed' ], true ) ) {
			return new WP_Error( 'pp_already_closed', __( 'Anfrage ist bereits abgeschlossen.', 'project-prepper' ), [ 'status' => 409 ] );
		}
		if ( empty( $inquiry->date_from ) || empty( $inquiry->date_to ) ) {
			return new WP_Error( 'pp_missing_dates', __( 'Anfrage hat keinen Zeitraum — Konvertierung nicht möglich.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		// Items übernehmen — gelöschte Artikel überspringen, Tagessatz aus dem Artikel.
		$items = [];
		foreach ( $inquiry->items as $line ) {
			$item_id = (int) ( $line['item_id'] ?? 0 );
			$item    = $item_id ? Inventory::get_item( $item_id ) : null;
			if ( ! $item ) {
				continue;
			}
			$items[] = [
				'item_id'    => $item_id,
				'quantity'   => max( 1, (int) ( $line['quantity'] ?? 1 ) ),
				'daily_rate' => null !== $item->cost_per_day ? (float) $item->cost_per_day : '',
			];
		}
		if ( ! $items ) {
			return new WP_Error( 'pp_no_items', __( 'Anfrage enthält keine (gültigen) Artikel.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		/* translators: 1: Anfrage-ID, 2: Name */
		$notes = sprintf( __( 'Aus Anfrage #%1$d (%2$s) übernommen.', 'project-prepper' ), $id, $inquiry->name );
		if ( ! empty( $inquiry->message ) ) {
			$notes .= "\n\n" . $inquiry->message;
		}

		$rental_id = Rentals::create( [
			'borrower_name'  => $inquiry->name,
			'borrower_email' => $inquiry->email,
			'borrower_phone' => $inquiry->phone,
			'date_from'      => $inquiry->date_from,
			'date_to'        => $inquiry->date_to,
			'notes'          => $notes,
		], $items );
		if ( is_wp_error( $rental_id ) ) {
			return $rental_id;
		}

		self::set_status( $id, 'won' );
		ActivityLog::log( 'inquiry_converted', 'inquiry', $id, [ 'rental_id' => $rental_id ] );

		/**
		 * Hook-Punkt: z. B. Bestätigungs-Mail an den Anfragenden.
		 */
		do_action( 'pp_inquiry_converted', $id, $rental_id );

		return $rental_id;
	}

	/**
	 * Status setzen — nur erlaubte Übergänge (TRANSITIONS).
	 *
	 * @return bool|WP_Error true bei Erfolg, WP_Error bei ungültigem Übergang.
	 */
	public static function set_status( int $id, string $status ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'pp_invalid_status', __( 'Ungültiger Status.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$inquiry = self::get( $id );
		if ( ! $inquiry ) {
			return new WP_Error( 'pp_not_found', __( 'Anfrage nicht gefunden.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( ! in_array( $status, self::TRANSITIONS[ $inquiry->status ] ?? [], true ) ) {
			return new WP_Error(
				'pp_invalid_transition',
				/* translators: 1: aktueller Status, 2: Ziel-Status */
				sprintf( __( 'Statuswechsel %1$s → %2$s ist nicht erlaubt.', 'project-prepper' ), $inquiry->status, $status ),
				[ 'status' => 409 ]
			);
		}
		$ok = false !== $wpdb->update(
			Schema::table( 'inquiries' ),
			[ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		if ( $ok ) {
			ActivityLog::log( 'inquiry_status_changed', 'inquiry', $id, [ 'from' => $inquiry->status, 'to' => $status ] );
		}
		return $ok;
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		$ok = false !== $wpdb->delete( Schema::table( 'inquiries' ), [ 'id' => $id ], [ '%d' ] );
		if ( $ok ) {
			ActivityLog::log( 'inquiry_deleted', 'inquiry', $id );
		}
		return $ok;
	}

	private static function decode( object $row ): object {
		$row->items = json_decode( $row->items ?? '[]', true ) ?: [];
		return $row;
	}
}
