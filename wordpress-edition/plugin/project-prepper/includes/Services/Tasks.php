<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Projekt-Aufgaben — Pendant zu project_tasks der App (MVP-Subset:
 * Titel, Status open→doing→done, Priorität, Fälligkeit, optionaler WP-User).
 */
class Tasks {

	const STATUSES   = [ 'open', 'doing', 'done' ];
	const PRIORITIES = [ 'low', 'normal', 'high' ];

	// Annahme-Flow (v0.31.0, App: project_tasks.assignment_status): eine
	// Zuweisung an eine andere Person startet als 'pending' — die zugewiesene
	// Person nimmt an oder lehnt ab. Ablehnen behält assigned_user (wie die
	// App: Badge „Abgelehnt" am Namen); neu zuweisen setzt wieder 'pending'.
	const ASSIGNMENT_STATUSES = [ 'pending', 'accepted', 'declined' ];

	public static function for_project( int $project_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE project_id = %d ORDER BY sort_order ASC, id ASC',
			Schema::table( 'project_tasks' ),
			$project_id
		) ) ?: [];
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_tasks' ),
			$id
		) );
	}

	/**
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data ) {
		global $wpdb;

		if ( empty( $data['title'] ) || '' === trim( (string) $data['title'] ) ) {
			return new WP_Error( 'pp_missing_title', __( 'Task title is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$status   = $data['task_status'] ?? 'open';
		$priority = $data['priority'] ?? 'normal';
		if ( ! in_array( $status, self::STATUSES, true ) || ! in_array( $priority, self::PRIORITIES, true ) ) {
			return new WP_Error( 'pp_invalid_status', __( 'Invalid status.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$due = isset( $data['due_date'] ) ? (string) $data['due_date'] : '';
		if ( '' !== $due && ! Availability::is_valid_date( $due ) ) {
			return new WP_Error( 'pp_invalid_dates', __( 'Invalid date range.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$assignment = $data['assignment_status'] ?? 'accepted';
		if ( ! in_array( $assignment, self::ASSIGNMENT_STATUSES, true ) ) {
			return new WP_Error( 'pp_invalid_status', __( 'Invalid status.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$wpdb->insert( Schema::table( 'project_tasks' ), [
			'project_id'        => $project_id,
			'title'             => trim( (string) $data['title'] ),
			'task_status'       => $status,
			'priority'          => $priority,
			'due_date'          => '' !== $due ? $due : null,
			'assigned_user'     => ! empty( $data['assigned_user'] ) ? (int) $data['assigned_user'] : null,
			'assignment_status' => $assignment,
			'sort_order'        => (int) ( $data['sort_order'] ?? 0 ),
			'created_at'        => current_time( 'mysql' ),
		] );
		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'project_task_created', 'project', $project_id, [ 'title' => trim( (string) $data['title'] ) ] );
		return $id;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$task = self::get( $id );
		if ( ! $task ) {
			return new WP_Error( 'pp_not_found', __( 'Task not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		$fields = [];
		if ( array_key_exists( 'title', $data ) ) {
			if ( '' === trim( (string) $data['title'] ) ) {
				return new WP_Error( 'pp_missing_title', __( 'Task title is required.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['title'] = trim( (string) $data['title'] );
		}
		if ( array_key_exists( 'task_status', $data ) ) {
			if ( ! in_array( $data['task_status'], self::STATUSES, true ) ) {
				return new WP_Error( 'pp_invalid_status', __( 'Invalid status.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['task_status'] = $data['task_status'];
		}
		if ( array_key_exists( 'priority', $data ) ) {
			if ( ! in_array( $data['priority'], self::PRIORITIES, true ) ) {
				return new WP_Error( 'pp_invalid_status', __( 'Invalid status.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['priority'] = $data['priority'];
		}
		if ( array_key_exists( 'due_date', $data ) ) {
			$due = (string) $data['due_date'];
			if ( '' !== $due && ! Availability::is_valid_date( $due ) ) {
				return new WP_Error( 'pp_invalid_dates', __( 'Invalid date range.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['due_date'] = '' !== $due ? $due : null;
		}
		if ( array_key_exists( 'assigned_user', $data ) ) {
			$fields['assigned_user'] = ! empty( $data['assigned_user'] ) ? (int) $data['assigned_user'] : null;
		}
		if ( array_key_exists( 'assignment_status', $data ) ) {
			if ( ! in_array( $data['assignment_status'], self::ASSIGNMENT_STATUSES, true ) ) {
				return new WP_Error( 'pp_invalid_status', __( 'Invalid status.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['assignment_status'] = $data['assignment_status'];
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$fields['sort_order'] = (int) $data['sort_order'];
		}
		if ( $fields ) {
			$wpdb->update( Schema::table( 'project_tasks' ), $fields, [ 'id' => $id ] );
			ActivityLog::log( 'project_task_updated', 'project', (int) $task->project_id, [
				'task_id' => $id,
				'fields'  => array_keys( $fields ),
			] );
		}
		return true;
	}

	/**
	 * Offene Zuweisungs-Anfragen an einen User („Annehmen/Ablehnen" steht aus)
	 * über alle Projekte seiner Gruppen — für den Dashboard-Hinweis und die
	 * Benachrichtigungen. Der group_members-Join stellt sicher, dass nur
	 * Projekte zählen, auf die der User (noch) Zugriff hat.
	 *
	 * @return array<object> Zeilen {id, title, project_id, project_name}.
	 */
	public static function pending_for_user( int $user_id ): array {
		global $wpdb;
		if ( ! $user_id ) {
			return [];
		}
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT t.id, t.title, t.project_id, p.name AS project_name
			 FROM %i t
			 INNER JOIN %i p ON p.id = t.project_id
			 INNER JOIN %i gm ON gm.group_id = p.owner_group_id AND gm.user_id = t.assigned_user
			 WHERE t.assigned_user = %d AND t.assignment_status = 'pending' AND t.task_status <> 'done'
			 ORDER BY t.id DESC",
			Schema::table( 'project_tasks' ),
			Schema::table( 'projects' ),
			Schema::table( 'group_members' ),
			$user_id
		) ) ?: [];
	}

	/**
	 * @return true|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;
		$task = self::get( $id );
		if ( ! $task ) {
			return new WP_Error( 'pp_not_found', __( 'Task not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$wpdb->delete( Schema::table( 'project_tasks' ), [ 'id' => $id ], [ '%d' ] );
		ActivityLog::log( 'project_task_deleted', 'project', (int) $task->project_id, [ 'title' => $task->title ] );
		return true;
	}
}
