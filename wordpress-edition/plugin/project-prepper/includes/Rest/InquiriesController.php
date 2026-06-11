<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\Inquiries;
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

		register_rest_route( self::REST_NAMESPACE, '/inquiries/(?P<id>\d+)/status', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'set_status' ],
			'permission_callback' => $this->require_cap( Capabilities::EDIT_INQUIRIES ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/inquiries/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete' ],
			'permission_callback' => $this->require_cap( Capabilities::EDIT_INQUIRIES ),
		] );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( Inquiries::all( [
			'status' => sanitize_text_field( (string) $request->get_param( 'status' ) ),
		] ) );
	}

	public function set_status( WP_REST_Request $request ) {
		$status = sanitize_text_field( (string) ( $request->get_json_params()['status'] ?? '' ) );
		if ( ! in_array( $status, Inquiries::STATUSES, true ) ) {
			return new WP_Error( 'pp_invalid_status', __( 'Ungültiger Status.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		Inquiries::set_status( (int) $request['id'], $status );
		return new WP_REST_Response( Inquiries::get( (int) $request['id'] ) );
	}

	public function delete( WP_REST_Request $request ) {
		Inquiries::delete( (int) $request['id'] );
		return new WP_REST_Response( [ 'deleted' => true ] );
	}
}
