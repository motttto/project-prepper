<?php
/**
 * Uninstall — räumt nur auf, wenn der Betreiber das ausdrücklich will.
 *
 * Tabellen werden nur gelöscht, wenn die Option pp_delete_data_on_uninstall
 * gesetzt ist (kommt später als Checkbox in die Einstellungen).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/Schema.php';
require_once __DIR__ . '/includes/Capabilities.php';

\ProjectPrepper\Capabilities::uninstall();

if ( get_option( 'pp_delete_data_on_uninstall' ) ) {
	global $wpdb;
	foreach ( [ 'rental_items', 'rentals', 'units', 'items', 'categories', 'activity_log' ] as $pp_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bewusste Schema-Löschung beim Uninstall (Opt-in via Option).
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', \ProjectPrepper\Schema::table( $pp_table ) ) );
	}
	delete_option( 'pp_delete_data_on_uninstall' );
	// Betreiber-Einstellungen des Verleihs + Riegel der einmaligen Zuordnung.
	foreach ( [ 'pp_collective_rentals_visible', 'pp_item_time_status', 'pp_rental_buffer_before', 'pp_rental_buffer_after', 'pp_rental_backfill_done' ] as $pp_opt ) {
		delete_option( $pp_opt );
	}
}

delete_option( \ProjectPrepper\Schema::OPTION_KEY );
