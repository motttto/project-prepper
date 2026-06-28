<?php
namespace ProjectPrepper;

defined( 'ABSPATH' ) || exit;

/**
 * Selbst-Updater für den GitHub-Vertrieb (docs/06 §12).
 *
 * Da das Plugin NICHT über wordpress.org läuft, liefert WordPress keine Updates.
 * Dieser Updater hängt sich in den normalen WP-Update-Mechanismus: er prüft das
 * letzte GitHub-Release, und wenn dessen Tag neuer als PP_VERSION ist, erscheint
 * unter Plugins „Update verfügbar" mit Ein-Klick-Update wie bei wordpress.org.
 *
 * Bevorzugt wird das an das Release angehängte **gebaute** ZIP
 * (project-prepper-x.y.z.zip aus build.sh) — dessen Top-Ordner heißt bereits
 * `project-prepper/`. Falls nur der GitHub-Quell-Tarball vorliegt, benennt
 * `fix_source_dir()` den entpackten Ordner passend um.
 *
 * Vertrauen = HTTPS zur GitHub-API (Standard wie bei plugin-update-checker).
 * Signaturprüfung ist bewusst als spätere Härtung offen (vgl. CRA-Reifegrad).
 */
class Updater {

	/** Default-Repo (owner/repo). Per Filter/Konstante überschreibbar (Fork/eigenes Repo). */
	const DEFAULT_REPO = 'motttto/project-prepper';
	const CACHE_KEY    = 'pp_update_release';
	const CACHE_TTL    = 6 * HOUR_IN_SECONDS;
	const FAIL_TTL     = 15 * MINUTE_IN_SECONDS;
	const TOKEN_OPTION = 'pp_update_token';

	public static function init(): void {
		if ( ! apply_filters( 'pp_updater_enabled', ! ( defined( 'PP_DISABLE_UPDATER' ) && PP_DISABLE_UPDATER ) ) ) {
			return;
		}
		// Nur dort, wo der WP-Update-Mechanismus überhaupt greift — nicht bei jedem Frontend-Hit.
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', [ self::class, 'inject_update' ] );
		add_filter( 'plugins_api', [ self::class, 'details' ], 20, 3 );
		add_filter( 'upgrader_source_selection', [ self::class, 'fix_source_dir' ], 10, 4 );
		add_action( 'admin_post_pp_save_update_token', [ self::class, 'handle_save_token' ] );
	}

	/** Update-Token im Admin speichern/entfernen (nur Betreiber:innen). */
	public static function handle_save_token(): void {
		if ( ! current_user_can( Capabilities::OPERATE ) || ! check_admin_referer( 'pp_save_update_token' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'project-prepper' ) );
		}
		if ( ! self::token_locked_by_constant() ) {
			$do = sanitize_key( wp_unslash( (string) ( $_POST['pp_do'] ?? 'save' ) ) );
			if ( 'remove' === $do ) {
				delete_option( self::TOKEN_OPTION );
			} else {
				$token = isset( $_POST['pp_update_token'] ) ? trim( (string) wp_unslash( $_POST['pp_update_token'] ) ) : '';
				if ( '' !== $token ) {
					update_option( self::TOKEN_OPTION, sanitize_text_field( $token ) );
				}
			}
			// Cache leeren, damit der nächste Check sofort (authentifiziert) frisch fragt.
			delete_transient( self::CACHE_KEY );
		}
		wp_safe_redirect( add_query_arg( 'pp_msg', 'token_saved', admin_url( 'admin.php?page=pp-security' ) ) );
		exit;
	}

	public static function repo(): string {
		$repo = defined( 'PP_UPDATE_REPO' ) ? PP_UPDATE_REPO : self::DEFAULT_REPO;
		return (string) apply_filters( 'pp_updater_repo', $repo );
	}

	/**
	 * Optionales GitHub-Token für authentifizierte API-Abrufe (5000/h statt 60/h).
	 * Reihenfolge: Konstante `PP_UPDATE_TOKEN` (sicherste, hat Vorrang) → Option
	 * `pp_update_token` (im Admin setzbar) → Filter `pp_updater_token`. Für ein
	 * öffentliches Repo genügt ein Token OHNE Scopes.
	 */
	public static function token(): string {
		$token = defined( 'PP_UPDATE_TOKEN' ) ? (string) PP_UPDATE_TOKEN : (string) get_option( self::TOKEN_OPTION, '' );
		return trim( (string) apply_filters( 'pp_updater_token', $token ) );
	}

	/** Token nur aus der DB-Option setzbar? (Per Konstante gesetzt = im UI gesperrt.) */
	public static function token_locked_by_constant(): bool {
		return defined( 'PP_UPDATE_TOKEN' );
	}

	public static function basename(): string {
		return plugin_basename( PP_PLUGIN_FILE ); // project-prepper/project-prepper.php
	}

	public static function slug(): string {
		return dirname( self::basename() ); // project-prepper
	}

	/**
	 * Hat der Nutzer einen erzwungenen Update-Check ausgelöst? WordPress setzt
	 * `force-check` beim Klick auf „Erneut prüfen" (update-core.php) — dann soll
	 * auch unser eigener 6-h-Cache verworfen werden, sonst zeigt ein frisch
	 * öffentlich gewordenes Release erst nach Cache-Ablauf auf.
	 */
	private static function is_forced_check(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reines Lese-Signal; WP setzt force-check selbst, keine Schreibaktion.
		return ! empty( $_GET['force-check'] );
	}

	/**
	 * Daten des letzten GitHub-Releases (6 h gecacht). Per Filter überschreibbar
	 * (Tests / fortgeschrittene Setups). Nicht erreichbar / kein Release → null.
	 *
	 * @param bool $force Cache verwerfen und frisch von GitHub holen (erzwungener Check).
	 * @return array{version:string,package:string,changelog:string,url:string,published:string,is_asset:bool}|null
	 */
	public static function latest_release( bool $force = false ): ?array {
		$override = apply_filters( 'pp_updater_latest_release', null );
		if ( is_array( $override ) ) {
			return $override;
		}

		// Bei erzwungenem Check den Eigencache genau EINMAL pro Request leeren —
		// die anschließende set_transient()-Frischung verhindert Mehrfach-Fetches.
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

		// Bevorzugt das statische Manifest (über GitHubs CDN, daher KEIN 60/h-API-
		// Limit und kein Token nötig); Fallback auf die GitHub-API (mit Token).
		$rel = self::fetch_from_manifest();
		if ( ! is_array( $rel ) ) {
			$rel = self::fetch_from_api();
		}
		// Fehlversuche (z. B. 403 Rate-Limit) nur kurz cachen, damit ein erneuter
		// Check bald wieder die Quelle fragt, statt 6 h „kein Release" festzuhalten.
		if ( ! is_array( $rel ) ) {
			set_transient( self::CACHE_KEY, 'none', self::FAIL_TTL );
			return null;
		}
		set_transient( self::CACHE_KEY, $rel, self::CACHE_TTL );
		return $rel;
	}

	/**
	 * URL des statischen Update-Manifests (über GitHubs CDN ausgeliefert, daher
	 * KEIN 60/h-API-Limit und kein Token nötig). Default: raw-URL des Vertriebs-
	 * Branches. Per Konstante `PP_UPDATE_MANIFEST` / Filter `pp_updater_manifest`
	 * überschreibbar; leerer String schaltet das Manifest ab (nur API).
	 */
	public static function manifest_url(): string {
		$default = 'https://raw.githubusercontent.com/' . self::repo() . '/wordpress-edition/update.json';
		$url     = defined( 'PP_UPDATE_MANIFEST' ) ? (string) PP_UPDATE_MANIFEST : $default;
		return (string) apply_filters( 'pp_updater_manifest', $url );
	}

	/** Update-Info aus dem statischen Manifest (update.json). Null bei Fehler. */
	private static function fetch_from_manifest(): ?array {
		$url = self::manifest_url();
		if ( '' === $url ) {
			return null;
		}
		$resp = wp_remote_get( $url, [
			'timeout' => 8,
			'headers' => [ 'Accept' => 'application/json', 'User-Agent' => 'project-prepper-updater' ],
		] );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$m = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $m ) || empty( $m['version'] ) || empty( $m['package'] ) ) {
			return null;
		}
		return [
			'version'   => ltrim( (string) $m['version'], 'vV' ),
			'package'   => (string) $m['package'],
			'changelog' => (string) ( $m['changelog'] ?? '' ),
			'url'       => (string) ( $m['url'] ?? ( 'https://github.com/' . self::repo() ) ),
			'published' => (string) ( $m['published'] ?? '' ),
			'is_asset'  => true,
		];
	}

	/** Update-Info aus der GitHub-API (Fallback; nutzt optionales Token). Null bei Fehler. */
	private static function fetch_from_api(): ?array {
		$headers = [
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'project-prepper-updater',
		];
		// Optionales GitHub-Token hebt das 60/h-Limit unauthentifizierter Abrufe
		// (problematisch auf Shared-Hosting mit geteilter IP) auf 5000/h.
		$gh_token = self::token();
		if ( '' !== $gh_token ) {
			$headers['Authorization'] = 'Bearer ' . $gh_token;
		}
		$resp = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			[
				'timeout' => 8,
				'headers' => $headers,
			]
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}
		return self::normalize_release( $data );
	}

	/** GitHub-Release-Payload auf die fürs Update nötigen Felder reduzieren. */
	public static function normalize_release( array $data ): array {
		$version = ltrim( (string) $data['tag_name'], 'vV' );

		// Bevorzugt das gebaute project-prepper-*.zip als Release-Asset.
		$package  = '';
		$is_asset = false;
		foreach ( (array) ( $data['assets'] ?? [] ) as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );
			if ( preg_match( '/^project-prepper-.*\.zip$/i', $name ) && ! empty( $asset['browser_download_url'] ) ) {
				$package  = (string) $asset['browser_download_url'];
				$is_asset = true;
				break;
			}
		}
		if ( '' === $package ) {
			$package = (string) ( $data['zipball_url'] ?? '' );
		}

		return [
			'version'   => $version,
			'package'   => $package,
			'changelog' => (string) ( $data['body'] ?? '' ),
			'url'       => (string) ( $data['html_url'] ?? '' ),
			'published' => (string) ( $data['published_at'] ?? '' ),
			'is_asset'  => $is_asset,
		];
	}

	/**
	 * Update in den Plugin-Update-Transient einspeisen, wenn das Release neuer ist.
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
		if ( version_compare( $rel['version'], PP_VERSION, '<=' ) ) {
			return $transient; // bereits aktuell.
		}

		$item = (object) [
			'slug'        => self::slug(),
			'plugin'      => self::basename(),
			'new_version' => $rel['version'],
			'url'         => '' !== (string) $rel['url'] ? $rel['url'] : 'https://github.com/' . self::repo(),
			'package'     => $rel['package'],
		];
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}
		$transient->response[ self::basename() ] = $item;
		return $transient;
	}

	/**
	 * „Details ansehen"-Modal mit Versionsinfo + Changelog aus den Release-Notes.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param object $args
	 * @return mixed
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== self::slug() ) {
			return $result;
		}
		$rel = self::latest_release();
		if ( ! $rel ) {
			return $result;
		}
		return (object) [
			'name'          => 'Project Prepper',
			'slug'          => self::slug(),
			'version'       => $rel['version'],
			'author'        => 'motttto',
			'homepage'      => 'https://github.com/' . self::repo(),
			'download_link' => $rel['package'],
			'last_updated'  => $rel['published'],
			'sections'      => [
				'changelog' => self::changelog_html( (string) $rel['changelog'] ),
			],
		];
	}

	/**
	 * Entpackten Quell-Ordner auf den Plugin-Slug umbenennen — nötig, falls das
	 * Update aus dem GitHub-Quell-Tarball kommt (entpackt nach `owner-repo-hash/`).
	 * Beim gebauten Asset-ZIP (`project-prepper/`) ist nichts zu tun.
	 *
	 * @param string $source
	 * @param string $remote_source
	 * @param object $upgrader
	 * @param array  $hook_extra
	 * @return string|\WP_Error
	 */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== self::basename() ) {
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
					$out  .= '<ul>';
					$list  = true;
				}
				$out .= '<li>' . esc_html( $m[1] ) . '</li>';
			} else {
				if ( $list ) {
					$out  .= '</ul>';
					$list  = false;
				}
				$out .= '<p>' . esc_html( $line ) . '</p>';
			}
		}
		if ( $list ) {
			$out .= '</ul>';
		}
		return '' !== $out ? $out : esc_html__( 'See the GitHub release notes.', 'project-prepper' );
	}
}
