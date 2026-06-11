<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Nummernkreise — Pendant zur Auto-Inventarnummer der App.
 *
 * Inventarnummern: {PREFIX}-{0001} (Prefix aus Kategorie, Fallback "PP").
 * Verleihnummern:  V-{JAHR}-{0001}.
 */
class Numbering {

	const FALLBACK_PREFIX = 'PP';

	public static function next_inventory_number( ?int $category_id ): string {
		global $wpdb;

		$prefix = self::FALLBACK_PREFIX;
		if ( $category_id ) {
			$cat = $wpdb->get_row( $wpdb->prepare(
				'SELECT prefix FROM ' . Schema::table( 'categories' ) . ' WHERE id = %d',
				$category_id
			) );
			if ( $cat && $cat->prefix !== '' ) {
				$prefix = strtoupper( $cat->prefix );
			}
		}

		$like = $wpdb->esc_like( $prefix . '-' ) . '%';
		$max  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(CAST(SUBSTRING_INDEX(inventory_number, '-', -1) AS UNSIGNED))
			 FROM " . Schema::table( 'items' ) . '
			 WHERE inventory_number LIKE %s',
			$like
		) );

		return sprintf( '%s-%04d', $prefix, $max + 1 );
	}

	public static function next_rental_number(): string {
		global $wpdb;

		$year = current_time( 'Y' );
		$like = $wpdb->esc_like( 'V-' . $year . '-' ) . '%';
		$max  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(CAST(SUBSTRING_INDEX(rental_number, '-', -1) AS UNSIGNED))
			 FROM " . Schema::table( 'rentals' ) . '
			 WHERE rental_number LIKE %s',
			$like
		) );

		return sprintf( 'V-%s-%04d', $year, $max + 1 );
	}
}
