<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\Inventory;
use ProjectPrepper\Services\Units;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Einzelstücke (§8.4) — pro Stück: Nummer, Zustand, Notizen.
 */
class UnitsController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/items/(?P<item_id>\d+)/units', [
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

		register_rest_route( self::REST_NAMESPACE, '/units/(?P<id>\d+)', [
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

	public function index( WP_REST_Request $request ) {
		$item_id = (int) $request['item_id'];
		if ( ! Inventory::get_item( $item_id ) ) {
			return new WP_Error( 'pp_not_found', __( 'Artikel nicht gefunden.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( Units::for_item( $item_id ) );
	}

	public function create( WP_REST_Request $request ) {
		$item_id = (int) $request['item_id'];
		$item    = Inventory::get_item( $item_id );
		if ( ! $item ) {
			return new WP_Error( 'pp_not_found', __( 'Artikel nicht gefunden.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( count( Units::for_item( $item_id ) ) >= (int) $item->quantity ) {
			return new WP_Error( 'pp_units_full', __( 'Es gibt bereits ein Einzelstück für jedes Stück der Menge.', 'project-prepper' ), [ 'status' => 409 ] );
		}
		$json = $request->get_json_params() ?: [];
		Units::create( $item_id, [
			'condition' => sanitize_text_field( (string) ( $json['condition'] ?? '' ) ),
			'notes'     => sanitize_textarea_field( (string) ( $json['notes'] ?? '' ) ),
		] );
		return new WP_REST_Response( Units::for_item( $item_id ), 201 );
	}

	public function update( WP_REST_Request $request ) {
		$json = $request->get_json_params() ?: [];
		$data = [];
		if ( array_key_exists( 'condition', $json ) ) {
			$data['condition'] = sanitize_text_field( (string) $json['condition'] );
		}
		if ( array_key_exists( 'notes', $json ) ) {
			$data['notes'] = sanitize_textarea_field( (string) $json['notes'] );
		}
		Units::update( (int) $request['id'], $data );
		return new WP_REST_Response( [ 'updated' => true ] );
	}

	public function delete( WP_REST_Request $request ) {
		Units::delete( (int) $request['id'] );
		return new WP_REST_Response( [ 'deleted' => true ] );
	}
}
