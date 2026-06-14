<?php
/**
 * Plugin Name:       Project Prepper
 * Plugin URI:        https://project-prepper.dunkelstrom.net
 * Description:       Equipment inventory, projects & rentals for teams — categories with number ranges, unit tracking, availability checks and rental management.
 * Version:           0.68.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            motttto
 * License:           GPL-2.0-or-later
 * Text Domain:       project-prepper
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'PP_VERSION', '0.68.0' );
define( 'PP_PLUGIN_FILE', __FILE__ );
define( 'PP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Simple PSR-4 autoloader: ProjectPrepper\Foo\Bar → includes/Foo/Bar.php
 */
spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'ProjectPrepper\\' ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( 'ProjectPrepper\\' ) );
	$path     = PP_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( file_exists( $path ) ) {
		require_once $path;
	}
} );

register_activation_hook( __FILE__, function () {
	\ProjectPrepper\Schema::migrate();
	\ProjectPrepper\Capabilities::install();
	\ProjectPrepper\Frontend\MemberPortal::ensure_page();
} );

register_deactivation_hook( __FILE__, function () {
	// Daten und Rollen bleiben erhalten — Aufräumen passiert erst in uninstall.php.
} );

add_action( 'plugins_loaded', function () {
	\ProjectPrepper\Plugin::init();
} );
