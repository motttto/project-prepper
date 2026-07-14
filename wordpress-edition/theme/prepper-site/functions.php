<?php
/**
 * Prepper Site — Block-Theme für das Project-Prepper-Plugin.
 */

defined( 'ABSPATH' ) || exit;

// Selbst-Updater für den GitHub-Vertrieb (das Theme läuft nicht über wordpress.org).
require_once get_template_directory() . '/inc/updater.php';
Prepper_Site_Updater::init();

add_action( 'after_setup_theme', function () {
	// Gebündelte Übersetzungen aus languages/ — nötig, solange das Theme
	// nicht auf wordpress.org gehostet ist (keine automatischen Language Packs).
	load_theme_textdomain( 'prepper-site', get_template_directory() . '/languages' );
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style(
		'prepper-site',
		get_stylesheet_uri(),
		[],
		wp_get_theme()->get( 'Version' )
	);
} );
