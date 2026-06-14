<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Users;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Benutzer & Rechte (Superadmin). Auf Administrator-Caps begrenzt
 * (`edit_users` lesen, `promote_users` schreiben) — das ist das WP-konforme Gate
 * für Benutzer-Verwaltung und verhindert Rechte-Eskalation durch Nicht-Admins.
 */
class UsersController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/users', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'index' ],
			'permission_callback' => $this->require_cap( 'edit_users' ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/users/(?P<id>\d+)', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'update' ],
			'permission_callback' => $this->require_cap( 'promote_users' ),
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
