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
		// Top-Level = Dashboard (Startseite des Plugins). Niedrigste sinnvolle
		// View-Cap, damit das Menü erscheint, sobald der Nutzer ein Modul sehen darf.
		add_menu_page(
			'Project Prepper',
			'Project Prepper',
			Capabilities::VIEW_INVENTORY,
			'project-prepper',
			[ self::class, 'render_dashboard' ],
			'dashicons-archive',
			30
		);

		add_submenu_page(
			'project-prepper',
			__( 'Dashboard', 'project-prepper' ),
			__( 'Dashboard', 'project-prepper' ),
			Capabilities::VIEW_INVENTORY,
			'project-prepper',
			[ self::class, 'render_dashboard' ]
		);

		// Inventar ist von der Top-Ebene auf den eigenen Slug pp-inventory umgezogen.
		add_submenu_page(
			'project-prepper',
			__( 'Inventory', 'project-prepper' ),
			__( 'Inventory', 'project-prepper' ),
			Capabilities::VIEW_INVENTORY,
			'pp-inventory',
			[ self::class, 'render_inventory' ]
		);

		add_submenu_page(
			'project-prepper',
			__( 'Projects', 'project-prepper' ),
			__( 'Projects', 'project-prepper' ),
			Capabilities::VIEW_PROJECTS,
			'pp-projects',
			[ self::class, 'render_projects' ]
		);

		add_submenu_page(
			'project-prepper',
			__( 'Groups', 'project-prepper' ),
			__( 'Groups', 'project-prepper' ),
			Capabilities::MANAGE_GROUPS,
			'pp-groups',
			[ self::class, 'render_groups' ]
		);

		add_submenu_page(
			'project-prepper',
			__( 'Rentals', 'project-prepper' ),
			__( 'Rentals', 'project-prepper' ),
			Capabilities::VIEW_RENTALS,
			'pp-rentals',
			[ self::class, 'render_rentals' ]
		);

		add_submenu_page(
			'project-prepper',
			__( 'Inquiries', 'project-prepper' ),
			__( 'Inquiries', 'project-prepper' ),
			Capabilities::VIEW_INQUIRIES,
			'pp-inquiries',
			[ self::class, 'render_inquiries' ]
		);

		add_submenu_page(
			'project-prepper',
			__( 'Calendar', 'project-prepper' ),
			__( 'Calendar', 'project-prepper' ),
			Capabilities::VIEW_RENTALS,
			'pp-calendar',
			[ self::class, 'render_calendar' ]
		);

		add_submenu_page(
			'project-prepper',
			__( 'Categories', 'project-prepper' ),
			__( 'Categories', 'project-prepper' ),
			Capabilities::EDIT_INVENTORY,
			'pp-categories',
			[ self::class, 'render_categories' ]
		);

		add_submenu_page(
			'project-prepper',
			__( 'Settings', 'project-prepper' ),
			__( 'Settings', 'project-prepper' ),
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
		// SheetJS Community Edition (Apache-2.0) — XLSX-Import/-Export client-seitig, lokal gebündelt (kein CDN).
		wp_enqueue_script( 'pp-xlsx', PP_PLUGIN_URL . 'admin/js/vendor/xlsx.full.min.js', [], '0.20.3', true );
		wp_enqueue_script( 'pp-admin', PP_PLUGIN_URL . 'admin/js/admin.js', [ 'pp-xlsx', 'wp-i18n' ], PP_VERSION, true );
		wp_set_script_translations( 'pp-admin', 'project-prepper', PP_PLUGIN_DIR . 'languages' );
		// Medien-Frame (wp.media) für den Projekt-Dateien-Tab — nur auf den pp-Seiten.
		wp_enqueue_media();

		wp_localize_script( 'pp-admin', 'ppConfig', [
			'restUrl' => esc_url_raw( rest_url( 'project-prepper/v1' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'canEdit' => [
				'inventory'    => current_user_can( Capabilities::EDIT_INVENTORY ),
				'projects'     => current_user_can( Capabilities::EDIT_PROJECTS ),
				'rentals'      => current_user_can( Capabilities::EDIT_RENTALS ),
				'inquiries'    => current_user_can( Capabilities::EDIT_INQUIRIES ),
				'importExport' => current_user_can( Capabilities::IMPORT_EXPORT ),
				'settings'     => current_user_can( Capabilities::MANAGE_SETTINGS ),
				'groups'       => current_user_can( Capabilities::MANAGE_GROUPS ),
			],
		] );
	}

	public static function render_dashboard(): void {
		// Persönliche Begrüßung wie in der Web-App ("Hallo {Name}") statt
		// generischem Titel — der Name ist serverseitig verfügbar (kein Flash).
		$user = wp_get_current_user();
		$name = ( $user && '' !== $user->display_name ) ? $user->display_name : $user->user_login;
		echo '<div class="wrap pp-dash-wrap">';
		printf(
			'<h1 class="pp-greeting">%s</h1>',
			/* translators: %s = display name of the current user. */
			esc_html( sprintf( __( 'Hello %s', 'project-prepper' ), $name ) )
		);
		echo '<p class="pp-greeting-sub">' . esc_html( get_bloginfo( 'name' ) ) . '</p>';
		echo '<div id="pp-admin" data-page="dashboard"></div></div>';
	}

	public static function render_inventory(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Inventory', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="inventory"></div></div>';
	}

	public static function render_projects(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Projects', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="projects"></div></div>';
	}

	public static function render_groups(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Groups', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="groups"></div></div>';
	}

	public static function render_rentals(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Rentals', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="rentals"></div></div>';
	}

	public static function render_calendar(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Calendar', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="calendar"></div></div>';
	}

	public static function render_categories(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Categories', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="categories"></div></div>';
	}

	public static function render_settings(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Settings', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="settings"></div></div>';
	}

	public static function render_inquiries(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Inquiries', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="inquiries"></div></div>';
	}
}
