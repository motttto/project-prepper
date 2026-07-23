<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Eigene Kalender + Termine (v0.29.0) — Pendant zu `calendar_groups` /
 * `calendar_events` der App (Seite /calendar): farbige Kalender und frei
 * angelegte Termine pro Arbeitsbereich.
 *
 * Owner-Modell wie pp_inquiries: GENAU EIN Owner — Solo (owner_user_id) XOR
 * Gruppe (owner_group_id); das jeweils andere Feld ist 0. Alle Methoden nehmen
 * das Paar ($user_id, $group_id) entgegen, wobei $group_id 0 = Solo bedeutet.
 * Im Gruppen-Modus wird die aktive Mitgliedschaft erzwungen (Defense-in-Depth
 * zusätzlich zum Dispatcher-Gate).
 *
 * Zeiten: date_from/date_to (Y-m-d) + optionale time_start/time_end (HH:MM).
 * Leere Zeiten = ganztägig (App: all_day). Die uid-Spalte (v0.33.0) trägt die
 * iCal-UID für den CalDAV-Zweiweg-Server (CalDav\Server); im Portal angelegte
 * Termine haben sie leer und bekommen beim Export eine abgeleitete UID.
 */
class CalendarEvents {

	/** Feste Farb-Palette der App (CALENDAR_COLORS in calendar/page.tsx). */
	const COLORS = [ '#0066FF', '#22C55E', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#06B6D4', '#F97316' ];

	/* ===================== Kalender ===================== */

	/**
	 * Kalender des Arbeitsbereichs (Solo: $group_id = 0), sortiert.
	 *
	 * @return array<object>
	 */
	public static function calendars( int $user_id, int $group_id ): array {
		global $wpdb;
		$owner = self::owner_pair( $user_id, $group_id );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE owner_user_id = %d AND owner_group_id = %d
			 ORDER BY sort_order ASC, name ASC, id ASC',
			Schema::table( 'calendar_groups' ),
			$owner['user'],
			$owner['group']
		) ) ?: [];
		foreach ( $rows as $r ) {
			$r->id = (int) $r->id;
		}
		return $rows;
	}

	/**
	 * Kalender anlegen. Name Pflicht, Farbe aus der festen Palette (sonst
	 * erste Farbe). Gruppen-Modus erfordert aktive Mitgliedschaft.
	 *
	 * @return int|WP_Error Neue Kalender-ID.
	 */
	public static function create_calendar( int $user_id, int $group_id, array $data ) {
		global $wpdb;

		$guard = self::workspace_guard( $user_id, $group_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$name = isset( $data['name'] ) ? trim( (string) $data['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'pp_missing_name', __( 'A name is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$owner = self::owner_pair( $user_id, $group_id );
		$wpdb->insert( Schema::table( 'calendar_groups' ), [
			'owner_user_id'  => $owner['user'],
			'owner_group_id' => $owner['group'],
			'name'           => $name,
			'color'          => self::sanitize_color( $data['color'] ?? '' ),
			'sort_order'     => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
			'created_at'     => current_time( 'mysql' ),
		] );
		$id = (int) $wpdb->insert_id;
		ActivityLog::log( 'calendar_created', $group_id ? 'group' : 'user', $group_id ?: $user_id, [ 'calendar_id' => $id, 'name' => $name ] );
		return $id;
	}

	/**
	 * Kalender umbenennen / umfärben. Nur im eigenen Arbeitsbereich.
	 *
	 * @return true|WP_Error
	 */
	public static function update_calendar( int $id, int $user_id, int $group_id, array $data ) {
		global $wpdb;

		$cal = self::owned_calendar( $id, $user_id, $group_id );
		if ( is_wp_error( $cal ) ) {
			return $cal;
		}
		$fields = [];
		if ( array_key_exists( 'name', $data ) ) {
			$name = trim( (string) $data['name'] );
			if ( '' === $name ) {
				return new WP_Error( 'pp_missing_name', __( 'A name is required.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['name'] = $name;
		}
		if ( array_key_exists( 'color', $data ) ) {
			$fields['color'] = self::sanitize_color( $data['color'] );
		}
		if ( $fields ) {
			$wpdb->update( Schema::table( 'calendar_groups' ), $fields, [ 'id' => $id ] );
		}
		return true;
	}

	/**
	 * Kalender löschen — Termine bleiben erhalten und fallen auf „kein
	 * Kalender" (calendar_group_id = 0) zurück.
	 *
	 * @return true|WP_Error
	 */
	public static function delete_calendar( int $id, int $user_id, int $group_id ) {
		global $wpdb;

		$cal = self::owned_calendar( $id, $user_id, $group_id );
		if ( is_wp_error( $cal ) ) {
			return $cal;
		}
		$wpdb->update(
			Schema::table( 'calendar_events' ),
			[ 'calendar_group_id' => 0 ],
			[ 'calendar_group_id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);
		$wpdb->delete( Schema::table( 'calendar_groups' ), [ 'id' => $id ], [ '%d' ] );
		ActivityLog::log( 'calendar_deleted', $group_id ? 'group' : 'user', $group_id ?: $user_id, [ 'calendar_id' => $id ] );
		return true;
	}

	/* ===================== Termine ===================== */

	/**
	 * Termine des Arbeitsbereichs, die [from..to] überlappen — inkl. Farbe und
	 * Name des zugeordneten Kalenders (calendar_color/calendar_name, NULL wenn
	 * keiner).
	 *
	 * @return array<object>
	 */
	public static function events_between( int $user_id, int $group_id, string $from, string $to ): array {
		global $wpdb;
		$owner = self::owner_pair( $user_id, $group_id );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			'SELECT e.*, c.color AS calendar_color, c.name AS calendar_name
			 FROM %i e LEFT JOIN %i c ON c.id = e.calendar_group_id
			 WHERE e.owner_user_id = %d AND e.owner_group_id = %d
			   AND e.date_from <= %s AND COALESCE(e.date_to, e.date_from) >= %s
			 ORDER BY e.date_from ASC, e.time_start ASC, e.id ASC',
			Schema::table( 'calendar_events' ),
			Schema::table( 'calendar_groups' ),
			$owner['user'],
			$owner['group'],
			$to,
			$from
		) ) ?: [];
		foreach ( $rows as $r ) {
			$r->id                = (int) $r->id;
			$r->calendar_group_id = (int) $r->calendar_group_id;
		}
		return $rows;
	}

	/**
	 * Termine eines Arbeitsbereichs, die genau EINEM Kalender zugeordnet sind
	 * (calendar_group_id) — für den CalDAV-Server, der jede Kalender-Sammlung
	 * als eigene Collection ausliefert. Owner-gefiltert (Defense-in-Depth).
	 *
	 * @return array<object>
	 */
	public static function events_in_calendar( int $user_id, int $group_id, int $calendar_group_id ): array {
		global $wpdb;
		$owner = self::owner_pair( $user_id, $group_id );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE owner_user_id = %d AND owner_group_id = %d AND calendar_group_id = %d
			 ORDER BY date_from ASC, time_start ASC, id ASC',
			Schema::table( 'calendar_events' ),
			$owner['user'],
			$owner['group'],
			$calendar_group_id
		) ) ?: [];
		foreach ( $rows as $r ) {
			$r->id                = (int) $r->id;
			$r->calendar_group_id = (int) $r->calendar_group_id;
		}
		return $rows;
	}

	/**
	 * Einen Termin per iCal-UID innerhalb eines Kalenders auflösen (CalDAV:
	 * UID ist der Ressourcen-Schlüssel). Owner-gefiltert; NULL wenn keiner.
	 */
	public static function find_in_calendar_by_uid( int $user_id, int $group_id, int $calendar_group_id, string $uid ): ?object {
		global $wpdb;
		if ( '' === $uid ) {
			return null;
		}
		$owner = self::owner_pair( $user_id, $group_id );
		$row   = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE owner_user_id = %d AND owner_group_id = %d AND calendar_group_id = %d AND uid = %s LIMIT 1',
			Schema::table( 'calendar_events' ),
			$owner['user'],
			$owner['group'],
			$calendar_group_id,
			$uid
		) );
		if ( $row ) {
			$row->id                = (int) $row->id;
			$row->calendar_group_id = (int) $row->calendar_group_id;
		}
		return $row ?: null;
	}

	/**
	 * Owner-geprüfter Einzeltermin nach ID (für CalDAV-GET/DELETE der aus der
	 * ID abgeleiteten UID pp-event-<id>). NULL wenn nicht im Arbeitsbereich.
	 */
	public static function get_owned_event( int $id, int $user_id, int $group_id ): ?object {
		$row = self::owned_event( $id, $user_id, $group_id );
		if ( is_wp_error( $row ) ) {
			return null;
		}
		$row->id                = (int) $row->id;
		$row->calendar_group_id = (int) $row->calendar_group_id;
		return $row;
	}

	/**
	 * Termin anlegen. Titel + gültiges Startdatum Pflicht; Ende optional
	 * (>= Start, sonst 400 pp_invalid_date); Zeiten optional (HH:MM, sonst
	 * verworfen). Kalender-Zuordnung nur auf eigene Kalender.
	 *
	 * @return int|WP_Error Neue Termin-ID.
	 */
	public static function create_event( int $user_id, int $group_id, array $data ) {
		global $wpdb;

		$guard = self::workspace_guard( $user_id, $group_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$norm = self::normalize_event( $user_id, $group_id, $data );
		if ( is_wp_error( $norm ) ) {
			return $norm;
		}
		$owner = self::owner_pair( $user_id, $group_id );
		$now   = current_time( 'mysql' );
		$wpdb->insert( Schema::table( 'calendar_events' ), array_merge( $norm, [
			'owner_user_id'  => $owner['user'],
			'owner_group_id' => $owner['group'],
			'created_by'     => $user_id ?: null,
			'created_at'     => $now,
			'updated_at'     => $now,
		] ) );
		$id = (int) $wpdb->insert_id;
		ActivityLog::log( 'calendar_event_created', $group_id ? 'group' : 'user', $group_id ?: $user_id, [ 'event_id' => $id, 'title' => $norm['title'] ] );
		return $id;
	}

	/**
	 * Termin ändern (alle Felder). Nur im eigenen Arbeitsbereich.
	 *
	 * @return true|WP_Error
	 */
	public static function update_event( int $id, int $user_id, int $group_id, array $data ) {
		global $wpdb;

		$event = self::owned_event( $id, $user_id, $group_id );
		if ( is_wp_error( $event ) ) {
			return $event;
		}
		$norm = self::normalize_event( $user_id, $group_id, $data );
		if ( is_wp_error( $norm ) ) {
			return $norm;
		}
		$norm['updated_at'] = current_time( 'mysql' );
		$wpdb->update( Schema::table( 'calendar_events' ), $norm, [ 'id' => $id ] );
		return true;
	}

	/**
	 * Termin löschen. Nur im eigenen Arbeitsbereich.
	 *
	 * @return true|WP_Error
	 */
	public static function delete_event( int $id, int $user_id, int $group_id ) {
		global $wpdb;

		$event = self::owned_event( $id, $user_id, $group_id );
		if ( is_wp_error( $event ) ) {
			return $event;
		}
		$wpdb->delete( Schema::table( 'calendar_events' ), [ 'id' => $id ], [ '%d' ] );
		ActivityLog::log( 'calendar_event_deleted', $group_id ? 'group' : 'user', $group_id ?: $user_id, [ 'event_id' => $id ] );
		return true;
	}

	/* ===================== Intern ===================== */

	/** Owner-Paar des Arbeitsbereichs: Solo = (uid, 0), Gruppe = (0, gid). */
	private static function owner_pair( int $user_id, int $group_id ): array {
		return $group_id > 0
			? [ 'user' => 0, 'group' => $group_id ]
			: [ 'user' => $user_id, 'group' => 0 ];
	}

	/**
	 * Gruppen-Arbeitsbereich erfordert aktive Mitgliedschaft.
	 *
	 * @return true|WP_Error
	 */
	private static function workspace_guard( int $user_id, int $group_id ) {
		if ( $group_id > 0 && ! Groups::is_member( $group_id, $user_id ) ) {
			return new WP_Error( 'pp_not_group_member', __( 'Only members of the project group may vote.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * Kalender laden und Owner-Match mit dem Arbeitsbereich erzwingen (IDOR).
	 *
	 * @return object|WP_Error
	 */
	private static function owned_calendar( int $id, int $user_id, int $group_id ) {
		global $wpdb;
		$guard = self::workspace_guard( $user_id, $group_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'calendar_groups' ),
			$id
		) );
		$owner = self::owner_pair( $user_id, $group_id );
		if ( ! $row || (int) $row->owner_user_id !== $owner['user'] || (int) $row->owner_group_id !== $owner['group'] ) {
			return new WP_Error( 'pp_not_found', __( 'Calendar not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return $row;
	}

	/**
	 * Termin laden und Owner-Match mit dem Arbeitsbereich erzwingen (IDOR).
	 *
	 * @return object|WP_Error
	 */
	private static function owned_event( int $id, int $user_id, int $group_id ) {
		global $wpdb;
		$guard = self::workspace_guard( $user_id, $group_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'calendar_events' ),
			$id
		) );
		$owner = self::owner_pair( $user_id, $group_id );
		if ( ! $row || (int) $row->owner_user_id !== $owner['user'] || (int) $row->owner_group_id !== $owner['group'] ) {
			return new WP_Error( 'pp_not_found', __( 'Event not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return $row;
	}

	/**
	 * Termin-Eingaben normalisieren + validieren.
	 *
	 * @return array<string,mixed>|WP_Error Spalten-Map für insert/update.
	 */
	private static function normalize_event( int $user_id, int $group_id, array $data ) {
		$title = isset( $data['title'] ) ? trim( (string) $data['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'pp_missing_title', __( 'A title is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$from = isset( $data['date_from'] ) ? trim( (string) $data['date_from'] ) : '';
		if ( '' === $from || ! Availability::is_valid_date( $from ) ) {
			return new WP_Error( 'pp_invalid_date', __( 'Please enter a valid date.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$to = isset( $data['date_to'] ) ? trim( (string) $data['date_to'] ) : '';
		if ( '' !== $to && ! Availability::is_valid_date( $to ) ) {
			return new WP_Error( 'pp_invalid_date', __( 'Please enter a valid date.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		if ( '' !== $to && $to < $from ) {
			return new WP_Error( 'pp_invalid_date', __( 'Please enter a valid date.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		// Kalender-Zuordnung: 0 = keiner; sonst muss der Kalender dem
		// Arbeitsbereich gehören (kein Fremd-Kalender unterschiebbar).
		$cal_id = isset( $data['calendar_group_id'] ) ? (int) $data['calendar_group_id'] : 0;
		if ( $cal_id > 0 ) {
			$cal = self::owned_calendar( $cal_id, $user_id, $group_id );
			if ( is_wp_error( $cal ) ) {
				$cal_id = 0;
			}
		}

		$fields = [
			'calendar_group_id' => $cal_id,
			'title'             => $title,
			'description'       => isset( $data['description'] ) ? (string) $data['description'] : '',
			'location'          => isset( $data['location'] ) ? trim( (string) $data['location'] ) : '',
			'date_from'         => $from,
			'date_to'           => '' !== $to && $to !== $from ? $to : null,
			'time_start'        => self::sanitize_time( $data['time_start'] ?? '' ),
			'time_end'          => self::sanitize_time( $data['time_end'] ?? '' ),
		];

		// iCal-UID nur setzen, wenn explizit übergeben (CalDAV-PUT) — Portal-
		// Bearbeitungen übergeben keinen Schlüssel und lassen die UID unangetastet.
		if ( array_key_exists( 'uid', $data ) ) {
			$fields['uid'] = self::sanitize_uid( $data['uid'] );
		}
		return $fields;
	}

	/** iCal-UID säubern: druckbares ASCII, max. 255 Zeichen. */
	private static function sanitize_uid( $uid ): string {
		$uid = preg_replace( '/[^\x20-\x7E]/', '', (string) $uid );
		return substr( trim( (string) $uid ), 0, 255 );
	}

	/** Farbe auf die feste Palette einschränken (Fallback: erste Farbe). */
	private static function sanitize_color( $color ): string {
		$color = strtoupper( trim( (string) $color ) );
		return in_array( $color, self::COLORS, true ) ? $color : self::COLORS[0];
	}

	/** HH:MM validieren, sonst leer (= keine Zeit / ganztägig). */
	private static function sanitize_time( $time ): string {
		$time = trim( (string) $time );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '';
	}
}
