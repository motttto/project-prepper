<?php
namespace ProjectPrepper\Frontend;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Security;
use ProjectPrepper\Services\Groups;
use ProjectPrepper\Services\GroupGovernance as Governance;
use ProjectPrepper\Services\Inventory;
use ProjectPrepper\Services\Feedback;
use ProjectPrepper\Services\MemberInventory;
use ProjectPrepper\Services\MemberInquiries;
use ProjectPrepper\Services\MemberRentals;
use ProjectPrepper\Services\Rentals;
use ProjectPrepper\Services\Inquiries;
use ProjectPrepper\Services\Borrowing;
use ProjectPrepper\Services\Projects;
use ProjectPrepper\Services\Costs;
use ProjectPrepper\Services\Schedule;
use ProjectPrepper\Services\Decisions;
use ProjectPrepper\Services\Polls;
use ProjectPrepper\Services\FederatedBorrow;
use ProjectPrepper\Rest\CalendarController;
use ProjectPrepper\Federation;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Member-Portal — das Front-End-Zuhause der Mitglieder (Plattform-Modell,
 * siehe docs/05-MEMBER-PORTAL.md).
 *
 * Leitidee: Die WP-Instanz ist eine PLATTFORM, auf der Single-User Kollektive
 * (= Gruppen) gründen/​betreten. **Mitglieder (Rolle pp_member) arbeiten
 * ausschließlich im Frontend, nicht im wp-admin** — admin_init leitet sie auf
 * die Portal-Seite um, die Admin-Bar wird ausgeblendet. Der Plattform-Account
 * entsteht **nur per Einladung** (der Admin legt den User an), es gibt kein
 * offenes Signup.
 *
 * Phase 1 (Fundament): Login + Begrüßung + Liste der eigenen Kollektive +
 * ehrliche Vorschau auf „Kollektiv gründen / beitreten" und „Mein Inventar"
 * (Phase 2-3). Sicherheits-Leitlinie (security by design): das Portal liest
 * NUR Daten des aktuell eingeloggten Users, keine Enumeration fremder Objekte.
 */
class MemberPortal {

	const PAGE_OPTION = 'pp_portal_page_id';
	const ENSURE_FLAG = 'pp_ensure_portal_page';
	const SHORTCODE   = 'pp_member_portal';

	public static function init(): void {
		add_shortcode( self::SHORTCODE, [ self::class, 'render' ] );

		// Portal-Seite nach Schema-Upgrade einmalig anlegen (Flag von Plugin::init).
		add_action( 'init', [ self::class, 'maybe_ensure_page' ] );

		// Reine Mitglieder gehören ins Frontend, nicht ins wp-admin.
		add_action( 'admin_init', [ self::class, 'redirect_members_from_admin' ] );
		add_filter( 'show_admin_bar', [ self::class, 'filter_admin_bar' ] );

		add_action( 'wp_enqueue_scripts', [ self::class, 'register_assets' ] );

		// Kollektiv-Selbstbedienung (Gründen/Einladen/Annehmen/Abstimmen) — ein
		// Dispatcher, nur eingeloggt (kein nopriv).
		add_action( 'admin_post_pp_collective', [ self::class, 'handle_collective_action' ] );

		// Mein-Inventar CSV-Export (Download) + -Import (Bulk). Eigene Handler, weil
		// der Export streamt (kein Redirect) und der Import eine Datei hochlädt.
		add_action( 'admin_post_pp_member_export', [ self::class, 'handle_inventory_export' ] );
		add_action( 'admin_post_pp_member_import', [ self::class, 'handle_inventory_import' ] );
		add_action( 'admin_post_pp_member_photo', [ self::class, 'handle_inventory_photo' ] );
		add_action( 'admin_post_pp_member_doc', [ self::class, 'handle_inventory_doc' ] );
		add_action( 'admin_post_pp_member_data', [ self::class, 'handle_member_data_export' ] );
		add_action( 'admin_post_pp_member_avatar', [ self::class, 'handle_member_avatar' ] );
		add_action( 'admin_post_pp_accept_terms', [ self::class, 'handle_accept_terms' ] );
		add_action( 'admin_post_pp_member_feedback', [ self::class, 'handle_feedback' ] );

		// Eigene Profilfotos als WP-Avatar überall im Portal (Mitgliederlisten,
		// Topbar) durchreichen — Fallback bleibt Gravatar/Initialen.
		add_filter( 'pre_get_avatar_data', [ self::class, 'filter_avatar_data' ], 10, 2 );

		// Offene E-Mail-Einladungen beim Registrieren verknüpfen.
		add_action( 'user_register', [ Governance::class, 'link_user_on_register' ] );

		// Vollbild-App-Shell: die Portal-Seite bekommt ein eigenes Template
		// (theme-unabhängig, Sidebar + Topbar wie die Next.js-App).
		add_filter( 'template_include', [ self::class, 'portal_template' ], 99 );

		// Private Instanz: nach außen gibt es nur das Login. Alle Frontend-Anfragen
		// Ausgeloggter gehen aufs Portal; Ausnahme nur die Rechtstexte.
		add_action( 'template_redirect', [ self::class, 'restrict_public_to_portal' ] );
	}

	/**
	 * Diese Instanz hat KEINE öffentliche Außendarstellung außer dem Login: jede
	 * Frontend-Anfrage Ausgeloggter (Startseite, Seiten, Archive, Suche, Feeds,
	 * 404 …) wird aufs Portal (= Login) umgeleitet. Ausnahmen:
	 *  - die Portal-Seite selbst (Login/App; Schleifenschutz),
	 *  - gesetzlich nötige Rechtstexte (Impressum/Datenschutz, WP-Datenschutzseite).
	 * Eingeloggte Mitglieder surfen frei; nur die Marketing-Startseite führt auch
	 * sie ins Portal. Abschaltbar per Filter `pp_restrict_public_pages`; einzelne
	 * Seiten per `pp_page_is_public` wieder öffentlich schaltbar.
	 */
	public static function restrict_public_to_portal(): void {
		// Infrastruktur (robots.txt, Favicon) nicht umleiten.
		if ( is_robots() || is_favicon() ) {
			return;
		}
		if ( ! apply_filters( 'pp_restrict_public_pages', true ) ) {
			return;
		}
		$portal_id = (int) get_option( self::PAGE_OPTION );
		if ( $portal_id <= 0 ) {
			return; // Portal noch nicht angelegt → nicht aussperren.
		}
		// Portal selbst (Login/App) nie umleiten.
		if ( (int) get_queried_object_id() === $portal_id ) {
			return;
		}

		if ( is_user_logged_in() ) {
			// Mitglieder: nur die Marketing-Startseite ins Portal lenken, sonst frei.
			if ( is_front_page() ) {
				wp_safe_redirect( self::portal_url() );
				exit;
			}
			return;
		}

		// Ausgeloggt: alles dicht außer den Rechtstexten.
		$public = (bool) apply_filters( 'pp_page_is_public', self::is_legally_public(), get_queried_object() );
		if ( $public ) {
			return;
		}
		wp_safe_redirect( self::portal_url() );
		exit;
	}

	/**
	 * Gesetzlich ohne Login erreichbar zu haltende Seiten: Impressum (§5 DDG) und
	 * Datenschutzerklärung. Erkennung über die Plugin-Shortcodes bzw. die offiziell
	 * gesetzte WP-Datenschutzseite — slug-unabhängig.
	 */
	private static function is_legally_public(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post ) {
			return false;
		}
		$privacy_id = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( $privacy_id > 0 && (int) $post->ID === $privacy_id ) {
			return true;
		}
		$content = (string) $post->post_content;
		return has_shortcode( $content, 'pp_impressum' )
			|| has_shortcode( $content, 'pp_datenschutz' );
	}

	public static function register_assets(): void {
		// Portal nutzt das gemeinsame Frontend-Stylesheet (enthält die .pp-portal-Regeln).
		wp_register_style( 'pp-frontend', PP_PLUGIN_URL . 'assets/css/frontend.css', [], PP_VERSION );
		// Kleine progressive Erweiterung (z. B. „+ Option" beim Umfrage-Anlegen).
		wp_register_script( 'pp-portal', PP_PLUGIN_URL . 'assets/js/portal.js', [], PP_VERSION, true );
		// SheetJS (gebündelt) + Inventar-Excel-Logik — nur auf der Inventar-View geladen.
		wp_register_script( 'pp-xlsx', PP_PLUGIN_URL . 'admin/js/vendor/xlsx.full.min.js', [], '0.20.3', true );
		wp_register_script( 'pp-portal-inv', PP_PLUGIN_URL . 'assets/js/portal-inventory.js', [ 'pp-xlsx' ], PP_VERSION, true );

		// Auf der Portal-Seite das Stylesheet hier einreihen — das Vollbild-Template
		// rendert erst nach wp_head(), ein späteres enqueue käme zu spät.
		if ( self::is_portal_page() ) {
			wp_enqueue_style( 'pp-frontend' );
			wp_enqueue_script( 'pp-portal' );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine View-Erkennung fürs Enqueue.
			$view = isset( $_GET['pp_view'] ) ? sanitize_key( wp_unslash( $_GET['pp_view'] ) ) : '';
			if ( 'inventory' === $view ) {
				wp_enqueue_script( 'pp-portal-inv' );
			}
		}
	}

	/** Aktuelle Anfrage ist die (singuläre) Portal-Seite? */
	public static function is_portal_page(): bool {
		$page_id = (int) get_option( self::PAGE_OPTION );
		return $page_id > 0 && is_page( $page_id );
	}

	/** template_include: Portal-Seite über das Vollbild-Shell-Template rendern. */
	public static function portal_template( string $template ): string {
		return self::is_portal_page() ? PP_PLUGIN_DIR . 'templates/portal-app.php' : $template;
	}

	/* ===================== Rollen-Helper ===================== */

	/**
	 * „Reines Mitglied" = eingeloggt, Rolle pp_member, OHNE Backend-Fähigkeiten
	 * (kein Administrator, kein Manager, kein manage_options/edit_posts). Genau
	 * diese User werden vom wp-admin ferngehalten und ins Portal geleitet.
	 */
	public static function is_member_only( ?WP_User $user = null ): bool {
		$user = $user ?: wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		// Backend-fähige User (Admin/Manager/Editor …) behalten das wp-admin.
		if ( user_can( $user, 'manage_options' ) || user_can( $user, 'edit_posts' ) || user_can( $user, Capabilities::MANAGE_GROUPS ) ) {
			return false;
		}
		return in_array( 'pp_member', (array) $user->roles, true );
	}

	/** Backend-Zugang (Admin/Manager) — für den Portal-Hinweis „zur Verwaltung". */
	public static function has_backend_access( ?WP_User $user = null ): bool {
		$user = $user ?: wp_get_current_user();
		return $user && $user->exists()
			&& ( user_can( $user, 'manage_options' ) || user_can( $user, Capabilities::MANAGE_GROUPS ) );
	}

	/* ===================== wp-admin-Schutz ===================== */

	public static function redirect_members_from_admin(): void {
		// AJAX/REST laufen ebenfalls über admin_init-nahe Pfade — nie umleiten.
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		// WICHTIG: admin-post.php (und admin-ajax.php) feuern `admin_init`, sind aber
		// die legitimen Ziele der Frontend-Formulare der Mitglieder (Dispatcher
		// `admin_post_pp_collective`). Würden wir hier umleiten, käme KEIN
		// Member-Formular je an (Voting, Kollektiv-Gründung, Inventar-Teilen, Leihen).
		$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		if ( in_array( $pagenow, [ 'admin-post.php', 'admin-ajax.php' ], true ) ) {
			return;
		}
		if ( ! self::is_member_only() ) {
			return;
		}
		wp_safe_redirect( self::portal_url() );
		exit;
	}

	public static function filter_admin_bar( $show ) {
		return self::is_member_only() ? false : $show;
	}

	/* ===================== Portal-Seite ===================== */

	public static function portal_url(): string {
		$page_id = (int) get_option( self::PAGE_OPTION );
		$url     = $page_id ? get_permalink( $page_id ) : '';
		return $url ?: home_url( '/' );
	}

	/** 'init'-Hook: Portal-Seite anlegen, falls das Upgrade-Flag gesetzt ist. */
	public static function maybe_ensure_page(): void {
		if ( get_option( self::ENSURE_FLAG ) ) {
			self::ensure_page();
			delete_option( self::ENSURE_FLAG );
		}
	}

	/**
	 * Stellt sicher, dass eine veröffentlichte Portal-Seite mit dem Shortcode
	 * existiert. Idempotent — bei Schema-Upgrade (über das Flag) und bei
	 * Aktivierung aufgerufen.
	 */
	public static function ensure_page(): void {
		$page_id = (int) get_option( self::PAGE_OPTION );
		if ( $page_id && 'page' === get_post_type( $page_id ) && 'trash' !== get_post_status( $page_id ) ) {
			return;
		}

		$existing = get_page_by_path( 'portal' );
		if ( $existing ) {
			update_option( self::PAGE_OPTION, (int) $existing->ID );
			return;
		}

		$id = wp_insert_post( [
			'post_title'   => __( 'Member portal', 'project-prepper' ),
			'post_name'    => 'portal',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
		] );
		if ( $id && ! is_wp_error( $id ) ) {
			update_option( self::PAGE_OPTION, (int) $id );
		}
	}

	/* ===================== Aktionen (admin-post) ===================== */

	/**
	 * Ein Dispatcher für alle Kollektiv-Aktionen. Nonce-geschützt, nur
	 * eingeloggt. Leitet mit ?pp_msg=<code> zur Portal-Seite zurück.
	 */
	public static function handle_collective_action(): void {
		// pp_project (falls gesetzt) bestimmt das Redirect-Ziel = Projekt-Detail,
		// damit der User nach einer Abstimmung dort bleibt. Nonce folgt direkt.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nur Redirect-Ziel; Nonce wird unmittelbar geprüft.
		$proj_id = (int) ( $_POST['pp_project'] ?? 0 );
		$back    = $proj_id > 0
			? add_query_arg( [ 'pp_view' => 'projects', 'pp_project' => $proj_id ], self::portal_url() )
			: self::portal_url();

		if ( ! is_user_logged_in() ||
			! isset( $_POST['pp_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['pp_nonce'] ), 'pp_collective' ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'error', $back ) );
			exit;
		}

		$do     = sanitize_key( wp_unslash( (string) ( $_POST['pp_do'] ?? '' ) ) );
		$inv_id = (int) ( $_POST['pp_invitation'] ?? 0 );
		$req_id = (int) ( $_POST['pp_request'] ?? 0 );
		$grp_id = (int) ( $_POST['pp_group'] ?? 0 );

		// Projekt-Governance (Beschlüsse/Umfragen): Zugriff aufs (Gruppen-)Projekt
		// erzwingen, bevor irgendeine Aktion läuft. Projects::get() gate-keept über
		// die Gruppen-Mitgliedschaft (Fremd-/Site-Projekt → null). Die einzelnen
		// Service-Calls prüfen zusätzlich Mitgliedschaft/offenen Status.
		$gov_actions = [ 'decision_vote', 'decision_create', 'decision_cancel', 'poll_vote', 'poll_create', 'poll_close', 'poll_reopen' ];
		if ( in_array( $do, $gov_actions, true ) && ( ! $proj_id || ! Projects::get( $proj_id ) ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'error', self::portal_url() ) );
			exit;
		}

		// Föderierte Leih-Entscheidungen kehren zur Verleih-Ansicht zurück.
		if ( in_array( $do, [ 'fedborrow_approve', 'fedborrow_decline', 'fedborrow_return' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'lending', self::portal_url() );
		}
		// Ausgehende Netzwerk-Anfrage kehrt zum Netzwerk-Tab zurück.
		if ( 'fed_request' === $do ) {
			$back = add_query_arg( 'pp_view', 'network', self::portal_url() );
		}
		// Workspace-Wechsel kehrt zur vorherigen Ansicht zurück (kein pp_msg).
		if ( 'set_workspace' === $do ) {
			$v    = sanitize_key( wp_unslash( (string) ( $_POST['pp_view'] ?? 'dashboard' ) ) );
			$back = 'dashboard' === $v ? self::portal_url() : add_query_arg( 'pp_view', $v, self::portal_url() );
		}
		// Gruppen-Umfrage-Aktionen kehren zum Umfragen-Tab zurück.
		if ( in_array( $do, [ 'gpoll_vote', 'gpoll_create', 'gpoll_close', 'gpoll_reopen' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'polls', self::portal_url() );
		}
		// Aus einer Gruppe austreten kehrt zur Kollektive-Ansicht zurück.
		if ( 'group_leave' === $do ) {
			$back = add_query_arg( 'pp_view', 'collectives', self::portal_url() );
		}
		// Kategorie- und Gesamt-Freigabe-Aktionen kehren zur Inventar-Ansicht zurück.
		if ( in_array( $do, [ 'category_create', 'category_adopt', 'category_delete', 'inventory_share_all', 'inventory_unshare_all', 'item_share', 'item_unshare', 'item_share_set' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'inventory', self::portal_url() );
		}
		// Anfragen-Aktionen kehren zur Anfragen-Ansicht zurück.
		if ( in_array( $do, [ 'inquiry_create', 'inquiry_update', 'inquiry_status', 'inquiry_delete' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'inquiries', self::portal_url() );
		}
		// Externe-Verleih-Aktionen kehren zur Verleih-Ansicht zurück.
		if ( in_array( $do, [ 'rental_create', 'rental_status', 'rental_delete' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'lending', self::portal_url() );
		}
		// Projekt anlegen/löschen → zurück zur Projektliste (Bearbeiten bleibt im Detail).
		if ( in_array( $do, [ 'project_create', 'project_delete' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'projects', self::portal_url() );
		}

		$result = new \WP_Error( 'pp_unknown', 'unknown' );
		$ok_msg = 'ok';

		switch ( $do ) {
			case 'found':
				$result = Governance::found(
					sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) ),
					sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_description'] ?? '' ) ) )
				);
				$ok_msg = 'founded';
				break;
			case 'invite':
				$result = Governance::invite( $grp_id, sanitize_email( wp_unslash( (string) ( $_POST['pp_email'] ?? '' ) ) ) );
				$ok_msg = 'invited';
				break;
			case 'accept':
				$result = Governance::accept( $inv_id );
				$ok_msg = 'accepted';
				break;
			case 'decline':
				$result = Governance::decline( $inv_id );
				$ok_msg = 'declined';
				break;
			case 'cancel':
				$result = Governance::cancel( $inv_id );
				$ok_msg = 'cancelled';
				break;
			case 'vote':
				$result = Governance::vote( $inv_id, sanitize_key( wp_unslash( (string) ( $_POST['pp_vote'] ?? '' ) ) ) );
				$ok_msg = 'voted';
				break;
			case 'group_leave':
				$uid    = get_current_user_id();
				$result = Groups::remove_member( $grp_id, $uid );
				// War der verlassene Workspace der aktive, zurück auf Solo.
				if ( ! is_wp_error( $result ) && (string) get_user_meta( $uid, 'pp_active_group', true ) === (string) $grp_id ) {
					update_user_meta( $uid, 'pp_active_group', 'solo' );
				}
				$ok_msg = 'group_left';
				break;
			case 'profile_save':
				$new_name = sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) );
				if ( '' === $new_name ) {
					$result = new \WP_Error( 'pp_missing_name', __( 'Please enter a display name.', 'project-prepper' ) );
				} else {
					$result = wp_update_user( [ 'ID' => get_current_user_id(), 'display_name' => $new_name ] );
				}
				$ok_msg = 'profile_saved';
				break;
			case 'item_create':
				$result = MemberInventory::create( get_current_user_id(), self::item_input() );
				$ok_msg = 'item_saved';
				break;
			case 'item_update':
				$result = MemberInventory::update( get_current_user_id(), (int) ( $_POST['pp_item'] ?? 0 ), self::item_input() );
				$ok_msg = 'item_saved';
				break;
			case 'item_delete':
				$result = MemberInventory::delete( get_current_user_id(), (int) ( $_POST['pp_item'] ?? 0 ) );
				$ok_msg = 'item_deleted';
				break;
			case 'item_share':
				$result = MemberInventory::share( get_current_user_id(), (int) ( $_POST['pp_item'] ?? 0 ), $grp_id );
				$ok_msg = 'item_shared';
				break;
			case 'item_unshare':
				$result = MemberInventory::unshare( get_current_user_id(), (int) ( $_POST['pp_item'] ?? 0 ), $grp_id );
				$ok_msg = 'item_unshared';
				break;
			case 'item_share_set':
				$pp_item = (int) ( $_POST['pp_item'] ?? 0 );
				if ( empty( $_POST['pp_shared'] ) ) {
					$result = MemberInventory::unshare( get_current_user_id(), $pp_item, $grp_id );
					$ok_msg = 'item_unshared';
				} else {
					$result = MemberInventory::set_share( get_current_user_id(), $pp_item, $grp_id, [
						'daily_rate'        => isset( $_POST['pp_rate'] ) ? wp_unslash( (string) $_POST['pp_rate'] ) : null,
						'requires_approval' => ! empty( $_POST['pp_approval'] ),
						'conditions_tags'   => array_map( 'sanitize_key', (array) wp_unslash( $_POST['pp_cond'] ?? [] ) ),
						'conditions'        => isset( $_POST['pp_conditions'] ) ? wp_unslash( (string) $_POST['pp_conditions'] ) : '',
					] );
					$ok_msg = 'item_shared';
				}
				break;
			case 'inventory_share_all':
				$result = MemberInventory::share_all( get_current_user_id(), $grp_id, [
					'daily_rate'        => isset( $_POST['pp_rate'] ) ? wp_unslash( (string) $_POST['pp_rate'] ) : null,
					'requires_approval' => ! empty( $_POST['pp_approval'] ),
					'conditions_tags'   => array_map( 'sanitize_key', (array) wp_unslash( $_POST['pp_cond'] ?? [] ) ),
					'conditions'        => isset( $_POST['pp_conditions'] ) ? wp_unslash( (string) $_POST['pp_conditions'] ) : '',
				] );
				$ok_msg = 'inventory_shared_all';
				break;
			case 'inventory_unshare_all':
				$result = MemberInventory::unshare_all( get_current_user_id(), $grp_id );
				$ok_msg = 'inventory_unshared_all';
				break;
			case 'category_create':
				$result = MemberInventory::create_category( get_current_user_id(), self::category_input() );
				$ok_msg = 'category_saved';
				break;
			case 'category_adopt':
				$result = MemberInventory::adopt_template( get_current_user_id(), (int) ( $_POST['pp_template'] ?? 0 ) );
				$ok_msg = 'category_adopted';
				break;
			case 'category_delete':
				$result = MemberInventory::delete_category( get_current_user_id(), (int) ( $_POST['pp_category_id'] ?? 0 ) );
				$ok_msg = 'category_deleted';
				break;
			case 'inquiry_create':
				$result = MemberInquiries::create( get_current_user_id(), self::active_workspace_group(), self::inquiry_input() );
				$ok_msg = 'inquiry_saved';
				break;
			case 'inquiry_update':
				$result = MemberInquiries::update( (int) ( $_POST['pp_inquiry'] ?? 0 ), get_current_user_id(), self::active_workspace_group(), self::inquiry_input() );
				$ok_msg = 'inquiry_saved';
				break;
			case 'inquiry_status':
				$result = MemberInquiries::set_status( (int) ( $_POST['pp_inquiry'] ?? 0 ), get_current_user_id(), self::active_workspace_group(), sanitize_key( wp_unslash( (string) ( $_POST['pp_status'] ?? '' ) ) ) );
				$ok_msg = 'inquiry_status';
				break;
			case 'inquiry_delete':
				$result = MemberInquiries::delete( (int) ( $_POST['pp_inquiry'] ?? 0 ), get_current_user_id(), self::active_workspace_group() );
				$ok_msg = 'inquiry_deleted';
				break;
			case 'inquiry_to_project':
				$result = MemberInquiries::to_project( (int) ( $_POST['pp_inquiry'] ?? 0 ), get_current_user_id(), self::active_workspace_group() );
				$ok_msg = 'inquiry_converted';
				break;
			case 'rental_create':
				$in     = self::rental_input();
				$result = MemberRentals::create( get_current_user_id(), $in['data'], $in['items'] );
				$ok_msg = 'rental_saved';
				break;
			case 'rental_status':
				$result = MemberRentals::set_status( (int) ( $_POST['pp_rental'] ?? 0 ), get_current_user_id(), sanitize_key( wp_unslash( (string) ( $_POST['pp_status'] ?? '' ) ) ) );
				$ok_msg = 'rental_status';
				break;
			case 'rental_delete':
				$result = MemberRentals::delete( (int) ( $_POST['pp_rental'] ?? 0 ), get_current_user_id() );
				$ok_msg = 'rental_deleted';
				break;
			case 'project_create':
				$result = self::member_create_project();
				$ok_msg = 'project_saved';
				break;
			case 'project_update':
				$result = self::member_update_project( (int) ( $_POST['pp_project'] ?? 0 ) );
				$ok_msg = 'project_saved';
				break;
			case 'project_delete':
				$result = self::member_delete_project( (int) ( $_POST['pp_project'] ?? 0 ) );
				$ok_msg = 'project_deleted';
				break;
			case 'borrow_request':
				$result = Borrowing::request(
					get_current_user_id(),
					(int) ( $_POST['pp_item'] ?? 0 ),
					$grp_id,
					sanitize_text_field( wp_unslash( (string) ( $_POST['pp_from'] ?? '' ) ) ),
					sanitize_text_field( wp_unslash( (string) ( $_POST['pp_to'] ?? '' ) ) ),
					sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_message'] ?? '' ) ) )
				);
				$ok_msg = 'borrow_requested';
				break;
			case 'borrow_approve':
				$result = Borrowing::approve( get_current_user_id(), $req_id );
				$ok_msg = 'borrow_decided';
				break;
			case 'borrow_decline':
				$result = Borrowing::decline( get_current_user_id(), $req_id );
				$ok_msg = 'borrow_decided';
				break;
			case 'borrow_cancel':
				$result = Borrowing::cancel( get_current_user_id(), $req_id );
				$ok_msg = 'borrow_cancelled';
				break;
			case 'borrow_return':
				$result = Borrowing::mark_returned( get_current_user_id(), $req_id );
				$ok_msg = 'borrow_returned';
				break;
			case 'decision_vote':
				$result = Decisions::cast_vote(
					(int) ( $_POST['pp_decision'] ?? 0 ),
					get_current_user_id(),
					sanitize_key( wp_unslash( (string) ( $_POST['pp_vote'] ?? '' ) ) )
				);
				$ok_msg = 'voted';
				break;
			case 'decision_create':
				$result = Decisions::create( $proj_id, [
					'title'              => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_title'] ?? '' ) ) ),
					'description'        => sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_description'] ?? '' ) ) ),
					'requires_unanimous' => ! empty( $_POST['pp_unanimous'] ),
				] );
				$ok_msg = 'decision_created';
				break;
			case 'decision_cancel':
				$result = Decisions::cancel( (int) ( $_POST['pp_decision'] ?? 0 ), get_current_user_id() );
				$ok_msg = 'decision_closed';
				break;
			case 'poll_vote':
				$result = Polls::cast_vote(
					(int) ( $_POST['pp_option'] ?? 0 ),
					get_current_user_id(),
					sanitize_key( wp_unslash( (string) ( $_POST['pp_vote'] ?? '' ) ) )
				);
				$ok_msg = 'voted';
				break;
			case 'poll_create':
				$result = Polls::create( $proj_id, self::poll_input() );
				$ok_msg = 'poll_created';
				break;
			case 'poll_close':
				$result = Polls::close( (int) ( $_POST['pp_poll'] ?? 0 ), get_current_user_id() );
				$ok_msg = 'poll_closed';
				break;
			case 'poll_reopen':
				$result = Polls::reopen( (int) ( $_POST['pp_poll'] ?? 0 ), get_current_user_id() );
				$ok_msg = 'poll_reopened';
				break;
			case 'gpoll_vote':
				$result = Polls::cast_vote(
					(int) ( $_POST['pp_option'] ?? 0 ),
					get_current_user_id(),
					sanitize_key( wp_unslash( (string) ( $_POST['pp_vote'] ?? '' ) ) )
				);
				$ok_msg = 'voted';
				break;
			case 'gpoll_create':
				$result = Polls::create_group( (int) ( $_POST['pp_group'] ?? 0 ), self::poll_input() );
				$ok_msg = 'poll_created';
				break;
			case 'gpoll_close':
				$result = Polls::close( (int) ( $_POST['pp_poll'] ?? 0 ), get_current_user_id() );
				$ok_msg = 'poll_closed';
				break;
			case 'gpoll_reopen':
				$result = Polls::reopen( (int) ( $_POST['pp_poll'] ?? 0 ), get_current_user_id() );
				$ok_msg = 'poll_reopened';
				break;
			case 'fedborrow_approve':
				$result = FederatedBorrow::decide( get_current_user_id(), (int) ( $_POST['pp_fedreq'] ?? 0 ), 'approve' );
				$ok_msg = 'fed_decided';
				break;
			case 'fedborrow_decline':
				$result = FederatedBorrow::decide( get_current_user_id(), (int) ( $_POST['pp_fedreq'] ?? 0 ), 'decline' );
				$ok_msg = 'fed_decided';
				break;
			case 'fedborrow_return':
				$result = FederatedBorrow::mark_returned( get_current_user_id(), (int) ( $_POST['pp_fedreq'] ?? 0 ) );
				$ok_msg = 'borrow_returned';
				break;
			case 'set_workspace':
				$ws  = sanitize_text_field( wp_unslash( (string) ( $_POST['pp_ws'] ?? '' ) ) );
				$uid = get_current_user_id();
				if ( 'solo' === $ws ) {
					update_user_meta( $uid, 'pp_active_group', 'solo' );
				} else {
					$gid = (int) $ws;
					if ( $gid && Groups::is_member( $gid, $uid ) ) {
						update_user_meta( $uid, 'pp_active_group', (string) $gid );
					}
				}
				$result = true;
				$ok_msg = 'ok';
				break;
			case 'fed_request':
				$result = FederatedBorrow::request_outbound( get_current_user_id(), [
					'partner_url'     => wp_unslash( (string) ( $_POST['pp_partner'] ?? '' ) ),
					'item_id'         => (int) ( $_POST['pp_item'] ?? 0 ),
					'item_label'      => wp_unslash( (string) ( $_POST['pp_item_label'] ?? '' ) ),
					'item_detail_url' => wp_unslash( (string) ( $_POST['pp_detail_url'] ?? '' ) ),
					'date_from'       => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_from'] ?? '' ) ) ),
					'date_to'         => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_to'] ?? '' ) ) ),
					'message'         => wp_unslash( (string) ( $_POST['pp_message'] ?? '' ) ),
				] );
				$ok_msg = 'fed_requested';
				break;
		}

		if ( is_wp_error( $result ) ) {
			// Spezifische, hilfreiche Meldungen, sonst generisch.
			$code = $result->get_error_code();
			if ( 'pp_no_units' === $code ) {
				$msg = 'borrow_unavailable';
			} elseif ( 'pp_last_founder' === $code ) {
				$msg = 'leave_last_founder';
			} elseif ( 'pp_not_available' === $code ) {
				$msg = 'rental_unavailable';
			} else {
				$msg = 'error';
			}
		} else {
			$msg = $ok_msg;
		}
		// Anfrage→Projekt: bei Erfolg direkt zum neuen Projekt springen, sonst
		// zurück zur Anfragen-Ansicht.
		if ( 'inquiry_to_project' === $do ) {
			$back = is_wp_error( $result )
				? add_query_arg( 'pp_view', 'inquiries', self::portal_url() )
				: add_query_arg( [ 'pp_view' => 'projects', 'pp_project' => (int) $result ], self::portal_url() );
		}
		wp_safe_redirect( add_query_arg( 'pp_msg', $msg, $back ) );
		exit;
	}

	/** Statusmeldungen für ?pp_msg — Code → menschenlesbarer Text. */
	private static function messages(): array {
		return [
			'founded'   => [ 'ok', __( 'Collective founded. You are its founder.', 'project-prepper' ) ],
			'feedback_ok'  => [ 'ok', __( 'Thanks for your feedback!', 'project-prepper' ) ],
			'feedback_err' => [ 'err', __( 'Please enter a message.', 'project-prepper' ) ],
			'invited'   => [ 'ok', __( 'Invitation sent.', 'project-prepper' ) ],
			'accepted'  => [ 'ok', __( 'Invitation accepted.', 'project-prepper' ) ],
			'declined'  => [ 'ok', __( 'Invitation declined.', 'project-prepper' ) ],
			'cancelled' => [ 'ok', __( 'Invitation cancelled.', 'project-prepper' ) ],
			'voted'         => [ 'ok', __( 'Your vote was recorded.', 'project-prepper' ) ],
			'item_saved'    => [ 'ok', __( 'Item saved.', 'project-prepper' ) ],
			'item_deleted'  => [ 'ok', __( 'Item deleted.', 'project-prepper' ) ],
			'item_shared'   => [ 'ok', __( 'Item shared with the collective.', 'project-prepper' ) ],
			'item_unshared'    => [ 'ok', __( 'Item is no longer shared.', 'project-prepper' ) ],
			'inventory_shared_all'   => [ 'ok', __( 'Your whole inventory is now shared with the collective.', 'project-prepper' ) ],
			'inventory_unshared_all' => [ 'ok', __( 'Your inventory is no longer shared with the collective.', 'project-prepper' ) ],
			'category_saved'   => [ 'ok', __( 'Category saved.', 'project-prepper' ) ],
			'category_adopted' => [ 'ok', __( 'Template category adopted.', 'project-prepper' ) ],
			'category_deleted' => [ 'ok', __( 'Category deleted.', 'project-prepper' ) ],
			'inquiry_saved'    => [ 'ok', __( 'Inquiry saved.', 'project-prepper' ) ],
			'inquiry_status'   => [ 'ok', __( 'Inquiry status updated.', 'project-prepper' ) ],
			'inquiry_deleted'  => [ 'ok', __( 'Inquiry deleted.', 'project-prepper' ) ],
			'inquiry_converted' => [ 'ok', __( 'Inquiry turned into a project.', 'project-prepper' ) ],
			'rental_saved'     => [ 'ok', __( 'Rental created.', 'project-prepper' ) ],
			'rental_status'    => [ 'ok', __( 'Rental status updated.', 'project-prepper' ) ],
			'rental_deleted'   => [ 'ok', __( 'Rental deleted.', 'project-prepper' ) ],
			'rental_unavailable' => [ 'err', __( 'One of the items is not available in that period. Please adjust the dates or quantity.', 'project-prepper' ) ],
			'project_saved'    => [ 'ok', __( 'Project saved.', 'project-prepper' ) ],
			'project_deleted'  => [ 'ok', __( 'Project deleted.', 'project-prepper' ) ],
			'borrow_requested' => [ 'ok', __( 'Borrow request sent to the owner.', 'project-prepper' ) ],
			'borrow_decided'   => [ 'ok', __( 'Request updated.', 'project-prepper' ) ],
			'borrow_cancelled' => [ 'ok', __( 'Request cancelled.', 'project-prepper' ) ],
			'borrow_returned'  => [ 'ok', __( 'Marked as returned.', 'project-prepper' ) ],
			'decision_created' => [ 'ok', __( 'Decision created.', 'project-prepper' ) ],
			'decision_closed'  => [ 'ok', __( 'Decision closed.', 'project-prepper' ) ],
			'poll_created'     => [ 'ok', __( 'Poll created.', 'project-prepper' ) ],
			'poll_closed'      => [ 'ok', __( 'Poll closed.', 'project-prepper' ) ],
			'poll_reopened'    => [ 'ok', __( 'Poll reopened.', 'project-prepper' ) ],
			'fed_decided'      => [ 'ok', __( 'Request updated.', 'project-prepper' ) ],
			'fed_requested'    => [ 'ok', __( 'Borrow request sent to the partner instance.', 'project-prepper' ) ],
			'import_nofile'    => [ 'err', __( 'Please choose a CSV file to import.', 'project-prepper' ) ],
			'photo_saved'      => [ 'ok', __( 'Photo saved.', 'project-prepper' ) ],
			'photo_removed'    => [ 'ok', __( 'Photo removed.', 'project-prepper' ) ],
			'photo_failed'     => [ 'err', __( 'The image could not be uploaded. Please use a JPG, PNG, GIF or WebP file.', 'project-prepper' ) ],
			'profile_saved'    => [ 'ok', __( 'Profile updated.', 'project-prepper' ) ],
			'avatar_saved'     => [ 'ok', __( 'Profile photo saved.', 'project-prepper' ) ],
			'avatar_removed'   => [ 'ok', __( 'Profile photo removed.', 'project-prepper' ) ],
			'avatar_failed'    => [ 'err', __( 'The image could not be uploaded. Please use a JPG, PNG, GIF or WebP file.', 'project-prepper' ) ],
			'doc_saved'        => [ 'ok', __( 'Document uploaded.', 'project-prepper' ) ],
			'doc_removed'      => [ 'ok', __( 'Document removed.', 'project-prepper' ) ],
			'doc_failed'       => [ 'err', __( 'The document could not be uploaded. Please use a PDF or image file.', 'project-prepper' ) ],
			'borrow_unavailable' => [ 'err', __( 'No units of this item are free in that period. Please pick other dates.', 'project-prepper' ) ],
			'group_left'         => [ 'ok', __( 'You have left the group.', 'project-prepper' ) ],
			'leave_last_founder' => [ 'err', __( 'As the last founder you cannot leave. Appoint another founder or delete the group instead.', 'project-prepper' ) ],
			'error'            => [ 'err', __( 'Something went wrong. Please try again.', 'project-prepper' ) ],
		];
	}

	/** Sanitisierte Item-Felder aus dem Inventar-Formular (Nonce bereits geprüft). */
	private static function item_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		$tags_raw = sanitize_text_field( wp_unslash( (string) ( $_POST['pp_tags'] ?? '' ) ) );
		$tags     = '' !== $tags_raw ? array_values( array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) ) ) : [];
		return [
			'name'          => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) ),
			'category_id'   => (int) ( $_POST['pp_category'] ?? 0 ),
			'quantity'      => max( 1, (int) ( $_POST['pp_quantity'] ?? 1 ) ),
			'condition'     => sanitize_key( wp_unslash( (string) ( $_POST['pp_condition'] ?? 'good' ) ) ),
			'cost_per_day'  => '' !== ( $_POST['pp_cost'] ?? '' ) ? (float) $_POST['pp_cost'] : '',
			'manufacturer'  => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_manufacturer'] ?? '' ) ) ),
			'model'         => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_model'] ?? '' ) ) ),
			'serial_number' => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_serial'] ?? '' ) ) ),
			'location'      => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_location'] ?? '' ) ) ),
			'dimensions'    => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_dimensions'] ?? '' ) ) ),
			'tags'          => $tags,
			'description'   => sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_description'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Umfrage-Eingaben aus dem „Neue Umfrage"-Formular (Nonce im Dispatcher
	 * geprüft). Optionen kommen als Textarea (eine pro Zeile); bei date-Umfragen
	 * je Zeile „JJJJ-MM-TT" optional gefolgt von „ HH:MM". Polls::create
	 * validiert (≥2 gültige Optionen, gültige Daten).
	 */
	private static function poll_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		$type = sanitize_key( wp_unslash( (string) ( $_POST['pp_poll_type'] ?? 'choice' ) ) );
		if ( ! in_array( $type, [ 'date', 'choice' ], true ) ) {
			$type = 'choice';
		}
		// Je Option eine eigene Box (pp_opt[]); leere werden ignoriert.
		$boxes   = isset( $_POST['pp_opt'] ) && is_array( $_POST['pp_opt'] ) ? wp_unslash( $_POST['pp_opt'] ) : [];
		$options = [];
		foreach ( $boxes as $box ) {
			$box = trim( (string) $box );
			if ( '' === $box ) {
				continue;
			}
			if ( 'date' === $type ) {
				$parts     = preg_split( '/\s+/', $box );
				$options[] = [ 'option_date' => sanitize_text_field( $parts[0] ), 'option_time' => isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '' ];
			} else {
				$options[] = [ 'label' => sanitize_text_field( $box ) ];
			}
		}
		return [
			'title'       => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_title'] ?? '' ) ) ),
			'description' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_description'] ?? '' ) ) ),
			'poll_type'   => $type,
			'options'     => $options,
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/* ===================== Rendering ===================== */

	/** Shortcode-Einstieg (eingebettet). Die Portal-Seite nutzt portal-app.php. */
	public static function render(): string {
		wp_enqueue_style( 'pp-frontend' );
		return self::render_body();
	}

	/** Body-Inhalt — vom Vollbild-Template UND vom eingebetteten Shortcode genutzt. */
	public static function render_body(): string {
		if ( ! is_user_logged_in() ) {
			return self::render_login();
		}
		// Impersonation-Banner (docs/06 §5): sieht ein Betreiber gerade „als User",
		// zeigen wir oben ein Banner mit Rückweg und überspringen das AGB-Gate
		// (der Betreiber sieht nur, akzeptiert nichts im Namen des Mitglieds).
		$imp = \ProjectPrepper\Impersonation::banner();
		if ( '' !== $imp ) {
			return $imp . self::render_app();
		}
		// AGB-Gate (docs/06 §10.4): wenn der Betreiber Zustimmung verlangt und die
		// aktuelle AGB-Version noch nicht akzeptiert ist, zuerst akzeptieren lassen.
		// Betreiber (pp_operate) sind ausgenommen — sie setzen die AGB selbst.
		if ( self::terms_pending( get_current_user_id() ) ) {
			return self::render_terms_gate();
		}
		return self::render_app();
	}

	const META_TERMS_ACCEPTED = 'pp_agb_accepted_version';

	/** Muss dieser User die aktuelle AGB-Version noch akzeptieren? */
	public static function terms_pending( int $user_id ): bool {
		if ( ! $user_id || ! \ProjectPrepper\Platform::terms_required() ) {
			return false;
		}
		if ( user_can( $user_id, \ProjectPrepper\Capabilities::OPERATE ) ) {
			return false; // Betreiber setzen die AGB, akzeptieren sie nicht.
		}
		$accepted = (int) get_user_meta( $user_id, self::META_TERMS_ACCEPTED, true );
		return $accepted < \ProjectPrepper\Platform::terms_version();
	}

	/** Vollbild-Zustimmungsschirm: AGB-Text + Akzeptieren / Abmelden. */
	private static function render_terms_gate(): string {
		$text = \ProjectPrepper\Platform::terms_text();
		ob_start();
		?>
		<div class="pp-front pp-portal pp-portal--login">
			<h2 class="pp-portal__title"><?php esc_html_e( 'Terms of use', 'project-prepper' ); ?></h2>
			<p class="pp-portal__lead"><?php esc_html_e( 'Please review and accept the terms to continue using the portal.', 'project-prepper' ); ?></p>
			<div class="pp-terms"><?php echo nl2br( esc_html( $text ) ); ?></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem;">
				<input type="hidden" name="action" value="pp_accept_terms">
				<?php wp_nonce_field( 'pp_accept_terms', 'pp_nonce' ); ?>
				<label style="display:flex;align-items:flex-start;gap:8px;">
					<input type="checkbox" name="pp_agree" value="1" required style="margin-top:.25rem;">
					<span><?php esc_html_e( 'I have read and accept the terms of use.', 'project-prepper' ); ?></span>
				</label>
				<button type="submit" class="pp-portal__btn" style="margin-top:.75rem;"><?php esc_html_e( 'Accept and continue', 'project-prepper' ); ?></button>
			</form>
			<p class="pp-portal__note">
				<a href="<?php echo esc_url( wp_logout_url( self::portal_url() ) ); ?>"><?php esc_html_e( 'Decline and sign out', 'project-prepper' ); ?></a>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** Akzeptiert die aktuelle AGB-Version für den eingeloggten User. */
	public static function handle_accept_terms(): void {
		$back = self::portal_url();
		if ( ! is_user_logged_in() || ! isset( $_POST['pp_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['pp_nonce'] ), 'pp_accept_terms' )
			|| empty( $_POST['pp_agree'] ) ) {
			wp_safe_redirect( $back );
			exit;
		}
		update_user_meta( get_current_user_id(), self::META_TERMS_ACCEPTED, \ProjectPrepper\Platform::terms_version() );
		wp_safe_redirect( $back );
		exit;
	}

	/** Mitglieder-Feedback aus dem Portal-Modal entgegennehmen. */
	public static function handle_feedback(): void {
		$back = self::portal_url();
		if ( ! is_user_logged_in() || ! isset( $_POST['pp_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['pp_nonce'] ), 'pp_member_feedback' ) ) {
			wp_safe_redirect( $back );
			exit;
		}
		$route = isset( $_POST['pp_route'] ) ? esc_url_raw( wp_unslash( (string) $_POST['pp_route'] ) ) : '';
		$res   = \ProjectPrepper\Services\Feedback::create(
			get_current_user_id(),
			sanitize_key( wp_unslash( (string) ( $_POST['pp_type'] ?? 'other' ) ) ),
			wp_unslash( (string) ( $_POST['pp_message'] ?? '' ) ),
			$route
		);
		$target = $route ?: $back;
		wp_safe_redirect( add_query_arg( 'pp_msg', is_wp_error( $res ) ? 'feedback_err' : 'feedback_ok', $target ) );
		exit;
	}

	private static function render_login(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reine Status-Anzeige
		$login_msg  = isset( $_GET['pp_login'] ) ? sanitize_key( wp_unslash( $_GET['pp_login'] ) ) : '';
		$reg_msg    = isset( $_GET['pp_reg'] ) ? sanitize_key( wp_unslash( $_GET['pp_reg'] ) ) : '';
		$two_factor = Security::on( 'member_2fa' );
		$pending    = $two_factor && MemberAuth::has_pending() && isset( $_GET['pp_2fa'] );
		$can_register = Security::on( 'allow_self_registration' );
		$reg_errors = [
			'invalid'  => __( 'Please enter a valid email address.', 'project-prepper' ),
			'exists'   => __( 'An account with that email already exists. Please sign in.', 'project-prepper' ),
			'weakpass' => __( 'Please choose a password with at least 8 characters.', 'project-prepper' ),
			'closed'   => __( 'Self-registration is currently closed.', 'project-prepper' ),
			'failed'   => __( 'Registration failed. Please try again.', 'project-prepper' ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		ob_start();
		?>
		<div class="pp-front pp-portal pp-portal--login">
			<h2 class="pp-portal__title"><?php esc_html_e( 'Member login', 'project-prepper' ); ?></h2>

			<?php if ( 'failed' === $login_msg ) : ?>
				<div class="pp-portal__notice pp-portal__notice--err"><?php esc_html_e( 'Login failed. Please check your details and try again.', 'project-prepper' ); ?></div>
			<?php elseif ( 'code' === $login_msg ) : ?>
				<div class="pp-portal__notice pp-portal__notice--err"><?php esc_html_e( 'That code was not correct. Please try again.', 'project-prepper' ); ?></div>
			<?php elseif ( 'resent' === $login_msg ) : ?>
				<div class="pp-portal__notice pp-portal__notice--ok"><?php esc_html_e( 'A new code is on its way to your email.', 'project-prepper' ); ?></div>
			<?php elseif ( 'resend_limit' === $login_msg ) : ?>
				<div class="pp-portal__notice pp-portal__notice--err"><?php esc_html_e( 'You have requested too many codes. Please wait a moment and try again.', 'project-prepper' ); ?></div>
			<?php endif; ?>

			<?php if ( '' !== $reg_msg && isset( $reg_errors[ $reg_msg ] ) ) : ?>
				<div class="pp-portal__notice pp-portal__notice--err"><?php echo esc_html( $reg_errors[ $reg_msg ] ); ?></div>
			<?php endif; ?>

			<?php if ( $pending ) : /* ---- Schritt 2: Code ---- */ ?>
				<p class="pp-portal__lead"><?php esc_html_e( 'We sent a one-time code to your email. Enter it to finish signing in.', 'project-prepper' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pp_member_2fa">
					<?php wp_nonce_field( 'pp_member_2fa', 'pp_nonce' ); ?>
					<label for="pp-2fa-code"><?php esc_html_e( 'Login code', 'project-prepper' ); ?></label>
					<input type="text" id="pp-2fa-code" name="pp_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" required>
					<button type="submit" class="pp-portal__btn"><?php esc_html_e( 'Confirm code', 'project-prepper' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.5rem;">
					<input type="hidden" name="action" value="pp_member_2fa_resend">
					<?php wp_nonce_field( 'pp_member_2fa', 'pp_nonce' ); ?>
					<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Resend code', 'project-prepper' ); ?></button>
				</form>

			<?php elseif ( $two_factor ) : /* ---- Schritt 1: eigenes Formular (2FA aktiv) ---- */ ?>
				<p class="pp-portal__lead"><?php esc_html_e( 'Sign in to manage your inventory, your collectives and shared resources.', 'project-prepper' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pp_member_login">
					<?php wp_nonce_field( 'pp_member_login', 'pp_nonce' ); ?>
					<label for="pp-login-user"><?php esc_html_e( 'Email or username', 'project-prepper' ); ?></label>
					<input type="text" id="pp-login-user" name="log" required>
					<label for="pp-login-pass"><?php esc_html_e( 'Password', 'project-prepper' ); ?></label>
					<input type="password" id="pp-login-pass" name="pwd" required>
					<p class="login-remember"><label><input type="checkbox" name="rememberme" value="1"> <?php esc_html_e( 'Remember me', 'project-prepper' ); ?></label></p>
					<button type="submit" class="pp-portal__btn"><?php esc_html_e( 'Sign in', 'project-prepper' ); ?></button>
				</form>

			<?php else : /* ---- Standard-Login (2FA aus) ---- */
				wp_login_form( [
					'redirect'       => self::portal_url(),
					'label_username' => __( 'Email or username', 'project-prepper' ),
					'label_password' => __( 'Password', 'project-prepper' ),
					'label_log_in'   => __( 'Sign in', 'project-prepper' ),
					'remember'       => true,
				] );
			endif; ?>

			<?php if ( $can_register && ! $pending ) : /* ---- Selbst-Registrierung (Schalter an) ---- */ ?>
				<hr style="margin:1.5rem 0;border:none;border-top:1px solid var(--pp-border);">
				<details class="pp-portal__add"<?php echo '' !== $reg_msg ? ' open' : ''; ?>>
					<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'New here? Create an account', 'project-prepper' ); ?></summary>
					<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.75rem;">
						<input type="hidden" name="action" value="pp_member_register">
						<?php wp_nonce_field( 'pp_member_register', 'pp_nonce' ); ?>
						<label><?php esc_html_e( 'Name', 'project-prepper' ); ?>
							<input type="text" name="pp_name" required>
						</label>
						<label><?php esc_html_e( 'Email', 'project-prepper' ); ?>
							<input type="email" name="pp_email" required>
						</label>
						<label><?php esc_html_e( 'Password (min. 8 characters)', 'project-prepper' ); ?>
							<input type="password" name="pp_password" minlength="8" required>
						</label>
						<input type="text" name="pp_website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;">
						<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Create account', 'project-prepper' ); ?></button>
					</form>
				</details>
			<?php endif; ?>

			<p class="pp-portal__note">
				<?php if ( ! $can_register ) : ?>
					<?php esc_html_e( 'Access is by invitation only. Ask the platform operators to set up an account for you.', 'project-prepper' ); ?>
					<br>
				<?php endif; ?>
				<a href="<?php echo esc_url( wp_lostpassword_url( self::portal_url() ) ); ?>"><?php esc_html_e( 'Forgot your password?', 'project-prepper' ); ?></a>
			</p>

			<?php
			// Rechtstexte: einzige neben dem Login öffentlich erreichbaren Seiten —
			// daher hier direkt verlinkt (Impressum-Pflicht, § 5 DDG).
			$pp_legal = Legal::links();
			if ( $pp_legal['impressum'] || $pp_legal['datenschutz'] ) : ?>
				<p class="pp-portal__legal">
					<?php if ( $pp_legal['impressum'] ) : ?>
						<a href="<?php echo esc_url( $pp_legal['impressum'] ); ?>"><?php esc_html_e( 'Imprint', 'project-prepper' ); ?></a>
					<?php endif; ?>
					<?php if ( $pp_legal['impressum'] && $pp_legal['datenschutz'] ) : ?> · <?php endif; ?>
					<?php if ( $pp_legal['datenschutz'] ) : ?>
						<a href="<?php echo esc_url( $pp_legal['datenschutz'] ); ?>"><?php esc_html_e( 'Privacy policy', 'project-prepper' ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ===================== App-Shell (Vollbild, 1:1 zur Next.js-App) ===================== */

	/** Komplette App-Shell: dunkle Sidebar + Topbar + view-basierter Inhalt. */
	private static function render_app(): string {
		$user   = wp_get_current_user();
		$groups = Groups::user_groups( (int) $user->ID );
		$view   = self::current_view();

		ob_start();
		?>
		<div class="pp-app">
			<input type="checkbox" id="pp-nav-toggle" class="pp-app__nav-cb" hidden>
			<label for="pp-nav-toggle" class="pp-app__overlay" aria-hidden="true"></label>
			<?php self::render_sidebar( $user, $groups, $view ); ?>
			<div class="pp-app__main-wrap">
				<?php self::render_topbar( $user ); ?>
				<main class="pp-app__main">
					<div class="pp-front pp-app__content">
						<?php
						self::render_message();
						switch ( $view ) {
							case 'inventory':
								self::view_inventory( $user, $groups );
								break;
							case 'lending':
								self::view_lending( $user, $groups );
								break;
							case 'projects':
								self::view_projects( $user, $groups );
								break;
							case 'inquiries':
								self::view_inquiries( $user, $groups );
								break;
							case 'calendar':
								self::view_calendar( $user, $groups );
								break;
							case 'costs':
								self::view_costs( $user, $groups );
								break;
							case 'polls':
								self::view_polls( $groups );
								break;
							case 'network':
								self::view_network();
								break;
							case 'collectives':
								self::view_collectives( $user, $groups );
								break;
							default:
								self::view_dashboard( $user, $groups );
						}
						?>
					</div>
				</main>
			</div>
			<?php self::render_feedback_modal(); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** Feedback-Modal (global in der App-Shell) — Bug/Idee/Sonstiges an die Betreiber. */
	private static function render_feedback_modal(): void {
		$route = add_query_arg( 'pp_view', self::current_view(), self::portal_url() );
		?>
		<dialog class="pp-modal pp-modal--portal" id="pp-feedback-modal">
			<div class="pp-modal-header">
				<h2 class="pp-modal__title"><?php esc_html_e( 'Send feedback', 'project-prepper' ); ?></h2>
				<button type="button" class="pp-modal-close" data-pp-modal-close aria-label="<?php esc_attr_e( 'Close', 'project-prepper' ); ?>">✕</button>
			</div>
			<div class="pp-modal-body">
				<p class="pp-portal__hint"><?php esc_html_e( 'Found a bug or have an idea? Let the operators know.', 'project-prepper' ); ?></p>
				<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pp_member_feedback">
					<?php wp_nonce_field( 'pp_member_feedback', 'pp_nonce' ); ?>
					<input type="hidden" name="pp_route" value="<?php echo esc_url( $route ); ?>">
					<label><?php esc_html_e( 'Type', 'project-prepper' ); ?>
						<select name="pp_type">
							<?php foreach ( Feedback::types() as $pp_k => $pp_label ) : ?>
								<option value="<?php echo esc_attr( $pp_k ); ?>"><?php echo esc_html( $pp_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><?php esc_html_e( 'Your message', 'project-prepper' ); ?>
						<textarea name="pp_message" rows="4" required></textarea>
					</label>
					<button type="submit" class="pp-portal__btn"><?php esc_html_e( 'Send feedback', 'project-prepper' ); ?></button>
				</form>
			</div>
		</dialog>
		<?php
	}

	/** Erlaubte Views — Default Dashboard. */
	private static function current_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Navigation
		$view    = isset( $_GET['pp_view'] ) ? sanitize_key( wp_unslash( $_GET['pp_view'] ) ) : 'dashboard';
		$allowed = [ 'dashboard', 'inventory', 'lending', 'projects', 'inquiries', 'calendar', 'costs', 'polls', 'network', 'collectives' ];
		return in_array( $view, $allowed, true ) ? $view : 'dashboard';
	}

	/**
	 * Sidebar-Navigationspunkte. Die Labels wechseln mit dem Arbeitsbereich (wie
	 * in der App): Solo → „Mein/Meine …", Gruppe → schlicht „…" / „Alle Gruppen".
	 */
	private static function nav_items( int $active ): array {
		$solo  = ( 0 === $active );
		$items = [
			[ 'view' => 'dashboard', 'icon' => 'dashboard', 'label' => __( 'Dashboard', 'project-prepper' ) ],
			[ 'view' => 'inventory', 'icon' => 'inventory', 'label' => $solo ? __( 'My inventory', 'project-prepper' ) : __( 'Inventory', 'project-prepper' ) ],
			[ 'view' => 'lending',   'icon' => 'package',   'label' => $solo ? __( 'My lending', 'project-prepper' ) : __( 'Lending', 'project-prepper' ) ],
			[ 'view' => 'projects',  'icon' => 'projects',  'label' => $solo ? __( 'My projects', 'project-prepper' ) : __( 'Projects', 'project-prepper' ) ],
			[ 'view' => 'inquiries', 'icon' => 'inbox',     'label' => $solo ? __( 'My inquiries', 'project-prepper' ) : __( 'Inquiries', 'project-prepper' ) ],
			[ 'view' => 'calendar',  'icon' => 'calendar',  'label' => __( 'Calendar', 'project-prepper' ) ],
		];
		// Eigenständige Umfragen + globale Kostenübersicht NUR im Gruppen-Modus
		// (wie die App: Solo zeigt Kosten/Umfragen direkt im Projekt bzw. gar nicht).
		if ( ! $solo ) {
			$items[] = [ 'view' => 'costs', 'icon' => 'costs', 'label' => __( 'Costs', 'project-prepper' ) ];
			$items[] = [ 'view' => 'polls', 'icon' => 'clipboard', 'label' => __( 'Polls', 'project-prepper' ) ];
		}
		$items[] = [ 'view' => 'network',     'icon' => 'globe', 'label' => __( 'Network', 'project-prepper' ) ];
		$items[] = [ 'view' => 'collectives', 'icon' => 'users', 'label' => $solo ? __( 'My groups', 'project-prepper' ) : __( 'All groups', 'project-prepper' ) ];
		return $items;
	}

	/** URL einer View auf der Portal-Seite. */
	private static function view_url( string $view ): string {
		$base = self::portal_url();
		return 'dashboard' === $view ? $base : add_query_arg( 'pp_view', $view, $base );
	}

	private static function render_sidebar( WP_User $user, array $groups, string $view ): void {
		$count = count( $groups );
		?>
		<aside class="pp-app__sidebar">
			<a class="pp-app__brand" href="<?php echo esc_url( self::view_url( 'dashboard' ) ); ?>">
				<span class="pp-app__brand-mark">P</span>
				<span class="pp-app__brand-name">Project Prepper</span>
			</a>

			<?php
			$active       = self::active_group_id( $groups );
			$active_label = __( 'Solo', 'project-prepper' );
			foreach ( $groups as $g ) {
				if ( (int) $g->id === $active ) {
					$active_label = $g->name;
					break;
				}
			}
			?>
			<details class="pp-app__ws">
				<summary class="pp-app__workspace">
					<span class="pp-app__ws-text">
						<span class="pp-app__workspace-label"><?php esc_html_e( 'Workspace', 'project-prepper' ); ?></span>
						<span class="pp-app__workspace-name"><?php echo esc_html( $active_label ); ?></span>
					</span>
					<span class="pp-app__ws-caret">▾</span>
				</summary>
				<div class="pp-app__ws-menu">
					<?php
					$ws_options = [ [ 'ws' => 'solo', 'label' => __( 'Solo', 'project-prepper' ), 'is' => ( 0 === $active ) ] ];
					foreach ( $groups as $g ) {
						$ws_options[] = [ 'ws' => (string) (int) $g->id, 'label' => $g->name, 'is' => ( (int) $g->id === $active ) ];
					}
					foreach ( $ws_options as $opt ) :
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php self::action_fields( 'set_workspace' ); ?>
							<input type="hidden" name="pp_ws" value="<?php echo esc_attr( $opt['ws'] ); ?>">
							<input type="hidden" name="pp_view" value="<?php echo esc_attr( $view ); ?>">
							<button type="submit" class="pp-app__ws-opt<?php echo $opt['is'] ? ' is-active' : ''; ?>"><?php echo esc_html( $opt['label'] ); ?></button>
						</form>
					<?php endforeach; ?>
				</div>
			</details>

			<nav class="pp-app__nav">
				<?php foreach ( self::nav_items( $active ) as $item ) :
					$active = ( $item['view'] === $view ); ?>
					<a class="pp-app__nav-item<?php echo $active ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::view_url( $item['view'] ) ); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
						<?php self::nav_icon( $item['icon'] ); ?>
						<span><?php echo esc_html( $item['label'] ); ?></span>
						<?php if ( 'collectives' === $item['view'] && $count > 0 ) : ?>
							<span class="pp-app__nav-badge"><?php echo (int) $count; ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="pp-app__sidebar-foot">
				<?php if ( self::has_backend_access( $user ) ) : ?>
					<a class="pp-app__nav-item pp-app__nav-item--muted" href="<?php echo esc_url( admin_url( 'admin.php?page=project-prepper' ) ); ?>">
						<?php self::nav_icon( 'admin' ); ?>
						<span><?php esc_html_e( 'Admin area', 'project-prepper' ); ?></span>
					</a>
				<?php endif; ?>
			</div>
		</aside>
		<?php
	}

	private static function render_topbar( WP_User $user ): void {
		$role = self::has_backend_access( $user )
			? __( 'Manager', 'project-prepper' )
			: __( 'Member', 'project-prepper' );
		?>
		<header class="pp-app__topbar">
			<label for="pp-nav-toggle" class="pp-app__burger" aria-label="<?php esc_attr_e( 'Toggle menu', 'project-prepper' ); ?>">
				<span></span><span></span><span></span>
			</label>
			<div class="pp-app__topbar-right">
				<?php
				$notifs = self::notifications( $user );
				$ncount = count( $notifs );
				?>
				<details class="pp-notif">
					<summary class="pp-app__icon-btn pp-notif__bell" aria-label="<?php esc_attr_e( 'Notifications', 'project-prepper' ); ?>" title="<?php esc_attr_e( 'Notifications', 'project-prepper' ); ?>">
						<?php self::nav_icon( 'bell' ); ?>
						<?php if ( $ncount > 0 ) : ?>
							<span class="pp-notif__badge"><?php echo (int) $ncount; ?></span>
						<?php endif; ?>
					</summary>
					<div class="pp-notif__menu">
						<?php if ( $notifs ) : ?>
							<ul class="pp-notif__list">
								<?php foreach ( $notifs as $n ) : ?>
									<li><a href="<?php echo esc_url( $n['url'] ); ?>"><?php echo esc_html( $n['label'] ); ?></a></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<p class="pp-notif__empty"><?php esc_html_e( 'Nothing needs your attention right now.', 'project-prepper' ); ?></p>
						<?php endif; ?>
					</div>
				</details>
				<button type="button" class="pp-app__icon-btn pp-app__feedback-btn" data-pp-modal="pp-feedback-modal" title="<?php esc_attr_e( 'Send feedback', 'project-prepper' ); ?>"><?php esc_html_e( 'Feedback', 'project-prepper' ); ?></button>
				<div class="pp-app__user">
					<?php $topbar_avatar = self::avatar_url( (int) $user->ID, 'thumbnail' ); ?>
					<?php if ( $topbar_avatar ) : ?>
						<span class="pp-app__avatar pp-app__avatar--img"><img src="<?php echo esc_url( $topbar_avatar ); ?>" alt=""></span>
					<?php else : ?>
						<span class="pp-app__avatar"><?php echo esc_html( self::initials( $user->display_name ) ); ?></span>
					<?php endif; ?>
					<span class="pp-app__user-meta">
						<span class="pp-app__user-name"><?php echo esc_html( $user->display_name ); ?></span>
						<span class="pp-app__user-role"><?php echo esc_html( $role ); ?></span>
					</span>
				</div>
				<a class="pp-app__icon-btn" href="<?php echo esc_url( wp_logout_url( self::portal_url() ) ); ?>" aria-label="<?php esc_attr_e( 'Sign out', 'project-prepper' ); ?>" title="<?php esc_attr_e( 'Sign out', 'project-prepper' ); ?>">
					<?php self::nav_icon( 'logout' ); ?>
				</a>
			</div>
		</header>
		<?php
	}

	/** Initialen aus dem Anzeigenamen (1–2 Buchstaben). */
	private static function initials( string $name ): string {
		$name = trim( $name );
		if ( '' === $name ) {
			return '?';
		}
		$parts = preg_split( '/\s+/', $name );
		$first = mb_substr( $parts[0], 0, 1 );
		$last  = count( $parts ) > 1 ? mb_substr( (string) end( $parts ), 0, 1 ) : '';
		return mb_strtoupper( $first . $last );
	}

	/** Attachment-ID des hochgeladenen Profilfotos (0 = keins). */
	private static function avatar_id( int $user_id ): int {
		return (int) get_user_meta( $user_id, 'pp_avatar_id', true );
	}

	/** URL des hochgeladenen Profilfotos oder null (Fallback = Gravatar/Initialen). */
	private static function avatar_url( int $user_id, string $size = 'thumbnail' ): ?string {
		$aid = self::avatar_id( $user_id );
		if ( ! $aid ) {
			return null;
		}
		return wp_get_attachment_image_url( $aid, $size ) ?: null;
	}

	/**
	 * Aktionable In-App-Benachrichtigungen für die Topbar-Glocke: offene
	 * Einladungen, ausstehende Beitritts-Abstimmungen und eingehende
	 * (lokale + föderierte) Leih-Anfragen für die eigenen Artikel.
	 *
	 * @return array<array{label:string,url:string}>
	 */
	private static function notifications( WP_User $user ): array {
		$uid   = (int) $user->ID;
		$items = [];

		// Offene Einladungen an mich (annehmen/ablehnen).
		foreach ( Governance::my_pending_invitations( $uid ) as $inv ) {
			if ( 'pending' === $inv->status ) {
				$items[] = [
					/* translators: %s: collective name. */
					'label' => sprintf( __( 'Invitation to “%s”', 'project-prepper' ), $inv->group_name ),
					'url'   => self::view_url( 'collectives' ),
				];
			}
		}

		// Beitritts-Abstimmungen, bei denen meine Stimme noch fehlt.
		foreach ( Groups::user_group_ids( $uid ) as $gid ) {
			foreach ( Governance::invitations_for_group( $gid, [ 'voting' ] ) as $inv ) {
				if ( (int) $inv->invited_user_id !== $uid && empty( $inv->my_vote ) ) {
					$items[] = [
						/* translators: %s: invited email address. */
						'label' => sprintf( __( 'Vote on joining: %s', 'project-prepper' ), $inv->invited_email ),
						'url'   => self::view_url( 'collectives' ),
					];
				}
			}
		}

		// Eingehende Leih-Anfragen für meine Artikel.
		$borrow = count( array_filter(
			Borrowing::incoming_requests( $uid ),
			static fn( $r ) => 'requested' === $r->status
		) );
		if ( $borrow > 0 ) {
			$items[] = [
				/* translators: %d: number of borrow requests. */
				'label' => sprintf( _n( '%d borrow request for your items', '%d borrow requests for your items', $borrow, 'project-prepper' ), $borrow ),
				'url'   => self::view_url( 'lending' ),
			];
		}

		// Eingehende föderierte Leih-Anfragen.
		$fed = count( array_filter(
			FederatedBorrow::inbound_for_owner( $uid ),
			static fn( $r ) => 'requested' === $r->status
		) );
		if ( $fed > 0 ) {
			$items[] = [
				/* translators: %d: number of network requests. */
				'label' => sprintf( _n( '%d network borrow request', '%d network borrow requests', $fed, 'project-prepper' ), $fed ),
				'url'   => self::view_url( 'lending' ),
			];
		}

		return $items;
	}

	/**
	 * Eigenes Profilfoto als WP-Avatar durchreichen (überall wo get_avatar()
	 * greift). $id_or_email kann ID, Objekt mit ->user_id oder E-Mail sein.
	 *
	 * @param array $args        Avatar-Argumente.
	 * @param mixed $id_or_email Identifikator des Users.
	 */
	public static function filter_avatar_data( array $args, $id_or_email ): array {
		$user_id = 0;
		if ( is_numeric( $id_or_email ) ) {
			$user_id = (int) $id_or_email;
		} elseif ( $id_or_email instanceof \WP_User ) {
			$user_id = (int) $id_or_email->ID;
		} elseif ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
			$user_id = (int) $id_or_email->user_id;
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$u       = get_user_by( 'email', $id_or_email );
			$user_id = $u ? (int) $u->ID : 0;
		}
		if ( $user_id ) {
			$url = self::avatar_url( $user_id, 'thumbnail' );
			if ( $url ) {
				$args['url']          = $url;
				$args['found_avatar'] = true;
			}
		}
		return $args;
	}

	/** Inline-SVG-Icon (stroke=currentColor) — passend zur App-Sidebar. */
	private static function nav_icon( string $name ): void {
		$icons = [
			'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
			'inventory' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
			'package'   => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
			'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
			'projects'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h6"/>',
			'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
			'globe'     => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
			'clipboard' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6"/><path d="M9 16h6"/>',
			'costs'     => '<line x1="12" y1="2" x2="12" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
			'inbox'     => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
			'admin'     => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
			'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
			'info'      => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
			'bell'      => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
		];
		$path = $icons[ $name ] ?? '';
		echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- statisches Icon-Markup.
	}

	/* ---------- Views ---------- */

	private static function view_dashboard( WP_User $user, array $groups ): void {
		$inv_count  = count( MemberInventory::my_items( (int) $user->ID ) );
		$grp_count  = count( $groups );
		$proj_count = count( self::member_projects( $groups ) );
		$inq_count  = MemberInquiries::count_for_owner( (int) $user->ID, self::active_group_id( $groups ) );
		$incoming   = Borrowing::incoming_requests( (int) $user->ID );
		$open_reqs  = count( array_filter( $incoming, static fn( $r ) => 'requested' === $r->status ) );
		$rent_kpis  = MemberRentals::kpis( (int) $user->ID );
		$rent_out   = (int) $rent_kpis['reserved'] + (int) $rent_kpis['active'];
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title">
				<?php
				/* translators: %s: member display name. */
				printf( esc_html__( 'Hello %s', 'project-prepper' ), esc_html( $user->display_name ) );
				?>
			</h1>
			<p class="pp-app__page-sub">
				<?php
				$active = self::active_group_id( $groups );
				if ( $active ) {
					$gname = '';
					foreach ( $groups as $g ) {
						if ( (int) $g->id === $active ) {
							$gname = $g->name;
							break;
						}
					}
					/* translators: %s: active group name. */
					printf( esc_html__( 'Group: %s', 'project-prepper' ), esc_html( $gname ) );
				} elseif ( $grp_count > 0 ) {
					esc_html_e( 'Solo workspace', 'project-prepper' );
				} else {
					esc_html_e( 'Welcome to your collective platform.', 'project-prepper' );
				}
				?>
			</p>
		</header>

		<?php self::render_how_it_works(); ?>

		<div class="pp-kpi-grid">
			<?php
			self::kpi_card( 'inventory', $inv_count, __( 'Inventory items', 'project-prepper' ), 'warning', 'inventory' );
			self::kpi_card( 'projects', $proj_count, __( 'Projects', 'project-prepper' ), 'primary', 'projects' );
			self::kpi_card( 'inquiries', $inq_count, __( 'Inquiries', 'project-prepper' ), 'info', 'inbox' );
			self::kpi_card( 'collectives', $grp_count, __( 'Collectives', 'project-prepper' ), 'info', 'users' );
			self::kpi_card( 'lending', $open_reqs, __( 'Open borrow requests', 'project-prepper' ), 'success', 'package' );
			self::kpi_card( 'lending', $rent_out, __( 'Active external rentals', 'project-prepper' ), 'warning', 'package' );
			?>
		</div>

		<?php self::render_my_invitations( $user ); ?>

		<section class="pp-app__section">
			<div class="pp-app__section-head">
				<h2 class="pp-portal__subtitle"><?php esc_html_e( 'Your collectives', 'project-prepper' ); ?></h2>
				<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" href="<?php echo esc_url( self::view_url( 'collectives' ) ); ?>"><?php esc_html_e( 'Manage', 'project-prepper' ); ?></a>
			</div>
			<?php if ( $groups ) : ?>
				<ul class="pp-portal__groups">
					<?php foreach ( $groups as $g ) : ?>
						<li class="pp-portal__group">
							<span class="pp-portal__group-name"><?php echo esc_html( $g->name ); ?></span>
							<?php if ( 'founder' === $g->member_role ) : ?>
								<span class="pp-portal__tag"><?php esc_html_e( 'Founder', 'project-prepper' ); ?></span>
							<?php else : ?>
								<span class="pp-portal__tag pp-portal__tag--muted"><?php esc_html_e( 'Member', 'project-prepper' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'You are not part of any collective yet. Go to “My collectives” to found one or accept an invitation.', 'project-prepper' ); ?></p>
			<?php endif; ?>
		</section>

		<section class="pp-app__section">
			<div class="pp-app__section-head">
				<h2 class="pp-portal__subtitle"><?php esc_html_e( 'My profile', 'project-prepper' ); ?></h2>
			</div>
			<?php $prof_avatar = self::avatar_url( (int) $user->ID, 'thumbnail' ); ?>
			<div class="pp-profile">
				<span class="pp-profile__avatar">
					<?php if ( $prof_avatar ) : ?>
						<img src="<?php echo esc_url( $prof_avatar ); ?>" alt="">
					<?php else : ?>
						<?php echo esc_html( self::initials( $user->display_name ) ); ?>
					<?php endif; ?>
				</span>
				<div class="pp-profile__forms">
					<form class="pp-portal__form pp-portal__form--inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php self::action_fields( 'profile_save' ); ?>
						<label class="pp-profile__name-label"><?php esc_html_e( 'Display name', 'project-prepper' ); ?>
							<input type="text" name="pp_name" value="<?php echo esc_attr( $user->display_name ); ?>" required>
						</label>
						<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save name', 'project-prepper' ); ?></button>
					</form>
					<details class="pp-portal__edit">
						<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Profile photo', 'project-prepper' ); ?></summary>
						<form class="pp-portal__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="pp_member_avatar">
							<?php wp_nonce_field( 'pp_member_avatar', 'pp_nonce' ); ?>
							<label><?php esc_html_e( 'Image file', 'project-prepper' ); ?>
								<input type="file" name="pp_avatar" accept="image/*" required>
							</label>
							<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save photo', 'project-prepper' ); ?></button>
						</form>
						<?php if ( $prof_avatar ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.4rem;">
								<input type="hidden" name="action" value="pp_member_avatar">
								<?php wp_nonce_field( 'pp_member_avatar', 'pp_nonce' ); ?>
								<input type="hidden" name="pp_remove" value="1">
								<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Remove photo', 'project-prepper' ); ?></button>
							</form>
						<?php endif; ?>
					</details>
				</div>
			</div>
		</section>

		<section class="pp-app__section">
			<div class="pp-app__section-head">
				<h2 class="pp-portal__subtitle"><?php esc_html_e( 'Account & data', 'project-prepper' ); ?></h2>
			</div>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Download a copy of the data this platform holds about you — your profile, inventory, collectives and borrow records (GDPR Art. 15/20).', 'project-prepper' ); ?></p>
			<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pp_member_data' ), 'pp_member_data', 'pp_nonce' ) ); ?>"><?php esc_html_e( 'Download my data (JSON)', 'project-prepper' ); ?></a>
		</section>
		<?php
	}

	private static function kpi_card( string $view, int $value, string $label, string $tone, string $icon ): void {
		?>
		<a class="pp-kpi pp-kpi--<?php echo esc_attr( $tone ); ?>" href="<?php echo esc_url( self::view_url( $view ) ); ?>">
			<span class="pp-kpi__icon"><?php self::nav_icon( $icon ); ?></span>
			<span class="pp-kpi__value"><?php echo (int) $value; ?></span>
			<span class="pp-kpi__label"><?php echo esc_html( $label ); ?></span>
		</a>
		<?php
	}

	/** „So funktioniert die Plattform" — einklappbar (WP-nativ, ohne JS). */
	private static function render_how_it_works(): void {
		?>
		<details class="pp-hiw" open>
			<summary class="pp-hiw__summary">
				<?php self::nav_icon( 'info' ); ?>
				<span><?php esc_html_e( 'How the platform works', 'project-prepper' ); ?></span>
			</summary>
			<div class="pp-hiw__body">
				<div class="pp-hiw__cols">
					<div class="pp-hiw__col">
						<span class="pp-hiw__step">1</span>
						<h3><?php esc_html_e( 'Your own inventory', 'project-prepper' ); ?></h3>
						<p><?php esc_html_e( 'Add the equipment you own. It stays yours — you decide who may use it.', 'project-prepper' ); ?></p>
					</div>
					<div class="pp-hiw__col">
						<span class="pp-hiw__step">2</span>
						<h3><?php esc_html_e( 'Share with collectives', 'project-prepper' ); ?></h3>
						<p><?php esc_html_e( 'Found or join a collective and share selected items with its members.', 'project-prepper' ); ?></p>
					</div>
					<div class="pp-hiw__col">
						<span class="pp-hiw__step">3</span>
						<h3><?php esc_html_e( 'Browse & borrow', 'project-prepper' ); ?></h3>
						<p><?php esc_html_e( 'Borrow what others share, and lend out your own — non-commercial, among members.', 'project-prepper' ); ?></p>
					</div>
				</div>
			</div>
		</details>
		<?php
	}

	private static function view_inventory( WP_User $user, array $groups ): void {
		// Arbeitsbereich-bewusst (wie Projekte/Anfragen): im Gruppen-Modus zeigt
		// die Seite das geteilte Gruppen-Inventar, nicht das persönliche.
		$active = self::active_group_id( $groups );
		if ( $active > 0 ) {
			self::view_group_inventory( $user, $groups, $active );
			return;
		}
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title"><?php esc_html_e( 'My inventory', 'project-prepper' ); ?></h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Your personal equipment — share items with your collectives.', 'project-prepper' ); ?></p>
		</header>
		<?php
		self::render_equipment_out( $user );
		self::render_my_inventory( $user, $groups, false );
	}

	/**
	 * Gruppen-Inventar (Member-Portal): der Pool aller Artikel, die Mitglieder
	 * MIT dieser Gruppe geteilt haben (Tabelle item_group_shares). Read-only —
	 * eigene Artikel verwaltet man weiterhin über „Mein Inventar" (Solo). Pendant
	 * zur owner_group_id-Inventarsicht der Next.js-App.
	 */
	private static function view_group_inventory( WP_User $user, array $groups, int $group_id ): void {
		$group = null;
		foreach ( $groups as $g ) {
			if ( (int) $g->id === $group_id ) {
				$group = $g;
				break;
			}
		}
		$name = $group ? (string) $group->name : __( 'Group', 'project-prepper' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Suche/Navigation
		$q    = isset( $_GET['pp_q'] ) ? sanitize_text_field( wp_unslash( $_GET['pp_q'] ) ) : '';
		$args = [ 'shared_with_group' => $group_id ];
		if ( '' !== trim( $q ) ) {
			$args['search'] = trim( $q );
		}
		$items        = Inventory::items( $args );
		$conditions   = Shortcodes::condition_labels();
		$uid          = (int) $user->ID;
		$total_pieces = 0;
		$total_value  = 0.0;
		foreach ( $items as $it ) {
			$total_pieces += (int) $it->quantity;
			$total_value  += (float) $it->cost_per_day * (int) $it->quantity;
		}
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title">
				<?php /* translators: %s: group name. */ printf( esc_html__( 'Inventory of %s', 'project-prepper' ), esc_html( $name ) ); ?>
			</h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Equipment that members have shared with this group.', 'project-prepper' ); ?></p>
		</header>

		<section class="pp-portal__section pp-ginv">
			<?php if ( ! $items && '' === $q ) : ?>
				<p class="pp-portal__empty">
					<?php esc_html_e( 'Nothing shared with this group yet. Members add equipment from their own inventory: switch your workspace to “Solo”, open “My inventory”, and use the share buttons on an item.', 'project-prepper' ); ?>
				</p>
			<?php else : ?>
				<form class="pp-inv-search" method="get">
					<input type="hidden" name="pp_view" value="inventory">
					<input type="search" name="pp_q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Search this group’s inventory …', 'project-prepper' ); ?>">
					<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Search', 'project-prepper' ); ?></button>
				</form>

				<?php if ( $items ) : ?>
					<p class="pp-inv-kpi">
						<?php
						$pp_dv = ( (float) $total_value === floor( (float) $total_value ) ) ? number_format_i18n( $total_value, 0 ) : number_format_i18n( $total_value, 2 );
						/* translators: 1: item count, 2: total pieces, 3: total daily value. */
						printf( esc_html__( '%1$d items · %2$d pieces · daily value %3$s €', 'project-prepper' ), count( $items ), (int) $total_pieces, esc_html( $pp_dv ) );
						?>
					</p>

					<div class="pp-inv-row pp-inv-row--head">
						<span class="pp-col pp-col--name"><?php esc_html_e( 'Item', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--cat"><?php esc_html_e( 'Category', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--owner"><?php esc_html_e( 'Owner', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--c"><?php esc_html_e( 'Quantity', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--c"><?php esc_html_e( 'Available', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--cond"><?php esc_html_e( 'Condition', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--r">€/<?php echo esc_html__( 'day', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--loc"><?php esc_html_e( 'Location', 'project-prepper' ); ?></span>
					</div>
					<?php
					foreach ( $items as $item ) :
						$is_mine  = ( (int) ( $item->owner_user_id ?? 0 ) === $uid );
						$owner    = $is_mine ? null : get_userdata( (int) ( $item->owner_user_id ?? 0 ) );
						$avail    = (int) max( 0, (int) $item->quantity - (int) ( $item->out_now ?? 0 ) );
						$owner_lb = $is_mine ? __( 'You', 'project-prepper' ) : ( $owner ? $owner->display_name : '—' );
						$pp_sub   = $item->model ?: ( $item->description ?? '' );
						?>
						<div class="pp-inv-row pp-ginv__row">
							<span class="pp-col pp-col--name">
								<?php if ( ! empty( $item->image_url ) ) : ?><img class="pp-portal__item-thumb" src="<?php echo esc_url( $item->image_url ); ?>" alt="" loading="lazy"><?php else : ?><span class="pp-portal__item-thumb pp-portal__item-thumb--empty" aria-hidden="true"></span><?php endif; ?>
								<span class="pp-inv-name-wrap"><span class="pp-inv-name-top"><span class="pp-portal__group-name"><?php echo esc_html( $item->name ); ?></span> <small class="pp-portal__item-num"><?php echo esc_html( $item->inventory_number ); ?></small></span><?php if ( '' !== trim( (string) $pp_sub ) ) : ?><small class="pp-inv-name-sub"><?php echo esc_html( (string) $pp_sub ); ?></small><?php endif; ?></span>
							</span>
							<span class="pp-col pp-col--cat" data-label="<?php esc_attr_e( 'Category', 'project-prepper' ); ?>"><?php echo $item->category_name ? esc_html( trim( ( $item->category_icon ? $item->category_icon . ' ' : '' ) . (string) $item->category_name ) ) : '—'; ?></span>
							<span class="pp-col pp-col--owner" data-label="<?php esc_attr_e( 'Owner', 'project-prepper' ); ?>"><?php echo esc_html( $owner_lb ); ?></span>
							<span class="pp-col pp-col--c" data-label="<?php esc_attr_e( 'Quantity', 'project-prepper' ); ?>"><?php echo (int) $item->quantity; ?></span>
							<span class="pp-col pp-col--c" data-label="<?php esc_attr_e( 'Available', 'project-prepper' ); ?>"><?php echo (int) $avail; ?></span>
							<span class="pp-col pp-col--cond" data-label="<?php esc_attr_e( 'Condition', 'project-prepper' ); ?>"><?php echo esc_html( $conditions[ $item->item_condition ] ?? $item->item_condition ); ?></span>
							<span class="pp-col pp-col--r" data-label="€/<?php echo esc_attr__( 'day', 'project-prepper' ); ?>"><?php echo ( null !== $item->cost_per_day && '' !== $item->cost_per_day ) ? esc_html( number_format_i18n( (float) $item->cost_per_day, 2 ) . ' €' ) : '—'; ?></span>
							<span class="pp-col pp-col--loc" data-label="<?php esc_attr_e( 'Location', 'project-prepper' ); ?>"><?php echo ! empty( $item->location ) ? esc_html( (string) $item->location ) : '—'; ?></span>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<p class="pp-portal__empty"><?php esc_html_e( 'No items match your search.', 'project-prepper' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * „Mein Equipment unterwegs" (App: §9.3) — eigene Artikel, die gerade verliehen/
	 * ausgeliehen sind (genehmigte Kollektiv-Leihen + externe Verleihe). Pro Zeile:
	 * Artikel + Inv.-Nr. + Menge + Status + an wen + Zeitraum + Gebühr.
	 */
	private static function render_equipment_out( WP_User $user ): void {
		$out = Borrowing::equipment_out( (int) $user->ID );
		if ( ! $out ) {
			return;
		}
		?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php esc_html_e( 'My equipment on the road', 'project-prepper' ); ?></h3>
			<?php foreach ( $out as $r ) : ?>
				<div class="pp-portal__invite">
					<span class="pp-portal__group-name"><?php echo esc_html( $r->item_name ); ?> <small class="pp-portal__item-num"><?php echo esc_html( $r->inventory_number ); ?></small></span>
					<span class="pp-portal__item-meta">
						<?php
						$bits = [];
						if ( $r->quantity > 1 ) {
							$bits[] = (int) $r->quantity . '×';
						}
						$bits[] = $r->status_label;
						if ( '' !== trim( (string) $r->to_name ) ) {
							/* translators: %s: borrower name. */
							$bits[] = sprintf( __( 'to %s', 'project-prepper' ), $r->to_name );
						}
						/* translators: 1: start date, 2: end date, 3: number of days. */
						$bits[] = sprintf( __( '%1$s – %2$s (%3$dd)', 'project-prepper' ), $r->date_from, $r->date_to, (int) $r->days );
						if ( null !== $r->fee ) {
							/* translators: %s: rental fee. */
							$bits[] = sprintf( __( 'fee %s €', 'project-prepper' ), number_format_i18n( (float) $r->fee, 2 ) );
						}
						echo esc_html( implode( ' · ', $bits ) );
						?>
					</span>
					<span class="pp-portal__tag <?php echo 'rental' === $r->kind ? '' : 'pp-portal__tag--muted'; ?>"><?php echo esc_html( 'rental' === $r->kind ? __( 'Rental', 'project-prepper' ) : __( 'Collective', 'project-prepper' ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	private static function view_lending( WP_User $user, array $groups ): void {
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title"><?php esc_html_e( 'Borrowing & lending', 'project-prepper' ); ?></h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Browse what your collectives share, request items, manage requests for your own — and lend out your equipment to externals.', 'project-prepper' ); ?></p>
		</header>
		<?php
		// Externe Verleihe (App: /rentals) — persönlich, daher immer sichtbar.
		self::render_external_rentals( $user );

		$fed_incoming = FederatedBorrow::inbound_for_owner( (int) $user->ID );
		// Stöbern ist auf den aktiven Workspace begrenzt (Solo → kein Stöbern).
		$active        = self::active_group_id( $groups );
		$active_groups = array_values( array_filter( $groups, static fn( $g ) => (int) $g->id === $active ) );
		self::render_browse( $user, $active_groups );
		self::render_my_borrows( $user );
		self::render_incoming_borrows( $user );
		self::render_incoming_fed_borrows( $fed_incoming );
		self::render_borrow_history( $user );
	}

	/**
	 * Externe Verleihe (App-Pendant src/app/(dashboard)/rentals): eigene Artikel
	 * an Personen außerhalb der Plattform verleihen — KPI-Karten, Status-Pills,
	 * Abrechnung (netto/USt/brutto + Kaution) und der Status-Flow
	 * reserved→active→returned/cancelled. Persönlich (owner_user_id), siehe
	 * {@see MemberRentals}-Klassendoku zur Gruppen-Abgrenzung.
	 */
	private static function render_external_rentals( WP_User $user ): void {
		$uid      = (int) $user->ID;
		$rentals  = MemberRentals::for_owner( $uid );
		$kpis     = MemberRentals::kpis( $uid );
		$lendable = MemberRentals::lendable_items( $uid );
		?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php esc_html_e( 'External lending', 'project-prepper' ); ?></h3>
			<p class="pp-portal__hint"><?php esc_html_e( 'Lend your own equipment to people outside the platform. Reservation, hand-out, return and billing — just like the app.', 'project-prepper' ); ?></p>

			<div class="pp-kpi-grid pp-kpi-grid--compact">
				<?php
				self::rental_kpi( __( 'Reserved', 'project-prepper' ), (string) (int) $kpis['reserved'], 'info' );
				self::rental_kpi( __( 'Handed out', 'project-prepper' ), (string) (int) $kpis['active'], 'warning' );
				self::rental_kpi( __( 'Returned', 'project-prepper' ), (string) (int) $kpis['returned'], 'success' );
				self::rental_kpi(
					__( 'Deposit held', 'project-prepper' ),
					number_format_i18n( (float) $kpis['deposit_open'], 2 ) . ' €',
					'primary'
				);
				?>
			</div>

			<?php if ( $rentals ) : ?>
				<?php foreach ( $rentals as $r ) :
					$full = MemberRentals::get_owned( (int) $r->id, $uid );
					if ( ! $full ) {
						continue;
					}
					$next = Rentals::TRANSITIONS[ $full->status ] ?? [];
					$bill = $full->billing;
					?>
					<div class="pp-portal__item">
						<div class="pp-portal__item-head">
							<span class="pp-portal__group-name">
								<?php echo esc_html( $full->borrower_name ); ?>
								<small class="pp-portal__item-num"><?php echo esc_html( $full->rental_number ); ?></small>
							</span>
							<span class="pp-status pp-status--<?php echo esc_attr( $full->status ); ?>"><?php echo esc_html( self::rental_status_label( $full->status ) ); ?></span>
							<span class="pp-portal__item-meta"><?php echo esc_html( self::fmt_range( $full->date_from, $full->date_to ) ); ?></span>
						</div>

						<?php if ( $full->items ) : ?>
							<ul class="pp-portal__rental-lines">
								<?php foreach ( $full->items as $line ) : ?>
									<li>
										<?php echo esc_html( $line->item_name ?: ( '#' . (int) $line->item_id ) ); ?>
										<?php if ( (int) $line->quantity > 1 ) : ?>
											<span class="pp-portal__item-meta"><?php echo (int) $line->quantity; ?>×</span>
										<?php endif; ?>
										<?php if ( null !== $line->daily_rate ) : ?>
											<span class="pp-portal__item-meta"><?php echo esc_html( number_format_i18n( (float) $line->daily_rate, 2 ) ); ?> €/<?php esc_html_e( 'day', 'project-prepper' ); ?></span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<div class="pp-portal__item-meta pp-portal__rental-bill">
							<?php
							/* translators: %d: number of rental days. */
							echo esc_html( sprintf( _n( '%d day', '%d days', (int) $bill['days'], 'project-prepper' ), (int) $bill['days'] ) );
							echo ' · ';
							/* translators: %s: net amount in euro. */
							echo esc_html( sprintf( __( 'Net %s €', 'project-prepper' ), number_format_i18n( (float) $bill['net'], 2 ) ) );
							echo ' · ';
							/* translators: 1: VAT amount, 2: VAT rate percent. */
							echo esc_html( sprintf( __( 'VAT %1$s € (%2$s%%)', 'project-prepper' ), number_format_i18n( (float) $bill['vat'], 2 ), number_format_i18n( (float) $bill['vat_rate'], 0 ) ) );
							echo ' · ';
							/* translators: %s: gross amount in euro. */
							echo '<strong>' . esc_html( sprintf( __( 'Gross %s €', 'project-prepper' ), number_format_i18n( (float) $bill['gross'], 2 ) ) ) . '</strong>';
							if ( (float) $bill['deposit'] > 0 ) {
								echo ' · ';
								/* translators: %s: deposit amount in euro. */
								echo esc_html( sprintf( __( 'Deposit %s €', 'project-prepper' ), number_format_i18n( (float) $bill['deposit'], 2 ) ) );
							}
							?>
						</div>

						<?php if ( '' !== trim( (string) $full->notes ) ) : ?>
							<p class="pp-portal__inq-msg"><?php echo esc_html( $full->notes ); ?></p>
						<?php endif; ?>

						<?php if ( $next ) : ?>
							<div class="pp-portal__share-row">
								<span class="pp-portal__share-label"><?php esc_html_e( 'Move to:', 'project-prepper' ); ?></span>
								<?php foreach ( $next as $st ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php self::action_fields( 'rental_status' ); ?>
										<input type="hidden" name="pp_rental" value="<?php echo (int) $full->id; ?>">
										<input type="hidden" name="pp_status" value="<?php echo esc_attr( $st ); ?>">
										<button type="submit" class="pp-portal__chip"><?php echo esc_html( self::rental_status_label( $st ) ); ?></button>
									</form>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<div class="pp-portal__actions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this rental?', 'project-prepper' ) ); ?>');">
								<?php self::action_fields( 'rental_delete' ); ?>
								<input type="hidden" name="pp_rental" value="<?php echo (int) $full->id; ?>">
								<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Delete', 'project-prepper' ); ?></button>
							</form>
						</div>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'No external rentals yet. Add your first one below.', 'project-prepper' ); ?></p>
			<?php endif; ?>

			<?php if ( $lendable ) : ?>
				<details class="pp-portal__add">
					<summary class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'New rental', 'project-prepper' ); ?></summary>
					<?php self::rental_form( $lendable ); ?>
				</details>
			<?php else : ?>
				<p class="pp-portal__hint"><?php esc_html_e( 'Add items to your inventory first — then you can lend them out.', 'project-prepper' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function rental_kpi( string $label, string $value, string $tone ): void {
		?>
		<div class="pp-kpi pp-kpi--<?php echo esc_attr( $tone ); ?> pp-kpi--static">
			<span class="pp-kpi__value"><?php echo esc_html( $value ); ?></span>
			<span class="pp-kpi__label"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php
	}

	/** @return string Status-Label (App-Begriffe). */
	private static function rental_status_label( string $status ): string {
		$labels = [
			'reserved'  => __( 'Reserved', 'project-prepper' ),
			'active'    => __( 'Handed out', 'project-prepper' ),
			'returned'  => __( 'Returned', 'project-prepper' ),
			'cancelled' => __( 'Cancelled', 'project-prepper' ),
		];
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Anlege-Formular für einen externen Verleih. Person + Zeitraum + Geld +
	 * eine Auswahl der eigenen Artikel (Checkbox + Menge + Tagessatz pro Zeile).
	 *
	 * @param array<int,object> $lendable Eigene Artikel (ID → Objekt).
	 */
	private static function rental_form( array $lendable ): void {
		?>
		<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::action_fields( 'rental_create' ); ?>
			<label><?php esc_html_e( 'Borrower name', 'project-prepper' ); ?>
				<input type="text" name="pp_borrower" required>
			</label>
			<label><?php esc_html_e( 'Email', 'project-prepper' ); ?>
				<input type="email" name="pp_email">
			</label>
			<label><?php esc_html_e( 'Phone', 'project-prepper' ); ?>
				<input type="text" name="pp_phone">
			</label>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'From', 'project-prepper' ); ?>
					<input type="date" name="pp_from" required>
				</label>
				<label><?php esc_html_e( 'To', 'project-prepper' ); ?>
					<input type="date" name="pp_to" required>
				</label>
			</div>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Deposit (€)', 'project-prepper' ); ?>
					<input type="number" name="pp_deposit" min="0" step="0.01" placeholder="0.00">
				</label>
				<label><?php esc_html_e( 'Rental fee (€, gross — optional)', 'project-prepper' ); ?>
					<input type="number" name="pp_fee" min="0" step="0.01" placeholder="<?php esc_attr_e( 'auto from daily rates', 'project-prepper' ); ?>">
				</label>
			</div>

			<fieldset class="pp-portal__rental-items">
				<legend><?php esc_html_e( 'Items to lend out', 'project-prepper' ); ?></legend>
				<?php foreach ( $lendable as $item ) :
					$rate = isset( $item->cost_per_day ) && '' !== (string) $item->cost_per_day ? (float) $item->cost_per_day : '';
					?>
					<div class="pp-portal__rental-item-row">
						<label class="pp-portal__rental-item-pick">
							<input type="checkbox" name="pp_item[<?php echo (int) $item->id; ?>][on]" value="1">
							<span><?php echo esc_html( $item->name ); ?> <small class="pp-portal__item-num"><?php echo esc_html( $item->inventory_number ?? '' ); ?></small></span>
						</label>
						<label class="pp-portal__rental-item-qty"><?php esc_html_e( 'Qty', 'project-prepper' ); ?>
							<input type="number" name="pp_item[<?php echo (int) $item->id; ?>][qty]" min="1" value="1">
						</label>
						<label class="pp-portal__rental-item-rate"><?php esc_html_e( '€/day', 'project-prepper' ); ?>
							<input type="number" name="pp_item[<?php echo (int) $item->id; ?>][rate]" min="0" step="0.01" value="<?php echo esc_attr( '' === $rate ? '' : number_format( (float) $rate, 2, '.', '' ) ); ?>">
						</label>
					</div>
				<?php endforeach; ?>
			</fieldset>

			<label><?php esc_html_e( 'Notes', 'project-prepper' ); ?>
				<textarea name="pp_notes" rows="2"></textarea>
			</label>
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Create rental', 'project-prepper' ); ?></button>
		</form>
		<?php
	}

	/** Eingaben des Verleih-Formulars einsammeln (Header + Positionen). */
	private static function rental_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		$data = [
			'borrower_name'  => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_borrower'] ?? '' ) ) ),
			'borrower_email' => sanitize_email( wp_unslash( (string) ( $_POST['pp_email'] ?? '' ) ) ),
			'borrower_phone' => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_phone'] ?? '' ) ) ),
			'date_from'      => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_from'] ?? '' ) ) ),
			'date_to'        => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_to'] ?? '' ) ) ),
			'deposit_amount' => '' !== (string) ( $_POST['pp_deposit'] ?? '' ) ? (float) $_POST['pp_deposit'] : '',
			'rental_fee'     => '' !== (string) ( $_POST['pp_fee'] ?? '' ) ? (float) $_POST['pp_fee'] : '',
			'notes'          => sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_notes'] ?? '' ) ) ),
		];
		$items = [];
		$raw   = isset( $_POST['pp_item'] ) && is_array( $_POST['pp_item'] ) ? wp_unslash( $_POST['pp_item'] ) : [];
		foreach ( $raw as $item_id => $line ) {
			if ( empty( $line['on'] ) ) {
				continue;
			}
			$items[] = [
				'item_id'    => (int) $item_id,
				'quantity'   => max( 1, (int) ( $line['qty'] ?? 1 ) ),
				'daily_rate' => isset( $line['rate'] ) && '' !== $line['rate'] ? (float) $line['rate'] : '',
			];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return [ 'data' => $data, 'items' => $items ];
	}

	/** Eingehende föderierte Leih-Anfragen für die eigenen Artikel (Slice 4). */
	private static function render_incoming_fed_borrows( array $requests ): void {
		if ( ! $requests ) {
			return;
		}
		?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php esc_html_e( 'Network requests for your items', 'project-prepper' ); ?></h3>
			<?php foreach ( $requests as $r ) :
				$from = trim( (string) $r->origin_name ) !== '' ? $r->origin_name : self::pretty_host( (string) $r->origin_url ); ?>
				<div class="pp-portal__invite">
					<span class="pp-portal__group-name"><?php echo esc_html( $r->item_name ); ?></span>
					<span class="pp-portal__item-meta">
						<?php
						echo esc_html( self::fmt_range( $r->date_from, $r->date_to ) );
						/* translators: 1: requester name, 2: instance name. */
						echo ' · ' . esc_html( sprintf( __( '%1$s via %2$s', 'project-prepper' ), $r->requester_name, $from ) );
						?>
					</span>
					<span class="pp-portal__tag <?php echo esc_attr( self::borrow_status_class( $r->status ) ); ?>"><?php echo esc_html( self::borrow_status_label( $r->status ) ); ?></span>
					<?php if ( '' !== trim( (string) $r->message ) ) : ?>
						<p class="pp-portal__members" style="flex-basis:100%;margin:.3rem 0 0;"><?php echo esc_html( $r->message ); ?></p>
					<?php endif; ?>
					<p class="pp-portal__members" style="flex-basis:100%;margin:.2rem 0 0;">
						<a href="<?php echo esc_url( 'mailto:' . $r->requester_contact ); ?>"><?php echo esc_html( $r->requester_contact ); ?></a>
					</p>
					<?php if ( 'requested' === $r->status ) : ?>
						<div class="pp-portal__actions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
								<?php self::action_fields( 'fedborrow_approve' ); ?>
								<input type="hidden" name="pp_fedreq" value="<?php echo (int) $r->id; ?>">
								<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Approve', 'project-prepper' ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
								<?php self::action_fields( 'fedborrow_decline' ); ?>
								<input type="hidden" name="pp_fedreq" value="<?php echo (int) $r->id; ?>">
								<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Decline', 'project-prepper' ); ?></button>
							</form>
						</div>
					<?php elseif ( 'approved' === $r->status ) : ?>
						<div class="pp-portal__actions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
								<?php self::action_fields( 'fedborrow_return' ); ?>
								<input type="hidden" name="pp_fedreq" value="<?php echo (int) $r->id; ?>">
								<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Mark returned', 'project-prepper' ); ?></button>
							</form>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	private static function view_collectives( WP_User $user, array $groups ): void {
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title"><?php esc_html_e( 'My collectives', 'project-prepper' ); ?></h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Found or join collectives and invite members to share resources.', 'project-prepper' ); ?></p>
		</header>
		<?php self::render_my_invitations( $user ); ?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php esc_html_e( 'Your collectives', 'project-prepper' ); ?></h3>
			<?php if ( $groups ) : ?>
				<?php foreach ( $groups as $group ) {
					self::render_collective( (int) $group->id, $group->name, $group->member_role, (int) $user->ID );
				} ?>
			<?php else : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'You are not part of any collective yet. Found one below or accept an invitation to start sharing inventory.', 'project-prepper' ); ?></p>
			<?php endif; ?>
		</section>

		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php esc_html_e( 'Found a collective', 'project-prepper' ); ?></h3>
			<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php self::action_fields( 'found' ); ?>
				<label for="pp-found-name"><?php esc_html_e( 'Collective name', 'project-prepper' ); ?></label>
				<input type="text" id="pp-found-name" name="pp_name" required>
				<label for="pp-found-desc"><?php esc_html_e( 'Description (optional)', 'project-prepper' ); ?></label>
				<textarea id="pp-found-desc" name="pp_description" rows="2"></textarea>
				<button type="submit" class="pp-portal__btn"><?php esc_html_e( 'Found collective', 'project-prepper' ); ?></button>
			</form>
		</section>
		<?php
	}

	/* ---------- Meine Projekte (Gruppen-Projekte, read-only) ---------- */

	/**
	 * Aktiver Arbeitsbereich: 0 = Solo, sonst eine Gruppen-ID (aus User-Meta,
	 * gegen die Mitgliedschaft validiert). Default = erste Gruppe, sonst Solo.
	 */
	private static function active_group_id( array $groups ): int {
		$gids   = array_map( static fn( $g ) => (int) $g->id, $groups );
		$stored = (string) get_user_meta( get_current_user_id(), 'pp_active_group', true );
		if ( 'solo' === $stored ) {
			return 0;
		}
		$sid = (int) $stored;
		if ( $sid && in_array( $sid, $gids, true ) ) {
			return $sid;
		}
		return $gids ? (int) $gids[0] : 0;
	}

	/** Aktiver Arbeitsbereich des aktuellen Users als Gruppen-ID (0 = Solo). */
	private static function active_workspace_group(): int {
		return self::active_group_id( Groups::user_groups( get_current_user_id() ) );
	}

	/* ---------- Anfragen (docs/06 §10.1) ---------- */

	/** @return array<string,string> Status-Schlüssel → Label. */
	private static function inquiry_status_labels(): array {
		return [
			'new'       => __( 'New', 'project-prepper' ),
			'contacted' => __( 'Contacted', 'project-prepper' ),
			'offer'     => __( 'Offer', 'project-prepper' ),
			'won'       => __( 'Won', 'project-prepper' ),
			'lost'      => __( 'Lost', 'project-prepper' ),
			'closed'    => __( 'Closed', 'project-prepper' ),
		];
	}

	/** Eingaben des Anfrage-Formulars einsammeln. */
	private static function inquiry_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		return [
			'name'      => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) ),
			'email'     => sanitize_email( wp_unslash( (string) ( $_POST['pp_email'] ?? '' ) ) ),
			'phone'     => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_phone'] ?? '' ) ) ),
			'message'   => sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_message'] ?? '' ) ) ),
			'date_from' => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_from'] ?? '' ) ) ),
			'date_to'   => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_to'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private static function view_inquiries( WP_User $user, array $groups ): void {
		$group_id  = self::active_group_id( $groups );
		$inquiries = MemberInquiries::for_owner( (int) $user->ID, $group_id );
		$labels    = self::inquiry_status_labels();
		?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php echo $group_id ? esc_html__( 'Inquiries', 'project-prepper' ) : esc_html__( 'My inquiries', 'project-prepper' ); ?></h3>
			<p class="pp-portal__hint"><?php esc_html_e( 'Track external requests as bookkeeping and move them through the pipeline. A later step turns a won inquiry into a project.', 'project-prepper' ); ?></p>

			<?php if ( $inquiries ) : ?>
				<?php foreach ( $inquiries as $inq ) : ?>
					<?php
					$next = Inquiries::TRANSITIONS[ $inq->status ] ?? [];
					$span = '';
					if ( $inq->date_from ) {
						$span = $inq->date_from === $inq->date_to || ! $inq->date_to
							? (string) $inq->date_from
							: $inq->date_from . ' – ' . $inq->date_to;
					}
					?>
					<div class="pp-portal__item">
						<div class="pp-portal__item-head">
							<span class="pp-portal__group-name"><?php echo esc_html( $inq->name ); ?></span>
							<span class="pp-status pp-status--<?php echo esc_attr( $inq->status ); ?>"><?php echo esc_html( $labels[ $inq->status ] ?? $inq->status ); ?></span>
							<?php if ( $span ) : ?><span class="pp-portal__item-meta"><?php echo esc_html( $span ); ?></span><?php endif; ?>
						</div>
						<?php if ( $inq->email || $inq->phone ) : ?>
							<div class="pp-portal__item-meta"><?php echo esc_html( trim( $inq->email . ( $inq->email && $inq->phone ? ' · ' : '' ) . $inq->phone ) ); ?></div>
						<?php endif; ?>
						<?php if ( $inq->message ) : ?>
							<p class="pp-portal__inq-msg"><?php echo esc_html( $inq->message ); ?></p>
						<?php endif; ?>

						<?php if ( $next ) : ?>
							<div class="pp-portal__share-row">
								<span class="pp-portal__share-label"><?php esc_html_e( 'Move to:', 'project-prepper' ); ?></span>
								<?php foreach ( $next as $st ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php self::action_fields( 'inquiry_status' ); ?>
										<input type="hidden" name="pp_inquiry" value="<?php echo (int) $inq->id; ?>">
										<input type="hidden" name="pp_status" value="<?php echo esc_attr( $st ); ?>">
										<button type="submit" class="pp-portal__chip"><?php echo esc_html( $labels[ $st ] ?? $st ); ?></button>
									</form>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<div class="pp-portal__actions">
							<?php if ( $group_id > 0 && ! in_array( $inq->status, [ 'won', 'lost', 'closed' ], true ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php self::action_fields( 'inquiry_to_project' ); ?>
									<input type="hidden" name="pp_inquiry" value="<?php echo (int) $inq->id; ?>">
									<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Create project', 'project-prepper' ); ?></button>
								</form>
							<?php endif; ?>
							<details class="pp-portal__edit">
								<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Edit', 'project-prepper' ); ?></summary>
								<?php self::inquiry_form( 'inquiry_update', $inq ); ?>
							</details>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this inquiry?', 'project-prepper' ) ); ?>');">
								<?php self::action_fields( 'inquiry_delete' ); ?>
								<input type="hidden" name="pp_inquiry" value="<?php echo (int) $inq->id; ?>">
								<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Delete', 'project-prepper' ); ?></button>
							</form>
						</div>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'No inquiries yet. Add your first one below.', 'project-prepper' ); ?></p>
			<?php endif; ?>

			<details class="pp-portal__add">
				<summary class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'New inquiry', 'project-prepper' ); ?></summary>
				<?php self::inquiry_form( 'inquiry_create', null ); ?>
			</details>
		</section>
		<?php
	}

	private static function inquiry_form( string $do, ?object $inq ): void {
		$val = static fn( string $f, $d = '' ) => $inq && isset( $inq->$f ) && null !== $inq->$f ? $inq->$f : $d;
		?>
		<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::action_fields( $do ); ?>
			<?php if ( $inq ) : ?>
				<input type="hidden" name="pp_inquiry" value="<?php echo (int) $inq->id; ?>">
			<?php endif; ?>
			<label><?php esc_html_e( 'Contact name', 'project-prepper' ); ?>
				<input type="text" name="pp_name" value="<?php echo esc_attr( (string) $val( 'name' ) ); ?>" required>
			</label>
			<label><?php esc_html_e( 'Email', 'project-prepper' ); ?>
				<input type="email" name="pp_email" value="<?php echo esc_attr( (string) $val( 'email' ) ); ?>">
			</label>
			<label><?php esc_html_e( 'Phone', 'project-prepper' ); ?>
				<input type="text" name="pp_phone" value="<?php echo esc_attr( (string) $val( 'phone' ) ); ?>">
			</label>
			<label><?php esc_html_e( 'From', 'project-prepper' ); ?>
				<input type="date" name="pp_from" value="<?php echo esc_attr( (string) $val( 'date_from' ) ); ?>">
			</label>
			<label><?php esc_html_e( 'To', 'project-prepper' ); ?>
				<input type="date" name="pp_to" value="<?php echo esc_attr( (string) $val( 'date_to' ) ); ?>">
			</label>
			<label><?php esc_html_e( 'Message / notes', 'project-prepper' ); ?>
				<textarea name="pp_message" rows="2"><?php echo esc_textarea( (string) $val( 'message' ) ); ?></textarea>
			</label>
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save inquiry', 'project-prepper' ); ?></button>
		</form>
		<?php
	}

	/* ---------- Projekte: Gruppen-CRUD (docs/06 §10.1 Slice C) ---------- */

	/** Projekt der aktiven Gruppe, das der User bearbeiten darf (sonst null). */
	private static function member_owned_project( int $pid ): ?object {
		$active = self::active_workspace_group();
		if ( $active <= 0 || $pid <= 0 ) {
			return null;
		}
		$p = Projects::get( $pid );
		return ( $p && (int) $p->owner_group_id === $active ) ? $p : null;
	}

	/** Eingaben des Projekt-Formulars (Kernfelder; Finanzen bleiben im Backend). */
	private static function project_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		return [
			'name'        => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) ),
			'status'      => sanitize_key( wp_unslash( (string) ( $_POST['pp_status'] ?? 'draft' ) ) ),
			'date_start'  => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_from'] ?? '' ) ) ),
			'date_end'    => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_to'] ?? '' ) ) ),
			'venue_name'  => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_venue'] ?? '' ) ) ),
			'client_name' => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_client'] ?? '' ) ) ),
			'notes'       => sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_notes'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private static function member_create_project() {
		if ( self::active_workspace_group() <= 0 ) {
			return new \WP_Error( 'pp_forbidden', __( 'Pick a group workspace to create a project.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$data                   = self::project_input();
		$data['owner_group_id'] = self::active_workspace_group(); // Projects::create prüft die Mitgliedschaft.
		return Projects::create( $data );
	}

	private static function member_update_project( int $pid ) {
		if ( ! self::member_owned_project( $pid ) ) {
			return new \WP_Error( 'pp_forbidden', __( 'This project is not available.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$data = self::project_input();
		// Status läuft über set_status (Projects::update whitelistet ihn bewusst nicht).
		$status = (string) ( $data['status'] ?? '' );
		unset( $data['status'] );
		$res = Projects::update( $pid, $data );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( '' !== $status ) {
			$st = Projects::set_status( $pid, $status );
			if ( is_wp_error( $st ) ) {
				return $st;
			}
		}
		return true;
	}

	private static function member_delete_project( int $pid ) {
		if ( ! self::member_owned_project( $pid ) ) {
			return new \WP_Error( 'pp_forbidden', __( 'This project is not available.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		return Projects::delete( $pid ) ? true : new \WP_Error( 'pp_delete_failed', __( 'The project could not be deleted.', 'project-prepper' ) );
	}

	/** Projekt-Formular (Kernfelder) für Anlegen/Bearbeiten. */
	private static function project_form( string $do, ?object $p ): void {
		$val = static fn( string $f, $d = '' ) => $p && isset( $p->$f ) && null !== $p->$f ? $p->$f : $d;
		?>
		<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::action_fields( $do ); ?>
			<?php if ( $p ) : ?>
				<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
			<?php endif; ?>
			<label><?php esc_html_e( 'Project name', 'project-prepper' ); ?>
				<input type="text" name="pp_name" value="<?php echo esc_attr( (string) $val( 'name' ) ); ?>" required>
			</label>
			<label><?php esc_html_e( 'Status', 'project-prepper' ); ?>
				<select name="pp_status">
					<?php foreach ( Projects::STATUSES as $st ) : ?>
						<option value="<?php echo esc_attr( $st ); ?>" <?php selected( (string) $val( 'status', 'draft' ), $st ); ?>><?php echo esc_html( self::project_status_label( $st ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><?php esc_html_e( 'From', 'project-prepper' ); ?>
				<input type="date" name="pp_from" value="<?php echo esc_attr( (string) $val( 'date_start' ) ); ?>">
			</label>
			<label><?php esc_html_e( 'To', 'project-prepper' ); ?>
				<input type="date" name="pp_to" value="<?php echo esc_attr( (string) $val( 'date_end' ) ); ?>">
			</label>
			<label><?php esc_html_e( 'Venue', 'project-prepper' ); ?>
				<input type="text" name="pp_venue" value="<?php echo esc_attr( (string) $val( 'venue_name' ) ); ?>">
			</label>
			<label><?php esc_html_e( 'Client', 'project-prepper' ); ?>
				<input type="text" name="pp_client" value="<?php echo esc_attr( (string) $val( 'client_name' ) ); ?>">
			</label>
			<label><?php esc_html_e( 'Notes', 'project-prepper' ); ?>
				<textarea name="pp_notes" rows="2"><?php echo esc_textarea( (string) $val( 'notes' ) ); ?></textarea>
			</label>
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save project', 'project-prepper' ); ?></button>
		</form>
		<?php
	}

	/** Projekte des aktiven Workspaces (Solo → keine; sonst nur die aktive Gruppe). */
	private static function member_projects( array $groups ): array {
		$active = self::active_group_id( $groups );
		if ( ! $active ) {
			return [];
		}
		return array_values( array_filter(
			Projects::all(),
			static fn( $p ) => (int) $p->owner_group_id === $active
		) );
	}

	private static function view_projects( WP_User $user, array $groups ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Navigation
		$pid = isset( $_GET['pp_project'] ) ? (int) $_GET['pp_project'] : 0;
		if ( $pid ) {
			self::view_project_detail( $pid, $groups );
			return;
		}

		$projects    = self::member_projects( $groups );
		$group_names = [];
		foreach ( $groups as $g ) {
			$group_names[ (int) $g->id ] = $g->name;
		}
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title"><?php esc_html_e( 'My projects', 'project-prepper' ); ?></h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Projects of the collectives you belong to.', 'project-prepper' ); ?></p>
		</header>
		<?php if ( ! $projects ) : ?>
			<?php if ( 0 === self::active_group_id( $groups ) && $groups ) : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'You are in Solo. Pick a group in the workspace switcher (top left) to see its projects.', 'project-prepper' ); ?></p>
			<?php else : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'No projects yet. Projects created in your collectives will appear here.', 'project-prepper' ); ?></p>
			<?php endif; ?>
		<?php else : ?>
			<div class="pp-proj-list">
				<?php foreach ( $projects as $p ) :
					$bits  = [];
					$range = self::fmt_range( $p->date_start, $p->date_end );
					if ( '' !== $range ) {
						$bits[] = $range;
					}
					if ( '' !== (string) $p->venue_name ) {
						$bits[] = $p->venue_name;
					}
					if ( isset( $group_names[ (int) $p->owner_group_id ] ) ) {
						$bits[] = $group_names[ (int) $p->owner_group_id ];
					}
					?>
					<a class="pp-proj-card" href="<?php echo esc_url( add_query_arg( [ 'pp_view' => 'projects', 'pp_project' => (int) $p->id ], self::portal_url() ) ); ?>">
						<span class="pp-proj-card__num"><?php echo esc_html( $p->project_number ); ?></span>
						<div class="pp-proj-card__head">
							<span class="pp-proj-card__name"><?php echo esc_html( $p->name ); ?></span>
							<span class="pp-status pp-status--<?php echo esc_attr( $p->status ); ?>"><?php echo esc_html( self::project_status_label( $p->status ) ); ?></span>
						</div>
						<div class="pp-proj-card__meta"><?php echo esc_html( implode( ' · ', $bits ) ); ?></div>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( self::active_group_id( $groups ) > 0 ) : ?>
			<details class="pp-portal__add" style="margin-top:1rem">
				<summary class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'New project', 'project-prepper' ); ?></summary>
				<?php self::project_form( 'project_create', null ); ?>
			</details>
		<?php endif; ?>
		<?php
	}

	private static function view_project_detail( int $pid, array $groups ): void {
		$p    = Projects::get( $pid );
		$back = add_query_arg( 'pp_view', 'projects', self::portal_url() );

		// Nur Gruppen-Projekte der eigenen Kollektive (Site-Ebene zählt hier nicht
		// als „mein Projekt" — verhindert Sicht auf reine Plattform-Projekte).
		$gids = array_map( static fn( $g ) => (int) $g->id, $groups );
		if ( ! $p || ! in_array( (int) $p->owner_group_id, $gids, true ) ) {
			?>
			<p class="pp-proj-back"><a href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( '← Back to projects', 'project-prepper' ); ?></a></p>
			<p class="pp-portal__empty"><?php esc_html_e( 'This project is not available.', 'project-prepper' ); ?></p>
			<?php
			return;
		}
		$range = self::fmt_range( $p->date_start, $p->date_end );
		?>
		<p class="pp-proj-back"><a href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( '← Back to projects', 'project-prepper' ); ?></a></p>
		<header class="pp-app__page-head">
			<div class="pp-proj-detail-head">
				<h1 class="pp-app__page-title"><?php echo esc_html( $p->name ); ?></h1>
				<span class="pp-status pp-status--<?php echo esc_attr( $p->status ); ?>"><?php echo esc_html( self::project_status_label( $p->status ) ); ?></span>
			</div>
			<p class="pp-app__page-sub"><?php echo esc_html( $p->project_number . ( '' !== $range ? ' · ' . $range : '' ) ); ?></p>
		</header>

		<?php if ( (int) $p->owner_group_id === self::active_workspace_group() ) : ?>
			<div class="pp-portal__actions" style="margin-bottom:1rem">
				<details class="pp-portal__edit">
					<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Edit project', 'project-prepper' ); ?></summary>
					<?php self::project_form( 'project_update', $p ); ?>
				</details>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this project? This cannot be undone.', 'project-prepper' ) ); ?>');">
					<?php self::action_fields( 'project_delete' ); ?>
					<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
					<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Delete project', 'project-prepper' ); ?></button>
				</form>
			</div>
		<?php endif; ?>

		<?php
		// 1) Übersicht (Veranstaltungsort / Kunde / Notizen) — nur wenn etwas da ist.
		$has_overview = '' !== (string) $p->venue_name || '' !== (string) $p->venue_address
			|| '' !== (string) $p->client_name || '' !== (string) $p->notes;
		if ( $has_overview ) :
			?>
			<section class="pp-card">
				<h3 class="pp-card__title"><?php esc_html_e( 'Overview', 'project-prepper' ); ?></h3>
				<dl class="pp-dl">
					<?php
					if ( '' !== (string) $p->venue_name || '' !== (string) $p->venue_address ) {
						$venue = trim( $p->venue_name . ( '' !== (string) $p->venue_address ? ', ' . $p->venue_address : '' ), ', ' );
						self::dl_row( __( 'Venue', 'project-prepper' ), $venue );
					}
					if ( '' !== (string) $p->client_name ) {
						self::dl_row( __( 'Client', 'project-prepper' ), $p->client_name );
					}
					if ( '' !== (string) $p->notes ) {
						self::dl_row( __( 'Notes', 'project-prepper' ), $p->notes );
					}
					?>
				</dl>
			</section>
			<?php
		endif;

		// 2) Gebuchtes Equipment.
		if ( ! empty( $p->items ) ) : ?>
			<section class="pp-card">
				<h3 class="pp-card__title"><?php esc_html_e( 'Booked equipment', 'project-prepper' ); ?></h3>
				<div class="pp-rows">
					<?php foreach ( $p->items as $line ) :
						$lrange = self::fmt_range( $line->date_from, $line->date_to ); ?>
						<div class="pp-row">
							<span class="pp-row__main"><?php echo esc_html( $line->item_name ?: ( '#' . (int) $line->item_id ) ); ?></span>
							<?php if ( ! empty( $line->inventory_number ) ) : ?>
								<span class="pp-portal__item-num"><?php echo esc_html( $line->inventory_number ); ?></span>
							<?php endif; ?>
							<span class="pp-row__meta">
								<?php
								/* translators: %d: quantity. */
								printf( esc_html__( 'Qty %d', 'project-prepper' ), (int) $line->quantity );
								if ( '' !== $lrange ) {
									echo ' · ' . esc_html( $lrange );
								}
								?>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif;

		// 3) Zeitplan.
		if ( ! empty( $p->schedule ) ) : ?>
			<section class="pp-card">
				<h3 class="pp-card__title"><?php esc_html_e( 'Schedule', 'project-prepper' ); ?></h3>
				<div class="pp-rows">
					<?php foreach ( $p->schedule as $s ) :
						$time = trim( (string) $s->time_start . ( ! empty( $s->time_end ) ? '–' . $s->time_end : '' ) );
						$meta = trim( self::fmt_date( $s->schedule_date ) . ( '' !== $time ? ' ' . $time : '' ) ); ?>
						<div class="pp-row">
							<span class="pp-row__main"><?php echo esc_html( $s->title ); ?></span>
							<?php if ( ! empty( $s->location ) ) : ?>
								<span class="pp-muted-inline"><?php echo esc_html( $s->location ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $meta ) : ?>
								<span class="pp-row__meta"><?php echo esc_html( $meta ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif;

		// 4) Aufgaben.
		if ( ! empty( $p->tasks ) ) : ?>
			<section class="pp-card">
				<h3 class="pp-card__title"><?php esc_html_e( 'Tasks', 'project-prepper' ); ?></h3>
				<div class="pp-rows">
					<?php foreach ( $p->tasks as $t ) :
						$assignee = $t->assigned_user ? get_userdata( (int) $t->assigned_user ) : null;
						$meta     = self::task_priority_label( (string) $t->priority );
						if ( ! empty( $t->due_date ) ) {
							$meta .= ' · ' . self::fmt_date( $t->due_date );
						}
						if ( $assignee ) {
							$meta .= ' · ' . $assignee->display_name;
						} ?>
						<div class="pp-row">
							<span class="pp-status pp-status--<?php echo esc_attr( $t->task_status ); ?>"><?php echo esc_html( self::task_status_label( (string) $t->task_status ) ); ?></span>
							<span class="pp-row__main"><?php echo esc_html( $t->title ); ?></span>
							<span class="pp-row__meta"><?php echo esc_html( $meta ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif;

		// 5) Checklisten.
		if ( ! empty( $p->checklists ) ) : ?>
			<section class="pp-card">
				<h3 class="pp-card__title"><?php esc_html_e( 'Checklists', 'project-prepper' ); ?></h3>
				<?php foreach ( $p->checklists as $list ) : ?>
					<div class="pp-checklist">
						<p class="pp-checklist__name"><?php echo esc_html( $list->name ); ?></p>
						<?php foreach ( (array) $list->items as $ci ) :
							$done = ! empty( $ci->is_checked ); ?>
							<div class="pp-checkitem<?php echo $done ? ' pp-checkitem--done' : ''; ?>">
								<span class="pp-checkitem__box<?php echo $done ? ' pp-checkitem__box--on' : ''; ?>"><?php echo $done ? '✓' : ''; ?></span>
								<span class="pp-checkitem__label"><?php echo esc_html( $ci->label ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</section>
		<?php endif;

		// 6) Material.
		if ( ! empty( $p->consumables ) ) : ?>
			<section class="pp-card">
				<h3 class="pp-card__title"><?php esc_html_e( 'Materials', 'project-prepper' ); ?></h3>
				<div class="pp-rows">
					<?php foreach ( $p->consumables as $c ) : ?>
						<div class="pp-row">
							<span class="pp-row__main"><?php echo esc_html( $c->name ); ?></span>
							<span class="pp-row__meta"><?php echo esc_html( trim( (string) $c->quantity . ' ' . (string) $c->unit ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif;

		// 7) Team.
		if ( ! empty( $p->team ) ) : ?>
			<section class="pp-card">
				<h3 class="pp-card__title"><?php esc_html_e( 'Team', 'project-prepper' ); ?></h3>
				<div class="pp-rows">
					<?php foreach ( $p->team as $m ) :
						$meta = trim( (string) $m->role . ( '' !== (string) $m->department ? ' · ' . $m->department : '' ) ); ?>
						<div class="pp-row">
							<span class="pp-row__main"><?php echo esc_html( $m->name ); ?></span>
							<?php if ( '' !== $meta ) : ?>
								<span class="pp-row__meta"><?php echo esc_html( $meta ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif;

		// 8) Kontakte.
		if ( ! empty( $p->contacts ) ) : ?>
			<section class="pp-card">
				<h3 class="pp-card__title"><?php esc_html_e( 'Contacts', 'project-prepper' ); ?></h3>
				<div class="pp-rows">
					<?php foreach ( $p->contacts as $c ) :
						$meta = implode( ' · ', array_filter( [ $c->role, $c->company, $c->email, $c->phone ] ) ); ?>
						<div class="pp-row">
							<span class="pp-row__main"><?php echo esc_html( $c->name ); ?></span>
							<?php if ( '' !== $meta ) : ?>
								<span class="pp-row__meta"><?php echo esc_html( $meta ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif;

		// 9) Dateien.
		if ( ! empty( $p->files ) ) : ?>
			<section class="pp-card">
				<h3 class="pp-card__title"><?php esc_html_e( 'Files', 'project-prepper' ); ?></h3>
				<div class="pp-rows">
					<?php foreach ( $p->files as $f ) :
						$label = '' !== (string) $f->title ? $f->title : ( $f->filename ?: __( 'File', 'project-prepper' ) ); ?>
						<div class="pp-row">
							<?php if ( ! empty( $f->url ) ) : ?>
								<a class="pp-row__main" href="<?php echo esc_url( $f->url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $label ); ?></a>
							<?php else : ?>
								<span class="pp-row__main"><?php echo esc_html( $label ); ?></span>
								<span class="pp-row__meta"><?php esc_html_e( 'missing', 'project-prepper' ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif;

		// 10) Beteiligte (read-only Roster aus der besitzenden Gruppe).
		self::render_project_members( $p );

		// 11) Kosten + Budget/Gewinn (read-only). Alle Betrachter dieses Details
		// sind aktive Mitglieder der besitzenden Gruppe (oben erzwungen) — das ist
		// das WP-Pendant zu canViewCosts=isMember der App. Kein Finanz-Leak gegen
		// Nicht-Mitglieder, weil die das Detail gar nicht erreichen.
		self::render_project_costs( $p );

		// 12) Gewinnverteilung (read-only, gleiche Mitglieder-Sicht wie Kosten).
		self::render_project_profit( $p );

		// 13) Kooperationsvereinbarung (read-only Status + Signatur-Roster).
		self::render_project_agreement_summary( $p );

		// 14) Beschlüsse + 15) Umfragen — interaktiv (Voting), nur Gruppenmitglieder.
		self::render_decisions( $p );
		self::render_polls( $p );
	}

	/** Beteiligten-Roster (read-only) — Name + Rolle/Notiz, verwaiste markiert. */
	private static function render_project_members( object $p ): void {
		$members = (array) ( $p->members ?? [] );
		if ( ! $members ) {
			return;
		}
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Participants', 'project-prepper' ); ?></h3>
			<div class="pp-rows">
				<?php foreach ( $members as $m ) :
					$meta = trim( (string) ( $m->role_title ?? '' ) . ( ! empty( $m->note ) ? ' · ' . $m->note : '' ) ); ?>
					<div class="pp-row">
						<span class="pp-row__main">
							<?php echo esc_html( (string) $m->display_name ); ?>
							<?php if ( ! empty( $m->missing ) ) : ?>
								<small class="pp-row__meta">(<?php esc_html_e( 'former member', 'project-prepper' ); ?>)</small>
							<?php endif; ?>
						</span>
						<?php if ( '' !== $meta ) : ?>
							<span class="pp-row__meta"><?php echo esc_html( $meta ); ?></span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/** Kosten + Budget/Gewinn (read-only). Mitglieder-Sicht = canViewCosts der App. */
	private static function render_project_costs( object $p ): void {
		$items   = (array) ( $p->cost_items ?? [] );
		$summary = (array) ( $p->cost_summary ?? [] );
		$has_money = $items
			|| null !== ( $summary['budget_planned'] ?? null )
			|| null !== ( $summary['revenue_actual'] ?? null );
		if ( ! $has_money ) {
			return;
		}
		$eur = static fn( $v ) => number_format_i18n( (float) $v, 2 ) . ' €';
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Costs & budget', 'project-prepper' ); ?></h3>

			<?php if ( $items ) : ?>
				<div class="pp-rows">
					<?php foreach ( $items as $c ) :
						$cat_label = self::cost_category_label( (string) ( $c->category ?? '' ) );
						$label = trim( $cat_label . ( ! empty( $c->description ) ? ' · ' . $c->description : '' ) );
						if ( '' === $label ) {
							$label = __( 'Cost item', 'project-prepper' );
						}
						$amount = null !== $c->amount_actual ? (float) $c->amount_actual : (float) $c->amount_planned;
						$is_planned = ( null === $c->amount_actual ); ?>
						<div class="pp-row">
							<span class="pp-row__main"><?php echo esc_html( $label ); ?></span>
							<span class="pp-row__meta">
								<?php
								echo esc_html( $eur( $amount ) );
								if ( (float) $c->vat_rate > 0 ) {
									/* translators: %s: VAT rate, e.g. 19. */
									echo ' · ' . esc_html( sprintf( __( '%s%% VAT', 'project-prepper' ), number_format_i18n( (float) $c->vat_rate, (float) $c->vat_rate == (int) $c->vat_rate ? 0 : 1 ) ) );
								}
								if ( $is_planned ) {
									echo ' · ' . esc_html__( 'planned', 'project-prepper' );
								}
								?>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<dl class="pp-dl pp-dl--money">
				<?php
				self::dl_row( __( 'Planned (net)', 'project-prepper' ), $eur( $summary['planned_net'] ?? 0 ) );
				self::dl_row( __( 'Planned (gross)', 'project-prepper' ), $eur( $summary['planned_gross'] ?? 0 ) );
				if ( ( $summary['actual_net'] ?? 0 ) > 0 || ( $summary['actual_gross'] ?? 0 ) > 0 ) {
					self::dl_row( __( 'Actual (net)', 'project-prepper' ), $eur( $summary['actual_net'] ?? 0 ) );
					self::dl_row( __( 'Actual (gross)', 'project-prepper' ), $eur( $summary['actual_gross'] ?? 0 ) );
				}
				if ( null !== ( $summary['budget_planned'] ?? null ) ) {
					self::dl_row( __( 'Budget', 'project-prepper' ), $eur( $summary['budget_planned'] ) );
				}
				if ( null !== ( $summary['revenue_actual'] ?? null ) ) {
					self::dl_row( __( 'Revenue', 'project-prepper' ), $eur( $summary['revenue_actual'] ) );
				}
				if ( null !== ( $summary['profit'] ?? null ) ) {
					self::dl_row( __( 'Profit', 'project-prepper' ), $eur( $summary['profit'] ) );
				}
				?>
			</dl>
		</section>
		<?php
	}

	/** Gewinnverteilung (read-only) — Anteile je Mitglied + berechneter Betrag. */
	private static function render_project_profit( object $p ): void {
		$shares  = (array) ( $p->profit_shares ?? [] );
		if ( ! $shares ) {
			return;
		}
		$summary = (array) ( $p->profit_summary ?? [] );
		$eur     = static fn( $v ) => number_format_i18n( (float) $v, 2 ) . ' €';
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Profit distribution', 'project-prepper' ); ?></h3>
			<div class="pp-rows">
				<?php foreach ( $shares as $s ) :
					$is_pct = ( 'percentage' === ( $s->share_type ?? '' ) );
					$basis  = $is_pct
						? number_format_i18n( (float) $s->share_value, (float) $s->share_value == (int) $s->share_value ? 0 : 1 ) . ' %'
						: $eur( $s->share_value );
					$calc   = ( null !== ( $s->calculated_amount ?? null ) ) ? $eur( $s->calculated_amount ) : '—'; ?>
					<div class="pp-row">
						<span class="pp-row__main">
							<?php echo esc_html( (string) $s->display_name ); ?>
							<?php if ( ! empty( $s->missing ) ) : ?>
								<small class="pp-row__meta">(<?php esc_html_e( 'former member', 'project-prepper' ); ?>)</small>
							<?php endif; ?>
						</span>
						<span class="pp-row__meta"><?php echo esc_html( $basis ); ?></span>
						<span class="pp-row__meta pp-row__meta--strong"><?php echo esc_html( $calc ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( $summary ) : ?>
				<dl class="pp-dl pp-dl--money">
					<?php
					if ( null !== ( $summary['pool'] ?? null ) ) {
						self::dl_row( __( 'Profit pool', 'project-prepper' ), $eur( $summary['pool'] ) );
					}
					if ( null !== ( $summary['total_allocated'] ?? null ) ) {
						self::dl_row( __( 'Allocated', 'project-prepper' ), $eur( $summary['total_allocated'] ) );
					}
					if ( ! empty( $summary['over_allocated'] ) ) {
						echo '<dt>' . esc_html__( 'Note', 'project-prepper' ) . '</dt><dd class="pp-money-warn">' . esc_html__( 'More than the pool is allocated.', 'project-prepper' ) . '</dd>';
					}
					?>
				</dl>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Kooperationsvereinbarung (read-only) — Status, Version, Signatur-Roster. */
	private static function render_project_agreement_summary( object $p ): void {
		$a = $p->agreement ?? null;
		if ( ! $a ) {
			return;
		}
		$status_labels = [
			'draft'      => __( 'Draft', 'project-prepper' ),
			'signing'    => __( 'In signing', 'project-prepper' ),
			'active'     => __( 'Active', 'project-prepper' ),
			'terminated' => __( 'Terminated', 'project-prepper' ),
		];
		$sig_labels = [
			'signed'   => __( 'Signed', 'project-prepper' ),
			'declined' => __( 'Declined', 'project-prepper' ),
			'pending'  => __( 'Pending', 'project-prepper' ),
		];
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Cooperation agreement', 'project-prepper' ); ?></h3>
			<div class="pp-proj-detail-head" style="margin-bottom:.5rem">
				<span class="pp-status pp-status--<?php echo esc_attr( (string) $a->status ); ?>"><?php echo esc_html( $status_labels[ $a->status ] ?? $a->status ); ?></span>
				<?php if ( ! empty( $a->title ) ) : ?>
					<span class="pp-row__main"><?php echo esc_html( (string) $a->title ); ?></span>
				<?php endif; ?>
				<span class="pp-row__meta">
					<?php
					/* translators: %d: agreement version. */
					printf( esc_html__( 'Version %d', 'project-prepper' ), (int) $a->version );
					?>
				</span>
			</div>

			<?php if ( ! empty( $a->terms ) ) : ?>
				<p class="pp-agreement__terms"><?php echo nl2br( esc_html( (string) $a->terms ) ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $a->signatures ) ) : ?>
				<div class="pp-rows">
					<?php foreach ( $a->signatures as $s ) :
						$st   = (string) ( $s->status ?? 'pending' );
						$when = '';
						if ( 'signed' === $st && ! empty( $s->signed_at ) ) {
							$when = self::fmt_date( substr( (string) $s->signed_at, 0, 10 ) );
						} elseif ( 'declined' === $st && ! empty( $s->declined_at ) ) {
							$when = self::fmt_date( substr( (string) $s->declined_at, 0, 10 ) );
						} ?>
						<div class="pp-row">
							<span class="pp-status pp-status--<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $sig_labels[ $st ] ?? $st ); ?></span>
							<span class="pp-row__main"><?php echo esc_html( (string) $s->display_name ); ?></span>
							<?php if ( '' !== $when ) : ?>
								<span class="pp-row__meta"><?php echo esc_html( $when ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/* ---------- Beschlüsse + Umfragen (interaktiv) ---------- */

	private static function render_decisions( object $p ): void {
		$decisions  = (array) ( $p->decisions ?? [] );
		$project_id = (int) $p->id;
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Decisions', 'project-prepper' ); ?></h3>
			<?php if ( ! $decisions ) : ?>
				<p class="pp-portal__empty pp-gov__empty"><?php esc_html_e( 'No decisions yet.', 'project-prepper' ); ?></p>
			<?php else :
				foreach ( $decisions as $d ) : ?>
					<div class="pp-gov">
						<div class="pp-gov__head">
							<span class="pp-gov__title"><?php echo esc_html( $d->title ); ?></span>
							<span class="pp-status pp-status--<?php echo esc_attr( self::decision_status_class( $d->status ) ); ?>"><?php echo esc_html( self::decision_status_label( $d->status ) ); ?></span>
							<span class="pp-gov__mode"><?php echo esc_html( $d->requires_unanimous ? __( 'Unanimous', 'project-prepper' ) : __( 'Majority', 'project-prepper' ) ); ?></span>
						</div>
						<?php if ( '' !== (string) $d->description ) : ?>
							<p class="pp-gov__desc"><?php echo nl2br( esc_html( $d->description ) ); ?></p>
						<?php endif; ?>
						<p class="pp-gov__tally">
							<?php
							printf(
								/* translators: 1: approve count, 2: reject count, 3: abstain count, 4: total eligible voters. */
								esc_html__( 'Approve %1$d · Reject %2$d · Abstain %3$d of %4$d', 'project-prepper' ),
								(int) $d->approve_count,
								(int) $d->reject_count,
								(int) $d->abstain_count,
								(int) $d->total_active
							);
							?>
						</p>
						<?php if ( 'open' === $d->status && $d->can_vote ) : ?>
							<div class="pp-gov__vote">
								<?php foreach ( [ 'approve' => __( 'Approve', 'project-prepper' ), 'reject' => __( 'Reject', 'project-prepper' ), 'abstain' => __( 'Abstain', 'project-prepper' ) ] as $v => $label ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php self::action_fields( 'decision_vote' ); ?>
										<input type="hidden" name="pp_project" value="<?php echo (int) $project_id; ?>">
										<input type="hidden" name="pp_decision" value="<?php echo (int) $d->id; ?>">
										<input type="hidden" name="pp_vote" value="<?php echo esc_attr( $v ); ?>">
										<button type="submit" class="pp-vote-btn pp-vote-btn--<?php echo esc_attr( $v ); ?><?php echo $d->my_vote === $v ? ' is-active' : ''; ?>"><?php echo esc_html( $label ); ?></button>
									</form>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<?php if ( 'open' === $d->status && self::gov_can_manage( $d ) ) : ?>
							<form class="pp-gov__manage" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php self::action_fields( 'decision_cancel' ); ?>
								<input type="hidden" name="pp_project" value="<?php echo (int) $project_id; ?>">
								<input type="hidden" name="pp_decision" value="<?php echo (int) $d->id; ?>">
								<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Close', 'project-prepper' ); ?></button>
							</form>
						<?php endif; ?>
					</div>
				<?php endforeach;
			endif; ?>

			<details class="pp-portal__add">
				<summary class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'New decision', 'project-prepper' ); ?></summary>
				<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php self::action_fields( 'decision_create' ); ?>
					<input type="hidden" name="pp_project" value="<?php echo (int) $project_id; ?>">
					<label><?php esc_html_e( 'Title', 'project-prepper' ); ?>
						<input type="text" name="pp_title" required>
					</label>
					<label><?php esc_html_e( 'Description (optional)', 'project-prepper' ); ?>
						<textarea name="pp_description" rows="2"></textarea>
					</label>
					<label class="pp-gov__check"><input type="checkbox" name="pp_unanimous" value="1" checked> <?php esc_html_e( 'Requires unanimous', 'project-prepper' ); ?></label>
					<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Create decision', 'project-prepper' ); ?></button>
				</form>
			</details>
		</section>
		<?php
	}

	/** Projekt-Umfragen (im Projekt-Detail, im Card-Rahmen). */
	private static function render_polls( object $p ): void {
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Polls', 'project-prepper' ); ?></h3>
			<?php self::render_polls_list( (array) ( $p->polls ?? [] ), [ 'prefix' => 'poll', 'project' => (int) $p->id ] ); ?>
		</section>
		<?php
	}

	/** Hidden-Felder, die das Redirect-Ziel der Umfrage-Formulare bestimmen. */
	private static function poll_ctx_hidden( array $ctx ): void {
		if ( isset( $ctx['project'] ) ) {
			echo '<input type="hidden" name="pp_project" value="' . (int) $ctx['project'] . '">';
		}
		if ( isset( $ctx['group'] ) ) {
			echo '<input type="hidden" name="pp_group" value="' . (int) $ctx['group'] . '">';
			echo '<input type="hidden" name="pp_view" value="polls">';
		}
	}

	/**
	 * Umfrage-Liste + „Neue Umfrage"-Formular — von Projekt- UND Gruppen-Umfragen
	 * genutzt. $ctx['prefix'] = 'poll' (Projekt) | 'gpoll' (Gruppe) wählt die
	 * Dispatcher-Aktionen; poll_ctx_hidden() setzt das Redirect-Ziel.
	 */
	private static function render_polls_list( array $polls, array $ctx ): void {
		$pre = $ctx['prefix'];
		if ( ! $polls ) : ?>
			<p class="pp-portal__empty pp-gov__empty"><?php esc_html_e( 'No polls yet.', 'project-prepper' ); ?></p>
		<?php else :
			foreach ( $polls as $poll ) :
				$open = ( 'open' === $poll->status ); ?>
				<div class="pp-gov">
					<div class="pp-gov__head">
						<span class="pp-gov__title"><?php echo esc_html( $poll->title ); ?></span>
						<span class="pp-status pp-status--<?php echo $open ? 'open' : 'done'; ?>"><?php echo esc_html( $open ? __( 'Open', 'project-prepper' ) : __( 'Closed', 'project-prepper' ) ); ?></span>
						<span class="pp-gov__mode"><?php echo esc_html( 'date' === $poll->poll_type ? __( 'Date poll', 'project-prepper' ) : __( 'Choice poll', 'project-prepper' ) ); ?></span>
					</div>
					<?php if ( '' !== (string) $poll->description ) : ?>
						<p class="pp-gov__desc"><?php echo nl2br( esc_html( $poll->description ) ); ?></p>
					<?php endif; ?>
					<div class="pp-poll-opts">
						<?php foreach ( (array) $poll->options as $opt ) :
							$mine = $poll->my_votes->{(string) $opt->id} ?? ''; ?>
							<div class="pp-poll-opt">
								<span class="pp-poll-opt__label"><?php echo esc_html( self::poll_option_label( $poll->poll_type, $opt ) ); ?></span>
								<span class="pp-poll-opt__tally">
									<span class="pp-poll-c pp-poll-c--yes"><?php echo (int) $opt->yes; ?></span>
									<span class="pp-poll-c pp-poll-c--maybe"><?php echo (int) $opt->maybe; ?></span>
									<span class="pp-poll-c pp-poll-c--no"><?php echo (int) $opt->no; ?></span>
								</span>
								<?php if ( $open && $poll->can_vote ) : ?>
									<span class="pp-poll-opt__vote">
										<?php foreach ( [ 'yes' => __( 'Yes', 'project-prepper' ), 'maybe' => __( 'Maybe', 'project-prepper' ), 'no' => __( 'No', 'project-prepper' ) ] as $v => $vl ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<?php self::action_fields( $pre . '_vote' ); ?>
												<?php self::poll_ctx_hidden( $ctx ); ?>
												<input type="hidden" name="pp_option" value="<?php echo (int) $opt->id; ?>">
												<input type="hidden" name="pp_vote" value="<?php echo esc_attr( $v ); ?>">
												<button type="submit" class="pp-poll-btn pp-poll-btn--<?php echo esc_attr( $v ); ?><?php echo $mine === $v ? ' is-active' : ''; ?>"><?php echo esc_html( $vl ); ?></button>
											</form>
										<?php endforeach; ?>
									</span>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
					<?php if ( self::gov_can_manage( $poll ) ) : ?>
						<form class="pp-gov__manage" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php self::action_fields( $open ? $pre . '_close' : $pre . '_reopen' ); ?>
							<?php self::poll_ctx_hidden( $ctx ); ?>
							<input type="hidden" name="pp_poll" value="<?php echo (int) $poll->id; ?>">
							<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php echo esc_html( $open ? __( 'Close', 'project-prepper' ) : __( 'Reopen', 'project-prepper' ) ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			<?php endforeach;
		endif; ?>

		<button type="button" class="pp-portal__btn pp-portal__btn--sm" data-pp-modal="pp-poll-create"><?php esc_html_e( 'New poll', 'project-prepper' ); ?></button>
		<dialog class="pp-modal pp-modal--portal" id="pp-poll-create">
			<div class="pp-modal-header">
				<h2 class="pp-modal__title"><?php esc_html_e( 'New poll', 'project-prepper' ); ?></h2>
				<button type="button" class="pp-modal-close" data-pp-modal-close aria-label="<?php esc_attr_e( 'Close', 'project-prepper' ); ?>">✕</button>
			</div>
			<div class="pp-modal-body">
			<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php self::action_fields( $pre . '_create' ); ?>
				<?php self::poll_ctx_hidden( $ctx ); ?>
				<label><?php esc_html_e( 'Title', 'project-prepper' ); ?>
					<input type="text" name="pp_title" required>
				</label>
				<label><?php esc_html_e( 'Poll type', 'project-prepper' ); ?>
					<select name="pp_poll_type">
						<option value="choice"><?php esc_html_e( 'Choice poll', 'project-prepper' ); ?></option>
						<option value="date"><?php esc_html_e( 'Date poll', 'project-prepper' ); ?></option>
					</select>
				</label>
				<fieldset class="pp-poll-optset">
					<legend><?php esc_html_e( 'Options', 'project-prepper' ); ?></legend>
					<div class="pp-poll-optbox-list">
						<?php for ( $i = 1; $i <= 3; $i++ ) : ?>
							<input type="text" name="pp_opt[]" class="pp-poll-optbox" placeholder="<?php
								/* translators: %d: option number. */
								echo esc_attr( sprintf( __( 'Option %d', 'project-prepper' ), $i ) );
							?>">
						<?php endfor; ?>
					</div>
					<button type="button" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm pp-poll-add" data-label="<?php esc_attr_e( 'Option', 'project-prepper' ); ?>"><?php esc_html_e( '+ Add option', 'project-prepper' ); ?></button>
					<p class="pp-poll-opthint"><?php esc_html_e( 'At least two options. For date polls enter YYYY-MM-DD (optionally HH:MM). Empty boxes are ignored.', 'project-prepper' ); ?></p>
				</fieldset>
				<label><?php esc_html_e( 'Description (optional)', 'project-prepper' ); ?>
					<textarea name="pp_description" rows="2"></textarea>
				</label>
				<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Create poll', 'project-prepper' ); ?></button>
			</form>
			</div>
		</dialog>
		<?php
	}

	/**
	 * Globale Kostenübersicht (Pendant zur App-Seite `/costs`): aggregiert die
	 * Kostenposten über ALLE Projekte des aktiven Workspace. Wie die App nur im
	 * Gruppen-Modus — im Solo-Modus stehen Kosten direkt im jeweiligen Projekt.
	 * Leak-sicher: Quelle ist `member_projects()` (nur Projekte der aktiven
	 * Gruppe, in der der User Mitglied ist) → `Costs::for_projects()`.
	 */
	private static function view_costs( WP_User $user, array $groups ): void {
		$active = self::active_group_id( $groups );
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title"><?php esc_html_e( 'Costs', 'project-prepper' ); ?></h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Aggregated across all projects of your active group.', 'project-prepper' ); ?></p>
		</header>
		<?php
		if ( ! $active ) {
			echo '<p class="pp-portal__empty">' . esc_html__( 'You are in Solo. In solo mode you find costs directly inside each project. Pick a group in the workspace switcher (top left) for the aggregated view.', 'project-prepper' ) . '</p>';
			return;
		}

		$projects     = self::member_projects( $groups );
		$project_ids  = array_map( static fn( $p ) => (int) $p->id, $projects );
		$all_costs    = Costs::for_projects( $project_ids );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reiner Filter, keine Mutation.
		$filter = isset( $_GET['pp_cat'] ) ? sanitize_key( wp_unslash( $_GET['pp_cat'] ) ) : 'all';
		if ( 'all' !== $filter && ! in_array( $filter, Costs::CATEGORIES, true ) ) {
			$filter = 'all';
		}
		$shown = 'all' === $filter
			? $all_costs
			: array_values( array_filter( $all_costs, static fn( $c ) => (string) $c->category === $filter ) );

		$planned = 0.0;
		$actual  = 0.0;
		foreach ( $shown as $c ) {
			$planned += (float) $c->amount_planned;
			$actual  += null !== $c->amount_actual ? (float) $c->amount_actual : 0.0;
		}
		$eur     = static fn( $v ) => number_format_i18n( (float) $v, 2 ) . ' €';
		$base    = self::portal_url();
		?>
		<div class="pp-kpi-grid pp-kpi-grid--compact">
			<?php
			self::rental_kpi( __( 'Projects', 'project-prepper' ), (string) count( $projects ), 'info' );
			self::rental_kpi( __( 'Planned costs', 'project-prepper' ), $eur( $planned ), 'primary' );
			self::rental_kpi( __( 'Actual costs', 'project-prepper' ), $eur( $actual ), $actual > $planned ? 'warning' : 'success' );
			?>
		</div>

		<div class="pp-inv-pills">
			<?php
			$cats = array_merge( [ 'all' ], Costs::CATEGORIES );
			foreach ( $cats as $cat ) :
				$label = 'all' === $cat ? __( 'All', 'project-prepper' ) : self::cost_category_label( $cat );
				$url   = add_query_arg( [ 'pp_view' => 'costs', 'pp_cat' => $cat ], $base );
				?>
				<a class="pp-portal__chip <?php echo $filter === $cat ? 'pp-portal__chip--on' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</div>

		<?php if ( ! $shown ) : ?>
			<p class="pp-portal__empty"><?php esc_html_e( 'No cost items.', 'project-prepper' ); ?></p>
		<?php else : ?>
			<section class="pp-card">
				<div class="pp-rows">
					<?php foreach ( $shown as $c ) :
						$purl = add_query_arg( [ 'pp_view' => 'projects', 'pp_project' => (int) $c->project_id ], $base );
						$desc = '' !== (string) $c->description ? $c->description : '';
						$amount     = null !== $c->amount_actual ? (float) $c->amount_actual : (float) $c->amount_planned;
						$is_planned = ( null === $c->amount_actual ); ?>
						<div class="pp-row pp-row--costs">
							<span class="pp-row__main">
								<a class="pp-row__link" href="<?php echo esc_url( $purl ); ?>"><?php echo esc_html( $c->project_name ?: '—' ); ?></a>
								<span class="pp-row__sub"><?php echo esc_html( trim( self::cost_category_label( (string) $c->category ) . ( '' !== $desc ? ' · ' . $desc : '' ) ) ); ?></span>
							</span>
							<span class="pp-row__meta">
								<?php
								echo esc_html( $eur( $amount ) );
								if ( $is_planned ) {
									echo ' · ' . esc_html__( 'planned', 'project-prepper' );
								}
								?>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>
		<?php
	}

	/** Eigenständiger „Umfragen"-Tab (gruppen-weit, nur im Gruppen-Modus). */
	private static function view_polls( array $groups ): void {
		$active = self::active_group_id( $groups );
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title"><?php esc_html_e( 'Polls', 'project-prepper' ); ?></h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Polls for your active group — appointment finding and decisions, independent of a project.', 'project-prepper' ); ?></p>
		</header>
		<?php
		if ( ! $active ) {
			echo '<p class="pp-portal__empty">' . esc_html__( 'You are in Solo. Pick a group in the workspace switcher (top left) to run polls.', 'project-prepper' ) . '</p>';
			return;
		}
		$polls = Polls::for_group( $active, get_current_user_id() ?: null );
		?>
		<section class="pp-card">
			<?php self::render_polls_list( $polls, [ 'prefix' => 'gpoll', 'group' => $active ] ); ?>
		</section>
		<?php
	}

	private static function poll_option_label( string $type, object $opt ): string {
		if ( 'date' === $type ) {
			$label = self::fmt_date( $opt->option_date );
			if ( ! empty( $opt->option_time ) ) {
				$label .= ' ' . substr( (string) $opt->option_time, 0, 5 );
			}
			return $label;
		}
		return (string) $opt->label;
	}

	/** Darf der aktuelle User diesen Beschluss/diese Umfrage verwalten (Ersteller)? */
	private static function gov_can_manage( object $row ): bool {
		$uid = get_current_user_id();
		return $uid && (int) $row->created_by === $uid;
	}

	private static function decision_status_label( string $s ): string {
		$map = [
			'open'      => __( 'Open', 'project-prepper' ),
			'approved'  => __( 'Approved', 'project-prepper' ),
			'rejected'  => __( 'Rejected', 'project-prepper' ),
			'cancelled' => __( 'Cancelled', 'project-prepper' ),
		];
		return $map[ $s ] ?? $s;
	}

	/** Beschluss-Status → vorhandene .pp-status-Farbklasse. */
	private static function decision_status_class( string $s ): string {
		$map = [
			'open'      => 'open',
			'approved'  => 'running',
			'rejected'  => 'cancelled',
			'cancelled' => 'done',
		];
		return $map[ $s ] ?? 'done';
	}

	/** Eine Zeile in der Übersichts-Definitionsliste (Label + Wert). */
	private static function dl_row( string $label, string $value ): void {
		echo '<dt>' . esc_html( $label ) . '</dt><dd>' . nl2br( esc_html( $value ) ) . '</dd>';
	}

	private static function fmt_date( ?string $date ): string {
		if ( empty( $date ) || '0000-00-00' === $date ) {
			return '';
		}
		$ts = strtotime( $date );
		return $ts ? date_i18n( (string) get_option( 'date_format' ), $ts ) : (string) $date;
	}

	private static function fmt_range( ?string $from, ?string $to ): string {
		$a = self::fmt_date( $from );
		$b = self::fmt_date( $to );
		if ( '' !== $a && '' !== $b ) {
			return $a . ' – ' . $b;
		}
		return '' !== $a ? $a : $b;
	}

	private static function project_status_label( string $s ): string {
		$map = [
			'draft'     => __( 'Draft', 'project-prepper' ),
			'planned'   => __( 'Planned', 'project-prepper' ),
			'confirmed' => __( 'Confirmed', 'project-prepper' ),
			'running'   => __( 'Running', 'project-prepper' ),
			'done'      => __( 'Done', 'project-prepper' ),
			'cancelled' => __( 'Cancelled', 'project-prepper' ),
		];
		return $map[ $s ] ?? $s;
	}

	private static function task_status_label( string $s ): string {
		$map = [
			'open'  => __( 'Open', 'project-prepper' ),
			'doing' => __( 'In progress', 'project-prepper' ),
			'done'  => __( 'Done', 'project-prepper' ),
		];
		return $map[ $s ] ?? $s;
	}

	private static function task_priority_label( string $s ): string {
		$map = [
			'low'    => __( 'Low', 'project-prepper' ),
			'normal' => __( 'Normal', 'project-prepper' ),
			'high'   => __( 'High', 'project-prepper' ),
		];
		return $map[ $s ] ?? $s;
	}

	private static function cost_category_label( string $s ): string {
		$map = [
			'personnel' => __( 'Personnel', 'project-prepper' ),
			'material'  => __( 'Material', 'project-prepper' ),
			'inventory' => __( 'Inventory', 'project-prepper' ),
			'external'  => __( 'External services', 'project-prepper' ),
			'other'     => __( 'Other', 'project-prepper' ),
		];
		return $map[ $s ] ?? $s;
	}

	/* ---------- Kalender (read-only Monatsraster) ---------- */

	private static function view_calendar( WP_User $user, array $groups ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Navigation
		$mode = ( isset( $_GET['pp_cal'] ) && 'week' === sanitize_key( wp_unslash( $_GET['pp_cal'] ) ) ) ? 'week' : 'month';
		if ( 'week' === $mode ) {
			self::render_calendar_week( $user, $groups );
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Navigation
		$month = isset( $_GET['pp_month'] ) ? sanitize_text_field( wp_unslash( $_GET['pp_month'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$month = current_time( 'Y-m' );
		}
		$first_ts    = strtotime( $month . '-01' );
		$days        = (int) gmdate( 't', $first_ts );
		$month_start = $month . '-01';
		$month_end   = sprintf( '%s-%02d', $month, $days );
		$lead        = (int) gmdate( 'N', $first_ts ) - 1; // Mo=0 … So=6
		$today       = current_time( 'Y-m-d' );
		$prev        = gmdate( 'Y-m', strtotime( '-1 month', $first_ts ) );
		$next        = gmdate( 'Y-m', strtotime( '+1 month', $first_ts ) );

		$by_day = self::calendar_events( $user, $groups, $month_start, $month_end );
		$base   = self::view_url( 'calendar' );
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title"><?php esc_html_e( 'Calendar', 'project-prepper' ); ?></h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Your collectives’ projects, schedule and loans at a glance.', 'project-prepper' ); ?></p>
		</header>

		<div class="pp-cal">
			<div class="pp-cal__bar">
				<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" href="<?php echo esc_url( add_query_arg( 'pp_month', $prev, $base ) ); ?>" aria-label="<?php esc_attr_e( 'Previous month', 'project-prepper' ); ?>">‹</a>
				<span class="pp-cal__title"><?php echo esc_html( date_i18n( 'F Y', $first_ts ) ); ?></span>
				<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" href="<?php echo esc_url( add_query_arg( 'pp_month', $next, $base ) ); ?>" aria-label="<?php esc_attr_e( 'Next month', 'project-prepper' ); ?>">›</a>
				<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm pp-cal__today" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Today', 'project-prepper' ); ?></a>
				<span class="pp-cal__modes">
					<span class="pp-portal__btn pp-portal__btn--sm pp-cal__mode--on"><?php esc_html_e( 'Month', 'project-prepper' ); ?></span>
					<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" href="<?php echo esc_url( add_query_arg( 'pp_cal', 'week', $base ) ); ?>"><?php esc_html_e( 'Week', 'project-prepper' ); ?></a>
				</span>
			</div>

			<div class="pp-cal__grid">
				<?php
				$ref = strtotime( '2024-01-01' ); // Montag
				for ( $i = 0; $i < 7; $i++ ) {
					echo '<div class="pp-cal__dow">' . esc_html( date_i18n( 'D', strtotime( "+$i day", $ref ) ) ) . '</div>';
				}
				for ( $i = 0; $i < $lead; $i++ ) {
					echo '<div class="pp-cal__cell pp-cal__cell--blank"></div>';
				}
				for ( $d = 1; $d <= $days; $d++ ) {
					$key      = sprintf( '%s-%02d', $month, $d );
					$is_today = ( $key === $today );
					$events   = $by_day[ $key ] ?? [];
					echo '<div class="pp-cal__cell' . ( $is_today ? ' pp-cal__cell--today' : '' ) . '">';
					echo '<span class="pp-cal__daynum">' . (int) $d . '</span>';
					foreach ( array_slice( $events, 0, 3 ) as $ev ) {
						$cls = 'pp-cal__chip pp-cal__chip--' . $ev['type'];
						if ( ! empty( $ev['url'] ) ) {
							echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $ev['url'] ) . '" title="' . esc_attr( $ev['label'] ) . '">' . esc_html( $ev['label'] ) . '</a>';
						} else {
							echo '<span class="' . esc_attr( $cls ) . '" title="' . esc_attr( $ev['label'] ) . '">' . esc_html( $ev['label'] ) . '</span>';
						}
					}
					$extra = count( $events ) - 3;
					if ( $extra > 0 ) {
						/* translators: %d: number of additional events on that day. */
						echo '<span class="pp-cal__more">' . esc_html( sprintf( __( '+%d more', 'project-prepper' ), $extra ) ) . '</span>';
					}
					echo '</div>';
				}
				?>
			</div>

			<?php self::calendar_legend(); ?>
		</div>
		<?php
	}

	/** Wochenansicht des Kalenders (7 Spalten, alle Events je Tag, ohne Kürzung). */
	private static function render_calendar_week( WP_User $user, array $groups ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Navigation
		$anchor = isset( $_GET['pp_week'] ) ? sanitize_text_field( wp_unslash( $_GET['pp_week'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $anchor ) ) {
			$anchor = current_time( 'Y-m-d' );
		}
		$anchor_ts  = strtotime( $anchor );
		$dow        = (int) gmdate( 'N', $anchor_ts ); // Mo=1 … So=7
		$monday_ts  = strtotime( '-' . ( $dow - 1 ) . ' day', $anchor_ts );
		$week_start = gmdate( 'Y-m-d', $monday_ts );
		$week_end   = gmdate( 'Y-m-d', strtotime( '+6 day', $monday_ts ) );
		$today      = current_time( 'Y-m-d' );
		$prev       = gmdate( 'Y-m-d', strtotime( '-7 day', $monday_ts ) );
		$next       = gmdate( 'Y-m-d', strtotime( '+7 day', $monday_ts ) );

		$by_day = self::calendar_events( $user, $groups, $week_start, $week_end );
		$base   = self::view_url( 'calendar' );
		$week_base = add_query_arg( 'pp_cal', 'week', $base );
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title"><?php esc_html_e( 'Calendar', 'project-prepper' ); ?></h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Your collectives’ projects, schedule and loans at a glance.', 'project-prepper' ); ?></p>
		</header>

		<div class="pp-cal">
			<div class="pp-cal__bar">
				<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" href="<?php echo esc_url( add_query_arg( 'pp_week', $prev, $week_base ) ); ?>" aria-label="<?php esc_attr_e( 'Previous week', 'project-prepper' ); ?>">‹</a>
				<span class="pp-cal__title"><?php echo esc_html( date_i18n( 'j. M', $monday_ts ) . ' – ' . date_i18n( 'j. M Y', strtotime( '+6 day', $monday_ts ) ) ); ?></span>
				<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" href="<?php echo esc_url( add_query_arg( 'pp_week', $next, $week_base ) ); ?>" aria-label="<?php esc_attr_e( 'Next week', 'project-prepper' ); ?>">›</a>
				<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm pp-cal__today" href="<?php echo esc_url( $week_base ); ?>"><?php esc_html_e( 'Today', 'project-prepper' ); ?></a>
				<span class="pp-cal__modes">
					<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Month', 'project-prepper' ); ?></a>
					<span class="pp-portal__btn pp-portal__btn--sm pp-cal__mode--on"><?php esc_html_e( 'Week', 'project-prepper' ); ?></span>
				</span>
			</div>

			<div class="pp-cal__week">
				<?php
				for ( $i = 0; $i < 7; $i++ ) {
					$day_ts   = strtotime( "+$i day", $monday_ts );
					$key      = gmdate( 'Y-m-d', $day_ts );
					$is_today = ( $key === $today );
					$events   = $by_day[ $key ] ?? [];
					echo '<div class="pp-cal__weekday' . ( $is_today ? ' pp-cal__cell--today' : '' ) . '">';
					echo '<div class="pp-cal__weekday-head">' . esc_html( date_i18n( 'D j.', $day_ts ) ) . '</div>';
					if ( $events ) {
						foreach ( $events as $ev ) {
							$cls = 'pp-cal__chip pp-cal__chip--' . $ev['type'];
							if ( ! empty( $ev['url'] ) ) {
								echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $ev['url'] ) . '" title="' . esc_attr( $ev['label'] ) . '">' . esc_html( $ev['label'] ) . '</a>';
							} else {
								echo '<span class="' . esc_attr( $cls ) . '" title="' . esc_attr( $ev['label'] ) . '">' . esc_html( $ev['label'] ) . '</span>';
							}
						}
					} else {
						echo '<span class="pp-cal__weekday-empty">·</span>';
					}
					echo '</div>';
				}
				?>
			</div>

			<?php self::calendar_legend(); ?>
		</div>
		<?php
	}

	/** Gemeinsame Kalender-Legende (Monat + Woche). */
	private static function calendar_legend(): void {
		?>
		<div class="pp-cal__legend">
			<span class="pp-cal__legend-item"><span class="pp-cal__dot pp-cal__dot--project"></span><?php esc_html_e( 'Project', 'project-prepper' ); ?></span>
			<span class="pp-cal__legend-item"><span class="pp-cal__dot pp-cal__dot--schedule"></span><?php esc_html_e( 'Schedule', 'project-prepper' ); ?></span>
			<span class="pp-cal__legend-item"><span class="pp-cal__dot pp-cal__dot--borrow"></span><?php esc_html_e( 'Loan', 'project-prepper' ); ?></span>
		</div>
		<?php
		// iCal-Feed-Link nur für Betrachter, die ohnehin die Site-Verleihe sehen
		// dürfen (der Feed ist betreiberweit, nicht persönlich) — kein Leak.
		if ( current_user_can( Capabilities::VIEW_RENTALS ) ) {
			$feed = add_query_arg(
				'token',
				CalendarController::token(),
				rest_url( 'project-prepper/v1/calendar.ics' )
			);
			?>
			<p class="pp-cal__feed">
				<a href="<?php echo esc_url( $feed ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Subscribe to the rentals calendar (iCal feed)', 'project-prepper' ); ?></a>
				<span class="pp-muted-inline"><?php esc_html_e( 'read-only · operator-wide rentals', 'project-prepper' ); ?></span>
			</p>
			<?php
		}
	}

	/**
	 * Events des Monats [ms..me] nach Tag (Y-m-d) gruppiert — gruppen-gescoped:
	 * eigene Gruppen-Projekte (+ deren Zeitplan) und eigene Ausleihen. KEINE
	 * site-weiten Verleihe (das ist Admin-Sache).
	 */
	private static function calendar_events( WP_User $user, array $groups, string $ms, string $me ): array {
		$by_day   = [];
		$projects = self::member_projects( $groups );

		foreach ( $projects as $p ) {
			$start = (string) ( $p->date_start ?? '' );
			if ( '' !== $start ) {
				$end = ! empty( $p->date_end ) ? (string) $p->date_end : $start;
				self::cal_span( $by_day, $start, $end, $ms, $me, [
					'type'  => 'project',
					'label' => $p->name,
					'url'   => add_query_arg( [ 'pp_view' => 'projects', 'pp_project' => (int) $p->id ], self::portal_url() ),
				] );
			}
			foreach ( Schedule::for_project( (int) $p->id ) as $s ) {
				$date = (string) ( $s->schedule_date ?? '' );
				if ( '' === $date || $date < $ms || $date > $me ) {
					continue;
				}
				$label = trim( ( ! empty( $s->time_start ) ? substr( (string) $s->time_start, 0, 5 ) . ' ' : '' ) . $s->title );
				$by_day[ $date ][] = [
					'type'  => 'schedule',
					'label' => $label,
					'url'   => add_query_arg( [ 'pp_view' => 'projects', 'pp_project' => (int) $p->id ], self::portal_url() ),
				];
			}
		}

		$borrows     = array_merge(
			Borrowing::my_requests( (int) $user->ID ),
			Borrowing::incoming_requests( (int) $user->ID )
		);
		$lending_url = self::view_url( 'lending' );
		foreach ( $borrows as $b ) {
			if ( ! in_array( $b->status, [ 'requested', 'approved' ], true ) ) {
				continue;
			}
			$start = (string) $b->date_from;
			if ( '' === $start ) {
				continue;
			}
			$end = ! empty( $b->date_to ) ? (string) $b->date_to : $start;
			self::cal_span( $by_day, $start, $end, $ms, $me, [
				'type'  => 'borrow',
				'label' => $b->item_name,
				'url'   => $lending_url,
			] );
		}

		// Eigene externe Verleihe (an Personen außerhalb der Plattform) —
		// reserved/active. Owner = aktueller User (Solo/persönlich), kein Leak
		// fremder/Site-Verleihe.
		foreach ( MemberRentals::for_owner( (int) $user->ID ) as $r ) {
			if ( ! in_array( $r->status, [ 'reserved', 'active' ], true ) ) {
				continue;
			}
			$start = (string) $r->date_from;
			if ( '' === $start ) {
				continue;
			}
			$end = ! empty( $r->date_to ) ? (string) $r->date_to : $start;
			self::cal_span( $by_day, $start, $end, $ms, $me, [
				'type'  => 'borrow',
				'label' => $r->borrower_name,
				'url'   => $lending_url,
			] );
		}

		// Pro Tag stabil sortieren: Projekt, Zeitplan, Verleih.
		$order = [ 'project' => 0, 'schedule' => 1, 'borrow' => 2 ];
		foreach ( $by_day as &$list ) {
			usort( $list, static fn( $a, $b ) => ( $order[ $a['type'] ] ?? 9 ) <=> ( $order[ $b['type'] ] ?? 9 ) );
		}
		unset( $list );

		return $by_day;
	}

	/** Ein (mehrtägiges) Event auf jeden Tag im Schnitt mit [ms..me] legen. */
	private static function cal_span( array &$by_day, string $from, string $to, string $ms, string $me, array $event ): void {
		$start = $from < $ms ? $ms : $from;
		$end   = $to > $me ? $me : $to;
		if ( $start > $end ) {
			return;
		}
		for ( $t = strtotime( $start ); $t <= strtotime( $end ); $t = strtotime( '+1 day', $t ) ) {
			$by_day[ gmdate( 'Y-m-d', $t ) ][] = $event;
		}
	}

	/* ---------- Netzwerk (Föderation Slice 3, read-only) ---------- */

	private static function view_network(): void {
		$partners   = Federation::partners();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reiner Filter
		$q          = isset( $_GET['pp_q'] ) ? sanitize_text_field( wp_unslash( $_GET['pp_q'] ) ) : '';
		$conditions = Shortcodes::condition_labels();
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title"><?php esc_html_e( 'Network', 'project-prepper' ); ?></h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Shared inventory from connected partner instances.', 'project-prepper' ); ?></p>
		</header>

		<?php self::render_my_fed_requests(); ?>

		<?php if ( ! $partners ) : ?>
			<p class="pp-portal__empty"><?php esc_html_e( 'No partner instances are connected yet. Ask the platform operators to add other instances on the Federation page.', 'project-prepper' ); ?></p>
			<?php
			return;
		endif;
		?>

		<form class="pp-net-search" method="get">
			<input type="hidden" name="pp_view" value="network">
			<input type="search" name="pp_q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Filter by item, postal code or topic …', 'project-prepper' ); ?>">
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Filter', 'project-prepper' ); ?></button>
		</form>

		<?php
		$needle    = mb_strtolower( $q );
		$any_shown = false;
		foreach ( $partners as $url ) {
			$catalog = Federation::partner_catalog( $url );
			if ( null === $catalog ) {
				?>
				<div class="pp-net-inst pp-net-inst--down">
					<span class="pp-net-inst__name"><?php echo esc_html( self::pretty_host( $url ) ); ?></span>
					<span class="pp-net-inst__meta"><?php esc_html_e( 'not reachable', 'project-prepper' ); ?></span>
				</div>
				<?php
				continue;
			}
			$profile = (array) $catalog['profile'];
			$items   = (array) $catalog['items'];

			// Filter: passt die Instanz selbst (Name/PLZ/Thema), zeigen wir ihren
			// ganzen Katalog; sonst nur die namentlich passenden Artikel — und die
			// Instanz nur, wenn überhaupt ein Artikel übrig bleibt.
			if ( '' !== $needle ) {
				$inst_hay = mb_strtolower( ( $profile['name'] ?? '' ) . ' ' . ( $profile['postal_code'] ?? '' ) . ' ' . ( $profile['topic'] ?? '' ) );
				if ( false === mb_strpos( $inst_hay, $needle ) ) {
					$items = array_values( array_filter( $items, static fn( $it ) => false !== mb_strpos( mb_strtolower( (string) ( $it['name'] ?? '' ) ), $needle ) ) );
					if ( ! $items ) {
						continue;
					}
				}
			}
			$any_shown = true;
			$inst_url  = (string) ( $profile['url'] ?? $url );
			?>
			<section class="pp-net-inst">
				<div class="pp-net-inst__head">
					<a class="pp-net-inst__name" href="<?php echo esc_url( $inst_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $profile['name'] ?? self::pretty_host( $url ) ); ?></a>
					<span class="pp-net-inst__meta"><?php echo esc_html( implode( ' · ', array_filter( [ (string) ( $profile['postal_code'] ?? '' ), (string) ( $profile['topic'] ?? '' ) ] ) ) ); ?></span>
					<span class="pp-net-inst__count">
						<?php
						/* translators: %d: number of shared items. */
						printf( esc_html( _n( '%d item', '%d items', count( $items ), 'project-prepper' ) ), count( $items ) );
						?>
					</span>
				</div>
				<?php if ( $items ) : ?>
					<div class="pp-front-grid">
						<?php foreach ( $items as $it ) :
							$cond = (string) ( $it['condition'] ?? '' );
							$rate = $it['cost_per_day'] ?? null; ?>
							<div class="pp-front-card pp-net-item">
								<a class="pp-net-item__link" href="<?php echo esc_url( (string) ( $it['detail_url'] ?? $inst_url ) ); ?>" target="_blank" rel="noopener">
									<div class="pp-front-card-media">
										<?php if ( ! empty( $it['image_url'] ) ) : ?>
											<img src="<?php echo esc_url( (string) $it['image_url'] ); ?>" alt="">
										<?php else : ?>
											<span class="pp-front-card-icon"><?php echo esc_html( (string) ( $it['category_icon'] ?? '📦' ) ); ?></span>
										<?php endif; ?>
									</div>
									<div class="pp-front-card-body">
										<h4 class="pp-front-card-title"><?php echo esc_html( (string) ( $it['name'] ?? '' ) ); ?></h4>
										<div class="pp-front-card-meta">
											<?php if ( '' !== $cond ) : ?>
												<span class="pp-front-chip pp-front-chip-<?php echo esc_attr( $cond ); ?>"><?php echo esc_html( $conditions[ $cond ] ?? $cond ); ?></span>
											<?php endif; ?>
											<span class="pp-front-chip">×<?php echo (int) ( $it['quantity'] ?? 1 ); ?></span>
										</div>
										<?php if ( null !== $rate && '' !== $rate ) : ?>
											<div class="pp-front-card-rate"><?php echo esc_html( number_format_i18n( (float) $rate, 2 ) ); ?> €<span> / <?php esc_html_e( 'day', 'project-prepper' ); ?></span></div>
										<?php endif; ?>
									</div>
								</a>
								<?php if ( ! empty( $it['id'] ) ) : ?>
									<details class="pp-net-item__ask">
										<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Ask to borrow', 'project-prepper' ); ?></summary>
										<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<?php self::action_fields( 'fed_request' ); ?>
											<input type="hidden" name="pp_partner" value="<?php echo esc_attr( (string) $url ); ?>">
											<input type="hidden" name="pp_item" value="<?php echo (int) $it['id']; ?>">
											<input type="hidden" name="pp_item_label" value="<?php echo esc_attr( (string) ( $it['name'] ?? '' ) ); ?>">
											<input type="hidden" name="pp_detail_url" value="<?php echo esc_attr( (string) ( $it['detail_url'] ?? '' ) ); ?>">
											<label><?php esc_html_e( 'From', 'project-prepper' ); ?>
												<input type="date" name="pp_from">
											</label>
											<label><?php esc_html_e( 'To', 'project-prepper' ); ?>
												<input type="date" name="pp_to">
											</label>
											<label><?php esc_html_e( 'Message (optional)', 'project-prepper' ); ?>
												<textarea name="pp_message" rows="2"></textarea>
											</label>
											<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Send request', 'project-prepper' ); ?></button>
										</form>
									</details>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="pp-net-inst__empty"><?php esc_html_e( 'No shared items.', 'project-prepper' ); ?></p>
				<?php endif; ?>
			</section>
			<?php
		}

		if ( ! $any_shown && '' !== $needle ) {
			echo '<p class="pp-portal__empty">' . esc_html__( 'Nothing matched your filter.', 'project-prepper' ) . '</p>';
		}
		?>
		<p class="pp-net-note"><?php esc_html_e( 'Borrowing across instances isn’t available yet — open an item to view it on the partner instance.', 'project-prepper' ); ?></p>
		<?php
	}

	/** Host einer URL für die Anzeige (ohne Schema/Pfad). */
	private static function pretty_host( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $host ? (string) $host : $url;
	}

	/** Eigene ausgehende Netzwerk-Leih-Anfragen mit (gedrosselt gepolltem) Status. */
	private static function render_my_fed_requests(): void {
		$requests = FederatedBorrow::my_outbound( get_current_user_id() );
		if ( ! $requests ) {
			return;
		}
		?>
		<section class="pp-app__section">
			<h2 class="pp-portal__subtitle"><?php esc_html_e( 'My network requests', 'project-prepper' ); ?></h2>
			<?php foreach ( $requests as $r ) : ?>
				<div class="pp-portal__invite">
					<?php if ( ! empty( $r->item_detail_url ) ) : ?>
						<a class="pp-portal__group-name" href="<?php echo esc_url( $r->item_detail_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $r->item_label ); ?></a>
					<?php else : ?>
						<span class="pp-portal__group-name"><?php echo esc_html( $r->item_label ); ?></span>
					<?php endif; ?>
					<span class="pp-portal__item-meta"><?php echo esc_html( self::fmt_range( $r->date_from, $r->date_to ) . ' · ' . self::pretty_host( (string) $r->partner_url ) ); ?></span>
					<span class="pp-portal__tag <?php echo esc_attr( self::borrow_status_class( $r->status ) ); ?>"><?php echo esc_html( self::borrow_status_label( $r->status ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/* ---------- Render-Bausteine ---------- */

	/** Hidden-Felder + Nonce für eine Kollektiv-Aktion. */
	private static function action_fields( string $do ): void {
		echo '<input type="hidden" name="action" value="pp_collective">';
		echo '<input type="hidden" name="pp_do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( 'pp_collective', 'pp_nonce' );
	}

	private static function render_message(): void {
		// phpcs:ignore WordPress.Security.NonceVerification -- reine Anzeige eines Status-Codes
		$code = isset( $_GET['pp_msg'] ) ? sanitize_key( wp_unslash( $_GET['pp_msg'] ) ) : '';
		if ( '' === $code ) {
			return;
		}
		// Import-Ergebnis mit Anzahl (pp_n).
		if ( 'imported' === $code ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige
			$n = isset( $_GET['pp_n'] ) ? (int) $_GET['pp_n'] : 0;
			printf(
				'<div class="pp-portal__notice pp-portal__notice--ok">%s</div>',
				/* translators: %d: number of imported items. */
				esc_html( sprintf( _n( '%d item imported.', '%d items imported.', $n, 'project-prepper' ), $n ) )
			);
			return;
		}
		$map = self::messages();
		if ( ! isset( $map[ $code ] ) ) {
			return;
		}
		[ $kind, $text ] = $map[ $code ];
		printf(
			'<div class="pp-portal__notice pp-portal__notice--%s">%s</div>',
			esc_attr( $kind ),
			esc_html( $text )
		);
	}

	/** Offene Einladungen an den aktuellen User (annehmen/ablehnen). */
	private static function render_my_invitations( WP_User $user ): void {
		$invitations = Governance::my_pending_invitations( (int) $user->ID );
		if ( ! $invitations ) {
			return;
		}
		?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php esc_html_e( 'Your invitations', 'project-prepper' ); ?></h3>
			<?php foreach ( $invitations as $inv ) : ?>
				<div class="pp-portal__invite">
					<span class="pp-portal__group-name"><?php echo esc_html( $inv->group_name ); ?></span>
					<?php if ( 'pending' === $inv->status ) : ?>
						<div class="pp-portal__actions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php self::action_fields( 'accept' ); ?>
								<input type="hidden" name="pp_invitation" value="<?php echo (int) $inv->id; ?>">
								<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Accept', 'project-prepper' ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php self::action_fields( 'decline' ); ?>
								<input type="hidden" name="pp_invitation" value="<?php echo (int) $inv->id; ?>">
								<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Decline', 'project-prepper' ); ?></button>
							</form>
						</div>
					<?php else : /* voting */ ?>
						<span class="pp-portal__tag pp-portal__tag--muted"><?php esc_html_e( 'Being voted on by the collective', 'project-prepper' ); ?></span>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php self::action_fields( 'decline' ); ?>
							<input type="hidden" name="pp_invitation" value="<?php echo (int) $inv->id; ?>">
							<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Withdraw', 'project-prepper' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/** Eine Kollektiv-Karte: Mitglieder + Einladen + offene Beitritts-Abstimmungen. */
	private static function render_collective( int $group_id, string $name, string $role, int $user_id ): void {
		$members     = Groups::members( $group_id );
		$invitations = Governance::invitations_for_group( $group_id, [ 'pending', 'voting' ] );
		// Austreten erlaubt für Mitglieder; ein Gründer nur, wenn ein weiterer Gründer bleibt.
		$founder_count = 0;
		foreach ( $members as $m ) {
			if ( 'founder' === $m->member_role ) {
				$founder_count++;
			}
		}
		$can_leave = ( 'founder' !== $role ) || $founder_count > 1;
		?>
		<div class="pp-portal__collective">
			<div class="pp-portal__collective-head">
				<span class="pp-portal__group-name"><?php echo esc_html( $name ); ?></span>
				<?php if ( 'founder' === $role ) : ?>
					<span class="pp-portal__tag"><?php esc_html_e( 'Founder', 'project-prepper' ); ?></span>
				<?php else : ?>
					<span class="pp-portal__tag pp-portal__tag--muted"><?php esc_html_e( 'Member', 'project-prepper' ); ?></span>
				<?php endif; ?>
			</div>

			<div class="pp-portal__memberlist">
				<?php
				/* translators: %d: number of members. */
				echo '<h4 class="pp-portal__memberhead">' . esc_html( sprintf( _n( '%d member', '%d members', count( $members ), 'project-prepper' ), count( $members ) ) ) . '</h4>';
				foreach ( $members as $m ) :
					$is_founder = ( 'founder' === $m->member_role );
					$is_self    = ( (int) $m->user_id === $user_id );
					$joined     = self::fmt_date( $m->joined_at );
					?>
					<div class="pp-portal__member">
						<span class="pp-portal__member-name">
							<?php echo esc_html( $m->display_name ); ?>
							<?php if ( $is_self ) : ?>
								<span class="pp-portal__member-you"><?php esc_html_e( '(you)', 'project-prepper' ); ?></span>
							<?php endif; ?>
						</span>
						<span class="pp-portal__member-meta">
							<span class="pp-portal__tag<?php echo $is_founder ? '' : ' pp-portal__tag--muted'; ?>"><?php echo esc_html( $is_founder ? __( 'Founder', 'project-prepper' ) : __( 'Member', 'project-prepper' ) ); ?></span>
							<?php if ( '' !== $joined ) : ?>
								<span class="pp-portal__member-joined">
									<?php
									/* translators: %s: join date. */
									printf( esc_html__( 'since %s', 'project-prepper' ), esc_html( $joined ) );
									?>
								</span>
							<?php endif; ?>
						</span>
					</div>
				<?php endforeach; ?>
			</div>

			<?php foreach ( $invitations as $inv ) :
				$is_invitee = ( (int) $inv->invited_user_id === $user_id ); ?>
				<div class="pp-portal__vote">
					<span class="pp-portal__vote-email"><?php echo esc_html( $inv->invited_email ); ?></span>
					<?php if ( 'pending' === $inv->status ) : ?>
						<span class="pp-portal__tag pp-portal__tag--muted"><?php esc_html_e( 'Waiting for acceptance', 'project-prepper' ); ?></span>
					<?php else : /* voting */ ?>
						<span class="pp-portal__tag pp-portal__tag--muted">
							<?php
							/* translators: 1: approvals, 2: members needed. */
							printf( esc_html__( '%1$d / %2$d approvals', 'project-prepper' ), (int) $inv->approvals, (int) $inv->needed );
							?>
						</span>
						<?php if ( ! $is_invitee ) : ?>
							<?php if ( $inv->my_vote ) : ?>
								<span class="pp-portal__tag"><?php echo esc_html( self::vote_label( $inv->my_vote ) ); ?></span>
							<?php endif; ?>
							<div class="pp-portal__actions">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php self::action_fields( 'vote' ); ?>
									<input type="hidden" name="pp_invitation" value="<?php echo (int) $inv->id; ?>">
									<input type="hidden" name="pp_vote" value="approve">
									<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Approve', 'project-prepper' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php self::action_fields( 'vote' ); ?>
									<input type="hidden" name="pp_invitation" value="<?php echo (int) $inv->id; ?>">
									<input type="hidden" name="pp_vote" value="reject">
									<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Reject', 'project-prepper' ); ?></button>
								</form>
							</div>
						<?php endif; ?>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php self::action_fields( 'cancel' ); ?>
						<input type="hidden" name="pp_invitation" value="<?php echo (int) $inv->id; ?>">
						<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Cancel', 'project-prepper' ); ?></button>
					</form>
				</div>
			<?php endforeach; ?>

			<form class="pp-portal__form pp-portal__form--inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php self::action_fields( 'invite' ); ?>
				<input type="hidden" name="pp_group" value="<?php echo (int) $group_id; ?>">
				<input type="email" name="pp_email" placeholder="<?php esc_attr_e( 'Invite by email', 'project-prepper' ); ?>" required>
				<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Invite', 'project-prepper' ); ?></button>
			</form>

			<?php if ( $can_leave ) : ?>
				<div class="pp-portal__collective-foot">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php echo esc_js( __( 'Leave this group? You will lose access to its shared inventory and projects.', 'project-prepper' ) ); ?>');">
						<?php self::action_fields( 'group_leave' ); ?>
						<input type="hidden" name="pp_group" value="<?php echo (int) $group_id; ?>">
						<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm pp-portal__btn--danger"><?php esc_html_e( 'Leave group', 'project-prepper' ); ?></button>
					</form>
				</div>
			<?php elseif ( 'founder' === $role ) : ?>
				<p class="pp-portal__hint"><?php esc_html_e( 'You are the only founder. Appoint another founder before you can leave.', 'project-prepper' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function vote_label( string $vote ): string {
		$map = [
			'approve' => __( 'You approved', 'project-prepper' ),
			'reject'  => __( 'You rejected', 'project-prepper' ),
			'abstain' => __( 'You abstained', 'project-prepper' ),
		];
		return $map[ $vote ] ?? '';
	}

	/* ---------- Mein Inventar (Phase 3) ---------- */

	/** @param array<object> $groups Kollektive des Users (id, name, member_role). */
	private static function render_my_inventory( WP_User $user, array $groups, bool $heading = true ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Suche/Navigation
		$q         = isset( $_GET['pp_q'] ) ? sanitize_text_field( wp_unslash( $_GET['pp_q'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Navigation
		$cat       = isset( $_GET['pp_cat'] ) ? (int) $_GET['pp_cat'] : 0;
		$all_items = MemberInventory::my_items( (int) $user->ID, $q );
		// KPI + Kategorie-Zählung über alle (such-gefilterten) Artikel.
		$total_pieces = 0;
		$total_value  = 0.0;
		$cat_counts   = [];
		$cat_labels   = [];
		foreach ( $all_items as $it ) {
			$total_pieces += (int) $it->quantity;
			$total_value  += (float) $it->cost_per_day * (int) $it->quantity;
			$cid = (int) ( $it->category_id ?? 0 );
			$cat_counts[ $cid ] = ( $cat_counts[ $cid ] ?? 0 ) + 1;
			if ( $cid && ! isset( $cat_labels[ $cid ] ) ) {
				$cat_labels[ $cid ] = trim( ( $it->category_icon ? $it->category_icon . ' ' : '' ) . (string) ( $it->category_name ?? '' ) );
			}
		}
		$shown_items = $cat ? array_values( array_filter( $all_items, static function ( $it ) use ( $cat ) {
			return (int) ( $it->category_id ?? 0 ) === $cat;
		} ) ) : $all_items;
		$per_page  = 12;
		$total     = count( $shown_items );
		$pages     = max( 1, (int) ceil( $total / $per_page ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Navigation
		$page      = isset( $_GET['pp_p'] ) ? max( 1, min( $pages, (int) $_GET['pp_p'] ) ) : 1;
		$items     = array_slice( $shown_items, ( $page - 1 ) * $per_page, $per_page );
		$base_url  = self::portal_url();
		$own_cats   = MemberInventory::own_categories( (int) $user->ID );
		$tpl_cats   = MemberInventory::template_categories();
		$categories = [ 'own' => $own_cats, 'templates' => $tpl_cats ];
		$conditions = Shortcodes::condition_labels();
		?>
		<section class="pp-portal__section">
			<?php if ( $heading ) : ?>
				<h3 class="pp-portal__subtitle"><?php esc_html_e( 'My inventory', 'project-prepper' ); ?></h3>
			<?php endif; ?>

			<?php self::render_inventory_tools( $categories, $conditions, $own_cats, $tpl_cats, $user, $groups ); ?>

			<?php if ( $all_items || '' !== $q ) : ?>
				<form class="pp-inv-search" method="get">
					<input type="hidden" name="pp_view" value="inventory">
					<input type="search" name="pp_q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Search your inventory …', 'project-prepper' ); ?>">
					<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Search', 'project-prepper' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( $all_items ) : ?>
				<p class="pp-inv-kpi">
					<?php
					/* translators: 1: item count, 2: total pieces. */
					$pp_dv = ( (float) $total_value === floor( (float) $total_value ) ) ? number_format_i18n( $total_value, 0 ) : number_format_i18n( $total_value, 2 );
					printf( esc_html__( '%1$d items · %2$d pieces · daily value %3$s €', 'project-prepper' ), count( $all_items ), (int) $total_pieces, esc_html( $pp_dv ) );
					?>
				</p>
				<div class="pp-inv-pills">
					<a class="pp-portal__chip <?php echo $cat ? '' : 'pp-portal__chip--on'; ?>" href="<?php echo esc_url( add_query_arg( array_filter( [ 'pp_view' => 'inventory', 'pp_q' => $q ] ), $base_url ) ); ?>"><?php esc_html_e( 'All', 'project-prepper' ); ?> (<?php echo (int) count( $all_items ); ?>)</a>
					<?php foreach ( $cat_labels as $cid => $label ) : ?>
						<a class="pp-portal__chip <?php echo $cat === (int) $cid ? 'pp-portal__chip--on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_filter( [ 'pp_view' => 'inventory', 'pp_q' => $q, 'pp_cat' => (int) $cid ] ), $base_url ) ); ?>"><?php echo esc_html( $label ); ?> (<?php echo (int) $cat_counts[ $cid ]; ?>)</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $items ) : ?>
				<div class="pp-inv-row pp-inv-row--head">
					<span class="pp-col pp-col--name"><?php esc_html_e( 'Item', 'project-prepper' ); ?></span>
					<span class="pp-col pp-col--cat"><?php esc_html_e( 'Category', 'project-prepper' ); ?></span>
					<span class="pp-col pp-col--c"><?php esc_html_e( 'Quantity', 'project-prepper' ); ?></span>
					<span class="pp-col pp-col--c"><?php esc_html_e( 'Available', 'project-prepper' ); ?></span>
					<span class="pp-col pp-col--cond"><?php esc_html_e( 'Condition', 'project-prepper' ); ?></span>
					<span class="pp-col pp-col--r">€/<?php echo esc_html__( 'day', 'project-prepper' ); ?></span>
					<span class="pp-col pp-col--shared"><?php esc_html_e( 'Shared', 'project-prepper' ); ?></span>
					<span class="pp-col pp-col--loc"><?php esc_html_e( 'Location', 'project-prepper' ); ?></span>
					<span class="pp-col pp-col--manage"></span>
				</div>
				<?php foreach ( $items as $item ) : ?>
					<?php $shared = $groups ? MemberInventory::shared_group_ids( (int) $item->id ) : []; $shared_names = []; foreach ( $groups as $pp_g ) { if ( in_array( (int) $pp_g->id, $shared, true ) ) { $shared_names[] = $pp_g->name; } } ?>
					<div class="pp-portal__item pp-portal__item--row">
						<div class="pp-inv-row pp-portal__item-head pp-inv-row--click" role="button" tabindex="0" data-pp-modal="pp-item-<?php echo (int) $item->id; ?>">
							<span class="pp-col pp-col--name">
								<?php if ( ! empty( $item->image_url ) ) : ?><img class="pp-portal__item-thumb" src="<?php echo esc_url( $item->image_url ); ?>" alt="" loading="lazy"><?php else : ?><span class="pp-portal__item-thumb pp-portal__item-thumb--empty" aria-hidden="true"></span><?php endif; ?>
								<span class="pp-inv-name-wrap"><span class="pp-inv-name-top"><span class="pp-portal__group-name"><?php echo esc_html( $item->name ); ?></span> <small class="pp-portal__item-num"><?php echo esc_html( $item->inventory_number ); ?></small></span><?php $pp_sub = $item->model ?: ( $item->description ?? '' ); if ( '' !== trim( (string) $pp_sub ) ) : ?><small class="pp-inv-name-sub"><?php echo esc_html( (string) $pp_sub ); ?></small><?php endif; ?></span>
							</span>
							<span class="pp-col pp-col--cat" data-label="<?php esc_attr_e( 'Category', 'project-prepper' ); ?>"><?php echo $item->category_name ? esc_html( trim( ( $item->category_icon ? $item->category_icon . ' ' : '' ) . (string) $item->category_name ) ) : '—'; ?></span>
							<span class="pp-col pp-col--c" data-label="<?php esc_attr_e( 'Quantity', 'project-prepper' ); ?>"><?php echo (int) $item->quantity; ?></span>
							<span class="pp-col pp-col--c" data-label="<?php esc_attr_e( 'Available', 'project-prepper' ); ?>"><?php echo (int) max( 0, (int) $item->quantity - (int) ( $item->out_now ?? 0 ) ); ?></span>
							<span class="pp-col pp-col--cond" data-label="<?php esc_attr_e( 'Condition', 'project-prepper' ); ?>"><?php echo esc_html( $conditions[ $item->condition ] ?? $item->condition ); ?></span>
							<span class="pp-col pp-col--r" data-label="€/<?php echo esc_attr__( 'day', 'project-prepper' ); ?>"><?php echo ( null !== $item->cost_per_day && '' !== $item->cost_per_day ) ? esc_html( number_format_i18n( (float) $item->cost_per_day, 2 ) . ' €' ) : '—'; ?></span>
							<span class="pp-col pp-col--shared" data-label="<?php esc_attr_e( 'Shared', 'project-prepper' ); ?>"><?php echo $shared_names ? esc_html( implode( ', ', $shared_names ) ) : '<span class="pp-muted">' . esc_html__( 'Not shared', 'project-prepper' ) . '</span>'; ?></span>
							<span class="pp-col pp-col--loc" data-label="<?php esc_attr_e( 'Location', 'project-prepper' ); ?>"><?php echo ! empty( $item->location ) ? esc_html( (string) $item->location ) : '—'; ?></span>
							<span class="pp-col pp-col--manage"><span class="pp-manage-btn"><?php esc_html_e( 'Manage', 'project-prepper' ); ?></span></span>
						</div>

						<dialog class="pp-modal pp-modal--portal" id="pp-item-<?php echo (int) $item->id; ?>">
						<div class="pp-modal-header">
							<h2 class="pp-modal__title"><?php echo esc_html( $item->name ); ?> <small class="pp-portal__item-num"><?php echo esc_html( $item->inventory_number ); ?></small></h2>
							<button type="button" class="pp-modal-close" data-pp-modal-close aria-label="<?php esc_attr_e( 'Close', 'project-prepper' ); ?>">✕</button>
						</div>
						<div class="pp-modal-body">
							<div class="pp-modal-photo">
								<?php if ( ! empty( $item->image_url ) ) : ?>
									<img class="pp-modal-photo__img" src="<?php echo esc_url( $item->image_url ); ?>" alt="">
								<?php endif; ?>
								<form class="pp-modal-photo__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="pp_member_photo">
									<?php wp_nonce_field( 'pp_member_photo', 'pp_nonce' ); ?>
									<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
									<input type="file" name="pp_photo" accept="image/*" required>
									<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save photo', 'project-prepper' ); ?></button>
								</form>
								<?php if ( ! empty( $item->image_url ) ) : ?>
									<form class="pp-modal-photo__remove" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="pp_member_photo">
										<?php wp_nonce_field( 'pp_member_photo', 'pp_nonce' ); ?>
										<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
										<input type="hidden" name="pp_remove" value="1">
										<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Remove photo', 'project-prepper' ); ?></button>
									</form>
								<?php endif; ?>
							</div>
							<?php self::item_form( 'item_update', $categories, $conditions, $item ); ?>
						<?php if ( $groups ) :
							$pp_share_cfg = MemberInventory::share_settings( (int) $item->id );
							$pp_presets   = MemberInventory::condition_presets(); ?>
							<div class="pp-share">
								<h4 class="pp-share__title"><?php esc_html_e( 'Share with your collectives', 'project-prepper' ); ?></h4>
								<?php foreach ( $groups as $g ) :
									$pp_cfg = $pp_share_cfg[ (int) $g->id ] ?? null; ?>
									<form class="pp-share__group" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php self::action_fields( 'item_share_set' ); ?>
										<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
										<input type="hidden" name="pp_group" value="<?php echo (int) $g->id; ?>">
										<label class="pp-share__head">
											<input type="checkbox" name="pp_shared" value="1" data-pp-share-toggle <?php checked( null !== $pp_cfg ); ?>>
											<span class="pp-share__name"><?php echo esc_html( $g->name ); ?></span>
										</label>
										<div class="pp-share__body">
											<div class="pp-share__fields">
												<label class="pp-share__rate"><?php esc_html_e( 'Daily rate (€)', 'project-prepper' ); ?>
													<input type="number" step="0.01" min="0" name="pp_rate" value="<?php echo ( $pp_cfg && null !== $pp_cfg->daily_rate ) ? esc_attr( number_format( (float) $pp_cfg->daily_rate, 2, '.', '' ) ) : ''; ?>">
												</label>
												<label class="pp-share__approval"><input type="checkbox" name="pp_approval" value="1" <?php checked( $pp_cfg && $pp_cfg->requires_approval ); ?>> <?php esc_html_e( 'Requires approval', 'project-prepper' ); ?></label>
											</div>
											<div class="pp-share__conds">
												<?php foreach ( $pp_presets as $pp_key => $pp_label ) :
													$pp_on = $pp_cfg && in_array( $pp_key, (array) $pp_cfg->conditions_tags, true ); ?>
													<label class="pp-portal__chip"><input type="checkbox" name="pp_cond[]" value="<?php echo esc_attr( $pp_key ); ?>" <?php checked( $pp_on ); ?> hidden><?php echo esc_html( $pp_label ); ?></label>
												<?php endforeach; ?>
											</div>
											<label class="pp-share__notes"><?php esc_html_e( 'Notes (optional)', 'project-prepper' ); ?>
												<textarea name="pp_conditions" rows="2"><?php echo esc_textarea( $pp_cfg->conditions ?? '' ); ?></textarea>
											</label>
										</div>
										<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save sharing', 'project-prepper' ); ?></button>
									</form>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
							<details class="pp-modal-section">
								<summary class="pp-modal-section__head"><?php esc_html_e( 'Documents', 'project-prepper' ); ?><?php if ( ! empty( $item->documents ) ) : ?> (<?php echo (int) count( $item->documents ); ?>)<?php endif; ?></summary>
								<?php if ( ! empty( $item->documents ) ) : ?>
									<ul class="pp-portal__docs">
										<?php foreach ( $item->documents as $doc ) : ?>
											<li class="pp-portal__doc">
												<a href="<?php echo esc_url( $doc['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $doc['title'] ?: __( 'Document', 'project-prepper' ) ); ?></a>
												<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
													<input type="hidden" name="action" value="pp_member_doc">
													<?php wp_nonce_field( 'pp_member_doc', 'pp_nonce' ); ?>
													<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
													<input type="hidden" name="pp_doc" value="<?php echo (int) $doc['id']; ?>">
													<input type="hidden" name="pp_remove" value="1">
													<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Remove', 'project-prepper' ); ?></button>
												</form>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
								<form class="pp-portal__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="pp_member_doc">
									<?php wp_nonce_field( 'pp_member_doc', 'pp_nonce' ); ?>
									<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
									<label><?php esc_html_e( 'PDF or image', 'project-prepper' ); ?>
										<input type="file" name="pp_doc" accept="application/pdf,image/*" required>
									</label>
									<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Upload document', 'project-prepper' ); ?></button>
								</form>
							</details>
						</div>
						<div class="pp-modal-footer">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this item?', 'project-prepper' ) ); ?>');">
								<?php self::action_fields( 'item_delete' ); ?>
								<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
								<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm pp-modal-footer__del"><?php esc_html_e( 'Delete', 'project-prepper' ); ?></button>
							</form>
							<button type="button" class="pp-portal__btn pp-portal__btn--sm" data-pp-modal-close><?php esc_html_e( 'Close', 'project-prepper' ); ?></button>
						</div>
						</dialog>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<?php if ( '' !== $q ) : ?>
					<p class="pp-portal__empty"><?php esc_html_e( 'No items match your search.', 'project-prepper' ); ?></p>
				<?php else : ?>
					<p class="pp-portal__empty"><?php esc_html_e( 'You have no personal inventory yet. Add your first item below.', 'project-prepper' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $pages > 1 ) : ?>
				<div class="pp-pagination">
					<?php if ( $page > 1 ) : ?>
						<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" href="<?php echo esc_url( self::inventory_page_url( $q, $page - 1 ) ); ?>">‹</a>
					<?php endif; ?>
					<span class="pp-pagination__info"><?php /* translators: 1: current page, 2: total pages. */ printf( esc_html__( 'Page %1$d of %2$d', 'project-prepper' ), (int) $page, (int) $pages ); ?></span>
					<?php if ( $page < $pages ) : ?>
						<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" href="<?php echo esc_url( self::inventory_page_url( $q, $page + 1 ) ); ?>">›</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>

		</section>
		<?php
	}

	/**
	 * „Meine Kategorien" — eigene Kategorien anlegen/löschen + Betreiber-Vorlagen
	 * übernehmen (docs/06 §10.3).
	 *
	 * @param array<object> $own_cats
	 * @param array<object> $tpl_cats
	 */
	private static function render_my_categories( array $own_cats, array $tpl_cats ): void {
		// Bereits übernommene Vorlagen (per Name) nicht erneut zum Übernehmen anbieten.
		$own_names = array_map( static fn( $c ) => $c->name, $own_cats );
		$available = array_filter( $tpl_cats, static fn( $t ) => ! in_array( $t->name, $own_names, true ) );
		?>
		<details class="pp-portal__add">
			<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'My categories', 'project-prepper' ); ?></summary>
			<div class="pp-portal__cats">
				<p class="pp-portal__hint"><?php esc_html_e( 'Organise your inventory with your own categories. Adopt one of the operator’s suggested templates or create your own.', 'project-prepper' ); ?></p>

				<?php if ( $own_cats ) : ?>
					<ul class="pp-portal__cat-list">
						<?php foreach ( $own_cats as $cat ) : ?>
							<li class="pp-portal__cat">
								<span><?php echo esc_html( trim( ( $cat->icon ? $cat->icon . ' ' : '' ) . $cat->name ) ); ?><?php if ( $cat->prefix ) : ?> <code><?php echo esc_html( $cat->prefix ); ?></code><?php endif; ?></span>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this category? Items keep their data but lose this category.', 'project-prepper' ) ); ?>');">
									<?php self::action_fields( 'category_delete' ); ?>
									<input type="hidden" name="pp_category_id" value="<?php echo (int) $cat->id; ?>">
									<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Delete', 'project-prepper' ); ?></button>
								</form>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="pp-portal__empty"><?php esc_html_e( 'You have no own categories yet.', 'project-prepper' ); ?></p>
				<?php endif; ?>

				<?php if ( $available ) : ?>
					<h4 class="pp-portal__cat-sub"><?php esc_html_e( 'Suggested templates', 'project-prepper' ); ?></h4>
					<div class="pp-portal__cat-templates">
						<?php foreach ( $available as $tpl ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php self::action_fields( 'category_adopt' ); ?>
								<input type="hidden" name="pp_template" value="<?php echo (int) $tpl->id; ?>">
								<button type="submit" class="pp-portal__chip">
									<?php echo esc_html( trim( ( $tpl->icon ? $tpl->icon . ' ' : '' ) . $tpl->name ) ); ?> +
								</button>
							</form>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php self::action_fields( 'category_create' ); ?>
					<label><?php esc_html_e( 'New category name', 'project-prepper' ); ?>
						<input type="text" name="pp_cat_name" required>
					</label>
					<label><?php esc_html_e( 'Icon (emoji, optional)', 'project-prepper' ); ?>
						<input type="text" name="pp_cat_icon" maxlength="8">
					</label>
					<label><?php esc_html_e( 'Number prefix (optional)', 'project-prepper' ); ?>
						<input type="text" name="pp_cat_prefix" maxlength="10">
					</label>
					<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Create category', 'project-prepper' ); ?></button>
				</form>
			</div>
		</details>
		<?php
	}

	/** Eingaben des Kategorie-Formulars einsammeln. */
	private static function category_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		return [
			'name'   => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_cat_name'] ?? '' ) ) ),
			'icon'   => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_cat_icon'] ?? '' ) ) ),
			'prefix' => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_cat_prefix'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Formular zum Anlegen/Bearbeiten eines eigenen Items.
	 *
	 * @param array{own:array<object>,templates:array<object>} $categories
	 * @param array<string,string> $conditions
	 */
	private static function item_form( string $do, array $categories, array $conditions, ?object $item ): void {
		$val      = static fn( string $field, $default = '' ) => $item && isset( $item->$field ) ? $item->$field : $default;
		$selected = (int) $val( 'category_id', 0 );
		?>
		<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::action_fields( $do ); ?>
			<?php if ( $item ) : ?>
				<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
			<?php endif; ?>
			<label><?php esc_html_e( 'Name', 'project-prepper' ); ?>
				<input type="text" name="pp_name" value="<?php echo esc_attr( (string) $val( 'name' ) ); ?>" required>
			</label>
			<label><?php esc_html_e( 'Category', 'project-prepper' ); ?>
				<select name="pp_category">
					<option value="0"><?php esc_html_e( '— none —', 'project-prepper' ); ?></option>
					<?php if ( ! empty( $categories['own'] ) ) : ?>
						<optgroup label="<?php esc_attr_e( 'My categories', 'project-prepper' ); ?>">
							<?php foreach ( $categories['own'] as $cat ) : ?>
								<option value="<?php echo (int) $cat->id; ?>" <?php selected( $selected, (int) $cat->id ); ?>><?php echo esc_html( $cat->name ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endif; ?>
					<?php if ( ! empty( $categories['templates'] ) ) : ?>
						<optgroup label="<?php esc_attr_e( 'Templates', 'project-prepper' ); ?>">
							<?php foreach ( $categories['templates'] as $cat ) : ?>
								<option value="<?php echo (int) $cat->id; ?>" <?php selected( $selected, (int) $cat->id ); ?>><?php echo esc_html( $cat->name ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endif; ?>
				</select>
			</label>
			<label><?php esc_html_e( 'Quantity', 'project-prepper' ); ?>
				<input type="number" name="pp_quantity" min="1" value="<?php echo (int) $val( 'quantity', 1 ); ?>">
			</label>
			<label><?php esc_html_e( 'Condition', 'project-prepper' ); ?>
				<select name="pp_condition">
					<?php foreach ( $conditions as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( (string) $val( 'condition', 'good' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><?php esc_html_e( 'Daily rate (€, optional)', 'project-prepper' ); ?>
				<input type="number" name="pp_cost" step="0.01" min="0" value="<?php echo esc_attr( (string) $val( 'cost_per_day' ) ); ?>">
			</label>
			<label><?php esc_html_e( 'Manufacturer', 'project-prepper' ); ?>
				<input type="text" name="pp_manufacturer" value="<?php echo esc_attr( (string) $val( 'manufacturer' ) ); ?>">
			</label>
			<label><?php esc_html_e( 'Model', 'project-prepper' ); ?>
				<input type="text" name="pp_model" value="<?php echo esc_attr( (string) $val( 'model' ) ); ?>">
			</label>
			<label><?php esc_html_e( 'Serial number', 'project-prepper' ); ?>
				<input type="text" name="pp_serial" value="<?php echo esc_attr( (string) $val( 'serial_number' ) ); ?>">
			</label>
			<label><?php esc_html_e( 'Location', 'project-prepper' ); ?>
				<input type="text" name="pp_location" value="<?php echo esc_attr( (string) $val( 'location' ) ); ?>">
			</label>
			<label><?php esc_html_e( 'Dimensions', 'project-prepper' ); ?>
				<input type="text" name="pp_dimensions" value="<?php echo esc_attr( (string) $val( 'dimensions' ) ); ?>">
			</label>
			<label><?php esc_html_e( 'Tags (comma-separated)', 'project-prepper' ); ?>
				<input type="text" name="pp_tags" value="<?php echo esc_attr( $item && isset( $item->tags ) ? implode( ', ', (array) $item->tags ) : '' ); ?>">
			</label>
			<label><?php esc_html_e( 'Description (optional)', 'project-prepper' ); ?>
				<textarea name="pp_description" rows="2"><?php echo esc_textarea( (string) $val( 'description' ) ); ?></textarea>
			</label>
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save item', 'project-prepper' ); ?></button>
		</form>
		<?php
	}

	/* ---------- Mein Inventar: CSV-Export / -Import ---------- */

	/** Werkzeugleiste über dem eigenen Inventar: Export-Link + Import-Formular. */
	private static function render_inventory_tools( array $categories, array $conditions, array $own_cats, array $tpl_cats, ?WP_User $user = null, array $groups = [] ): void {
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=pp_member_export' ), 'pp_member_export', 'pp_nonce' );
		?>
		<div class="pp-inv-tools">
			<?php self::render_my_categories( $own_cats, $tpl_cats ); ?>
			<?php if ( $user && $groups ) : ?>
				<?php self::render_full_share_tool( $user, $groups ); ?>
			<?php endif; ?>
			<?php if ( $user ) : ?>
				<?php self::render_evaluation_tool( $user ); ?>
			<?php endif; ?>
			<details class="pp-portal__add">
				<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Import (CSV / Excel)', 'project-prepper' ); ?></summary>
				<form class="pp-portal__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pp_member_import">
					<?php wp_nonce_field( 'pp_member_import', 'pp_nonce' ); ?>
					<label><?php esc_html_e( 'CSV or Excel file', 'project-prepper' ); ?>
						<input type="file" name="pp_file" accept=".csv,text/csv,.xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
					</label>
					<p class="pp-poll-opthint"><?php esc_html_e( 'Tip: export first to get the exact columns, fill in your data, then import. Excel files (.xlsx) are converted automatically; the “Name” column is required.', 'project-prepper' ); ?></p>
					<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Import', 'project-prepper' ); ?></button>
				</form>
			</details>
			<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export (CSV)', 'project-prepper' ); ?></a>
			<button type="button" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" data-pp-xlsx-export="<?php echo esc_url( $export_url ); ?>" data-pp-xlsx-name="mein-inventar-<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"><?php esc_html_e( 'Export (Excel)', 'project-prepper' ); ?></button>
			<span class="pp-inv-tools__spacer"></span>
			<details class="pp-portal__add pp-inv-tools__new">
				<summary class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Add item', 'project-prepper' ); ?></summary>
				<?php self::item_form( 'item_create', $categories, $conditions, null ); ?>
			</details>
		</div>
		<?php
	}

	/**
	 * Werkzeug „Inventar freigeben" (App: Gesamt-Share): teilt alle eigenen Artikel
	 * auf einmal mit einer gewählten Gruppe; reversibel. Nur bei Gruppen-Mitgliedschaft.
	 */
	private static function render_full_share_tool( WP_User $user, array $groups ): void {
		$total = count( MemberInventory::my_items( (int) $user->ID ) );
		?>
		<button type="button" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" data-pp-modal="pp-fullshare"><?php esc_html_e( 'Share inventory', 'project-prepper' ); ?></button>
		<dialog class="pp-modal pp-modal--portal" id="pp-fullshare">
			<div class="pp-modal-header">
				<h2 class="pp-modal__title"><?php esc_html_e( 'Share inventory', 'project-prepper' ); ?></h2>
				<button type="button" class="pp-modal-close" data-pp-modal-close aria-label="<?php esc_attr_e( 'Close', 'project-prepper' ); ?>">✕</button>
			</div>
			<div class="pp-modal-body">
			<div class="pp-portal__cats">
				<p class="pp-portal__hint"><?php esc_html_e( 'Share all your items at once with one of your collectives — instead of sharing them one by one. You can revoke it again any time.', 'project-prepper' ); ?></p>
				<?php $fp_presets = MemberInventory::condition_presets(); ?>
				<div class="pp-fullshare__list">
					<?php foreach ( $groups as $g ) :
						$shared = MemberInventory::shared_count_in_group( (int) $user->ID, (int) $g->id ); ?>
					<div class="pp-fullshare__group">
						<div class="pp-fullshare__head">
							<span class="pp-share__name"><?php echo esc_html( $g->name ); ?></span>
							<small class="pp-muted"><?php /* translators: 1: shared item count, 2: total item count. */ printf( esc_html__( '%1$d of %2$d shared', 'project-prepper' ), (int) $shared, (int) $total ); ?></small>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php self::action_fields( 'inventory_share_all' ); ?>
							<input type="hidden" name="pp_group" value="<?php echo (int) $g->id; ?>">
							<details class="pp-fullshare__defaults">
								<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Default conditions (for newly shared items)', 'project-prepper' ); ?></summary>
								<div class="pp-share__fields">
									<label class="pp-share__rate"><?php esc_html_e( 'Daily rate (€)', 'project-prepper' ); ?>
										<input type="number" step="0.01" min="0" name="pp_rate">
									</label>
									<label class="pp-share__approval"><input type="checkbox" name="pp_approval" value="1"> <?php esc_html_e( 'Requires approval', 'project-prepper' ); ?></label>
								</div>
								<div class="pp-share__conds">
									<?php foreach ( $fp_presets as $fp_key => $fp_label ) : ?>
										<label class="pp-portal__chip"><input type="checkbox" name="pp_cond[]" value="<?php echo esc_attr( $fp_key ); ?>" hidden><?php echo esc_html( $fp_label ); ?></label>
									<?php endforeach; ?>
								</div>
								<label class="pp-share__notes"><?php esc_html_e( 'Notes (optional)', 'project-prepper' ); ?>
									<textarea name="pp_conditions" rows="2"></textarea>
								</label>
							</details>
							<button type="submit" class="pp-portal__btn pp-portal__btn--sm" <?php disabled( $total > 0 && $shared >= $total ); ?>><?php esc_html_e( 'Share all', 'project-prepper' ); ?></button>
						</form>
						<?php if ( $shared > 0 ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke sharing of all your items with this collective?', 'project-prepper' ) ); ?>');">
								<?php self::action_fields( 'inventory_unshare_all' ); ?>
								<input type="hidden" name="pp_group" value="<?php echo (int) $g->id; ?>">
								<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Revoke all', 'project-prepper' ); ?></button>
							</form>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
			</div>
		</dialog>
		<?php
	}

	/**
	 * Werkzeug „Auswertung" (App: Inventar-Auswertung): Tageswert-Potenzial,
	 * Auslastung (Einsätze/Tage unterwegs) und belegte Verleih-Erträge je Artikel.
	 * Ehrlich ohne erfundene Erträge — es gibt keine Projekt-Ertrags-Snapshots.
	 */
	private static function render_evaluation_tool( WP_User $user ): void {
		$eval   = MemberInventory::evaluation( (int) $user->ID );
		$rows   = $eval['items'];
		$totals = $eval['totals'];
		$has_earn = $totals->earnings > 0;
		?>
		<details class="pp-portal__add">
			<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Analysis', 'project-prepper' ); ?></summary>
			<div class="pp-portal__cats pp-eval">
				<p class="pp-portal__hint"><?php esc_html_e( 'Daily-rate potential and how often each item was out. Rental earnings are only shown where a per-item daily rate is recorded — nothing is estimated.', 'project-prepper' ); ?></p>

				<div class="pp-eval__kpis">
					<div class="pp-eval__kpi">
						<span class="pp-eval__kpi-label"><?php esc_html_e( 'Daily value', 'project-prepper' ); ?></span>
						<span class="pp-eval__kpi-val"><?php echo esc_html( number_format_i18n( $totals->daily_value, 2 ) . ' €' ); ?></span>
					</div>
					<div class="pp-eval__kpi">
						<span class="pp-eval__kpi-label"><?php esc_html_e( 'Active items', 'project-prepper' ); ?></span>
						<span class="pp-eval__kpi-val"><?php echo (int) $totals->active . ' / ' . (int) $totals->count; ?></span>
					</div>
					<div class="pp-eval__kpi">
						<span class="pp-eval__kpi-label"><?php esc_html_e( 'Times out', 'project-prepper' ); ?></span>
						<span class="pp-eval__kpi-val"><?php echo (int) $totals->uses; ?></span>
					</div>
					<div class="pp-eval__kpi">
						<span class="pp-eval__kpi-label"><?php esc_html_e( 'Recorded earnings', 'project-prepper' ); ?></span>
						<span class="pp-eval__kpi-val"><?php echo esc_html( number_format_i18n( $totals->earnings, 2 ) . ' €' ); ?></span>
					</div>
				</div>

				<?php if ( $rows ) : ?>
					<div class="pp-inv-row pp-inv-row--head pp-eval__head">
						<span class="pp-col pp-col--name"><?php esc_html_e( 'Item', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--c"><?php esc_html_e( 'Daily value', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--c"><?php esc_html_e( 'Times out', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--c"><?php esc_html_e( 'Days out', 'project-prepper' ); ?></span>
						<?php if ( $has_earn ) : ?><span class="pp-col pp-col--c"><?php esc_html_e( 'Earnings', 'project-prepper' ); ?></span><?php endif; ?>
					</div>
					<?php foreach ( $rows as $r ) : ?>
						<div class="pp-inv-row pp-eval__row">
							<span class="pp-col pp-col--name">
								<span class="pp-portal__group-name"><?php echo esc_html( $r->name ); ?></span>
								<small class="pp-portal__item-num"><?php echo esc_html( $r->inventory_number ); ?></small>
							</span>
							<span class="pp-col pp-col--c" data-label="<?php esc_attr_e( 'Daily value', 'project-prepper' ); ?>"><?php echo esc_html( number_format_i18n( $r->daily_value, 2 ) . ' €' ); ?></span>
							<span class="pp-col pp-col--c" data-label="<?php esc_attr_e( 'Times out', 'project-prepper' ); ?>"><?php echo (int) $r->uses; ?></span>
							<span class="pp-col pp-col--c" data-label="<?php esc_attr_e( 'Days out', 'project-prepper' ); ?>"><?php echo (int) $r->days_out; ?></span>
							<?php if ( $has_earn ) : ?><span class="pp-col pp-col--c" data-label="<?php esc_attr_e( 'Earnings', 'project-prepper' ); ?>"><?php echo $r->earnings > 0 ? esc_html( number_format_i18n( $r->earnings, 2 ) . ' €' ) : '—'; ?></span><?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<p class="pp-portal__empty"><?php esc_html_e( 'Add items to see an analysis.', 'project-prepper' ); ?></p>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	/** URL einer Inventar-Seite (Suche + Pagination erhalten). */
	private static function inventory_page_url( string $q, int $page ): string {
		$args = [ 'pp_view' => 'inventory' ];
		if ( '' !== $q ) {
			$args['pp_q'] = $q;
		}
		if ( $page > 1 ) {
			$args['pp_p'] = $page;
		}
		return add_query_arg( $args, self::portal_url() );
	}

	/** CSV-Download des eigenen Inventars (Semikolon + BOM → deutsches Excel). */
	public static function handle_inventory_export(): void {
		$back = add_query_arg( 'pp_view', 'inventory', self::portal_url() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce direkt darunter geprüft.
		if ( ! is_user_logged_in() || ! isset( $_GET['pp_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['pp_nonce'] ), 'pp_member_export' ) ) {
			wp_safe_redirect( $back );
			exit;
		}

		$items      = MemberInventory::my_items( get_current_user_id() );
		$columns    = \ProjectPrepper\Rest\ImportExportController::export_columns();
		$conditions = Shortcodes::condition_labels();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mein-inventar-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo "\xEF\xBB\xBF"; // UTF-8-BOM für Excel.
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV-Streaming an die Ausgabe; WP_Filesystem ist dafür nicht vorgesehen.
		fputcsv( $out, array_values( $columns ), ';' );
		foreach ( $items as $it ) {
			$row = [];
			foreach ( array_keys( $columns ) as $key ) {
				$row[] = self::export_inventory_cell( $it, $key, $conditions );
			}
			fputcsv( $out, $row, ';' );
		}
		exit; // php://output wird beim Exit geschlossen.
	}

	/**
	 * DSGVO-Selbstauskunft (Art. 15/20): JSON-Download aller eigenen Plugin-Daten
	 * des eingeloggten Mitglieds — Profil, Inventar, Gruppen, Leih-Vorgänge.
	 */
	public static function handle_member_data_export(): void {
		$back = self::portal_url();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce direkt darunter geprüft.
		if ( ! is_user_logged_in() || ! isset( $_GET['pp_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['pp_nonce'] ), 'pp_member_data' ) ) {
			wp_safe_redirect( $back );
			exit;
		}

		$user = wp_get_current_user();
		$uid  = (int) $user->ID;

		$data = [
			'exported_at' => gmdate( 'c' ),
			'profile'     => [
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'registered'   => $user->user_registered,
			],
			'inventory'   => array_map( static function ( $it ) {
				return [
					'inventory_number' => $it->inventory_number ?? '',
					'name'             => $it->name ?? '',
					'category'         => $it->category_name ?? '',
					'condition'        => $it->condition ?? '',
					'quantity'         => (int) ( $it->quantity ?? 0 ),
					'manufacturer'     => $it->manufacturer ?? '',
					'model'            => $it->model ?? '',
					'serial_number'    => $it->serial_number ?? '',
					'location'         => $it->location ?? '',
					'cost_per_day'     => $it->cost_per_day ?? null,
					'tags'             => (array) ( $it->tags ?? [] ),
				];
			}, MemberInventory::my_items( $uid ) ),
			'groups'      => array_map( static function ( $g ) {
				return [
					'name' => $g->name,
					'role' => $g->member_role,
				];
			}, Groups::user_groups( $uid ) ),
			'borrows_outgoing' => array_map( [ self::class, 'export_borrow_row' ], Borrowing::my_requests( $uid ) ),
			'borrows_incoming' => array_map( [ self::class, 'export_borrow_row' ], Borrowing::incoming_requests( $uid ) ),
		];

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="meine-daten-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/** Eine Leih-Zeile auf die fürs DSGVO-Export relevanten Felder reduzieren. */
	private static function export_borrow_row( object $r ): array {
		return [
			'item'      => $r->item_name ?? '',
			'date_from' => $r->date_from ?? '',
			'date_to'   => $r->date_to ?? '',
			'status'    => $r->status ?? '',
			'message'   => $r->message ?? '',
		];
	}

	private static function export_inventory_cell( object $item, string $key, array $conditions ): string {
		switch ( $key ) {
			case 'condition':
				$c = (string) ( $item->condition ?? '' );
				return $conditions[ $c ] ?? $c;
			case 'category_name':
				return (string) ( $item->category_name ?? '' );
			case 'tags':
				return implode( ', ', (array) ( $item->tags ?? [] ) );
			default:
				$v = $item->$key ?? '';
				return is_scalar( $v ) ? (string) $v : '';
		}
	}

	/** CSV-Upload → eigene Items anlegen (Bulk). */
	public static function handle_inventory_import(): void {
		$back = add_query_arg( 'pp_view', 'inventory', self::portal_url() );
		if ( ! is_user_logged_in() || ! isset( $_POST['pp_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pp_nonce'] ), 'pp_member_import' ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'error', $back ) );
			exit;
		}
		if ( empty( $_FILES['pp_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['pp_file']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'import_nofile', $back ) );
			exit;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- tmp_name kommt von PHP, is_uploaded_file geprüft.
		$created = self::import_inventory_csv( get_current_user_id(), $_FILES['pp_file']['tmp_name'] );
		wp_safe_redirect( add_query_arg( [ 'pp_msg' => 'imported', 'pp_n' => (int) $created ], $back ) );
		exit;
	}

	/** CSV einlesen (max. 500 Zeilen) und je Zeile ein eigenes Item anlegen. */
	private static function import_inventory_csv( int $user_id, string $path ): int {
		$columns = \ProjectPrepper\Rest\ImportExportController::export_columns();
		$map     = [];
		foreach ( $columns as $key => $label ) {
			$map[ self::norm_head( (string) $label ) ] = $key;
			$map[ self::norm_head( $key ) ]            = $key;
		}
		// Aliase für gängige Fremd-Exporte (z. B. Project-Prepper-Web-App / Supabase),
		// damit Inventarnummer, Tagessatz & Co. auch bei abweichenden Überschriften ankommen.
		$aliases = [
			'inv.-nr.'          => 'inventory_number',
			'inv-nr.'           => 'inventory_number',
			'inv-nr'            => 'inventory_number',
			'inventarnummer'    => 'inventory_number',
			'nummer'            => 'inventory_number',
			'preis/tag (€)'     => 'cost_per_day',
			'preis/tag'         => 'cost_per_day',
			'€/tag'             => 'cost_per_day',
			'tagessatz (€)'     => 'cost_per_day',
			'kaufpreis (€)'     => 'purchase_price',
			'kaufpreis'         => 'purchase_price',
			'abmaße'            => 'dimensions',
			'abmaße (mm)'       => 'dimensions',
			'leistung (w)'      => 'power_watts',
			'hersteller-link'   => 'manufacturer_url',
			'manual-link'       => 'manual_url',
			'gerätebezeichnung' => 'model',
			'freifeld'          => 'notes',
			'pate'              => 'funding_source',
		];
		foreach ( $aliases as $head => $key ) {
			$map[ self::norm_head( $head ) ] = $key;
		}
		// Kategorie-Namen → ID (eigene Kategorien + Betreiber-Vorlagen; keine Auto-Anlage).
		$cat_map = [];
		foreach ( MemberInventory::template_categories() as $cat ) {
			$cat_map[ self::norm_head( $cat->name ) ] = (int) $cat->id;
		}
		foreach ( MemberInventory::own_categories( $user_id ) as $cat ) {
			$cat_map[ self::norm_head( $cat->name ) ] = (int) $cat->id; // eigene haben Vorrang
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Zeilenweises Parsen der hochgeladenen CSV (is_uploaded_file geprüft); WP_Filesystem kann CSV nicht streamen.
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			return 0;
		}
		$header = fgetcsv( $fh, 0, ';' );
		if ( ! $header ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- s. fopen oben.
			return 0;
		}
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$keys      = array_map( static fn( $h ) => $map[ self::norm_head( (string) $h ) ] ?? null, $header );

		$created = 0;
		$rows    = 0;
		while ( ( $row = fgetcsv( $fh, 0, ';' ) ) !== false ) {
			if ( ++$rows > 500 ) {
				break;
			}
			$d = [];
			foreach ( $row as $i => $cell ) {
				$key = $keys[ $i ] ?? null;
				if ( $key ) {
					$d[ $key ] = trim( (string) $cell );
				}
			}
			if ( empty( $d['name'] ) ) {
				continue;
			}
			$result = MemberInventory::create( $user_id, self::build_import_item( $d, $cat_map ) );
			if ( ! is_wp_error( $result ) ) {
				$created++;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- s. fopen oben.
		return $created;
	}

	/** Eine CSV-Zeile in das Item-Datenarray für MemberInventory::create übersetzen. */
	private static function build_import_item( array $d, array $cat_map ): array {
		$cond = \ProjectPrepper\Rest\ImportExportController::CONDITION_MAP[ self::norm_head( $d['condition'] ?? '' ) ] ?? 'good';
		$tags = ( isset( $d['tags'] ) && '' !== $d['tags'] )
			? array_values( array_filter( array_map( 'trim', explode( ',', $d['tags'] ) ) ) )
			: [];
		return [
			'inventory_number' => sanitize_text_field( $d['inventory_number'] ?? '' ),
			'name'           => sanitize_text_field( $d['name'] ?? '' ),
			'description'    => sanitize_textarea_field( $d['description'] ?? '' ),
			'manufacturer'   => sanitize_text_field( $d['manufacturer'] ?? '' ),
			'model'          => sanitize_text_field( $d['model'] ?? '' ),
			'serial_number'  => sanitize_text_field( $d['serial_number'] ?? '' ),
			'location'       => sanitize_text_field( $d['location'] ?? '' ),
			'dimensions'     => sanitize_text_field( $d['dimensions'] ?? '' ),
			'accessories'    => sanitize_text_field( $d['accessories'] ?? '' ),
			'notes'          => sanitize_textarea_field( $d['notes'] ?? '' ),
			'manufacturer_url' => esc_url_raw( $d['manufacturer_url'] ?? '' ),
			'manual_url'     => esc_url_raw( $d['manual_url'] ?? '' ),
			'funding_source' => sanitize_text_field( $d['funding_source'] ?? '' ),
			'category_id'    => $cat_map[ self::norm_head( $d['category_name'] ?? '' ) ] ?? 0,
			'quantity'       => max( 1, (int) ( $d['quantity'] ?? 1 ) ),
			'condition'      => $cond,
			'cost_per_day'   => self::num_str( $d['cost_per_day'] ?? '' ),
			'purchase_price' => self::num_str( $d['purchase_price'] ?? '' ),
			'current_value'  => self::num_str( $d['current_value'] ?? '' ),
			'power_watts'    => ( isset( $d['power_watts'] ) && '' !== $d['power_watts'] ) ? (int) $d['power_watts'] : '',
			'purchase_date'  => self::parse_import_date( $d['purchase_date'] ?? '' ),
			'tags'           => $tags,
		];
	}

	private static function norm_head( string $s ): string {
		return mb_strtolower( trim( $s ) );
	}

	private static function num_str( string $v ): string {
		$v = trim( str_replace( ',', '.', $v ) );
		return is_numeric( $v ) ? $v : '';
	}

	private static function parse_import_date( string $v ): string {
		$v = trim( $v );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
			return $v;
		}
		if ( preg_match( '#^(\d{2})\.(\d{2})\.(\d{4})$#', $v, $m ) ) {
			return $m[3] . '-' . $m[2] . '-' . $m[1];
		}
		return '';
	}

	/** Foto eines eigenen Items hochladen/ersetzen oder entfernen. */
	public static function handle_inventory_photo(): void {
		$back = add_query_arg( 'pp_view', 'inventory', self::portal_url() );
		if ( ! is_user_logged_in() || ! isset( $_POST['pp_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pp_nonce'] ), 'pp_member_photo' ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'error', $back ) );
			exit;
		}
		$user_id = get_current_user_id();
		$item_id = (int) ( $_POST['pp_item'] ?? 0 );
		if ( ! MemberInventory::owns( $user_id, $item_id ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'error', $back ) );
			exit;
		}

		// Entfernen.
		if ( ! empty( $_POST['pp_remove'] ) ) {
			MemberInventory::set_image( $user_id, $item_id, null );
			wp_safe_redirect( add_query_arg( 'pp_msg', 'photo_removed', $back ) );
			exit;
		}

		if ( empty( $_FILES['pp_photo']['tmp_name'] ) || ! is_uploaded_file( $_FILES['pp_photo']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'import_nofile', $back ) );
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Nur Bild-MIME-Typen zulassen (kein test_form, da kein klassisches Admin-Formular).
		$overrides = [
			'test_form' => false,
			'mimes'     => [
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
			],
		];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- $_FILES wird von wp_handle_upload validiert (mimes-Whitelist).
		$moved = wp_handle_upload( $_FILES['pp_photo'], $overrides );
		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'photo_failed', $back ) );
			exit;
		}

		$attach_id = wp_insert_attachment( [
			'post_mime_type' => $moved['type'],
			'post_title'     => sanitize_file_name( wp_basename( $moved['file'] ) ),
			'post_status'    => 'inherit',
		], $moved['file'] );
		if ( ! $attach_id || is_wp_error( $attach_id ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'photo_failed', $back ) );
			exit;
		}
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( (int) $attach_id, $moved['file'] ) );
		MemberInventory::set_image( $user_id, $item_id, (int) $attach_id );

		wp_safe_redirect( add_query_arg( 'pp_msg', 'photo_saved', $back ) );
		exit;
	}

	/** Eigenes Profilfoto (Avatar) hochladen/ersetzen oder entfernen. */
	public static function handle_member_avatar(): void {
		$back = self::portal_url();
		if ( ! is_user_logged_in() || ! isset( $_POST['pp_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pp_nonce'] ), 'pp_member_avatar' ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'error', $back ) );
			exit;
		}
		$user_id = get_current_user_id();

		// Entfernen.
		if ( ! empty( $_POST['pp_remove'] ) ) {
			delete_user_meta( $user_id, 'pp_avatar_id' );
			wp_safe_redirect( add_query_arg( 'pp_msg', 'avatar_removed', $back ) );
			exit;
		}

		if ( empty( $_FILES['pp_avatar']['tmp_name'] ) || ! is_uploaded_file( $_FILES['pp_avatar']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'import_nofile', $back ) );
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$overrides = [
			'test_form' => false,
			'mimes'     => [
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
			],
		];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- $_FILES wird von wp_handle_upload validiert (mimes-Whitelist).
		$moved = wp_handle_upload( $_FILES['pp_avatar'], $overrides );
		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'avatar_failed', $back ) );
			exit;
		}

		$attach_id = wp_insert_attachment( [
			'post_mime_type' => $moved['type'],
			'post_title'     => sanitize_file_name( wp_basename( $moved['file'] ) ),
			'post_status'    => 'inherit',
		], $moved['file'] );
		if ( ! $attach_id || is_wp_error( $attach_id ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'avatar_failed', $back ) );
			exit;
		}
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( (int) $attach_id, $moved['file'] ) );
		update_user_meta( $user_id, 'pp_avatar_id', (int) $attach_id );

		wp_safe_redirect( add_query_arg( 'pp_msg', 'avatar_saved', $back ) );
		exit;
	}

	/**
	 * Dokument-Upload (PDF/Bild) für ein eigenes Inventar-Item. Analog zum Foto,
	 * aber als Mehrfach-Liste (document_ids) statt einzelnem Bild.
	 */
	public static function handle_inventory_doc(): void {
		$back = add_query_arg( 'pp_view', 'inventory', self::portal_url() );
		if ( ! is_user_logged_in() || ! isset( $_POST['pp_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pp_nonce'] ), 'pp_member_doc' ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'error', $back ) );
			exit;
		}
		$user_id = get_current_user_id();
		$item_id = (int) ( $_POST['pp_item'] ?? 0 );
		if ( ! MemberInventory::owns( $user_id, $item_id ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'error', $back ) );
			exit;
		}

		// Entfernen einer einzelnen Datei (Attachment bleibt in der Mediathek).
		if ( ! empty( $_POST['pp_remove'] ) ) {
			MemberInventory::remove_document( $user_id, $item_id, (int) ( $_POST['pp_doc'] ?? 0 ) );
			wp_safe_redirect( add_query_arg( 'pp_msg', 'doc_removed', $back ) );
			exit;
		}

		if ( empty( $_FILES['pp_doc']['tmp_name'] ) || ! is_uploaded_file( $_FILES['pp_doc']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'import_nofile', $back ) );
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// PDF + gängige Bildformate zulassen.
		$overrides = [
			'test_form' => false,
			'mimes'     => [
				'pdf'          => 'application/pdf',
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
			],
		];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- $_FILES wird von wp_handle_upload validiert (mimes-Whitelist).
		$moved = wp_handle_upload( $_FILES['pp_doc'], $overrides );
		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'doc_failed', $back ) );
			exit;
		}

		$attach_id = wp_insert_attachment( [
			'post_mime_type' => $moved['type'],
			'post_title'     => sanitize_file_name( wp_basename( $moved['file'] ) ),
			'post_status'    => 'inherit',
		], $moved['file'] );
		if ( ! $attach_id || is_wp_error( $attach_id ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'doc_failed', $back ) );
			exit;
		}
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( (int) $attach_id, $moved['file'] ) );
		MemberInventory::add_document( $user_id, $item_id, (int) $attach_id );

		wp_safe_redirect( add_query_arg( 'pp_msg', 'doc_saved', $back ) );
		exit;
	}

	/* ---------- Stöbern & Leihen (Phase 4) ---------- */

	/** @param array<object> $groups */
	private static function render_browse( WP_User $user, array $groups ): void {
		if ( ! $groups ) {
			return;
		}
		$conditions = Shortcodes::condition_labels();
		// Optionaler Zeitraum-Filter (GET) — zeigt die Verfügbarkeit für genau diese
		// Tage statt nur „heute" und belegt das Leih-Formular vor.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reiner Anzeige-Filter ohne Schreibvorgang.
		$pf = isset( $_GET['pp_bfrom'] ) ? sanitize_text_field( wp_unslash( $_GET['pp_bfrom'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reiner Anzeige-Filter ohne Schreibvorgang.
		$pt        = isset( $_GET['pp_bto'] ) ? sanitize_text_field( wp_unslash( $_GET['pp_bto'] ) ) : '';
		$period_ok = self::is_ymd( $pf ) && self::is_ymd( $pt ) && $pf <= $pt;
		$any       = false;
		ob_start();
		foreach ( $groups as $group ) {
			$items = Borrowing::browse( (int) $group->id );
			if ( ! $items ) {
				continue;
			}
			$any = true;
			?>
			<div class="pp-portal__collective">
				<div class="pp-portal__collective-head">
					<span class="pp-portal__group-name"><?php echo esc_html( $group->name ); ?></span>
				</div>
				<?php foreach ( $items as $item ) :
					$is_mine = ( (int) ( $item->owner_user_id ?? 0 ) === (int) $user->ID ); ?>
					<div class="pp-portal__browse-item">
						<div class="pp-portal__item-head">
							<span class="pp-portal__group-name"><?php echo esc_html( $item->name ); ?></span>
							<span class="pp-portal__item-meta">
								<?php
								echo esc_html( $conditions[ $item->item_condition ] ?? $item->item_condition );
								echo ' · ';
								/* translators: %s: owner display name. */
								printf( esc_html__( 'from %s', 'project-prepper' ), esc_html( $item->owner_name ) );
								echo ' · ';
								if ( $period_ok ) {
									$pp_avail = Borrowing::available_units( (int) $item->id, $pf, $pt );
									/* translators: 1: free units, 2: total quantity, 3: start date, 4: end date. */
									printf( esc_html__( '%1$d of %2$d free (%3$s – %4$s)', 'project-prepper' ), (int) $pp_avail, (int) $item->quantity, esc_html( $pf ), esc_html( $pt ) );
								} else {
									$pp_today = current_time( 'Y-m-d' );
									$pp_avail = Borrowing::available_units( (int) $item->id, $pp_today, $pp_today );
									/* translators: 1: free units today, 2: total quantity. */
									printf( esc_html__( '%1$d of %2$d free today', 'project-prepper' ), (int) $pp_avail, (int) $item->quantity );
								}
								?>
							</span>
						</div>
						<?php if ( $is_mine ) : ?>
							<span class="pp-portal__tag pp-portal__tag--muted"><?php esc_html_e( 'Your item', 'project-prepper' ); ?></span>
						<?php else : ?>
							<button type="button" class="pp-portal__btn pp-portal__btn--sm" data-pp-modal="pp-borrow-<?php echo (int) $group->id; ?>-<?php echo (int) $item->id; ?>"><?php esc_html_e( 'Borrow', 'project-prepper' ); ?></button>
							<dialog class="pp-modal pp-modal--portal" id="pp-borrow-<?php echo (int) $group->id; ?>-<?php echo (int) $item->id; ?>">
								<div class="pp-modal-header">
									<h2 class="pp-modal__title"><?php echo esc_html( $item->name ); ?></h2>
									<button type="button" class="pp-modal-close" data-pp-modal-close aria-label="<?php esc_attr_e( 'Close', 'project-prepper' ); ?>">✕</button>
								</div>
								<div class="pp-modal-body">
								<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php self::action_fields( 'borrow_request' ); ?>
									<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
									<input type="hidden" name="pp_group" value="<?php echo (int) $group->id; ?>">
									<label><?php esc_html_e( 'From', 'project-prepper' ); ?>
										<input type="date" name="pp_from" value="<?php echo $period_ok ? esc_attr( $pf ) : ''; ?>" required>
									</label>
									<label><?php esc_html_e( 'To', 'project-prepper' ); ?>
										<input type="date" name="pp_to" value="<?php echo $period_ok ? esc_attr( $pt ) : ''; ?>" required>
									</label>
									<label><?php esc_html_e( 'Message (optional)', 'project-prepper' ); ?>
										<textarea name="pp_message" rows="2"></textarea>
									</label>
									<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Send request', 'project-prepper' ); ?></button>
								</form>
								</div>
							</dialog>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		}
		$html = (string) ob_get_clean();
		if ( ! $any ) {
			return;
		}
		?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php esc_html_e( 'Available in your collectives', 'project-prepper' ); ?></h3>
			<form class="pp-browse-period" method="get">
				<input type="hidden" name="pp_view" value="lending">
				<label><?php esc_html_e( 'From', 'project-prepper' ); ?>
					<input type="date" name="pp_bfrom" value="<?php echo esc_attr( $pf ); ?>">
				</label>
				<label><?php esc_html_e( 'To', 'project-prepper' ); ?>
					<input type="date" name="pp_bto" value="<?php echo esc_attr( $pt ); ?>">
				</label>
				<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Check availability', 'project-prepper' ); ?></button>
				<?php if ( $period_ok ) : ?>
					<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" href="<?php echo esc_url( self::view_url( 'lending' ) ); ?>"><?php esc_html_e( 'Reset', 'project-prepper' ); ?></a>
				<?php endif; ?>
			</form>
			<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intern erzeugtes, bereits escaptes Markup. ?>
		</section>
		<?php
	}

	/** Strikte YYYY-MM-DD-Prüfung (für GET-Datums-Filter). */
	private static function is_ymd( string $v ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v );
	}

	/** Aktive Vorgänge (offen/laufend) vs. abgeschlossene (Historie). */
	private const BORROW_ACTIVE = [ 'requested', 'approved' ];
	private const BORROW_CLOSED = [ 'returned', 'declined', 'cancelled' ];

	private static function render_my_borrows( WP_User $user ): void {
		$requests = array_filter(
			Borrowing::my_requests( (int) $user->ID ),
			static fn( $r ) => in_array( $r->status, self::BORROW_ACTIVE, true )
		);
		if ( ! $requests ) {
			return;
		}
		?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php esc_html_e( 'My borrow requests', 'project-prepper' ); ?></h3>
			<?php foreach ( $requests as $r ) : ?>
				<div class="pp-portal__invite">
					<span class="pp-portal__group-name"><?php echo esc_html( $r->item_name ); ?></span>
					<span class="pp-portal__item-meta"><?php echo esc_html( $r->date_from . ' – ' . $r->date_to ); ?></span>
					<span class="pp-portal__tag <?php echo esc_attr( self::borrow_status_class( $r->status ) ); ?>"><?php echo esc_html( self::borrow_status_label( $r->status ) ); ?></span>
					<?php if ( 'requested' === $r->status ) : ?>
						<?php self::borrow_action_form( 'borrow_cancel', (int) $r->id, __( 'Cancel', 'project-prepper' ), true ); ?>
					<?php elseif ( 'approved' === $r->status ) : ?>
						<?php self::borrow_action_form( 'borrow_return', (int) $r->id, __( 'Mark returned', 'project-prepper' ), true ); ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	private static function render_incoming_borrows( WP_User $user ): void {
		$requests = array_filter(
			Borrowing::incoming_requests( (int) $user->ID ),
			static fn( $r ) => in_array( $r->status, self::BORROW_ACTIVE, true )
		);
		if ( ! $requests ) {
			return;
		}
		?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php esc_html_e( 'Borrow requests for your items', 'project-prepper' ); ?></h3>
			<?php foreach ( $requests as $r ) : ?>
				<div class="pp-portal__invite">
					<span class="pp-portal__group-name"><?php echo esc_html( $r->item_name ); ?></span>
					<span class="pp-portal__item-meta">
						<?php
						echo esc_html( $r->date_from . ' – ' . $r->date_to );
						if ( '' !== (string) $r->counterpart_name ) {
							echo ' · ' . esc_html( $r->counterpart_name );
						}
						?>
					</span>
					<span class="pp-portal__tag <?php echo esc_attr( self::borrow_status_class( $r->status ) ); ?>"><?php echo esc_html( self::borrow_status_label( $r->status ) ); ?></span>
					<?php if ( '' !== trim( (string) $r->message ) ) : ?>
						<p class="pp-portal__members" style="flex-basis:100%;margin:.3rem 0 0;"><?php echo esc_html( $r->message ); ?></p>
					<?php endif; ?>
					<div class="pp-portal__actions">
						<?php if ( 'requested' === $r->status ) : ?>
							<?php self::borrow_action_form( 'borrow_approve', (int) $r->id, __( 'Approve', 'project-prepper' ) ); ?>
							<?php self::borrow_action_form( 'borrow_decline', (int) $r->id, __( 'Decline', 'project-prepper' ), true ); ?>
						<?php elseif ( 'approved' === $r->status ) : ?>
							<?php self::borrow_action_form( 'borrow_return', (int) $r->id, __( 'Mark returned', 'project-prepper' ), true ); ?>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * Ausleih-Historie: abgeschlossene Vorgänge (zurückgegeben/abgelehnt/storniert)
	 * aus beiden Richtungen, eingeklappt und nach Enddatum absteigend sortiert.
	 */
	private static function render_borrow_history( WP_User $user ): void {
		$uid  = (int) $user->ID;
		$rows = [];

		foreach ( Borrowing::my_requests( $uid ) as $r ) {
			if ( in_array( $r->status, self::BORROW_CLOSED, true ) ) {
				$r->pp_dir = 'out';
				$rows[]    = $r;
			}
		}
		foreach ( Borrowing::incoming_requests( $uid ) as $r ) {
			if ( in_array( $r->status, self::BORROW_CLOSED, true ) ) {
				$r->pp_dir = 'in';
				$rows[]    = $r;
			}
		}
		if ( ! $rows ) {
			return;
		}
		usort( $rows, static fn( $a, $b ) => strcmp( (string) $b->date_to, (string) $a->date_to ) );
		?>
		<section class="pp-portal__section">
			<details class="pp-portal__edit">
				<summary class="pp-portal__subtitle pp-history__summary"><?php esc_html_e( 'Borrowing history', 'project-prepper' ); ?> (<?php echo (int) count( $rows ); ?>)</summary>
				<div class="pp-history">
					<?php foreach ( $rows as $r ) : ?>
						<div class="pp-portal__invite">
							<span class="pp-portal__group-name"><?php echo esc_html( $r->item_name ); ?></span>
							<span class="pp-portal__item-meta">
								<?php
								echo esc_html( $r->date_from . ' – ' . $r->date_to );
								echo ' · ';
								echo 'out' === $r->pp_dir
									? esc_html__( 'borrowed', 'project-prepper' )
									: esc_html__( 'lent out', 'project-prepper' );
								if ( '' !== (string) ( $r->counterpart_name ?? '' ) ) {
									echo ' · ' . esc_html( $r->counterpart_name );
								}
								?>
							</span>
							<span class="pp-portal__tag <?php echo esc_attr( self::borrow_status_class( $r->status ) ); ?>"><?php echo esc_html( self::borrow_status_label( $r->status ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</details>
		</section>
		<?php
	}

	private static function borrow_action_form( string $do, int $request_id, string $label, bool $ghost = false ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
			<?php self::action_fields( $do ); ?>
			<input type="hidden" name="pp_request" value="<?php echo (int) $request_id; ?>">
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm <?php echo $ghost ? 'pp-portal__btn--ghost' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function borrow_status_label( string $status ): string {
		$map = [
			'requested' => __( 'Requested', 'project-prepper' ),
			'approved'  => __( 'Approved', 'project-prepper' ),
			'declined'  => __( 'Declined', 'project-prepper' ),
			'cancelled' => __( 'Cancelled', 'project-prepper' ),
			'returned'  => __( 'Returned', 'project-prepper' ),
		];
		return $map[ $status ] ?? $status;
	}

	private static function borrow_status_class( string $status ): string {
		return in_array( $status, [ 'declined', 'cancelled' ], true ) ? 'pp-portal__tag--muted' : '';
	}
}
