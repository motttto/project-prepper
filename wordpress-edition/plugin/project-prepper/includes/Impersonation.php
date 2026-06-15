<?php
namespace ProjectPrepper;

use ProjectPrepper\Services\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Sichere Betreiber-Impersonation — „als User ansehen" (docs/06 §5).
 *
 * Sicherheitsmodell (angelehnt an das bewährte „User Switching"-Muster):
 * - Nur OPERATE darf starten; das Ziel darf KEIN Administrator und KEIN Operator
 *   sein (keine laterale/aufwärts gerichtete Übernahme).
 * - Der Original-(Operator-)User wird SERVERSEITIG in einem Transient gehalten,
 *   referenziert über ein zufälliges Token in einem HttpOnly-Cookie. Das Cookie
 *   allein kann niemanden übernehmen: Starten erfordert Cap + Nonce; das Cookie
 *   steuert ausschließlich das Zurückschalten (De-Eskalation auf den Operator).
 * - Vollständig reversibel und auditiert (Activity-Log).
 */
class Impersonation {

	const COOKIE = 'pp_impersonator';
	const TRANS  = 'pp_imp_';

	public static function init(): void {
		add_action( 'admin_post_pp_impersonate', [ self::class, 'handle_start' ] );
		add_action( 'admin_post_pp_impersonate_stop', [ self::class, 'handle_stop' ] );
		add_action( 'admin_post_nopriv_pp_impersonate_stop', [ self::class, 'handle_stop' ] );
	}

	/** Darf der aktuelle Operator diesen Ziel-User ansehen? */
	public static function can_impersonate( int $target_id ): bool {
		if ( ! current_user_can( Capabilities::OPERATE ) ) {
			return false;
		}
		if ( $target_id <= 0 || $target_id === get_current_user_id() ) {
			return false;
		}
		$target = get_user_by( 'id', $target_id );
		if ( ! $target ) {
			return false;
		}
		if ( in_array( 'administrator', (array) $target->roles, true ) ) {
			return false;
		}
		if ( user_can( $target, Capabilities::OPERATE ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Fertige, nonce-geschützte „Ansehen als"-URL (oder '' wenn nicht erlaubt).
	 *
	 * Rohe Ampersands (kein esc_url): Die URL wird in der JS-App per setAttribute
	 * als href gesetzt — dort werden `&#038;`/`&amp;` NICHT dekodiert, was die
	 * Query-Parameter zerstören würde. add_query_arg liefert unkodierte `&`.
	 */
	public static function start_url( int $target_id ): string {
		if ( ! self::can_impersonate( $target_id ) ) {
			return '';
		}
		return add_query_arg(
			[
				'action'   => 'pp_impersonate',
				'user'     => $target_id,
				'_wpnonce' => wp_create_nonce( 'pp_impersonate_' . $target_id ),
			],
			admin_url( 'admin-post.php' )
		);
	}

	public static function handle_start(): void {
		$target_id = isset( $_GET['user'] ) ? (int) $_GET['user'] : 0;
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'pp_impersonate_' . $target_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'project-prepper' ) );
		}
		if ( ! self::can_impersonate( $target_id ) ) {
			wp_die( esc_html__( 'You are not allowed to view as this user.', 'project-prepper' ) );
		}

		$orig  = get_current_user_id();
		$token = wp_generate_password( 43, false, false );
		set_transient( self::TRANS . $token, $orig, 2 * HOUR_IN_SECONDS );
		self::set_cookie( $token );

		ActivityLog::log( 'impersonation_started', 'user', $target_id, [ 'by' => $orig ] );

		wp_clear_auth_cookie();
		wp_set_current_user( $target_id );
		wp_set_auth_cookie( $target_id );

		wp_safe_redirect( Frontend\MemberPortal::portal_url() );
		exit;
	}

	public static function handle_stop(): void {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'pp_impersonate_stop' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'project-prepper' ) );
		}
		$orig  = self::pending_original();
		$token = self::cookie_token();
		self::clear_cookie();
		if ( '' !== $token ) {
			delete_transient( self::TRANS . $token );
		}
		if ( $orig > 0 ) {
			ActivityLog::log( 'impersonation_stopped', 'user', get_current_user_id(), [ 'by' => $orig ] );
			wp_clear_auth_cookie();
			wp_set_current_user( $orig );
			wp_set_auth_cookie( $orig );
			wp_safe_redirect( admin_url( 'admin.php?page=pp-users' ) );
			exit;
		}
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/** Original-Operator-ID, wenn gerade impersoniert wird (sonst 0). */
	public static function pending_original(): int {
		$token = self::cookie_token();
		if ( '' === $token ) {
			return 0;
		}
		$orig = get_transient( self::TRANS . $token );
		return $orig ? (int) $orig : 0;
	}

	public static function is_active(): bool {
		return self::pending_original() > 0;
	}

	/** Banner-HTML für das Portal (oder '' wenn nicht aktiv). */
	public static function banner(): string {
		if ( ! self::is_active() ) {
			return '';
		}
		$user = wp_get_current_user();
		$name = $user ? $user->display_name : '';
		$stop = wp_nonce_url( admin_url( 'admin-post.php?action=pp_impersonate_stop' ), 'pp_impersonate_stop' );

		/* translators: %s: display name of the impersonated member. */
		$msg = sprintf( __( 'You are viewing the portal as %s.', 'project-prepper' ), $name );

		return '<div class="pp-imp-banner">'
			. '<span>' . esc_html( $msg ) . '</span>'
			. '<a class="pp-imp-back" href="' . esc_url( $stop ) . '">' . esc_html__( 'Back to your account', 'project-prepper' ) . '</a>'
			. '</div>';
	}

	/* ---------- Cookie ---------- */

	private static function set_cookie( string $token ): void {
		setcookie( self::COOKIE, $token, [
			'expires'  => time() + 2 * HOUR_IN_SECONDS,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		] );
		$_COOKIE[ self::COOKIE ] = $token; // im selben Request sofort sichtbar
	}

	private static function clear_cookie(): void {
		setcookie( self::COOKIE, '', [
			'expires'  => time() - 3600,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		] );
		unset( $_COOKIE[ self::COOKIE ] );
	}

	private static function cookie_token(): string {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return '';
		}
		$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		// Token ist ein 43-stelliges alphanumerisches wp_generate_password-Resultat.
		return preg_match( '/^[A-Za-z0-9]{43}$/', $token ) ? $token : '';
	}
}
