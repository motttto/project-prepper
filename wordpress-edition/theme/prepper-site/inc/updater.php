<?php
/**
 * Selbst-Updater für den GitHub-Vertrieb des Themes.
 *
 * Das Theme läuft NICHT über wordpress.org, also liefert WordPress von sich aus
 * keine Updates. Dieser Updater hängt sich in den normalen WP-Update-Mechanismus:
 * er liest ein statisches Manifest (theme-update.json im Repo-Root, ausgeliefert
 * über GitHubs raw-CDN) und meldet ein Update, sobald dessen Version neuer ist als
 * die installierte. Ergebnis: „Update verfügbar" unter Design → Themes, Ein-Klick-
 * Update wie bei wordpress.org.
 *
 * Bewusst manifest-only (anders als der Plugin-Updater, der die GitHub-API als
 * Fallback nutzt): Theme-Releases tragen den Tag-Präfix `theme-v`, ein Abruf von
 * /releases/latest würde also das jüngste PLUGIN-Release liefern. Ist das Manifest
 * nicht erreichbar, wird schlicht kein Update gemeldet — nie ein falsches.
 *
 * Vertrauen = HTTPS zu GitHub, wie beim Plugin-Updater. Signaturprüfung bleibt als
 * spätere Härtung offen.
 *
 * @package Prepper_Site
 */

defined( 'ABSPATH' ) || exit;

class Prepper_Site_Updater {

	const DEFAULT_REPO = 'motttto/project-prepper';
	const CACHE_KEY    = 'prepper_site_update';
	const CACHE_TTL    = 6 * HOUR_IN_SECONDS;
	const FAIL_TTL     = 15 * MINUTE_IN_SECONDS;

	public static function init(): void {
		if ( ! apply_filters( 'prepper_site_updater_enabled', ! ( defined( 'PREPPER_SITE_DISABLE_UPDATER' ) && PREPPER_SITE_DISABLE_UPDATER ) ) ) {
			return;
		}
		// Nur dort, wo der WP-Update-Mechanismus überhaupt greift — nicht bei jedem Frontend-Hit.
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_themes', [ self::class, 'inject_update' ] );
		add_filter( 'themes_api', [ self::class, 'details' ], 20, 3 );
		add_filter( 'upgrader_source_selection', [ self::class, 'fix_source_dir' ], 10, 4 );
	}

	/** Verzeichnisname des Themes (= Slug im Update-Transient). */
	public static function slug(): string {
		return get_template();
	}

	/** Installierte Version aus dem style.css-Header. */
	public static function version(): string {
		return (string) wp_get_theme( self::slug() )->get( 'Version' );
	}

	public static function repo(): string {
		$repo = defined( 'PREPPER_SITE_UPDATE_REPO' ) ? PREPPER_SITE_UPDATE_REPO : self::DEFAULT_REPO;
		return (string) apply_filters( 'prepper_site_updater_repo', $repo );
	}

	/**
	 * URL des statischen Update-Manifests (raw-CDN → kein API-Rate-Limit, kein Token).
	 * Per Konstante `PREPPER_SITE_UPDATE_MANIFEST` / Filter überschreibbar; leerer
	 * String schaltet den Updater faktisch ab.
	 */
	public static function manifest_url(): string {
		$default = 'https://raw.githubusercontent.com/' . self::repo() . '/wordpress-edition/theme-update.json';
		$url     = defined( 'PREPPER_SITE_UPDATE_MANIFEST' ) ? (string) PREPPER_SITE_UPDATE_MANIFEST : $default;
		return (string) apply_filters( 'prepper_site_updater_manifest', $url );
	}

	/**
	 * Hat der Nutzer „Erneut prüfen" geklickt? Dann auch den Eigencache verwerfen,
	 * sonst zeigt ein frisches Release erst nach Cache-Ablauf auf.
	 */
	private static function is_forced_check(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reines Lese-Signal; WP setzt force-check selbst, keine Schreibaktion.
		return ! empty( $_GET['force-check'] );
	}

	/**
	 * Manifest-Daten (6 h gecacht). Nicht erreichbar / unbrauchbar → null.
	 *
	 * @param bool $force Cache verwerfen und frisch holen.
	 * @return array{version:string,package:string,changelog:string,url:string,published:string,requires:string,requires_php:string}|null
	 */
	public static function latest_release( bool $force = false ): ?array {
		$override = apply_filters( 'prepper_site_updater_latest_release', null );
		if ( is_array( $override ) ) {
			return $override;
		}

		// Bei erzwungenem Check den Eigencache genau EINMAL pro Request leeren.
		static $forced_fetched = false;
		if ( $force && ! $forced_fetched ) {
			delete_transient( self::CACHE_KEY );
			$forced_fetched = true;
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'none' === $cached ) {
			return null;
		}

		$rel = self::fetch_from_manifest();
		if ( ! is_array( $rel ) ) {
			// Fehlversuche nur kurz cachen, damit der nächste Check bald neu fragt.
			set_transient( self::CACHE_KEY, 'none', self::FAIL_TTL );
			return null;
		}
		set_transient( self::CACHE_KEY, $rel, self::CACHE_TTL );
		return $rel;
	}

	/** Update-Info aus dem statischen Manifest. Null bei Fehler. */
	private static function fetch_from_manifest(): ?array {
		$url = self::manifest_url();
		if ( '' === $url ) {
			return null;
		}
		$resp = wp_remote_get(
			$url,
			[
				'timeout' => 8,
				'headers' => [
					'Accept'     => 'application/json',
					'User-Agent' => 'prepper-site-updater',
				],
			]
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$m = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $m ) || empty( $m['version'] ) || empty( $m['package'] ) ) {
			return null;
		}
		return [
			'version'      => ltrim( (string) $m['version'], 'vV' ),
			'package'      => (string) $m['package'],
			'changelog'    => (string) ( $m['changelog'] ?? '' ),
			'url'          => (string) ( $m['url'] ?? ( 'https://github.com/' . self::repo() ) ),
			'published'    => (string) ( $m['published'] ?? '' ),
			'requires'     => (string) ( $m['requires'] ?? '' ),
			'requires_php' => (string) ( $m['requires_php'] ?? '' ),
		];
	}

	/**
	 * Update in den Theme-Update-Transient einspeisen, wenn das Release neuer ist.
	 * Theme-Einträge sind ARRAYS (anders als bei Plugins, die Objekte erwarten).
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}
		$rel = self::latest_release( self::is_forced_check() );
		if ( ! $rel || '' === (string) $rel['package'] ) {
			return $transient;
		}
		if ( version_compare( $rel['version'], self::version(), '<=' ) ) {
			return $transient; // bereits aktuell.
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}
		$transient->response[ self::slug() ] = [
			'theme'        => self::slug(),
			'new_version'  => $rel['version'],
			'url'          => '' !== (string) $rel['url'] ? $rel['url'] : 'https://github.com/' . self::repo(),
			'package'      => $rel['package'],
			'requires'     => $rel['requires'],
			'requires_php' => $rel['requires_php'],
		];
		return $transient;
	}

	/**
	 * „Details ansehen"-Modal mit Version + Changelog aus dem Manifest.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param object $args
	 * @return mixed
	 */
	public static function details( $result, $action, $args ) {
		if ( 'theme_information' !== $action || empty( $args->slug ) || $args->slug !== self::slug() ) {
			return $result;
		}
		$rel = self::latest_release();
		if ( ! $rel ) {
			return $result;
		}
		return (object) [
			'name'           => 'Prepper Site',
			'slug'           => self::slug(),
			'version'        => $rel['version'],
			'author'         => 'motttto',
			'homepage'       => 'https://github.com/' . self::repo(),
			'download_link'  => $rel['package'],
			'last_updated'   => $rel['published'],
			'requires'       => $rel['requires'],
			'requires_php'   => $rel['requires_php'],
			'sections'       => [
				'changelog' => self::changelog_html( (string) $rel['changelog'] ),
			],
		];
	}

	/**
	 * Entpackten Ordner auf den Theme-Slug umbenennen — nötig, falls das Update aus
	 * einem GitHub-Quell-Tarball kommt (entpackt nach `owner-repo-hash/`). Beim
	 * gebauten Asset-ZIP (`prepper-site/`) ist nichts zu tun.
	 *
	 * @param string $source
	 * @param string $remote_source
	 * @param object $upgrader
	 * @param array  $hook_extra
	 * @return string|\WP_Error
	 */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		if ( empty( $hook_extra['theme'] ) || $hook_extra['theme'] !== self::slug() ) {
			return $source;
		}
		global $wp_filesystem;
		$desired = trailingslashit( $remote_source ) . self::slug();
		if ( untrailingslashit( $source ) === $desired ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), $desired, true ) ) {
			return trailingslashit( $desired );
		}
		return $source;
	}

	/** Sehr einfache Markdown→HTML-Wandlung der Release-Notes (escaped). */
	private static function changelog_html( string $md ): string {
		$out  = '';
		$list = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $md ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^[-*]\s+(.*)/', $line, $m ) ) {
				if ( ! $list ) {
					$out .= '<ul>';
					$list = true;
				}
				$out .= '<li>' . esc_html( $m[1] ) . '</li>';
			} else {
				if ( $list ) {
					$out .= '</ul>';
					$list = false;
				}
				$out .= '<p>' . esc_html( $line ) . '</p>';
			}
		}
		if ( $list ) {
			$out .= '</ul>';
		}
		return '' !== $out ? $out : esc_html__( 'See the GitHub release notes.', 'prepper-site' );
	}
}
