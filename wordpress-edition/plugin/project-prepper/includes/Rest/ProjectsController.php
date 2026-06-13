<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\Agreements;
use ProjectPrepper\Services\Checklists;
use ProjectPrepper\Services\Consumables;
use ProjectPrepper\Services\Contacts;
use ProjectPrepper\Services\Costs;
use ProjectPrepper\Services\Decisions;
use ProjectPrepper\Services\Files;
use ProjectPrepper\Services\Polls;
use ProjectPrepper\Services\ProfitShares;
use ProjectPrepper\Services\ProjectMembers;
use ProjectPrepper\Services\Projects;
use ProjectPrepper\Services\Schedule;
use ProjectPrepper\Services\Tasks;
use ProjectPrepper\Services\Team;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Projekte: CRUD + Status + Buchungszeilen + Checklisten + Aufgaben.
 *
 * Mutationen liefern bewusst kompakte Antworten — das Admin-UI lädt danach
 * das Projekt per GET /projects/{id} neu (eine Quelle der Wahrheit).
 */
class ProjectsController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/projects', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'index' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'show' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/status', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'set_status' ],
			'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/items', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'items' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_item' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/items/(?P<line_id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_item' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/schedule', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'schedule' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_schedule' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/schedule/(?P<entry_id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_schedule' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_schedule' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/costs', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'costs' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_cost' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/costs/(?P<cost_id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_cost' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_cost' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/consumables', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'consumables' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_consumable' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/consumables/(?P<cid>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_consumable' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_consumable' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/team', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'team' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_team' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/team/(?P<tid>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_team' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_team' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/contacts', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'contacts' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_contact' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/contacts/(?P<cid>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_contact' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_contact' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/files', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'files' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_file' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/files/(?P<file_id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_file' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_file' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/members', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'members' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_member' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/members/(?P<member_id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_member' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_member' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/decisions', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'decisions' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_decision' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/decisions/(?P<decision_id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_decision' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_decision' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		// Abstimmen erfordert NUR die View-Cap (abstimmen ist kein Bearbeiten);
		// die Gruppenmitgliedschaft wird im Service (cast_vote) erzwungen.
		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/decisions/(?P<decision_id>\d+)/vote', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'vote_decision' ],
			'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
		] );

		// Vorzeitiges Schließen: View-Cap genügt; Ersteller/Admin-Check im Service.
		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/decisions/(?P<decision_id>\d+)/cancel', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'cancel_decision' ],
			'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/polls', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'polls' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_poll' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/polls/(?P<poll_id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_poll' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_poll' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
			],
		] );

		// Abstimmen erfordert NUR die View-Cap (abstimmen ist kein Bearbeiten);
		// die Gruppenmitgliedschaft wird im Service (cast_vote) erzwungen.
		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/polls/(?P<poll_id>\d+)/vote', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'vote_poll' ],
			'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
		] );

		// Schließen/Öffnen: View-Cap genügt; Ersteller/Admin-Check im Service.
		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/polls/(?P<poll_id>\d+)/close', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'close_poll' ],
			'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
		] );
		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/polls/(?P<poll_id>\d+)/reopen', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'reopen_poll' ],
			'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/profit-shares', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'profit_shares' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_profit_share' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/profit-shares/(?P<share_id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_profit_share' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_profit_share' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		// Kooperationsvereinbarung (Gruppen-Phase 5). Es gibt nur EINE pro Projekt
		// → kein {agreement_id} in der Route; der Service löst project→agreement auf.
		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/agreement', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'agreement' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_agreement' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_agreement' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_agreement' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/agreement/open', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'open_agreement' ],
			'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
		] );

		// Unterschreiben/Ablehnen erfordert NUR die View-Cap (signieren ist kein
		// Bearbeiten); die Gruppenmitgliedschaft wird im Service erzwungen.
		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/agreement/sign', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'sign_agreement' ],
			'permission_callback' => $this->require_cap( Capabilities::VIEW_PROJECTS ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/agreement/revise', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'revise_agreement' ],
			'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/agreement/terminate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'terminate_agreement' ],
			'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/checklists', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_checklist' ],
			'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/checklists/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_checklist' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_checklist' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/checklists/(?P<id>\d+)/items', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'add_checklist_item' ],
			'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/checklist-items/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_checklist_item' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_checklist_item' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/projects/(?P<id>\d+)/tasks', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_task' ],
			'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/tasks/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_task' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_task' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_PROJECTS ),
			],
		] );
	}

	/* ---------- Projekte ---------- */

	public function index( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( Projects::all( [
			'status' => sanitize_text_field( (string) $request->get_param( 'status' ) ),
		] ) );
	}

	public function show( WP_REST_Request $request ) {
		$project = Projects::get( (int) $request['id'] );
		if ( ! $project ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( $project );
	}

	public function create( WP_REST_Request $request ) {
		$result = Projects::create( $this->project_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Projects::get( $result ), 201 );
	}

	public function update( WP_REST_Request $request ) {
		$result = Projects::update( (int) $request['id'], $this->project_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Projects::get( (int) $request['id'] ) );
	}

	public function set_status( WP_REST_Request $request ) {
		$status = sanitize_text_field( (string) ( $request->get_json_params()['status'] ?? '' ) );
		$result = Projects::set_status( (int) $request['id'], $status );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Projects::get( (int) $request['id'] ) );
	}

	public function delete( WP_REST_Request $request ) {
		Projects::delete( (int) $request['id'] );
		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	/**
	 * Header-Felder sanitizen — nur übergebene Keys weiterreichen (Service-Diff).
	 */
	private function project_payload( array $json ): array {
		$data = $this->sanitize_text_fields( $json, [
			'name', 'status', 'date_start', 'date_end', 'venue_name', 'client_name', 'client_phone',
		] );
		if ( array_key_exists( 'client_email', $json ) ) {
			$data['client_email'] = sanitize_email( (string) $json['client_email'] );
		}
		foreach ( [ 'venue_address', 'notes' ] as $key ) {
			if ( array_key_exists( $key, $json ) ) {
				$data[ $key ] = sanitize_textarea_field( (string) $json[ $key ] );
			}
		}
		foreach ( [ 'budget_planned', 'revenue_actual' ] as $key ) {
			if ( array_key_exists( $key, $json ) ) {
				// Roh-Wert durchreichen ('' → NULL); der Service validiert die Zahl.
				$data[ $key ] = is_scalar( $json[ $key ] ) ? (string) $json[ $key ] : '';
			}
		}
		// Gruppen-Zuordnung (v0.10.0) — leer/0 → NULL, sonst Gruppen-ID;
		// der Service prüft Existenz + Mitgliedschaft/Admin-Recht.
		if ( array_key_exists( 'owner_group_id', $json ) ) {
			$data['owner_group_id'] = is_scalar( $json['owner_group_id'] ) ? (int) $json['owner_group_id'] : 0;
		}
		return $data;
	}

	/* ---------- Buchungszeilen ---------- */

	public function items( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( Projects::items_for( (int) $request['id'] ) );
	}

	public function add_item( WP_REST_Request $request ) {
		$result = Projects::add_item( (int) $request['id'], $this->line_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Projects::items_for( (int) $request['id'] ), 201 );
	}

	public function update_item( WP_REST_Request $request ) {
		$result = Projects::update_item( (int) $request['id'], (int) $request['line_id'], $this->line_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Projects::items_for( (int) $request['id'] ) );
	}

	public function remove_item( WP_REST_Request $request ) {
		$result = Projects::remove_item( (int) $request['id'], (int) $request['line_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Projects::items_for( (int) $request['id'] ) );
	}

	private function line_payload( array $json ): array {
		$line = [];
		foreach ( [ 'item_id', 'quantity' ] as $key ) {
			if ( array_key_exists( $key, $json ) ) {
				$line[ $key ] = (int) $json[ $key ];
			}
		}
		foreach ( [ 'date_from', 'date_to' ] as $key ) {
			if ( array_key_exists( $key, $json ) ) {
				$line[ $key ] = sanitize_text_field( (string) $json[ $key ] );
			}
		}
		if ( array_key_exists( 'notes', $json ) ) {
			$line['notes'] = sanitize_textarea_field( (string) $json['notes'] );
		}
		return $line;
	}

	/* ---------- Zeitplan ---------- */

	public function schedule( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( Schedule::for_project( (int) $request['id'] ) );
	}

	public function add_schedule( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$result = Schedule::create( (int) $request['id'], $this->schedule_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Schedule::for_project( (int) $request['id'] ), 201 );
	}

	public function update_schedule( WP_REST_Request $request ) {
		$owner = $this->schedule_entry_in_project( (int) $request['id'], (int) $request['entry_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Schedule::update( (int) $request['entry_id'], $this->schedule_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Schedule::for_project( (int) $request['id'] ) );
	}

	public function remove_schedule( WP_REST_Request $request ) {
		$owner = $this->schedule_entry_in_project( (int) $request['id'], (int) $request['entry_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Schedule::delete( (int) $request['entry_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Schedule::for_project( (int) $request['id'] ) );
	}

	/**
	 * Sicherstellen, dass der Eintrag zum Projekt der URL gehört (nested route).
	 *
	 * @return true|WP_Error
	 */
	private function schedule_entry_in_project( int $project_id, int $entry_id ) {
		$entry = Schedule::get( $entry_id );
		if ( ! $entry || (int) $entry->project_id !== $project_id ) {
			return new WP_Error( 'pp_not_found', __( 'Schedule entry not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return true;
	}

	private function schedule_payload( array $json ): array {
		$data = $this->sanitize_text_fields( $json, [ 'title', 'schedule_date', 'time_start', 'time_end', 'location' ] );
		if ( array_key_exists( 'notes', $json ) ) {
			$data['notes'] = sanitize_textarea_field( (string) $json['notes'] );
		}
		if ( array_key_exists( 'sort_order', $json ) ) {
			$data['sort_order'] = (int) $json['sort_order'];
		}
		return $data;
	}

	/* ---------- Kosten ---------- */

	public function costs( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( [
			'cost_items'   => Costs::for_project( (int) $request['id'] ),
			'cost_summary' => Costs::summary( (int) $request['id'] ),
		] );
	}

	public function add_cost( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$result = Costs::create( (int) $request['id'], $this->cost_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Projects::get( (int) $request['id'] ), 201 );
	}

	public function update_cost( WP_REST_Request $request ) {
		$owner = $this->cost_item_in_project( (int) $request['id'], (int) $request['cost_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Costs::update( (int) $request['cost_id'], $this->cost_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Projects::get( (int) $request['id'] ) );
	}

	public function remove_cost( WP_REST_Request $request ) {
		$owner = $this->cost_item_in_project( (int) $request['id'], (int) $request['cost_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Costs::delete( (int) $request['cost_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Projects::get( (int) $request['id'] ) );
	}

	/**
	 * Sicherstellen, dass der Kostenposten zum Projekt der URL gehört (nested route).
	 *
	 * @return true|WP_Error
	 */
	private function cost_item_in_project( int $project_id, int $cost_id ) {
		$entry = Costs::get( $cost_id );
		if ( ! $entry || (int) $entry->project_id !== $project_id ) {
			return new WP_Error( 'pp_not_found', __( 'Cost item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return true;
	}

	private function cost_payload( array $json ): array {
		$data = $this->sanitize_text_fields( $json, [ 'category' ] );
		if ( array_key_exists( 'description', $json ) ) {
			$data['description'] = sanitize_text_field( (string) $json['description'] );
		}
		foreach ( [ 'amount_planned', 'amount_actual', 'vat_rate' ] as $key ) {
			if ( array_key_exists( $key, $json ) ) {
				// Roh-Wert durchreichen ('' / null erlaubt); der Service validiert die Zahl.
				$data[ $key ] = is_scalar( $json[ $key ] ) ? (string) $json[ $key ] : '';
			}
		}
		if ( array_key_exists( 'exclude_from_profit', $json ) ) {
			$data['exclude_from_profit'] = ! empty( $json['exclude_from_profit'] );
		}
		if ( array_key_exists( 'sort_order', $json ) ) {
			$data['sort_order'] = (int) $json['sort_order'];
		}
		return $data;
	}

	/* ---------- Verbrauchsmaterial ---------- */

	public function consumables( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( Consumables::for_project( (int) $request['id'] ) );
	}

	public function add_consumable( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$result = Consumables::create( (int) $request['id'], $this->consumable_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Consumables::for_project( (int) $request['id'] ), 201 );
	}

	public function update_consumable( WP_REST_Request $request ) {
		$owner = $this->consumable_in_project( (int) $request['id'], (int) $request['cid'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Consumables::update( (int) $request['cid'], $this->consumable_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Consumables::for_project( (int) $request['id'] ) );
	}

	public function remove_consumable( WP_REST_Request $request ) {
		$owner = $this->consumable_in_project( (int) $request['id'], (int) $request['cid'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Consumables::delete( (int) $request['cid'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Consumables::for_project( (int) $request['id'] ) );
	}

	/**
	 * @return true|WP_Error
	 */
	private function consumable_in_project( int $project_id, int $consumable_id ) {
		$entry = Consumables::get( $consumable_id );
		if ( ! $entry || (int) $entry->project_id !== $project_id ) {
			return new WP_Error( 'pp_not_found', __( 'Consumable not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return true;
	}

	private function consumable_payload( array $json ): array {
		$data = $this->sanitize_text_fields( $json, [ 'name', 'unit' ] );
		foreach ( [ 'quantity', 'cost' ] as $key ) {
			if ( array_key_exists( $key, $json ) ) {
				// Roh-Wert durchreichen ('' / null erlaubt); der Service validiert die Zahl.
				$data[ $key ] = is_scalar( $json[ $key ] ) ? (string) $json[ $key ] : '';
			}
		}
		if ( array_key_exists( 'sort_order', $json ) ) {
			$data['sort_order'] = (int) $json['sort_order'];
		}
		return $data;
	}

	/* ---------- Team-Mitglieder ---------- */

	public function team( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( Team::for_project( (int) $request['id'] ) );
	}

	public function add_team( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$result = Team::create( (int) $request['id'], $this->team_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Team::for_project( (int) $request['id'] ), 201 );
	}

	public function update_team( WP_REST_Request $request ) {
		$owner = $this->team_member_in_project( (int) $request['id'], (int) $request['tid'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Team::update( (int) $request['tid'], $this->team_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Team::for_project( (int) $request['id'] ) );
	}

	public function remove_team( WP_REST_Request $request ) {
		$owner = $this->team_member_in_project( (int) $request['id'], (int) $request['tid'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Team::delete( (int) $request['tid'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Team::for_project( (int) $request['id'] ) );
	}

	/**
	 * @return true|WP_Error
	 */
	private function team_member_in_project( int $project_id, int $member_id ) {
		$entry = Team::get( $member_id );
		if ( ! $entry || (int) $entry->project_id !== $project_id ) {
			return new WP_Error( 'pp_not_found', __( 'Team member not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return true;
	}

	private function team_payload( array $json ): array {
		$data = $this->sanitize_text_fields( $json, [ 'name', 'role', 'department' ] );
		if ( array_key_exists( 'sort_order', $json ) ) {
			$data['sort_order'] = (int) $json['sort_order'];
		}
		return $data;
	}

	/* ---------- Kontakte ---------- */

	public function contacts( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( Contacts::for_project( (int) $request['id'] ) );
	}

	public function add_contact( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$result = Contacts::create( (int) $request['id'], $this->contact_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Contacts::for_project( (int) $request['id'] ), 201 );
	}

	public function update_contact( WP_REST_Request $request ) {
		$owner = $this->contact_in_project( (int) $request['id'], (int) $request['cid'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Contacts::update( (int) $request['cid'], $this->contact_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Contacts::for_project( (int) $request['id'] ) );
	}

	public function remove_contact( WP_REST_Request $request ) {
		$owner = $this->contact_in_project( (int) $request['id'], (int) $request['cid'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Contacts::delete( (int) $request['cid'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Contacts::for_project( (int) $request['id'] ) );
	}

	/**
	 * @return true|WP_Error
	 */
	private function contact_in_project( int $project_id, int $contact_id ) {
		$entry = Contacts::get( $contact_id );
		if ( ! $entry || (int) $entry->project_id !== $project_id ) {
			return new WP_Error( 'pp_not_found', __( 'Contact not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return true;
	}

	private function contact_payload( array $json ): array {
		$data = $this->sanitize_text_fields( $json, [ 'name', 'role', 'company', 'phone' ] );
		if ( array_key_exists( 'email', $json ) ) {
			$data['email'] = sanitize_email( (string) $json['email'] );
		}
		if ( array_key_exists( 'sort_order', $json ) ) {
			$data['sort_order'] = (int) $json['sort_order'];
		}
		return $data;
	}

	/* ---------- Dateien ---------- */

	public function files( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( Files::for_project( (int) $request['id'] ) );
	}

	public function add_file( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$json   = $request->get_json_params() ?: [];
		$result = Files::attach(
			(int) $request['id'],
			(int) ( $json['attachment_id'] ?? 0 ),
			isset( $json['title'] ) ? sanitize_text_field( (string) $json['title'] ) : ''
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Files::for_project( (int) $request['id'] ), 201 );
	}

	public function update_file( WP_REST_Request $request ) {
		$owner = $this->file_link_in_project( (int) $request['id'], (int) $request['file_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$json = $request->get_json_params() ?: [];
		$data = [];
		if ( array_key_exists( 'title', $json ) ) {
			$data['title'] = sanitize_text_field( (string) $json['title'] );
		}
		if ( array_key_exists( 'sort_order', $json ) ) {
			$data['sort_order'] = (int) $json['sort_order'];
		}
		$result = Files::update( (int) $request['file_id'], $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Files::for_project( (int) $request['id'] ) );
	}

	public function remove_file( WP_REST_Request $request ) {
		$owner = $this->file_link_in_project( (int) $request['id'], (int) $request['file_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Files::detach( (int) $request['file_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Files::for_project( (int) $request['id'] ) );
	}

	/**
	 * Sicherstellen, dass die Verknüpfung zum Projekt der URL gehört (nested route).
	 *
	 * @return true|WP_Error
	 */
	private function file_link_in_project( int $project_id, int $file_id ) {
		$entry = Files::get( $file_id );
		if ( ! $entry || (int) $entry->project_id !== $project_id ) {
			return new WP_Error( 'pp_not_found', __( 'File not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return true;
	}

	/* ---------- Beteiligte (Gruppen-Phase 2) ---------- */

	public function members( WP_REST_Request $request ) {
		// Projects::get() liefert für Nicht-Gruppenmitglieder eines Gruppen-Projekts
		// null → 404 (Gruppen-Zugriffsguard aus Phase 1, kein Leak). Site-Projekte
		// + Mitglieder + Admins erhalten das Projekt → Zugriff erlaubt.
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( ProjectMembers::for_project( (int) $request['id'] ) );
	}

	public function add_member( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$json   = $request->get_json_params() ?: [];
		$result = ProjectMembers::add(
			(int) $request['id'],
			(int) ( $json['user_id'] ?? 0 ),
			$this->member_payload( $json )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( ProjectMembers::for_project( (int) $request['id'] ), 201 );
	}

	public function update_member( WP_REST_Request $request ) {
		$owner = $this->member_in_project( (int) $request['id'], (int) $request['member_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = ProjectMembers::update( (int) $request['member_id'], $this->member_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( ProjectMembers::for_project( (int) $request['id'] ) );
	}

	public function remove_member( WP_REST_Request $request ) {
		$owner = $this->member_in_project( (int) $request['id'], (int) $request['member_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = ProjectMembers::remove( (int) $request['member_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( ProjectMembers::for_project( (int) $request['id'] ) );
	}

	/**
	 * Sicherstellen, dass die Roster-Zeile zum Projekt der URL gehört (nested
	 * route) UND dass der Aufrufer auf das Projekt zugreifen darf (Gruppen-Guard
	 * aus Phase 1). Reihenfolge: erst Projekt-Zugriff (404 bei Fremd-Projekt,
	 * kein Leak über die Existenz der Roster-Zeile), dann Zugehörigkeit.
	 *
	 * @return true|WP_Error
	 */
	private function member_in_project( int $project_id, int $member_id ) {
		if ( ! Projects::get( $project_id ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$entry = ProjectMembers::get( $member_id );
		if ( ! $entry || (int) $entry->project_id !== $project_id ) {
			return new WP_Error( 'pp_not_found', __( 'Member not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return true;
	}

	private function member_payload( array $json ): array {
		$data = [];
		if ( array_key_exists( 'role_title', $json ) ) {
			$data['role_title'] = sanitize_text_field( (string) $json['role_title'] );
		}
		if ( array_key_exists( 'note', $json ) ) {
			$data['note'] = sanitize_textarea_field( (string) $json['note'] );
		}
		if ( array_key_exists( 'sort_order', $json ) ) {
			$data['sort_order'] = (int) $json['sort_order'];
		}
		return $data;
	}

	/* ---------- Beschlüsse (Gruppen-Phase 3) ---------- */

	public function decisions( WP_REST_Request $request ) {
		// Projects::get() liefert für Nicht-Gruppenmitglieder eines Gruppen-
		// Projekts null → 404 (Gruppen-Zugriffsguard, kein Leak).
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( Decisions::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	public function add_decision( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$result = Decisions::create( (int) $request['id'], $this->decision_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Decisions::for_project( (int) $request['id'], get_current_user_id() ?: null ), 201 );
	}

	public function update_decision( WP_REST_Request $request ) {
		$owner = $this->decision_in_project( (int) $request['id'], (int) $request['decision_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Decisions::update( (int) $request['decision_id'], $this->decision_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Decisions::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	public function remove_decision( WP_REST_Request $request ) {
		$owner = $this->decision_in_project( (int) $request['id'], (int) $request['decision_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Decisions::delete( (int) $request['decision_id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Decisions::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	public function vote_decision( WP_REST_Request $request ) {
		$owner = $this->decision_in_project( (int) $request['id'], (int) $request['decision_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$json    = $request->get_json_params() ?: [];
		$vote    = sanitize_text_field( (string) ( $json['vote'] ?? '' ) );
		$comment = isset( $json['comment'] ) ? sanitize_textarea_field( (string) $json['comment'] ) : '';
		$result  = Decisions::cast_vote( (int) $request['decision_id'], get_current_user_id(), $vote, $comment );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Decisions::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	public function cancel_decision( WP_REST_Request $request ) {
		$owner = $this->decision_in_project( (int) $request['id'], (int) $request['decision_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Decisions::cancel( (int) $request['decision_id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Decisions::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	/**
	 * Sicherstellen, dass der Beschluss zum Projekt der URL gehört (nested route)
	 * UND der Aufrufer auf das Projekt zugreifen darf (Gruppen-Guard aus Phase 1).
	 * Reihenfolge: erst Projekt-Zugriff (404 bei Fremd-/Nicht-Gruppen-Projekt,
	 * kein Leak), dann Zugehörigkeit der Beschluss-Zeile.
	 *
	 * @return true|WP_Error
	 */
	private function decision_in_project( int $project_id, int $decision_id ) {
		if ( ! Projects::get( $project_id ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$entry = Decisions::get( $decision_id );
		if ( ! $entry || (int) $entry->project_id !== $project_id ) {
			return new WP_Error( 'pp_not_found', __( 'Decision not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return true;
	}

	private function decision_payload( array $json ): array {
		$data = [];
		if ( array_key_exists( 'title', $json ) ) {
			$data['title'] = sanitize_text_field( (string) $json['title'] );
		}
		if ( array_key_exists( 'description', $json ) ) {
			$data['description'] = sanitize_textarea_field( (string) $json['description'] );
		}
		if ( array_key_exists( 'requires_unanimous', $json ) ) {
			$data['requires_unanimous'] = ! empty( $json['requires_unanimous'] );
		}
		return $data;
	}

	/* ---------- Umfragen (v0.15.0, Pendant zu tab-polls) ---------- */

	public function polls( WP_REST_Request $request ) {
		// Projects::get() liefert für Nicht-Gruppenmitglieder eines Gruppen-
		// Projekts null → 404 (Gruppen-Zugriffsguard, kein Leak).
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( Polls::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	public function add_poll( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$result = Polls::create( (int) $request['id'], $this->poll_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Polls::for_project( (int) $request['id'], get_current_user_id() ?: null ), 201 );
	}

	public function update_poll( WP_REST_Request $request ) {
		$owner = $this->poll_in_project( (int) $request['id'], (int) $request['poll_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$json = $request->get_json_params() ?: [];
		$data = [];
		if ( array_key_exists( 'title', $json ) ) {
			$data['title'] = sanitize_text_field( (string) $json['title'] );
		}
		if ( array_key_exists( 'description', $json ) ) {
			$data['description'] = sanitize_textarea_field( (string) $json['description'] );
		}
		$result = Polls::update( (int) $request['poll_id'], $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Polls::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	public function remove_poll( WP_REST_Request $request ) {
		$owner = $this->poll_in_project( (int) $request['id'], (int) $request['poll_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Polls::delete( (int) $request['poll_id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Polls::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	/**
	 * Stimme zu EINER Option. Body: { option_id, vote }. Die Option muss zur
	 * Umfrage der URL gehören (sonst 404), Mitgliedschaft erzwingt der Service.
	 */
	public function vote_poll( WP_REST_Request $request ) {
		$owner = $this->poll_in_project( (int) $request['id'], (int) $request['poll_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$json      = $request->get_json_params() ?: [];
		$option_id = (int) ( $json['option_id'] ?? 0 );
		$vote      = sanitize_text_field( (string) ( $json['vote'] ?? '' ) );
		if ( ! $this->option_in_poll( (int) $request['poll_id'], $option_id ) ) {
			return new WP_Error( 'pp_not_found', __( 'Poll option not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$result = Polls::cast_vote( $option_id, get_current_user_id(), $vote );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Polls::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	public function close_poll( WP_REST_Request $request ) {
		$owner = $this->poll_in_project( (int) $request['id'], (int) $request['poll_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Polls::close( (int) $request['poll_id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Polls::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	public function reopen_poll( WP_REST_Request $request ) {
		$owner = $this->poll_in_project( (int) $request['id'], (int) $request['poll_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = Polls::reopen( (int) $request['poll_id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Polls::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	/**
	 * Sicherstellen, dass die Umfrage zum Projekt der URL gehört (nested route)
	 * UND der Aufrufer auf das Projekt zugreifen darf (Gruppen-Guard aus Phase 1).
	 * Reihenfolge: erst Projekt-Zugriff (404 bei Fremd-/Nicht-Gruppen-Projekt,
	 * kein Leak), dann Zugehörigkeit der Umfrage-Zeile.
	 *
	 * @return true|WP_Error
	 */
	private function poll_in_project( int $project_id, int $poll_id ) {
		if ( ! Projects::get( $project_id ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$entry = Polls::get( $poll_id );
		if ( ! $entry || (int) $entry->project_id !== $project_id ) {
			return new WP_Error( 'pp_not_found', __( 'Poll not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return true;
	}

	/**
	 * Gehört die Option zur Umfrage der URL? (verhindert Cross-Poll-Voting)
	 */
	private function option_in_poll( int $poll_id, int $option_id ): bool {
		global $wpdb;
		if ( ! $option_id ) {
			return false;
		}
		$found = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT poll_id FROM %i WHERE id = %d',
			\ProjectPrepper\Schema::table( 'project_poll_options' ),
			$option_id
		) );
		return $found === $poll_id;
	}

	private function poll_payload( array $json ): array {
		$data = [];
		if ( array_key_exists( 'title', $json ) ) {
			$data['title'] = sanitize_text_field( (string) $json['title'] );
		}
		if ( array_key_exists( 'description', $json ) ) {
			$data['description'] = sanitize_textarea_field( (string) $json['description'] );
		}
		if ( array_key_exists( 'poll_type', $json ) ) {
			$data['poll_type'] = sanitize_text_field( (string) $json['poll_type'] );
		}
		if ( isset( $json['options'] ) && is_array( $json['options'] ) ) {
			$options = [];
			foreach ( $json['options'] as $opt ) {
				if ( ! is_array( $opt ) ) {
					continue;
				}
				$options[] = [
					'label'       => isset( $opt['label'] ) ? sanitize_text_field( (string) $opt['label'] ) : '',
					'option_date' => isset( $opt['option_date'] ) ? sanitize_text_field( (string) $opt['option_date'] ) : '',
					'option_time' => isset( $opt['option_time'] ) ? sanitize_text_field( (string) $opt['option_time'] ) : '',
				];
			}
			$data['options'] = $options;
		}
		return $data;
	}

	/* ---------- Gewinnverteilung (Gruppen-Phase 4) ---------- */

	public function profit_shares( WP_REST_Request $request ) {
		// Projects::get() liefert für Nicht-Gruppenmitglieder eines Gruppen-
		// Projekts null → 404 (Gruppen-Zugriffsguard, kein Leak).
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( [
			'profit_shares'  => ProfitShares::for_project( (int) $request['id'] ),
			'profit_summary' => ProfitShares::summary( (int) $request['id'] ),
		] );
	}

	public function add_profit_share( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$json   = $request->get_json_params() ?: [];
		$result = ProfitShares::add(
			(int) $request['id'],
			(int) ( $json['user_id'] ?? 0 ),
			$this->profit_share_payload( $json )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Projects::get( (int) $request['id'] ), 201 );
	}

	public function update_profit_share( WP_REST_Request $request ) {
		$owner = $this->profit_share_in_project( (int) $request['id'], (int) $request['share_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = ProfitShares::update( (int) $request['share_id'], $this->profit_share_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Projects::get( (int) $request['id'] ) );
	}

	public function remove_profit_share( WP_REST_Request $request ) {
		$owner = $this->profit_share_in_project( (int) $request['id'], (int) $request['share_id'] );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = ProfitShares::remove( (int) $request['share_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Projects::get( (int) $request['id'] ) );
	}

	/**
	 * Sicherstellen, dass die Anteilszeile zum Projekt der URL gehört (nested
	 * route) UND der Aufrufer auf das Projekt zugreifen darf (Gruppen-Guard aus
	 * Phase 1). Reihenfolge: erst Projekt-Zugriff (404 bei Fremd-/Nicht-Gruppen-
	 * Projekt, kein Leak), dann Zugehörigkeit der Anteils-Zeile.
	 *
	 * @return true|WP_Error
	 */
	private function profit_share_in_project( int $project_id, int $share_id ) {
		if ( ! Projects::get( $project_id ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$entry = ProfitShares::get( $share_id );
		if ( ! $entry || (int) $entry->project_id !== $project_id ) {
			return new WP_Error( 'pp_not_found', __( 'Profit share not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return true;
	}

	private function profit_share_payload( array $json ): array {
		$data = $this->sanitize_text_fields( $json, [ 'share_type' ] );
		if ( array_key_exists( 'share_value', $json ) ) {
			// Roh-Wert durchreichen ('' / null erlaubt); der Service validiert die Zahl.
			$data['share_value'] = is_scalar( $json['share_value'] ) ? (string) $json['share_value'] : '';
		}
		if ( array_key_exists( 'note', $json ) ) {
			$data['note'] = sanitize_text_field( (string) $json['note'] );
		}
		if ( array_key_exists( 'sort_order', $json ) ) {
			$data['sort_order'] = (int) $json['sort_order'];
		}
		return $data;
	}

	/* ---------- Kooperationsvereinbarung (Gruppen-Phase 5) ---------- */

	public function agreement( WP_REST_Request $request ) {
		// Projects::get() liefert für Nicht-Gruppenmitglieder eines Gruppen-
		// Projekts null → 404 (Gruppen-Zugriffsguard, kein Leak).
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( Agreements::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	public function create_agreement( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$result = Agreements::create( (int) $request['id'], $this->agreement_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Agreements::for_project( (int) $request['id'], get_current_user_id() ?: null ), 201 );
	}

	public function update_agreement( WP_REST_Request $request ) {
		$agreement = $this->agreement_for_project( (int) $request['id'] );
		if ( is_wp_error( $agreement ) ) {
			return $agreement;
		}
		$result = Agreements::update( (int) $agreement->id, $this->agreement_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Agreements::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	public function open_agreement( WP_REST_Request $request ) {
		$agreement = $this->agreement_for_project( (int) $request['id'] );
		if ( is_wp_error( $agreement ) ) {
			return $agreement;
		}
		$result = Agreements::open_for_signing( (int) $agreement->id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Agreements::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	public function sign_agreement( WP_REST_Request $request ) {
		$agreement = $this->agreement_for_project( (int) $request['id'] );
		if ( is_wp_error( $agreement ) ) {
			return $agreement;
		}
		$action = sanitize_text_field( (string) ( $request->get_json_params()['action'] ?? 'sign' ) );
		$result = 'decline' === $action
			? Agreements::decline( (int) $agreement->id, get_current_user_id() )
			: Agreements::sign( (int) $agreement->id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Agreements::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	public function revise_agreement( WP_REST_Request $request ) {
		$agreement = $this->agreement_for_project( (int) $request['id'] );
		if ( is_wp_error( $agreement ) ) {
			return $agreement;
		}
		$result = Agreements::revise( (int) $agreement->id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Agreements::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	public function terminate_agreement( WP_REST_Request $request ) {
		$agreement = $this->agreement_for_project( (int) $request['id'] );
		if ( is_wp_error( $agreement ) ) {
			return $agreement;
		}
		$result = Agreements::terminate( (int) $agreement->id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Agreements::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	public function remove_agreement( WP_REST_Request $request ) {
		$agreement = $this->agreement_for_project( (int) $request['id'] );
		if ( is_wp_error( $agreement ) ) {
			return $agreement;
		}
		$result = Agreements::delete( (int) $agreement->id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Agreements::for_project( (int) $request['id'], get_current_user_id() ?: null ) );
	}

	/**
	 * Projekt-Zugriff prüfen (Gruppen-Guard aus Phase 1, 404 bei Fremd-/Nicht-
	 * Gruppen-Projekt, kein Leak) UND die (einzige) Vereinbarung des Projekts
	 * auflösen. 404 wenn keine existiert.
	 *
	 * @return object|WP_Error  Roh-Vereinbarung.
	 */
	private function agreement_for_project( int $project_id ) {
		if ( ! Projects::get( $project_id ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$agreement = Agreements::for_project( $project_id );
		if ( ! $agreement ) {
			return new WP_Error( 'pp_not_found', __( 'Agreement not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return $agreement;
	}

	private function agreement_payload( array $json ): array {
		$data = [];
		if ( array_key_exists( 'title', $json ) ) {
			$data['title'] = sanitize_text_field( (string) $json['title'] );
		}
		if ( array_key_exists( 'terms', $json ) ) {
			$data['terms'] = sanitize_textarea_field( (string) $json['terms'] );
		}
		return $data;
	}

	/* ---------- Checklisten ---------- */

	public function create_checklist( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$name   = sanitize_text_field( (string) ( $request->get_json_params()['name'] ?? '' ) );
		$result = Checklists::create( (int) $request['id'], $name );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'id' => $result ], 201 );
	}

	public function update_checklist( WP_REST_Request $request ) {
		$json = $request->get_json_params() ?: [];
		$data = $this->sanitize_text_fields( $json, [ 'name' ] );
		if ( array_key_exists( 'sort_order', $json ) ) {
			$data['sort_order'] = (int) $json['sort_order'];
		}
		$result = Checklists::update( (int) $request['id'], $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'updated' => true ] );
	}

	public function delete_checklist( WP_REST_Request $request ) {
		$result = Checklists::delete( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	public function add_checklist_item( WP_REST_Request $request ) {
		$label  = sanitize_text_field( (string) ( $request->get_json_params()['label'] ?? '' ) );
		$result = Checklists::add_item( (int) $request['id'], $label );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'id' => $result ], 201 );
	}

	public function update_checklist_item( WP_REST_Request $request ) {
		$json = $request->get_json_params() ?: [];
		$data = $this->sanitize_text_fields( $json, [ 'label' ] );
		if ( array_key_exists( 'is_checked', $json ) ) {
			$data['is_checked'] = ! empty( $json['is_checked'] );
		}
		if ( array_key_exists( 'sort_order', $json ) ) {
			$data['sort_order'] = (int) $json['sort_order'];
		}
		$result = Checklists::update_item( (int) $request['id'], $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'updated' => true ] );
	}

	public function delete_checklist_item( WP_REST_Request $request ) {
		$result = Checklists::delete_item( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	/* ---------- Aufgaben ---------- */

	public function create_task( WP_REST_Request $request ) {
		if ( ! Projects::get( (int) $request['id'] ) ) {
			return new WP_Error( 'pp_not_found', __( 'Project not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$result = Tasks::create( (int) $request['id'], $this->task_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'id' => $result ], 201 );
	}

	public function update_task( WP_REST_Request $request ) {
		$result = Tasks::update( (int) $request['id'], $this->task_payload( $request->get_json_params() ?: [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'updated' => true ] );
	}

	public function delete_task( WP_REST_Request $request ) {
		$result = Tasks::delete( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	private function task_payload( array $json ): array {
		$data = $this->sanitize_text_fields( $json, [ 'title', 'task_status', 'priority', 'due_date' ] );
		if ( array_key_exists( 'assigned_user', $json ) ) {
			$data['assigned_user'] = (int) $json['assigned_user'];
		}
		if ( array_key_exists( 'sort_order', $json ) ) {
			$data['sort_order'] = (int) $json['sort_order'];
		}
		return $data;
	}
}
