<?php
namespace ProjectPrepper;

use ProjectPrepper\Services\Groups;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Benutzer & Rechte (Superadmin-Steuerzentrale, docs/06).
 *
 * Betreiber-Sicht auf die WP-Benutzer: Rolle setzen, feingranulare pp-Caps pro
 * User überschreiben, Gruppen-Mitgliedschaften + letzter Login sehen. Pendant zum
 * `users-overview-tab` der Web-App, an die WP-Rollen/Caps angepasst.
 *
 * Sicherheit: Die REST-Routen sind auf `promote_users`/`edit_users` (Administrator)
 * begrenzt. Es lassen sich NUR die drei bekannten Rollen und die 12 pp-Caps setzen
 * (keine Core-Caps → keine Rechte-Eskalation über das Admin-Niveau hinaus). Guards
 * verhindern Selbst-Aussperren und das Entfernen des letzten Administrators.
 */
class Users {

	const META_LAST_LOGIN = 'pp_last_login';

	public static function init(): void {
		add_action( 'wp_login', [ self::class, 'record_login' ], 10, 2 );
		// Gelöschte Nutzer hinterließen bisher Mitgliedschaften, Stimmen und
		// Inventar-Freigaben. Folge: Einladungs-Votings und Beschlüsse warteten
		// dauerhaft auf jemanden, den es nicht mehr gibt.
		add_action( 'deleted_user', [ self::class, 'purge_plugin_data' ], 10, 1 );
	}

	public static function record_login( $user_login, $user = null ): void {
		$id = ( $user instanceof \WP_User ) ? (int) $user->ID : 0;
		if ( $id ) {
			update_user_meta( $id, self::META_LAST_LOGIN, current_time( 'mysql' ) );
		}
	}

	/** Die drei verwaltbaren Rollen (Schlüssel → Label). */
	/**
	 * Plugin-Daten eines gelöschten WordPress-Nutzers aufräumen.
	 *
	 * Bewusst NICHT gelöscht werden Vorgänge mit Belegcharakter (Verleihe,
	 * Projekte, Anfragen) — sie bleiben als Historie lesbar. Entfernt wird, was
	 * andere Mitglieder blockieren würde: Mitgliedschaften, Stimmen und die
	 * Freigaben seines Inventars (ohne Eigentümer könnte niemand mehr über eine
	 * Leihanfrage entscheiden).
	 */
	public static function purge_plugin_data( int $user_id ): void {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return;
		}
		$t = static fn( string $name ) => Schema::table( $name );

		// Betroffene offene Abstimmungen merken, BEVOR die Stimmen verschwinden.
		$invitations = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			'SELECT DISTINCT invitation_id FROM %i WHERE voter_id = %d',
			$t( 'group_invitation_votes' ),
			$user_id
		) ) );
		$decisions = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			'SELECT DISTINCT decision_id FROM %i WHERE user_id = %d',
			$t( 'project_decision_votes' ),
			$user_id
		) ) );

		$removed = [
			'group_members'          => (int) $wpdb->delete( $t( 'group_members' ), [ 'user_id' => $user_id ], [ '%d' ] ),
			'group_invitation_votes' => (int) $wpdb->delete( $t( 'group_invitation_votes' ), [ 'voter_id' => $user_id ], [ '%d' ] ),
			'project_decision_votes' => (int) $wpdb->delete( $t( 'project_decision_votes' ), [ 'user_id' => $user_id ], [ '%d' ] ),
			'project_poll_votes'     => (int) $wpdb->delete( $t( 'project_poll_votes' ), [ 'user_id' => $user_id ], [ '%d' ] ),
		];

		// Freigaben seines Inventars zurückziehen (die Artikel selbst bleiben).
		$item_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM %i WHERE owner_user_id = %d',
			$t( 'items' ),
			$user_id
		) ) );
		if ( $item_ids ) {
			$in                      = implode( ',', $item_ids );
			$removed['item_group_shares'] = (int) $wpdb->query( "DELETE FROM `{$t( 'item_group_shares' )}` WHERE item_id IN ({$in})" ); // phpcs:ignore WordPress.DB -- nur geprüfte Integer.
		}

		// Jetzt erneut auswerten: Ohne seine Stimme kann Einstimmigkeit erreicht sein.
		foreach ( $invitations as $invitation_id ) {
			Services\GroupGovernance::reresolve( $invitation_id );
		}
		foreach ( $decisions as $decision_id ) {
			Services\Decisions::reresolve( $decision_id );
		}

		Services\ActivityLog::log( 'user_data_purged', 'user', $user_id, array_filter( $removed ) );
	}

	public static function roles(): array {
		return [
			'administrator' => __( 'Administrator', 'project-prepper' ),
			'pp_manager'    => __( 'Prepper Manager', 'project-prepper' ),
			'pp_member'     => __( 'Prepper Member', 'project-prepper' ),
		];
	}

	/** Katalog der 12 pp-Caps (Schlüssel → Label) für die Permission-Matrix. */
	public static function caps_catalog(): array {
		return [
			Capabilities::VIEW_INVENTORY  => __( 'Inventory: view', 'project-prepper' ),
			Capabilities::EDIT_INVENTORY  => __( 'Inventory: edit', 'project-prepper' ),
			Capabilities::VIEW_PROJECTS   => __( 'Projects: view', 'project-prepper' ),
			Capabilities::EDIT_PROJECTS   => __( 'Projects: edit', 'project-prepper' ),
			Capabilities::VIEW_RENTALS    => __( 'Rentals: view', 'project-prepper' ),
			Capabilities::EDIT_RENTALS    => __( 'Rentals: edit', 'project-prepper' ),
			Capabilities::VIEW_INQUIRIES  => __( 'Inquiries: view', 'project-prepper' ),
			Capabilities::EDIT_INQUIRIES  => __( 'Inquiries: edit', 'project-prepper' ),
			Capabilities::IMPORT_EXPORT   => __( 'Import / export', 'project-prepper' ),
			Capabilities::MANAGE_GROUPS   => __( 'Manage groups', 'project-prepper' ),
			Capabilities::MANAGE_SETTINGS => __( 'Manage settings', 'project-prepper' ),
			Capabilities::COLLECTIVES     => __( 'Take part in groups', 'project-prepper' ),
		];
	}

	/** Gesamt-Payload für das Backend: Benutzer + Rollen-/Cap-Kataloge. */
	public static function list_payload(): array {
		$users = get_users( [
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => 500,
		] );
		$rows = [];
		foreach ( $users as $user ) {
			$rows[] = self::user_row( $user );
		}
		return [
			'users'     => $rows,
			'roles'     => self::roles(),
			'caps'      => self::caps_catalog(),
			'role_caps' => self::role_caps(),
		];
	}

	/** Default-Caps je verwaltbarer Rolle — damit das UI die Checkboxen beim
	 *  Rollenwechsel auf die richtigen Vorgaben setzt (verhindert Stale-Overrides). */
	private static function role_caps(): array {
		$out = [];
		foreach ( array_keys( self::roles() ) as $role ) {
			$obj  = get_role( $role );
			$caps = [];
			foreach ( array_keys( self::caps_catalog() ) as $cap ) {
				$caps[ $cap ] = ( 'administrator' === $role ) || ( $obj && ! empty( $obj->capabilities[ $cap ] ) );
			}
			$out[ $role ] = $caps;
		}
		return $out;
	}

	private static function user_row( \WP_User $user ): array {
		$known     = array_keys( self::roles() );
		$role      = '';
		foreach ( (array) $user->roles as $r ) {
			if ( in_array( $r, $known, true ) ) {
				$role = $r;
				break;
			}
		}
		if ( '' === $role ) {
			$role = (string) ( $user->roles[0] ?? '' );
		}

		$caps = [];
		foreach ( array_keys( self::caps_catalog() ) as $cap ) {
			$caps[ $cap ] = user_can( $user, $cap );
		}

		$groups = array_map(
			static function ( $g ) {
				return [ 'name' => $g->name, 'role' => $g->member_role ];
			},
			Groups::user_groups( (int) $user->ID )
		);

		$last = get_user_meta( (int) $user->ID, self::META_LAST_LOGIN, true );

		return [
			'id'           => (int) $user->ID,
			'name'         => $user->display_name,
			'email'        => $user->user_email,
			'role'         => $role,
			'caps'         => $caps,
			'groups'       => $groups,
			'registered'   => $user->user_registered ? mysql2date( 'd.m.Y', $user->user_registered ) : '',
			'last_login'   => $last ? mysql2date( 'd.m.Y H:i', (string) $last ) : '',
			'is_self'      => (int) $user->ID === get_current_user_id(),
			'is_admin'     => in_array( 'administrator', (array) $user->roles, true ),
			'impersonate_url' => Impersonation::start_url( (int) $user->ID ),
		];
	}

	/**
	 * Rolle und/oder pro-User-Cap-Overrides setzen. Nur die drei bekannten Rollen
	 * und die 12 pp-Caps; Guards gegen Selbst-Aussperren + letzten Administrator.
	 *
	 * @param array $data ['role' => string|null, 'caps' => array<string,bool>|null]
	 * @return true|WP_Error
	 */
	public static function update( int $user_id, array $data ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'pp_not_found', __( 'User not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$is_self = ( $user_id === get_current_user_id() );

		// 1) Rolle (optional).
		if ( array_key_exists( 'role', $data ) && null !== $data['role'] && '' !== $data['role'] ) {
			$role = (string) $data['role'];
			if ( ! array_key_exists( $role, self::roles() ) ) {
				return new WP_Error( 'pp_bad_role', __( 'Unknown role.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			if ( $is_self ) {
				return new WP_Error( 'pp_self_role', __( 'You cannot change your own role.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			// Letzten Administrator nicht herabstufen.
			if ( in_array( 'administrator', (array) $user->roles, true ) && 'administrator' !== $role
				&& self::admin_count() <= 1 ) {
				return new WP_Error( 'pp_last_admin', __( 'This is the last administrator and cannot be demoted.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			if ( ! in_array( $role, (array) $user->roles, true ) ) {
				$user->set_role( $role ); // ersetzt Rolle + setzt User-Level-Caps zurück.
				$user = get_user_by( 'id', $user_id ); // frisch laden.
			}
		}

		// 2) Per-User-Cap-Overrides (nur die 12 pp-Caps). Minimal halten: nur abweichend
		//    vom Rollen-Default als User-Override speichern.
		if ( array_key_exists( 'caps', $data ) && is_array( $data['caps'] ) ) {
			$role_obj = get_role( (string) ( $user->roles[0] ?? '' ) );
			foreach ( array_keys( self::caps_catalog() ) as $cap ) {
				if ( ! array_key_exists( $cap, $data['caps'] ) ) {
					continue;
				}
				$desired   = (bool) $data['caps'][ $cap ];
				$role_has  = $role_obj && ! empty( $role_obj->capabilities[ $cap ] );
				if ( $desired === $role_has ) {
					$user->remove_cap( $cap ); // kein Override nötig → Rollen-Default.
				} else {
					$user->add_cap( $cap, $desired );
				}
			}
		}

		return true;
	}

	private static function admin_count(): int {
		$q = new \WP_User_Query( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 0, 'count_total' => true ] );
		return (int) $q->get_total();
	}
}
