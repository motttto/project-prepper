<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Projekt-Checklisten — Pendant zu project_checklists / project_checklist_items der App.
 */
class Checklists {

	public static function for_project( int $project_id ): array {
		global $wpdb;
		$lists = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE project_id = %d ORDER BY sort_order ASC, id ASC',
			Schema::table( 'project_checklists' ),
			$project_id
		) ) ?: [];
		foreach ( $lists as $list ) {
			$list->items = $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM %i WHERE checklist_id = %d ORDER BY sort_order ASC, id ASC',
				Schema::table( 'project_checklist_items' ),
				(int) $list->id
			) ) ?: [];
		}
		return $lists;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_checklists' ),
			$id
		) );
	}

	/**
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, string $name ) {
		global $wpdb;
		if ( '' === trim( $name ) ) {
			return new WP_Error( 'pp_missing_name', __( 'Checklist name is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$wpdb->insert( Schema::table( 'project_checklists' ), [
			'project_id' => $project_id,
			'name'       => trim( $name ),
			'sort_order' => 0,
		], [ '%d', '%s', '%d' ] );
		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'project_checklist_created', 'project', $project_id, [ 'name' => trim( $name ) ] );
		return $id;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;
		$list = self::get( $id );
		if ( ! $list ) {
			return new WP_Error( 'pp_not_found', __( 'Checklist not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$fields  = [];
		$formats = [];
		if ( array_key_exists( 'name', $data ) ) {
			if ( '' === trim( (string) $data['name'] ) ) {
				return new WP_Error( 'pp_missing_name', __( 'Checklist name is required.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['name'] = trim( (string) $data['name'] );
			$formats[]      = '%s';
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$fields['sort_order'] = (int) $data['sort_order'];
			$formats[]            = '%d';
		}
		if ( $fields ) {
			$wpdb->update( Schema::table( 'project_checklists' ), $fields, [ 'id' => $id ], $formats, [ '%d' ] );
			ActivityLog::log( 'project_checklist_updated', 'project', (int) $list->project_id, [ 'checklist_id' => $id ] );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;
		$list = self::get( $id );
		if ( ! $list ) {
			return new WP_Error( 'pp_not_found', __( 'Checklist not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$wpdb->delete( Schema::table( 'project_checklist_items' ), [ 'checklist_id' => $id ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'project_checklists' ), [ 'id' => $id ], [ '%d' ] );

		ActivityLog::log( 'project_checklist_deleted', 'project', (int) $list->project_id, [ 'name' => $list->name ] );
		return true;
	}

	/**
	 * Checkliste innerhalb ihres Projekts hoch/runter schieben (sort_order-
	 * Tausch; Reihenfolge = for_project: sort_order ASC, id ASC). Am Rand: No-op.
	 *
	 * @param string $dir 'up' | 'down'
	 * @return true|WP_Error
	 */
	public static function move( int $id, string $dir ) {
		global $wpdb;
		$list = self::get( $id );
		if ( ! $list ) {
			return new WP_Error( 'pp_not_found', __( 'Checklist not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM %i WHERE project_id = %d ORDER BY sort_order ASC, id ASC',
			Schema::table( 'project_checklists' ),
			(int) $list->project_id
		) );
		$moved = self::swap_in_list( Schema::table( 'project_checklists' ), array_map( 'intval', $ids ?: [] ), $id, $dir );
		if ( true === $moved ) {
			ActivityLog::log( 'project_checklist_moved', 'project', (int) $list->project_id, [ 'checklist_id' => $id, 'dir' => $dir ] );
		}
		return $moved;
	}

	/* ---------- Items ---------- */

	public static function get_item( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_checklist_items' ),
			$id
		) );
	}

	/**
	 * @return int|WP_Error
	 */
	public static function add_item( int $checklist_id, string $label ) {
		global $wpdb;
		$list = self::get( $checklist_id );
		if ( ! $list ) {
			return new WP_Error( 'pp_not_found', __( 'Checklist not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( '' === trim( $label ) ) {
			return new WP_Error( 'pp_missing_label', __( 'Checklist item label is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$wpdb->insert( Schema::table( 'project_checklist_items' ), [
			'checklist_id' => $checklist_id,
			'label'        => trim( $label ),
			'is_checked'   => 0,
			'sort_order'   => 0,
		], [ '%d', '%s', '%d', '%d' ] );
		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'project_checklist_item_added', 'project', (int) $list->project_id, [ 'checklist_id' => $checklist_id ] );
		return $id;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function update_item( int $id, array $data ) {
		global $wpdb;
		$item = self::get_item( $id );
		if ( ! $item ) {
			return new WP_Error( 'pp_not_found', __( 'Checklist item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$fields  = [];
		$formats = [];
		if ( array_key_exists( 'label', $data ) ) {
			if ( '' === trim( (string) $data['label'] ) ) {
				return new WP_Error( 'pp_missing_label', __( 'Checklist item label is required.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['label'] = trim( (string) $data['label'] );
			$formats[]       = '%s';
		}
		if ( array_key_exists( 'is_checked', $data ) ) {
			$fields['is_checked'] = ! empty( $data['is_checked'] ) ? 1 : 0;
			$formats[]            = '%d';
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$fields['sort_order'] = (int) $data['sort_order'];
			$formats[]            = '%d';
		}
		if ( $fields ) {
			$wpdb->update( Schema::table( 'project_checklist_items' ), $fields, [ 'id' => $id ], $formats, [ '%d' ] );
			$list = self::get( (int) $item->checklist_id );
			ActivityLog::log( 'project_checklist_item_updated', 'project', $list ? (int) $list->project_id : null, [ 'item_id' => $id ] );
		}
		return true;
	}

	/**
	 * Checklist-Punkt innerhalb seiner Liste hoch/runter schieben (sort_order-
	 * Tausch; Reihenfolge = for_project: sort_order ASC, id ASC). Am Rand: No-op.
	 *
	 * @param string $dir 'up' | 'down'
	 * @return true|WP_Error
	 */
	public static function move_item( int $id, string $dir ) {
		global $wpdb;
		$item = self::get_item( $id );
		if ( ! $item ) {
			return new WP_Error( 'pp_not_found', __( 'Checklist item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM %i WHERE checklist_id = %d ORDER BY sort_order ASC, id ASC',
			Schema::table( 'project_checklist_items' ),
			(int) $item->checklist_id
		) );
		$moved = self::swap_in_list( Schema::table( 'project_checklist_items' ), array_map( 'intval', $ids ?: [] ), $id, $dir );
		if ( true === $moved ) {
			$list = self::get( (int) $item->checklist_id );
			ActivityLog::log( 'project_checklist_item_moved', 'project', $list ? (int) $list->project_id : null, [ 'item_id' => $id, 'dir' => $dir ] );
		}
		return $moved;
	}

	/**
	 * sort_order der Liste auf 0..n-1 normalisieren und $id mit dem Nachbarn
	 * in Richtung $dir tauschen. Am Rand: No-op (true). $dir wird validiert.
	 *
	 * @param array<int> $ordered_ids IDs in aktueller Anzeige-Reihenfolge.
	 * @return true|WP_Error
	 */
	private static function swap_in_list( string $table, array $ordered_ids, int $id, string $dir ) {
		global $wpdb;
		if ( ! in_array( $dir, [ 'up', 'down' ], true ) ) {
			return new WP_Error( 'pp_invalid_dir', __( 'Invalid direction.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$pos = array_search( $id, $ordered_ids, true );
		if ( false === $pos ) {
			return new WP_Error( 'pp_not_found', __( 'Checklist item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$target = 'up' === $dir ? $pos - 1 : $pos + 1;
		if ( $target < 0 || $target >= count( $ordered_ids ) ) {
			return true;
		}
		[ $ordered_ids[ $pos ], $ordered_ids[ $target ] ] = [ $ordered_ids[ $target ], $ordered_ids[ $pos ] ];
		foreach ( array_values( $ordered_ids ) as $index => $row_id ) {
			$wpdb->update( $table, [ 'sort_order' => $index ], [ 'id' => (int) $row_id ], [ '%d' ], [ '%d' ] );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function delete_item( int $id ) {
		global $wpdb;
		$item = self::get_item( $id );
		if ( ! $item ) {
			return new WP_Error( 'pp_not_found', __( 'Checklist item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$wpdb->delete( Schema::table( 'project_checklist_items' ), [ 'id' => $id ], [ '%d' ] );
		$list = self::get( (int) $item->checklist_id );
		ActivityLog::log( 'project_checklist_item_deleted', 'project', $list ? (int) $list->project_id : null, [ 'item_id' => $id ] );
		return true;
	}
}
