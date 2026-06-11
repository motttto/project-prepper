<?php
namespace ProjectPrepper;

defined( 'ABSPATH' ) || exit;

/**
 * Central wiring: schema upgrades, REST routes, admin menu.
 */
class Plugin {

	public static function init(): void {
		// Schema-Upgrade nach Plugin-Update (Pendant zum Supabase-Migrations-Runner).
		if ( get_option( Schema::OPTION_KEY ) !== Schema::VERSION ) {
			Schema::migrate();
			Capabilities::install();
		}

		load_plugin_textdomain( 'project-prepper', false, dirname( plugin_basename( PP_PLUGIN_FILE ) ) . '/languages' );

		add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );

		if ( is_admin() ) {
			Admin\Menu::init();
		}
	}

	public static function register_rest_routes(): void {
		( new Rest\CategoriesController() )->register_routes();
		( new Rest\ItemsController() )->register_routes();
		( new Rest\RentalsController() )->register_routes();
	}
}
