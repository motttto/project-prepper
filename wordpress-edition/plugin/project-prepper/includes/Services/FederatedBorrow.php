<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use ProjectPrepper\Federation;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Föderiertes Leihen (Slice 4) — moderiertes Modell („Einfach & moderiert").
 *
 * EINGEHEND (diese Instanz = Anbieter): eine Partner-Instanz schickt für eines
 * ihrer Mitglieder eine Leih-Anfrage an unseren öffentlichen Endpoint. Vertrauen
 * entsteht NICHT über geteilte Schlüssel, sondern über:
 *   1. Opt-in: Föderation an UND `accept_borrows` an (beides default AUS).
 *   2. Trust-Gate: die `origin`-URL MUSS in unserer Partner-Liste stehen
 *      (gegenseitige Betreiber-Konfiguration).
 *   3. Mensch-in-der-Schleife: der Artikel-Eigentümer bestätigt JEDE Anfrage.
 *   4. Missbrauchsschutz: Rate-Limit pro Instanz/Tag, Honeypot, Pflicht-Kontakt,
 *      nur nutzbare (nicht broken/retired) Artikel.
 *
 * Die anfragende Instanz erhält ein unerratbares `request_token` und pollt damit
 * den Status (kein Callback nötig). Personenbezogen reist nur, was der Anfrager
 * selbst mitgibt (Name + Kontakt-E-Mail) — kein Konto, kein Login auf der
 * Anbieter-Instanz.
 *
 * AUSGEHEND (diese Instanz = Anfrager) folgt in Slice-4-Run-2.
 */
class FederatedBorrow {

	const STATUSES    = [ 'requested', 'approved', 'declined', 'cancelled' ];
	const MAX_PER_DAY = 20; // Eingehende Anfragen pro Partner-Instanz / Tag.

	/* ===================== Eingehend (Anbieter) ===================== */

	/**
	 * Eingehende föderierte Leih-Anfragen für die Artikel EINES Eigentümers
	 * (Moderations-Liste im Portal). Neueste zuerst.
	 *
	 * @return array<object>
	 */
	public static function inbound_for_owner( int $user_id ): array {
		global $wpdb;
		if ( ! $user_id ) {
			return [];
		}
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT fb.*, i.name AS item_name, i.inventory_number
			 FROM %i fb
			 JOIN %i i ON i.id = fb.item_id
			 WHERE i.owner_user_id = %d
			 ORDER BY fb.created_at DESC',
			Schema::table( 'fed_borrow_in' ),
			Schema::table( 'items' ),
			$user_id
		) ) ?: [];
	}

	/**
	 * Eingehende Anfrage anlegen (vom öffentlichen Endpoint aufgerufen).
	 *
	 * @param array $data origin, origin_name, item_id, requester_name,
	 *                    requester_contact, date_from, date_to, message, hp.
	 * @return array{status:string,token:string}|WP_Error
	 */
	public static function create_inbound( array $data ) {
		global $wpdb;

		if ( ! Federation::accept_borrows() ) {
			return new WP_Error( 'pp_fed_off', __( 'This instance does not accept federated borrow requests.', 'project-prepper' ), [ 'status' => 403 ] );
		}

		// Honeypot: gefülltes Feld = Bot → freundlich „ok" tun, aber verwerfen.
		if ( '' !== trim( (string) ( $data['hp'] ?? '' ) ) ) {
			return [ 'status' => 'requested', 'token' => wp_generate_password( 40, false ) ];
		}

		// Trust-Gate: origin muss eine konfigurierte Partner-Instanz sein.
		$origin = untrailingslashit( esc_url_raw( (string) ( $data['origin'] ?? '' ), [ 'http', 'https' ] ) );
		if ( '' === $origin || ! Federation::is_known_partner( $origin ) ) {
			return new WP_Error( 'pp_fed_origin', __( 'The requesting instance is not a configured partner.', 'project-prepper' ), [ 'status' => 403 ] );
		}

		// Rate-Limit pro Instanz/Tag.
		$rl_key = 'pp_fedrl_' . md5( $origin );
		$count  = (int) get_transient( $rl_key );
		if ( $count >= self::MAX_PER_DAY ) {
			return new WP_Error( 'pp_fed_rate', __( 'Too many requests from this instance today.', 'project-prepper' ), [ 'status' => 429 ] );
		}

		// Artikel muss existieren und nutzbar sein (kein broken/retired).
		$item_id = (int) ( $data['item_id'] ?? 0 );
		$item    = $item_id ? Inventory::get_item( $item_id ) : null;
		if ( ! $item || in_array( (string) $item->item_condition, [ 'broken', 'retired' ], true ) ) {
			return new WP_Error( 'pp_fed_item', __( 'The requested item is not available.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$name    = sanitize_text_field( (string) ( $data['requester_name'] ?? '' ) );
		$contact = sanitize_email( (string) ( $data['requester_contact'] ?? '' ) );
		if ( '' === $name || ! is_email( $contact ) ) {
			return new WP_Error( 'pp_fed_input', __( 'A name and a valid contact email are required.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$token = wp_generate_password( 40, false );
		$wpdb->insert( Schema::table( 'fed_borrow_in' ), [
			'origin_url'        => $origin,
			'origin_name'       => sanitize_text_field( (string) ( $data['origin_name'] ?? '' ) ),
			'item_id'           => $item_id,
			'requester_name'    => $name,
			'requester_contact' => $contact,
			'date_from'         => self::valid_date( (string) ( $data['date_from'] ?? '' ) ),
			'date_to'           => self::valid_date( (string) ( $data['date_to'] ?? '' ) ),
			'message'           => sanitize_textarea_field( (string) ( $data['message'] ?? '' ) ),
			'status'            => 'requested',
			'request_token'     => $token,
			'created_at'        => current_time( 'mysql' ),
		] );

		set_transient( $rl_key, $count + 1, DAY_IN_SECONDS );
		ActivityLog::log( 'fed_borrow_received', 'item', $item_id, [ 'origin' => $origin ] );

		return [ 'status' => 'requested', 'token' => $token ];
	}

	/**
	 * Status einer Anfrage über ihr Token (für das Polling der anfragenden
	 * Instanz). Unbekanntes Token → null.
	 */
	public static function status_for_token( string $token ): ?string {
		global $wpdb;
		$token = trim( $token );
		if ( '' === $token ) {
			return null;
		}
		$status = $wpdb->get_var( $wpdb->prepare(
			'SELECT status FROM %i WHERE request_token = %s',
			Schema::table( 'fed_borrow_in' ),
			$token
		) );
		return null === $status ? null : (string) $status;
	}

	/**
	 * Eingehende Anfrage entscheiden — NUR der Eigentümer des betroffenen
	 * Artikels, nur solange sie offen ist.
	 *
	 * @param string $decision approve|decline.
	 * @return true|WP_Error
	 */
	public static function decide( int $user_id, int $request_id, string $decision ) {
		global $wpdb;

		$map = [ 'approve' => 'approved', 'decline' => 'declined' ];
		if ( ! isset( $map[ $decision ] ) ) {
			return new WP_Error( 'pp_fed_decision', __( 'Invalid decision.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$req = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'fed_borrow_in' ),
			$request_id
		) );
		if ( ! $req ) {
			return new WP_Error( 'pp_not_found', __( 'Request not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( 'requested' !== $req->status ) {
			return new WP_Error( 'pp_fed_closed', __( 'This request has already been decided.', 'project-prepper' ), [ 'status' => 409 ] );
		}

		$item = Inventory::get_item( (int) $req->item_id );
		if ( ! $item || (int) $item->owner_user_id !== $user_id ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the item owner may decide on this request.', 'project-prepper' ), [ 'status' => 403 ] );
		}

		$wpdb->update(
			Schema::table( 'fed_borrow_in' ),
			[ 'status' => $map[ $decision ], 'decided_at' => current_time( 'mysql' ) ],
			[ 'id' => $request_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		ActivityLog::log( 'fed_borrow_decided', 'item', (int) $req->item_id, [ 'request_id' => $request_id, 'status' => $map[ $decision ] ] );
		return true;
	}

	private static function valid_date( string $date ): ?string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : null;
	}
}
