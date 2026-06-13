<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\Checklists;
use ProjectPrepper\Services\Costs;
use ProjectPrepper\Services\Projects;
use ProjectPrepper\Services\Schedule;
use ProjectPrepper\Services\Tasks;
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
