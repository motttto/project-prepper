<?php
namespace ProjectPrepper;

defined( 'ABSPATH' ) || exit;

/**
 * Auslieferungs-Performance: verwaltet einen eigenen Regel-Block in der
 * .htaccess des WordPress-Roots (Kompression + Browser-Caching für statische
 * Assets). Hintergrund: Shared-Hosting-Instanzen liefern HTML/CSS/JS sonst
 * unkomprimiert und ohne Cache-Header aus — bei Vollreload-Navigation des
 * Portals ist das der spürbare Flaschenhals (gemessen 2026-08: 99-KB-CSS
 * unkomprimiert pro Seitenwechsel).
 *
 * Sicherheit/Robustheit:
 *  - Nur auf Apache/LiteSpeed (beide werten .htaccess aus); sonst no-op.
 *  - Alle Direktiven in <IfModule>-Guards — fehlt ein Modul, passiert nichts.
 *  - insert_with_markers() pflegt den Block idempotent zwischen
 *    "# BEGIN/END Project Prepper Performance"-Markern; der WordPress-Block
 *    und fremde Regeln bleiben unangetastet.
 *  - Kein Expires auf text/html — Portal-Seiten sind personalisiert.
 *  - Deaktivieren des Plugins leert den Block wieder.
 */
class Performance {

	const MARKER     = 'Project Prepper Performance';
	const OPTION_KEY = 'pp_perf_htaccess_version';

	public static function init(): void {
		// Nach jedem Plugin-Update einmal (neu) schreiben — unabhängig vom
		// Schema-Versions-Check, der nur bei DB-Änderungen anschlägt. Auf 'init'
		// statt plugins_loaded, damit WP-Umgebungsdaten sicher geladen sind.
		if ( get_option( self::OPTION_KEY ) !== PP_VERSION ) {
			add_action( 'init', [ self::class, 'install' ], 20 );
		}
	}

	/** Regel-Block schreiben/aktualisieren (Aktivierung + nach Updates). */
	public static function install(): void {
		update_option( self::OPTION_KEY, PP_VERSION );
		self::write( self::rules() );
	}

	/** Regel-Block leeren (Deaktivierung). */
	public static function remove(): void {
		delete_option( self::OPTION_KEY );
		self::write( [] );
	}

	/**
	 * @return string[] Zeilen zwischen den Markern.
	 */
	public static function rules(): array {
		return [
			'<IfModule mod_deflate.c>',
			"\tAddOutputFilterByType DEFLATE text/html text/css text/javascript application/javascript application/json application/xml image/svg+xml",
			'</IfModule>',
			'<IfModule mod_expires.c>',
			"\tExpiresActive On",
			// Nur statische Assets — Asset-URLs tragen ?ver=<Plugin-Version>,
			// Updates brechen den Cache also von selbst. HTML bewusst NICHT.
			"\tExpiresByType text/css \"access plus 1 month\"",
			"\tExpiresByType text/javascript \"access plus 1 month\"",
			"\tExpiresByType application/javascript \"access plus 1 month\"",
			"\tExpiresByType image/png \"access plus 1 month\"",
			"\tExpiresByType image/jpeg \"access plus 1 month\"",
			"\tExpiresByType image/gif \"access plus 1 month\"",
			"\tExpiresByType image/webp \"access plus 1 month\"",
			"\tExpiresByType image/svg+xml \"access plus 1 month\"",
			"\tExpiresByType font/woff2 \"access plus 1 month\"",
			'</IfModule>',
		];
	}

	/** Läuft die Seite auf einem Server, der .htaccess auswertet? */
	private static function server_supported(): bool {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		return false !== stripos( $software, 'Apache' ) || false !== stripos( $software, 'LiteSpeed' );
	}

	/**
	 * @param string[] $lines
	 */
	private static function write( array $lines ): void {
		if ( ! self::server_supported() ) {
			return;
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$htaccess = get_home_path() . '.htaccess';
		// Nur bestehende, beschreibbare Datei anfassen (WordPress legt sie mit
		// Permalinks an); scheitert es, bleibt alles beim Alten — kein Fehler.
		if ( ! wp_is_writable( $htaccess ) && ! ( ! file_exists( $htaccess ) && wp_is_writable( dirname( $htaccess ) ) ) ) {
			return;
		}
		insert_with_markers( $htaccess, self::MARKER, $lines );
	}
}
