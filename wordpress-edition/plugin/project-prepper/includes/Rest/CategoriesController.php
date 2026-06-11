<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\Inventory;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class CategoriesController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/categories', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'index' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_INVENTORY ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_INVENTORY ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/categories/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_INVENTORY ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_INVENTORY ),
			],
		] );
	}

	public function index(): WP_REST_Response {
		return new WP_REST_Response( Inventory::categories() );
	}

	public function create( WP_REST_Request $request ) {
		$data = $this->sanitize_text_fields( $request->get_json_params() ?: [], [ 'name', 'icon', 'prefix' ] );
		if ( empty( $data['name'] ) ) {
			return new WP_Error( 'pp_missing_name', __( 'Name fehlt.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$data['sort_order'] = (int) ( $request['sort_order'] ?? 0 );
		$id = Inventory::create_category( $data );
		return new WP_REST_Response( [ 'id' => $id ], 201 );
	}

	public function update( WP_REST_Request $request ) {
		$data = $this->sanitize_text_fields( $request->get_json_params() ?: [], [ 'name', 'icon', 'prefix' ] );
		$json = $request->get_json_params() ?: [];
		if ( array_key_exists( 'sort_order', $json ) ) {
			$data['sort_order'] = (int) $json['sort_order'];
		}
		Inventory::update_category( (int) $request['id'], $data );
		return new WP_REST_Response( [ 'updated' => true ] );
	}

	public function delete( WP_REST_Request $request ) {
		Inventory::delete_category( (int) $request['id'] );
		return new WP_REST_Response( [ 'deleted' => true ] );
	}
}
