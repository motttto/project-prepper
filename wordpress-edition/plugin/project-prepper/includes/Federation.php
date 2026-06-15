<?php
namespace ProjectPrepper;

use ProjectPrepper\Services\Groups;
use ProjectPrepper\Services\Inventory;
use ProjectPrepper\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

/**
 * Föderation — Slice 1 (Fernziel aus docs/05): macht diese Instanz für andere
 * wp-prepper-Instanzen **auffindbar** (Opt-in). Andere Instanzen lesen das
 * öffentliche Profil über den Discovery-Endpoint und können nach Postleitzahl /
 * Thema eingrenzen.
 *
 * **Opt-in, per Default AUS.** Solange aus, liefert der Endpoint 404 und es wird
 * nichts veröffentlicht. Das Profil enthält bewusst nur grobe, nicht-personen-
 * bezogene Eckdaten (Name, PLZ, Thema, Anzahl Kollektive/Mitglieder).
 *
 * Instanzübergreifendes Stöbern/Leihen ist ein späterer Slice.
 */
class Federation {

	const OPTION = 'pp_federation';

	public static function defaults(): array {
		return [
			'enabled'        => false,
			'postal_code'    => '',
			'topic'          => '',
			'contact_email'  => '',
			'partners'       => [], // URLs anderer Instanzen (vom Betreiber gepflegt).
			'accept_borrows' => false, // Eingehende föderierte Leih-Anfragen annehmen (Slice 4).
		];
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	public static function enabled(): bool {
		return (bool) self::all()['enabled'];
	}

	/** Nimmt diese Instanz eingehende föderierte Leih-Anfragen an? (Slice 4, default AUS) */
	public static function accept_borrows(): bool {
		return self::enabled() && (bool) self::all()['accept_borrows'];
	}

	/** Ist $url eine vom Betreiber konfigurierte Partner-Instanz? (Trust-Gate) */
	public static function is_known_partner( string $url ): bool {
		$needle = untrailingslashit( $url );
		foreach ( self::partners() as $p ) {
			if ( untrailingslashit( $p ) === $needle ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Öffentliches Profil für den Discovery-Endpoint — nur grobe Eckdaten.
	 */
	public static function public_profile(): array {
		// Identität (Name/Thema/PLZ/Kontakt) wohnt jetzt in der Instanz-Konfiguration.
		return [
			'name'        => Platform::name(),
			'url'         => home_url( '/' ),
			'postal_code' => Platform::postal_code(),
			'topic'       => Platform::topic(),
			'collectives' => count( Groups::all() ),
			'members'     => self::member_count(),
			'contact'     => Platform::contact_email(),
		];
	}

	private static function member_count(): int {
		$q = new \WP_User_Query( [ 'role' => 'pp_member', 'number' => 0, 'count_total' => true, 'fields' => 'ID' ] );
		return (int) $q->get_total();
	}

	/* ===================== Partner-Verzeichnis (Slice 2) ===================== */

	/** @return string[] Konfigurierte Partner-Instanz-URLs. */
	public static function partners(): array {
		$p = self::all()['partners'];
		return is_array( $p ) ? $p : [];
	}

	/**
	 * Öffentliches Profil einer Partner-Instanz abrufen (1 h gecacht).
	 *
	 * Outbound nur an vom Betreiber konfigurierte URLs; liest ausschließlich den
	 * öffentlichen Discovery-Endpoint. Nicht erreichbar/aus → null.
	 *
	 * @return array|null
	 */
	public static function fetch( string $url ): ?array {
		$url = untrailingslashit( $url );
		$key = 'pp_fed_' . md5( $url );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'none' === $cached ) {
			return null;
		}

		$resp = wp_remote_get( $url . '/wp-json/project-prepper/v1/federation/info', [ 'timeout' => 5 ] );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			set_transient( $key, 'none', HOUR_IN_SECONDS );
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) ) {
			set_transient( $key, 'none', HOUR_IN_SECONDS );
			return null;
		}
		set_transient( $key, $data, HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Verzeichnis aller Partner mit (gecachtem) Live-Profil.
	 *
	 * @return array<array{url:string,profile:array|null}>
	 */
	public static function directory(): array {
		$out = [];
		foreach ( self::partners() as $url ) {
			$out[] = [ 'url' => $url, 'profile' => self::fetch( $url ) ];
		}
		return $out;
	}

	/**
	 * Live-Erreichbarkeitsprüfung aller Partner — umgeht den fetch()-Cache und
	 * frischt ihn auf. Für die Health-/Zustellbarkeits-Ansicht der Steuerzentrale,
	 * damit der Betreiber einen Partner-Ausfall sofort (statt erst nach Cache-
	 * Ablauf) sieht.
	 *
	 * @return array<array{url:string,reachable:bool,name:string,error:string}>
	 */
	public static function check_partners(): array {
		$out = [];
		foreach ( self::partners() as $url ) {
			$url  = untrailingslashit( $url );
			$key  = 'pp_fed_' . md5( $url );
			$resp = wp_remote_get( $url . '/wp-json/project-prepper/v1/federation/info', [ 'timeout' => 5 ] );

			if ( is_wp_error( $resp ) ) {
				set_transient( $key, 'none', HOUR_IN_SECONDS );
				$out[] = [ 'url' => $url, 'reachable' => false, 'name' => '', 'error' => $resp->get_error_message() ];
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( 200 !== $code ) {
				set_transient( $key, 'none', HOUR_IN_SECONDS );
				/* translators: %d: HTTP status code. */
				$out[] = [ 'url' => $url, 'reachable' => false, 'name' => '', 'error' => sprintf( __( 'HTTP %d', 'project-prepper' ), $code ) ];
				continue;
			}
			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $data ) ) {
				set_transient( $key, 'none', HOUR_IN_SECONDS );
				$out[] = [ 'url' => $url, 'reachable' => false, 'name' => '', 'error' => __( 'Invalid response', 'project-prepper' ) ];
				continue;
			}
			set_transient( $key, $data, HOUR_IN_SECONDS );
			$out[] = [ 'url' => $url, 'reachable' => true, 'name' => (string) ( $data['name'] ?? '' ), 'error' => '' ];
		}
		return $out;
	}

	/* ===================== Geteiltes Inventar (Slice 3) ===================== */

	/**
	 * Öffentlich teilbarer Inventar-Katalog DIESER Instanz für die Föderation.
	 *
	 * Bewusst dieselbe Whitelist wie das öffentliche Frontend
	 * (Shortcodes::public_item) — nur nutzbare Artikel (kein broken/retired),
	 * KEINE personenbezogenen/internen Felder (kein Owner, keine Seriennummer,
	 * keine Notizen). Der Tagessatz reist nur mit, wenn der Betreiber ihn
	 * öffentlich freigegeben hat (Option pp_public_show_rates). detail_url ist
	 * absolut (zeigt auf die öffentliche Artikelseite dieser Instanz).
	 *
	 * @param int $limit Obergrenze der Payload (Schutz vor Riesen-Antworten).
	 * @return array<array<string,mixed>>
	 */
	public static function public_inventory( int $limit = 200 ): array {
		$items      = Inventory::items( [ 'usable_only' => true ] );
		$show_rates = (bool) get_option( 'pp_public_show_rates', false );
		$out        = [];
		foreach ( array_slice( $items, 0, max( 1, $limit ) ) as $item ) {
			$row = Shortcodes::public_item( $item );
			if ( ! $show_rates ) {
				$row['cost_per_day'] = null;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Geteilten Katalog einer Partner-Instanz abrufen (1 h gecacht).
	 *
	 * Outbound nur an vom Betreiber konfigurierte URLs (SSRF-Schutz wie fetch()),
	 * liest ausschließlich den öffentlichen /federation/inventory-Endpoint.
	 * Nicht erreichbar/aus → null.
	 *
	 * @return array{profile:array,items:array}|null
	 */
	public static function partner_catalog( string $url ): ?array {
		$url    = untrailingslashit( $url );
		$key    = 'pp_fedinv_' . md5( $url );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'none' === $cached ) {
			return null;
		}

		$resp = wp_remote_get( $url . '/wp-json/project-prepper/v1/federation/inventory', [ 'timeout' => 5 ] );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			set_transient( $key, 'none', HOUR_IN_SECONDS );
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
			set_transient( $key, 'none', HOUR_IN_SECONDS );
			return null;
		}
		$catalog = [
			'profile' => ( isset( $data['instance'] ) && is_array( $data['instance'] ) ) ? $data['instance'] : [],
			'items'   => $data['items'],
		];
		set_transient( $key, $catalog, HOUR_IN_SECONDS );
		return $catalog;
	}

	/* ===================== Admin-Formular ===================== */

	public static function init(): void {
		add_action( 'admin_post_pp_save_federation', [ self::class, 'handle_save' ] );
	}

	/**
	 * Partner-URLs aus einer Zeilen-Liste (String) ODER einem Array säubern.
	 * Nur explizite http(s)://-Zeilen, dedupliziert, ohne Trailing-Slash.
	 *
	 * @param string|array $raw
	 * @return string[]
	 */
	public static function clean_partners( $raw ): array {
		$lines    = is_array( $raw ) ? $raw : preg_split( '/\r\n|\r|\n/', (string) $raw );
		$partners = [];
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( ! preg_match( '#^https?://#i', $line ) ) {
				continue;
			}
			$clean = esc_url_raw( $line, [ 'http', 'https' ] );
			if ( $clean ) {
				$partners[] = untrailingslashit( $clean );
			}
		}
		return array_values( array_unique( $partners ) );
	}

	/**
	 * Föderations-Einstellungen aus einem Eingabe-Array säubern + speichern.
	 * Quelle egal ($_POST oder JSON); `partners` darf String (Zeilen) oder Array
	 * sein. Permission/Nonce prüft der Aufrufer.
	 *
	 * @return array Die gespeicherten Werte (= all()).
	 */
	public static function save_from( array $in ): array {
		// Identität (Thema/PLZ/Kontakt) wird auf der Instanz-Seite gepflegt — hier nur
		// noch Discovery an/aus, föderiertes Leihen und die Partner-Liste.
		update_option( self::OPTION, [
			'enabled'        => ! empty( $in['enabled'] ),
			'partners'       => self::clean_partners( $in['partners'] ?? '' ),
			'accept_borrows' => ! empty( $in['accept_borrows'] ),
		] );
		return self::all();
	}

	public static function handle_save(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ||
			! isset( $_POST['pp_fed_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['pp_fed_nonce'] ), 'pp_save_federation' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'project-prepper' ) );
		}

		self::save_from( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- save_from() säubert jedes Feld einzeln.

		wp_safe_redirect( add_query_arg( 'pp_fed', 'saved', admin_url( 'admin.php?page=pp-manage&tab=federation' ) ) );
		exit;
	}
}
