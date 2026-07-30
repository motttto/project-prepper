<?php
namespace ProjectPrepper\Email;

defined( 'ABSPATH' ) || exit;

/**
 * SMTP-Versand für wp_mail (Pendant zur E-Mail-Konfiguration der alten App).
 *
 * Konfiguration liegt als Option pp_smtp (nur Betreiber, via REST /settings).
 * Ist SMTP aktiviert + Host gesetzt, wird PHPMailer via phpmailer_init auf
 * SMTP umgestellt. Ein gesetzter Absender überschreibt zusätzlich die
 * wordpress@…-Default-Adresse (wichtig für SPF/Zustellbarkeit).
 */
class Mailer {

	const OPTION = 'pp_smtp';

	public static function init(): void {
		add_action( 'phpmailer_init', [ self::class, 'configure_phpmailer' ] );
		add_filter( 'wp_mail_from', [ self::class, 'filter_from_email' ], 20 );
		add_filter( 'wp_mail_from_name', [ self::class, 'filter_from_name' ], 20 );
	}

	/** Effektive Konfiguration (Defaults + gespeicherte Option). */
	public static function config(): array {
		$defaults = [
			'enabled'    => false,
			'host'       => '',
			'port'       => 587,
			'security'   => 'tls', // none | ssl | tls
			'user'       => '',
			'pass'       => '',
			'from_email' => '',
			'from_name'  => '',
		];
		$saved = get_option( self::OPTION, [] );
		return array_merge( $defaults, is_array( $saved ) ? array_intersect_key( $saved, $defaults ) : [] );
	}

	/** SMTP-Transport aktiv? (aktiviert + Host gesetzt) */
	public static function active(): bool {
		$config = self::config();
		return $config['enabled'] && '' !== $config['host'];
	}

	/**
	 * Konfiguration aus REST-Eingabe säubern + speichern. Ein leeres
	 * Passwort-Feld behält das gespeicherte Passwort (Write-only-Feld im UI).
	 */
	public static function save( array $input ): void {
		$current = self::config();
		$pass    = (string) ( $input['pass'] ?? '' );
		$clean   = [
			'enabled'    => ! empty( $input['enabled'] ),
			'host'       => sanitize_text_field( (string) ( $input['host'] ?? '' ) ),
			'port'       => min( 65535, max( 1, (int) ( $input['port'] ?? 587 ) ) ),
			'security'   => in_array( $input['security'] ?? '', [ 'none', 'ssl', 'tls' ], true ) ? $input['security'] : $current['security'],
			'user'       => sanitize_text_field( (string) ( $input['user'] ?? '' ) ),
			'pass'       => '' !== $pass ? $pass : $current['pass'],
			'from_email' => sanitize_email( (string) ( $input['from_email'] ?? '' ) ),
			'from_name'  => sanitize_text_field( (string) ( $input['from_name'] ?? '' ) ),
		];
		update_option( self::OPTION, $clean, false );
	}

	/** Konfiguration für die REST-Antwort — ohne Passwort, nur ob eins gesetzt ist. */
	public static function public_config(): array {
		$config             = self::config();
		$config['pass_set'] = '' !== $config['pass'];
		unset( $config['pass'] );
		return $config;
	}

	/**
	 * phpmailer_init: PHPMailer auf SMTP umstellen, wenn aktiviert.
	 * Authentifiziert wird, sobald ein Benutzername hinterlegt ist.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer-Instanz von wp_mail.
	 */
	public static function configure_phpmailer( $phpmailer ): void {
		if ( ! self::active() ) {
			return;
		}
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer-API.
		$config          = self::config();
		$phpmailer->isSMTP();
		$phpmailer->Host = $config['host'];
		$phpmailer->Port = $config['port'];
		if ( 'none' === $config['security'] ) {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		} else {
			$phpmailer->SMTPSecure = $config['security'];
		}
		$phpmailer->SMTPAuth = '' !== $config['user'];
		if ( $phpmailer->SMTPAuth ) {
			$phpmailer->Username = $config['user'];
			$phpmailer->Password = $config['pass'];
		}
		// phpcs:enable
	}

	/** wp_mail_from: Absender-Adresse erzwingen, wenn konfiguriert. */
	public static function filter_from_email( $email ) {
		$config = self::config();
		return ( $config['enabled'] && '' !== $config['from_email'] ) ? $config['from_email'] : $email;
	}

	/** wp_mail_from_name: Absender-Name erzwingen, wenn konfiguriert. */
	public static function filter_from_name( $name ) {
		$config = self::config();
		return ( $config['enabled'] && '' !== $config['from_name'] ) ? $config['from_name'] : $name;
	}
}
