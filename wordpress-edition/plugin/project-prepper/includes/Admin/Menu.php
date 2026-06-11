<?php
namespace ProjectPrepper\Admin;

use ProjectPrepper\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-Menü + Asset-Loading.
 *
 * Das UI ist bewusst Vanilla-JS gegen die eigene REST-API (kein Build-Step).
 * Später ersetzbar durch React via @wordpress/scripts, die REST-API bleibt gleich.
 */
class Menu {

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
	}

	public static function register_menu(): void {
		add_menu_page(
			'Project Prepper',
			'Project Prepper',
			Capabilities::VIEW_INVENTORY,
			'project-prepper',
			[ self::class, 'render_inventory' ],
			'dashicons-archive',
			30
		);

		add_submenu_page(
			'project-prepper',
			__( 'Inventar', 'project-prepper' ),
			__( 'Inventar', 'project-prepper' ),
			Capabilities::VIEW_INVENTORY,
			'project-prepper',
			[ self::class, 'render_inventory' ]
		);

		add_submenu_page(
			'project-prepper',
			__( 'Verleih', 'project-prepper' ),
			__( 'Verleih', 'project-prepper' ),
			Capabilities::VIEW_RENTALS,
			'pp-rentals',
			[ self::class, 'render_rentals' ]
		);

		add_submenu_page(
			'project-prepper',
			__( 'Anfragen', 'project-prepper' ),
			__( 'Anfragen', 'project-prepper' ),
			Capabilities::VIEW_INQUIRIES,
			'pp-inquiries',
			[ self::class, 'render_inquiries' ]
		);

		add_submenu_page(
			'project-prepper',
			__( 'Kategorien', 'project-prepper' ),
			__( 'Kategorien', 'project-prepper' ),
			Capabilities::EDIT_INVENTORY,
			'pp-categories',
			[ self::class, 'render_categories' ]
		);

		add_submenu_page(
			'project-prepper',
			__( 'Einstellungen', 'project-prepper' ),
			__( 'Einstellungen', 'project-prepper' ),
			Capabilities::MANAGE_SETTINGS,
			'pp-settings',
			[ self::class, 'render_settings' ]
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'project-prepper' ) === false && strpos( $hook, 'pp-' ) === false ) {
			return;
		}

		wp_enqueue_style( 'pp-admin', PP_PLUGIN_URL . 'admin/css/admin.css', [], PP_VERSION );
		wp_enqueue_script( 'pp-admin', PP_PLUGIN_URL . 'admin/js/admin.js', [], PP_VERSION, true );

		wp_localize_script( 'pp-admin', 'ppConfig', [
			'restUrl' => esc_url_raw( rest_url( 'project-prepper/v1' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'canEdit' => [
				'inventory'    => current_user_can( Capabilities::EDIT_INVENTORY ),
				'rentals'      => current_user_can( Capabilities::EDIT_RENTALS ),
				'inquiries'    => current_user_can( Capabilities::EDIT_INQUIRIES ),
				'importExport' => current_user_can( Capabilities::IMPORT_EXPORT ),
				'settings'     => current_user_can( Capabilities::MANAGE_SETTINGS ),
			],
		] );
	}

	public static function render_inventory(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Inventar', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="inventory"></div></div>';
	}

	public static function render_rentals(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Verleih', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="rentals"></div></div>';
	}

	public static function render_categories(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Kategorien', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="categories"></div></div>';
	}

	public static function render_settings(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Einstellungen', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="settings"></div></div>';
	}

	public static function render_inquiries(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Anfragen', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="inquiries"></div></div>';
	}
}
