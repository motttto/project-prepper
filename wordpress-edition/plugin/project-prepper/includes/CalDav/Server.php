<?php
namespace ProjectPrepper\CalDav;

use ProjectPrepper\Schema;
use ProjectPrepper\Rest\CalendarController;
use ProjectPrepper\Services\CalendarEvents;
use ProjectPrepper\Services\Groups;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * CalDAV-Zweiweg-Server (v0.33.0) — Pendant zum CalDAV-Teil der App (Cloudflare-
 * Worker `caldav-proxy`), hier WordPress-nativ. Externe Kalender-Clients (Apple
 * Kalender, Thunderbird, DAVx5) können die Portal-Kalender ABONNIEREN und
 * Termine anlegen/ändern/löschen; die Änderungen landen in pp_calendar_events.
 *
 * Der read-only iCal-Feed (CalendarController) bleibt daneben bestehen.
 *
 * ## Routing
 * WP/REST kann die WebDAV-Methoden (PROPFIND/REPORT/PROPPATCH …) nicht sauber.
 * Wir fangen den Pfad-Präfix `/pp-caldav/` früh auf `parse_request` ab, lesen
 * REQUEST_METHOD + php://input roh und dispatchen selbst (eigene Header/Status).
 * Eine Rewrite-Rule sorgt nebenbei für saubere URLs; die eigentliche Erkennung
 * läuft aber über REQUEST_URI und ist damit flush-unabhängig.
 *
 * ## Auth
 * HTTP Basic Auth (so verbinden CalDAV-Clients): Benutzername = WP user_login,
 * Passwort = das persönliche pp_ical_token des Users (konstantzeit-verglichen).
 * Kein Cookie/Nonce. Fehl-Auth → 401 mit WWW-Authenticate.
 *
 * ## Rechte
 * Der Token bestimmt den User; ausgeliefert werden NUR dessen Kalender: Solo
 * (owner_user_id) + Kalender seiner Kollektive (owner_group_id, aktive
 * Mitgliedschaft). Jede Collection wird bei Zugriff erneut gegen den Token-User
 * geprüft — kein Cross-User-Leak/-Write über geratene Pfade.
 *
 * ## Collections
 * Eine Collection je pp_calendar_groups-Zeile (`cal-<id>`) plus je Arbeitsbereich
 * ein „ohne Kalender"-Eimer (`ws-u<uid>` / `ws-g<gid>`) für Termine mit
 * calendar_group_id = 0, damit kein Termin unsichtbar bleibt.
 *
 * ## Bewusst offen (v2.x+)
 * Echte Zeitzonen/VTIMEZONE (Zeiten sind floating local wie der Feed), Free/Busy,
 * Scheduling/iTIP, wiederkehrende Termine (RRULE), inkrementelles sync-collection
 * (liefert derzeit den vollen Stand).
 */
class Server {

	const BASE = 'pp-caldav';
	const QUERY_VAR = 'pp_caldav_path';
	const REALM = 'Project Prepper CalDAV';

	/** @var string|null Roh-Body (einmalig aus php://input gelesen). */
	private static $body_cache = null;

	public static function init(): void {
		// Rewrite früh (Prio 5) registrieren, damit der gemeinsame Flush in
		// Frontend\ItemDetail (Prio 10) die Regel mit erfasst.
		add_action( 'init', [ self::class, 'register_rewrite' ], 5 );
		add_filter( 'query_vars', [ self::class, 'register_query_var' ] );
		add_action( 'parse_request', [ self::class, 'maybe_dispatch' ], 0 );
	}

	public static function register_rewrite(): void {
		add_rewrite_rule( '^' . self::BASE . '/?(.*)$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public static function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/** Basis-URL des CalDAV-Servers (für Portal-Anzeige). */
	public static function base_url(): string {
		return home_url( '/' . self::BASE . '/' );
	}

	/** Server-absoluter Basis-Pfad (für href-Ausgabe in den XML-Antworten). */
	private static function base_path(): string {
		return (string) wp_parse_url( self::base_url(), PHP_URL_PATH );
	}

	/* ===================== Dispatch ===================== */

	/**
	 * Auf jedem Frontend-Request: gehört die URL uns? Wenn ja, komplett selbst
	 * beantworten und beenden; sonst sofort zurück (WP läuft normal weiter).
	 */
	public static function maybe_dispatch( $wp ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$path = self::request_path();
		if ( null === $path ) {
			return;
		}

		// .well-known/caldav → dauerhafte Umleitung auf die Basis (Discovery-Komfort).
		if ( '.well-known/caldav' === $path ) {
			header( 'Location: ' . self::base_url() );
			status_header( 301 );
			exit;
		}

		if ( self::BASE !== $path && 0 !== strpos( $path, self::BASE . '/' ) ) {
			return; // nicht unsere URL
		}

		$sub      = ( self::BASE === $path ) ? '' : substr( $path, strlen( self::BASE ) + 1 );
		$sub      = trim( $sub, '/' );
		$segments = '' === $sub ? [] : array_map( 'rawurldecode', explode( '/', $sub ) );
		$method   = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		// OPTIONS beantworten CalDAV-Clients auch unauthentifiziert (Capability-Probe).
		if ( 'OPTIONS' === $method ) {
			self::send_options();
		}

		$uid = self::authenticate();
		if ( ! $uid ) {
			self::send_401();
		}

		self::route( $method, $segments, $uid );
		self::send_status( 404, 'Not Found' ); // Fallback (route beendet normalerweise selbst).
	}

	/** Angefragter Pfad ohne Home-Präfix und Slashes, oder null wenn irrelevant. */
	private static function request_path(): ?string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $uri ) {
			return null;
		}
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = trim( $path, '/' );

		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home ) {
			if ( 0 === strpos( $path, $home . '/' ) ) {
				$path = substr( $path, strlen( $home ) + 1 );
			} elseif ( $path === $home ) {
				$path = '';
			}
		}
		return $path;
	}

	/**
	 * Nach Methode + Pfadsegmenten verzweigen. Segmente:
	 *   []                         → Server-Wurzel (current-user-principal)
	 *   [principal]                → Principal
	 *   [calendars]                → calendar-home-set
	 *   [calendars, <colid>]       → eine Kalender-Sammlung
	 *   [calendars, <colid>, <r>]  → eine Termin-Ressource (<uid>.ics)
	 */
	private static function route( string $method, array $segments, int $uid ): void {
		$count = count( $segments );

		if ( 0 === $count ) {
			if ( 'PROPFIND' === $method ) {
				self::propfind_root( $uid );
			}
			self::send_status( 405, 'Method Not Allowed' );
		}

		if ( 'principal' === $segments[0] && 1 === $count ) {
			if ( 'PROPFIND' === $method ) {
				self::propfind_principal( $uid );
			}
			self::send_status( 405, 'Method Not Allowed' );
		}

		if ( 'calendars' === $segments[0] ) {
			if ( 1 === $count ) {
				if ( 'PROPFIND' === $method ) {
					self::propfind_home( $uid );
				}
				self::send_status( 405, 'Method Not Allowed' );
			}

			$col = self::resolve_collection( (string) $segments[1], $uid );
			if ( null === $col ) {
				self::send_status( 404, 'Not Found' );
			}

			if ( 2 === $count ) {
				self::handle_collection( $method, $col );
			}
			if ( 3 === $count ) {
				self::handle_resource( $method, $col, (string) $segments[2] );
			}
		}

		self::send_status( 404, 'Not Found' );
	}

	/* ===================== Auth ===================== */

	/** @return int Token-User-ID oder 0. */
	private static function authenticate(): int {
		list( $login, $pass ) = self::basic_credentials();
		if ( '' === $login || '' === $pass ) {
			return 0;
		}
		$user = get_user_by( 'login', $login );
		if ( ! $user ) {
			return 0;
		}
		$token = (string) get_user_meta( $user->ID, CalendarController::USER_TOKEN_META, true );
		if ( '' === $token ) {
			return 0;
		}
		return hash_equals( $token, $pass ) ? (int) $user->ID : 0;
	}

	/** @return array{0:string,1:string} [ login, password ] aus Basic Auth. */
	private static function basic_credentials(): array {
		$login = isset( $_SERVER['PHP_AUTH_USER'] ) ? (string) wp_unslash( $_SERVER['PHP_AUTH_USER'] ) : '';
		$pass  = isset( $_SERVER['PHP_AUTH_PW'] ) ? (string) wp_unslash( $_SERVER['PHP_AUTH_PW'] ) : '';

		if ( '' === $login ) {
			$header = '';
			if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
				$header = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
			} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
				$header = (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
			}
			if ( 0 === stripos( $header, 'Basic ' ) ) {
				$decoded = base64_decode( substr( $header, 6 ), true );
				if ( is_string( $decoded ) && false !== strpos( $decoded, ':' ) ) {
					list( $login, $pass ) = explode( ':', $decoded, 2 );
				}
			}
		}
		return [ $login, $pass ];
	}

	/* ===================== Collections ===================== */

	/**
	 * Alle Collections, auf die der Token-User Zugriff hat.
	 *
	 * @return array<array<string,mixed>>
	 */
	private static function list_collections( int $uid ): array {
		$cols = [];
		foreach ( CalendarEvents::calendars( $uid, 0 ) as $c ) {
			$cols[] = self::calendar_descriptor( $c, $uid, 0 );
		}
		$cols[] = self::bucket_descriptor( $uid, 0, __( 'My events', 'project-prepper' ) );

		foreach ( Groups::user_groups( $uid ) as $g ) {
			$gid = (int) $g->id;
			foreach ( CalendarEvents::calendars( $uid, $gid ) as $c ) {
				$cols[] = self::calendar_descriptor( $c, $uid, $gid );
			}
			/* translators: %s: group name */
			$cols[] = self::bucket_descriptor( $uid, $gid, sprintf( __( '%s — events', 'project-prepper' ), $g->name ) );
		}
		return $cols;
	}

	/**
	 * Eine Collection-Kennung auflösen und gegen den Token-User absichern.
	 * NULL = unbekannt oder kein Zugriff (→ 404, kein Leak).
	 *
	 * @return array<string,mixed>|null
	 */
	private static function resolve_collection( string $colid, int $uid ): ?array {
		if ( preg_match( '/^cal-(\d+)$/', $colid, $m ) ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				Schema::table( 'calendar_groups' ),
				(int) $m[1]
			) );
			if ( ! $row ) {
				return null;
			}
			$owner_user  = (int) $row->owner_user_id;
			$owner_group = (int) $row->owner_group_id;
			if ( $owner_group > 0 ) {
				return Groups::is_member( $owner_group, $uid ) ? self::calendar_descriptor( $row, $uid, $owner_group ) : null;
			}
			return ( $owner_user === $uid ) ? self::calendar_descriptor( $row, $uid, 0 ) : null;
		}
		if ( preg_match( '/^ws-u(\d+)$/', $colid, $m ) ) {
			return ( (int) $m[1] === $uid ) ? self::bucket_descriptor( $uid, 0, __( 'My events', 'project-prepper' ) ) : null;
		}
		if ( preg_match( '/^ws-g(\d+)$/', $colid, $m ) ) {
			$gid = (int) $m[1];
			if ( ! Groups::is_member( $gid, $uid ) ) {
				return null;
			}
			$g = Groups::get( $gid );
			/* translators: %s: group name */
			return self::bucket_descriptor( $uid, $gid, $g ? sprintf( __( '%s — events', 'project-prepper' ), $g->name ) : __( 'Group events', 'project-prepper' ) );
		}
		return null;
	}

	/** @return array<string,mixed> */
	private static function calendar_descriptor( object $c, int $uid, int $gid ): array {
		return [
			'id'                => 'cal-' . (int) $c->id,
			'acting_user'       => $uid,
			'group_id'          => $gid,
			'calendar_group_id' => (int) $c->id,
			'name'              => (string) $c->name,
			'color'             => (string) $c->color,
		];
	}

	/** @return array<string,mixed> */
	private static function bucket_descriptor( int $uid, int $gid, string $name ): array {
		return [
			'id'                => $gid ? 'ws-g' . $gid : 'ws-u' . $uid,
			'acting_user'       => $uid,
			'group_id'          => $gid,
			'calendar_group_id' => 0,
			'name'              => $name,
			'color'             => CalendarEvents::COLORS[0],
		];
	}

	/** @return array<object> Termine der Collection. */
	private static function collection_events( array $col ): array {
		return CalendarEvents::events_in_calendar( $col['acting_user'], $col['group_id'], $col['calendar_group_id'] );
	}

	/** Opaker CTag/Sync-Hash der Collection (ändert sich bei jeder Termin-Änderung). */
	private static function collection_hash( array $col ): string {
		$events = self::collection_events( $col );
		$max    = '';
		foreach ( $events as $e ) {
			if ( (string) $e->updated_at > $max ) {
				$max = (string) $e->updated_at;
			}
		}
		return md5( $col['id'] . '|' . count( $events ) . '|' . $max );
	}

	/* ===================== PROPFIND ===================== */

	private static function propfind_root( int $uid ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$base = self::base_path();
		$xml  = self::response(
			$base,
			'<d:resourcetype><d:collection/></d:resourcetype>'
			. '<d:current-user-principal><d:href>' . self::x( $base . 'principal/' ) . '</d:href></d:current-user-principal>'
			. '<d:principal-URL><d:href>' . self::x( $base . 'principal/' ) . '</d:href></d:principal-URL>'
		);
		self::send_multistatus( $xml );
	}

	private static function propfind_principal( int $uid ): void {
		$base = self::base_path();
		$user = get_userdata( $uid );
		$name = $user ? $user->display_name : 'Principal';
		$mail = $user ? $user->user_email : '';

		$prop = '<d:resourcetype><d:principal/></d:resourcetype>'
			. '<d:displayname>' . self::x( $name ) . '</d:displayname>'
			. '<d:current-user-principal><d:href>' . self::x( $base . 'principal/' ) . '</d:href></d:current-user-principal>'
			. '<d:principal-URL><d:href>' . self::x( $base . 'principal/' ) . '</d:href></d:principal-URL>'
			. '<cal:calendar-home-set><d:href>' . self::x( $base . 'calendars/' ) . '</d:href></cal:calendar-home-set>';
		if ( '' !== $mail ) {
			$prop .= '<cal:calendar-user-address-set><d:href>' . self::x( 'mailto:' . $mail ) . '</d:href></cal:calendar-user-address-set>';
		}
		self::send_multistatus( self::response( $base . 'principal/', $prop ) );
	}

	private static function propfind_home( int $uid ): void {
		$base  = self::base_path();
		$depth = self::depth();

		$body = self::response(
			$base . 'calendars/',
			'<d:resourcetype><d:collection/></d:resourcetype>'
			. '<d:displayname>' . self::x( get_bloginfo( 'name' ) ) . '</d:displayname>'
			. '<d:current-user-principal><d:href>' . self::x( $base . 'principal/' ) . '</d:href></d:current-user-principal>'
		);

		if ( '0' !== $depth ) {
			foreach ( self::list_collections( $uid ) as $col ) {
				$body .= self::calendar_response( $col );
			}
		}
		self::send_multistatus( $body );
	}

	/** Eine Kalender-Sammlung: PROPFIND (Depth 0/1) sowie GET des Gesamt-VCALENDAR. */
	private static function handle_collection( string $method, array $col ): void {
		if ( 'PROPFIND' === $method ) {
			$body = self::calendar_response( $col );
			if ( '0' !== self::depth() ) {
				$host = self::host();
				foreach ( self::collection_events( $col ) as $e ) {
					$body .= self::event_response( $col, $e, $host, false );
				}
			}
			self::send_multistatus( $body );
		}
		if ( 'REPORT' === $method ) {
			self::report( $col );
		}
		if ( 'GET' === $method ) {
			$host  = self::host();
			$lines = [ 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Project Prepper//CalDAV//EN', 'CALSCALE:GREGORIAN' ];
			foreach ( self::collection_events( $col ) as $e ) {
				$lines = array_merge( $lines, ICal::vevent_lines( $e, $host ) );
			}
			$lines[] = 'END:VCALENDAR';
			self::send_body( 200, implode( "\r\n", $lines ) . "\r\n", 'text/calendar; charset=utf-8' );
		}
		self::send_status( 405, 'Method Not Allowed' );
	}

	/** XML-<response> einer Kalender-Sammlung. */
	private static function calendar_response( array $col ): string {
		$base = self::base_path();
		$href = $base . 'calendars/' . rawurlencode( $col['id'] ) . '/';
		$hash = self::collection_hash( $col );
		$color = self::x( $col['color'] . 'FF' );

		$prop = '<d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>'
			. '<d:displayname>' . self::x( $col['name'] ) . '</d:displayname>'
			. '<cal:supported-calendar-component-set><cal:comp name="VEVENT"/></cal:supported-calendar-component-set>'
			. '<cs:getctag>pp-' . $hash . '</cs:getctag>'
			. '<d:sync-token>' . self::x( $base . 'ns/sync/' . $hash ) . '</d:sync-token>'
			. '<ical:calendar-color>' . $color . '</ical:calendar-color>'
			. '<d:current-user-privilege-set>'
			. '<d:privilege><d:read/></d:privilege>'
			. '<d:privilege><d:write/></d:privilege>'
			. '<d:privilege><d:write-content/></d:privilege>'
			. '<d:privilege><d:bind/></d:privilege>'
			. '<d:privilege><d:unbind/></d:privilege>'
			. '</d:current-user-privilege-set>'
			. '<d:supported-report-set>'
			. '<d:supported-report><d:report><cal:calendar-query/></d:report></d:supported-report>'
			. '<d:supported-report><d:report><cal:calendar-multiget/></d:report></d:supported-report>'
			. '<d:supported-report><d:report><d:sync-collection/></d:report></d:supported-report>'
			. '</d:supported-report-set>';
		return self::response( $href, $prop );
	}

	/* ===================== REPORT ===================== */

	private static function report( array $col ): void {
		$body = self::request_body();
		$host = self::host();

		if ( false !== stripos( $body, 'calendar-multiget' ) ) {
			$out = '';
			if ( preg_match_all( '/<[^>]*href[^>]*>([^<]+)<\/[^>]*href\s*>/i', $body, $mm ) ) {
				foreach ( $mm[1] as $href ) {
					$resource = rawurldecode( basename( trim( $href ) ) );
					$event    = self::resolve_event( $col, $resource );
					if ( $event ) {
						$out .= self::event_response( $col, $event, $host, true );
					} else {
						$out .= '<d:response><d:href>' . self::x( trim( $href ) ) . '</d:href>'
							. '<d:status>HTTP/1.1 404 Not Found</d:status></d:response>';
					}
				}
			}
			self::send_multistatus( $out );
		}

		// calendar-query und sync-collection: den aktuellen Stand vollständig
		// ausliefern (kein inkrementelles Diff — bewusst v2.x).
		$out = '';
		foreach ( self::collection_events( $col ) as $e ) {
			$out .= self::event_response( $col, $e, $host, true );
		}
		if ( false !== stripos( $body, 'sync-collection' ) ) {
			$out .= '<d:sync-token>' . self::x( self::base_path() . 'ns/sync/' . self::collection_hash( $col ) ) . '</d:sync-token>';
		}
		self::send_multistatus( $out );
	}

	/** XML-<response> einer Termin-Ressource (mit optionaler calendar-data). */
	private static function event_response( array $col, object $e, string $host, bool $with_data ): string {
		$base = self::base_path();
		$uid  = ICal::event_uid( $e, $host );
		$href = $base . 'calendars/' . rawurlencode( $col['id'] ) . '/' . rawurlencode( $uid ) . '.ics';

		$prop = '<d:getetag>' . self::x( ICal::etag( $e ) ) . '</d:getetag>'
			. '<d:getcontenttype>text/calendar; charset=utf-8; component=vevent</d:getcontenttype>';
		if ( $with_data ) {
			$prop .= '<cal:calendar-data>' . self::x( ICal::to_vcalendar( $e, $host ) ) . '</cal:calendar-data>';
		}
		return self::response( $href, $prop );
	}

	/* ===================== Ressource: GET / PUT / DELETE ===================== */

	private static function handle_resource( string $method, array $col, string $resource ): void {
		$host = self::host();

		if ( 'GET' === $method ) {
			$event = self::resolve_event( $col, $resource );
			if ( ! $event ) {
				self::send_status( 404, 'Not Found' );
			}
			header( 'ETag: ' . ICal::etag( $event ) );
			self::send_body( 200, ICal::to_vcalendar( $event, $host ), 'text/calendar; charset=utf-8' );
		}

		if ( 'PUT' === $method ) {
			self::put_resource( $col, $resource, $host );
		}

		if ( 'DELETE' === $method ) {
			$event = self::resolve_event( $col, $resource );
			if ( ! $event ) {
				self::send_status( 404, 'Not Found' );
			}
			$if_match = self::header( 'HTTP_IF_MATCH' );
			if ( '' !== $if_match && ! self::etag_matches( $if_match, ICal::etag( $event ) ) ) {
				self::send_status( 412, 'Precondition Failed' );
			}
			CalendarEvents::delete_event( (int) $event->id, $col['acting_user'], $col['group_id'] );
			self::send_status( 204, '' );
		}

		if ( 'PROPFIND' === $method ) {
			$event = self::resolve_event( $col, $resource );
			if ( ! $event ) {
				self::send_status( 404, 'Not Found' );
			}
			self::send_multistatus( self::event_response( $col, $event, $host, false ) );
		}

		self::send_status( 405, 'Method Not Allowed' );
	}

	private static function put_resource( array $col, string $resource, string $host ): void {
		$parsed = ICal::parse_vevent( self::request_body() );
		if ( null === $parsed ) {
			self::send_status( 400, 'Invalid iCalendar body' );
		}

		$res_uid   = preg_replace( '/\.ics$/i', '', $resource );
		$key_uid   = '' !== $parsed['uid'] ? $parsed['uid'] : $res_uid;
		$existing  = CalendarEvents::find_in_calendar_by_uid( $col['acting_user'], $col['group_id'], $col['calendar_group_id'], $key_uid );
		if ( ! $existing ) {
			// Portal-Termine haben eine abgeleitete UID pp-event-<id> — daraus auflösen.
			$id = self::event_id_from_uid( $res_uid );
			if ( ! $id ) {
				$id = self::event_id_from_uid( $key_uid );
			}
			if ( $id ) {
				$candidate = CalendarEvents::get_owned_event( $id, $col['acting_user'], $col['group_id'] );
				if ( $candidate && (int) $candidate->calendar_group_id === (int) $col['calendar_group_id'] ) {
					$existing = $candidate;
				}
			}
		}

		$if_match      = self::header( 'HTTP_IF_MATCH' );
		$if_none_match = self::header( 'HTTP_IF_NONE_MATCH' );

		$data = [
			'calendar_group_id' => (int) $col['calendar_group_id'],
			'title'             => '' !== trim( $parsed['title'] ) ? $parsed['title'] : __( '(No title)', 'project-prepper' ),
			'description'       => $parsed['description'],
			'location'          => $parsed['location'],
			'date_from'         => $parsed['date_from'],
			'date_to'           => $parsed['date_to'],
			'time_start'        => $parsed['time_start'],
			'time_end'          => $parsed['time_end'],
			'uid'               => $key_uid,
		];

		if ( $existing ) {
			// If-None-Match: * verlangt „nur neu anlegen" — Konflikt.
			if ( '*' === trim( $if_none_match ) ) {
				self::send_status( 412, 'Precondition Failed' );
			}
			if ( '' !== $if_match && ! self::etag_matches( $if_match, ICal::etag( $existing ) ) ) {
				self::send_status( 412, 'Precondition Failed' );
			}
			$res = CalendarEvents::update_event( (int) $existing->id, $col['acting_user'], $col['group_id'], $data );
			self::guard_result( $res );
			$fresh = CalendarEvents::get_owned_event( (int) $existing->id, $col['acting_user'], $col['group_id'] );
			if ( $fresh ) {
				header( 'ETag: ' . ICal::etag( $fresh ) );
			}
			self::send_status( 204, '' );
		}

		// If-Match auf nicht-existente Ressource → Konflikt.
		if ( '' !== $if_match ) {
			self::send_status( 412, 'Precondition Failed' );
		}
		$res = CalendarEvents::create_event( $col['acting_user'], $col['group_id'], $data );
		self::guard_result( $res );
		$fresh = CalendarEvents::get_owned_event( (int) $res, $col['acting_user'], $col['group_id'] );
		if ( $fresh ) {
			header( 'ETag: ' . ICal::etag( $fresh ) );
		}
		self::send_status( 201, '' );
	}

	/** Termin einer Collection aus einem Ressourcennamen (<uid>.ics) auflösen. */
	private static function resolve_event( array $col, string $resource ): ?object {
		$res_uid = preg_replace( '/\.ics$/i', '', rawurldecode( $resource ) );

		$event = CalendarEvents::find_in_calendar_by_uid( $col['acting_user'], $col['group_id'], $col['calendar_group_id'], $res_uid );
		if ( $event ) {
			return $event;
		}
		$id = self::event_id_from_uid( $res_uid );
		if ( $id ) {
			$candidate = CalendarEvents::get_owned_event( $id, $col['acting_user'], $col['group_id'] );
			if ( $candidate && (int) $candidate->calendar_group_id === (int) $col['calendar_group_id'] ) {
				return $candidate;
			}
		}
		return null;
	}

	/** Event-ID aus einer abgeleiteten UID (pp-event-<id>@host). 0 = keine. */
	private static function event_id_from_uid( string $uid ): int {
		return preg_match( '/^pp-event-(\d+)@/', $uid, $m ) ? (int) $m[1] : 0;
	}

	/** Service-Fehler (WP_Error) in einen HTTP-Status übersetzen und beenden. */
	private static function guard_result( $res ): void {
		if ( $res instanceof WP_Error ) {
			$data   = $res->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			self::send_status( $status, $res->get_error_message() );
		}
	}

	/**
	 * ETag-Vergleich für If-Match/If-None-Match. '*' passt immer; sonst wird der
	 * innere Wert (ohne W/-Präfix und Quotes) verglichen, kommagetrennte Liste ok.
	 */
	private static function etag_matches( string $header, string $etag ): bool {
		$header = trim( $header );
		if ( '*' === $header ) {
			return true;
		}
		$want = self::etag_core( $etag );
		foreach ( explode( ',', $header ) as $candidate ) {
			if ( self::etag_core( $candidate ) === $want ) {
				return true;
			}
		}
		return false;
	}

	private static function etag_core( string $etag ): string {
		$etag = trim( $etag );
		if ( 0 === stripos( $etag, 'W/' ) ) {
			$etag = substr( $etag, 2 );
		}
		return trim( $etag, '"' );
	}

	/* ===================== HTTP-Helfer ===================== */

	private static function depth(): string {
		$d = isset( $_SERVER['HTTP_DEPTH'] ) ? strtolower( trim( (string) wp_unslash( $_SERVER['HTTP_DEPTH'] ) ) ) : '0';
		return '' === $d ? '0' : $d;
	}

	private static function header( string $key ): string {
		return isset( $_SERVER[ $key ] ) ? trim( (string) wp_unslash( $_SERVER[ $key ] ) ) : '';
	}

	private static function host(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/** Roh-Body einmalig aus php://input lesen. */
	private static function request_body(): string {
		if ( null === self::$body_cache ) {
			self::$body_cache = (string) file_get_contents( 'php://input' );
		}
		return self::$body_cache;
	}

	private static function x( string $s ): string {
		return htmlspecialchars( $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/** Ein <d:response> mit einem 200-<propstat>. */
	private static function response( string $href, string $prop ): string {
		return '<d:response><d:href>' . self::x( $href ) . '</d:href>'
			. '<d:propstat><d:prop>' . $prop . '</d:prop>'
			. '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
	}

	private static function send_multistatus( string $inner ): void {
		status_header( 207 );
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'DAV: 1, 2, 3, calendar-access' );
		nocache_headers();
		echo '<?xml version="1.0" encoding="utf-8"?>' . "\n"
			. '<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/" xmlns:ical="http://apple.com/ns/ical/">'
			. $inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selbst gebautes XML, Werte via self::x() escaped.
			. '</d:multistatus>';
		exit;
	}

	private static function send_options(): void {
		status_header( 200 );
		header( 'DAV: 1, 2, 3, calendar-access' );
		header( 'Allow: OPTIONS, GET, HEAD, PUT, DELETE, PROPFIND, PROPPATCH, REPORT' );
		header( 'MS-Author-Via: DAV' );
		header( 'Content-Length: 0' );
		exit;
	}

	private static function send_401(): void {
		status_header( 401 );
		header( 'WWW-Authenticate: Basic realm="' . self::REALM . '"' );
		header( 'Content-Type: text/plain; charset=utf-8' );
		nocache_headers();
		echo 'Unauthorized';
		exit;
	}

	private static function send_body( int $code, string $body, string $ctype ): void {
		status_header( $code );
		header( 'Content-Type: ' . $ctype );
		nocache_headers();
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar/Textausgabe, kein HTML-Kontext.
		exit;
	}

	private static function send_status( int $code, string $message ): void {
		status_header( $code );
		nocache_headers();
		if ( '' !== $message ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( $message );
		}
		exit;
	}
}
