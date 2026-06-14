<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Users;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Benutzer & Rechte (Steuerzentrale). Gate = `pp_operate` (nur Administrator).
 * Es lassen sich NUR die 3 pp-Rollen + 12 pp-Caps setzen (keine Core-Caps), und
 * Users::update() hat Guards (kein Selbst-Aussperren, letzter Admin geschützt) —
 * daher keine Rechte-Eskalation über das Admin-Niveau hinaus.
 */
class UsersController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/users', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'index' ],
			'permission_callback' => $this->require_cap( Capabilities::OPERATE ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/users/(?P<id>\d+)', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'update' ],
			'permission_callback' => $this->require_cap( Capabilities::OPERATE ),
			'args'                => [
				'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) ],
			],
		] );
	}

	public function index(): WP_REST_Response {
		return new WP_REST_Response( Users::list_payload() );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response {
		$id     = (int) $request['id'];
		$json   = $request->get_json_params() ?: [];
		$result = Users::update( $id, [
			'role' => $json['role'] ?? null,
			'caps' => isset( $json['caps'] ) && is_array( $json['caps'] ) ? $json['caps'] : null,
		] );
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( [ 'error' => $result->get_error_code(), 'message' => $result->get_error_message() ], $status );
		}
		return new WP_REST_Response( Users::list_payload() );
	}
}
