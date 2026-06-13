<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Projekte-Service (Kern + Planung, v0.9.0) — Subset des App-Projektmoduls.
 *
 * Status-Flow (bewusst einfach gehalten): jeder Wechsel ist erlaubt,
 * AUSSER weg von 'cancelled' — Storno ist der einzige harte Endstatus.
 * 'done' bleibt korrigierbar (z. B. zurück auf 'running'), weil es ohne
 * Abrechnung/Snapshot keine Folgewirkung hat.
 *
 * Verfügbarkeit: Buchungen blockieren Inventar nur in confirmed/running
 * (siehe Availability::available_quantity). Der Guard beim Anlegen/Ändern
 * von Buchungszeilen prüft trotzdem in jedem Status, damit Konflikte früh
 * sichtbar werden.
 */
class Projects {

	const STATUSES = [ 'draft', 'planned', 'confirmed', 'running', 'done', 'cancelled' ];

	// Nur diese Projekt-Stati blockieren Inventar (gespiegelt in Availability).
	const BLOCKING_STATUSES = [ 'confirmed', 'running' ];

	public static function all( array $args = [] ): array {
		global $wpdb;
		$projects = Schema::table( 'projects' );
		$lines    = Schema::table( 'project_items' );

		$where  = [ '1=1' ];
		$params = [];
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'p.status = %s';
			$params[] = $args['status'];
		}

		array_unshift( $params, $lines, $projects );
		$sql = $wpdb->prepare(
			'SELECT p.*, (SELECT COALESCE(SUM(pi.quantity), 0) FROM %i pi WHERE pi.project_id = p.id) AS item_count
			 FROM %i p
			 WHERE ' . implode( ' AND ', $where ) . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE-Bedingungen sind statische Strings mit Platzhaltern.
			' ORDER BY p.date_start DESC, p.id DESC',
			$params
		);
		return $wpdb->get_results( $sql ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ist oben via prepare() aufgebaut.
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$project = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'projects' ),
			$id
		) );
		if ( ! $project ) {
			return null;
		}
		$project->items      = self::items_for( $id );
		$project->checklists = Checklists::for_project( $id );
		$project->tasks      = Tasks::for_project( $id );
		return $project;
	}

	/**
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		if ( empty( $data['name'] ) || '' === trim( (string) $data['name'] ) ) {
			return new WP_Error( 'pp_missing_name', __( 'Project name is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$status = $data['status'] ?? 'draft';
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'pp_invalid_status', __( 'Invalid status.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$dates = self::validate_dates( $data['date_start'] ?? '', $data['date_end'] ?? '' );
		if ( is_wp_error( $dates ) ) {
			return $dates;
		}

		$now = current_time( 'mysql' );
		$wpdb->insert( Schema::table( 'projects' ), [
			'project_number' => Numbering::next_project_number(),
			'name'           => trim( (string) $data['name'] ),
			'status'         => $status,
			'date_start'     => $dates['start'],
			'date_end'       => $dates['end'],
			'venue_name'     => $data['venue_name'] ?? '',
			'venue_address'  => $data['venue_address'] ?? '',
			'client_name'    => $data['client_name'] ?? '',
			'client_email'   => $data['client_email'] ?? '',
			'client_phone'   => $data['client_phone'] ?? '',
			'notes'          => $data['notes'] ?? '',
			'created_by'     => get_current_user_id() ?: null,
			'created_at'     => $now,
			'updated_at'     => $now,
		] );
		$project_id = (int) $wpdb->insert_id;

		ActivityLog::log( 'project_created', 'project', $project_id, [
			'name'   => trim( (string) $data['name'] ),
			'status' => $status,
		] );

		/**
		 * Hook-Punkt (ersetzt DB-Trigger).
		 */
		do_action( 'pp_project_created', $project_id );

		return $project_id;
	}

	/**
	 * Stammdaten bearbeiten — nur übergebene Keys werden geändert.
	 *
	 * Bewusst in jedem Status erlaubt (auch done/cancelled bleiben z. B. für
	 * Notizen korrigierbar). Achtung: Eine Änderung des Projekt-Zeitraums
	 * re-validiert geerbte Buchungszeiträume NICHT (dokumentierte Lücke).
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$project = self::get( $id );
		if ( ! $project ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( array_key_exists( 'name', $data ) && '' === trim( (string) $data['name'] ) ) {
			return new WP_Error( 'pp_missing_name', __( 'Project name is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		// Effektiver Zeitraum = neue Werte, Fallback auf Bestand.
		$start = array_key_exists( 'date_start', $data ) ? (string) $data['date_start'] : (string) $project->date_start;
		$end   = array_key_exists( 'date_end', $data ) ? (string) $data['date_end'] : (string) $project->date_end;
		$dates = self::validate_dates( $start, $end );
		if ( is_wp_error( $dates ) ) {
			return $dates;
		}

		$fields = [];
		foreach ( [ 'name', 'venue_name', 'venue_address', 'client_name', 'client_email', 'client_phone', 'notes' ] as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$fields[ $key ] = 'name' === $key ? trim( (string) $data[ $key ] ) : (string) $data[ $key ];
			}
		}
		if ( array_key_exists( 'date_start', $data ) ) {
			$fields['date_start'] = $dates['start'];
		}
		if ( array_key_exists( 'date_end', $data ) ) {
			$fields['date_end'] = $dates['end'];
		}
		$fields['updated_at'] = current_time( 'mysql' );
		$wpdb->update( Schema::table( 'projects' ), $fields, [ 'id' => $id ] );

		ActivityLog::log( 'project_updated', 'project', $id, [
			'fields' => array_values( array_diff( array_keys( $fields ), [ 'updated_at' ] ) ),
		] );

		/**
		 * Hook-Punkt (ersetzt DB-Trigger).
		 */
		do_action( 'pp_project_updated', $id );

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function set_status( int $id, string $status ) {
		global $wpdb;

		$project = self::get( $id );
		if ( ! $project ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'pp_invalid_status', __( 'Invalid status.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		// Einziger Guard: 'cancelled' ist Endstatus — von dort kein Wechsel mehr.
		if ( 'cancelled' === $project->status && $status !== $project->status ) {
			return new WP_Error(
				'pp_invalid_transition',
				__( 'Cancelled projects cannot change status.', 'project-prepper' ),
				[ 'status' => 409 ]
			);
		}

		$wpdb->update(
			Schema::table( 'projects' ),
			[ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		ActivityLog::log( 'project_status_changed', 'project', $id, [ 'from' => $project->status, 'to' => $status ] );

		/**
		 * Hook-Punkt (ersetzt DB-Trigger).
		 */
		do_action( 'pp_project_status_changed', $id, $project->status, $status );

		return true;
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		$checklist_ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM %i WHERE project_id = %d',
			Schema::table( 'project_checklists' ),
			$id
		) );
		foreach ( array_map( 'intval', $checklist_ids ) as $checklist_id ) {
			$wpdb->delete( Schema::table( 'project_checklist_items' ), [ 'checklist_id' => $checklist_id ], [ '%d' ] );
		}
		$wpdb->delete( Schema::table( 'project_checklists' ), [ 'project_id' => $id ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'project_tasks' ), [ 'project_id' => $id ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'project_items' ), [ 'project_id' => $id ], [ '%d' ] );
		$ok = false !== $wpdb->delete( Schema::table( 'projects' ), [ 'id' => $id ], [ '%d' ] );
		if ( $ok ) {
			ActivityLog::log( 'project_deleted', 'project', $id );
		}
		return $ok;
	}

	/* ---------- Buchungszeilen ---------- */

	public static function items_for( int $project_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT pi.*, i.name AS item_name, i.inventory_number
			 FROM %i pi
			 LEFT JOIN %i i ON i.id = pi.item_id
			 WHERE pi.project_id = %d
			 ORDER BY pi.id ASC',
			Schema::table( 'project_items' ),
			Schema::table( 'items' ),
			$project_id
		) ) ?: [];
	}

	public static function get_item_line( int $project_id, int $line_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d AND project_id = %d',
			Schema::table( 'project_items' ),
			$line_id,
			$project_id
		) );
	}

	/**
	 * Buchungszeile anlegen — mit Verfügbarkeits-Guard über den effektiven
	 * Zeitraum (Zeilen-Datum, Fallback Projekt-Zeitraum).
	 *
	 * @return int|WP_Error
	 */
	public static function add_item( int $project_id, array $line ) {
		global $wpdb;

		$project = self::get( $project_id );
		if ( ! $project ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		$validated = self::validate_line( $project, $line, 0 );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$wpdb->insert( Schema::table( 'project_items' ), [
			'project_id' => $project_id,
			'item_id'    => $validated['item_id'],
			'quantity'   => $validated['quantity'],
			'date_from'  => $validated['date_from'],
			'date_to'    => $validated['date_to'],
			'notes'      => $line['notes'] ?? '',
		] );
		$line_id = (int) $wpdb->insert_id;

		ActivityLog::log( 'project_item_added', 'project', $project_id, [
			'item_id'  => $validated['item_id'],
			'quantity' => $validated['quantity'],
		] );

		return $line_id;
	}

	/**
	 * Buchungszeile ändern — Verfügbarkeit wird neu geprüft; die Buchungen des
	 * eigenen Projekts werden dabei über exclude_project_id ausgenommen.
	 *
	 * @return true|WP_Error
	 */
	public static function update_item( int $project_id, int $line_id, array $line ) {
		global $wpdb;

		$project = self::get( $project_id );
		if ( ! $project ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$existing = self::get_item_line( $project_id, $line_id );
		if ( ! $existing ) {
			return new WP_Error( 'pp_not_found', __( 'Line item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		// Nicht übergebene Felder mit dem Bestand auffüllen.
		$merged = [
			'item_id'   => array_key_exists( 'item_id', $line ) ? $line['item_id'] : $existing->item_id,
			'quantity'  => array_key_exists( 'quantity', $line ) ? $line['quantity'] : $existing->quantity,
			'date_from' => array_key_exists( 'date_from', $line ) ? $line['date_from'] : (string) $existing->date_from,
			'date_to'   => array_key_exists( 'date_to', $line ) ? $line['date_to'] : (string) $existing->date_to,
		];

		$validated = self::validate_line( $project, $merged, $line_id );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$fields = [
			'item_id'   => $validated['item_id'],
			'quantity'  => $validated['quantity'],
			'date_from' => $validated['date_from'],
			'date_to'   => $validated['date_to'],
		];
		if ( array_key_exists( 'notes', $line ) ) {
			$fields['notes'] = (string) $line['notes'];
		}
		$wpdb->update( Schema::table( 'project_items' ), $fields, [ 'id' => $line_id, 'project_id' => $project_id ] );

		ActivityLog::log( 'project_item_updated', 'project', $project_id, [
			'line_id'  => $line_id,
			'item_id'  => $validated['item_id'],
			'quantity' => $validated['quantity'],
		] );

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function remove_item( int $project_id, int $line_id ) {
		global $wpdb;
		$existing = self::get_item_line( $project_id, $line_id );
		if ( ! $existing ) {
			return new WP_Error( 'pp_not_found', __( 'Line item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$wpdb->delete( Schema::table( 'project_items' ), [ 'id' => $line_id, 'project_id' => $project_id ], [ '%d', '%d' ] );

		ActivityLog::log( 'project_item_removed', 'project', $project_id, [
			'line_id' => $line_id,
			'item_id' => (int) $existing->item_id,
		] );

		return true;
	}

	/* ---------- Validierung ---------- */

	/**
	 * Buchungszeile validieren: Artikel, Menge, optionaler eigener Zeitraum,
	 * Verfügbarkeits-Guard über den effektiven Zeitraum.
	 *
	 * Das eigene Projekt wird in Availability komplett ausgenommen
	 * (exclude_project_id) und seine übrigen Zeilen werden hier manuell
	 * gegengerechnet — so greift der Guard auch in draft/planned, obwohl
	 * diese Stati global nichts blockieren (Konflikte früh sichtbar machen).
	 *
	 * @param object $project         Projekt (für geerbten Zeitraum).
	 * @param array  $line            Zeilen-Daten.
	 * @param int    $exclude_line_id Beim Ändern: die eigene Zeile ausnehmen.
	 * @return array|WP_Error [ item_id, quantity, date_from, date_to ]
	 */
	private static function validate_line( object $project, array $line, int $exclude_line_id ) {
		global $wpdb;
		$item_id = (int) ( $line['item_id'] ?? 0 );
		$qty     = max( 1, (int) ( $line['quantity'] ?? 1 ) );
		if ( ! $item_id || ! Inventory::get_item( $item_id ) ) {
			return new WP_Error( 'pp_invalid_line', __( 'Invalid line item.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		// Eigener Zeitraum (beide Daten oder keins).
		$line_from = isset( $line['date_from'] ) ? (string) $line['date_from'] : '';
		$line_to   = isset( $line['date_to'] ) ? (string) $line['date_to'] : '';
		if ( ( '' === $line_from ) !== ( '' === $line_to ) ) {
			return new WP_Error( 'pp_invalid_dates', __( 'Provide both start and end date, or neither.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		if ( '' !== $line_from && ! Availability::is_valid_range( $line_from, $line_to ) ) {
			return new WP_Error( 'pp_invalid_dates', __( 'Invalid date range.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		// Effektiver Zeitraum: Zeile, sonst geerbt vom Projekt. Ohne Zeitraum
		// blockiert die Buchung nichts → kein Guard nötig.
		$eff_from = '' !== $line_from ? $line_from : (string) $project->date_start;
		$eff_to   = '' !== $line_to ? $line_to : (string) $project->date_end;
		if ( '' !== $eff_from && '' !== $eff_to ) {
			$available = Availability::available_quantity( $item_id, $eff_from, $eff_to, 0, (int) $project->id );

			// Übrige Zeilen des eigenen Projekts (statusunabhängig) gegenrechnen.
			$own_booked = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COALESCE(SUM(pi.quantity), 0)
				 FROM %i pi
				 WHERE pi.project_id = %d
				   AND pi.item_id = %d
				   AND pi.id != %d
				   AND COALESCE(pi.date_from, %s) <= %s
				   AND COALESCE(pi.date_to, %s) >= %s',
				Schema::table( 'project_items' ),
				(int) $project->id,
				$item_id,
				$exclude_line_id,
				(string) $project->date_start,
				$eff_to,
				(string) $project->date_end,
				$eff_from
			) );
			$available = max( 0, $available - $own_booked );

			if ( $qty > $available ) {
				$item = Inventory::get_item( $item_id );
				return new WP_Error(
					'pp_not_available',
					sprintf(
						/* translators: 1: item name, 2: available quantity */
						__( '"%1$s" is only available %2$d× in this period.', 'project-prepper' ),
						$item ? $item->name : "#{$item_id}",
						$available
					),
					[ 'status' => 409 ]
				);
			}
		}

		return [
			'item_id'   => $item_id,
			'quantity'  => $qty,
			'date_from' => '' !== $line_from ? $line_from : null,
			'date_to'   => '' !== $line_to ? $line_to : null,
		];
	}

	/**
	 * Projekt-Zeitraum validieren: beide Daten optional; wenn beide gesetzt,
	 * muss start <= end gelten.
	 *
	 * @return array|WP_Error [ 'start' => ?string, 'end' => ?string ]
	 */
	private static function validate_dates( string $start, string $end ) {
		if ( '' !== $start && ! Availability::is_valid_date( $start ) ) {
			return new WP_Error( 'pp_invalid_dates', __( 'Invalid date range.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		if ( '' !== $end && ! Availability::is_valid_date( $end ) ) {
			return new WP_Error( 'pp_invalid_dates', __( 'Invalid date range.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		if ( '' !== $start && '' !== $end && $start > $end ) {
			return new WP_Error( 'pp_invalid_dates', __( 'Invalid date range.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		return [
			'start' => '' !== $start ? $start : null,
			'end'   => '' !== $end ? $end : null,
		];
	}
}
