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
	}

	public static function repo(): string {
		$repo = defined( 'PP_UPDATE_REPO' ) ? PP_UPDATE_REPO : self::DEFAULT_REPO;
		return (string) apply_filters( 'pp_updater_repo', $repo );
	}

	public static function basename(): string {
		return plugin_basename( PP_PLUGIN_FILE ); // project-prepper/project-prepper.php
	}

	public static function slug(): string {
		return dirname( self::basename() ); // project-prepper
	}

	/**
	 * Daten des letzten GitHub-Releases (6 h gecacht). Per Filter überschreibbar
	 * (Tests / fortgeschrittene Setups). Nicht erreichbar / kein Release → null.
	 *
	 * @return array{version:string,package:string,changelog:string,url:string,published:string,is_asset:bool}|null
	 */
	public static function latest_release(): ?array {
		$override = apply_filters( 'pp_updater_latest_release', null );
		if ( is_array( $override ) ) {
			return $override;
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'none' === $cached ) {
			return null;
		}

		$resp = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			[
				'timeout' => 8,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'project-prepper-updater',
				],
			]
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			set_transient( self::CACHE_KEY, 'none', self::CACHE_TTL );
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, 'none', self::CACHE_TTL );
			return null;
		}
		$rel = self::normalize_release( $data );
		set_transient( self::CACHE_KEY, $rel, self::CACHE_TTL );
		return $rel;
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
		$rel = self::latest_release();
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
