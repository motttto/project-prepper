<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\Availability;
use ProjectPrepper\Services\Calendar;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * REST-Controller für die read-only Monatsansicht des Kalenders.
 *
 * Getrennt vom iCal-Feed (CalendarController::feed mit Token-Auth) — diese
 * Route liefert JSON für die Admin-UI und prüft eine View-Cap.
 *
 * Zugriff: niedrigste sinnvolle View-Cap (Verleih ODER Projekte) — die
 * gelieferten Events sind ohnehin Aggregate, Projekte respektieren im Service
 * zusätzlich den Gruppen-Zugriff.
 */
class CalendarViewController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/calendar-events', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'index' ],
			'permission_callback' => static function () {
				return current_user_can( Capabilities::VIEW_RENTALS )
					|| current_user_can( Capabilities::VIEW_PROJECTS );
			},
		] );
	}

	public function index( WP_REST_Request $request ) {
		$from = (string) $request->get_param( 'from' );
		$to   = (string) $request->get_param( 'to' );

		// Default: aktueller Monat, wenn keine Parameter übergeben werden.
		if ( '' === $from && '' === $to ) {
			$from = current_time( 'Y-m-01' );
			$to   = current_time( 'Y-m-t' );
		}

		if ( ! Availability::is_valid_date( $from ) || ! Availability::is_valid_date( $to ) ) {
			return new WP_Error( 'pp_invalid_dates', __( 'Invalid date range.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		if ( $from > $to ) {
			return new WP_Error( 'pp_invalid_dates', __( 'Invalid date range.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		return new WP_REST_Response( Calendar::events( $from, $to ) );
	}
}
