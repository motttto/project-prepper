<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Mitglieder-Feedback an die Betreiber:innen (Pendant zur App `app_feedback` +
 * Admin-Feedback-Tab). Mitglieder senden Bug/Idee/Sonstiges aus dem Portal; die
 * Betreiber:innen lesen es im Backend.
 */
class Feedback {

	/** Erlaubte Feedback-Typen. */
	public static function types(): array {
		return [
			'bug'   => __( 'Bug / problem', 'project-prepper' ),
			'idea'  => __( 'Idea / wish', 'project-prepper' ),
			'other' => __( 'Something else', 'project-prepper' ),
		];
	}

	/**
	 * Neues Feedback speichern.
	 *
	 * @return true|WP_Error
	 */
	public static function create( int $user_id, string $type, string $message, string $route = '' ) {
		global $wpdb;
		$message = trim( wp_strip_all_tags( $message ) );
		if ( '' === $message ) {
			return new WP_Error( 'pp_empty', __( 'Please enter a message.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		if ( ! array_key_exists( $type, self::types() ) ) {
			$type = 'other';
		}
		$wpdb->insert( Schema::table( 'app_feedback' ), [
			'user_id'       => $user_id ?: null,
			'feedback_type' => $type,
			'message'       => mb_substr( $message, 0, 5000 ),
			'route'         => substr( sanitize_text_field( $route ), 0, 190 ),
			'status'        => 'new',
			'created_at'    => current_time( 'mysql' ),
		] );
		return true;
	}

	/**
	 * Neueste Einträge (Backend-Liste).
	 *
	 * @return array<object>
	 */
	public static function recent( int $limit = 200 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d',
			Schema::table( 'app_feedback' ),
			$limit
		) ) ?: [];
	}

	/** Anzahl ungelesener (status = new) — für ein Badge im Backend. */
	public static function new_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE status = %s',
			Schema::table( 'app_feedback' ),
			'new'
		) );
	}

	/** Status setzen (new|read|done). */
	public static function set_status( int $id, string $status ): void {
		global $wpdb;
		if ( ! in_array( $status, [ 'new', 'read', 'done' ], true ) ) {
			return;
		}
		$wpdb->update( Schema::table( 'app_feedback' ), [ 'status' => $status ], [ 'id' => $id ] );
	}
}
