<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Email\Notifications;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * E-Mail-Templates (Steuerzentrale). Alle vom Plugin verschickten Mails zentral
 * editierbar (Verleih, Anfrage, Gruppen-Einladung, Leihe, 2FA-Code). Ersetzt die
 * frühere, auf die Verleih-Mails beschränkte Sektion in den Einstellungen.
 */
class EmailTemplatesController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/email-templates', [
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
		if ( isset( $json['templates'] ) && is_array( $json['templates'] ) ) {
			Notifications::save_templates( $json['templates'] );
		}
		return new WP_REST_Response( $this->payload() );
	}

	private function payload(): array {
		return [
			'enabled'   => Notifications::enabled(),
			'templates' => Notifications::templates(),
			'catalog'   => Notifications::catalog(),
		];
	}
}
