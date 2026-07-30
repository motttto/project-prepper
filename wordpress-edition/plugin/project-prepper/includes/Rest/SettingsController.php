<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Email\Mailer;
use ProjectPrepper\Email\Notifications;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Einstellungen: E-Mail-Benachrichtigungen, Templates, iCal-Feed, Uninstall-Verhalten.
 */
class SettingsController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/settings', [
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

		register_rest_route( self::REST_NAMESPACE, '/settings/regenerate-ical-token', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'regenerate_ical_token' ],
			'permission_callback' => $this->require_cap( Capabilities::OPERATE ),
		] );

		// Health: Test-E-Mail an die eigene Adresse senden (Zustellbarkeits-Check).
		register_rest_route( self::REST_NAMESPACE, '/settings/test-email', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'test_email' ],
			'permission_callback' => $this->require_cap( Capabilities::OPERATE ),
		] );
	}

	public function show(): WP_REST_Response {
		return new WP_REST_Response( $this->payload() );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response {
		$json = $request->get_json_params() ?: [];

		if ( array_key_exists( 'email_notifications', $json ) ) {
			update_option( Notifications::OPTION_ENABLED, (bool) $json['email_notifications'] );
		}
		if ( array_key_exists( 'delete_data_on_uninstall', $json ) ) {
			update_option( 'pp_delete_data_on_uninstall', (bool) $json['delete_data_on_uninstall'] );
		}
		if ( array_key_exists( 'public_show_rates', $json ) ) {
			update_option( 'pp_public_show_rates', (bool) $json['public_show_rates'] );
		}
		if ( array_key_exists( 'smtp', $json ) && is_array( $json['smtp'] ) ) {
			Mailer::save( $json['smtp'] );
		}
		if ( array_key_exists( 'email_templates', $json ) && is_array( $json['email_templates'] ) ) {
			$clean    = [];
			$defaults = Notifications::default_templates();
			foreach ( $defaults as $key => $default ) {
				if ( isset( $json['email_templates'][ $key ] ) ) {
					$tpl = (array) $json['email_templates'][ $key ];
					$clean[ $key ] = [
						'subject' => sanitize_text_field( (string) ( $tpl['subject'] ?? $default['subject'] ) ),
						'body'    => sanitize_textarea_field( (string) ( $tpl['body'] ?? $default['body'] ) ),
					];
				}
			}
			update_option( Notifications::OPTION_TEMPLATES, $clean );
		}

		return new WP_REST_Response( $this->payload() );
	}

	public function regenerate_ical_token(): WP_REST_Response {
		CalendarController::regenerate_token();
		return new WP_REST_Response( $this->payload() );
	}

	/**
	 * Sendet eine Test-E-Mail über wp_mail an die Adresse des aktuellen Betreibers
	 * und meldet, ob WordPress sie zur Zustellung angenommen hat. (wp_mail liefert
	 * true, wenn die Mail an den MTA übergeben wurde — keine Garantie der Zustellung,
	 * aber ein verlässlicher Hinweis auf eine kaputte Mail-Konfiguration.)
	 */
	public function test_email(): WP_REST_Response {
		$user  = wp_get_current_user();
		$to    = $user ? $user->user_email : get_option( 'admin_email' );
		$sent  = false;
		$error = '';
		// Fehlermeldung des Mailers (z. B. SMTP-Login fehlgeschlagen) einsammeln,
		// damit der Betreiber im UI sieht, WORAN der Versand scheitert.
		$capture = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error->get_error_message();
		};
		add_action( 'wp_mail_failed', $capture );
		if ( $to ) {
			$sent = wp_mail(
				$to,
				/* translators: %s: site name. */
				sprintf( __( '[%s] Test email', 'project-prepper' ), get_bloginfo( 'name' ) ),
				__( 'This is a test email from Project Prepper. If you received it, outgoing mail works.', 'project-prepper' )
			);
		}
		remove_action( 'wp_mail_failed', $capture );
		return new WP_REST_Response( [
			'sent'  => (bool) $sent,
			'to'    => (string) $to,
			'error' => $error,
			'smtp'  => Mailer::active(),
		] );
	}

	private function payload(): array {
		return [
			'email_notifications'      => Notifications::enabled(),
			'email_templates'          => Notifications::templates(),
			'smtp'                     => Mailer::public_config(),
			'delete_data_on_uninstall' => (bool) get_option( 'pp_delete_data_on_uninstall', false ),
			'public_show_rates'        => (bool) get_option( 'pp_public_show_rates', false ),
			'ical_url'                 => rest_url( self::REST_NAMESPACE . '/calendar.ics' ) . '?token=' . CalendarController::token(),
		];
	}
}
