<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\Availability;
use ProjectPrepper\Services\Inventory;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class ItemsController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/items', [
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

		register_rest_route( self::REST_NAMESPACE, '/items/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'show' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_INVENTORY ),
			],
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

		register_rest_route( self::REST_NAMESPACE, '/stats', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'stats' ],
			'permission_callback' => $this->require_cap( Capabilities::VIEW_INVENTORY ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/items/(?P<id>\d+)/availability', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'availability' ],
			'permission_callback' => $this->require_cap( Capabilities::VIEW_INVENTORY ),
			'args'                => [
				'from' => [ 'required' => true ],
				'to'   => [ 'required' => true ],
			],
		] );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( Inventory::items( [
			'search'      => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'category_id' => (int) $request->get_param( 'category_id' ),
			'out_only'    => rest_sanitize_boolean( $request->get_param( 'out_only' ) ),
		] ) );
	}

	public function show( WP_REST_Request $request ) {
		$item = Inventory::get_item( (int) $request['id'] );
		if ( ! $item ) {
			return new WP_Error( 'pp_not_found', __( 'Artikel nicht gefunden.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( $item );
	}

	public function create( WP_REST_Request $request ) {
		$data = $this->payload( $request );
		if ( empty( $data['name'] ) ) {
			return new WP_Error( 'pp_missing_name', __( 'Name fehlt.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$id = Inventory::create_item( $data );
		return new WP_REST_Response( Inventory::get_item( $id ), 201 );
	}

	public function update( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! Inventory::get_item( $id ) ) {
			return new WP_Error( 'pp_not_found', __( 'Artikel nicht gefunden.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		Inventory::update_item( $id, $this->payload( $request ) );
		return new WP_REST_Response( Inventory::get_item( $id ) );
	}

	public function delete( WP_REST_Request $request ) {
		Inventory::delete_item( (int) $request['id'] );
		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	public function stats(): WP_REST_Response {
		return new WP_REST_Response( Inventory::stats() );
	}

	public function availability( WP_REST_Request $request ) {
		$from = sanitize_text_field( (string) $request->get_param( 'from' ) );
		$to   = sanitize_text_field( (string) $request->get_param( 'to' ) );
		if ( ! Availability::is_valid_range( $from, $to ) ) {
			return new WP_Error( 'pp_invalid_dates', __( 'Ungültiger Zeitraum.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		return new WP_REST_Response( [
			'item_id'   => (int) $request['id'],
			'from'      => $from,
			'to'        => $to,
			'available' => Availability::available_quantity( (int) $request['id'], $from, $to ),
		] );
	}

	/**
	 * Eingabe-Payload säubern (Whitelist + Typen).
	 */
	private function payload( WP_REST_Request $request ): array {
		$json = $request->get_json_params() ?: [];
		$data = $this->sanitize_text_fields( $json, [
			'inventory_number', 'name', 'manufacturer', 'model', 'serial_number', 'condition', 'location', 'purchase_date', 'dimensions',
			'ownership_type', 'funding_source', 'depreciation_method',
		] );

		foreach ( [ 'manufacturer_url', 'manual_url' ] as $key ) {
			if ( array_key_exists( $key, $json ) ) {
				$data[ $key ] = esc_url_raw( (string) $json[ $key ] );
			}
		}
		foreach ( [ 'description', 'notes', 'accessories' ] as $key ) {
			if ( array_key_exists( $key, $json ) ) {
				$data[ $key ] = sanitize_textarea_field( (string) $json[ $key ] );
			}
		}
		foreach ( [ 'category_id', 'quantity', 'image_id' ] as $key ) {
			if ( array_key_exists( $key, $json ) ) {
				$data[ $key ] = (int) $json[ $key ];
			}
		}
		foreach ( [ 'power_watts', 'depreciation_years' ] as $key ) {
			if ( array_key_exists( $key, $json ) ) {
				$data[ $key ] = '' === $json[ $key ] || null === $json[ $key ] ? '' : (int) $json[ $key ];
			}
		}
		foreach ( [ 'cost_per_day', 'purchase_price', 'current_value', 'residual_value' ] as $key ) {
			if ( array_key_exists( $key, $json ) ) {
				$data[ $key ] = '' === $json[ $key ] ? '' : (float) $json[ $key ];
			}
		}
		if ( array_key_exists( 'tags', $json ) ) {
			$data['tags'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) $json['tags'] ) ) );
		}
		if ( array_key_exists( 'document_ids', $json ) ) {
			$data['document_ids'] = array_values( array_map( 'intval', (array) $json['document_ids'] ) );
		}
		return $data;
	}
}
