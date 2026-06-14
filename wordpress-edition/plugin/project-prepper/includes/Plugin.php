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
			// Portal-Seite erst auf 'init' anlegen (wp_insert_post ist auf
			// plugins_loaded zu früh) — Flag setzen, MemberPortal erledigt es.
			update_option( Frontend\MemberPortal::ENSURE_FLAG, 1 );
		}

		// Gebündelte Übersetzungen aus languages/ laden — nötig, solange das Plugin
		// nicht auf wordpress.org gehostet ist (keine automatischen Language Packs).
		load_plugin_textdomain( 'project-prepper', false, dirname( plugin_basename( PP_PLUGIN_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound

		add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );

		Email\Notifications::init();
		Privacy::init();
		Security::init();
		Federation::init();
		Frontend\Shortcodes::init();
		Frontend\Blocks::init();
		Frontend\ItemDetail::init();
		Frontend\MemberPortal::init();
		Frontend\MemberAuth::init();

		if ( is_admin() ) {
			Admin\Menu::init();
		}
	}

	public static function register_rest_routes(): void {
		( new Rest\DashboardController() )->register_routes();
		( new Rest\CategoriesController() )->register_routes();
		( new Rest\ItemsController() )->register_routes();
		( new Rest\UnitsController() )->register_routes();
		( new Rest\MediaController() )->register_routes();
		( new Rest\ProjectsController() )->register_routes();
		( new Rest\GroupsController() )->register_routes();
		( new Rest\RentalsController() )->register_routes();
		( new Rest\InquiriesController() )->register_routes();
		( new Rest\ImportExportController() )->register_routes();
		( new Rest\CalendarController() )->register_routes();
		( new Rest\CalendarViewController() )->register_routes();
		( new Rest\SettingsController() )->register_routes();
		( new Rest\FederationController() )->register_routes();
		( new Rest\PlatformController() )->register_routes();
		( new Rest\SecurityController() )->register_routes();
	}
}
