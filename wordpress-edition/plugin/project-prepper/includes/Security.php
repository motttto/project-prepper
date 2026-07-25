<?php
namespace ProjectPrepper;

defined( 'ABSPATH' ) || exit;

/**
 * Sicherheits-Prozesse fürs Frontend (Member-Portal) — zentral schaltbar im
 * Backend. Konservative Defaults; einzige AUSNAHME seit v0.122.0: die
 * Login-Brute-Force-Drosselung ist standardmäßig AN (sicherer Default), alle
 * übrigen Härtungen bleiben opt-in. Jeder Schalter ist im Backend umstellbar.
 *
 * Speicherung: Option `pp_security` (assoziatives Array). Siehe Admin →
 * Project Prepper → Sicherheit.
 */
class Security {

	const OPTION = 'pp_security';

	/** Standardwerte — bewusst konservativ: alles aus / unbegrenzt. */
	public static function defaults(): array {
		return [
			// Frontend-Login gegen Brute-Force sperren (zählt Fehlversuche pro IP).
			// Seit v0.122.0 standardmäßig AN (sicherer Default; WP-Core hat keinen
			// Brute-Force-Schutz). Betreiber können es im Backend abschalten.
			'login_throttle'          => true,
			'login_max_attempts'      => 5,
			'login_lockout_minutes'   => 15,
			// Schneeball-Schutz: max. Anzahl Kollektive, in denen ein User sein darf (0 = unbegrenzt).
			'groups_per_user'         => 0,
			// Max. Einladungen, die ein Mitglied pro 24 h aussprechen darf (0 = unbegrenzt).
			'invites_per_day'         => 0,
			// Offene Selbst-Registrierung erlauben (aus = nur per Einladung, der sichere Default).
			'allow_self_registration' => false,
			// Zwei-Faktor für Mitglieder — vorbereitet, Aktivierung folgt in eigenem Lauf.
			'member_2fa'              => false,
		];
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	/** Bool-Schalter. */
	public static function on( string $key ): bool {
		return (bool) ( self::all()[ $key ] ?? false );
	}

	/** Ganzzahliger Wert (Limits/Schwellen). */
	public static function int( string $key ): int {
		return (int) ( self::all()[ $key ] ?? 0 );
	}

	/* ===================== Durchsetzung ===================== */

	public static function init(): void {
		// Login-Drosselung (nur wenn aktiviert).
		if ( self::on( 'login_throttle' ) ) {
			add_filter( 'authenticate', [ self::class, 'block_if_locked' ], 30, 1 );
			add_action( 'wp_login_failed', [ self::class, 'record_failed_login' ], 10, 1 );
			add_action( 'wp_login', [ self::class, 'clear_failed_login' ], 10, 1 );
		}
		// Selbst-Registrierung (nur wenn aktiviert) — sonst bleibt es invite-only.
		if ( self::on( 'allow_self_registration' ) ) {
			add_filter( 'option_users_can_register', '__return_true' );
			add_filter( 'option_default_role', [ self::class, 'force_member_role' ] );
		}
		// Speichern aus dem Admin-Formular.
		add_action( 'admin_post_pp_save_security', [ self::class, 'handle_save' ] );
	}

	public static function force_member_role(): string {
		return 'pp_member';
	}

	/* ---------- Login-Drosselung ---------- */

	private static function ip_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'pp_sec_lf_' . md5( $ip );
	}

	/**
	 * Blockt die Authentifizierung, wenn das IP-Fehlversuchslimit erreicht ist.
	 *
	 * @param mixed $user WP_User|WP_Error|null
	 * @return mixed
	 */
	public static function block_if_locked( $user ) {
		$attempts = (int) get_transient( self::ip_key() );
		if ( $attempts >= self::int( 'login_max_attempts' ) && self::int( 'login_max_attempts' ) > 0 ) {
			return new \WP_Error(
				'pp_locked',
				sprintf(
					/* translators: %d: minutes until the next login attempt is allowed. */
					__( 'Too many failed attempts. Please try again in about %d minutes.', 'project-prepper' ),
					self::int( 'login_lockout_minutes' )
				)
			);
		}
		return $user;
	}

	public static function record_failed_login(): void {
		$key      = self::ip_key();
		$attempts = (int) get_transient( $key ) + 1;
		set_transient( $key, $attempts, self::int( 'login_lockout_minutes' ) * MINUTE_IN_SECONDS );
	}

	public static function clear_failed_login(): void {
		delete_transient( self::ip_key() );
	}

	/* ===================== Admin-Formular ===================== */

	/**
	 * Werte aus einem (assoziativen) Eingabe-Array säubern + speichern. Quelle
	 * egal — $_POST (Admin-Formular) oder JSON (REST). Gibt die gespeicherten
	 * Werte zurück. Permission/Nonce prüft der Aufrufer.
	 *
	 * @return array Die gespeicherten Werte (= all()).
	 */
	public static function save_from( array $in ): array {
		$data = [
			'login_throttle'          => ! empty( $in['login_throttle'] ),
			'login_max_attempts'      => max( 1, (int) ( $in['login_max_attempts'] ?? 5 ) ),
			'login_lockout_minutes'   => max( 1, (int) ( $in['login_lockout_minutes'] ?? 15 ) ),
			'groups_per_user'         => max( 0, (int) ( $in['groups_per_user'] ?? 0 ) ),
			'invites_per_day'         => max( 0, (int) ( $in['invites_per_day'] ?? 0 ) ),
			'allow_self_registration' => ! empty( $in['allow_self_registration'] ),
			'member_2fa'              => ! empty( $in['member_2fa'] ),
		];
		update_option( self::OPTION, $data );
		return self::all();
	}

	public static function handle_save(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ||
			! isset( $_POST['pp_sec_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['pp_sec_nonce'] ), 'pp_save_security' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'project-prepper' ) );
		}

		self::save_from( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- save_from() casts/sanitisiert jedes Feld einzeln.

		wp_safe_redirect( add_query_arg( 'pp_sec', 'saved', admin_url( 'admin.php?page=pp-security' ) ) );
		exit;
	}
}
