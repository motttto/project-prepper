<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Anfragen im Member-Portal (docs/06 §10.1).
 *
 * Jede Anfrage gehört genau EINEM Arbeitsbereich: Solo (owner_user_id) ODER
 * Gruppe (owner_group_id). Mitglieder pflegen ihre Anfragen vorne (Buchhaltung);
 * der Betreiber sieht im Backend nur Aggregate. Der Lifecycle Anfrage→Projekt
 * folgt in einem weiteren Slice. Diese Schicht prüft Eigentum selbst (RLS-Ersatz).
 */
class MemberInquiries {

	/** Anfragen des aktiven Arbeitsbereichs (Solo: eigene; Gruppe: der Gruppe). */
	public static function for_owner( int $user_id, int $group_id ): array {
		global $wpdb;
		$t = Schema::table( 'inquiries' );
		if ( $group_id > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE owner_group_id = %d ORDER BY id DESC', $t, $group_id ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE owner_user_id = %d AND owner_group_id IS NULL ORDER BY id DESC', $t, $user_id ) );
		}
		return array_map( [ self::class, 'decode' ], $rows ?: [] );
	}

	public static function count_for_owner( int $user_id, int $group_id ): int {
		global $wpdb;
		$t = Schema::table( 'inquiries' );
		if ( $group_id > 0 ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE owner_group_id = %d', $t, $group_id ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE owner_user_id = %d AND owner_group_id IS NULL', $t, $user_id ) );
	}

	public static function owns( object $inq, int $user_id, int $group_id ): bool {
		if ( $group_id > 0 ) {
			return (int) ( $inq->owner_group_id ?? 0 ) === $group_id;
		}
		return (int) ( $inq->owner_user_id ?? 0 ) === $user_id && empty( $inq->owner_group_id );
	}

	public static function get_owned( int $id, int $user_id, int $group_id ): ?object {
		$row = Inquiries::get( $id );
		return ( $row && self::owns( $row, $user_id, $group_id ) ) ? $row : null;
	}

	/**
	 * Neue Anfrage im aktiven Arbeitsbereich anlegen.
	 *
	 * @return int|WP_Error
	 */
	public static function create( int $user_id, int $group_id, array $data ) {
		if ( ! $user_id || ! current_user_can( Capabilities::COLLECTIVES ) ) {
			return new WP_Error( 'pp_forbidden', __( 'You are not allowed to add inquiries.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'pp_missing_name', __( 'Please enter a contact name for the inquiry.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( Schema::table( 'inquiries' ), [
			'name'           => $name,
			'email'          => (string) ( $data['email'] ?? '' ),
			'phone'          => (string) ( $data['phone'] ?? '' ),
			'message'        => (string) ( $data['message'] ?? '' ),
			'date_from'      => ! empty( $data['date_from'] ) ? $data['date_from'] : null,
			'date_to'        => ! empty( $data['date_to'] ) ? $data['date_to'] : null,
			'items'          => wp_json_encode( [] ),
			'status'         => 'new',
			'owner_user_id'  => $group_id > 0 ? null : $user_id,
			'owner_group_id' => $group_id > 0 ? $group_id : null,
			'created_at'     => $now,
			'updated_at'     => $now,
		] );
		$id = (int) $wpdb->insert_id;
		ActivityLog::log( 'inquiry_created', 'inquiry', $id, [ 'name' => $name, 'owner_group' => $group_id ] );
		return $id;
	}

	/**
	 * Editierbare Felder einer eigenen Anfrage ändern (nicht den Status — dafür
	 * set_status mit Übergangs-Guard).
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, int $user_id, int $group_id, array $data ) {
		if ( ! self::get_owned( $id, $user_id, $group_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This inquiry is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'pp_missing_name', __( 'Please enter a contact name for the inquiry.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		global $wpdb;
		$wpdb->update(
			Schema::table( 'inquiries' ),
			[
				'name'       => $name,
				'email'      => (string) ( $data['email'] ?? '' ),
				'phone'      => (string) ( $data['phone'] ?? '' ),
				'message'    => (string) ( $data['message'] ?? '' ),
				'date_from'  => ! empty( $data['date_from'] ) ? $data['date_from'] : null,
				'date_to'    => ! empty( $data['date_to'] ) ? $data['date_to'] : null,
				'updated_at' => current_time( 'mysql' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
		return true;
	}

	/** Status setzen (mit Übergangs-Guard aus Inquiries::TRANSITIONS). */
	public static function set_status( int $id, int $user_id, int $group_id, string $status ) {
		if ( ! self::get_owned( $id, $user_id, $group_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This inquiry is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		return Inquiries::set_status( $id, $status );
	}

	public static function delete( int $id, int $user_id, int $group_id ) {
		if ( ! self::get_owned( $id, $user_id, $group_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This inquiry is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		return Inquiries::delete( $id );
	}

	private static function decode( object $row ): object {
		$row->items = json_decode( $row->items ?? '[]', true ) ?: [];
		return $row;
	}
}
