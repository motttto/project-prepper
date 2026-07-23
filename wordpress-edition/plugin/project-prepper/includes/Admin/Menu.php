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

		// Verwaltung: Inventar/Projekte/Gruppen/Verleih/Anfragen/Kalender/Kategorien/
		// Föderation gebündelt unter EINEM Menüpunkt mit Reitern (Tabs). Pro Tab eigene
		// Cap-Prüfung; das Menü erscheint ab der niedrigsten gemeinsamen View-Cap.
		add_submenu_page(
			'project-prepper',
			__( 'Manage', 'project-prepper' ),
			__( 'Manage', 'project-prepper' ),
			Capabilities::VIEW_INVENTORY,
			'pp-manage',
			[ self::class, 'render_manage' ]
		);

		// ----- Steuerzentrale (Betreiber, Cap pp_operate) -----

		// Instanz: Identität & Zweck + Ökonomie-Modell + AGB/Recht (Konfiguration).
		add_submenu_page(
			'project-prepper',
			__( 'Instance', 'project-prepper' ),
			__( 'Instance', 'project-prepper' ),
			Capabilities::OPERATE,
			'pp-instance',
			[ self::class, 'render_instance' ]
		);

		// Plattform-Übersicht: Member-Portal-Systemprozesse für Betreiber/Moderation.
		add_submenu_page(
			'project-prepper',
			__( 'Platform', 'project-prepper' ),
			__( 'Platform', 'project-prepper' ),
			Capabilities::OPERATE,
			'pp-platform',
			[ self::class, 'render_platform' ]
		);

		// Benutzer & Rechte: Rollen + feingranulare Caps + Gruppen.
		add_submenu_page(
			'project-prepper',
			__( 'Users & permissions', 'project-prepper' ),
			__( 'Users & permissions', 'project-prepper' ),
			Capabilities::OPERATE,
			'pp-users',
			[ self::class, 'render_users' ]
		);

		add_submenu_page(
			'project-prepper',
			__( 'Settings', 'project-prepper' ),
			__( 'Settings', 'project-prepper' ),
			Capabilities::OPERATE,
			'pp-settings',
			[ self::class, 'render_settings' ]
		);

		// E-Mail-Templates: alle Plugin-Mails zentral editierbar.
		add_submenu_page(
			'project-prepper',
			__( 'Email templates', 'project-prepper' ),
			__( 'Email templates', 'project-prepper' ),
			Capabilities::OPERATE,
			'pp-email-templates',
			[ self::class, 'render_email_templates' ]
		);

		// Sicherheit: Frontend-Härtung zentral schaltbar (per Default alles aus).
		add_submenu_page(
			'project-prepper',
			__( 'Security', 'project-prepper' ),
			__( 'Security', 'project-prepper' ),
			Capabilities::OPERATE,
			'pp-security',
			[ self::class, 'render_security' ]
		);

		// Mitglieder-Feedback lesen (Pendant zum App-Feedback-Tab).
		$fb_count = \ProjectPrepper\Services\Feedback::new_count();
		$fb_label = __( 'Feedback', 'project-prepper' );
		add_submenu_page(
			'project-prepper',
			__( 'Feedback', 'project-prepper' ),
			$fb_count > 0 ? $fb_label . ' <span class="awaiting-mod">' . (int) $fb_count . '</span>' : $fb_label,
			Capabilities::OPERATE,
			'pp-feedback',
			[ self::class, 'render_feedback' ]
		);
	}

	/** Mitglieder-Feedback (Liste + Status setzen). */
	public static function render_feedback(): void {
		if ( isset( $_POST['pp_fb_id'], $_POST['pp_fb_status'] ) && check_admin_referer( 'pp_feedback_status' ) ) {
			\ProjectPrepper\Services\Feedback::set_status( (int) $_POST['pp_fb_id'], sanitize_key( wp_unslash( (string) $_POST['pp_fb_status'] ) ) );
		}
		$rows  = \ProjectPrepper\Services\Feedback::recent();
		$types = \ProjectPrepper\Services\Feedback::types();
		echo '<div class="wrap"><h1>' . esc_html__( 'Member feedback', 'project-prepper' ) . '</h1>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No feedback yet.', 'project-prepper' ) . '</p></div>';
			return;
		}
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'project-prepper' ) . '</th><th>' . esc_html__( 'From', 'project-prepper' ) . '</th><th>' . esc_html__( 'Type', 'project-prepper' ) . '</th><th>' . esc_html__( 'Message', 'project-prepper' ) . '</th><th>' . esc_html__( 'Status', 'project-prepper' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$u    = $r->user_id ? get_userdata( (int) $r->user_id ) : null;
			$next = 'done' === $r->status ? 'new' : ( 'read' === $r->status ? 'done' : 'read' );
			echo '<tr>';
			echo '<td>' . esc_html( $r->created_at ) . '</td>';
			echo '<td>' . esc_html( $u ? $u->display_name : '—' ) . '</td>';
			echo '<td>' . esc_html( $types[ $r->feedback_type ] ?? $r->feedback_type ) . '</td>';
			echo '<td>' . nl2br( esc_html( $r->message ) ) . '</td>';
			echo '<td>' . esc_html( $r->status ) . '</td>';
			echo '<td><form method="post" style="display:inline">';
			wp_nonce_field( 'pp_feedback_status' );
			echo '<input type="hidden" name="pp_fb_id" value="' . (int) $r->id . '">';
			/* translators: %s = next status (read/done/new). */
			echo '<button class="button button-small" name="pp_fb_status" value="' . esc_attr( $next ) . '">' . esc_html( sprintf( __( 'Mark as %s', 'project-prepper' ), $next ) ) . '</button>';
			echo '</form></td></tr>';
		}
		echo '</tbody></table></div>';
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

	/**
	 * Die unter „Manage" gebündelten Module — Reihenfolge = Tab-Reihenfolge.
	 * Jeder Eintrag: Label, benötigte Capability, data-page für admin.js.
	 *
	 * @return array<string,array{label:string,cap:string,page:string}>
	 */
	private static function manage_tabs(): array {
		return [
			'inventory'  => [ 'label' => __( 'Inventory', 'project-prepper' ),  'cap' => Capabilities::VIEW_INVENTORY, 'page' => 'inventory' ],
			'projects'   => [ 'label' => __( 'Projects', 'project-prepper' ),   'cap' => Capabilities::VIEW_PROJECTS,  'page' => 'projects' ],
			'groups'     => [ 'label' => __( 'Groups', 'project-prepper' ),     'cap' => Capabilities::MANAGE_GROUPS,  'page' => 'groups' ],
			'rentals'    => [ 'label' => __( 'Rentals', 'project-prepper' ),    'cap' => Capabilities::VIEW_RENTALS,   'page' => 'rentals' ],
			'inquiries'  => [ 'label' => __( 'Inquiries', 'project-prepper' ),  'cap' => Capabilities::VIEW_INQUIRIES, 'page' => 'inquiries' ],
			'calendar'   => [ 'label' => __( 'Calendar', 'project-prepper' ),   'cap' => Capabilities::VIEW_RENTALS,   'page' => 'calendar' ],
			'categories' => [ 'label' => __( 'Categories', 'project-prepper' ), 'cap' => Capabilities::EDIT_INVENTORY, 'page' => 'categories' ],
			'federation' => [ 'label' => __( 'Federation', 'project-prepper' ), 'cap' => Capabilities::OPERATE,        'page' => 'federation' ],
		];
	}

	/**
	 * Gebündelte Modulseite mit WP-Reitern (nav-tab). Jeder Reiter rendert wie
	 * bisher seine JS-App-Shell (`data-page`); admin.js routet unverändert. Pro
	 * Reiter eigene Capability — es erscheinen nur die erlaubten.
	 */
	public static function render_manage(): void {
		$tabs      = self::manage_tabs();
		$available = array_filter( $tabs, static fn( $t ) => current_user_can( $t['cap'] ) );
		if ( ! $available ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'project-prepper' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Tab-Navigation (Lesen).
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( ! isset( $available[ $requested ] ) ) {
			$requested = (string) array_key_first( $available );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Manage', 'project-prepper' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper" style="margin-bottom:1rem;">';
		foreach ( $available as $key => $tab ) {
			$url = add_query_arg( [ 'page' => 'pp-manage', 'tab' => $key ], admin_url( 'admin.php' ) );
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $url ),
				$key === $requested ? ' nav-tab-active' : '',
				esc_html( $tab['label'] )
			);
		}
		echo '</nav>';
		echo '<div id="pp-admin" data-page="' . esc_attr( $available[ $requested ]['page'] ) . '"></div>';
		echo '</div>';
	}

	public static function render_settings(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Settings', 'project-prepper' ) . '</h1>';
		self::render_telegram_card();
		echo '<div id="pp-admin" data-page="settings"></div></div>';
	}

	/**
	 * Instanz-Bot-Token für Telegram-Benachrichtigungen (Betreiber-Sache). EIN
	 * Token pro Instanz; die chat_id pro Kollektiv setzen Gründer im Portal. Das
	 * Token wird nie im Klartext zurückgegeben — nur der Status (gesetzt / nicht).
	 * Muster wie die Update-Token-Karte (admin-post, Passwort-Feld, Konstant-Lock).
	 */
	private static function render_telegram_card(): void {
		$has    = \ProjectPrepper\Services\Telegram::has_bot_token();
		$locked = \ProjectPrepper\Services\Telegram::token_locked_by_constant();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige nach Redirect.
		$saved  = isset( $_GET['pp_msg'] ) && 'telegram_token_saved' === $_GET['pp_msg'];
		echo '<div class="card" style="max-width:680px;margin:1em 0;padding:4px 18px 14px">';
		echo '<h2>' . esc_html__( 'Telegram notifications', 'project-prepper' ) . '</h2>';
		if ( $saved ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Saved.', 'project-prepper' ) . '</p></div>';
		}
		echo '<p class="description">' . esc_html__( 'Optional. Set one bot token for this instance to send short notifications (new inquiries, bookings, availability) to a collective’s Telegram group. Each collective’s founders set their own chat ID in the portal under “Edit collective”.', 'project-prepper' ) . '</p>';
		echo '<p>' . esc_html__( 'Status:', 'project-prepper' ) . ' <strong>' . ( $has ? esc_html__( 'bot token active', 'project-prepper' ) : esc_html__( 'no bot token (notifications off)', 'project-prepper' ) ) . '</strong></p>';
		if ( $locked ) {
			echo '<p><strong>' . esc_html__( 'The token is set via the PP_TELEGRAM_BOT_TOKEN constant in wp-config.php (the most secure option). The field below is therefore disabled.', 'project-prepper' ) . '</strong></p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" autocomplete="off">';
			echo '<input type="hidden" name="action" value="pp_save_telegram_token">';
			wp_nonce_field( 'pp_save_telegram_token' );
			echo '<input type="password" name="pp_telegram_token" autocomplete="new-password" class="regular-text" placeholder="123456789:ABCdef…" style="width:100%;max-width:440px" aria-label="' . esc_attr__( 'Telegram bot token', 'project-prepper' ) . '">';
			echo '<p><button class="button button-primary" name="pp_do" value="save">' . esc_html__( 'Save token', 'project-prepper' ) . '</button> ';
			if ( $has ) {
				echo '<button class="button" name="pp_do" value="remove">' . esc_html__( 'Remove token', 'project-prepper' ) . '</button>';
			}
			echo '</p></form>';
		}
		echo '<p class="description">' . esc_html__( 'Create a bot with @BotFather in Telegram, then add the bot to the group and use that group’s chat ID.', 'project-prepper' ) . '</p>';
		echo '</div>';
	}

	public static function render_users(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Users & permissions', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="users"></div></div>';
	}

	public static function render_email_templates(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Email templates', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="email-templates"></div></div>';
	}

	/* ===================== Steuerzentrale (Betreiber) =====================
	 * Reine JS-App-Shells gegen die REST-API (PlatformController,
	 * SecurityController, …). Föderation läuft als Reiter unter „Manage". */

	public static function render_instance(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Instance', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="instance"></div></div>';
	}

	public static function render_platform(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Platform', 'project-prepper' ) . '</h1><div id="pp-admin" data-page="platform"></div></div>';
	}

	public static function render_security(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Security', 'project-prepper' ) . '</h1>';
		self::render_update_token_card();
		echo '<div id="pp-admin" data-page="security"></div></div>';
	}

	/** Feld für das optionale GitHub-Update-Token (gegen das 60/h-Rate-Limit). */
	private static function render_update_token_card(): void {
		$has    = '' !== \ProjectPrepper\Updater::token();
		$locked = \ProjectPrepper\Updater::token_locked_by_constant();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige nach Redirect.
		$saved  = isset( $_GET['pp_msg'] ) && 'token_saved' === $_GET['pp_msg'];
		echo '<div class="card" style="max-width:680px;margin:1em 0;padding:4px 18px 14px">';
		echo '<h2>' . esc_html__( 'Update token (GitHub)', 'project-prepper' ) . '</h2>';
		if ( $saved ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Saved.', 'project-prepper' ) . '</p></div>';
		}
		echo '<p class="description">' . esc_html__( 'Optional. Authenticated update checks raise GitHub’s rate limit from 60 to 5000 requests per hour — recommended on shared hosting, where the auto-updater otherwise fails intermittently. A token WITHOUT any scopes is enough for a public repository.', 'project-prepper' ) . '</p>';
		echo '<p>' . esc_html__( 'Status:', 'project-prepper' ) . ' <strong>' . ( $has ? esc_html__( 'token active', 'project-prepper' ) : esc_html__( 'no token (unauthenticated, 60/h)', 'project-prepper' ) ) . '</strong></p>';
		if ( $locked ) {
			echo '<p><strong>' . esc_html__( 'The token is set via the PP_UPDATE_TOKEN constant in wp-config.php (the most secure option). The field below is therefore disabled.', 'project-prepper' ) . '</strong></p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" autocomplete="off">';
			echo '<input type="hidden" name="action" value="pp_save_update_token">';
			wp_nonce_field( 'pp_save_update_token' );
			echo '<input type="password" name="pp_update_token" autocomplete="new-password" class="regular-text" placeholder="github_pat_… / ghp_…" style="width:100%;max-width:440px" aria-label="' . esc_attr__( 'GitHub token', 'project-prepper' ) . '">';
			echo '<p><button class="button button-primary" name="pp_do" value="save">' . esc_html__( 'Save token', 'project-prepper' ) . '</button> ';
			if ( $has ) {
				echo '<button class="button" name="pp_do" value="remove">' . esc_html__( 'Remove token', 'project-prepper' ) . '</button>';
			}
			echo '</p></form>';
		}
		echo '<p class="description"><a href="https://github.com/settings/personal-access-tokens" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Create a fine-grained token on GitHub (Read-only, no scopes needed) →', 'project-prepper' ) . '</a></p>';
		echo '</div>';
	}
}
