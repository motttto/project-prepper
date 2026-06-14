<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Federation;
use ProjectPrepper\Services\FederatedBorrow;
use WP_REST_Request;
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

		// Slice 3: öffentlich geteilter Inventar-Katalog (anonymisiert).
		register_rest_route( self::REST_NAMESPACE, '/federation/inventory', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'inventory' ],
			'permission_callback' => '__return_true',
		] );

		// Slice 4: föderierte Leih-Anfrage entgegennehmen (moderiert). Öffentlich —
		// das Trust-Gate (Partner-Liste, accept_borrows, Rate-Limit, Honeypot) sitzt
		// in FederatedBorrow::create_inbound.
		register_rest_route( self::REST_NAMESPACE, '/federation/borrow', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'borrow' ],
			'permission_callback' => '__return_true',
		] );

		// Slice 4: Status einer Anfrage über ihr Token (Polling der Anfrager-Instanz).
		register_rest_route( self::REST_NAMESPACE, '/federation/borrow/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'borrow_status' ],
			'permission_callback' => '__return_true',
		] );

		// Admin-UI: Einstellungen lesen/speichern + Partner-Verzeichnis (nur Betreiber).
		register_rest_route( self::REST_NAMESPACE, '/federation/admin', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'admin_show' ],
				'permission_callback' => $this->require_cap( Capabilities::OPERATE ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'admin_update' ],
				'permission_callback' => $this->require_cap( Capabilities::OPERATE ),
			],
		] );
	}

	public function admin_show(): WP_REST_Response {
		return new WP_REST_Response( $this->admin_payload() );
	}

	public function admin_update( WP_REST_Request $request ): WP_REST_Response {
		$json = $request->get_json_params() ?: [];
		Federation::save_from( $json );
		return new WP_REST_Response( $this->admin_payload() );
	}

	/** Einstellungen + Discovery-URL + (gecachtes) Partner-Verzeichnis. */
	private function admin_payload(): array {
		$cfg = Federation::all();
		return [
			'enabled'        => (bool) $cfg['enabled'],
			'accept_borrows' => ! empty( $cfg['accept_borrows'] ),
			'partners'       => implode( "\n", Federation::partners() ),
			'discovery_url'  => rest_url( self::REST_NAMESPACE . '/federation/info' ),
			'directory'      => array_map( static function ( $entry ) {
				$p = $entry['profile'];
				return [
					'url'         => $entry['url'],
					'reachable'   => (bool) $p,
					'name'        => $p ? (string) ( $p['name'] ?? '' ) : '',
					'postal_code' => $p ? (string) ( $p['postal_code'] ?? '' ) : '',
					'topic'       => $p ? (string) ( $p['topic'] ?? '' ) : '',
					'collectives' => $p ? (int) ( $p['collectives'] ?? 0 ) : null,
					'members'     => $p ? (int) ( $p['members'] ?? 0 ) : null,
				];
			}, Federation::directory() ),
		];
	}

	public function info() {
		if ( ! Federation::enabled() ) {
			return new WP_REST_Response( [ 'federation' => 'disabled' ], 404 );
		}
		return new WP_REST_Response( Federation::public_profile(), 200 );
	}

	public function inventory() {
		if ( ! Federation::enabled() ) {
			return new WP_REST_Response( [ 'federation' => 'disabled' ], 404 );
		}
		return new WP_REST_Response( [
			'instance' => Federation::public_profile(),
			'items'    => Federation::public_inventory(),
		], 200 );
	}

	public function borrow( WP_REST_Request $request ) {
		$p      = $request->get_json_params();
		$p      = is_array( $p ) ? $p : $request->get_params();
		$result = FederatedBorrow::create_inbound( [
			'origin'            => $p['origin'] ?? '',
			'origin_name'       => $p['origin_name'] ?? '',
			'item_id'           => $p['item_id'] ?? 0,
			'requester_name'    => $p['requester_name'] ?? '',
			'requester_contact' => $p['requester_contact'] ?? '',
			'date_from'         => $p['date_from'] ?? '',
			'date_to'           => $p['date_to'] ?? '',
			'message'           => $p['message'] ?? '',
			'hp'                => $p['hp'] ?? '',
		] );
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( [ 'error' => $result->get_error_code(), 'message' => $result->get_error_message() ], $status );
		}
		return new WP_REST_Response( [ 'ok' => true, 'status' => $result['status'], 'token' => $result['token'] ], 200 );
	}

	public function borrow_status( WP_REST_Request $request ) {
		$status = FederatedBorrow::status_for_token( sanitize_text_field( (string) $request->get_param( 'token' ) ) );
		if ( null === $status ) {
			return new WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}
		return new WP_REST_Response( [ 'status' => $status ], 200 );
	}
}
