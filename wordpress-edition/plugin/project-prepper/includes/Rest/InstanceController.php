<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Platform;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Plattform-/Instanz-Konfiguration (Identität, Ökonomie-Modell, AGB).
 * Steuerzentrale-Seite, gegated auf `pp_operate` (Betreiber).
 */
class InstanceController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/instance', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'show' ],
				'permission_callback' => $this->require_cap( Capabilities::OPERATE ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update' ],
				'permission_callback' => $this->require_cap( Capabilities::OPERATE ),
			],
		] );
	}

	public function show(): WP_REST_Response {
		return new WP_REST_Response( $this->payload() );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response {
		$json = $request->get_json_params() ?: [];
		Platform::save_from( $json );
		return new WP_REST_Response( $this->payload() );
	}

	private function payload(): array {
		$p = Platform::all();
		// Modelle als {value,label} für das UI mitliefern.
		$p['economy_models'] = [
			[ 'value' => 'free',       'label' => __( 'Free (non-commercial)', 'project-prepper' ) ],
			[ 'value' => 'donation',   'label' => __( 'Donation-based', 'project-prepper' ) ],
			[ 'value' => 'membership', 'label' => __( 'Membership fee', 'project-prepper' ) ],
			[ 'value' => 'fees',       'label' => __( 'Per-item fees', 'project-prepper' ) ],
			[ 'value' => 'pro',        'label' => __( 'Pro tier / brokering', 'project-prepper' ) ],
		];
		return $p;
	}
}
