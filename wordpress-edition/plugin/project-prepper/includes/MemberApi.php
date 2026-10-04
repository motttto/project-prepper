<?php
namespace ProjectPrepper;

use ProjectPrepper\Services\ActivityLog;
use WP_Application_Passwords;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Mitglieder-API (v0.149.0): Jedes Mitglied liest SEIN Equipment und SEINE
 * Verleihe über die Routen `/me…` ({@see Rest\MeController}) — z. B. für ein
 * eigenes Dashboard. Angemeldet wird mit WordPress-App-Passwörtern (HTTP Basic
 * Auth), die Mitglieder im Portal unter „API-Zugang" selbst anlegen und
 * widerrufen; ins wp-admin kommen sie ja nicht.
 *
 * Drei Leitplanken:
 *  1. Betreiber-Schalter `api` (Funktionsbereiche, Standard AN). Aus = die
 *     /me-Routen antworten 403, der Portal-Bereich verschwindet, und
 *     App-Passwörter von Mitgliedern greifen nicht mehr — auch bereits angelegte.
 *  2. Nur lesen: Wer kein Backend-Recht hat und sich per App-Passwort anmeldet,
 *     erreicht ausschließlich GET auf `/project-prepper/v1/me…` und
 *     `/wp/v2/users/me`. Dasselbe gilt für JEDES im Portal angelegte Passwort
 *     (Kennung `app_id` = {@see APP_ID}), auch das eines Managers oder Admins —
 *     sonst wäre das „Nur lesen" im Portal für sie gelogen. Ein entwendetes
 *     Passwort kann damit nichts ändern und nichts lesen, was der Nutzer nicht
 *     ohnehin sieht. Im wp-admin angelegte Passwörter von Betreibern und Managern
 *     bleiben unverändert (Admin-Routen bleiben, wie sie sind).
 *  3. Drosselung: Anfragen je Nutzer und Minute (Sicherheit → `api_rate_limit`);
 *     Fehlanmeldungen mit App-Passwort zählen in die Login-Sperre je IP
 *     ({@see Security::init}).
 */
class MemberApi {

	/** Schlüssel im Feature-Schalter-Array ({@see Settings::feature_defaults}). */
	const FEATURE = 'api';

	/** Höchstzahl App-Passwörter je Mitglied — eins je Programm reicht. */
	const MAX_PASSWORDS = 10;

	/** Längster Name eines App-Passworts (wird gekürzt). */
	const NAME_MAX_LEN = 60;

	/** Das neue Passwort überlebt den Redirect genau einmal — verschlüsselt. */
	const NEW_PW_TRANSIENT = 'pp_api_new_pw_';

	/** Zählerstand der Drosselung je Nutzer. */
	const RATE_TRANSIENT = 'pp_api_rate_';

	/**
	 * Kennung (`app_id`) aller im Portal angelegten Passwörter — daran hängt die
	 * Nur-Lese-Grenze, unabhängig von der Rolle (auch nach einer Beförderung).
	 */
	const APP_ID = 'fe05bff7-9327-447b-a2c7-9052bb60aaec';

	/** Core-Rechte zum Verwalten von App-Passwörtern — für Mitglieder nur übers Portal. */
	const MANAGE_CAPS = [ 'create_app_password', 'edit_app_password', 'delete_app_password', 'delete_app_passwords' ];

	public static function init(): void {
		add_filter( 'wp_is_application_passwords_available_for_user', [ self::class, 'filter_available_for_user' ], 10, 2 );
		add_filter( 'rest_pre_dispatch', [ self::class, 'guard_rest' ], 5, 3 );
		// Verwalten (anlegen/umbenennen/löschen) nur im Portal: Höchstzahl, Namensregel
		// und Schalter gelten dann auf JEDEM Weg — Core-REST in jeder Schreibweise,
		// wp-admin/authorize-application.php (Audit ACC-API-01/07).
		add_filter( 'map_meta_cap', [ self::class, 'restrict_manage_caps' ], 10, 3 );
		add_filter( 'rest_post_dispatch', [ self::class, 'add_retry_after' ], 10, 3 );
		// Protokoll für JEDES App-Passwort, egal über welchen Weg (Portal, Profil im
		// wp-admin, Core-REST) es entsteht oder verschwindet. Das Passwort selbst
		// steht nie im Protokoll.
		add_action( 'wp_create_application_password', [ self::class, 'log_created' ], 10, 2 );
		add_action( 'wp_delete_application_password', [ self::class, 'log_deleted' ], 10, 2 );
		// Nutzer gelöscht → seine Zähler/Einmal-Anzeige gleich mit (die App-Passwörter
		// selbst sind User-Meta und gehen mit dem Nutzer).
		add_action( 'deleted_user', [ self::class, 'forget_user' ], 10, 1 );
	}

	/** @param int $user_id */
	public static function forget_user( $user_id ): void {
		delete_transient( self::RATE_TRANSIENT . (int) $user_id );
		delete_transient( self::NEW_PW_TRANSIENT . (int) $user_id );
	}

	public static function enabled(): bool {
		return Settings::feature_on( self::FEATURE );
	}

	/**
	 * Gilt die Nur-Lese-Leitplanke? Ja für alle ohne Backend-Recht (Mitglieder,
	 * Abonnenten) — dieselbe Grenze wie die wp-admin-Umleitung in
	 * {@see Frontend\MemberPortal::is_member_only}.
	 */
	public static function is_restricted( WP_User $user ): bool {
		return ! ( user_can( $user, 'manage_options' ) || user_can( $user, 'edit_posts' ) || user_can( $user, Capabilities::MANAGE_GROUPS ) );
	}

	/* ===================== WordPress-Filter ===================== */

	/**
	 * App-Passwörter für Mitglieder nur bei eingeschalteter Mitglieder-API — und
	 * nie über XML-RPC, das keine Nur-Lese-Grenze kennt.
	 *
	 * @param bool    $available
	 * @param WP_User $user
	 * @return bool
	 */
	public static function filter_available_for_user( $available, $user ) {
		if ( ! $available || ! $user instanceof WP_User || ! self::is_restricted( $user ) ) {
			return $available;
		}
		// Ohne Project-Prepper-Rolle nützt ein Passwort nichts: /me antwortet 403.
		if ( ! self::enabled() || ! user_can( $user, Capabilities::COLLECTIVES ) ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}
		return true;
	}

	/**
	 * Nur-Lese-Leitplanke für Mitglieder (siehe Klassenkommentar, Punkt 2), dazu:
	 * App-Passwörter verwalten Mitglieder ausschließlich im Portal — dort gelten
	 * Höchstzahl und Feature-Schalter. Die Schreibrouten des Core sind für sie zu.
	 *
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param WP_REST_Request  $request
	 * @return mixed
	 */
	public static function guard_rest( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return $result;
		}
		$method = strtoupper( (string) $request->get_method() );
		// Core vergleicht Routen OHNE Groß-/Kleinschreibung (`@…@i`) — hier also
		// auch, sonst rutscht `/wp/v2/users/me/Application-Passwords` durch (ACC-API-01).
		$route = strtolower( untrailingslashit( (string) $request->get_route() ) );
		$read  = in_array( $method, [ 'GET', 'HEAD' ], true );

		// Antworten als WP_REST_Response: Ein nacktes WP_Error reicht /batch/v1
		// ungewandelt an rest_post_dispatch weiter → Fatal in fremden Filtern (ACC-API-05).
		if ( ! $read && self::is_restricted( $user ) && preg_match( '#^/wp/v2/users/[^/]+/application-passwords#', $route ) ) {
			return rest_convert_error_to_response( new WP_Error(
				'pp_api_manage_in_portal',
				__( 'Manage your API passwords in the member portal under “API access”.', 'project-prepper' ),
				[ 'status' => 403 ]
			) );
		}

		if ( ! self::read_only_request( $user ) ) {
			return $result; // Cookie-Anmeldung oder unbeschränktes Passwort: unverändert.
		}
		if ( $read && self::read_route_allowed( $route ) ) {
			return $result;
		}
		return rest_convert_error_to_response( new WP_Error(
			'pp_api_read_only',
			__( 'This API password can only read your own equipment and rentals (GET /project-prepper/v1/me …).', 'project-prepper' ),
			[ 'status' => 403 ]
		) );
	}

	/**
	 * Ist diese Anfrage mit einem nur-lesenden App-Passwort angemeldet? Ja für
	 * jedes Passwort eines Nutzers ohne Backend-Recht und für jedes im Portal
	 * angelegte Passwort (Kennung {@see APP_ID}), egal welche Rolle der Nutzer
	 * heute hat (ACC-API-02/03).
	 */
	public static function read_only_request( WP_User $user ): bool {
		$uuid = rest_get_authenticated_app_password();
		if ( null === $uuid || '' === (string) $uuid ) {
			return false;
		}
		if ( self::is_restricted( $user ) ) {
			return true;
		}
		$item = WP_Application_Passwords::get_user_application_password( (int) $user->ID, (string) $uuid );
		return is_array( $item ) && self::APP_ID === (string) ( $item['app_id'] ?? '' );
	}

	/**
	 * Core-Verwaltungsrechte für Nutzer ohne Backend-Recht sperren. Geprüft wird
	 * der HANDELNDE Nutzer — ein Admin verwaltet die Passwörter eines Mitglieds im
	 * wp-admin weiterhin. Das Portal ruft WP_Application_Passwords direkt auf.
	 *
	 * @param string[] $caps
	 * @param string   $cap
	 * @param int      $user_id
	 * @return string[]
	 */
	public static function restrict_manage_caps( $caps, $cap, $user_id ) {
		if ( ! in_array( $cap, self::MANAGE_CAPS, true ) ) {
			return $caps;
		}
		$user = get_userdata( (int) $user_id );
		return ( $user && self::is_restricted( $user ) ) ? [ 'do_not_allow' ] : $caps;
	}

	/** Lesbare Routen für Mitglieder mit App-Passwort (Route kleingeschrieben). */
	public static function read_route_allowed( string $route ): bool {
		return '/wp/v2/users/me' === $route
			|| 1 === preg_match( '#^/' . preg_quote( Rest\BaseController::REST_NAMESPACE, '#' ) . '/me(/|$)#', $route );
	}

	/**
	 * Retry-After an jede 429 der Drosselung hängen (Sekunden bis zum nächsten
	 * Minutenfenster) — sauber gebaute Clients warten dann von selbst.
	 *
	 * @param WP_REST_Response $response
	 * @return WP_REST_Response
	 */
	public static function add_retry_after( $response, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( $response instanceof WP_REST_Response && 429 === $response->get_status() ) {
			$data = $response->get_data();
			if ( is_array( $data ) && 'pp_rate_limited' === ( $data['code'] ?? '' ) ) {
				$response->header( 'Retry-After', (string) max( 1, MINUTE_IN_SECONDS - ( time() % MINUTE_IN_SECONDS ) ) );
			}
		}
		return $response;
	}

	/* ===================== Drosselung ===================== */

	/**
	 * Zählt eine Anfrage und sagt, ob das Limit überschritten ist. Festes
	 * Minutenfenster je Nutzer, Zähler im Transient — wie die Login-Sperre in
	 * {@see Security}. Der erste Treffer je Fenster landet im Protokoll.
	 */
	public static function rate_limited( int $user_id ): bool {
		$limit = Security::int( 'api_rate_limit' );
		if ( $limit <= 0 || $user_id <= 0 ) {
			return false;
		}
		$key    = self::RATE_TRANSIENT . $user_id;
		$window = (int) floor( time() / MINUTE_IN_SECONDS );
		$state  = get_transient( $key );
		if ( ! is_array( $state ) || (int) ( $state['w'] ?? 0 ) !== $window ) {
			$state = [ 'w' => $window, 'n' => 0 ];
		}
		++$state['n'];
		set_transient( $key, $state, MINUTE_IN_SECONDS );
		if ( $limit + 1 === $state['n'] ) {
			ActivityLog::log( 'api_rate_limited', 'user', $user_id, [ 'limit' => $limit ] );
		}
		return $state['n'] > $limit;
	}

	/* ===================== App-Passwörter ===================== */

	/**
	 * Warum kann dieser Nutzer gerade kein App-Passwort anlegen? Leer = er kann.
	 */
	public static function unavailable_reason( WP_User $user ): string {
		if ( ! self::enabled() ) {
			return __( 'The member API is switched off on this site.', 'project-prepper' );
		}
		if ( ! user_can( $user, Capabilities::COLLECTIVES ) ) {
			return __( 'Your account has no access to the member API.', 'project-prepper' );
		}
		// „Ansehen als": Der Betreiber soll kein dauerhaftes Passwort im Namen des
		// Mitglieds hinterlassen können (ACC-API-08).
		if ( Impersonation::is_active() ) {
			return __( 'API passwords cannot be created while viewing the portal as another member.', 'project-prepper' );
		}
		if ( ! wp_is_application_passwords_supported() ) {
			return __( 'API passwords need an encrypted connection (HTTPS). Please ask the operators to switch this site to HTTPS.', 'project-prepper' );
		}
		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			return __( 'API passwords are switched off on this site. Please ask the operators.', 'project-prepper' );
		}
		return '';
	}

	/**
	 * App-Passwörter des Nutzers ohne Hash, neueste zuerst.
	 *
	 * @return array<int,array{uuid:string,name:string,created:int,last_used:?int,last_ip:string}>
	 */
	public static function passwords( int $user_id ): array {
		$out = [];
		foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			$out[] = [
				'uuid'      => (string) ( $item['uuid'] ?? '' ),
				'name'      => (string) ( $item['name'] ?? '' ),
				'created'   => (int) ( $item['created'] ?? 0 ),
				'last_used' => ! empty( $item['last_used'] ) ? (int) $item['last_used'] : null,
				'last_ip'   => (string) ( $item['last_ip'] ?? '' ),
			];
		}
		usort( $out, static fn( $a, $b ) => $b['created'] <=> $a['created'] );
		return $out;
	}

	/**
	 * Neues App-Passwort anlegen. Das Klartext-Passwort geht NICHT zurück an den
	 * Aufrufer, sondern verschlüsselt in ein kurzes Transient — die Portal-Ansicht
	 * zeigt es nach dem Redirect genau einmal ({@see take_new_password}).
	 *
	 * @return true|WP_Error
	 */
	public static function create_password( int $user_id, string $name ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'pp_forbidden', 'forbidden' );
		}
		$reason = self::unavailable_reason( $user );
		if ( '' !== $reason ) {
			return new WP_Error( 'pp_api_unavailable', $reason );
		}
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return new WP_Error( 'pp_api_missing_name', __( 'Please give the API password a name, e.g. the program that will use it.', 'project-prepper' ) );
		}
		$name = mb_substr( $name, 0, self::NAME_MAX_LEN );
		if ( count( WP_Application_Passwords::get_user_application_passwords( $user_id ) ) >= self::MAX_PASSWORDS ) {
			return new WP_Error(
				'pp_api_limit',
				sprintf(
					/* translators: %d: maximum number of API passwords per member. */
					__( 'You already have %d API passwords. Please revoke one you no longer need first.', 'project-prepper' ),
					self::MAX_PASSWORDS
				)
			);
		}
		if ( WP_Application_Passwords::application_name_exists_for_user( $user_id, $name ) ) {
			return new WP_Error( 'pp_api_name_taken', __( 'You already have an API password with this name. Please choose another name.', 'project-prepper' ) );
		}
		$created = WP_Application_Passwords::create_new_application_password( $user_id, [ 'name' => $name, 'app_id' => self::APP_ID ] );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		self::stash_new_password( $user_id, (string) $created[0], $name );
		return true;
	}

	/**
	 * Eigenes App-Passwort widerrufen. Eine fremde oder unbekannte UUID ist
	 * „nicht erlaubt" — ob es sie bei jemand anderem gibt, erfährt niemand.
	 *
	 * @return true|WP_Error
	 */
	public static function revoke_password( int $user_id, string $uuid ) {
		$uuid = sanitize_text_field( $uuid );
		if ( '' === $uuid || $user_id <= 0 || ! WP_Application_Passwords::get_user_application_password( $user_id, $uuid ) ) {
			return new WP_Error( 'pp_forbidden', 'forbidden' );
		}
		$deleted = WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		return is_wp_error( $deleted ) ? $deleted : true;
	}

	/**
	 * Passwörter widerrufen, die ohne das Plugin keine Nur-Lese-Grenze mehr
	 * hätten: alle eines Nutzers ohne Backend-Recht, sonst die im Portal
	 * angelegten. Für Deaktivierung und Deinstallation (ACC-API-04) — sonst würde
	 * ein entwendetes „Nur-Lese"-Passwort zur Kontoübernahme (Profil/E-Mail ändern).
	 *
	 * @return int Zahl der widerrufenen Passwörter.
	 */
	public static function revoke_unguarded( int $user_id ): int {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return 0;
		}
		$restricted = self::is_restricted( $user );
		$count      = 0;
		foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( $restricted || self::APP_ID === (string) ( $item['app_id'] ?? '' ) ) {
				if ( true === WP_Application_Passwords::delete_application_password( $user_id, (string) $item['uuid'] ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/** {@see revoke_unguarded} für alle Nutzer mit App-Passwörtern. */
	public static function revoke_all_unguarded(): int {
		$count = 0;
		foreach ( get_users( [ 'meta_key' => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, 'fields' => 'ID' ] ) as $uid ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- einmalig bei Deaktivierung/Deinstallation.
			$count += self::revoke_unguarded( (int) $uid );
		}
		return $count;
	}

	/**
	 * Das eben angelegte Passwort einmalig abholen (und dabei löschen).
	 *
	 * @return array{pw:string,name:string}|null
	 */
	public static function take_new_password( int $user_id ): ?array {
		$key = self::NEW_PW_TRANSIENT . $user_id;
		$raw = get_transient( $key );
		delete_transient( $key );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$bin = hex2bin( $raw );
		if ( false === $bin || strlen( $bin ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::stash_key( $user_id )
			);
		} catch ( \Throwable $e ) {
			return null;
		}
		$data = is_string( $plain ) ? json_decode( $plain, true ) : null;
		return is_array( $data ) && ! empty( $data['pw'] ) ? [ 'pw' => (string) $data['pw'], 'name' => (string) ( $data['name'] ?? '' ) ] : null;
	}

	/**
	 * Klartext nie unverschlüsselt in der Datenbank: Abgelaufene Transients bleiben
	 * ohne Objekt-Cache bis zum nächsten Aufräumen in wp_options liegen. Der
	 * Schlüssel stammt aus den Salts in wp-config.php, nicht aus der Datenbank.
	 */
	private static function stash_new_password( int $user_id, string $password, string $name ): void {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( (string) wp_json_encode( [ 'pw' => $password, 'name' => $name ] ), $nonce, self::stash_key( $user_id ) );
		set_transient( self::NEW_PW_TRANSIENT . $user_id, bin2hex( $nonce . $box ), 2 * MINUTE_IN_SECONDS );
	}

	private static function stash_key( int $user_id ): string {
		return sodium_crypto_generichash( wp_salt( 'auth' ) . '|pp_api_pw|' . $user_id, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/* ===================== Protokoll ===================== */

	/**
	 * @param int   $user_id
	 * @param array $item Neuer Eintrag (uuid, name, …) — ohne Klartext.
	 */
	public static function log_created( $user_id, $item ): void {
		ActivityLog::log( 'api_password_created', 'user', (int) $user_id, [ 'name' => (string) ( $item['name'] ?? '' ) ] );
	}

	/**
	 * @param int   $user_id
	 * @param array $item Gelöschter Eintrag.
	 */
	public static function log_deleted( $user_id, $item ): void {
		ActivityLog::log( 'api_password_revoked', 'user', (int) $user_id, [ 'name' => (string) ( $item['name'] ?? '' ) ] );
	}
}
