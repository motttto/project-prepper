<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Verfügbarkeitsprüfung — Pendant zu check_inventory_availability() der App.
 *
 * EINE Quelle der Wahrheit für ALLE Wege, auf denen ein Artikel das Regal
 * verlässt (v0.41.0). Verfügbar = Artikel-Menge
 *   − Σ Positionen überlappender Verleihe mit Status reserved/active
 *   − Σ Buchungen aus Projekten mit Status confirmed/running, deren effektiver
 *     Zeitraum (Zeilen-Datum, sonst geerbt vom Projekt) überlappt
 *   − Σ genehmigter Kollektiv-Leihen (borrow_requests) im Zeitraum
 *   − Σ genehmigter föderierter Leihen (fed_borrow_in, je Anfrage 1 Einheit).
 *
 * Vorher rechneten zwei Schichten getrennt — {@see Borrowing::available_units}
 * kannte Verleihe/Projekte nicht, diese Klasse kannte Leihen nicht. Derselbe
 * Artikel konnte dadurch im selben Zeitraum extern verliehen UND ans Kollektiv
 * verliehen werden. `Borrowing::available_units()` delegiert jetzt hierher.
 *
 * Buchungen ohne bestimmbaren Zeitraum (Zeile UND Projekt ohne Termine)
 * blockieren nichts.
 */
class Availability {

	/**
	 * @param int    $item_id             Artikel.
	 * @param string $from                Y-m-d.
	 * @param string $to                  Y-m-d.
	 * @param int    $exclude_rental_id   Eigener Verleih beim Bearbeiten ausnehmen.
	 * @param int    $exclude_project_id  Eigenes Projekt beim Bearbeiten von Buchungszeilen ausnehmen.
	 * @param int    $exclude_borrow_id   Eigene Leih-Anfrage beim Entscheiden ausnehmen.
	 * @param int    $exclude_bundle_ref  Ganzen Set-Leih-Vorgang ausnehmen (alle Zeilen dieser bundle_ref).
	 */
	public static function available_quantity( int $item_id, string $from, string $to, int $exclude_rental_id = 0, int $exclude_project_id = 0, int $exclude_borrow_id = 0, int $exclude_bundle_ref = 0 ): int {
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

		// Kollektiv-Leihen: nur genehmigte halten eine Einheit. `quantity` ist seit
		// v0.40.0 die angefragte Stückzahl (Einzel-Artikel: 1), Set-Teile tragen
		// Bedarf × Anzahl Sets. $exclude_bundle_ref klammert einen ganzen
		// Set-Vorgang aus (alle Zeilen mit dieser bundle_ref).
		$borrowed = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(quantity), 0) FROM %i
			 WHERE item_id = %d
			   AND status = 'approved'
			   AND id <> %d
			   AND ( bundle_ref IS NULL OR bundle_ref <> %d )
			   AND date_from <= %s
			   AND date_to >= %s",
			Schema::table( 'borrow_requests' ),
			$item_id,
			$exclude_borrow_id,
			$exclude_bundle_ref,
			$to,
			$from
		) );

		// Föderierte Leihen (Slice 5): eine genehmigte Anfrage einer Partner-Instanz
		// hält eine Einheit, bis sie zurückgegeben ist.
		$federated = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i
			 WHERE item_id = %d
			   AND status = 'approved'
			   AND date_from <= %s
			   AND date_to >= %s",
			Schema::table( 'fed_borrow_in' ),
			$item_id,
			$to,
			$from
		) );

		return max( 0, (int) $item->quantity - $rented - $booked - $borrowed - $federated );
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
