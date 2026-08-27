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

	const STATUSES    = [ 'requested', 'approved', 'declined', 'cancelled', 'returned' ];
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
		// Sets haben keinen eigenen Bestand (docs/07) — sie stehen deshalb gar nicht
		// erst im föderierten Katalog. Guard für den Fall eines veralteten Caches
		// oder einer manuell zusammengebauten Anfrage.
		if ( Bundles::is_bundle( $item_id ) ) {
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

		// Beim Genehmigen wird die Anfrage zur echten Ausleihe (Slice 5): erst prüfen,
		// dass im Zeitraum noch eine Einheit frei ist (lokale + föderierte Leihen).
		if ( 'approve' === $decision
			&& '' !== (string) $req->date_from && '' !== (string) $req->date_to
			&& Borrowing::available_units( (int) $req->item_id, (string) $req->date_from, (string) $req->date_to ) < 1 ) {
			return new WP_Error( 'pp_no_units', __( 'No units of this item are free in that period.', 'project-prepper' ), [ 'status' => 409 ] );
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

	/**
	 * Eine genehmigte föderierte Ausleihe als zurückgegeben markieren (Slice 5) —
	 * NUR der Artikel-Eigentümer. Gibt die Einheit wieder frei; die anfragende
	 * Instanz erfährt es beim nächsten Status-Polling.
	 *
	 * @return true|WP_Error
	 */
	public static function mark_returned( int $user_id, int $request_id ) {
		global $wpdb;

		$req = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'fed_borrow_in' ),
			$request_id
		) );
		if ( ! $req ) {
			return new WP_Error( 'pp_not_found', __( 'Request not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( 'approved' !== $req->status ) {
			return new WP_Error( 'pp_fed_state', __( 'Only an active loan can be returned.', 'project-prepper' ), [ 'status' => 409 ] );
		}
		$item = Inventory::get_item( (int) $req->item_id );
		if ( ! $item || (int) $item->owner_user_id !== $user_id ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the item owner may do this.', 'project-prepper' ), [ 'status' => 403 ] );
		}

		$wpdb->update(
			Schema::table( 'fed_borrow_in' ),
			[ 'status' => 'returned' ],
			[ 'id' => $request_id ],
			[ '%s' ],
			[ '%d' ]
		);
		ActivityLog::log( 'fed_borrow_returned', 'item', (int) $req->item_id, [ 'request_id' => $request_id ] );
		return true;
	}

	/* ===================== Ausgehend (Anfrager) ===================== */

	/**
	 * Föderierte Leih-Anfrage an eine Partner-Instanz schicken (vom Netzwerk-Tab).
	 *
	 * Outbound NUR an konfigurierte Partner-Instanzen. Schickt Name + Kontakt-
	 * E-Mail des anfragenden Mitglieds mit (kein Konto drüben). Bei Erfolg wird
	 * die ausgehende Zeile mit dem zurückgegebenen status_token gespeichert, mit
	 * dem wir später den Status pollen.
	 *
	 * @return true|WP_Error
	 */
	public static function request_outbound( int $user_id, array $data ) {
		global $wpdb;

		$partner = untrailingslashit( esc_url_raw( (string) ( $data['partner_url'] ?? '' ), [ 'http', 'https' ] ) );
		if ( '' === $partner || ! Federation::is_known_partner( $partner ) ) {
			return new WP_Error( 'pp_fed_partner', __( 'Unknown partner instance.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'pp_not_logged_in', __( 'Please sign in.', 'project-prepper' ), [ 'status' => 401 ] );
		}
		$item_id = (int) ( $data['item_id'] ?? 0 );
		if ( ! $item_id ) {
			return new WP_Error( 'pp_fed_item', __( 'The requested item is not available.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$from = self::valid_date( (string) ( $data['date_from'] ?? '' ) );
		$to   = self::valid_date( (string) ( $data['date_to'] ?? '' ) );
		$msg  = sanitize_textarea_field( (string) ( $data['message'] ?? '' ) );

		$resp = wp_remote_post( $partner . '/wp-json/project-prepper/v1/federation/borrow', [
			'timeout' => 8,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'origin'            => home_url( '/' ),
				'origin_name'       => get_bloginfo( 'name' ),
				'item_id'           => $item_id,
				'requester_name'    => $user->display_name,
				'requester_contact' => $user->user_email,
				'date_from'         => $from,
				'date_to'           => $to,
				'message'           => $msg,
			] ),
		] );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'pp_fed_unreachable', __( 'The partner instance could not be reached.', 'project-prepper' ), [ 'status' => 502 ] );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || empty( $json['ok'] ) || empty( $json['token'] ) ) {
			$message = ( is_array( $json ) && ! empty( $json['message'] ) )
				? (string) $json['message']
				: __( 'The partner instance declined the request.', 'project-prepper' );
			return new WP_Error( 'pp_fed_rejected', $message, [ 'status' => $code ?: 400 ] );
		}

		$wpdb->insert( Schema::table( 'fed_borrow_out' ), [
			'requester_id'    => $user_id,
			'partner_url'     => $partner,
			'item_label'      => sanitize_text_field( (string) ( $data['item_label'] ?? '' ) ),
			'item_detail_url' => esc_url_raw( (string) ( $data['item_detail_url'] ?? '' ), [ 'http', 'https' ] ),
			'date_from'       => $from,
			'date_to'         => $to,
			'message'         => $msg,
			'status'          => in_array( (string) $json['status'], self::STATUSES, true ) ? (string) $json['status'] : 'requested',
			'status_token'    => (string) $json['token'],
			'created_at'      => current_time( 'mysql' ),
		] );
		ActivityLog::log( 'fed_borrow_sent', 'item', $item_id, [ 'partner' => $partner ] );
		return true;
	}

	/**
	 * Ausgehende Anfragen eines Mitglieds — vorher (gedrosselt) den Status bei den
	 * Partnern auffrischen.
	 *
	 * @return array<object>
	 */
	public static function my_outbound( int $user_id ): array {
		global $wpdb;
		if ( ! $user_id ) {
			return [];
		}
		self::refresh_outbound( $user_id );
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE requester_id = %d ORDER BY created_at DESC',
			Schema::table( 'fed_borrow_out' ),
			$user_id
		) ) ?: [];
	}

	/**
	 * Status offener ausgehender Anfragen bei den Partner-Instanzen nachziehen.
	 * Pro User höchstens alle 30 s (Transient-Drossel), damit Seitenaufrufe keine
	 * Request-Lawine auslösen.
	 */
	public static function refresh_outbound( int $user_id ): void {
		global $wpdb;
		$guard = 'pp_fedpoll_' . $user_id;
		if ( get_transient( $guard ) ) {
			return;
		}
		set_transient( $guard, 1, 30 );

		// Auch bei 'approved' weiter pollen, damit eine spätere Rückgabe (Slice 5)
		// ankommt; nur abgeschlossene Endzustände werden nicht mehr abgefragt.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, partner_url, status_token, status FROM %i
			 WHERE requester_id = %d AND status NOT IN ('declined','cancelled','returned')",
			Schema::table( 'fed_borrow_out' ),
			$user_id
		) ) ?: [];

		foreach ( $rows as $r ) {
			$resp = wp_remote_get(
				untrailingslashit( (string) $r->partner_url ) . '/wp-json/project-prepper/v1/federation/borrow/status?token=' . rawurlencode( (string) $r->status_token ),
				[ 'timeout' => 5 ]
			);
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				continue;
			}
			$j = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( is_array( $j ) && ! empty( $j['status'] ) && (string) $j['status'] !== (string) $r->status && in_array( (string) $j['status'], self::STATUSES, true ) ) {
				$wpdb->update(
					Schema::table( 'fed_borrow_out' ),
					[ 'status' => (string) $j['status'] ],
					[ 'id' => (int) $r->id ],
					[ '%s' ],
					[ '%d' ]
				);
			}
		}
	}

	private static function valid_date( string $date ): ?string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : null;
	}
}
