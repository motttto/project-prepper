<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Verfügbarkeitsprüfung — Pendant zu check_inventory_availability() der App
 * (dort über Bookings + Rentals, hier über Verleihe + Projekt-Buchungen).
 *
 * Verfügbar = Artikel-Menge
 *   − Σ Positionen überlappender Verleihe mit Status reserved/active
 *   − Σ Buchungen aus Projekten mit Status confirmed/running, deren effektiver
 *     Zeitraum (Zeilen-Datum, sonst geerbt vom Projekt) überlappt.
 *
 * Buchungen ohne bestimmbaren Zeitraum (Zeile UND Projekt ohne Termine)
 * blockieren nichts.
 */
class Availability {

	/**
	 * @param int    $item_id            Artikel.
	 * @param string $from               Y-m-d.
	 * @param string $to                 Y-m-d.
	 * @param int    $exclude_rental_id  Eigener Verleih beim Bearbeiten ausnehmen.
	 * @param int    $exclude_project_id Eigenes Projekt beim Bearbeiten von Buchungszeilen ausnehmen.
	 */
	public static function available_quantity( int $item_id, string $from, string $to, int $exclude_rental_id = 0, int $exclude_project_id = 0 ): int {
		global $wpdb;

		$item = Inventory::get_item( $item_id );
		if ( ! $item ) {
			return 0;
		}

		$rented = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(ri.quantity), 0)
			 FROM %i ri
			 INNER JOIN %i r ON r.id = ri.rental_id
			 WHERE ri.item_id = %d
			   AND r.id != %d
			   AND r.status IN ('reserved', 'active')
			   AND r.date_from <= %s
			   AND r.date_to >= %s",
			Schema::table( 'rental_items' ),
			Schema::table( 'rentals' ),
			$item_id,
			$exclude_rental_id,
			$to,
			$from
		) );

		// Projekt-Buchungen: nur confirmed/running blockieren; effektiver Zeitraum
		// = Zeilen-Datum mit Fallback auf den Projekt-Zeitraum (COALESCE).
		$booked = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(pi.quantity), 0)
			 FROM %i pi
			 INNER JOIN %i p ON p.id = pi.project_id
			 WHERE pi.item_id = %d
			   AND p.id != %d
			   AND p.status IN ('confirmed', 'running')
			   AND COALESCE(pi.date_from, p.date_start) IS NOT NULL
			   AND COALESCE(pi.date_to, p.date_end) IS NOT NULL
			   AND COALESCE(pi.date_from, p.date_start) <= %s
			   AND COALESCE(pi.date_to, p.date_end) >= %s",
			Schema::table( 'project_items' ),
			Schema::table( 'projects' ),
			$item_id,
			$exclude_project_id,
			$to,
			$from
		) );

		return max( 0, (int) $item->quantity - $rented - $booked );
	}

	public static function is_valid_range( string $from, string $to ): bool {
		$d_from = \DateTime::createFromFormat( 'Y-m-d', $from );
		$d_to   = \DateTime::createFromFormat( 'Y-m-d', $to );
		return $d_from && $d_to
			&& $d_from->format( 'Y-m-d' ) === $from
			&& $d_to->format( 'Y-m-d' ) === $to
			&& $from <= $to;
	}

	public static function is_valid_date( string $date ): bool {
		$d = \DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
