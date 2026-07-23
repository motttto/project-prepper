<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Services\CalendarEvents;
use ProjectPrepper\Services\Groups;
use ProjectPrepper\Services\Rentals;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * iCal-Export (§13) — read-only Feed mit Token-Auth, abonnierbar in
 * Apple/Google/Outlook-Kalendern. Zwei Token-Arten an derselben Route:
 *
 * - Instanz-Token (Option, Betreiber): alle Verleihe der Site — unverändert.
 * - Persönliches Token (User-Meta): die eigenen Portal-Termine des Mitglieds
 *   (Solo-Arbeitsbereich + alle Gruppen, in denen es Mitglied ist), inkl.
 *   Kalendername als Kategorie und zeitlosen Terminen als ganztägig.
 *
 * CalDAV-Zweiweg bewusst NICHT (v2.x) — der Feed bleibt read-only.
 *
 * URL: /wp-json/project-prepper/v1/calendar.ics?token={token}
 */
class CalendarController extends BaseController {

	const OPTION_TOKEN    = 'pp_ical_token';
	const USER_TOKEN_META = 'pp_ical_token';

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/calendar.ics', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'feed' ],
			// Token-Auth statt Login — Kalender-Clients können keine WP-Session.
			'permission_callback' => '__return_true',
		] );
	}

	public static function token(): string {
		$token = get_option( self::OPTION_TOKEN );
		if ( ! $token ) {
			$token = wp_generate_password( 32, false, false );
			update_option( self::OPTION_TOKEN, $token );
		}
		return $token;
	}

	public static function regenerate_token(): string {
		delete_option( self::OPTION_TOKEN );
		return self::token();
	}

	/** Persönliches Feed-Token eines Mitglieds (wird bei Bedarf erzeugt). */
	public static function user_token( int $user_id ): string {
		$token = (string) get_user_meta( $user_id, self::USER_TOKEN_META, true );
		if ( '' === $token ) {
			$token = wp_generate_password( 32, false, false );
			update_user_meta( $user_id, self::USER_TOKEN_META, $token );
		}
		return $token;
	}

	/** Persönliches Token rotieren — die alte Feed-URL wird damit ungültig. */
	public static function regenerate_user_token( int $user_id ): string {
		delete_user_meta( $user_id, self::USER_TOKEN_META );
		return self::user_token( $user_id );
	}

	/** Persönliche Feed-URL eines Mitglieds. */
	public static function user_feed_url( int $user_id ): string {
		return add_query_arg( 'token', self::user_token( $user_id ), rest_url( self::REST_NAMESPACE . '/calendar.ics' ) );
	}

	public function feed( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		if ( '' === $token ) {
			return new WP_Error( 'pp_invalid_token', __( 'Invalid token.', 'project-prepper' ), [ 'status' => 401 ] );
		}

		// Betreiber-Feed (Instanz-Token): alle Verleihe.
		if ( hash_equals( self::token(), $token ) ) {
			$this->emit( $this->rentals_lines() );
		}

		// Persönlicher Feed (User-Token): eigene Portal-Termine.
		$user_id = self::user_by_token( $token );
		if ( $user_id ) {
			$this->emit( $this->personal_lines( $user_id ) );
		}

		return new WP_Error( 'pp_invalid_token', __( 'Invalid token.', 'project-prepper' ), [ 'status' => 401 ] );
	}

	/** User-ID zum persönlichen Token (0 = kein Treffer). */
	private static function user_by_token( string $token ): int {
		$ids = get_users( [
			'meta_key'   => self::USER_TOKEN_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $token,                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'number'     => 1,
			'fields'     => 'ID',
		] );
		if ( ! $ids ) {
			return 0;
		}
		$user_id = (int) $ids[0];
		// Defense-in-Depth: gespeicherten Wert konstant vergleichen.
		$stored = (string) get_user_meta( $user_id, self::USER_TOKEN_META, true );
		return ( '' !== $stored && hash_equals( $stored, $token ) ) ? $user_id : 0;
	}

	/** @return array<string> VCALENDAR-Zeilen des Betreiber-Feeds (Verleihe). */
	private function rentals_lines(): array {
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Project Prepper//Verleih//DE',
			'CALSCALE:GREGORIAN',
			'X-WR-CALNAME:' . $this->ical_escape( get_bloginfo( 'name' ) . ' — ' . __( 'Rentals', 'project-prepper' ) ),
		];

		foreach ( Rentals::all() as $rental ) {
			if ( ! in_array( $rental->status, [ 'reserved', 'active' ], true ) ) {
				continue;
			}
			$uid     = 'pp-rental-' . $rental->id . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
			$dt_end  = gmdate( 'Ymd', strtotime( $rental->date_to . ' +1 day' ) ); // DTEND exklusiv
			$summary = sprintf( '%s — %s', $rental->rental_number, $rental->borrower_name );

			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . $uid;
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z', strtotime( $rental->updated_at ) );
			$lines[] = 'DTSTART;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $rental->date_from ) );
			$lines[] = 'DTEND;VALUE=DATE:' . $dt_end;
			$lines[] = 'SUMMARY:' . $this->ical_escape( $summary );
			$lines[] = 'STATUS:' . ( 'active' === $rental->status ? 'CONFIRMED' : 'TENTATIVE' );
			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';
		return $lines;
	}

	/**
	 * VCALENDAR-Zeilen des persönlichen Feeds: alle Portal-Termine des Mitglieds
	 * aus Solo- und Gruppen-Arbeitsbereichen. Zeitlose Termine = ganztägig
	 * (VALUE=DATE, DTEND exklusiv), Termine mit Uhrzeit = lokale („floating“)
	 * Zeiten; Kalendername (und Gruppe) als CATEGORIES.
	 *
	 * @return array<string>
	 */
	private function personal_lines( int $user_id ): array {
		$user  = get_userdata( $user_id );
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Project Prepper//Termine//DE',
			'CALSCALE:GREGORIAN',
			'X-WR-CALNAME:' . $this->ical_escape( get_bloginfo( 'name' ) . ' — ' . ( $user ? $user->display_name : __( 'Events', 'project-prepper' ) ) ),
		];

		// Solo-Arbeitsbereich + jede Gruppe des Mitglieds.
		$workspaces = [ [ 'group_id' => 0, 'group_name' => '' ] ];
		foreach ( Groups::user_groups( $user_id ) as $g ) {
			$workspaces[] = [ 'group_id' => (int) $g->id, 'group_name' => (string) $g->name ];
		}

		foreach ( $workspaces as $ws ) {
			$events = CalendarEvents::events_between( $user_id, $ws['group_id'], '2000-01-01', '2100-12-31' );
			foreach ( $events as $e ) {
				$lines = array_merge( $lines, $this->event_lines( $e, $ws['group_name'], (string) $host ) );
			}
		}

		$lines[] = 'END:VCALENDAR';
		return $lines;
	}

	/**
	 * Ein Portal-Termin als VEVENT-Zeilen.
	 *
	 * @param object $e Zeile aus CalendarEvents::events_between (inkl. calendar_name/calendar_color).
	 * @return array<string>
	 */
	private function event_lines( object $e, string $group_name, string $host ): array {
		$lines   = [ 'BEGIN:VEVENT' ];
		$lines[] = 'UID:pp-event-' . (int) $e->id . '@' . $host;
		$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z', strtotime( (string) $e->updated_at ) );

		$date_from = (string) $e->date_from;
		$date_to   = (string) ( $e->date_to ?: $e->date_from );
		$has_time  = '' !== (string) $e->time_start;

		if ( $has_time ) {
			$start_ts = strtotime( $date_from . ' ' . $e->time_start );
			$end_ts   = '' !== (string) $e->time_end
				? strtotime( $date_to . ' ' . $e->time_end )
				: strtotime( '+1 hour', strtotime( $date_to . ' ' . $e->time_start ) );
			if ( $end_ts <= $start_ts ) {
				$end_ts = strtotime( '+1 hour', $start_ts );
			}
			// Floating local time — Kalender-Clients lesen sie in ihrer Zeitzone.
			$lines[] = 'DTSTART:' . gmdate( 'Ymd\THis', $start_ts );
			$lines[] = 'DTEND:' . gmdate( 'Ymd\THis', $end_ts );
		} else {
			$lines[] = 'DTSTART;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $date_from ) );
			$lines[] = 'DTEND;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $date_to . ' +1 day' ) ); // exklusiv
		}

		$lines[] = 'SUMMARY:' . $this->ical_escape( (string) $e->title );
		if ( '' !== (string) $e->location ) {
			$lines[] = 'LOCATION:' . $this->ical_escape( (string) $e->location );
		}
		if ( '' !== trim( (string) $e->description ) ) {
			$lines[] = 'DESCRIPTION:' . $this->ical_escape( (string) $e->description );
		}
		$categories = array_filter( [ (string) ( $e->calendar_name ?? '' ), $group_name ] );
		if ( $categories ) {
			$lines[] = 'CATEGORIES:' . implode( ',', array_map( [ $this, 'ical_escape' ], $categories ) );
		}
		if ( ! empty( $e->calendar_color ) ) {
			$lines[] = 'X-PP-CALENDAR-COLOR:' . $this->ical_escape( (string) $e->calendar_color );
		}
		$lines[] = 'END:VEVENT';
		return $lines;
	}

	/** Fertige iCal-Zeilen roh ausliefern (statt JSON) und beenden. */
	private function emit( array $lines ): void {
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="project-prepper.ics"' );
		echo implode( "\r\n", $lines ) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private function ical_escape( string $value ): string {
		return str_replace( [ "\r\n", "\n", "\r" ], '\n', addcslashes( $value, ",;\\" ) );
	}
}
