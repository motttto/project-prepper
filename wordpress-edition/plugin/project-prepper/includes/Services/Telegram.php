<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Telegram-Benachrichtigungen (v0.108.0) — Pendant zur App-Edge-Function
 * `telegram-bot`, aber bewusst OUTBOUND-only: EIN Bot-Token pro WP-Instanz
 * (vom Betreiber gesetzt) plus eine chat_id pro Kollektiv (von Gründern
 * gesetzt). Kein eingehender Bot/Command-Handler (bleibt v2.x).
 *
 * Zugriffsmodell:
 * - Bot-Token = Betreiber-Sache (Cap OPERATE) → Option `pp_telegram` oder
 *   Konstante `PP_TELEGRAM_BOT_TOKEN` (Vorrang, wie beim Update-Token). Das
 *   Token wird NIE ins Frontend/Portal ausgegeben und NIE geloggt.
 * - chat_id = Kollektiv-Sache (Gründer) → Spalte `pp_groups.telegram_chat_id`.
 *
 * `send_to_group()` ist robust: fehlt Token/chat_id → kein Versand, kein
 * Fatal; HTTP-/API-Fehler werden still (nur bei WP_DEBUG) geloggt und als
 * WP_Error zurückgegeben, ohne je das Token preiszugeben.
 */
class Telegram {

	const OPTION      = 'pp_telegram';
	const API_BASE    = 'https://api.telegram.org/bot';
	const HTTP_TIMEOUT = 8;

	public static function init(): void {
		add_action( 'admin_post_pp_save_telegram_token', [ self::class, 'handle_save_token' ] );
	}

	/* ===================== Betreiber-Token ===================== */

	/**
	 * Instanz-Bot-Token. Reihenfolge: Konstante `PP_TELEGRAM_BOT_TOKEN` (sicherste,
	 * hat Vorrang) → Option `pp_telegram['bot_token']`. Filterbar für Tests/Deploys.
	 */
	public static function bot_token(): string {
		$token = defined( 'PP_TELEGRAM_BOT_TOKEN' )
			? (string) PP_TELEGRAM_BOT_TOKEN
			: (string) ( self::option()['bot_token'] ?? '' );
		return trim( (string) apply_filters( 'pp_telegram_bot_token', $token ) );
	}

	public static function has_bot_token(): bool {
		return '' !== self::bot_token();
	}

	/** Token per Konstante gesetzt = im UI gesperrt (analog Update-Token). */
	public static function token_locked_by_constant(): bool {
		return defined( 'PP_TELEGRAM_BOT_TOKEN' );
	}

	public static function set_bot_token( string $token ): void {
		$opt               = self::option();
		$opt['bot_token']  = self::sanitize_token( $token );
		update_option( self::OPTION, $opt );
	}

	public static function clear_bot_token(): void {
		$opt              = self::option();
		$opt['bot_token'] = '';
		update_option( self::OPTION, $opt );
	}

	private static function option(): array {
		$opt = get_option( self::OPTION, [] );
		return is_array( $opt ) ? $opt : [];
	}

	/** Token säubern (nur die in Telegram-Tokens vorkommenden Zeichen). */
	public static function sanitize_token( string $token ): string {
		return preg_replace( '/[^A-Za-z0-9:_-]/', '', trim( $token ) );
	}

	/* ===================== Kollektiv chat_id ===================== */

	/**
	 * chat_id einer Gruppe. Numerische ID (Gruppen negativ, z. B.
	 * -1001234567890) oder @channelusername; alles andere wird beim Speichern
	 * (sanitize_chat_id) verworfen.
	 */
	public static function chat_id( int $group_id ): string {
		global $wpdb;
		if ( $group_id <= 0 ) {
			return '';
		}
		return (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT telegram_chat_id FROM %i WHERE id = %d',
			Schema::table( 'groups' ),
			$group_id
		) );
	}

	/**
	 * chat_id-Eingabe validieren: numerische ID oder @username. Ungültiges (oder
	 * leeres) ergibt '' = keine Benachrichtigungen für diese Gruppe.
	 */
	public static function sanitize_chat_id( string $raw ): string {
		$v = trim( $raw );
		if ( '' === $v ) {
			return '';
		}
		if ( preg_match( '/^-?\d{1,32}$/', $v ) ) {
			return $v;
		}
		if ( preg_match( '/^@?[A-Za-z0-9_]{5,32}$/', $v ) ) {
			return '@' . ltrim( $v, '@' );
		}
		return '';
	}

	/** Ist Telegram für diese Gruppe voll konfiguriert (Instanz-Token + chat_id)? */
	public static function is_configured( int $group_id ): bool {
		return self::has_bot_token() && '' !== self::chat_id( $group_id );
	}

	/* ===================== Versand ===================== */

	/**
	 * Kurznachricht an die Telegram-Gruppe eines Kollektivs senden. Text darf
	 * Telegram-HTML enthalten (dynamische Anteile mit self::esc() escapen).
	 *
	 * @return true|WP_Error true bei Erfolg; WP_Error bei fehlender Konfiguration
	 *                       oder HTTP-/API-Fehler (nie fatal).
	 */
	public static function send_to_group( int $group_id, string $text ) {
		$chat_id = self::chat_id( $group_id );
		if ( '' === $chat_id || ! self::has_bot_token() ) {
			return new WP_Error( 'pp_telegram_not_configured', __( 'Telegram is not configured for this collective.', 'project-prepper' ) );
		}
		return self::send( $chat_id, $text );
	}

	/**
	 * Testnachricht an die Gruppe (Gründer-Button im Portal). Guard des Aufrufers.
	 *
	 * @return true|WP_Error
	 */
	public static function send_test( int $group_id ) {
		return self::send_to_group(
			$group_id,
			__( 'Test message from Project Prepper — Telegram notifications are working.', 'project-prepper' )
		);
	}

	/**
	 * Roher Versand an eine chat_id. Kapselt wp_remote_post gegen die
	 * Telegram-Bot-API. Loggt Fehler still (nur bei WP_DEBUG) und OHNE das Token.
	 *
	 * @return true|WP_Error
	 */
	private static function send( string $chat_id, string $text ) {
		$token = self::bot_token();
		if ( '' === $token || '' === $chat_id ) {
			return new WP_Error( 'pp_telegram_not_configured', __( 'Telegram is not configured.', 'project-prepper' ) );
		}

		$response = wp_remote_post( self::API_BASE . $token . '/sendMessage', [
			'timeout' => self::HTTP_TIMEOUT,
			'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
			'body'    => wp_json_encode( [
				'chat_id'                  => $chat_id,
				'text'                     => $text,
				'parse_mode'               => 'HTML',
				'disable_web_page_preview' => true,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			self::log( 'HTTP error: ' . $response->get_error_message() );
			return new WP_Error( 'pp_telegram_http', __( 'The Telegram message could not be delivered.', 'project-prepper' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && is_array( $body ) && ! empty( $body['ok'] ) ) {
			return true;
		}

		// Telegrams Fehlerbeschreibung ist sicher zu loggen (enthält kein Token).
		$desc = is_array( $body ) && isset( $body['description'] ) ? (string) $body['description'] : ( 'HTTP ' . $code );
		self::log( 'API error: ' . $desc );
		return new WP_Error( 'pp_telegram_api', __( 'Telegram rejected the message. Please check the chat ID and bot permissions.', 'project-prepper' ) );
	}

	/** Dynamische Nachrichtenanteile für Telegram-HTML escapen (&, <, >). */
	public static function esc( string $s ): string {
		return str_replace( [ '&', '<', '>' ], [ '&amp;', '&lt;', '&gt;' ], $s );
	}

	/** Fehler still protokollieren — nur bei WP_DEBUG, NIE mit Token/URL. */
	private static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Project Prepper Telegram: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/* ===================== Betreiber-Maske (admin-post) ===================== */

	/** Bot-Token im Admin speichern/entfernen (nur Betreiber:innen, Cap OPERATE). */
	public static function handle_save_token(): void {
		if ( ! current_user_can( Capabilities::OPERATE ) || ! check_admin_referer( 'pp_save_telegram_token' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'project-prepper' ) );
		}
		if ( ! self::token_locked_by_constant() ) {
			$do = sanitize_key( wp_unslash( (string) ( $_POST['pp_do'] ?? 'save' ) ) );
			if ( 'remove' === $do ) {
				self::clear_bot_token();
			} else {
				// sanitize_text_field hier (nicht erst in set_bot_token) für sichtbare
				// Sanitisierung am Eingabepunkt; set_bot_token engt danach auf den
				// Token-Zeichensatz ein.
				$token = isset( $_POST['pp_telegram_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['pp_telegram_token'] ) ) : '';
				if ( '' !== $token ) {
					self::set_bot_token( $token );
				}
			}
		}
		wp_safe_redirect( add_query_arg( 'pp_msg', 'telegram_token_saved', admin_url( 'admin.php?page=pp-settings' ) ) );
		exit;
	}
}
