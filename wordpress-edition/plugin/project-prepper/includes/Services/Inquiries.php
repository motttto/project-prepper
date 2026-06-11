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

	const STATUSES = [ 'new', 'contacted', 'closed' ];

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

	public static function set_status( int $id, string $status ): bool {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$ok = false !== $wpdb->update(
			Schema::table( 'inquiries' ),
			[ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		if ( $ok ) {
			ActivityLog::log( 'inquiry_status_changed', 'inquiry', $id, [ 'to' => $status ] );
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
