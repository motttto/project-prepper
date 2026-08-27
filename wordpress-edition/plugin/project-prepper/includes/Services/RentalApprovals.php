<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Freigabe-Workflow für VERLEIH-Positionen (v0.41.0) — Schwester von
 * {@see BookingApprovals}, dieselbe Mechanik auf `pp_rental_items`.
 *
 * Seit dem Kollektiv-Verleih darf ein Mitglied im Gruppen-Arbeitsbereich auch
 * fremdes Equipment aus dem geteilten Pool extern verleihen. Trägt die Freigabe
 * des Eigentümers `requires_approval`, entsteht die Position mit
 * `approval_status = 'pending'` — freigeben oder ablehnen darf ausschließlich
 * der EIGENTÜMER des Artikels (`items.owner_user_id`).
 *
 * Bewusst eine eigene Klasse statt einer Verallgemeinerung von BookingApprovals:
 * Die Abfragen hängen an unterschiedlichen Tabellen (project_items ↔ rental_items)
 * und an unterschiedlichem Kontext (Projekt ↔ Verleih mit Leiher/Geld). Eine
 * gemeinsame Abstraktion hätte beide Seiten mit Sonderfällen belastet, ohne dass
 * echte Logik geteilt würde.
 *
 * Sicherheit: jede mutierende Methode prüft selbst, dass die Position zu einem
 * Artikel gehört, dessen Eigentümer der aufrufende User ist (IDOR-sicher).
 * Verfügbarkeit: pending-Positionen zählen weiter gegen die Verfügbarkeit
 * ({@see Availability} zählt alle rental_items überlappender Verleihe) — der Slot
 * ist gehalten, bis abgelehnt wird.
 */
class RentalApprovals {

	/**
	 * Offene Freigabe-Anfragen für Artikel, die $owner_id gehören: je Position
	 * Verleih + Anfrager + Leiher + Menge + Zeitraum + Tagessatz.
	 *
	 * @return array<object>
	 */
	public static function pending_for_owner( int $owner_id ): array {
		global $wpdb;
		if ( $owner_id <= 0 ) {
			return [];
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ri.id AS line_id, ri.quantity, ri.daily_rate, ri.requested_by, ri.approval_status,
			        r.id AS rental_id, r.rental_number, r.borrower_name, r.date_from, r.date_to, r.status AS rental_status,
			        i.id AS item_id, i.name AS item_name, i.inventory_number,
			        b.name AS bundle_name
			 FROM %i ri
			 JOIN %i r ON r.id = ri.rental_id
			 JOIN %i i ON i.id = ri.item_id
			 LEFT JOIN %i b ON b.id = ri.bundle_item_id
			 WHERE ri.approval_status = 'pending'
			   AND i.owner_user_id = %d
			   AND r.status IN ('reserved', 'active')
			 ORDER BY ri.id DESC",
			Schema::table( 'rental_items' ),
			Schema::table( 'rentals' ),
			Schema::table( 'items' ),
			Schema::table( 'items' ),
			$owner_id
		) ) ?: [];
		foreach ( $rows as $row ) {
			$requester           = $row->requested_by ? get_userdata( (int) $row->requested_by ) : null;
			$row->requester_name = $requester ? $requester->display_name : '';
		}
		return $rows;
	}

	/** Anzahl offener Verleih-Freigaben für die Artikel von $owner_id (Badge). */
	public static function pending_count_for_owner( int $owner_id ): int {
		global $wpdb;
		if ( $owner_id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			 FROM %i ri
			 JOIN %i r ON r.id = ri.rental_id
			 JOIN %i i ON i.id = ri.item_id
			 WHERE ri.approval_status = 'pending'
			   AND i.owner_user_id = %d
			   AND r.status IN ('reserved', 'active')",
			Schema::table( 'rental_items' ),
			Schema::table( 'rentals' ),
			Schema::table( 'items' ),
			$owner_id
		) );
	}

	/**
	 * Freigeben — Position bleibt, Status → approved.
	 *
	 * @return array|WP_Error Kontext für die Info-Mail an den Anfrager.
	 */
	public static function approve( int $owner_id, int $line_id ) {
		global $wpdb;
		$line = self::owned_pending_line( $owner_id, $line_id );
		if ( is_wp_error( $line ) ) {
			return $line;
		}
		$wpdb->update(
			Schema::table( 'rental_items' ),
			[ 'approval_status' => 'approved', 'decided_at' => current_time( 'mysql' ) ],
			[ 'id' => $line_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		ActivityLog::log( 'rental_line_approved', 'rental', (int) $line->rental_id, [ 'line_id' => $line_id, 'item_id' => (int) $line->item_id ] );
		return self::decision_context( $line );
	}

	/**
	 * Ablehnen — die Position wird ENTFERNT (der Slot wird frei, der Anfrager
	 * informiert). Ein Verleih ohne Positionen bleibt bestehen; er lässt sich
	 * bearbeiten oder stornieren.
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
		$wpdb->delete( Schema::table( 'rental_items' ), [ 'id' => $line_id ], [ '%d' ] );
		ActivityLog::log( 'rental_line_rejected', 'rental', (int) $line->rental_id, [ 'line_id' => $line_id, 'item_id' => (int) $line->item_id ] );
		return $context;
	}

	/**
	 * Kontext einer Position für die Anfrage-Mail an den Eigentümer (die Zeile
	 * existiert zum Anfrage-Zeitpunkt).
	 */
	public static function get_line_context( int $line_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT ri.id AS line_id, ri.quantity, ri.daily_rate, ri.requested_by,
			        r.id AS rental_id, r.rental_number, r.borrower_name, r.date_from, r.date_to,
			        i.id AS item_id, i.name AS item_name, i.owner_user_id
			 FROM %i ri
			 JOIN %i r ON r.id = ri.rental_id
			 JOIN %i i ON i.id = ri.item_id
			 WHERE ri.id = %d",
			Schema::table( 'rental_items' ),
			Schema::table( 'rentals' ),
			Schema::table( 'items' ),
			$line_id
		) );
	}

	/**
	 * Offene Positionen eines Verleihs nach Eigentümer gruppiert — Grundlage für
	 * EINE Sammel-Anfrage je Eigentümer (statt einer Mail pro Gerät), wie bei den
	 * Projekt-Buchungen.
	 *
	 * @return array<int,array<int>> owner_id => [ line_id, … ]
	 */
	public static function pending_lines_by_owner( int $rental_id ): array {
		global $wpdb;
		if ( $rental_id <= 0 ) {
			return [];
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ri.id AS line_id, i.owner_user_id
			 FROM %i ri
			 JOIN %i i ON i.id = ri.item_id
			 WHERE ri.rental_id = %d AND ri.approval_status = 'pending'",
			Schema::table( 'rental_items' ),
			Schema::table( 'items' ),
			$rental_id
		) ) ?: [];
		$out = [];
		foreach ( $rows as $row ) {
			$owner = (int) $row->owner_user_id;
			if ( $owner > 0 ) {
				$out[ $owner ][] = (int) $row->line_id;
			}
		}
		return $out;
	}

	/**
	 * Flache Liste der offenen Positions-IDs eines Verleihs — um vor/nach dem
	 * Speichern zu vergleichen, welche Freigaben NEU entstanden sind (nur für die
	 * wird gefragt).
	 *
	 * @return array<int>
	 */
	public static function pending_line_ids( int $rental_id ): array {
		$out = [];
		foreach ( self::pending_lines_by_owner( $rental_id ) as $lines ) {
			foreach ( $lines as $line_id ) {
				$out[] = (int) $line_id;
			}
		}
		return $out;
	}

	/**
	 * Hat dieser Verleih noch offene Freigaben? Solange ja, darf er nicht auf
	 * „ausgegeben" wechseln — sonst würde fremdes Equipment das Haus verlassen,
	 * bevor sein Eigentümer zugestimmt hat.
	 */
	public static function has_pending( int $rental_id ): bool {
		global $wpdb;
		if ( $rental_id <= 0 ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE rental_id = %d AND approval_status = 'pending'",
			Schema::table( 'rental_items' ),
			$rental_id
		) );
	}

	/**
	 * Position laden + Gate: gehört sie einem Artikel des Eigentümers und ist sie
	 * noch offen?
	 *
	 * @return object|WP_Error
	 */
	private static function owned_pending_line( int $owner_id, int $line_id ) {
		global $wpdb;
		if ( $owner_id <= 0 || $line_id <= 0 ) {
			return new WP_Error( 'pp_forbidden', __( 'This request is not available.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$line = $wpdb->get_row( $wpdb->prepare(
			'SELECT ri.id, ri.rental_id, ri.item_id, ri.quantity, ri.requested_by, ri.approval_status,
			        i.owner_user_id, i.name AS item_name,
			        r.rental_number, r.borrower_name
			 FROM %i ri
			 JOIN %i i ON i.id = ri.item_id
			 JOIN %i r ON r.id = ri.rental_id
			 WHERE ri.id = %d',
			Schema::table( 'rental_items' ),
			Schema::table( 'items' ),
			Schema::table( 'rentals' ),
			$line_id
		) );
		if ( ! $line ) {
			return new WP_Error( 'pp_not_found', __( 'Rental line not found.', 'project-prepper' ), [ 'status' => 404 ] );
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
			'requester_id'   => (int) $line->requested_by,
			'item_name'      => (string) $line->item_name,
			'rental_id'      => (int) $line->rental_id,
			'rental_number'  => (string) $line->rental_number,
			'borrower_name'  => (string) $line->borrower_name,
		];
	}
}
