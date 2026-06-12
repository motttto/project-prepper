<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Activity-Log light — Pendant zu org_activity_log der App.
 */
class ActivityLog {

	public static function log( string $action, string $entity_type, ?int $entity_id = null, array $metadata = [] ): void {
		global $wpdb;
		$wpdb->insert( Schema::table( 'activity_log' ), [
			'actor_id'    => get_current_user_id() ?: null,
			'action'      => $action,
			'entity_type' => $entity_type,
			'entity_id'   => $entity_id,
			'metadata'    => $metadata ? wp_json_encode( $metadata ) : null,
			'created_at'  => current_time( 'mysql' ),
		] );
	}

	public static function recent( int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i ORDER BY id DESC LIMIT %d',
			Schema::table( 'activity_log' ),
			$limit
		) ) ?: [];
	}
}
