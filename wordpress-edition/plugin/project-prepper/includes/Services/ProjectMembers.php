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
 * - Sehen bleibt gruppen-basiert (Groups::user_can_access_project).
 *
 * MIT-BEARBEITER (seit den Ersteller-Rechten, Mitglieder-Feedback 2026-09):
 * Die Spalte can_edit macht eine Roster-Zeile zum Bearbeitungsrecht. Ein
 * Kollektiv-Projekt dürfen nur sein Ersteller (projects.created_by) und die
 * Mit-Bearbeiter (can_edit = 1) ändern; alle anderen Mitglieder sehen es
 * read-only — Regeln in {@see Projects::can_edit()}. Vergeben/entziehen nur
 * der Ersteller im Portal (über {@see Projects::set_coeditor()} →
 * {@see set_editor()}). Das Betreiber-REST (add/update/remove) fasst can_edit
 * nicht an; remove löscht die Zeile und damit auch ein Mit-Bearbeiter-Recht.
 * Zeilen OHNE Haken gewähren weiterhin KEINE Rechte.
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
			$row->can_edit     = 1 === (int) ( $row->can_edit ?? 0 );
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

	/** Ist $user_id Mit-Bearbeiter des Projekts (can_edit = 1)? Mitgliedschaft prüft der Aufrufer. */
	public static function is_editor( int $project_id, int $user_id ): bool {
		global $wpdb;
		if ( $project_id <= 0 || $user_id <= 0 ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM %i WHERE project_id = %d AND user_id = %d AND can_edit = 1',
			Schema::table( 'project_members' ),
			$project_id,
			$user_id
		) );
	}

	/**
	 * User-IDs der Mit-Bearbeiter eines Projekts.
	 *
	 * @return int[]
	 */
	public static function editor_ids( int $project_id ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT user_id FROM %i WHERE project_id = %d AND can_edit = 1 ORDER BY id ASC',
			Schema::table( 'project_members' ),
			$project_id
		) );
		return array_map( 'intval', $ids ?: [] );
	}

	/**
	 * Mit-Bearbeiter-Recht setzen/entziehen — reiner Datenzugriff, die Rechte
	 * prüft {@see Projects::set_coeditor()}. Setzen ist ein Upsert über UNIQUE
	 * (project_id,user_id): eine bestehende Roster-Zeile (Rolle/Notiz) bleibt und
	 * bekommt nur den Haken. Entziehen nimmt den Haken; eine Zeile, die NUR für
	 * das Recht existierte (ohne Rolle/Notiz), verschwindet ganz.
	 *
	 * @return bool Ob sich etwas geändert hat.
	 */
	public static function set_editor( int $project_id, int $user_id, bool $on ): bool {
		global $wpdb;
		$table = Schema::table( 'project_members' );
		if ( $on ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-eigene Tabelle, atomarer Upsert.
			$rows = $wpdb->query( $wpdb->prepare(
				'INSERT INTO %i (project_id, user_id, role_title, note, can_edit, sort_order, created_at)
				 VALUES (%d, %d, %s, %s, 1, 0, %s)
				 ON DUPLICATE KEY UPDATE can_edit = 1',
				$table,
				$project_id,
				$user_id,
				'',
				'',
				current_time( 'mysql' )
			) );
			return (int) $rows > 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-eigene Tabelle.
		$gone = $wpdb->query( $wpdb->prepare(
			"DELETE FROM %i WHERE project_id = %d AND user_id = %d AND can_edit = 1 AND role_title = '' AND ( note IS NULL OR note = '' )",
			$table,
			$project_id,
			$user_id
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-eigene Tabelle.
		$off = $wpdb->query( $wpdb->prepare(
			'UPDATE %i SET can_edit = 0 WHERE project_id = %d AND user_id = %d AND can_edit = 1',
			$table,
			$project_id,
			$user_id
		) );
		return (int) $gone > 0 || (int) $off > 0;
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
