<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Verfügbarkeitsprüfung — Pendant zu check_inventory_availability() der App.
 *
 * Verfügbar = Artikel-Menge minus Summe der Positionen aus überlappenden
 * Verleihen mit Status reserved/active.
 */
class Availability {

	/**
	 * @param int    $item_id           Artikel.
	 * @param string $from              Y-m-d.
	 * @param string $to                Y-m-d.
	 * @param int    $exclude_rental_id Eigener Verleih beim Bearbeiten ausnehmen.
	 */
	public static function available_quantity( int $item_id, string $from, string $to, int $exclude_rental_id = 0 ): int {
		global $wpdb;

		$item = Inventory::get_item( $item_id );
		if ( ! $item ) {
			return 0;
		}

		$booked = (int) $wpdb->get_var( $wpdb->prepare(
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

		return max( 0, (int) $item->quantity - $booked );
	}

	public static function is_valid_range( string $from, string $to ): bool {
		$d_from = \DateTime::createFromFormat( 'Y-m-d', $from );
		$d_to   = \DateTime::createFromFormat( 'Y-m-d', $to );
		return $d_from && $d_to
			&& $d_from->format( 'Y-m-d' ) === $from
			&& $d_to->format( 'Y-m-d' ) === $to
			&& $from <= $to;
	}
}
