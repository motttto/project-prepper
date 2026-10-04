<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\MemberApi;
use ProjectPrepper\Security;
use ProjectPrepper\Services\Groups;
use ProjectPrepper\Services\Inventory;
use ProjectPrepper\Services\Rentals;
use ProjectPrepper\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Mitglieder-API (v0.149.0): NUR LESEN, NUR EIGENES — für jeden angemeldeten
 * Nutzer mit Project-Prepper-Rolle (Mitglied, Manager, Admin), auch per
 * App-Passwort aus dem Portal ({@see MemberApi}).
 *
 *   GET /me                  Wer bin ich: id, name, roles, groups
 *   GET /me/items            Eigene Artikel (owner_user_id) — Form wie GET /items
 *   GET /me/rentals?status=  Selbst angelegte Verleihe (owner_user_id) — Form wie GET /rentals
 *   GET /me/rentals/{id}     Ein eigener Verleih mit Positionen + Abrechnung — Form wie GET /rentals/{id}
 *
 * Mandanten-Grenze: Jede Abfrage filtert serverseitig auf die ID des
 * angemeldeten Nutzers; ein fremder Verleih per ID ist 404 (nicht 403 — wer
 * fremde IDs durchprobiert, erfährt nicht, ob es sie gibt). Kollektiv-Verleihe,
 * die ANDERE Mitglieder angelegt haben, gehören bewusst nicht dazu (Standard
 * „nur eigene"); im Namen eines Kollektivs selbst angelegte schon.
 *
 * Die Admin-Routen (/items, /rentals …) bleiben unverändert Betreiber-only.
 */
class MeController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/me', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'me' ],
			'permission_callback' => $this->require_member_api(),
		] );

		register_rest_route( self::REST_NAMESPACE, '/me/items', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'items' ],
			'permission_callback' => $this->require_member_api( 'inventory' ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/me/rentals', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rentals' ],
			'permission_callback' => $this->require_member_api( 'lending' ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/me/rentals/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rental' ],
			'permission_callback' => $this->require_member_api( 'lending' ),
		] );
	}

	/**
	 * permission_callback-Factory (Gegenstück zu require_cap): angemeldet,
	 * Project-Prepper-Rolle (pp_collectives haben Mitglied, Manager und Admin),
	 * Mitglieder-API an, ggf. der Funktionsbereich der Route an.
	 *
	 * Ohne Seiteneffekte — WordPress ruft den Callback für den Allow-Header ein
	 * zweites Mal auf. Die Drosselung zählt deshalb erst im Callback ({@see throttle}).
	 */
	protected function require_member_api( string $feature = '' ): callable {
		return static function () use ( $feature ) {
			if ( ! is_user_logged_in() ) {
				// Gesperrte IP: das sagen statt „bitte anmelden" — sonst sucht man den
				// Fehler beim (richtigen) Passwort (Audit ACC-API-09).
				if ( Security::is_locked() ) {
					return new WP_Error(
						'pp_locked',
						sprintf(
							/* translators: %d: minutes until the next login attempt is allowed. */
							__( 'Too many failed attempts. Please try again in about %d minutes.', 'project-prepper' ),
							Security::int( 'login_lockout_minutes' )
						),
						[ 'status' => 401 ]
					);
				}
				return new WP_Error(
					'rest_not_logged_in',
					__( 'Please sign in with your username and an API password from the member portal (“API access”).', 'project-prepper' ),
					[ 'status' => 401 ]
				);
			}
			if ( ! current_user_can( Capabilities::COLLECTIVES ) ) {
				return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'project-prepper' ), [ 'status' => 403 ] );
			}
			if ( ! MemberApi::enabled() ) {
				return new WP_Error( 'pp_member_api_off', __( 'The member API is switched off on this site.', 'project-prepper' ), [ 'status' => 403 ] );
			}
			if ( '' !== $feature && ! Settings::feature_on( $feature ) ) {
				return new WP_Error( 'pp_feature_off', __( 'This area is switched off on this site.', 'project-prepper' ), [ 'status' => 403 ] );
			}
			return true;
		};
	}

	/** Drosselung je Nutzer — einmal je beantworteter Anfrage. */
	private function throttle(): ?WP_Error {
		if ( MemberApi::rate_limited( get_current_user_id() ) ) {
			return new WP_Error( 'pp_rate_limited', __( 'Too many requests. Please wait a minute and try again.', 'project-prepper' ), [ 'status' => 429 ] );
		}
		return null;
	}

	public function me() {
		$limited = $this->throttle();
		if ( $limited ) {
			return $limited;
		}
		$user = wp_get_current_user();
		return new WP_REST_Response( [
			'id'     => (int) $user->ID,
			'name'   => (string) $user->display_name,
			'roles'  => array_values( (array) $user->roles ),
			'groups' => array_map( static function ( $g ) {
				return [
					'id'   => (int) $g->id,
					'name' => (string) $g->name,
					'role' => (string) $g->member_role,
				];
			}, Groups::user_groups( (int) $user->ID ) ),
		] );
	}

	public function items( WP_REST_Request $request ) {
		$limited = $this->throttle();
		if ( $limited ) {
			return $limited;
		}
		$user = wp_get_current_user();
		if ( $user->ID <= 0 ) {
			// Doppelter Boden: Inventory::items() ohne Eigentümer-Filter = ALLE Artikel.
			return new WP_REST_Response( [] );
		}
		$items = Inventory::items( [
			'owner_user_id' => (int) $user->ID,
			'search'        => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'category_id'   => (int) $request->get_param( 'category_id' ),
			'out_only'      => rest_sanitize_boolean( $request->get_param( 'out_only' ) ),
		] );
		foreach ( $items as $item ) {
			$item->owner_name = (string) $user->display_name;
		}
		return new WP_REST_Response( $items );
	}

	public function rentals( WP_REST_Request $request ) {
		$limited = $this->throttle();
		if ( $limited ) {
			return $limited;
		}
		return new WP_REST_Response( Rentals::all( [
			'owner_user_id' => get_current_user_id(),
			// Auch die im Namen eines Kollektivs selbst angelegten — Anleger ist er.
			'any_workspace' => true,
			'status'        => sanitize_text_field( (string) $request->get_param( 'status' ) ),
		] ) );
	}

	public function rental( WP_REST_Request $request ) {
		$limited = $this->throttle();
		if ( $limited ) {
			return $limited;
		}
		$rental = Rentals::get( (int) $request['id'] );
		if ( ! $rental || (int) ( $rental->owner_user_id ?? 0 ) !== get_current_user_id() ) {
			return new WP_Error( 'pp_not_found', __( 'Rental not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( $rental );
	}
}
