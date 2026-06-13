<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Projekt-Beteiligte (v0.11.0, Gruppen-Phase 2) — Pendant zu `project_members`
 * der App, bewusst schlank.
 *
 * Das Roster verweist auf WP-Benutzer aus der besitzenden Gruppe des Projekts
 * (pp_projects.owner_group_id) + freie Rolle (role_title) + Notiz. Es dient rein
 * dokumentarisch der Frage „wer ist am Projekt beteiligt und in welcher Rolle".
 *
 * Bewusste Vereinfachungen ggü. App:
 * - KEINE Beträge/Raten (hourly_rate/capital_contribution kommen mit der
 *   Gewinn- bzw. Vereinbarungs-Phase, mit eigenen Tabellen).
 * - KEINE zweite Zugriffsebene — der Projekt-Zugriff bleibt gruppen-basiert aus
 *   Phase 1 (Groups::user_can_access_project). Das Roster gewährt KEINE Rechte.
 *
 * Validierung (Kernstück Phase 2): ein Beteiligter kann nur hinzugefügt werden,
 * wenn das Projekt eine Eigentümer-Gruppe hat UND der WP-User aktives Mitglied
 * dieser Gruppe ist. Siehe docs/03-GRUPPEN-ARCHITEKTUR.md §Phasen-Roadmap.
 */
class ProjectMembers {

	/* ===================== Lesen ===================== */

	/**
	 * Roster eines Projekts, je Zeile mit aufgelöstem WP-User
	 * (display_name + user_email). Ein gelöschter WP-User (verwaiste user_id)
	 * wird mit ->missing=true markiert, statt zu crashen.
	 *
	 * @return array<object>
	 */
	public static function for_project( int $project_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE project_id = %d ORDER BY sort_order ASC, id ASC',
			Schema::table( 'project_members' ),
			$project_id
		) ) ?: [];

		foreach ( $rows as $row ) {
			$user              = get_userdata( (int) $row->user_id );
			$row->user_id      = (int) $row->user_id;
			$row->display_name = $user ? $user->display_name : sprintf( '#%d', (int) $row->user_id );
			$row->user_email   = $user ? $user->user_email : '';
			$row->missing      = $user ? false : true;
		}
		return $rows;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_members' ),
			$id
		) );
	}

	/* ===================== Schreiben ===================== */

	/**
	 * Beteiligten zum Projekt hinzufügen.
	 *
	 * Validierung:
	 * - Projekt muss eine Eigentümer-Gruppe haben (owner_group_id NOT NULL),
	 *   sonst 400 pp_no_group (ein Site-Projekt hat keine Gruppe → keine
	 *   Gruppenmitglieder, aus denen man wählen könnte).
	 * - $user_id MUSS aktives Mitglied dieser Gruppe sein, sonst 400
	 *   pp_not_group_member.
	 * - Doppel-Mitgliedschaft → idempotent: role_title/note werden, falls
	 *   übergeben, aktualisiert; sonst bleibt die Zeile unverändert (kein Fehler).
	 *
	 * @return int|WP_Error  ID der (neuen oder bestehenden) Roster-Zeile.
	 */
	public static function add( int $project_id, int $user_id, array $data = [] ) {
		global $wpdb;

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'pp_invalid_user', __( 'Unknown user.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$group_id = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT owner_group_id FROM %i WHERE id = %d',
			Schema::table( 'projects' ),
			$project_id
		) );
		if ( ! $group_id ) {
			return new WP_Error(
				'pp_no_group',
				__( 'This project has no owning group. Assign a group first to add members.', 'project-prepper' ),
				[ 'status' => 400 ]
			);
		}
		if ( ! Groups::is_member( $group_id, $user_id ) ) {
			return new WP_Error(
				'pp_not_group_member',
				__( 'This user is not a member of the project group.', 'project-prepper' ),
				[ 'status' => 400 ]
			);
		}

		$role_title = isset( $data['role_title'] ) ? (string) $data['role_title'] : '';
		$note       = isset( $data['note'] ) ? (string) $data['note'] : '';

		$existing = $wpdb->get_row( $wpdb->prepare(
			'SELECT id FROM %i WHERE project_id = %d AND user_id = %d',
			Schema::table( 'project_members' ),
			$project_id,
			$user_id
		) );
		if ( $existing ) {
			// Idempotent: nur übergebene Felder angleichen, kein Fehler.
			$fields = [];
			if ( array_key_exists( 'role_title', $data ) ) {
				$fields['role_title'] = $role_title;
			}
			if ( array_key_exists( 'note', $data ) ) {
				$fields['note'] = $note;
			}
			if ( $fields ) {
				$wpdb->update( Schema::table( 'project_members' ), $fields, [ 'id' => (int) $existing->id ] );
			}
			return (int) $existing->id;
		}

		$wpdb->insert( Schema::table( 'project_members' ), [
			'project_id' => $project_id,
			'user_id'    => $user_id,
			'role_title' => $role_title,
			'note'       => $note,
			'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
			'created_at' => current_time( 'mysql' ),
		] );
		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'project_member_added', 'project', $project_id, [
			'user_id'    => $user_id,
			'role_title' => $role_title,
		] );
		return $id;
	}

	/**
	 * Partielles Update — role_title/note/sort_order. user_id/project_id sind
	 * unveränderlich (eine Zeile = eine Person an einem Projekt).
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'pp_not_found', __( 'Member not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		$fields = [];
		if ( array_key_exists( 'role_title', $data ) ) {
			$fields['role_title'] = (string) $data['role_title'];
		}
		if ( array_key_exists( 'note', $data ) ) {
			$fields['note'] = (string) $data['note'];
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$fields['sort_order'] = (int) $data['sort_order'];
		}
		if ( $fields ) {
			$wpdb->update( Schema::table( 'project_members' ), $fields, [ 'id' => $id ] );
			ActivityLog::log( 'project_member_updated', 'project', (int) $row->project_id, [
				'member_id' => $id,
				'fields'    => array_keys( $fields ),
			] );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function remove( int $id ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'pp_not_found', __( 'Member not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$wpdb->delete( Schema::table( 'project_members' ), [ 'id' => $id ], [ '%d' ] );
		ActivityLog::log( 'project_member_removed', 'project', (int) $row->project_id, [
			'user_id' => (int) $row->user_id,
		] );
		return true;
	}
}
