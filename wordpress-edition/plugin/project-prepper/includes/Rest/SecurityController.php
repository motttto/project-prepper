<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Security;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend-Härtung (Sicherheits-Schalter). GET liefert die aktuellen Werte,
 * PUT speichert sie. Ersetzt das frühere server-gerenderte Formular; die
 * eigentliche Säuberung/Durchsetzung bleibt in der Security-Klasse.
 */
class SecurityController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/security', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'show' ],
				'permission_callback' => $this->require_cap( Capabilities::MANAGE_SETTINGS ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update' ],
				'permission_callback' => $this->require_cap( Capabilities::MANAGE_SETTINGS ),
			],
		] );
	}

	public function show(): WP_REST_Response {
		return new WP_REST_Response( Security::all() );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response {
		$json = $request->get_json_params() ?: [];
		return new WP_REST_Response( Security::save_from( $json ) );
	}
}
