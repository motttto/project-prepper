<?php
namespace ProjectPrepper\Frontend;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Zwei-Faktor-Login für Mitglieder (E-Mail-Code) — portal-eigener 2-Schritt-Flow.
 *
 * Greift **nur**, wenn der Betreiber „Zwei-Faktor für Mitglieder" aktiviert hat
 * (Security::on('member_2fa')) UND der/die Anmeldende ein reines Mitglied ist.
 * Ist 2FA aus (Default), läuft der normale `wp_login_form`-Login unverändert —
 * dieser Code wird dann nie betreten.
 *
 * Ablauf:
 *  1. Schritt 1 (pp_member_login): Zugangsdaten prüfen → 6-stelligen Code per
 *     E-Mail senden, Pending-Token als HttpOnly-Cookie setzen, KEIN Auth-Cookie.
 *  2. Schritt 2 (pp_member_2fa): Code prüfen → erst dann wp_set_auth_cookie.
 *
 * Härtung: Code nur als Hash gespeichert, 10 Min. gültig, max. 5 Eingabe-
 * versuche, generische Fehlermeldungen (keine User-Enumeration).
 */
class MemberAuth {

	const COOKIE      = 'pp_2fa_token';
	const TTL         = 600; // 10 Minuten
	const MAX_TRIES   = 5;

	public static function init(): void {
		// Login erfolgt durch ausgeloggte Besucher → nopriv-Hooks.
		add_action( 'admin_post_nopriv_pp_member_login', [ self::class, 'handle_login' ] );
		add_action( 'admin_post_nopriv_pp_member_2fa', [ self::class, 'handle_verify' ] );
		add_action( 'admin_post_nopriv_pp_member_register', [ self::class, 'handle_register' ] );
	}

	/* ===================== Selbst-Registrierung ===================== */

	/**
	 * Frontend-Registrierung — nur wenn der Betreiber „Selbst-Registrierung"
	 * aktiviert hat (Security::on('allow_self_registration')). Sonst bleibt es
	 * invite-only und dieser Handler weist ab. Legt einen pp_member-User an und
	 * loggt ihn ein; offene E-Mail-Einladungen werden über den user_register-Hook
	 * automatisch verknüpft.
	 */
	public static function handle_register(): void {
		$portal = MemberPortal::portal_url();

		if ( ! Security::on( 'allow_self_registration' ) ) {
			self::redirect( $portal, [ 'pp_reg' => 'closed' ] );
		}
		if ( ! isset( $_POST['pp_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pp_nonce'] ), 'pp_member_register' ) ) {
			self::redirect( $portal, [ 'pp_reg' => 'failed' ] );
		}
		// Honeypot: echtes Formular lässt pp_website leer.
		if ( ! empty( $_POST['pp_website'] ) ) {
			self::redirect( $portal, [ 'pp_reg' => 'failed' ] );
		}

		$name     = sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) );
		$email    = sanitize_email( wp_unslash( (string) ( $_POST['pp_email'] ?? '' ) ) );
		$password = (string) ( $_POST['pp_password'] ?? '' );

		if ( ! is_email( $email ) ) {
			self::redirect( $portal, [ 'pp_reg' => 'invalid' ] );
		}
		if ( email_exists( $email ) ) {
			self::redirect( $portal, [ 'pp_reg' => 'exists' ] );
		}
		if ( strlen( $password ) < 8 ) {
			self::redirect( $portal, [ 'pp_reg' => 'weakpass' ] );
		}

		// Eindeutigen Benutzernamen aus der E-Mail ableiten.
		$base  = sanitize_user( current( explode( '@', $email ) ), true );
		$login = '' !== $base ? $base : 'member';
		$i     = 2;
		while ( username_exists( $login ) ) {
			$login = $base . $i;
			$i++;
		}

		$user_id = wp_insert_user( [
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => $password,
			'display_name' => '' !== $name ? $name : $login,
			'role'         => 'pp_member',
		] );
		if ( is_wp_error( $user_id ) ) {
			self::redirect( $portal, [ 'pp_reg' => 'failed' ] );
		}

		// Direkt einloggen (Registrierung impliziert Passwort-Besitz).
		wp_set_auth_cookie( (int) $user_id );
		self::redirect( $portal, [] );
	}

	/** 2FA aktiv für diesen User? Nur reine Mitglieder, nur wenn Schalter an. */
	public static function applies_to( \WP_User $user ): bool {
		if ( ! Security::on( 'member_2fa' ) ) {
			return false;
		}
		if ( user_can( $user, 'manage_options' ) || user_can( $user, Capabilities::MANAGE_GROUPS ) ) {
			return false; // Betreiber/Manager nutzen den normalen wp-admin-Login.
		}
		return in_array( 'pp_member', (array) $user->roles, true );
	}

	/** Liegt gerade ein Pending-2FA-Schritt vor (für die UI)? */
	public static function has_pending(): bool {
		return ! empty( $_COOKIE[ self::COOKIE ] ) && false !== self::pending_data();
	}

	/* ===================== Schritt 1: Zugangsdaten ===================== */

	public static function handle_login(): void {
		$portal = MemberPortal::portal_url();

		if ( ! isset( $_POST['pp_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pp_nonce'] ), 'pp_member_login' ) ) {
			self::redirect( $portal, [ 'pp_login' => 'failed' ] );
		}

		$login    = sanitize_text_field( wp_unslash( (string) ( $_POST['log'] ?? '' ) ) );
		$password = (string) ( $_POST['pwd'] ?? '' ); // nicht sanitisieren — wp_authenticate prüft roh.
		$remember = ! empty( $_POST['rememberme'] );

		$user = wp_authenticate( $login, $password );
		if ( is_wp_error( $user ) ) {
			self::redirect( $portal, [ 'pp_login' => 'failed' ] );
		}

		// Kein 2FA nötig → normal einloggen.
		if ( ! self::applies_to( $user ) ) {
			wp_set_auth_cookie( $user->ID, $remember );
			self::redirect( $portal, [] );
		}

		// 2FA: Code erzeugen, mailen, Pending-Token setzen.
		$code  = (string) wp_rand( 100000, 999999 );
		$token = wp_generate_password( 24, false );
		set_transient( 'pp_2fa_' . $token, [
			'user'     => (int) $user->ID,
			'hash'     => wp_hash( $code ),
			'remember' => $remember,
			'tries'    => 0,
		], self::TTL );

		self::send_code( $user, $code );
		self::set_cookie( $token );
		self::redirect( $portal, [ 'pp_2fa' => '1' ] );
	}

	/* ===================== Schritt 2: Code ===================== */

	public static function handle_verify(): void {
		$portal = MemberPortal::portal_url();

		if ( ! isset( $_POST['pp_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pp_nonce'] ), 'pp_member_2fa' ) ) {
			self::redirect( $portal, [ 'pp_login' => 'failed' ] );
		}

		$token = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$data  = self::pending_data();
		if ( '' === $token || false === $data ) {
			self::clear_cookie();
			self::redirect( $portal, [ 'pp_login' => 'failed' ] );
		}

		// Zu viele Versuche → Pending verwerfen.
		if ( (int) $data['tries'] >= self::MAX_TRIES ) {
			delete_transient( 'pp_2fa_' . $token );
			self::clear_cookie();
			self::redirect( $portal, [ 'pp_login' => 'failed' ] );
		}

		$entered = preg_replace( '/\D/', '', (string) ( $_POST['pp_code'] ?? '' ) );
		if ( hash_equals( (string) $data['hash'], wp_hash( $entered ) ) ) {
			$uid = (int) $data['user'];
			delete_transient( 'pp_2fa_' . $token );
			self::clear_cookie();
			wp_set_auth_cookie( $uid, ! empty( $data['remember'] ) );
			self::redirect( $portal, [] );
		}

		// Fehlversuch zählen.
		$data['tries'] = (int) $data['tries'] + 1;
		set_transient( 'pp_2fa_' . $token, $data, self::TTL );
		self::redirect( $portal, [ 'pp_2fa' => '1', 'pp_login' => 'code' ] );
	}

	/* ===================== Intern ===================== */

	/** @return array|false */
	private static function pending_data() {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' === $token ) {
			return false;
		}
		$data = get_transient( 'pp_2fa_' . $token );
		return is_array( $data ) ? $data : false;
	}

	private static function send_code( \WP_User $user, string $code ): void {
		$subject = sprintf(
			/* translators: %s: site name. */
			__( 'Your login code — %s', 'project-prepper' ),
			get_bloginfo( 'name' )
		);
		$body = sprintf(
			/* translators: 1: 6-digit code, 2: minutes valid. */
			__( "Your one-time login code is: %1\$s\n\nIt is valid for %2\$d minutes. If you did not try to sign in, you can ignore this email.", 'project-prepper' ),
			$code,
			(int) ( self::TTL / 60 )
		);
		wp_mail( $user->user_email, $subject, $body );
	}

	private static function set_cookie( string $token ): void {
		setcookie( self::COOKIE, $token, time() + self::TTL, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true );
	}

	private static function clear_cookie(): void {
		setcookie( self::COOKIE, '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true );
	}

	/**
	 * @param array<string,string> $args
	 */
	private static function redirect( string $url, array $args ): void {
		wp_safe_redirect( $args ? add_query_arg( $args, $url ) : $url );
		exit;
	}
}
