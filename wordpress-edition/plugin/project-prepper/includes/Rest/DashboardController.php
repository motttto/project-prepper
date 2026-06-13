<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\Dashboard;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * REST-Controller für die Dashboard-Übersicht.
 *
 * Zugriff: Nutzer muss mindestens EIN Modul sehen dürfen (Inventar ODER
 * Projekte) — die niedrigste sinnvolle View-Cap. Die ausgelieferten Zahlen sind
 * Aggregate; Projekte respektieren im Service zusätzlich den Gruppen-Zugriff.
 */
class DashboardController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/dashboard', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'index' ],
			'permission_callback' => static function () {
				return current_user_can( Capabilities::VIEW_INVENTORY )
					|| current_user_can( Capabilities::VIEW_PROJECTS );
			},
		] );
	}

	public function index(): WP_REST_Response {
		return new WP_REST_Response( Dashboard::overview() );
	}
}
