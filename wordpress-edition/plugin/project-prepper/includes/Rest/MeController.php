<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\MemberApi;
use ProjectPrepper\Security;
use ProjectPrepper\Services\Groups;
use ProjectPrepper\Services\Inventory;
use ProjectPrepper\Services\MemberInventory;
use ProjectPrepper\Services\Rentals;
use ProjectPrepper\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Mitglieder-API (v0.149.0): NUR EIGENES — für jeden angemeldeten Nutzer mit
 * Project-Prepper-Rolle (Mitglied, Manager, Admin), auch per App-Passwort aus
 * dem Portal ({@see MemberApi}).
 *
 *   GET /me                  Wer bin ich: id, name, roles, groups
 *   GET /me/items            Eigene Artikel (owner_user_id) — Form wie GET /items
 *   GET /me/categories       Eigene Kategorien (id, name, icon, prefix) — v0.150.0
 *   POST /me/items           Eigenen Artikel anlegen (Besitzer = angemeldeter Nutzer) — v0.150.0
 *   PUT /me/items/{id}       Eigenen Artikel ändern, nur die mitgeschickten Felder — v0.150.0
 *   GET /me/rentals?status=  Selbst angelegte Verleihe (owner_user_id) — Form wie GET /rentals
 *   GET /me/rentals/{id}     Ein eigener Verleih mit Positionen + Abrechnung — Form wie GET /rentals/{id}
 *
 * Schreiben (v0.150.0) geht über dieselben Wege wie „Mein Inventar" im Portal
 * ({@see MemberInventory::create}/{@see MemberInventory::update}): Besitzer ist
 * immer der angemeldete Nutzer und nicht änderbar; Bild, Dokumente und Löschen
 * bleiben im Portal. Mit App-Passwort nur, wenn das Passwort im Portal mit
 * „Lesen + eigenes Equipment bearbeiten" angelegt wurde ({@see MemberApi::APP_ID_WRITE});
 * alle anderen App-Passwörter bleiben nur lesend.
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
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'items' ],
				'permission_callback' => $this->require_member_api( 'inventory' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->require_member_api( 'inventory' ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/me/items/(?P<id>\d+)', [
			'methods'             => 'PUT, PATCH',
			'callback'            => [ $this, 'update_item' ],
			'permission_callback' => $this->require_member_api( 'inventory' ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/me/categories', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'categories' ],
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
				// Core meldet einen FEHLGESCHLAGENEN App-Passwort-Versuch hier nur als
				// „nicht angemeldet" (Reihenfolge der Prüfungen). Für die Fehlersuche
				// zählt aber, ob Zugangsdaten ankamen und falsch waren — oder gar keine
				// ankamen (Server reicht den Authorization-Header nicht weiter). Welcher
				// Teil falsch ist, bleibt offen (keine Nutzer-Aufzählung).
				if ( is_wp_error( $GLOBALS['wp_rest_application_password_status'] ?? null ) ) {
					return new WP_Error(
						'pp_bad_credentials',
						__( 'Sign-in failed: the username or the API password is wrong, or this account cannot use the member API.', 'project-prepper' ),
						[ 'status' => 401 ]
					);
				}
				return new WP_Error(
					'rest_not_logged_in',
					__( 'No sign-in data arrived. Sign in with your username and an API password from the member portal (“API access”). If your program does send them, the web server is not passing the login on to WordPress.', 'project-prepper' ),
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

	public function categories() {
		$limited = $this->throttle();
		if ( $limited ) {
			return $limited;
		}
		return new WP_REST_Response( array_map( static function ( $c ) {
			return [
				'id'     => (int) $c->id,
				'name'   => (string) $c->name,
				'icon'   => (string) $c->icon,
				'prefix' => (string) $c->prefix,
			];
		}, MemberInventory::own_categories( get_current_user_id() ) ) );
	}

	public function create_item( WP_REST_Request $request ) {
		$limited = $this->throttle();
		if ( $limited ) {
			return $limited;
		}
		$uid  = get_current_user_id();
		$data = $this->member_payload( $request, $uid, 0 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$id = MemberInventory::create( $uid, $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return new WP_REST_Response( $this->own_item( (int) $id ), 201 );
	}

	public function update_item( WP_REST_Request $request ) {
		$limited = $this->throttle();
		if ( $limited ) {
			return $limited;
		}
		$uid = get_current_user_id();
		$id  = (int) $request['id'];
		$old = Inventory::get_item( $id );
		// Fremd oder unbekannt: 404 wie bei /me/rentals/{id} — wer IDs durchprobiert, erfährt nichts.
		if ( ! $old || (int) ( $old->owner_user_id ?? 0 ) !== $uid || $uid <= 0 ) {
			return new WP_Error( 'pp_not_found', __( 'Item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$data = $this->member_payload( $request, $uid, $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! array_key_exists( 'name', $data ) ) {
			$data['name'] = (string) $old->name; // Teil-Update ohne Namen: MemberInventory::update verlangt ihn.
		}
		$json   = $request->get_json_params() ?: [];
		$expect = isset( $json['expect'] ) ? sanitize_text_field( (string) $json['expect'] ) : '';
		$result = MemberInventory::update( $uid, $id, $data, '' !== $expect ? $expect : null );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $this->own_item( $id ) );
	}

	/**
	 * Body eines Schreibzugriffs: Felder wie die Admin-Route ({@see ItemsController::item_payload}),
	 * aber ohne Eigentum, Bild und Dokumente (fremde Anhänge ließen sich sonst
	 * einhängen), mit Prüfung von Kategorie (nur eigene), Zustand und Inventarnummer.
	 *
	 * @return array|WP_Error
	 */
	private function member_payload( WP_REST_Request $request, int $uid, int $item_id ) {
		$json = $request->get_json_params();
		if ( ! is_array( $json ) || ! $json ) {
			return new WP_Error( 'pp_bad_request', __( 'Please send the item fields as JSON.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$data = ItemsController::item_payload( $json );
		unset( $data['owner_user_id'], $data['image_id'], $data['document_ids'] );
		if ( array_key_exists( 'name', $data ) && '' === trim( $data['name'] ) ) {
			return new WP_Error( 'pp_missing_name', __( 'Please enter a name for the item.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		if ( array_key_exists( 'category_id', $data ) && 0 !== (int) $data['category_id'] ) {
			$own = array_map( static fn( $c ) => (int) $c->id, MemberInventory::own_categories( $uid ) );
			if ( ! in_array( (int) $data['category_id'], $own, true ) ) {
				return new WP_Error( 'pp_bad_category', __( 'This category is not one of yours (GET /me/categories).', 'project-prepper' ), [ 'status' => 400 ] );
			}
		}
		if ( array_key_exists( 'condition', $data ) && ! in_array( $data['condition'], Inventory::CONDITIONS, true ) ) {
			return new WP_Error(
				'pp_bad_condition',
				/* translators: %s: list of allowed condition values. */
				sprintf( __( 'Unknown condition. Allowed: %s', 'project-prepper' ), implode( ', ', Inventory::CONDITIONS ) ),
				[ 'status' => 400 ]
			);
		}
		if ( array_key_exists( 'purchase_date', $data ) && '' !== $data['purchase_date'] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['purchase_date'] ) ) {
			return new WP_Error( 'pp_bad_date', __( 'Purchase date as YYYY-MM-DD, please.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		if ( array_key_exists( 'inventory_number', $data ) ) {
			$data['inventory_number'] = trim( $data['inventory_number'] );
			if ( $item_id > 0 ) {
				// Wie im Portal: Die Nummer steht ab dem Anlegen fest (Etiketten/QR-Codes
				// zeigen auf sie; ACC-W-C1). Gleiche Nummer mitschicken ist erlaubt.
				if ( '' !== $data['inventory_number'] && $data['inventory_number'] !== (string) Inventory::get_item( $item_id )->inventory_number ) {
					return new WP_Error( 'pp_number_fixed', __( 'The inventory number cannot be changed after the item was added.', 'project-prepper' ), [ 'status' => 400 ] );
				}
				unset( $data['inventory_number'] );
			} elseif ( '' === $data['inventory_number'] ) {
				unset( $data['inventory_number'] ); // leer = nächste freie Nummer
			} else {
				if ( strlen( $data['inventory_number'] ) > 40 || preg_match( '/\d{10,}$/', $data['inventory_number'] ) ) {
					return new WP_Error( 'pp_bad_number', __( 'Inventory number: at most 40 characters and at most 9 digits at the end.', 'project-prepper' ), [ 'status' => 400 ] );
				}
				if ( Inventory::get_item_by_number( $data['inventory_number'] ) ) {
					return new WP_Error( 'pp_number_taken', __( 'This inventory number is already in use.', 'project-prepper' ), [ 'status' => 409 ] );
				}
			}
		}
		return $data;
	}

	/** Ein eigener Artikel in der Form von GET /items/{id}, mit owner_name wie /me/items. */
	private function own_item( int $id ) {
		$item = Inventory::get_item( $id );
		if ( $item ) {
			$item->owner_name = (string) wp_get_current_user()->display_name;
		}
		return $item;
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
