<?php
namespace ProjectPrepper\Admin;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Schema;
use ProjectPrepper\Security;
use ProjectPrepper\Services\Groups;
use ProjectPrepper\Services\GroupGovernance;
use ProjectPrepper\Services\Borrowing;

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

		// Plattform-Übersicht: hier laufen die Member-Portal-Systemprozesse
		// (Kollektive, Beitritts-Voting, Mitglieder-Inventar, Leih-Anfragen)
		// für Betreiber/Moderation sichtbar zusammen.
		add_submenu_page(
			'project-prepper',
			__( 'Platform', 'project-prepper' ),
			__( 'Platform', 'project-prepper' ),
			Capabilities::MANAGE_GROUPS,
			'pp-platform',
			[ self::class, 'render_platform' ]
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

		// Sicherheit: Frontend-Härtung zentral schaltbar (per Default alles aus).
		add_submenu_page(
			'project-prepper',
			__( 'Security', 'project-prepper' ),
			__( 'Security', 'project-prepper' ),
			Capabilities::MANAGE_SETTINGS,
			'pp-security',
			[ self::class, 'render_security' ]
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

	/* ===================== Plattform-Übersicht (Phase B1) ===================== */

	private static function count( string $table, string $where = '' ): int {
		global $wpdb;
		// $where ist ein statischer, im Code definierter String (kein User-Input).
		$sql = 'SELECT COUNT(*) FROM %i' . ( '' !== $where ? ' WHERE ' . $where : '' );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, Schema::table( $table ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function status_label( string $status ): string {
		$map = [
			'pending'   => __( 'Pending acceptance', 'project-prepper' ),
			'voting'    => __( 'In voting', 'project-prepper' ),
			'requested' => __( 'Requested', 'project-prepper' ),
			'approved'  => __( 'Approved', 'project-prepper' ),
			'declined'  => __( 'Declined', 'project-prepper' ),
			'cancelled' => __( 'Cancelled', 'project-prepper' ),
			'returned'  => __( 'Returned', 'project-prepper' ),
		];
		return $map[ $status ] ?? $status;
	}

	public static function render_platform(): void {
		$groups   = Groups::all();
		$invites  = GroupGovernance::all_pending();
		$borrows  = Borrowing::all_recent( 30 );
		$kpis     = [
			[ __( 'Collectives', 'project-prepper' ), count( $groups ) ],
			[ __( 'Member inventory', 'project-prepper' ), self::count( 'items', 'owner_user_id IS NOT NULL' ) ],
			[ __( 'Open invitations', 'project-prepper' ), count( $invites ) ],
			[ __( 'Active loans', 'project-prepper' ), self::count( 'borrow_requests', "status = 'approved'" ) ],
			[ __( 'Open borrow requests', 'project-prepper' ), self::count( 'borrow_requests', "status = 'requested'" ) ],
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Platform', 'project-prepper' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Where the member portal comes together: collectives, join voting, member inventory and borrow requests. Manage members under Groups.', 'project-prepper' ); ?></p>

			<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0 24px;">
				<?php foreach ( $kpis as $kpi ) : ?>
					<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 18px;min-width:120px;">
						<div style="font-size:26px;font-weight:700;line-height:1;"><?php echo (int) $kpi[1]; ?></div>
						<div style="color:#646970;font-size:12px;margin-top:4px;"><?php echo esc_html( $kpi[0] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<h2><?php esc_html_e( 'Open join invitations', 'project-prepper' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th><?php esc_html_e( 'Collective', 'project-prepper' ); ?></th>
					<th><?php esc_html_e( 'Invited', 'project-prepper' ); ?></th>
					<th><?php esc_html_e( 'Status', 'project-prepper' ); ?></th>
					<th><?php esc_html_e( 'Approvals', 'project-prepper' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( $invites ) : foreach ( $invites as $inv ) : ?>
					<tr>
						<td><?php echo esc_html( $inv->group_name ); ?></td>
						<td><?php echo esc_html( $inv->invited_email ); ?></td>
						<td><?php echo esc_html( self::status_label( $inv->status ) ); ?></td>
						<td><?php echo 'voting' === $inv->status ? esc_html( $inv->approvals . ' / ' . $inv->needed ) : '—'; ?></td>
					</tr>
				<?php endforeach; else : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No open invitations.', 'project-prepper' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:28px;"><?php esc_html_e( 'Recent borrow requests', 'project-prepper' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th><?php esc_html_e( 'Item', 'project-prepper' ); ?></th>
					<th><?php esc_html_e( 'Owner', 'project-prepper' ); ?></th>
					<th><?php esc_html_e( 'Borrower', 'project-prepper' ); ?></th>
					<th><?php esc_html_e( 'Period', 'project-prepper' ); ?></th>
					<th><?php esc_html_e( 'Status', 'project-prepper' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( $borrows ) : foreach ( $borrows as $b ) : ?>
					<tr>
						<td><?php echo esc_html( $b->item_name ); ?></td>
						<td><?php echo esc_html( $b->owner_name ); ?></td>
						<td><?php echo esc_html( $b->requester_name ); ?></td>
						<td><?php echo esc_html( $b->date_from . ' – ' . $b->date_to ); ?></td>
						<td><?php echo esc_html( self::status_label( $b->status ) ); ?></td>
					</tr>
				<?php endforeach; else : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No borrow requests yet.', 'project-prepper' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:28px;"><?php esc_html_e( 'Collectives', 'project-prepper' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th><?php esc_html_e( 'Name', 'project-prepper' ); ?></th>
					<th><?php esc_html_e( 'Members', 'project-prepper' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( $groups ) : foreach ( $groups as $g ) : ?>
					<tr>
						<td><?php echo esc_html( $g->name ); ?></td>
						<td><?php echo (int) $g->member_count; ?></td>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=pp-groups' ) ); ?>"><?php esc_html_e( 'Manage', 'project-prepper' ); ?></a></td>
					</tr>
				<?php endforeach; else : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No collectives yet.', 'project-prepper' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ===================== Sicherheit (Phase B2) ===================== */

	public static function render_security(): void {
		$s = Security::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Security', 'project-prepper' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Frontend hardening for the member portal. Everything is OFF by default — nothing changes until you enable it here.', 'project-prepper' ); ?></p>

			<?php if ( isset( $_GET['pp_sec'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['pp_sec'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Security settings saved.', 'project-prepper' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pp_save_security">
				<?php wp_nonce_field( 'pp_save_security', 'pp_sec_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Login throttling', 'project-prepper' ); ?></th>
						<td>
							<label><input type="checkbox" name="login_throttle" value="1" <?php checked( $s['login_throttle'] ); ?>> <?php esc_html_e( 'Lock out repeated failed logins (per IP).', 'project-prepper' ); ?></label>
							<p style="margin-top:8px;">
								<label><?php esc_html_e( 'Max attempts', 'project-prepper' ); ?> <input type="number" name="login_max_attempts" min="1" value="<?php echo (int) $s['login_max_attempts']; ?>" style="width:70px;"></label>
								&nbsp;
								<label><?php esc_html_e( 'Lockout (minutes)', 'project-prepper' ); ?> <input type="number" name="login_lockout_minutes" min="1" value="<?php echo (int) $s['login_lockout_minutes']; ?>" style="width:70px;"></label>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Collectives per user', 'project-prepper' ); ?></th>
						<td>
							<input type="number" name="groups_per_user" min="0" value="<?php echo (int) $s['groups_per_user']; ?>" style="width:70px;">
							<p class="description"><?php esc_html_e( 'Anti-snowball limit. 0 = unlimited.', 'project-prepper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Invitations per day', 'project-prepper' ); ?></th>
						<td>
							<input type="number" name="invites_per_day" min="0" value="<?php echo (int) $s['invites_per_day']; ?>" style="width:70px;">
							<p class="description"><?php esc_html_e( 'Per member, rolling 24 hours. 0 = unlimited.', 'project-prepper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Self-registration', 'project-prepper' ); ?></th>
						<td>
							<label><input type="checkbox" name="allow_self_registration" value="1" <?php checked( $s['allow_self_registration'] ); ?>> <?php esc_html_e( 'Allow open sign-up (new accounts become members). Off = invitation only.', 'project-prepper' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Two-factor for members', 'project-prepper' ); ?></th>
						<td>
							<label><input type="checkbox" name="member_2fa" value="1" <?php checked( $s['member_2fa'] ); ?>> <?php esc_html_e( 'Require a second factor at member login.', 'project-prepper' ); ?></label>
							<p class="description"><?php esc_html_e( 'When on, members get a one-time code by email at portal sign-in. Admins and managers keep the normal wp-admin login.', 'project-prepper' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save security settings', 'project-prepper' ) ); ?>
			</form>
		</div>
		<?php
	}
}
