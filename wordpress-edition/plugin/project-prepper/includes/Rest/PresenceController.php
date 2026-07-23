<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Services\Presence;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Heartbeat für die Presence-Anzeige („Wer ist online").
 *
 * Das Portal-JS pingt diese Route regelmäßig; sie setzt ausschließlich den
 * „zuletzt gesehen"-Stempel des aufrufenden Users. Keine Cap-Prüfung nötig —
 * eingeloggt genügt (jeder Portal-Nutzer darf sich selbst als online melden);
 * gelesen wird hier nichts über andere User. Nonce-Schutz über den
 * REST-Standard (X-WP-Nonce / wp_rest), sichtbar am Login-Gate.
 */
class PresenceController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/heartbeat', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'beat' ],
			'permission_callback' => static function () {
				return is_user_logged_in();
			},
		] );
	}

	public function beat(): WP_REST_Response {
		$user_id = get_current_user_id();
		Presence::touch( $user_id );
		return new WP_REST_Response( [
			'ok' => true,
			'ts' => time(),
		] );
	}
}
