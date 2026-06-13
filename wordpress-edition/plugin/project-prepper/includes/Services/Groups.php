<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Gruppen-Overlay (v0.10.0, Phase 1) — Pendant zum Gruppenmodell der App,
 * stark vereinfacht: Gruppenmitglieder sind WP-Benutzer der Site, ein Projekt
 * gehört OPTIONAL einer Gruppe (sonst Site-Ebene wie bisher).
 *
 * Diese Klasse ist zugleich der zentrale Zugriffs-Helper (RLS-Ersatz):
 * `user_can_access_project()` / `is_member()` / `user_group_ids()` werden von
 * Projects + REST genutzt. Jede Query/Route MUSS selbst prüfen — es gibt kein
 * RLS-Netz wie in Supabase.
 *
 * Siehe docs/03-GRUPPEN-ARCHITEKTUR.md.
 */
class Groups {

	const ROLES = [ 'founder', 'member' ];

	/* ===================== Lesen ===================== */

	/**
	 * Alle Gruppen (für Admin/Manager) inkl. member_count. Sortiert nach Name.
	 *
	 * @return array<object>
	 */
	public static function all(): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT g.*, (SELECT COUNT(*) FROM %i gm WHERE gm.group_id = g.id) AS member_count
			 FROM %i g
			 ORDER BY g.name ASC, g.id ASC',
			Schema::table( 'group_members' ),
			Schema::table( 'groups' )
		) ) ?: [];
	}

	/**
	 * Eine Gruppe inkl. ->members (user_id, member_role, display_name, user_email).
	 */
	public static function get( int $id ): ?object {
		global $wpdb;
		$group = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'groups' ),
			$id
		) );
		if ( ! $group ) {
			return null;
		}
		$group->members = self::members( $id );
		return $group;
	}

	/**
	 * Mitglieder einer Gruppe, mit aufgelösten WP-User-Daten.
	 *
	 * @return array<object>
	 */
	public static function members( int $group_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT user_id, member_role, joined_at FROM %i WHERE group_id = %d
			 ORDER BY (member_role = %s) DESC, joined_at ASC, id ASC',
			Schema::table( 'group_members' ),
			$group_id,
			'founder'
		) ) ?: [];

		foreach ( $rows as $row ) {
			$user                = get_userdata( (int) $row->user_id );
			$row->user_id        = (int) $row->user_id;
			$row->display_name   = $user ? $user->display_name : sprintf( '#%d', (int) $row->user_id );
			$row->user_email     = $user ? $user->user_email : '';
		}
		return $rows;
	}

	/* ===================== Schreiben ===================== */

	/**
	 * @return int|WP_Error  Neue Gruppen-ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$name = isset( $data['name'] ) ? trim( (string) $data['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'pp_missing_name', __( 'Group name is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$now     = current_time( 'mysql' );
		$creator = get_current_user_id() ?: null;
		$wpdb->insert( Schema::table( 'groups' ), [
			'name'        => $name,
			'slug'        => self::unique_slug( $name ),
			'description' => isset( $data['description'] ) ? (string) $data['description'] : '',
			'created_by'  => $creator,
			'created_at'  => $now,
		] );
		$group_id = (int) $wpdb->insert_id;

		// Ersteller automatisch als founder eintragen (sonst verwaiste Gruppe).
		if ( $creator ) {
			$wpdb->insert( Schema::table( 'group_members' ), [
				'group_id'    => $group_id,
				'user_id'     => $creator,
				'member_role' => 'founder',
				'joined_at'   => $now,
			] );
		}

		ActivityLog::log( 'group_created', 'group', $group_id, [ 'name' => $name ] );
		return $group_id;
	}

	/**
	 * Name/Beschreibung partiell ändern (slug bleibt stabil).
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$group = self::get( $id );
		if ( ! $group ) {
			return new WP_Error( 'pp_not_found', __( 'Group not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		$fields = [];
		if ( array_key_exists( 'name', $data ) ) {
			$name = trim( (string) $data['name'] );
			if ( '' === $name ) {
				return new WP_Error( 'pp_missing_name', __( 'Group name is required.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['name'] = $name;
		}
		if ( array_key_exists( 'description', $data ) ) {
			$fields['description'] = (string) $data['description'];
		}
		if ( $fields ) {
			$wpdb->update( Schema::table( 'groups' ), $fields, [ 'id' => $id ] );
			ActivityLog::log( 'group_updated', 'group', $id, [ 'fields' => array_keys( $fields ) ] );
		}
		return true;
	}

	/**
	 * Gruppe + Mitgliedschaften löschen. Projekte dieser Gruppe werden NICHT
	 * gelöscht, sondern auf owner_group_id=NULL zurückgesetzt (= Site-Ebene).
	 *
	 * @return true|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;
		$group = self::get( $id );
		if ( ! $group ) {
			return new WP_Error( 'pp_not_found', __( 'Group not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		// Projekte der Gruppe auf Site-Ebene zurückfallen lassen (nicht löschen!).
		$wpdb->update(
			Schema::table( 'projects' ),
			[ 'owner_group_id' => null ],
			[ 'owner_group_id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);

		$wpdb->delete( Schema::table( 'group_members' ), [ 'group_id' => $id ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'groups' ), [ 'id' => $id ], [ '%d' ] );

		ActivityLog::log( 'group_deleted', 'group', $id, [ 'name' => $group->name ] );
		return true;
	}

	/**
	 * Mitglied hinzufügen. Idempotent (Doppel-Mitgliedschaft aktualisiert nur
	 * die Rolle). Validiert: Gruppe existiert, $user_id ist ein echter WP-User.
	 *
	 * @return true|WP_Error
	 */
	public static function add_member( int $group_id, int $user_id, string $role = 'member' ) {
		global $wpdb;

		if ( ! self::get( $group_id ) ) {
			return new WP_Error( 'pp_not_found', __( 'Group not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'pp_invalid_user', __( 'Unknown user.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $role, self::ROLES, true ) ) {
			$role = 'member';
		}

		$existing = $wpdb->get_row( $wpdb->prepare(
			'SELECT id FROM %i WHERE group_id = %d AND user_id = %d',
			Schema::table( 'group_members' ),
			$group_id,
			$user_id
		) );
		if ( $existing ) {
			// Idempotent: nur die Rolle angleichen.
			$wpdb->update( Schema::table( 'group_members' ), [ 'member_role' => $role ], [ 'id' => (int) $existing->id ] );
			return true;
		}

		$wpdb->insert( Schema::table( 'group_members' ), [
			'group_id'    => $group_id,
			'user_id'     => $user_id,
			'member_role' => $role,
			'joined_at'   => current_time( 'mysql' ),
		] );

		ActivityLog::log( 'group_member_added', 'group', $group_id, [ 'user_id' => $user_id, 'role' => $role ] );
		return true;
	}

	/**
	 * Mitglied entfernen. Der LETZTE founder ist nicht entfernbar (sonst
	 * verwaiste Gruppe ohne Verantwortlichen).
	 *
	 * @return true|WP_Error
	 */
	public static function remove_member( int $group_id, int $user_id ) {
		global $wpdb;

		if ( ! self::get( $group_id ) ) {
			return new WP_Error( 'pp_not_found', __( 'Group not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$member = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, member_role FROM %i WHERE group_id = %d AND user_id = %d',
			Schema::table( 'group_members' ),
			$group_id,
			$user_id
		) );
		if ( ! $member ) {
			return new WP_Error( 'pp_not_found', __( 'Member not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		if ( 'founder' === $member->member_role ) {
			$founders = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE group_id = %d AND member_role = %s',
				Schema::table( 'group_members' ),
				$group_id,
				'founder'
			) );
			if ( $founders <= 1 ) {
				return new WP_Error( 'pp_last_founder', __( 'The last founder cannot be removed.', 'project-prepper' ), [ 'status' => 400 ] );
			}
		}

		$wpdb->delete( Schema::table( 'group_members' ), [ 'id' => (int) $member->id ], [ '%d' ] );
		ActivityLog::log( 'group_member_removed', 'group', $group_id, [ 'user_id' => $user_id ] );
		return true;
	}

	/* ===================== Zugriffs-Helper (Kernstück) ===================== */

	/**
	 * Admin-Signal für die Gruppen-Zugriffslogik: wer Gruppen verwalten darf
	 * (Administrator + Prepper Manager), sieht/bearbeitet ALLE Projekte —
	 * unabhängig von Gruppen-Mitgliedschaft. Bewusst an `pp_groups_manage`
	 * gekoppelt (nicht an manage_options), damit es konsistent mit den
	 * REST-permission_callbacks der Gruppen-Routen ist.
	 */
	public static function user_is_admin( int $user_id ): bool {
		return user_can( $user_id, Capabilities::MANAGE_GROUPS );
	}

	public static function is_member( int $group_id, int $user_id ): bool {
		global $wpdb;
		if ( ! $group_id || ! $user_id ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM %i WHERE group_id = %d AND user_id = %d',
			Schema::table( 'group_members' ),
			$group_id,
			$user_id
		) );
	}

	/**
	 * Gruppen des Users inkl. Name, Slug und eigener Rolle — für das
	 * Member-Portal (eine Query, ohne die Mitglieder-Auflösung von get()).
	 *
	 * @return array<object> Zeilen {id, name, slug, member_role}.
	 */
	public static function user_groups( int $user_id ): array {
		global $wpdb;
		if ( ! $user_id ) {
			return [];
		}
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT g.id, g.name, g.slug, gm.member_role
			 FROM %i gm JOIN %i g ON g.id = gm.group_id
			 WHERE gm.user_id = %d
			 ORDER BY (gm.member_role = %s) DESC, g.name ASC',
			Schema::table( 'group_members' ),
			Schema::table( 'groups' ),
			$user_id,
			'founder'
		) ) ?: [];
	}

	/**
	 * Gruppen-IDs, in denen der User Mitglied ist.
	 *
	 * @return int[]
	 */
	public static function user_group_ids( int $user_id ): array {
		global $wpdb;
		if ( ! $user_id ) {
			return [];
		}
		$ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT group_id FROM %i WHERE user_id = %d',
			Schema::table( 'group_members' ),
			$user_id
		) );
		return array_map( 'intval', $ids ?: [] );
	}

	/**
	 * Darf $user_id auf ein Projekt zugreifen?
	 *
	 * true wenn: Admin (siehe user_is_admin) ODER Projekt ist Site-Ebene
	 * (owner_group_id IS NULL → wie bisher Cap-gesteuert) ODER User ist Mitglied
	 * der besitzenden Gruppe.
	 *
	 * Achtung: prüft NUR die Gruppen-Dimension. Die Grund-Capability
	 * (pp_projects_view/_edit) gilt zusätzlich und wird in den REST-Routen
	 * separat erzwungen.
	 *
	 * @param object|int $project Projekt-Objekt (mit ->owner_group_id) oder ID.
	 */
	public static function user_can_access_project( $project, int $user_id ): bool {
		if ( self::user_is_admin( $user_id ) ) {
			return true;
		}
		if ( ! is_object( $project ) ) {
			global $wpdb;
			$project = $wpdb->get_row( $wpdb->prepare(
				'SELECT owner_group_id FROM %i WHERE id = %d',
				Schema::table( 'projects' ),
				(int) $project
			) );
			if ( ! $project ) {
				return false;
			}
		}
		$group_id = isset( $project->owner_group_id ) ? (int) $project->owner_group_id : 0;
		if ( ! $group_id ) {
			return true; // Site-Ebene: Cap-gesteuert (wie bisher).
		}
		return self::is_member( $group_id, $user_id );
	}

	/* ===================== Intern ===================== */

	/**
	 * Eindeutigen Slug aus dem Namen bilden (sanitize_title + Suffix bei Kollision).
	 */
	private static function unique_slug( string $name ): string {
		global $wpdb;
		$base = sanitize_title( $name );
		if ( '' === $base ) {
			$base = 'group';
		}
		$slug   = $base;
		$suffix = 2;
		$table  = Schema::table( 'groups' );
		while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug = %s', $table, $slug ) ) ) {
			$slug = $base . '-' . $suffix;
			$suffix++;
		}
		return $slug;
	}
}
