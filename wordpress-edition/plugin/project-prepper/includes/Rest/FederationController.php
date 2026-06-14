<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Federation;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Discovery-Endpoint der Föderation (Slice 1).
 *
 *   GET /wp-json/project-prepper/v1/federation/info
 *
 * Öffentlich (so finden andere Instanzen diese), aber liefert nur, wenn der
 * Betreiber die Föderation aktiviert hat — sonst 404. Gibt ausschließlich grobe,
 * nicht-personenbezogene Eckdaten zurück (kein RLS-/Daten-Leak).
 */
class FederationController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/federation/info', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'info' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function info() {
		if ( ! Federation::enabled() ) {
			return new WP_REST_Response( [ 'federation' => 'disabled' ], 404 );
		}
		return new WP_REST_Response( Federation::public_profile(), 200 );
	}
}
