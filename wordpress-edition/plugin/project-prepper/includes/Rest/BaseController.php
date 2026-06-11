<?php
namespace ProjectPrepper\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Basis für alle REST-Controller.
 *
 * Jede Route bekommt einen permission_callback auf Capability-Basis —
 * das ist der RLS-Ersatz der WordPress-Edition. Keine Route ohne Cap-Check.
 */
abstract class BaseController {

	const REST_NAMESPACE = 'project-prepper/v1';

	abstract public function register_routes(): void;

	/**
	 * permission_callback-Factory für eine Capability.
	 */
	protected function require_cap( string $cap ): callable {
		return static function () use ( $cap ) {
			return current_user_can( $cap );
		};
	}

	protected function sanitize_text_fields( array $data, array $keys ): array {
		$out = [];
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$out[ $key ] = sanitize_text_field( (string) $data[ $key ] );
			}
		}
		return $out;
	}
}
