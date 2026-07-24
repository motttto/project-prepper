<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Freigabe-Workflow für Technik-Buchungen (Member-Portal) — Pendant zum
 * App-Hook `use-booking-approvals.ts` (`bookings.approval_status`).
 *
 * Wenn ein Mitglied einen mit dem Kollektiv geteilten Artikel bucht, der einem
 * ANDEREN Mitglied gehört und dessen Freigabe `requires_approval` trägt, wird die
 * Buchungszeile (`pp_project_items`) mit `approval_status = 'pending'` angelegt.
 * Nur der EIGENTÜMER des Artikels (items.owner_user_id) darf sie freigeben oder
 * ablehnen — dieser Service kapselt die Abfragen + Gates (RLS-Ersatz).
 *
 * Sicherheit: jede mutierende Methode prüft selbst, dass die Buchungszeile zu
 * einem Artikel gehört, dessen Eigentümer der aufrufende User ist (IDOR-sicher).
 * Verfügbarkeit: pending-Zeilen zählen weiter gegen die Verfügbarkeit (Availability
 * zählt alle project_items-Zeilen), damit der Slot gehalten wird; erst ein
 * Ablehnen entfernt die Zeile und gibt den Slot frei.
 */
class BookingApprovals {

	/**
	 * Offene Freigabe-Anfragen für Artikel, die $owner_id gehören. Liefert je
	 * Anfrage Projekt + Anfrager + Menge + effektiven Zeitraum + die eigenen
	 * Bedingungen der Freigabe (Tagessatz/Tags/Text, aus der Gruppen-Share des
	 * Projekt-Kollektivs).
	 *
	 * @return array<object>
	 */
	public static function pending_for_owner( int $owner_id ): array {
		global $wpdb;
		if ( $owner_id <= 0 ) {
			return [];
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pi.id AS line_id, pi.quantity, pi.notes, pi.requested_by, pi.approval_status,
			        COALESCE(pi.date_from, p.date_start) AS date_from_eff,
			        COALESCE(pi.date_to, p.date_end) AS date_to_eff,
			        i.id AS item_id, i.name AS item_name, i.inventory_number,
			        p.id AS project_id, p.name AS project_name, p.owner_group_id,
			        s.daily_rate AS share_daily_rate, s.conditions_tags, s.conditions
			 FROM %i pi
			 JOIN %i i ON i.id = pi.item_id
			 JOIN %i p ON p.id = pi.project_id
			 LEFT JOIN %i s ON s.item_id = pi.item_id AND s.group_id = p.owner_group_id
			 WHERE pi.approval_status = 'pending' AND i.owner_user_id = %d
			 ORDER BY pi.id DESC",
			Schema::table( 'project_items' ),
			Schema::table( 'items' ),
			Schema::table( 'projects' ),
			Schema::table( 'item_group_shares' ),
			$owner_id
		) ) ?: [];
		foreach ( $rows as $row ) {
			$requester           = $row->requested_by ? get_userdata( (int) $row->requested_by ) : null;
			$row->requester_name = $requester ? $requester->display_name : '';
			$tags                = json_decode( (string) $row->conditions_tags, true );
			$row->conditions_tags = is_array( $tags ) ? $tags : [];
		}
		return $rows;
	}

	/** Anzahl offener Freigabe-Anfragen für die Artikel von $owner_id (Glocke/Badge). */
	public static function pending_count_for_owner( int $owner_id ): int {
		global $wpdb;
		if ( $owner_id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			 FROM %i pi
			 JOIN %i i ON i.id = pi.item_id
			 WHERE pi.approval_status = 'pending' AND i.owner_user_id = %d",
			Schema::table( 'project_items' ),
			Schema::table( 'items' ),
			$owner_id
		) );
	}

	/**
	 * Freigabe-Anfrage annehmen — Zeile bleibt bestehen, Status → approved.
	 * Gate: nur der Eigentümer des Artikels, nur solange pending.
	 *
	 * @return array|WP_Error Kontext (requester_id/item_name/project_name/project_id) für die Info-Mail.
	 */
	public static function approve( int $owner_id, int $line_id ) {
		global $wpdb;
		$line = self::owned_pending_line( $owner_id, $line_id );
		if ( is_wp_error( $line ) ) {
			return $line;
		}
		$wpdb->update(
			Schema::table( 'project_items' ),
			[ 'approval_status' => 'approved', 'decided_at' => current_time( 'mysql' ) ],
			[ 'id' => $line_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		ActivityLog::log( 'booking_approved', 'project', (int) $line->project_id, [ 'line_id' => $line_id, 'item_id' => (int) $line->item_id ] );
		return self::decision_context( $line );
	}

	/**
	 * Freigabe-Anfrage ablehnen — die Buchungszeile wird ENTFERNT, damit der Slot
	 * frei wird (der Anfrager wird benachrichtigt). Gate: nur der Eigentümer.
	 *
	 * @return array|WP_Error Kontext für die Info-Mail.
	 */
	public static function reject( int $owner_id, int $line_id ) {
		global $wpdb;
		$line = self::owned_pending_line( $owner_id, $line_id );
		if ( is_wp_error( $line ) ) {
			return $line;
		}
		$context = self::decision_context( $line );
		$wpdb->delete( Schema::table( 'project_items' ), [ 'id' => $line_id ], [ '%d' ] );
		ActivityLog::log( 'booking_rejected', 'project', (int) $line->project_id, [ 'line_id' => $line_id, 'item_id' => (int) $line->item_id ] );
		return $context;
	}

	/**
	 * Kontext einer Buchungszeile für die Freigabe-Anfrage-Mail an den Eigentümer.
	 * Wird von der E-Mail-Schicht genutzt (Zeile existiert zum Anfrage-Zeitpunkt).
	 *
	 * @return object|null
	 */
	public static function get_line_context( int $line_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT pi.id AS line_id, pi.quantity, pi.notes, pi.requested_by,
			        COALESCE(pi.date_from, p.date_start) AS date_from_eff,
			        COALESCE(pi.date_to, p.date_end) AS date_to_eff,
			        i.id AS item_id, i.name AS item_name, i.owner_user_id,
			        p.id AS project_id, p.name AS project_name, p.owner_group_id,
			        s.daily_rate AS share_daily_rate, s.conditions_tags, s.conditions
			 FROM %i pi
			 JOIN %i i ON i.id = pi.item_id
			 JOIN %i p ON p.id = pi.project_id
			 LEFT JOIN %i s ON s.item_id = pi.item_id AND s.group_id = p.owner_group_id
			 WHERE pi.id = %d",
			Schema::table( 'project_items' ),
			Schema::table( 'items' ),
			Schema::table( 'projects' ),
			Schema::table( 'item_group_shares' ),
			$line_id
		) );
		if ( $row ) {
			$tags                 = json_decode( (string) $row->conditions_tags, true );
			$row->conditions_tags = is_array( $tags ) ? $tags : [];
		}
		return $row;
	}

	/**
	 * Buchungszeile laden + Gate: gehört sie zu einem Artikel des Eigentümers und
	 * ist sie noch pending? Sonst WP_Error (nicht gefunden / fremd / schon entschieden).
	 *
	 * @return object|WP_Error
	 */
	private static function owned_pending_line( int $owner_id, int $line_id ) {
		global $wpdb;
		if ( $owner_id <= 0 || $line_id <= 0 ) {
			return new WP_Error( 'pp_forbidden', __( 'This request is not available.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$line = $wpdb->get_row( $wpdb->prepare(
			'SELECT pi.id, pi.project_id, pi.item_id, pi.quantity, pi.requested_by, pi.approval_status,
			        i.owner_user_id, i.name AS item_name, p.name AS project_name
			 FROM %i pi
			 JOIN %i i ON i.id = pi.item_id
			 JOIN %i p ON p.id = pi.project_id
			 WHERE pi.id = %d',
			Schema::table( 'project_items' ),
			Schema::table( 'items' ),
			Schema::table( 'projects' ),
			$line_id
		) );
		if ( ! $line ) {
			return new WP_Error( 'pp_not_found', __( 'Booking not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( (int) $line->owner_user_id !== $owner_id ) {
			// Fremder Artikel — kein Zugriff (IDOR-Schutz).
			return new WP_Error( 'pp_forbidden', __( 'Only the item owner can decide on this request.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( 'pending' !== (string) $line->approval_status ) {
			return new WP_Error( 'pp_not_pending', __( 'This request has already been decided.', 'project-prepper' ), [ 'status' => 409 ] );
		}
		return $line;
	}

	/** @return array Kontext für die Entscheidungs-Mail an den Anfrager. */
	private static function decision_context( object $line ): array {
		return [
			'requester_id' => (int) $line->requested_by,
			'item_name'    => (string) $line->item_name,
			'project_id'   => (int) $line->project_id,
			'project_name' => (string) $line->project_name,
		];
	}
}
