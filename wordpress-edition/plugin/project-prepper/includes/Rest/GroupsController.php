<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\Groups;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Gruppen-Overlay (v0.10.0, Phase 1): CRUD + Mitglieder-Verwaltung + ein
 * minimaler User-Picker für die Admin-UI.
 *
 * permission_callback (RLS-Ersatz):
 * - Verwaltung (POST/PUT/DELETE, Mitglieder, site-users) verlangt
 *   `pp_groups_manage` (Administrator + Prepper Manager).
 * - Lesen (GET /groups, GET /groups/{id}) erlaubt zusätzlich Mitglieder, damit
 *   die Gruppen-Auswahl im Projekt für normale Mitglieder funktioniert.
 *
 * Mutationen liefern bewusst kompakte Antworten — die Admin-UI lädt danach neu.
 */
class GroupsController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/groups', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'index' ],
				'permission_callback' => [ $this, 'can_read' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create' ],
				'permission_callback' => $this->require_cap( Capabilities::MANAGE_GROUPS ),
			],
		] );

		// User-Picker: WP-User der Site (für „Mitglied hinzufügen"). Eigener
		// Endpoint statt /wp/v2/users, um nicht von dessen Caps abzuhängen.
		register_rest_route( self::REST_NAMESPACE, '/groups/site-users', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'site_users' ],
			'permission_callback' => $this->require_cap( Capabilities::MANAGE_GROUPS ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/groups/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'show' ],
				'permission_callback' => [ $this, 'can_read' ],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update' ],
				'permission_callback' => $this->require_cap( Capabilities::MANAGE_GROUPS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete' ],
				'permission_callback' => $this->require_cap( Capabilities::MANAGE_GROUPS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/groups/(?P<id>\d+)/members', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'add_member' ],
			'permission_callback' => $this->require_cap( Capabilities::MANAGE_GROUPS ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/groups/(?P<id>\d+)/members/(?P<user_id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'remove_member' ],
			'permission_callback' => $this->require_cap( Capabilities::MANAGE_GROUPS ),
		] );
	}

	/**
	 * Lesen erlaubt für Gruppen-Verwalter ODER aktive Gruppenmitglieder
	 * (damit die Projekt-Gruppen-Auswahl auch für Member funktioniert).
	 */
	public function can_read(): bool {
		if ( current_user_can( Capabilities::MANAGE_GROUPS ) ) {
			return true;
		}
		return (bool) Groups::user_group_ids( get_current_user_id() );
	}

	public function index(): WP_REST_Response {
		// Verwalter sehen alle Gruppen; Mitglieder nur ihre eigenen.
		if ( current_user_can( Capabilities::MANAGE_GROUPS ) ) {
			return new WP_REST_Response( Groups::all() );
		}
		$mine = array_map( static function ( $id ) {
			return Groups::get( (int) $id );
		}, Groups::user_group_ids( get_current_user_id() ) );
		return new WP_REST_Response( array_values( array_filter( $mine ) ) );
	}

	public function show( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		// Mitglieder dürfen nur die eigenen Gruppen lesen.
		if ( ! current_user_can( Capabilities::MANAGE_GROUPS ) && ! Groups::is_member( $id, get_current_user_id() ) ) {
			return new WP_Error( 'pp_not_found', __( 'Group not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$group = Groups::get( $id );
		if ( ! $group ) {
			return new WP_Error( 'pp_not_found', __( 'Group not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( $group );
	}

	public function create( WP_REST_Request $request ) {
		$result = Groups::create( $this->group_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Groups::get( $result ), 201 );
	}

	public function update( WP_REST_Request $request ) {
		$result = Groups::update( (int) $request['id'], $this->group_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Groups::get( (int) $request['id'] ) );
	}

	public function delete( WP_REST_Request $request ) {
		$result = Groups::delete( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	public function add_member( WP_REST_Request $request ) {
		$json    = $request->get_json_params() ?: [];
		$user_id = (int) ( $json['user_id'] ?? 0 );
		$role    = sanitize_text_field( (string) ( $json['role'] ?? 'member' ) );
		$result  = Groups::add_member( (int) $request['id'], $user_id, $role );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Groups::get( (int) $request['id'] ) );
	}

	public function remove_member( WP_REST_Request $request ) {
		$result = Groups::remove_member( (int) $request['id'], (int) $request['user_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Groups::get( (int) $request['id'] ) );
	}

	/**
	 * WP-User der Site als {id, display_name, email} (für den Mitglieder-Picker).
	 */
	public function site_users(): WP_REST_Response {
		$users = get_users( [
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => 200,
			'fields'  => [ 'ID', 'display_name', 'user_email' ],
		] );
		$out = [];
		foreach ( $users as $user ) {
			$out[] = [
				'id'           => (int) $user->ID,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
			];
		}
		return new WP_REST_Response( $out );
	}

	private function group_payload( array $json ): array {
		$data = $this->sanitize_text_fields( $json, [ 'name' ] );
		if ( array_key_exists( 'description', $json ) ) {
			$data['description'] = sanitize_textarea_field( (string) $json['description'] );
		}
		return $data;
	}
}
