<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Leih-Anfragen zwischen Kollektiv-Mitgliedern (Member-Portal Phase 4).
 *
 * Nichtkommerziell: ein Mitglied fragt ein im Kollektiv **geteiltes** Item für
 * einen Zeitraum an, der **Eigentümer** entscheidet (annehmen/ablehnen). Keine
 * Gebühren. Guards (RLS-Ersatz): anfragen nur als Gruppenmitglied auf ein dort
 * geteiltes Item (nicht das eigene); entscheiden nur der Eigentümer.
 *
 * status: requested → approved | declined | cancelled → returned.
 */
class Borrowing {

	/* ===================== Stöbern ===================== */

	/**
	 * Im Kollektiv geteilte Items (Katalog). Aufrufer prüft Mitgliedschaft.
	 *
	 * @return array<object>
	 */
	public static function browse( int $group_id ): array {
		return MemberInventory::items_shared_with_group( $group_id );
	}

	/* ===================== Anfragen ===================== */

	/**
	 * Leih-Anfrage stellen.
	 *
	 * @return int|WP_Error Request-ID.
	 */
	public static function request( int $user_id, int $item_id, int $group_id, string $from, string $to, string $message = '' ) {
		global $wpdb;

		if ( ! $user_id || ! current_user_can( Capabilities::COLLECTIVES ) ) {
			return new WP_Error( 'pp_forbidden', __( 'You are not allowed to borrow.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( ! Groups::is_member( $group_id, $user_id ) ) {
			return new WP_Error( 'pp_not_member', __( 'You can only borrow within your own collectives.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		// Item muss in dieser Gruppe geteilt sein.
		$share = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE item_id = %d AND group_id = %d',
			Schema::table( 'item_group_shares' ),
			$item_id,
			$group_id
		) );
		if ( ! $share ) {
			return new WP_Error( 'pp_not_shared', __( 'This item is not shared with that collective.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$item = Inventory::get_item( $item_id );
		if ( ! $item ) {
			return new WP_Error( 'pp_not_found', __( 'Item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$owner_id = (int) ( $item->owner_user_id ?? 0 );
		if ( $owner_id === $user_id ) {
			return new WP_Error( 'pp_own_item', __( 'This is your own item.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$from = self::valid_date( $from );
		$to   = self::valid_date( $to );
		if ( ! $from || ! $to || $to < $from ) {
			return new WP_Error( 'pp_bad_dates', __( 'Please choose a valid period.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		// Verfügbarkeit schon beim Anfragen prüfen — keine aussichtslosen Anfragen,
		// die der Eigentümer ohnehin nicht genehmigen könnte.
		if ( self::available_units( $item_id, $from, $to ) < 1 ) {
			return new WP_Error( 'pp_no_units', __( 'No units of this item are free in that period. Please pick other dates.', 'project-prepper' ), [ 'status' => 409 ] );
		}

		$wpdb->insert( Schema::table( 'borrow_requests' ), [
			'item_id'      => $item_id,
			'group_id'     => $group_id,
			'requester_id' => $user_id,
			'owner_id'     => $owner_id ?: null,
			'date_from'    => $from,
			'date_to'      => $to,
			'message'      => $message,
			'status'       => 'requested',
			'created_at'   => current_time( 'mysql' ),
		] );
		$id = (int) $wpdb->insert_id;
		ActivityLog::log( 'borrow_requested', 'item', $item_id, [ 'request_id' => $id, 'group_id' => $group_id ] );
		do_action( 'pp_borrow_requested', $id );
		return $id;
	}

	/**
	 * Verfügbare Einheiten eines Items in einem Zeitraum: Menge minus überlappende
	 * genehmigte Leihen (eine Anfrage = eine Einheit). $exclude lässt die eigene
	 * Anfrage außen vor.
	 */
	public static function available_units( int $item_id, string $from, string $to, int $exclude = 0 ): int {
		global $wpdb;
		$item = Inventory::get_item( $item_id );
		if ( ! $item ) {
			return 0;
		}
		$qty  = max( 1, (int) $item->quantity );
		// Lokale genehmigte Leihen im Zeitraum (eine Anfrage = eine Einheit).
		$used = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i
			 WHERE item_id = %d AND status = 'approved' AND id <> %d
			   AND date_from <= %s AND date_to >= %s",
			Schema::table( 'borrow_requests' ),
			$item_id,
			$exclude,
			$to,
			$from
		) );
		// Föderierte genehmigte Ausleihen (Slice 5): eine genehmigte Anfrage einer
		// Partner-Instanz hält ebenfalls eine Einheit, bis sie zurückgegeben ist.
		$used += (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i
			 WHERE item_id = %d AND status = 'approved'
			   AND date_from <= %s AND date_to >= %s",
			Schema::table( 'fed_borrow_in' ),
			$item_id,
			$to,
			$from
		) );
		return max( 0, $qty - $used );
	}

	/** Eigentümer nimmt an. @return true|WP_Error */
	public static function approve( int $user_id, int $request_id ) {
		return self::decide( $user_id, $request_id, 'approved' );
	}

	/** Eigentümer lehnt ab. @return true|WP_Error */
	public static function decline( int $user_id, int $request_id ) {
		return self::decide( $user_id, $request_id, 'declined' );
	}

	/** Eigentümer entscheidet über eine offene Anfrage. */
	private static function decide( int $user_id, int $request_id, string $status ) {
		global $wpdb;
		$req = self::get( $request_id );
		if ( ! $req ) {
			return new WP_Error( 'pp_not_found', __( 'Request not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( (int) $req->owner_id !== $user_id ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the item owner can decide this request.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( 'requested' !== $req->status ) {
			return new WP_Error( 'pp_bad_state', __( 'This request is already resolved.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		// Beim Genehmigen Verfügbarkeit prüfen (keine Überbuchung über die Menge hinaus).
		if ( 'approved' === $status
			&& self::available_units( (int) $req->item_id, (string) $req->date_from, (string) $req->date_to, $request_id ) < 1 ) {
			return new WP_Error( 'pp_no_units', __( 'No units of this item are free in that period.', 'project-prepper' ), [ 'status' => 409 ] );
		}
		$wpdb->update(
			Schema::table( 'borrow_requests' ),
			[ 'status' => $status, 'decided_at' => current_time( 'mysql' ), 'decided_by' => $user_id ],
			[ 'id' => $request_id ],
			[ '%s', '%s', '%d' ],
			[ '%d' ]
		);
		ActivityLog::log( 'borrow_' . $status, 'item', (int) $req->item_id, [ 'request_id' => $request_id ] );
		do_action( 'pp_borrow_decided', $request_id, $status );
		return true;
	}

	/** Anfragender bricht eine eigene offene Anfrage ab. @return true|WP_Error */
	public static function cancel( int $user_id, int $request_id ) {
		global $wpdb;
		$req = self::get( $request_id );
		if ( ! $req ) {
			return new WP_Error( 'pp_not_found', __( 'Request not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( (int) $req->requester_id !== $user_id ) {
			return new WP_Error( 'pp_forbidden', __( 'You can only cancel your own requests.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( 'requested' !== $req->status ) {
			return new WP_Error( 'pp_bad_state', __( 'This request is already resolved.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$wpdb->update( Schema::table( 'borrow_requests' ), [ 'status' => 'cancelled' ], [ 'id' => $request_id ], [ '%s' ], [ '%d' ] );
		return true;
	}

	/** Als zurückgegeben markieren (Eigentümer ODER Anfragender). @return true|WP_Error */
	public static function mark_returned( int $user_id, int $request_id ) {
		global $wpdb;
		$req = self::get( $request_id );
		if ( ! $req ) {
			return new WP_Error( 'pp_not_found', __( 'Request not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( (int) $req->owner_id !== $user_id && (int) $req->requester_id !== $user_id ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the owner or borrower can do this.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( 'approved' !== $req->status ) {
			return new WP_Error( 'pp_bad_state', __( 'Only an active loan can be returned.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$wpdb->update( Schema::table( 'borrow_requests' ), [ 'status' => 'returned' ], [ 'id' => $request_id ], [ '%s' ], [ '%d' ] );
		ActivityLog::log( 'borrow_returned', 'item', (int) $req->item_id, [ 'request_id' => $request_id ] );
		return true;
	}

	/* ===================== Lesen (fürs Portal) ===================== */

	/** Anfragen, die der User gestellt hat (als Leiher). @return array<object> */
	public static function my_requests( int $user_id ): array {
		return self::query( 'requester_id', $user_id );
	}

	/** Anfragen auf den Items des Users (als Eigentümer). @return array<object> */
	public static function incoming_requests( int $user_id ): array {
		return self::query( 'owner_id', $user_id );
	}

	/**
	 * „Mein Equipment unterwegs" (App: §9.3): alle eigenen Artikel, die aktuell
	 * unterwegs sind — kollektiv-interne, genehmigte Leihen (borrow_requests,
	 * Status approved) PLUS externe Verleihe (rentals reserved/active), die eine
	 * Position mit einem eigenen Artikel enthalten. Liefert eine einheitliche,
	 * nach Enddatum sortierte Liste mit: Artikel, Inv.-Nr., Menge, Status, an wen,
	 * Zeitraum, Gebühr (nur bei externem Verleih ableitbar; Kollektiv-Leihe ist
	 * nichtkommerziell → keine Gebühr).
	 *
	 * @return array<object> Zeilen mit ->kind ('borrow'|'rental'), ->item_name,
	 *   ->inventory_number, ->quantity, ->status, ->status_label, ->to_name,
	 *   ->date_from, ->date_to, ->days, ->fee (float|null).
	 */
	public static function equipment_out( int $user_id ): array {
		global $wpdb;
		if ( ! $user_id ) {
			return [];
		}
		$out = [];

		// 1) Kollektiv-interne genehmigte Leihen auf eigene Artikel.
		foreach ( self::incoming_requests( $user_id ) as $r ) {
			if ( 'approved' !== $r->status ) {
				continue;
			}
			$from = (string) $r->date_from;
			$to   = (string) $r->date_to;
			$days = max( 1, (int) ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1 );
			$out[] = (object) [
				'kind'             => 'borrow',
				'item_name'        => (string) $r->item_name,
				'inventory_number' => (string) $r->inventory_number,
				'quantity'         => 1,
				'status'           => 'approved',
				'status_label'     => __( 'Lent out', 'project-prepper' ),
				'to_name'          => (string) ( $r->counterpart_name ?? '' ),
				'context'          => (string) ( $r->group_name ?? '' ),
				'date_from'        => $from,
				'date_to'          => $to,
				'days'             => $days,
				'fee'              => null, // nichtkommerziell
			];
		}

		// 2) Externe Verleihe (reserved/active) mit Positionen auf eigene Artikel.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.id AS rental_id, r.borrower_name, r.date_from, r.date_to, r.status,
			        r.rental_fee, ri.quantity, ri.daily_rate, i.name AS item_name, i.inventory_number
			 FROM %i ri
			 JOIN %i i ON i.id = ri.item_id
			 JOIN %i r ON r.id = ri.rental_id
			 WHERE i.owner_user_id = %d AND r.status IN ('reserved','active')
			 ORDER BY r.date_to DESC",
			Schema::table( 'rental_items' ),
			Schema::table( 'items' ),
			Schema::table( 'rentals' ),
			$user_id
		) ) ?: [];
		foreach ( $rows as $r ) {
			$from = (string) $r->date_from;
			$to   = (string) $r->date_to;
			$days = max( 1, (int) ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1 );
			// Positions-Gebühr nur ableitbar, wenn ein Tagessatz auf der Position steht.
			$fee = ( null !== $r->daily_rate && '' !== $r->daily_rate )
				? (float) $r->daily_rate * $days * max( 1, (int) $r->quantity )
				: null;
			$out[] = (object) [
				'kind'             => 'rental',
				'item_name'        => (string) $r->item_name,
				'inventory_number' => (string) $r->inventory_number,
				'quantity'         => max( 1, (int) $r->quantity ),
				'status'           => (string) $r->status,
				'status_label'     => 'active' === $r->status ? __( 'Active', 'project-prepper' ) : __( 'Reserved', 'project-prepper' ),
				'to_name'          => (string) $r->borrower_name,
				'context'          => __( 'External rental', 'project-prepper' ),
				'date_from'        => $from,
				'date_to'          => $to,
				'days'             => $days,
				'fee'              => $fee,
			];
		}

		usort( $out, static fn( $a, $b ) => strcmp( (string) $b->date_to, (string) $a->date_to ) );
		return $out;
	}

	private static function query( string $column, int $user_id ): array {
		global $wpdb;
		if ( ! $user_id ) {
			return [];
		}
		// $column auf zwei feste, sichere Spaltennamen begrenzen (kein User-Input).
		$col = 'owner_id' === $column ? 'owner_id' : 'requester_id';
		$sql = "SELECT b.*, i.name AS item_name, i.inventory_number, g.name AS group_name
			 FROM %i b
			 JOIN %i i ON i.id = b.item_id
			 LEFT JOIN %i g ON g.id = b.group_id
			 WHERE b.{$col} = %d
			 ORDER BY b.created_at DESC"; // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $col ist ein fester Spaltenname aus einer Whitelist, kein User-Input.
		$rows = $wpdb->get_results( $wpdb->prepare(
			$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql enthält nur %i/%d-Platzhalter + whitelisteten Spaltennamen.
			Schema::table( 'borrow_requests' ),
			Schema::table( 'items' ),
			Schema::table( 'groups' ),
			$user_id
		) ) ?: [];
		foreach ( $rows as $row ) {
			$other                 = get_userdata( (int) ( 'owner_id' === $column ? $row->requester_id : $row->owner_id ) );
			$row->counterpart_name = $other ? $other->display_name : '';
		}
		return $rows;
	}

	/**
	 * Letzte Leih-Anfragen plattformweit (Backend-Übersicht/Moderation).
	 *
	 * @return array<object>
	 */
	public static function all_recent( int $limit = 30 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT b.*, i.name AS item_name, g.name AS group_name
			 FROM %i b
			 JOIN %i i ON i.id = b.item_id
			 LEFT JOIN %i g ON g.id = b.group_id
			 ORDER BY b.created_at DESC
			 LIMIT %d',
			Schema::table( 'borrow_requests' ),
			Schema::table( 'items' ),
			Schema::table( 'groups' ),
			$limit
		) ) ?: [];
		foreach ( $rows as $row ) {
			$o               = get_userdata( (int) $row->owner_id );
			$r               = get_userdata( (int) $row->requester_id );
			$row->owner_name = $o ? $o->display_name : '';
			$row->requester_name = $r ? $r->display_name : '';
		}
		return $rows;
	}

	public static function get( int $request_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'borrow_requests' ), $request_id ) ) ?: null;
	}

	private static function valid_date( string $value ): string {
		$value = sanitize_text_field( $value );
		$d     = \DateTime::createFromFormat( 'Y-m-d', $value );
		return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $value : '';
	}
}
