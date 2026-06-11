<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\Rentals;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class RentalsController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/rentals', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'index' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_RENTALS ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_RENTALS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/rentals/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'show' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_RENTALS ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_RENTALS ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_RENTALS ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/rentals/(?P<id>\d+)/status', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'set_status' ],
			'permission_callback' => $this->require_cap( Capabilities::EDIT_RENTALS ),
		] );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( Rentals::all( [
			'status' => sanitize_text_field( (string) $request->get_param( 'status' ) ),
		] ) );
	}

	public function show( WP_REST_Request $request ) {
		$rental = Rentals::get( (int) $request['id'] );
		if ( ! $rental ) {
			return new WP_Error( 'pp_not_found', __( 'Verleih nicht gefunden.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( $rental );
	}

	public function create( WP_REST_Request $request ) {
		$json = $request->get_json_params() ?: [];

		$data = $this->sanitize_text_fields( $json, [
			'borrower_name', 'borrower_phone', 'date_from', 'date_to',
		] );
		$data['borrower_email']   = sanitize_email( (string) ( $json['borrower_email'] ?? '' ) );
		$data['borrower_address'] = sanitize_textarea_field( (string) ( $json['borrower_address'] ?? '' ) );
		$data['notes']            = sanitize_textarea_field( (string) ( $json['notes'] ?? '' ) );
		foreach ( [ 'deposit_amount', 'rental_fee', 'vat_rate' ] as $key ) {
			$data[ $key ] = isset( $json[ $key ] ) && '' !== $json[ $key ] ? (float) $json[ $key ] : '';
		}

		$result = Rentals::create( $data, (array) ( $json['items'] ?? [] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Rentals::get( $result ), 201 );
	}

	public function update( WP_REST_Request $request ) {
		$json = $request->get_json_params() ?: [];

		// Nur übergebene Keys weiterreichen — der Service macht den Header-Diff.
		$data = $this->sanitize_text_fields( $json, [
			'borrower_name', 'borrower_phone', 'date_from', 'date_to',
		] );
		if ( array_key_exists( 'borrower_email', $json ) ) {
			$data['borrower_email'] = sanitize_email( (string) $json['borrower_email'] );
		}
		if ( array_key_exists( 'borrower_address', $json ) ) {
			$data['borrower_address'] = sanitize_textarea_field( (string) $json['borrower_address'] );
		}
		if ( array_key_exists( 'notes', $json ) ) {
			$data['notes'] = sanitize_textarea_field( (string) $json['notes'] );
		}
		foreach ( [ 'deposit_amount', 'rental_fee', 'vat_rate' ] as $key ) {
			if ( array_key_exists( $key, $json ) ) {
				$data[ $key ] = '' !== $json[ $key ] && null !== $json[ $key ] ? (float) $json[ $key ] : '';
			}
		}

		$items  = array_key_exists( 'items', $json ) ? (array) $json['items'] : null;
		$result = Rentals::update( (int) $request['id'], $data, $items );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Rentals::get( (int) $request['id'] ) );
	}

	public function set_status( WP_REST_Request $request ) {
		$status = sanitize_text_field( (string) ( $request->get_json_params()['status'] ?? '' ) );
		if ( ! in_array( $status, Rentals::STATUSES, true ) ) {
			return new WP_Error( 'pp_invalid_status', __( 'Ungültiger Status.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$result = Rentals::set_status( (int) $request['id'], $status );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Rentals::get( (int) $request['id'] ) );
	}

	public function delete( WP_REST_Request $request ) {
		Rentals::delete( (int) $request['id'] );
		return new WP_REST_Response( [ 'deleted' => true ] );
	}
}
