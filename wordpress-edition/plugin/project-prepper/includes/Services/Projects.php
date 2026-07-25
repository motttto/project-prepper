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

		// Gruppen-Zugriffsfilter (additiv, v0.10.0): Nicht-Admins sehen Projekte
		// mit owner_group_id nur, wenn sie Mitglied der Gruppe sind; Site-Ebene
		// (NULL) bleibt sichtbar (Cap-gesteuert wie bisher). Admins sehen alles.
		// Ein WHERE, kein N+1. Default (alles NULL) bleibt damit unverändert.
		$user_id = get_current_user_id();
		if ( ! Groups::user_is_admin( $user_id ) ) {
			$group_ids = Groups::user_group_ids( $user_id );
			if ( $group_ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $group_ids ), '%d' ) );
				$where[]      = '(p.owner_group_id IS NULL OR p.owner_group_id IN (' . $placeholders . '))';
				$params       = array_merge( $params, $group_ids );
			} else {
				$where[] = 'p.owner_group_id IS NULL';
			}
		}

		array_unshift( $params, $lines, $projects );
		$sql = $wpdb->prepare(
			'SELECT p.*, (SELECT COUNT(*) FROM %i pi WHERE pi.project_id = p.id) AS item_count
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
		// Gruppen-Zugriff (v0.10.0): Nicht-Mitglieder eines Gruppen-Projekts
		// behandeln wir wie „nicht gefunden" (kein Leak). Site-Ebene + Admins
		// unverändert. Default (owner_group_id NULL) → true → wie bisher.
		if ( ! Groups::user_can_access_project( $project, get_current_user_id() ) ) {
			return null;
		}
		$project->items        = self::items_for( $id );
		$project->schedule     = Schedule::for_project( $id );
		$project->checklists   = Checklists::for_project( $id );
		$project->tasks        = Tasks::for_project( $id );
		$project->cost_items   = Costs::for_project( $id );
		$project->cost_summary = Costs::summary( $id );
		$project->consumables  = Consumables::for_project( $id );
		$project->team         = Team::for_project( $id );
		$project->contacts     = Contacts::for_project( $id );
		$project->files        = Files::for_project( $id );
		$project->members        = ProjectMembers::for_project( $id );
		$project->decisions      = Decisions::for_project( $id, get_current_user_id() ?: null );
		$project->profit_shares  = ProfitShares::for_project( $id );
		$project->profit_summary = ProfitShares::summary( $id );
		$project->agreement      = Agreements::for_project( $id, get_current_user_id() ?: null );
		$project->polls          = Polls::for_project( $id, get_current_user_id() ?: null );
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
		$budget = self::sanitize_money( $data['budget_planned'] ?? '' );
		if ( false === $budget ) {
			return new WP_Error( 'pp_invalid_amount', __( 'Invalid amount.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$revenue = self::sanitize_money( $data['revenue_actual'] ?? '' );
		if ( false === $revenue ) {
			return new WP_Error( 'pp_invalid_amount', __( 'Invalid amount.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$group = self::sanitize_owner_group( $data['owner_group_id'] ?? null );
		if ( is_wp_error( $group ) ) {
			return $group;
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
			'budget_planned' => $budget,
			'revenue_actual' => $revenue,
			'owner_group_id' => $group,
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
		foreach ( [ 'budget_planned', 'revenue_actual' ] as $money_key ) {
			if ( array_key_exists( $money_key, $data ) ) {
				$amount = self::sanitize_money( $data[ $money_key ] );
				if ( false === $amount ) {
					return new WP_Error( 'pp_invalid_amount', __( 'Invalid amount.', 'project-prepper' ), [ 'status' => 400 ] );
				}
				$fields[ $money_key ] = $amount;
			}
		}
		if ( array_key_exists( 'owner_group_id', $data ) ) {
			$group = self::sanitize_owner_group( $data['owner_group_id'] );
			if ( is_wp_error( $group ) ) {
				return $group;
			}
			$fields['owner_group_id'] = $group;
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
		$wpdb->delete( Schema::table( 'project_schedule' ), [ 'project_id' => $id ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'cost_items' ), [ 'project_id' => $id ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'project_consumables' ), [ 'project_id' => $id ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'project_team' ), [ 'project_id' => $id ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'project_contacts' ), [ 'project_id' => $id ], [ '%d' ] );
		// Nur die Datei-Verknüpfungen entfernen, nicht die Medien-Anhänge selbst.
		$wpdb->delete( Schema::table( 'project_files' ), [ 'project_id' => $id ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'project_members' ), [ 'project_id' => $id ], [ '%d' ] );
		// Beschlüsse + deren Stimmen (Phase 3) räumen.
		$decision_ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM %i WHERE project_id = %d',
			Schema::table( 'project_decisions' ),
			$id
		) );
		foreach ( array_map( 'intval', $decision_ids ) as $decision_id ) {
			$wpdb->delete( Schema::table( 'project_decision_votes' ), [ 'decision_id' => $decision_id ], [ '%d' ] );
		}
		$wpdb->delete( Schema::table( 'project_decisions' ), [ 'project_id' => $id ], [ '%d' ] );
		// Gewinnverteilung (Phase 4) räumen.
		$wpdb->delete( Schema::table( 'project_profit_shares' ), [ 'project_id' => $id ], [ '%d' ] );
		// Kooperationsvereinbarung + Signaturen (Phase 5) räumen.
		$agreement_ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM %i WHERE project_id = %d',
			Schema::table( 'project_agreements' ),
			$id
		) );
		foreach ( array_map( 'intval', $agreement_ids ) as $agreement_id ) {
			$wpdb->delete( Schema::table( 'project_agreement_signatures' ), [ 'agreement_id' => $agreement_id ], [ '%d' ] );
		}
		$wpdb->delete( Schema::table( 'project_agreements' ), [ 'project_id' => $id ], [ '%d' ] );
		// Umfragen + Optionen + Stimmen (v0.15.0) räumen.
		$poll_ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM %i WHERE project_id = %d',
			Schema::table( 'project_polls' ),
			$id
		) );
		foreach ( array_map( 'intval', $poll_ids ) as $poll_id ) {
			$option_ids = $wpdb->get_col( $wpdb->prepare(
				'SELECT id FROM %i WHERE poll_id = %d',
				Schema::table( 'project_poll_options' ),
				$poll_id
			) );
			foreach ( array_map( 'intval', $option_ids ) as $option_id ) {
				$wpdb->delete( Schema::table( 'project_poll_votes' ), [ 'option_id' => $option_id ], [ '%d' ] );
			}
			$wpdb->delete( Schema::table( 'project_poll_options' ), [ 'poll_id' => $poll_id ], [ '%d' ] );
		}
		$wpdb->delete( Schema::table( 'project_polls' ), [ 'project_id' => $id ], [ '%d' ] );
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
			'SELECT pi.*, i.name AS item_name, i.inventory_number,
			        i.description AS item_description, i.item_condition, i.image_id
			 FROM %i pi
			 LEFT JOIN %i i ON i.id = pi.item_id
			 WHERE pi.project_id = %d
			 ORDER BY pi.id ASC',
			Schema::table( 'project_items' ),
			Schema::table( 'items' ),
			$project_id
		) ) ?: [];
	}

	/** Packlisten-Zeitstempel-Felder, die {@see set_line_flag} setzen darf. */
	const LINE_FLAGS = [ 'packed' => 'packed_at', 'tested' => 'tested_at' ];

	/**
	 * Packlisten-Status (gepackt/getestet) einer Buchungszeile setzen: $on=true →
	 * Feld auf jetzt, false → NULL (zurückgesetzt). Der Aufrufer erzwingt den
	 * Projekt-Zugriff (member_owned_project).
	 *
	 * @param string $flag 'packed' oder 'tested' (Whitelist {@see LINE_FLAGS}).
	 * @return true|WP_Error
	 */
	public static function set_line_flag( int $project_id, int $line_id, string $flag, bool $on ) {
		global $wpdb;
		if ( ! isset( self::LINE_FLAGS[ $flag ] ) ) {
			return new WP_Error( 'pp_bad_flag', __( 'Unknown status.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$existing = self::get_item_line( $project_id, $line_id );
		if ( ! $existing ) {
			return new WP_Error( 'pp_not_found', __( 'Line item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$wpdb->update(
			Schema::table( 'project_items' ),
			[ self::LINE_FLAGS[ $flag ] => $on ? current_time( 'mysql' ) : null ],
			[ 'id' => $line_id, 'project_id' => $project_id ]
		);
		return true;
	}

	/** Kurzform: „gepackt"-Status setzen (Pendant zu {@see set_line_flag}). */
	public static function set_packed( int $project_id, int $line_id, bool $packed ) {
		return self::set_line_flag( $project_id, $line_id, 'packed', $packed );
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

	// Gültige Freigabe-Stati einer Buchungszeile (Pendant zur App).
	const APPROVAL_STATUSES = [ 'approved', 'pending', 'rejected' ];

	/**
	 * Buchungszeile anlegen — mit Verfügbarkeits-Guard über den effektiven
	 * Zeitraum (Zeilen-Datum, Fallback Projekt-Zeitraum).
	 *
	 * Optionale Freigabe-Felder in $line (vom Member-Portal gesetzt, wenn der
	 * Artikel einem anderen Mitglied gehört und Freigabe verlangt): 'approval_status'
	 * ('pending') + 'requested_by' (User-ID des Anfragers). Default = 'approved'
	 * (eigene Artikel, Freigabe nicht nötig, REST/Backend). Pending-Zeilen zählen
	 * weiterhin gegen die Verfügbarkeit (siehe Availability).
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

		$approval = in_array( $line['approval_status'] ?? '', self::APPROVAL_STATUSES, true )
			? (string) $line['approval_status']
			: 'approved';
		$requested_by = 'approved' !== $approval && ! empty( $line['requested_by'] )
			? (int) $line['requested_by']
			: null;

		$wpdb->insert( Schema::table( 'project_items' ), [
			'project_id'      => $project_id,
			'item_id'         => $validated['item_id'],
			'quantity'        => $validated['quantity'],
			'date_from'       => $validated['date_from'],
			'date_to'         => $validated['date_to'],
			'notes'           => $line['notes'] ?? '',
			'approval_status' => $approval,
			'requested_by'    => $requested_by,
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
		// Optionale Freigabe-Felder (vom Member-Portal beim Re-Approval gesetzt).
		// Whitelist wie in add_item; requested_by/decided_at folgen dem Status.
		if ( array_key_exists( 'approval_status', $line ) && in_array( $line['approval_status'], self::APPROVAL_STATUSES, true ) ) {
			$fields['approval_status'] = (string) $line['approval_status'];
		}
		if ( array_key_exists( 'requested_by', $line ) ) {
			$fields['requested_by'] = ! empty( $line['requested_by'] ) ? (int) $line['requested_by'] : null;
		}
		if ( array_key_exists( 'decided_at', $line ) ) {
			$fields['decided_at'] = null === $line['decided_at'] ? null : (string) $line['decided_at'];
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

	/**
	 * Budget-/Umsatzbetrag validieren: leer ('' / null) → NULL, sonst
	 * nicht-negative Dezimalzahl. Ungültig (nicht-numerisch / negativ) → false.
	 *
	 * @param mixed $value
	 * @return float|null|false
	 */
	private static function sanitize_money( $value ) {
		if ( null === $value || '' === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return null;
		}
		if ( ! is_numeric( $value ) ) {
			return false;
		}
		$num = (float) $value;
		if ( $num < 0 ) {
			return false;
		}
		return round( $num, 2 );
	}

	/**
	 * owner_group_id validieren (v0.10.0). Leer/0/null → NULL (Site-Ebene).
	 * Gesetzt → muss eine existierende Gruppe sein, und der aktuelle User muss
	 * sie zuweisen dürfen: Admin/Manager (pp_groups_manage) ODER aktives Mitglied
	 * der Gruppe. Konservativ — verhindert, dass ein Nicht-Mitglied ein Projekt
	 * einer fremden Gruppe „wegschiebt".
	 *
	 * @param mixed $value
	 * @return int|null|WP_Error
	 */
	private static function sanitize_owner_group( $value ) {
		$group_id = (int) $value;
		if ( $group_id <= 0 ) {
			return null;
		}
		if ( ! Groups::get( $group_id ) ) {
			return new WP_Error( 'pp_invalid_group', __( 'Unknown group.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$user_id = get_current_user_id();
		if ( ! Groups::user_is_admin( $user_id ) && ! Groups::is_member( $group_id, $user_id ) ) {
			return new WP_Error( 'pp_forbidden_group', __( 'You are not a member of this group.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		return $group_id;
	}

	/**
	 * Projekte, die einen bestimmten Artikel buchen — für die namentliche
	 * Anzeige „in Projekt X gebucht" im Inventar-Item-Detail (Pendant zum
	 * aggregierten out_now-Zähler). Liefert ALLE Buchungen (auch nicht-
	 * blockierende wie draft/planned), damit der Nutzer den vollen Kontext
	 * sieht; das Frontend kennzeichnet blockende Stati über das Badge.
	 *
	 * Effektiver Zeitraum = Zeilen-Datum mit Fallback auf den Projekt-Zeitraum.
	 * Sortiert nach Start, dann Projekt.
	 *
	 * @return array<object>
	 */
	public static function bookings_for_item( int $item_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT pi.id AS booking_id, pi.quantity,
					COALESCE(pi.date_from, p.date_start) AS date_from,
					COALESCE(pi.date_to, p.date_end) AS date_to,
					p.id AS project_id, p.project_number, p.name AS project_name, p.status
			 FROM %i pi
			 INNER JOIN %i p ON p.id = pi.project_id
			 WHERE pi.item_id = %d
			 ORDER BY COALESCE(pi.date_from, p.date_start) IS NULL, COALESCE(pi.date_from, p.date_start) ASC, p.id ASC',
			Schema::table( 'project_items' ),
			Schema::table( 'projects' ),
			$item_id
		) ) ?: [];
	}
}
