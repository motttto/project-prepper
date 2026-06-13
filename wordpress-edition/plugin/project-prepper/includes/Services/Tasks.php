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

		$wpdb->insert( Schema::table( 'project_tasks' ), [
			'project_id'    => $project_id,
			'title'         => trim( (string) $data['title'] ),
			'task_status'   => $status,
			'priority'      => $priority,
			'due_date'      => '' !== $due ? $due : null,
			'assigned_user' => ! empty( $data['assigned_user'] ) ? (int) $data['assigned_user'] : null,
			'sort_order'    => (int) ( $data['sort_order'] ?? 0 ),
			'created_at'    => current_time( 'mysql' ),
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
