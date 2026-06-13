<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Projekt-Zeitplan — Pendant zu project_schedule der App (MVP-Subset:
 * Datum, optionale Zeitspanne Von/Bis, Titel, Ort, Notizen).
 *
 * Zeitfelder sind alle nullable (z. B. ein Programmpunkt ohne feste Uhrzeit).
 * location/notes sind WP-Ergänzungen gegenüber der App-Tabelle.
 */
class Schedule {

	/**
	 * Chronologisch: zuerst nach Datum, dann Startzeit, dann manuelle
	 * Reihenfolge, dann id. Einträge ohne Datum/Zeit (NULL) landen jeweils
	 * ANS ENDE ihrer Stufe (`field IS NULL` ASC = gefüllte zuerst), damit
	 * terminierte Punkte oben stehen — andernfalls würde MySQL NULLs bei
	 * ASC nach vorne sortieren.
	 */
	public static function for_project( int $project_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE project_id = %d
			 ORDER BY (schedule_date IS NULL) ASC, schedule_date ASC,
			          (time_start IS NULL) ASC, time_start ASC,
			          sort_order ASC, id ASC',
			Schema::table( 'project_schedule' ),
			$project_id
		) ) ?: [];
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_schedule' ),
			$id
		) );
	}

	/**
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data ) {
		global $wpdb;

		if ( empty( $data['title'] ) || '' === trim( (string) $data['title'] ) ) {
			return new WP_Error( 'pp_missing_title', __( 'Title is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$date = isset( $data['schedule_date'] ) ? (string) $data['schedule_date'] : '';
		if ( '' !== $date && ! Availability::is_valid_date( $date ) ) {
			return new WP_Error( 'pp_invalid_dates', __( 'Invalid date range.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$start = self::sanitize_time( $data['time_start'] ?? '' );
		$end   = self::sanitize_time( $data['time_end'] ?? '' );
		if ( false === $start || false === $end ) {
			return new WP_Error( 'pp_invalid_time', __( 'Invalid time.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$wpdb->insert( Schema::table( 'project_schedule' ), [
			'project_id'    => $project_id,
			'schedule_date' => '' !== $date ? $date : null,
			'time_start'    => $start,
			'time_end'      => $end,
			'title'         => trim( (string) $data['title'] ),
			'location'      => isset( $data['location'] ) ? (string) $data['location'] : '',
			'notes'         => isset( $data['notes'] ) ? (string) $data['notes'] : '',
			'sort_order'    => (int) ( $data['sort_order'] ?? 0 ),
		] );
		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'schedule_created', 'project', $project_id, [ 'title' => trim( (string) $data['title'] ) ] );
		return $id;
	}

	/**
	 * Partielles Update — nur übergebene Keys werden geändert (wie Tasks::update).
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$entry = self::get( $id );
		if ( ! $entry ) {
			return new WP_Error( 'pp_not_found', __( 'Schedule entry not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		$fields = [];
		if ( array_key_exists( 'title', $data ) ) {
			if ( '' === trim( (string) $data['title'] ) ) {
				return new WP_Error( 'pp_missing_title', __( 'Title is required.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['title'] = trim( (string) $data['title'] );
		}
		if ( array_key_exists( 'schedule_date', $data ) ) {
			$date = (string) $data['schedule_date'];
			if ( '' !== $date && ! Availability::is_valid_date( $date ) ) {
				return new WP_Error( 'pp_invalid_dates', __( 'Invalid date range.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['schedule_date'] = '' !== $date ? $date : null;
		}
		if ( array_key_exists( 'time_start', $data ) ) {
			$start = self::sanitize_time( $data['time_start'] );
			if ( false === $start ) {
				return new WP_Error( 'pp_invalid_time', __( 'Invalid time.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['time_start'] = $start;
		}
		if ( array_key_exists( 'time_end', $data ) ) {
			$end = self::sanitize_time( $data['time_end'] );
			if ( false === $end ) {
				return new WP_Error( 'pp_invalid_time', __( 'Invalid time.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['time_end'] = $end;
		}
		if ( array_key_exists( 'location', $data ) ) {
			$fields['location'] = (string) $data['location'];
		}
		if ( array_key_exists( 'notes', $data ) ) {
			$fields['notes'] = (string) $data['notes'];
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$fields['sort_order'] = (int) $data['sort_order'];
		}
		if ( $fields ) {
			$wpdb->update( Schema::table( 'project_schedule' ), $fields, [ 'id' => $id ] );
			ActivityLog::log( 'schedule_updated', 'project', (int) $entry->project_id, [
				'entry_id' => $id,
				'fields'   => array_keys( $fields ),
			] );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;
		$entry = self::get( $id );
		if ( ! $entry ) {
			return new WP_Error( 'pp_not_found', __( 'Schedule entry not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$wpdb->delete( Schema::table( 'project_schedule' ), [ 'id' => $id ], [ '%d' ] );
		ActivityLog::log( 'schedule_deleted', 'project', (int) $entry->project_id, [ 'title' => $entry->title ] );
		return true;
	}

	/**
	 * Zeit als HH:MM (oder HH:MM:SS) validieren. Leer → NULL.
	 *
	 * @param mixed $value
	 * @return string|null|false  HH:MM:SS bei gültig, null bei leer, false bei ungültig.
	 */
	private static function sanitize_time( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( ! preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $value ) ) {
			return false;
		}
		// Auf HH:MM:SS normalisieren (TIME-Spalte).
		return 5 === strlen( $value ) ? $value . ':00' : $value;
	}
}
