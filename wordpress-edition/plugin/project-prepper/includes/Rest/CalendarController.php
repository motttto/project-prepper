<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Services\Borrowing;
use ProjectPrepper\Services\CalendarEvents;
use ProjectPrepper\Services\Groups;
use ProjectPrepper\Services\MemberRentals;
use ProjectPrepper\Services\Projects;
use ProjectPrepper\Services\Rentals;
use ProjectPrepper\Services\Schedule;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * iCal-Export (§13) — read-only Feed mit Token-Auth, abonnierbar in
 * Apple/Google/Outlook-Kalendern. Zwei Token-Arten an derselben Route:
 *
 * - Instanz-Token (Option, Betreiber): alle Verleihe der Site — unverändert.
 * - Persönliches Token (User-Meta): ALLES, was die Kalender-Ansicht des Portals
 *   zeigt — eigene Termine, Projekte, Zeitplan-Einträge, Kollektiv-Ausleihen und
 *   externe Verleihe (Solo-Arbeitsbereich + alle Gruppen des Mitglieds), jeweils
 *   mit CATEGORIES zum Unterscheiden im Kalender-Client.
 *
 * Bis v0.135.0 enthielt der persönliche Feed NUR die von Hand angelegten Termine.
 * Wer im Portal überwiegend Projekte und Verleihe sieht, abonnierte damit einen
 * praktisch leeren Kalender — der Feed „kam nicht an". Die vier abgeleiteten
 * Quellen stammen jetzt aus denselben Services wie die Portal-Ansicht.
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

	/**
	 * Dieselbe Feed-URL als `webcal://` — ein Klick darauf öffnet direkt den
	 * Abo-Dialog von Apple Kalender (bzw. dem Standard-Kalender des Systems),
	 * statt die .ics-Datei einmalig herunterzuladen.
	 */
	public static function user_feed_webcal( int $user_id ): string {
		return preg_replace( '#^https?://#i', 'webcal://', self::user_feed_url( $user_id ) );
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
		$lines = array_merge( $lines, self::refresh_lines() );

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
		// Der Feed läuft OHNE WP-Session (Token-Auth). Services, die ihren
		// Zugriffsfilter über get_current_user_id() bilden — allen voran
		// Projects::all() mit dem Gruppen-Filter — sähen sonst nur Site-Ebene und
		// lieferten KEINE Kollektiv-Projekte. Das Token identifiziert genau diesen
		// User, also wird er für die Dauer der Anfrage gesetzt; emit() beendet sie
		// direkt danach, und Auth-Cookies werden dabei nicht gesetzt.
		if ( get_current_user_id() !== $user_id ) {
			wp_set_current_user( $user_id );
		}
		$user  = get_userdata( $user_id );
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Project Prepper//Termine//DE',
			'CALSCALE:GREGORIAN',
			'X-WR-CALNAME:' . $this->ical_escape( get_bloginfo( 'name' ) . ' — ' . ( $user ? $user->display_name : __( 'Events', 'project-prepper' ) ) ),
		];
		$lines = array_merge( $lines, self::refresh_lines() );

		// Solo-Arbeitsbereich + jede Gruppe des Mitglieds.
		$workspaces = [ [ 'group_id' => 0, 'group_name' => '' ] ];
		foreach ( Groups::user_groups( $user_id ) as $g ) {
			$workspaces[] = [ 'group_id' => (int) $g->id, 'group_name' => (string) $g->name ];
		}

		$group_ids   = [];
		$group_names = [];
		foreach ( $workspaces as $ws ) {
			$events = CalendarEvents::events_between( $user_id, $ws['group_id'], '2000-01-01', '2100-12-31' );
			foreach ( $events as $e ) {
				$lines = array_merge( $lines, $this->event_lines( $e, $ws['group_name'], (string) $host ) );
			}
			if ( $ws['group_id'] > 0 ) {
				$group_ids[]                      = (int) $ws['group_id'];
				$group_names[ (int) $ws['group_id'] ] = (string) $ws['group_name'];
			}
		}

		// Dieselben abgeleiteten Quellen wie die Kalender-Ansicht des Portals
		// (MemberPortal::calendar_events): Projekte der eigenen Kollektive, deren
		// Zeitplan-Einträge, Kollektiv-Ausleihen und externe Verleihe. Ohne sie
		// enthielt der Feed nur die von Hand angelegten Termine.
		foreach ( Projects::all() as $p ) {
			$gid = (int) ( $p->owner_group_id ?? 0 );
			if ( ! in_array( $gid, $group_ids, true ) ) {
				continue;
			}
			$start = (string) ( $p->date_start ?? '' );
			if ( '' === $start ) {
				continue;
			}
			$end   = ! empty( $p->date_end ) ? (string) $p->date_end : $start;
			$lines = array_merge( $lines, $this->allday_lines(
				'pp-project-' . (int) $p->id . '@' . $host,
				(string) $p->name,
				$start,
				$end,
				__( 'Project', 'project-prepper' ),
				$group_names[ $gid ] ?? '',
				(string) ( $p->updated_at ?? '' )
			) );

			// Zeitplan-Einträge: mit Uhrzeit als Termin, sonst ganztägig.
			foreach ( Schedule::for_project( (int) $p->id ) as $sc ) {
				$date = (string) ( $sc->schedule_date ?? '' );
				if ( '' === $date ) {
					continue;
				}
				$lines = array_merge( $lines, $this->timed_lines(
					'pp-sched-' . (int) $sc->id . '@' . $host,
					(string) $sc->title,
					$date,
					(string) ( $sc->time_start ?? '' ),
					(string) ( $sc->time_end ?? '' ),
					__( 'Schedule', 'project-prepper' ),
					(string) $p->name
				) );
			}
		}

		// Kollektiv-Ausleihen (eigene Anfragen + Anfragen auf eigene Artikel).
		$borrows = array_merge( Borrowing::my_requests( $user_id ), Borrowing::incoming_requests( $user_id ) );
		$seen    = [];
		foreach ( $borrows as $b ) {
			if ( ! in_array( $b->status, [ 'requested', 'approved' ], true ) || isset( $seen[ (int) $b->id ] ) ) {
				continue;
			}
			$seen[ (int) $b->id ] = true;
			$start = (string) $b->date_from;
			if ( '' === $start ) {
				continue;
			}
			$lines = array_merge( $lines, $this->allday_lines(
				'pp-borrow-' . (int) $b->id . '@' . $host,
				(string) $b->item_name,
				$start,
				! empty( $b->date_to ) ? (string) $b->date_to : $start,
				__( 'Loan', 'project-prepper' ),
				'',
				(string) ( $b->created_at ?? '' ),
				'approved' === $b->status ? 'CONFIRMED' : 'TENTATIVE'
			) );
		}

		// Eigene externe Verleihe (reserved/active).
		foreach ( MemberRentals::for_owner( $user_id ) as $r ) {
			if ( ! in_array( $r->status, [ 'reserved', 'active' ], true ) || '' === (string) $r->date_from ) {
				continue;
			}
			$lines = array_merge( $lines, $this->allday_lines(
				'pp-myrental-' . (int) $r->id . '@' . $host,
				sprintf( '%s — %s', (string) $r->rental_number, (string) $r->borrower_name ),
				(string) $r->date_from,
				! empty( $r->date_to ) ? (string) $r->date_to : (string) $r->date_from,
				__( 'Rental', 'project-prepper' ),
				'',
				(string) ( $r->updated_at ?? '' ),
				'active' === $r->status ? 'CONFIRMED' : 'TENTATIVE'
			) );
		}

		$lines[] = 'END:VCALENDAR';
		return $lines;
	}

	/**
	 * Aktualisierungs-Hinweis für abonnierende Clients. Ohne ihn entscheidet z. B.
	 * Apple Kalender selbst, wie oft nachgeladen wird („Automatisch" kann sehr
	 * träge sein) — mit ihm fragt der Client stündlich nach.
	 *
	 * @return array<string>
	 */
	private static function refresh_lines(): array {
		return [
			'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
			'X-PUBLISHED-TTL:PT1H',
		];
	}

	/**
	 * Ganztägiges VEVENT (DTEND exklusiv) für die abgeleiteten Einträge.
	 *
	 * @param string $stamp  Zeitstempel für DTSTAMP (leer = jetzt).
	 * @param string $status STATUS-Feld (leer = keins).
	 * @return array<string>
	 */
	private function allday_lines( string $uid, string $summary, string $from, string $to, string $category, string $context = '', string $stamp = '', string $status = '' ): array {
		$lines   = [ 'BEGIN:VEVENT' ];
		$lines[] = 'UID:' . $uid;
		$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z', $stamp && strtotime( $stamp ) ? strtotime( $stamp ) : time() );
		$lines[] = 'DTSTART;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $from ) );
		$lines[] = 'DTEND;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $to . ' +1 day' ) );
		$lines[] = 'SUMMARY:' . $this->ical_escape( $summary );
		$lines   = array_merge( $lines, $this->context_lines( $category, $context ) );
		if ( '' !== $status ) {
			$lines[] = 'STATUS:' . $status;
		}
		$lines[] = 'END:VEVENT';
		return $lines;
	}

	/**
	 * VEVENT mit Uhrzeit (floating local wie die übrigen Termine); ohne Startzeit
	 * wird daraus ein ganztägiger Eintrag.
	 *
	 * @return array<string>
	 */
	private function timed_lines( string $uid, string $summary, string $date, string $time_start, string $time_end, string $category, string $context = '' ): array {
		if ( '' === $time_start ) {
			return $this->allday_lines( $uid, $summary, $date, $date, $category, $context );
		}
		$start_ts = strtotime( $date . ' ' . $time_start );
		$end_ts   = '' !== $time_end ? strtotime( $date . ' ' . $time_end ) : strtotime( '+1 hour', $start_ts );
		if ( $end_ts <= $start_ts ) {
			$end_ts = strtotime( '+1 hour', $start_ts );
		}
		$lines   = [ 'BEGIN:VEVENT' ];
		$lines[] = 'UID:' . $uid;
		$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z', time() );
		$lines[] = 'DTSTART:' . gmdate( 'Ymd\THis', $start_ts );
		$lines[] = 'DTEND:' . gmdate( 'Ymd\THis', $end_ts );
		$lines[] = 'SUMMARY:' . $this->ical_escape( $summary );
		$lines   = array_merge( $lines, $this->context_lines( $category, $context ) );
		$lines[] = 'END:VEVENT';
		return $lines;
	}

	/**
	 * Kategorie (+ Kontext wie Gruppen- oder Projektname) als CATEGORIES und als
	 * lesbare DESCRIPTION — im Kalender-Client sieht man damit auf einen Blick,
	 * woher ein Eintrag stammt.
	 *
	 * @return array<string>
	 */
	private function context_lines( string $category, string $context ): array {
		$out        = [];
		$categories = array_filter( [ $category, $context ] );
		if ( $categories ) {
			$out[] = 'CATEGORIES:' . implode( ',', array_map( [ $this, 'ical_escape' ], $categories ) );
		}
		$description = '' !== $context
			/* translators: 1: entry type (project, schedule, loan, rental), 2: project or group name. */
			? sprintf( __( '%1$s · %2$s', 'project-prepper' ), $category, $context )
			: $category;
		$out[] = 'DESCRIPTION:' . $this->ical_escape( $description );
		return $out;
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
		echo implode( "\r\n", array_map( [ $this, 'fold' ], $lines ) ) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Zeilenfaltung nach RFC 5545 §3.1: keine Content-Line länger als 75 Oktetts,
	 * Fortsetzung beginnt mit einem Leerzeichen. Ohne das brechen lange Projekt-
	 * oder Terminnamen strengere Parser (Apple ist tolerant, andere nicht).
	 * Gefaltet wird an Zeichengrenzen, damit kein UTF-8-Zeichen zerrissen wird.
	 */
	private function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out   = '';
		$chunk = '';
		$limit = 75;
		foreach ( preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			if ( strlen( $chunk ) + strlen( $char ) > $limit ) {
				$out  .= ( '' === $out ? '' : "\r\n " ) . $chunk;
				$chunk = '';
				$limit = 74; // Fortsetzungszeilen tragen das führende Leerzeichen mit.
			}
			$chunk .= $char;
		}
		return $out . ( '' === $out ? '' : "\r\n " ) . $chunk;
	}

	private function ical_escape( string $value ): string {
		return str_replace( [ "\r\n", "\n", "\r" ], '\n', addcslashes( $value, ",;\\" ) );
	}
}
