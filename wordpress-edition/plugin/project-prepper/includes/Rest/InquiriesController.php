<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\Inquiries;
use ProjectPrepper\Services\Rentals;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Anfragen — Admin-Seite (cap-geschützt). Das öffentliche Formular läuft
 * NICHT hierüber, sondern über admin-post.php (Frontend\Shortcodes).
 */
class InquiriesController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/inquiries', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'index' ],
			'permission_callback' => $this->require_cap( Capabilities::VIEW_INQUIRIES ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/inquiries/member-aggregate', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'member_aggregate' ],
			'permission_callback' => $this->require_cap( Capabilities::VIEW_INQUIRIES ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/inquiries/(?P<id>\d+)/status', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'set_status' ],
			'permission_callback' => $this->require_cap( Capabilities::EDIT_INQUIRIES ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/inquiries/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'show' ],
				'permission_callback' => $this->require_cap( Capabilities::VIEW_INQUIRIES ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_INQUIRIES ),
			],
		] );

		// Konvertierung legt einen Verleih an → braucht BEIDE Edit-Caps.
		register_rest_route( self::REST_NAMESPACE, '/inquiries/(?P<id>\d+)/convert', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'convert' ],
			'permission_callback' => static function () {
				return current_user_can( Capabilities::EDIT_INQUIRIES ) && current_user_can( Capabilities::EDIT_RENTALS );
			},
		] );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( Inquiries::all( [
			'status'    => sanitize_text_field( (string) $request->get_param( 'status' ) ),
			'site_only' => true, // Mitglieds-Anfragen bleiben dem Betreiber verborgen (§10.1).
		] ) );
	}

	public function member_aggregate(): WP_REST_Response {
		return new WP_REST_Response( Inquiries::member_aggregate() );
	}

	public function show( WP_REST_Request $request ) {
		$inquiry = Inquiries::get( (int) $request['id'] );
		if ( ! $inquiry ) {
			return new WP_Error( 'pp_not_found', __( 'Inquiry not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( $inquiry );
	}

	public function set_status( WP_REST_Request $request ) {
		$status = sanitize_text_field( (string) ( $request->get_json_params()['status'] ?? '' ) );
		if ( ! in_array( $status, Inquiries::STATUSES, true ) ) {
			return new WP_Error( 'pp_invalid_status', __( 'Invalid status.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$result = Inquiries::set_status( (int) $request['id'], $status );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Inquiries::get( (int) $request['id'] ) );
	}

	public function convert( WP_REST_Request $request ) {
		$result = Inquiries::convert_to_rental( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( Rentals::get( $result ), 201 );
	}

	public function delete( WP_REST_Request $request ) {
		Inquiries::delete( (int) $request['id'] );
		return new WP_REST_Response( [ 'deleted' => true ] );
	}
}
