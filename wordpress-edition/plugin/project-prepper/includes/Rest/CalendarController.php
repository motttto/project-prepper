<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Services\Rentals;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * iCal-Export der Verleihe (§13) — read-only Feed mit Token-Auth,
 * abonnierbar in Apple/Google/Outlook-Kalendern.
 *
 * URL: /wp-json/project-prepper/v1/calendar.ics?token={pp_ical_token}
 */
class CalendarController extends BaseController {

	const OPTION_TOKEN = 'pp_ical_token';

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/calendar.ics', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'feed' ],
			// Token-Auth statt Login — Kalender-Clients können keine WP-Session.
			'permission_callback' => '__return_true',
		] );
	}

	public static function token(): string {
		$token = get_option( self::OPTION_TOKEN );
		if ( ! $token ) {
			$token = wp_generate_password( 32, false, false );
			update_option( self::OPTION_TOKEN, $token );
		}
		return $token;
	}

	public static function regenerate_token(): string {
		delete_option( self::OPTION_TOKEN );
		return self::token();
	}

	public function feed( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		if ( ! $token || ! hash_equals( self::token(), $token ) ) {
			return new WP_Error( 'pp_invalid_token', __( 'Invalid token.', 'project-prepper' ), [ 'status' => 401 ] );
		}

		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Project Prepper//Verleih//DE',
			'CALSCALE:GREGORIAN',
			'X-WR-CALNAME:' . $this->ical_escape( get_bloginfo( 'name' ) . ' — ' . __( 'Rentals', 'project-prepper' ) ),
		];

		foreach ( Rentals::all() as $rental ) {
			if ( ! in_array( $rental->status, [ 'reserved', 'active' ], true ) ) {
				continue;
			}
			$uid     = 'pp-rental-' . $rental->id . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
			$dt_end  = gmdate( 'Ymd', strtotime( $rental->date_to . ' +1 day' ) ); // DTEND exklusiv
			$summary = sprintf( '%s — %s', $rental->rental_number, $rental->borrower_name );

			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . $uid;
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z', strtotime( $rental->updated_at ) );
			$lines[] = 'DTSTART;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $rental->date_from ) );
			$lines[] = 'DTEND;VALUE=DATE:' . $dt_end;
			$lines[] = 'SUMMARY:' . $this->ical_escape( $summary );
			$lines[] = 'STATUS:' . ( 'active' === $rental->status ? 'CONFIRMED' : 'TENTATIVE' );
			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';

		// Rohes iCal statt JSON ausliefern.
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="project-prepper.ics"' );
		echo implode( "\r\n", $lines ) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private function ical_escape( string $value ): string {
		return addcslashes( $value, ",;\\" );
	}
}
