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
use ProjectPrepper\Services\InquiryTeam;
use ProjectPrepper\Services\MemberRentals;
use ProjectPrepper\Services\Rentals;
use ProjectPrepper\Services\Inquiries;
use ProjectPrepper\Services\Borrowing;
use ProjectPrepper\Services\Projects;
use ProjectPrepper\Services\BookingApprovals;
use ProjectPrepper\Services\Bundles;
use ProjectPrepper\Services\Availability;
use ProjectPrepper\Services\Costs;
use ProjectPrepper\Services\Schedule;
use ProjectPrepper\Services\Tasks;
use ProjectPrepper\Services\Checklists;
use ProjectPrepper\Services\Consumables;
use ProjectPrepper\Services\Team;
use ProjectPrepper\Services\Contacts;
use ProjectPrepper\Services\ProfitShares;
use ProjectPrepper\Services\Files;
use ProjectPrepper\Services\Decisions;
use ProjectPrepper\Services\Polls;
use ProjectPrepper\Services\CalendarEvents;
use ProjectPrepper\Services\FederatedBorrow;
use ProjectPrepper\Services\Telegram;
use ProjectPrepper\Services\Presence;
use ProjectPrepper\Rest\CalendarController;
use ProjectPrepper\CalDav\Server as CalDavServer;
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
		add_action( 'admin_post_pp_project_file', [ self::class, 'handle_project_file' ] );
		add_action( 'admin_post_pp_member_data', [ self::class, 'handle_member_data_export' ] );
		add_action( 'admin_post_pp_member_avatar', [ self::class, 'handle_member_avatar' ] );
		add_action( 'admin_post_pp_group_logo', [ self::class, 'handle_group_logo' ] );
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
		// Live-Suche für alle Suchmasken (auch im öffentlichen Inventar nutzbar).
		wp_register_script( 'pp-live-search', PP_PLUGIN_URL . 'assets/js/live-search.js', [], PP_VERSION, true );
		// SheetJS (gebündelt) + Inventar-Excel-Logik — nur auf der Inventar-View geladen.
		wp_register_script( 'pp-xlsx', PP_PLUGIN_URL . 'admin/js/vendor/xlsx.full.min.js', [], '0.20.3', true );
		wp_register_script( 'pp-portal-inv', PP_PLUGIN_URL . 'assets/js/portal-inventory.js', [ 'pp-xlsx' ], PP_VERSION, true );

		// Auf der Portal-Seite das Stylesheet hier einreihen — das Vollbild-Template
		// rendert erst nach wp_head(), ein späteres enqueue käme zu spät.
		if ( self::is_portal_page() ) {
			wp_enqueue_style( 'pp-frontend' );
			wp_enqueue_script( 'pp-portal' );
			wp_enqueue_script( 'pp-live-search' );
			// Presence-Heartbeat: das Portal-JS pingt regelmäßig diese Route, damit
			// der eigene „zuletzt gesehen"-Stempel frisch bleibt (nur eingeloggt).
			if ( is_user_logged_in() ) {
				wp_localize_script( 'pp-portal', 'ppPortal', [
					'heartbeatUrl' => esc_url_raw( rest_url( 'project-prepper/v1/heartbeat' ) ),
					'nonce'        => wp_create_nonce( 'wp_rest' ),
					'heartbeatMs'  => 45000,
				] );
			}
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

		// Aktiven Reiter (und ggf. Anfrage-Detail-Kontext) über den Redirect
		// erhalten: die Formulare posten kein pp_tab, aber der (same-origin)
		// Referer kennt beides. Der Reiter wird unten nach den View-spezifischen
		// $back-Umbauten erneut angehängt, damit er JEDEN Redirect überlebt.
		$ref_query = (string) wp_parse_url( (string) wp_get_referer(), PHP_URL_QUERY );
		wp_parse_str( $ref_query, $ref_args );
		$ref_tab     = sanitize_key( (string) ( $ref_args['pp_tab'] ?? '' ) );
		$ref_inquiry = (int) ( $ref_args['pp_inquiry'] ?? 0 );
		$ref_group   = (int) ( $ref_args['pp_group'] ?? 0 );
		$ref_ctab    = sanitize_key( (string) ( $ref_args['pp_ctab'] ?? '' ) );
		if ( '' !== $ref_tab ) {
			$back = add_query_arg( 'pp_tab', $ref_tab, $back );
		}

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
		$gov_actions = [ 'decision_vote', 'decision_create', 'decision_cancel', 'poll_vote', 'poll_create', 'poll_close', 'poll_reopen', 'poll_delete' ];
		if ( in_array( $do, $gov_actions, true ) && ( ! $proj_id || ! Projects::get( $proj_id ) ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'error', self::portal_url() ) );
			exit;
		}

		// Projekt-Unterlisten (Zeitplan/Aufgaben/Checklisten/Material/Team/
		// Kontakte/Kosten/Gewinn/Finanzen): nur auf Projekten des AKTIVEN
		// Gruppen-Workspaces — dasselbe Gate wie die Equipment-Buchung.
		if ( in_array( $do, self::project_sub_actions(), true ) && ! self::member_owned_project( $proj_id ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'error', $back ) );
			exit;
		}

		// Leih-Entscheidungen (Kollektiv + föderiert) kehren zur Verleih-Ansicht zurück.
		if ( in_array( $do, [ 'borrow_request', 'borrow_approve', 'borrow_decline', 'borrow_cancel', 'borrow_return', 'fedborrow_approve', 'fedborrow_decline', 'fedborrow_return' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'lending', self::portal_url() );
		}
		// Eine neue Anfrage wird aus dem Kollektiv-Inventar gestellt (seit v0.41.0,
		// vorher „Stöbern") — danach direkt zu „Meine Leihen", damit man sie sieht.
		if ( 'borrow_request' === $do ) {
			$back = add_query_arg( 'pp_tab', 'borrows', $back );
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
		if ( in_array( $do, [ 'gpoll_vote', 'gpoll_create', 'gpoll_close', 'gpoll_reopen', 'gpoll_delete' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'polls', self::portal_url() );
		}
		// Kollektiv-Detail-Aktionen kehren ins jeweilige Gruppen-Detail zurück und
		// erhalten den aktiven Reiter (Übersicht/Einstellungen) über den Referer.
		$collective_detail_actions = [ 'invite', 'invite_resend', 'invite_remind_voters', 'cancel', 'vote', 'group_update', 'member_remove', 'telegram_test' ];
		if ( in_array( $do, $collective_detail_actions, true ) ) {
			$back = add_query_arg( 'pp_view', 'collectives', self::portal_url() );
			if ( $ref_group > 0 ) {
				$back = add_query_arg( 'pp_group', $ref_group, $back );
				if ( '' !== $ref_ctab ) {
					$back = add_query_arg( 'pp_ctab', $ref_ctab, $back );
				}
			}
		}
		// Gründen / Austreten / Auflösen sowie Einladung annehmen/ablehnen kehren zur
		// Kollektive-LISTE zurück (das Detail wäre danach ggf. nicht mehr zugänglich).
		if ( in_array( $do, [ 'found', 'group_leave', 'group_delete', 'accept', 'decline' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'collectives', self::portal_url() );
		}
		// Kalender-Aktionen kehren in die Kalender-Ansicht zurück — inklusive
		// Monat/Woche/Modus der Ausgangsseite (aus dem Referer), damit man nach
		// dem Speichern im selben Zeitfenster bleibt.
		if ( in_array( $do, [ 'calgroup_create', 'calgroup_update', 'calgroup_delete', 'event_create', 'event_update', 'event_delete', 'ical_rotate' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'calendar', self::portal_url() );
			foreach ( [ 'pp_cal', 'pp_month', 'pp_week' ] as $nav ) {
				$val = sanitize_text_field( (string) ( $ref_args[ $nav ] ?? '' ) );
				if ( '' !== $val ) {
					$back = add_query_arg( $nav, $val, $back );
				}
			}
		}
		// Inventar-, Kategorie- und Gesamt-Freigabe-Aktionen kehren zur Inventar-
		// Ansicht zurück (statt aufs Dashboard) — inkl. Artikel anlegen/bearbeiten/löschen.
		if ( in_array( $do, [ 'item_create', 'item_update', 'item_save_all', 'item_delete', 'category_create', 'category_adopt', 'category_delete', 'inventory_share_all', 'inventory_unshare_all', 'item_share', 'item_unshare', 'item_share_set' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'inventory', self::portal_url() );
		}
		// Anfragen-Aktionen kehren zur Anfragen-Ansicht zurück — Bearbeiten und
		// Statuswechsel aus der Detail-Ansicht bleiben im Detail (Referer kennt
		// den pp_inquiry-Kontext).
		if ( in_array( $do, [ 'inquiry_create', 'inquiry_update', 'inquiry_status', 'inquiry_delete' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'inquiries', self::portal_url() );
			if ( $ref_inquiry > 0 && in_array( $do, [ 'inquiry_update', 'inquiry_status' ], true ) ) {
				$back = add_query_arg( 'pp_inquiry', $ref_inquiry, $back );
			}
		}
		// Team-RSVP-Aktionen bleiben im Anfrage-Detail (Referer kennt pp_inquiry).
		if ( in_array( $do, [ 'inqteam_invite', 'inqteam_revoke', 'inqteam_rsvp' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'inquiries', self::portal_url() );
			if ( $ref_inquiry > 0 ) {
				$back = add_query_arg( 'pp_inquiry', $ref_inquiry, $back );
			}
		}
		// Externe-Verleih-Aktionen kehren zur Verleih-Ansicht zurück.
		if ( in_array( $do, [ 'rental_create', 'rental_update', 'rental_status', 'rental_delete' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'lending', self::portal_url() );
		}
		// Projekt anlegen/löschen → zurück zur Projektliste (Bearbeiten bleibt im Detail).
		if ( in_array( $do, [ 'project_create', 'project_delete' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'projects', self::portal_url() );
		}
		// Freigabe-Entscheidungen kehren zur Freigaben-Ansicht zurück.
		if ( in_array( $do, [ 'booking_approve', 'booking_reject', 'booking_decide_bulk' ], true ) ) {
			$back = add_query_arg( 'pp_view', 'approvals', self::portal_url() );
		}
		// Reiter-Erhalt für ALLE View-Redirects: die Umbauten oben starten von
		// portal_url() neu — den aktiven Reiter der Ausgangsseite wieder anhängen
		// (unbekannte Reiter fallen in der Ziel-View auf den Default zurück).
		if ( '' !== $ref_tab ) {
			$back = add_query_arg( 'pp_tab', $ref_tab, $back );
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
				$result = Governance::invite(
					$grp_id,
					sanitize_email( wp_unslash( (string) ( $_POST['pp_email'] ?? '' ) ) ),
					sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_message'] ?? '' ) ) )
				);
				$ok_msg = 'invited';
				break;
			case 'invite_remind_voters':
				$result = Governance::remind_voters( $inv_id );
				$ok_msg = 'voters_reminded';
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
			case 'invite_resend':
				$result = Governance::resend( $inv_id );
				$ok_msg = 'invite_resent';
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
			case 'group_update':
				// Nur Gründer der Gruppe dürfen Name/Beschreibung/Telegram-chat_id ändern.
				if ( ! self::is_group_founder( $grp_id, get_current_user_id() ) ) {
					$result = new \WP_Error( 'pp_forbidden', 'forbidden' );
				} else {
					$result = Groups::update( $grp_id, [
						'name'             => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) ),
						'description'      => sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_description'] ?? '' ) ) ),
						'telegram_chat_id' => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_telegram_chat_id'] ?? '' ) ) ),
					] );
				}
				$ok_msg = 'group_saved';
				break;
			case 'telegram_test':
				// Nur Gründer dürfen eine Testnachricht an die Gruppe auslösen.
				if ( ! self::is_group_founder( $grp_id, get_current_user_id() ) ) {
					$result = new \WP_Error( 'pp_forbidden', 'forbidden' );
				} else {
					$result = Telegram::send_test( $grp_id );
				}
				$ok_msg = 'telegram_sent';
				break;
			case 'member_remove':
				// Nur Gründer dürfen andere Mitglieder entfernen; Selbst-Entfernen
				// läuft über group_leave. remove_member schützt den letzten Gründer.
				$target = (int) ( $_POST['pp_member'] ?? 0 );
				if ( ! self::is_group_founder( $grp_id, get_current_user_id() ) || $target === get_current_user_id() ) {
					$result = new \WP_Error( 'pp_forbidden', 'forbidden' );
				} else {
					$result = Groups::remove_member( $grp_id, $target );
					// Aktiven Workspace des Entfernten zurücksetzen, falls nötig.
					if ( ! is_wp_error( $result ) && (string) get_user_meta( $target, 'pp_active_group', true ) === (string) $grp_id ) {
						update_user_meta( $target, 'pp_active_group', 'solo' );
					}
				}
				$ok_msg = 'member_removed';
				break;
			case 'group_delete':
				// Nur Gründer dürfen die Gruppe auflösen (Projekte fallen auf
				// Site-Ebene zurück, sie werden NICHT gelöscht — s. Groups::delete).
				if ( ! self::is_group_founder( $grp_id, get_current_user_id() ) ) {
					$result = new \WP_Error( 'pp_forbidden', 'forbidden' );
				} else {
					$result = Groups::delete( $grp_id );
					if ( ! is_wp_error( $result ) && (string) get_user_meta( get_current_user_id(), 'pp_active_group', true ) === (string) $grp_id ) {
						update_user_meta( get_current_user_id(), 'pp_active_group', 'solo' );
					}
				}
				$ok_msg = 'group_deleted';
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
				// Anlegen in EINEM Schritt: Stammdaten + optionales Foto + Set-Inhalt
				// + Kollektiv-Freigaben aus demselben Formular (Feedback: nicht erst
				// anlegen und dann über „Verwalten" nachpflegen).
				$result = MemberInventory::create( get_current_user_id(), self::item_input() );
				if ( ! is_wp_error( $result ) ) {
					self::apply_share_input( get_current_user_id(), (int) $result );
					$pp_bundle_res = self::apply_bundle_input( get_current_user_id(), (int) $result );
					$pp_photo_res  = self::process_item_photo_input( get_current_user_id(), (int) $result );
					if ( is_wp_error( $pp_bundle_res ) ) {
						// Artikel + Freigaben sind gespeichert — nur die Stückliste nicht.
						$result = $pp_bundle_res;
					} elseif ( is_wp_error( $pp_photo_res ) ) {
						// Artikel + Freigaben sind gespeichert — nur das Foto schlug fehl.
						$result = $pp_photo_res;
					}
				}
				$ok_msg = 'item_saved';
				break;
			case 'item_update':
				$result = MemberInventory::update( get_current_user_id(), (int) ( $_POST['pp_item'] ?? 0 ), self::item_input() );
				$ok_msg = 'item_saved';
				break;
			case 'item_save_all':
				// Verwalten-Modal: EIN Speichern für Stammdaten, Foto, Set-Inhalt und
				// alle Kollektiv-Freigaben zusammen (Feedback: kein eigener Button pro
				// Abschnitt; Änderungen werden beim Schließen übernommen).
				$pp_item = (int) ( $_POST['pp_item'] ?? 0 );
				$result  = MemberInventory::update( get_current_user_id(), $pp_item, self::item_input() );
				if ( ! is_wp_error( $result ) ) {
					self::apply_share_input( get_current_user_id(), $pp_item );
					$pp_bundle_res = self::apply_bundle_input( get_current_user_id(), $pp_item );
					$pp_photo_res  = self::process_item_photo_input( get_current_user_id(), $pp_item );
					if ( is_wp_error( $pp_bundle_res ) ) {
						$result = $pp_bundle_res;
					} elseif ( is_wp_error( $pp_photo_res ) ) {
						$result = $pp_photo_res;
					}
				}
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
				$gid    = self::active_workspace_group();
				$result = MemberInquiries::create( get_current_user_id(), $gid, self::inquiry_input() );
				if ( ! is_wp_error( $result ) && $gid > 0 ) {
					self::notify_new_inquiry( $gid, (int) $result );
				}
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
			// --- Team-RSVP bei Gruppen-Anfragen (Guards im Service) ---
			case 'inqteam_invite':
				$result = InquiryTeam::invite( (int) ( $_POST['pp_inquiry'] ?? 0 ), (int) ( $_POST['pp_user'] ?? 0 ), get_current_user_id() );
				$ok_msg = 'team_invited';
				break;
			case 'inqteam_revoke':
				$result = InquiryTeam::revoke( (int) ( $_POST['pp_inquiry'] ?? 0 ), (int) ( $_POST['pp_user'] ?? 0 ), get_current_user_id() );
				$ok_msg = 'team_revoked';
				break;
			case 'inqteam_rsvp':
				$rsvp   = sanitize_key( wp_unslash( (string) ( $_POST['pp_rsvp'] ?? '' ) ) );
				$inq_id = (int) ( $_POST['pp_inquiry'] ?? 0 );
				$result = InquiryTeam::respond( $inq_id, get_current_user_id(), $rsvp );
				if ( ! is_wp_error( $result ) && 'accepted' === $rsvp ) {
					self::notify_rsvp_accepted( $inq_id, get_current_user_id() );
				}
				$ok_msg = 'rsvp_saved';
				break;
			case 'rental_create':
				$in     = self::rental_input();
				$result = MemberRentals::create( get_current_user_id(), $in['data'], $in['items'], $in['sets'] );
				$ok_msg = 'rental_saved';
				break;
			case 'rental_update':
				$in     = self::rental_input();
				$result = MemberRentals::update( (int) ( $_POST['pp_rental'] ?? 0 ), get_current_user_id(), $in['data'], $in['items'], $in['sets'] );
				$ok_msg = 'rental_updated';
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
			case 'project_item_add':
				$result = self::member_book_equipment( $proj_id );
				// String-Rückgabe = eigener Erfolgs-Meldungscode (z.B. Teilerfolg).
				if ( is_string( $result ) ) {
					$ok_msg = $result;
					$result = true;
				} else {
					$ok_msg = 'booking_saved';
				}
				if ( ! is_wp_error( $result ) ) {
					self::notify_new_booking( self::active_workspace_group(), $proj_id );
				}
				break;
			case 'project_item_update':
				$result = self::member_update_booking( $proj_id, (int) ( $_POST['pp_line'] ?? 0 ) );
				// String-Rückgabe = eigener Erfolgs-Meldungscode (Re-Approval).
				if ( is_string( $result ) ) {
					$ok_msg = $result;
					$result = true;
				} else {
					$ok_msg = 'booking_saved';
				}
				break;
			case 'project_item_remove':
				$result = self::member_remove_booking( $proj_id, (int) ( $_POST['pp_line'] ?? 0 ) );
				$ok_msg = 'booking_removed';
				break;
			case 'project_bundle_update':
				$result = self::member_update_bundle( $proj_id, (int) ( $_POST['pp_bundle'] ?? 0 ) );
				// String-Rückgabe = eigener Erfolgs-Meldungscode (Re-Approval).
				if ( is_string( $result ) ) {
					$ok_msg = $result;
					$result = true;
				} else {
					$ok_msg = 'booking_saved';
				}
				break;
			case 'project_bundle_remove':
				$result = self::member_remove_bundle( $proj_id, (int) ( $_POST['pp_bundle'] ?? 0 ) );
				$ok_msg = 'booking_removed';
				break;
			case 'project_item_pack':
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce oben geprüft.
				$want   = ! empty( $_POST['pp_packed'] );
				$result = self::member_toggle_flag( $proj_id, (int) ( $_POST['pp_line'] ?? 0 ), 'packed', $want );
				$ok_msg = $want ? 'packlist_packed' : 'packlist_unpacked';
				break;
			case 'project_item_test':
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce oben geprüft.
				$want   = ! empty( $_POST['pp_tested'] );
				$result = self::member_toggle_flag( $proj_id, (int) ( $_POST['pp_line'] ?? 0 ), 'tested', $want );
				$ok_msg = $want ? 'packlist_tested' : 'packlist_untested';
				break;
			// --- Freigabe-Workflow: nur der Artikel-Eigentümer entscheidet (Gate im Service) ---
			case 'booking_approve':
				$result = BookingApprovals::approve( get_current_user_id(), (int) ( $_POST['pp_line'] ?? 0 ) );
				if ( is_array( $result ) ) {
					do_action( 'pp_booking_approval_decided', $result + [ 'status' => 'approved' ] );
					$result = true;
				}
				$ok_msg = 'booking_approved';
				break;
			case 'booking_reject':
				$result = BookingApprovals::reject( get_current_user_id(), (int) ( $_POST['pp_line'] ?? 0 ) );
				if ( is_array( $result ) ) {
					do_action( 'pp_booking_approval_decided', $result + [ 'status' => 'rejected' ] );
					$result = true;
				}
				$ok_msg = 'booking_rejected';
				break;
			case 'booking_decide_bulk':
				// Sammel-Entscheidung aus der Freigaben-Ansicht: je Zeile approve/
				// reject/leer („später"). Entscheidungen werden PRO ANFRAGER
				// gebündelt — jeder bekommt EINE Mail mit seiner Ergebnis-Liste.
				// Jede Zeile läuft durch dieselben Owner-Gates wie die Einzel-Aktion.
				$raw_decide = is_array( $_POST['pp_decide'] ?? null ) ? wp_unslash( $_POST['pp_decide'] ) : [];
				$decided    = 0;
				$by_requester = [];
				foreach ( $raw_decide as $bulk_line => $bulk_choice ) {
					$bulk_choice = sanitize_key( (string) $bulk_choice );
					if ( ! in_array( $bulk_choice, [ 'approve', 'reject' ], true ) ) {
						continue;
					}
					$ctx = 'approve' === $bulk_choice
						? BookingApprovals::approve( get_current_user_id(), (int) $bulk_line )
						: BookingApprovals::reject( get_current_user_id(), (int) $bulk_line );
					if ( is_array( $ctx ) ) {
						$decided++;
						$ctx['status'] = 'approve' === $bulk_choice ? 'approved' : 'rejected';
						$by_requester[ (int) $ctx['requester_id'] ][] = $ctx;
					}
				}
				foreach ( $by_requester as $bulk_req => $bulk_decisions ) {
					do_action( 'pp_booking_approvals_decided', (int) $bulk_req, $bulk_decisions );
				}
				$result = $decided > 0 ? true : new \WP_Error( 'pp_bulk_none', 'nothing chosen' );
				$ok_msg = 'booking_bulk_saved';
				break;
			// --- Projekt-Unterlisten (Gate: project_sub_actions oben) ---
			case 'sched_add':
				$result = Schedule::create( $proj_id, self::schedule_input() );
				$ok_msg = 'proj_entry_saved';
				break;
			case 'sched_update':
				$sid    = (int) ( $_POST['pp_entry'] ?? 0 );
				$result = self::sub_belongs( Schedule::get( $sid ), $proj_id )
					? Schedule::update( $sid, self::schedule_input() )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_saved';
				break;
			case 'sched_delete':
				$sid    = (int) ( $_POST['pp_entry'] ?? 0 );
				$result = self::sub_belongs( Schedule::get( $sid ), $proj_id )
					? Schedule::delete( $sid )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_deleted';
				break;
			case 'task_add':
				$result = self::member_task_save( $proj_id, 0 );
				$ok_msg = 'proj_entry_saved';
				break;
			case 'task_update':
				$result = self::member_task_save( $proj_id, (int) ( $_POST['pp_entry'] ?? 0 ) );
				$ok_msg = 'proj_entry_saved';
				break;
			case 'task_delete':
				$tid    = (int) ( $_POST['pp_entry'] ?? 0 );
				$result = self::sub_belongs( Tasks::get( $tid ), $proj_id )
					? Tasks::delete( $tid )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_deleted';
				break;
			case 'task_accept':
			case 'task_decline':
				// Nur die ZUGEWIESENE Person darf annehmen/ablehnen.
				$tid  = (int) ( $_POST['pp_entry'] ?? 0 );
				$task = Tasks::get( $tid );
				if ( ! self::sub_belongs( $task, $proj_id ) || (int) $task->assigned_user !== get_current_user_id() ) {
					$result = self::forbidden_error();
				} else {
					$result = Tasks::update( $tid, [ 'assignment_status' => 'task_accept' === $do ? 'accepted' : 'declined' ] );
				}
				$ok_msg = 'task_accept' === $do ? 'task_accepted' : 'task_declined';
				break;
			case 'sched_move':
				// Hoch/Runter innerhalb des Tages — bewusst ohne Erfolgsmeldung.
				$sid    = (int) ( $_POST['pp_entry'] ?? 0 );
				$result = self::sub_belongs( Schedule::get( $sid ), $proj_id )
					? Schedule::move( $sid, sanitize_key( wp_unslash( (string) ( $_POST['pp_dir'] ?? '' ) ) ) )
					: self::forbidden_error();
				break;
			case 'checklist_move':
				$lid    = (int) ( $_POST['pp_list'] ?? 0 );
				$result = self::sub_belongs( Checklists::get( $lid ), $proj_id )
					? Checklists::move( $lid, sanitize_key( wp_unslash( (string) ( $_POST['pp_dir'] ?? '' ) ) ) )
					: self::forbidden_error();
				break;
			case 'checkitem_move':
				$ci     = Checklists::get_item( (int) ( $_POST['pp_citem'] ?? 0 ) );
				$in_prj = $ci && self::sub_belongs( Checklists::get( (int) $ci->checklist_id ), $proj_id );
				$result = $in_prj
					? Checklists::move_item( (int) $ci->id, sanitize_key( wp_unslash( (string) ( $_POST['pp_dir'] ?? '' ) ) ) )
					: self::forbidden_error();
				break;
			case 'checklist_add':
				$result = Checklists::create( $proj_id, sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) ) );
				$ok_msg = 'proj_entry_saved';
				break;
			case 'checklist_delete':
				$lid    = (int) ( $_POST['pp_list'] ?? 0 );
				$result = self::sub_belongs( Checklists::get( $lid ), $proj_id )
					? Checklists::delete( $lid )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_deleted';
				break;
			case 'checkitem_add':
				$lid    = (int) ( $_POST['pp_list'] ?? 0 );
				$result = self::sub_belongs( Checklists::get( $lid ), $proj_id )
					? Checklists::add_item( $lid, sanitize_text_field( wp_unslash( (string) ( $_POST['pp_label'] ?? '' ) ) ) )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_saved';
				break;
			case 'checkitem_toggle':
				// Abhaken/Enthaken — bewusst ohne Erfolgsmeldung (ok_msg 'ok').
				$ci     = Checklists::get_item( (int) ( $_POST['pp_citem'] ?? 0 ) );
				$in_prj = $ci && self::sub_belongs( Checklists::get( (int) $ci->checklist_id ), $proj_id );
				$result = $in_prj
					? Checklists::update_item( (int) $ci->id, [ 'is_checked' => empty( $ci->is_checked ) ? 1 : 0 ] )
					: self::forbidden_error();
				break;
			case 'checkitem_delete':
				$ci     = Checklists::get_item( (int) ( $_POST['pp_citem'] ?? 0 ) );
				$in_prj = $ci && self::sub_belongs( Checklists::get( (int) $ci->checklist_id ), $proj_id );
				$result = $in_prj
					? Checklists::delete_item( (int) $ci->id )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_deleted';
				break;
			case 'material_add':
				$result = Consumables::create( $proj_id, self::consumable_input() );
				$ok_msg = 'proj_entry_saved';
				break;
			case 'material_update':
				$mid    = (int) ( $_POST['pp_entry'] ?? 0 );
				$result = self::sub_belongs( Consumables::get( $mid ), $proj_id )
					? Consumables::update( $mid, self::consumable_input() )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_saved';
				break;
			case 'material_delete':
				$mid    = (int) ( $_POST['pp_entry'] ?? 0 );
				$result = self::sub_belongs( Consumables::get( $mid ), $proj_id )
					? Consumables::delete( $mid )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_deleted';
				break;
			case 'crew_add':
				$result = Team::create( $proj_id, self::crew_input() );
				$ok_msg = 'proj_entry_saved';
				break;
			case 'crew_update':
				$cid    = (int) ( $_POST['pp_entry'] ?? 0 );
				$result = self::sub_belongs( Team::get( $cid ), $proj_id )
					? Team::update( $cid, self::crew_input() )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_saved';
				break;
			case 'crew_delete':
				$cid    = (int) ( $_POST['pp_entry'] ?? 0 );
				$result = self::sub_belongs( Team::get( $cid ), $proj_id )
					? Team::delete( $cid )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_deleted';
				break;
			case 'contact_add':
				$result = Contacts::create( $proj_id, self::contact_input() );
				$ok_msg = 'proj_entry_saved';
				break;
			case 'contact_update':
				$cid    = (int) ( $_POST['pp_entry'] ?? 0 );
				$result = self::sub_belongs( Contacts::get( $cid ), $proj_id )
					? Contacts::update( $cid, self::contact_input() )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_saved';
				break;
			case 'contact_delete':
				$cid    = (int) ( $_POST['pp_entry'] ?? 0 );
				$result = self::sub_belongs( Contacts::get( $cid ), $proj_id )
					? Contacts::delete( $cid )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_deleted';
				break;
			case 'cost_add':
				$result = Costs::create( $proj_id, self::cost_input() );
				$ok_msg = 'proj_entry_saved';
				break;
			case 'cost_update':
				$cid    = (int) ( $_POST['pp_entry'] ?? 0 );
				$result = self::sub_belongs( Costs::get( $cid ), $proj_id )
					? Costs::update( $cid, self::cost_input() )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_saved';
				break;
			case 'cost_delete':
				$cid    = (int) ( $_POST['pp_entry'] ?? 0 );
				$result = self::sub_belongs( Costs::get( $cid ), $proj_id )
					? Costs::delete( $cid )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_deleted';
				break;
			case 'profit_add':
				// Zielperson muss Mitglied der Projektgruppe sein (wie member_task_save).
				$pp_user = (int) ( $_POST['pp_user'] ?? 0 );
				$p_pf    = self::member_owned_project( $proj_id );
				if ( $pp_user && $p_pf && ! Groups::is_member( (int) $p_pf->owner_group_id, $pp_user ) ) {
					$result = new \WP_Error( 'pp_not_group_member', __( 'This user is not a member of the project group.', 'project-prepper' ), [ 'status' => 400 ] );
				} else {
					$result = ProfitShares::add( $proj_id, $pp_user, self::profit_input() );
				}
				$ok_msg = 'proj_entry_saved';
				break;
			case 'profit_update':
				$pid2   = (int) ( $_POST['pp_entry'] ?? 0 );
				$result = self::sub_belongs( ProfitShares::get( $pid2 ), $proj_id )
					? ProfitShares::update( $pid2, self::profit_input() )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_saved';
				break;
			case 'profit_remove':
				$pid2   = (int) ( $_POST['pp_entry'] ?? 0 );
				$result = self::sub_belongs( ProfitShares::get( $pid2 ), $proj_id )
					? ProfitShares::remove( $pid2 )
					: self::forbidden_error();
				$ok_msg = 'proj_entry_deleted';
				break;
			case 'project_finance':
				// Budget/Umsatz getrennt von den Stammdaten (Kosten- bzw. Gewinn-Karte).
				$fin = [];
				if ( isset( $_POST['pp_budget'] ) ) {
					$fin['budget_planned'] = self::money_field( 'pp_budget' );
				}
				if ( isset( $_POST['pp_revenue'] ) ) {
					$fin['revenue_actual'] = self::money_field( 'pp_revenue' );
				}
				$result = $fin ? Projects::update( $proj_id, $fin ) : true;
				$ok_msg = 'project_saved';
				break;
			case 'file_detach':
				$fid    = (int) ( $_POST['pp_entry'] ?? 0 );
				$result = self::sub_belongs( Files::get( $fid ), $proj_id )
					? Files::detach( $fid )
					: self::forbidden_error();
				$ok_msg = 'pfile_removed';
				break;
			case 'borrow_request':
				$pp_bitem = (int) ( $_POST['pp_item'] ?? 0 );
				$pp_bfrom = sanitize_text_field( wp_unslash( (string) ( $_POST['pp_from'] ?? '' ) ) );
				$pp_bto   = sanitize_text_field( wp_unslash( (string) ( $_POST['pp_to'] ?? '' ) ) );
				$pp_bmsg  = sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_message'] ?? '' ) ) );
				// Sets laufen über die Expansion in Teil-Anfragen (docs/07 §6). Ob es
				// ein Set ist, entscheidet der Server anhand der Stückliste — nicht
				// ein mitgeschicktes Feld.
				$result = Bundles::is_bundle( $pp_bitem )
					? Borrowing::request_bundle(
						get_current_user_id(),
						$pp_bitem,
						$grp_id,
						$pp_bfrom,
						$pp_bto,
						max( 1, (int) ( $_POST['pp_sets'] ?? 1 ) ),
						$pp_bmsg
					)
					: Borrowing::request( get_current_user_id(), $pp_bitem, $grp_id, $pp_bfrom, $pp_bto, $pp_bmsg );
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
				// 'none' = Stimme entfernen (Klick-Zyklus endet leer, wie die App).
				$vote   = sanitize_key( wp_unslash( (string) ( $_POST['pp_vote'] ?? '' ) ) );
				$result = 'none' === $vote
					? Polls::remove_vote( (int) ( $_POST['pp_option'] ?? 0 ), get_current_user_id() )
					: Polls::cast_vote( (int) ( $_POST['pp_option'] ?? 0 ), get_current_user_id(), $vote );
				$ok_msg = 'none' === $vote ? 'vote_removed' : 'voted';
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
			case 'poll_delete':
				$result = Polls::delete( (int) ( $_POST['pp_poll'] ?? 0 ), get_current_user_id() );
				$ok_msg = 'poll_deleted';
				break;
			case 'gpoll_vote':
				// 'none' = Stimme entfernen (Klick-Zyklus endet leer, wie die App).
				$vote   = sanitize_key( wp_unslash( (string) ( $_POST['pp_vote'] ?? '' ) ) );
				$result = 'none' === $vote
					? Polls::remove_vote( (int) ( $_POST['pp_option'] ?? 0 ), get_current_user_id() )
					: Polls::cast_vote( (int) ( $_POST['pp_option'] ?? 0 ), get_current_user_id(), $vote );
				$ok_msg = 'none' === $vote ? 'vote_removed' : 'voted';
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
			case 'gpoll_delete':
				$result = Polls::delete( (int) ( $_POST['pp_poll'] ?? 0 ), get_current_user_id() );
				$ok_msg = 'poll_deleted';
				break;
			case 'calgroup_create':
				$result = CalendarEvents::create_calendar( get_current_user_id(), self::active_workspace_group(), [
					'name'  => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) ),
					'color' => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_color'] ?? '' ) ) ),
				] );
				$ok_msg = 'calendar_saved';
				break;
			case 'calgroup_update':
				$result = CalendarEvents::update_calendar( (int) ( $_POST['pp_calendar'] ?? 0 ), get_current_user_id(), self::active_workspace_group(), [
					'name'  => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) ),
					'color' => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_color'] ?? '' ) ) ),
				] );
				$ok_msg = 'calendar_saved';
				break;
			case 'calgroup_delete':
				$result = CalendarEvents::delete_calendar( (int) ( $_POST['pp_calendar'] ?? 0 ), get_current_user_id(), self::active_workspace_group() );
				$ok_msg = 'calendar_deleted';
				break;
			case 'event_create':
				$result = CalendarEvents::create_event( get_current_user_id(), self::active_workspace_group(), self::event_input() );
				$ok_msg = 'event_saved';
				break;
			case 'event_update':
				$result = CalendarEvents::update_event( (int) ( $_POST['pp_event'] ?? 0 ), get_current_user_id(), self::active_workspace_group(), self::event_input() );
				$ok_msg = 'event_saved';
				break;
			case 'event_delete':
				$result = CalendarEvents::delete_event( (int) ( $_POST['pp_event'] ?? 0 ), get_current_user_id(), self::active_workspace_group() );
				$ok_msg = 'event_deleted';
				break;
			case 'ical_rotate':
				CalendarController::regenerate_user_token( get_current_user_id() );
				$result = true;
				$ok_msg = 'feed_rotated';
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
			} elseif ( 'pp_invalid_amount' === $code ) {
				$msg = 'invalid_amount';
			} elseif ( 'pp_not_group_member' === $code ) {
				$msg = 'not_group_member';
			} elseif ( 'pp_poll_deadline' === $code ) {
				$msg = 'poll_deadline';
			} elseif ( 'pp_invalid_date' === $code ) {
				$msg = 'invalid_date';
			} elseif ( in_array( $code, [ 'pp_missing_title', 'pp_missing_name', 'pp_missing_label' ], true ) ) {
				$msg = 'missing_required';
			} elseif ( 'pp_no_selection' === $code ) {
				$msg = 'booking_none_selected';
			} elseif ( 'pp_bulk_none' === $code ) {
				$msg = 'booking_bulk_none';
			} elseif ( 'pp_photo_failed' === $code ) {
				$msg = 'photo_failed';
			} elseif ( 'pp_bundle_line' === $code ) {
				$msg = 'bundle_line_locked';
			} elseif ( 'pp_bundle_nested' === $code ) {
				$msg = 'bundle_nested';
			} elseif ( 'pp_bundle_unavailable' === $code ) {
				$msg = 'bundle_unavailable';
			} elseif ( 'pp_bundle_empty' === $code ) {
				$msg = 'bundle_empty';
			} elseif ( 'pp_not_pending' === $code ) {
				$msg = 'booking_decided_already';
			} elseif ( 'pp_telegram_not_configured' === $code ) {
				$msg = 'telegram_not_configured';
			} elseif ( in_array( $code, [ 'pp_telegram_http', 'pp_telegram_api' ], true ) ) {
				$msg = 'telegram_failed';
			} elseif ( 'pp_bad_email' === $code ) {
				$msg = 'invite_bad_email';
			} elseif ( 'pp_already_member' === $code ) {
				$msg = 'invite_already_member';
			} elseif ( 'pp_already_invited' === $code ) {
				$msg = 'invite_already_invited';
			} elseif ( 'pp_invite_limit' === $code ) {
				$msg = 'invite_limit';
			} elseif ( 'pp_not_member' === $code ) {
				$msg = 'invite_not_member';
			} elseif ( 'pp_no_voters' === $code ) {
				$msg = 'voters_all_voted';
			} elseif ( 'pp_forbidden' === $code ) {
				$msg = 'forbidden';
			} else {
				$msg = 'error';
			}
		} else {
			$msg = $ok_msg;
		}
		// Anfrage→Projekt: bei Erfolg direkt zum neuen Projekt springen, sonst
		// zurück zur Anfragen-Ansicht (bzw. ins Detail, wenn von dort gepostet).
		if ( 'inquiry_to_project' === $do ) {
			if ( is_wp_error( $result ) ) {
				$back = add_query_arg( 'pp_view', 'inquiries', self::portal_url() );
				if ( $ref_inquiry > 0 ) {
					$back = add_query_arg( 'pp_inquiry', $ref_inquiry, $back );
				}
			} else {
				$back = add_query_arg( [ 'pp_view' => 'projects', 'pp_project' => (int) $result ], self::portal_url() );
			}
		}
		wp_safe_redirect( add_query_arg( 'pp_msg', $msg, $back ) );
		exit;
	}

	/* ===================== Telegram-Auslöser ===================== */

	/**
	 * Kurzer Portal-Link als Telegram-HTML-Anker. Args → Portal-URL mit
	 * pp_view/pp_project/pp_inquiry; Label übersetzt. & im href wird für den
	 * HTML-Parse-Mode zu &amp; escaped (Telegram fügt es beim Aufruf zusammen).
	 */
	private static function telegram_link( array $args, string $label ): string {
		$url = add_query_arg( $args, self::portal_url() );
		return '<a href="' . Telegram::esc( $url ) . '">' . Telegram::esc( $label ) . '</a>';
	}

	/**
	 * Benachrichtigung: neue Anfrage in einer Gruppe. Feuert NUR, wenn die
	 * besitzende Gruppe Telegram konfiguriert hat (Instanz-Token + chat_id).
	 * Fehler beim Versand sind bewusst folgenlos (Service loggt still).
	 */
	private static function notify_new_inquiry( int $group_id, int $inquiry_id ): void {
		if ( $group_id <= 0 || ! Telegram::is_configured( $group_id ) ) {
			return;
		}
		$inq = Inquiries::get( $inquiry_id );
		if ( ! $inq ) {
			return;
		}
		$name = trim( (string) ( $inq->name ?? '' ) );
		$text = sprintf(
			/* translators: %s: client name of the inquiry (bold). */
			__( 'New inquiry: %s', 'project-prepper' ),
			'<b>' . Telegram::esc( '' !== $name ? $name : __( 'Untitled', 'project-prepper' ) ) . '</b>'
		) . "\n" . self::telegram_link(
			[ 'pp_view' => 'inquiries', 'pp_inquiry' => $inquiry_id ],
			__( 'Open in the portal', 'project-prepper' )
		);
		Telegram::send_to_group( $group_id, $text );
	}

	/**
	 * Benachrichtigung: neue Technik-Buchung in einem Gruppen-Projekt. Feuert
	 * nur bei konfigurierter Gruppe.
	 */
	private static function notify_new_booking( int $group_id, int $project_id ): void {
		if ( $group_id <= 0 || $project_id <= 0 || ! Telegram::is_configured( $group_id ) ) {
			return;
		}
		$project = Projects::get( $project_id );
		if ( ! $project ) {
			return;
		}
		$text = sprintf(
			/* translators: %s: project name (bold). */
			__( 'New equipment booking in project %s', 'project-prepper' ),
			'<b>' . Telegram::esc( (string) $project->name ) . '</b>'
		) . "\n" . self::telegram_link(
			[ 'pp_view' => 'projects', 'pp_project' => $project_id ],
			__( 'Open in the portal', 'project-prepper' )
		);
		Telegram::send_to_group( $group_id, $text );
	}

	/**
	 * Benachrichtigung: ein Mitglied hat für eine Gruppen-Anfrage zugesagt.
	 * Die Gruppe wird aus der Anfrage gelesen (respond hat sie bereits gegated).
	 */
	private static function notify_rsvp_accepted( int $inquiry_id, int $user_id ): void {
		$inq = Inquiries::get( $inquiry_id );
		if ( ! $inq ) {
			return;
		}
		$group_id = (int) ( $inq->owner_group_id ?? 0 );
		if ( $group_id <= 0 || ! Telegram::is_configured( $group_id ) ) {
			return;
		}
		$user  = get_userdata( $user_id );
		$who   = $user ? $user->display_name : sprintf( '#%d', $user_id );
		$title = trim( (string) ( $inq->title ?? '' ) );
		if ( '' === $title ) {
			$title = trim( (string) ( $inq->name ?? '' ) );
		}
		$text = sprintf(
			/* translators: 1: member name, 2: inquiry title (bold). */
			__( '%1$s is available for %2$s', 'project-prepper' ),
			Telegram::esc( $who ),
			'<b>' . Telegram::esc( '' !== $title ? $title : __( 'an inquiry', 'project-prepper' ) ) . '</b>'
		) . "\n" . self::telegram_link(
			[ 'pp_view' => 'inquiries', 'pp_inquiry' => $inquiry_id ],
			__( 'Open in the portal', 'project-prepper' )
		);
		Telegram::send_to_group( $group_id, $text );
	}

	/** Statusmeldungen für ?pp_msg — Code → menschenlesbarer Text. */
	private static function messages(): array {
		return [
			'founded'   => [ 'ok', __( 'Collective founded. You are its founder.', 'project-prepper' ) ],
			'feedback_ok'  => [ 'ok', __( 'Thanks for your feedback!', 'project-prepper' ) ],
			'feedback_err' => [ 'err', __( 'Please enter a message.', 'project-prepper' ) ],
			'invited'   => [ 'ok', __( 'Invitation sent.', 'project-prepper' ) ],
			'invite_bad_email'       => [ 'err', __( 'Please enter a valid email address.', 'project-prepper' ) ],
			'invite_already_member'  => [ 'err', __( 'That person is already a member of this collective.', 'project-prepper' ) ],
			'invite_already_invited' => [ 'err', __( 'There is already an open invitation for this address.', 'project-prepper' ) ],
			'invite_limit'           => [ 'err', __( 'You have reached your invitation limit for today.', 'project-prepper' ) ],
			'invite_not_member'      => [ 'err', __( 'Only members of this collective can invite others.', 'project-prepper' ) ],
			'forbidden'              => [ 'err', __( 'You are not allowed to do this.', 'project-prepper' ) ],
			'accepted'  => [ 'ok', __( 'Invitation accepted.', 'project-prepper' ) ],
			'declined'  => [ 'ok', __( 'Invitation declined.', 'project-prepper' ) ],
			'cancelled' => [ 'ok', __( 'Invitation deleted.', 'project-prepper' ) ],
			'invite_resent' => [ 'ok', __( 'Invitation sent again.', 'project-prepper' ) ],
			'voters_reminded'  => [ 'ok', __( 'Reminder sent to the members who have not voted yet.', 'project-prepper' ) ],
			'voters_all_voted' => [ 'err', __( 'Everyone has already voted — no reminder needed.', 'project-prepper' ) ],
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
			'team_invited'     => [ 'ok', __( 'Availability request sent.', 'project-prepper' ) ],
			'team_revoked'     => [ 'ok', __( 'Availability request withdrawn.', 'project-prepper' ) ],
			'rsvp_saved'       => [ 'ok', __( 'Thanks — your answer has been saved.', 'project-prepper' ) ],
			'vote_removed'     => [ 'ok', __( 'Your vote was removed.', 'project-prepper' ) ],
			'task_accepted'    => [ 'ok', __( 'Task accepted.', 'project-prepper' ) ],
			'task_declined'    => [ 'ok', __( 'Task declined.', 'project-prepper' ) ],
			'rental_saved'     => [ 'ok', __( 'Rental created.', 'project-prepper' ) ],
			'rental_updated'   => [ 'ok', __( 'Rental updated.', 'project-prepper' ) ],
			'rental_status'    => [ 'ok', __( 'Rental status updated.', 'project-prepper' ) ],
			'rental_deleted'   => [ 'ok', __( 'Rental deleted.', 'project-prepper' ) ],
			'rental_unavailable' => [ 'err', __( 'One of the items is not available in that period. Please adjust the dates or quantity.', 'project-prepper' ) ],
			'project_saved'    => [ 'ok', __( 'Project saved.', 'project-prepper' ) ],
			'project_deleted'  => [ 'ok', __( 'Project deleted.', 'project-prepper' ) ],
			'proj_entry_saved'   => [ 'ok', __( 'Entry saved.', 'project-prepper' ) ],
			'proj_entry_deleted' => [ 'ok', __( 'Entry removed.', 'project-prepper' ) ],
			'pfile_saved'        => [ 'ok', __( 'File uploaded.', 'project-prepper' ) ],
			'pfile_removed'      => [ 'ok', __( 'File removed.', 'project-prepper' ) ],
			'pfile_failed'       => [ 'err', __( 'The file could not be uploaded. Please use a PDF or image file.', 'project-prepper' ) ],
			'invalid_amount'     => [ 'err', __( 'Invalid amount. Please enter a non-negative number.', 'project-prepper' ) ],
			'missing_required'   => [ 'err', __( 'Please fill in the required field.', 'project-prepper' ) ],
			'not_group_member'   => [ 'err', __( 'This user is not a member of the project group.', 'project-prepper' ) ],
			'booking_saved'    => [ 'ok', __( 'Equipment booked.', 'project-prepper' ) ],
			'booking_partial'  => [ 'ok', __( 'Booked what was available — some items were already taken in this period.', 'project-prepper' ) ],
			'booking_pending'  => [ 'ok', __( 'Booked. Some items need the owner’s approval and are marked as pending.', 'project-prepper' ) ],
			'booking_none_selected' => [ 'err', __( 'Please tick at least one item to book.', 'project-prepper' ) ],
			'booking_removed'  => [ 'ok', __( 'Booking removed.', 'project-prepper' ) ],
			'packlist_packed'   => [ 'ok', __( 'Marked as packed.', 'project-prepper' ) ],
			'packlist_unpacked' => [ 'ok', __( 'Marked as not packed.', 'project-prepper' ) ],
			'packlist_tested'   => [ 'ok', __( 'Marked as tested.', 'project-prepper' ) ],
			'packlist_untested' => [ 'ok', __( 'Marked as not tested.', 'project-prepper' ) ],
			'booking_reapproval' => [ 'ok', __( 'Change saved — it needs the owner’s approval again and is marked as pending.', 'project-prepper' ) ],
			'booking_approved' => [ 'ok', __( 'Booking approved.', 'project-prepper' ) ],
			'booking_rejected' => [ 'ok', __( 'Booking rejected and removed.', 'project-prepper' ) ],
			'booking_decided_already' => [ 'err', __( 'This request has already been decided.', 'project-prepper' ) ],
			'booking_bulk_saved' => [ 'ok', __( 'Decisions saved. Each requester was notified with one email.', 'project-prepper' ) ],
			'booking_bulk_none'  => [ 'err', __( 'Please choose Approve or Reject for at least one request.', 'project-prepper' ) ],
			'bundle_line_locked' => [ 'err', __( 'This line belongs to a set. Change or remove the whole set instead.', 'project-prepper' ) ],
			'bundle_nested'      => [ 'err', __( 'Sets cannot contain other sets.', 'project-prepper' ) ],
			'bundle_unavailable' => [ 'err', __( 'Not enough complete sets are free in this period — some parts are already taken.', 'project-prepper' ) ],
			'bundle_empty'       => [ 'err', __( 'This set has no parts (any more). Please check its contents.', 'project-prepper' ) ],
			'borrow_requested' => [ 'ok', __( 'Borrow request sent to the owner.', 'project-prepper' ) ],
			'borrow_decided'   => [ 'ok', __( 'Request updated.', 'project-prepper' ) ],
			'borrow_cancelled' => [ 'ok', __( 'Request cancelled.', 'project-prepper' ) ],
			'borrow_returned'  => [ 'ok', __( 'Marked as returned.', 'project-prepper' ) ],
			'decision_created' => [ 'ok', __( 'Decision created.', 'project-prepper' ) ],
			'decision_closed'  => [ 'ok', __( 'Decision closed.', 'project-prepper' ) ],
			'poll_created'     => [ 'ok', __( 'Poll created.', 'project-prepper' ) ],
			'poll_closed'      => [ 'ok', __( 'Poll closed.', 'project-prepper' ) ],
			'poll_reopened'    => [ 'ok', __( 'Poll reopened.', 'project-prepper' ) ],
			'poll_deleted'     => [ 'ok', __( 'Poll deleted.', 'project-prepper' ) ],
			'poll_deadline'    => [ 'err', __( 'The deadline for this poll has passed.', 'project-prepper' ) ],
			'invalid_date'     => [ 'err', __( 'Please enter a valid date.', 'project-prepper' ) ],
			'event_saved'      => [ 'ok', __( 'Event saved.', 'project-prepper' ) ],
			'event_deleted'    => [ 'ok', __( 'Event deleted.', 'project-prepper' ) ],
			'calendar_saved'   => [ 'ok', __( 'Calendar saved.', 'project-prepper' ) ],
			'calendar_deleted' => [ 'ok', __( 'Calendar deleted. Its events were kept.', 'project-prepper' ) ],
			'feed_rotated'     => [ 'ok', __( 'New feed URL created. Please update your calendar subscriptions.', 'project-prepper' ) ],
			'group_saved'      => [ 'ok', __( 'Collective updated.', 'project-prepper' ) ],
			'telegram_sent'          => [ 'ok', __( 'Test message sent to your Telegram group.', 'project-prepper' ) ],
			'telegram_failed'        => [ 'err', __( 'Telegram message could not be sent. Please check the chat ID and that the operator’s bot is in the group.', 'project-prepper' ) ],
			'telegram_not_configured' => [ 'err', __( 'Telegram is not set up yet. Add a chat ID (and ask the operator to set a bot token).', 'project-prepper' ) ],
			'member_removed'   => [ 'ok', __( 'Member removed from the collective.', 'project-prepper' ) ],
			'group_deleted'    => [ 'ok', __( 'Collective dissolved. Its projects were kept and moved to the site level.', 'project-prepper' ) ],
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
			'logo_saved'       => [ 'ok', __( 'Collective logo saved.', 'project-prepper' ) ],
			'logo_removed'     => [ 'ok', __( 'Collective logo removed.', 'project-prepper' ) ],
			'logo_failed'      => [ 'err', __( 'The logo could not be uploaded. Please choose a JPG, PNG, GIF or WebP file.', 'project-prepper' ) ],
			'doc_saved'        => [ 'ok', __( 'Document uploaded.', 'project-prepper' ) ],
			'doc_removed'      => [ 'ok', __( 'Document removed.', 'project-prepper' ) ],
			'doc_failed'       => [ 'err', __( 'The document could not be uploaded. Please use a PDF or image file.', 'project-prepper' ) ],
			'borrow_unavailable' => [ 'err', __( 'No units of this item are free in that period. Please pick other dates.', 'project-prepper' ) ],
			'group_left'         => [ 'ok', __( 'You have left the group.', 'project-prepper' ) ],
			'welcome_joined'     => [ 'ok', __( 'Welcome! Your account is ready and you are now a member of the collective.', 'project-prepper' ) ],
			'welcome_voting'     => [ 'ok', __( 'Welcome! Your account is ready. The members of the collective now vote on your admission — you will be unlocked as soon as everyone approves.', 'project-prepper' ) ],
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
	 * Kollektiv-Freigaben aus dem vereinten Artikel-Formular anwenden: je Gruppe
	 * des Users teilen/aktualisieren (Checkbox an) oder die Freigabe zurückziehen
	 * (Checkbox aus, war aber geteilt). Feldnamen siehe item_share_fields();
	 * Eigentums-/Mitgliedschafts-Gates liegen in set_share()/unshare().
	 */
	private static function apply_share_input( int $user_id, int $item_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		$on    = is_array( $_POST['pp_share_on'] ?? null ) ? $_POST['pp_share_on'] : [];
		$rate  = is_array( $_POST['pp_share_rate'] ?? null ) ? wp_unslash( $_POST['pp_share_rate'] ) : [];
		$appr  = is_array( $_POST['pp_share_approval'] ?? null ) ? $_POST['pp_share_approval'] : [];
		$cond  = is_array( $_POST['pp_share_cond'] ?? null ) ? wp_unslash( $_POST['pp_share_cond'] ) : [];
		$notes = is_array( $_POST['pp_share_notes'] ?? null ) ? wp_unslash( $_POST['pp_share_notes'] ) : [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$shared_now = MemberInventory::shared_group_ids( $item_id );
		foreach ( Groups::user_groups( $user_id ) as $g ) {
			$gid = (int) $g->id;
			if ( ! empty( $on[ $gid ] ) ) {
				MemberInventory::set_share( $user_id, $item_id, $gid, [
					'daily_rate'        => isset( $rate[ $gid ] ) ? (string) $rate[ $gid ] : null,
					'requires_approval' => ! empty( $appr[ $gid ] ),
					'conditions_tags'   => array_map( 'sanitize_key', (array) ( $cond[ $gid ] ?? [] ) ),
					'conditions'        => (string) ( $notes[ $gid ] ?? '' ),
				] );
			} elseif ( in_array( $gid, $shared_now, true ) ) {
				MemberInventory::unshare( $user_id, $item_id, $gid );
			}
		}
	}

	/**
	 * Foto-Feld des vereinten Artikel-Formulars verarbeiten: „Foto entfernen"-
	 * Checkbox und/oder neuer Upload (ersetzt das bisherige Bild). Nichts
	 * angegeben → true ohne Änderung. Upload-Fehler → WP_Error pp_photo_failed
	 * (die übrigen Formulardaten sind zu diesem Zeitpunkt bereits gespeichert).
	 *
	 * @return true|\WP_Error
	 */
	private static function process_item_photo_input( int $user_id, int $item_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		if ( ! empty( $_POST['pp_photo_remove'] ) ) {
			MemberInventory::set_image( $user_id, $item_id, null );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( empty( $_FILES['pp_photo']['tmp_name'] ) || ! is_uploaded_file( $_FILES['pp_photo']['tmp_name'] ) ) {
			return true;
		}
		$attach_id = self::create_photo_attachment();
		if ( is_wp_error( $attach_id ) ) {
			return $attach_id;
		}
		MemberInventory::set_image( $user_id, $item_id, (int) $attach_id );
		return true;
	}

	/**
	 * Hochgeladenes pp_photo als Attachment ablegen (Bild-MIME-Whitelist).
	 * Gemeinsame Kernlogik für handle_inventory_photo() und das vereinte
	 * Artikel-Formular (item_create / item_save_all).
	 *
	 * @return int|\WP_Error Attachment-ID.
	 */
	private static function create_photo_attachment() {
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
		$failed = new \WP_Error( 'pp_photo_failed', __( 'The image could not be uploaded. Please use a JPG, PNG, GIF or WebP file.', 'project-prepper' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- $_FILES wird von wp_handle_upload validiert (mimes-Whitelist).
		$moved = wp_handle_upload( $_FILES['pp_photo'], $overrides );
		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			return $failed;
		}
		$attach_id = wp_insert_attachment( [
			'post_mime_type' => $moved['type'],
			'post_title'     => sanitize_file_name( wp_basename( $moved['file'] ) ),
			'post_status'    => 'inherit',
		], $moved['file'] );
		if ( ! $attach_id || is_wp_error( $attach_id ) ) {
			return $failed;
		}
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( (int) $attach_id, $moved['file'] ) );
		return (int) $attach_id;
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
			'deadline'    => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_deadline'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/** Termin-Eingaben (Kalender) — Nonce im Dispatcher geprüft. */
	private static function event_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		return [
			'title'             => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_title'] ?? '' ) ) ),
			'calendar_group_id' => (int) ( $_POST['pp_calendar'] ?? 0 ),
			'date_from'         => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_from'] ?? '' ) ) ),
			'date_to'           => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_to'] ?? '' ) ) ),
			'time_start'        => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_time_start'] ?? '' ) ) ),
			'time_end'          => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_time_end'] ?? '' ) ) ),
			'location'          => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_location'] ?? '' ) ) ),
			'description'       => sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_description'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/** Ist der User Gründer der Gruppe? (für group_update) */
	private static function is_group_founder( int $group_id, int $user_id ): bool {
		if ( ! $group_id || ! $user_id ) {
			return false;
		}
		foreach ( Groups::user_groups( $user_id ) as $g ) {
			if ( (int) $g->id === $group_id ) {
				return 'founder' === $g->member_role;
			}
		}
		return false;
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
			'noinvite' => __( 'No open invitation was found for this email address. Please use exactly the address your invitation was sent to.', 'project-prepper' ),
			'failed'   => __( 'Registration failed. Please try again.', 'project-prepper' ),
		];

		// Beitritts-Link aus der Einladungs-Mail (?pp_join=<id>&pp_key=<token>):
		// öffnet die Registrierung mit verifizierter, vorausgefüllter Adresse.
		$join_state = '';
		$join_inv   = null;
		$join_id    = isset( $_GET['pp_join'] ) ? absint( $_GET['pp_join'] ) : 0;
		$join_key   = isset( $_GET['pp_key'] ) ? sanitize_text_field( wp_unslash( $_GET['pp_key'] ) ) : '';
		if ( $join_id ) {
			$inv = Governance::get_by_token( $join_id, $join_key );
			if ( ! $inv ) {
				$join_state = 'invalid';
			} elseif ( 'pending' !== $inv->status ) {
				$join_state = 'resolved';
			} elseif ( email_exists( (string) $inv->invited_email ) ) {
				$join_state = 'has_account';
			} else {
				$join_state = 'register';
				$join_inv   = $inv;
			}
		}
		$join_group = $join_inv ? Groups::get( (int) $join_inv->group_id ) : null;
		$join_by    = $join_inv && $join_inv->invited_by ? get_userdata( (int) $join_inv->invited_by ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		ob_start();
		?>
		<div class="pp-front pp-portal pp-portal--login">
			<h2 class="pp-portal__title"><?php echo esc_html( 'register' === $join_state ? __( 'Join the collective', 'project-prepper' ) : __( 'Member login', 'project-prepper' ) ); ?></h2>

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

			<?php if ( 'invalid' === $join_state ) : ?>
				<div class="pp-portal__notice pp-portal__notice--err"><?php esc_html_e( 'This invitation link is not valid. Please ask for a new invitation.', 'project-prepper' ); ?></div>
			<?php elseif ( 'resolved' === $join_state ) : ?>
				<div class="pp-portal__notice pp-portal__notice--err"><?php esc_html_e( 'This invitation has already been answered. You can sign in normally below.', 'project-prepper' ); ?></div>
			<?php elseif ( 'has_account' === $join_state ) : ?>
				<div class="pp-portal__notice pp-portal__notice--ok"><?php esc_html_e( 'You already have an account with the invited email address. Sign in to accept the invitation.', 'project-prepper' ); ?></div>
			<?php elseif ( 'register' === $join_state ) : /* ---- Einladungs-Registrierung (Token-Link) ---- */ ?>
				<p class="pp-portal__lead">
					<?php
					printf(
						/* translators: 1: inviter name, 2: collective name. */
						esc_html__( '%1$s has invited you to join the collective “%2$s”. Choose a password to create your account and accept the invitation.', 'project-prepper' ),
						esc_html( $join_by ? $join_by->display_name : get_bloginfo( 'name' ) ),
						esc_html( $join_group ? $join_group->name : '' )
					);
					?>
				</p>
				<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pp_member_register">
					<?php wp_nonce_field( 'pp_member_register', 'pp_nonce' ); ?>
					<input type="hidden" name="pp_invite" value="<?php echo (int) $join_inv->id; ?>">
					<input type="hidden" name="pp_key" value="<?php echo esc_attr( $join_key ); ?>">
					<label><?php esc_html_e( 'Email', 'project-prepper' ); ?>
						<input type="email" value="<?php echo esc_attr( (string) $join_inv->invited_email ); ?>" readonly>
					</label>
					<label><?php esc_html_e( 'Name', 'project-prepper' ); ?>
						<input type="text" name="pp_name" required>
					</label>
					<label><?php esc_html_e( 'Password (min. 8 characters)', 'project-prepper' ); ?>
						<input type="password" name="pp_password" minlength="8" required>
					</label>
					<input type="text" name="pp_website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;">
					<button type="submit" class="pp-portal__btn"><?php esc_html_e( 'Create account', 'project-prepper' ); ?></button>
				</form>
				<hr style="margin:1.5rem 0;border:none;border-top:1px solid var(--pp-border);">
				<p class="pp-portal__note"><?php esc_html_e( 'Already have an account? Sign in below.', 'project-prepper' ); ?></p>
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

			<?php if ( $can_register && ! $pending && 'register' !== $join_state ) : /* ---- Selbst-Registrierung (Schalter an) ---- */ ?>
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
			<?php elseif ( ! $can_register && ! $pending && 'register' !== $join_state ) : /* ---- Einladungs-Registrierung (manuell, invite-only) ---- */ ?>
				<hr style="margin:1.5rem 0;border:none;border-top:1px solid var(--pp-border);">
				<details class="pp-portal__add"<?php echo '' !== $reg_msg ? ' open' : ''; ?>>
					<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Received an invitation? Create your account', 'project-prepper' ); ?></summary>
					<p class="pp-portal__note" style="margin-top:.75rem;"><?php esc_html_e( 'Use exactly the email address your invitation was sent to.', 'project-prepper' ); ?></p>
					<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.75rem;">
						<input type="hidden" name="action" value="pp_member_register">
						<?php wp_nonce_field( 'pp_member_register', 'pp_nonce' ); ?>
						<label><?php esc_html_e( 'Email', 'project-prepper' ); ?>
							<input type="email" name="pp_email" required>
						</label>
						<label><?php esc_html_e( 'Name', 'project-prepper' ); ?>
							<input type="text" name="pp_name" required>
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
					<?php esc_html_e( 'Access is by invitation only.', 'project-prepper' ); ?>
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
							case 'approvals':
								self::view_approvals( $user );
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
		$allowed = [ 'dashboard', 'inventory', 'lending', 'projects', 'inquiries', 'calendar', 'costs', 'polls', 'network', 'collectives', 'approvals' ];
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
			$active_logo  = null;
			foreach ( $groups as $g ) {
				if ( (int) $g->id === $active ) {
					$active_label = $g->name;
					$active_logo  = self::group_logo_url( (int) ( $g->logo_id ?? 0 ) );
					break;
				}
			}
			?>
			<details class="pp-app__ws">
				<summary class="pp-app__workspace">
					<?php if ( $active_logo ) : ?>
						<img class="pp-app__ws-logo" src="<?php echo esc_url( $active_logo ); ?>" alt="">
					<?php endif; ?>
					<span class="pp-app__ws-text">
						<span class="pp-app__workspace-label"><?php esc_html_e( 'Workspace', 'project-prepper' ); ?></span>
						<span class="pp-app__workspace-name"><?php echo esc_html( $active_label ); ?></span>
					</span>
					<span class="pp-app__ws-caret">▾</span>
				</summary>
				<div class="pp-app__ws-menu">
					<?php
					$ws_options = [ [ 'ws' => 'solo', 'label' => __( 'Solo', 'project-prepper' ), 'is' => ( 0 === $active ), 'logo' => null ] ];
					foreach ( $groups as $g ) {
						$ws_options[] = [
							'ws'    => (string) (int) $g->id,
							'label' => $g->name,
							'is'    => ( (int) $g->id === $active ),
							'logo'  => self::group_logo_url( (int) ( $g->logo_id ?? 0 ) ),
						];
					}
					foreach ( $ws_options as $opt ) :
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php self::action_fields( 'set_workspace' ); ?>
							<input type="hidden" name="pp_ws" value="<?php echo esc_attr( $opt['ws'] ); ?>">
							<input type="hidden" name="pp_view" value="<?php echo esc_attr( $view ); ?>">
							<button type="submit" class="pp-app__ws-opt<?php echo $opt['is'] ? ' is-active' : ''; ?>">
								<?php if ( $opt['logo'] ) : ?>
									<img class="pp-app__ws-logo" src="<?php echo esc_url( $opt['logo'] ); ?>" alt="">
								<?php endif; ?>
								<?php echo esc_html( $opt['label'] ); ?>
							</button>
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
						'url'   => add_query_arg( [ 'pp_view' => 'collectives', 'pp_group' => (int) $gid ], self::portal_url() ),
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

		// Offene Aufgaben-Zuweisungen an mich (Annehmen/Ablehnen steht aus).
		$ptasks = count( Tasks::pending_for_user( $uid ) );
		if ( $ptasks > 0 ) {
			$items[] = [
				/* translators: %d: number of pending task assignments. */
				'label' => sprintf( _n( '%d task assignment awaiting your response', '%d task assignments awaiting your response', $ptasks, 'project-prepper' ), $ptasks ),
				'url'   => self::view_url( 'projects' ),
			];
		}

		// Offene Freigabe-Anfragen für meine Artikel (Technik-Buchungen).
		$approvals = BookingApprovals::pending_count_for_owner( $uid );
		if ( $approvals > 0 ) {
			$items[] = [
				/* translators: %d: number of equipment approval requests. */
				'label' => sprintf( _n( '%d equipment approval awaiting you', '%d equipment approvals awaiting you', $approvals, 'project-prepper' ), $approvals ),
				'url'   => self::view_url( 'approvals' ),
			];
		}

		// Offene „Bist du dabei?"-Anfragen (Team-RSVP bei Anfragen).
		$rsvps = count( InquiryTeam::pending_for_user( $uid ) );
		if ( $rsvps > 0 ) {
			$items[] = [
				/* translators: %d: number of open availability requests. */
				'label' => sprintf( _n( '%d inquiry asks: are you in?', '%d inquiries ask: are you in?', $rsvps, 'project-prepper' ), $rsvps ),
				'url'   => self::view_url( 'inquiries' ),
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
		// Arbeitsbereich-bewusst wie die App (ownerFilter): im Gruppen-Modus
		// zählt die Inventar-Kachel den geteilten Gruppen-Pool, sonst das eigene.
		$ws_group   = self::active_group_id( $groups );
		$inv_count  = $ws_group > 0
			? count( Inventory::items( [ 'shared_with_group' => $ws_group ] ) )
			: count( MemberInventory::my_items( (int) $user->ID ) );
		$grp_count  = count( $groups );
		$proj_count = count( self::member_projects( $groups ) );
		$inq_count  = MemberInquiries::count_for_owner( (int) $user->ID, self::active_group_id( $groups ) );
		$incoming   = Borrowing::incoming_requests( (int) $user->ID );
		$open_reqs  = count( array_filter( $incoming, static fn( $r ) => 'requested' === $r->status ) );
		$rent_kpis  = MemberRentals::kpis( (int) $user->ID );
		$rent_out   = (int) $rent_kpis['reserved'] + (int) $rent_kpis['active'];
		// Presence: verschiedene gerade online befindliche Mitglieder über alle
		// eigenen Kollektive (ein Batch-Query über die Vereinigungsmenge).
		$grp_member_ids = [];
		foreach ( $groups as $g ) {
			foreach ( Groups::members( (int) $g->id ) as $gm ) {
				$grp_member_ids[ (int) $gm->user_id ] = true;
			}
		}
		$members_online = count( Presence::online_user_ids( array_keys( $grp_member_ids ) ) );
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

		<?php
		// Kleiner Hinweis auf offene Aufgaben-Zuweisungen (Annehmen/Ablehnen
		// steht aus) — App: task_notifications.
		$pending_tasks = Tasks::pending_for_user( (int) $user->ID );
		if ( $pending_tasks ) :
			?>
			<section class="pp-card pp-taskhint">
				<h3 class="pp-card__title">
					<?php esc_html_e( 'Task assignments', 'project-prepper' ); ?>
					<span class="pp-team-chip pp-team-chip--invited"><?php echo (int) count( $pending_tasks ); ?></span>
				</h3>
				<div class="pp-rows">
					<?php foreach ( $pending_tasks as $pt ) : ?>
						<a class="pp-row pp-row--link" href="<?php echo esc_url( add_query_arg( [ 'pp_view' => 'projects', 'pp_project' => (int) $pt->project_id, 'pp_tab' => 'tasks' ], self::portal_url() ) ); ?>">
							<span class="pp-row__main"><?php echo esc_html( (string) $pt->title ); ?></span>
							<span class="pp-row__meta"><?php echo esc_html( (string) $pt->project_name ); ?></span>
							<span class="pp-portal__chip"><?php esc_html_e( 'Accept or decline', 'project-prepper' ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php
		// Offene Freigabe-Anfragen für MEINE Artikel (App: use-booking-approvals).
		$pending_approvals = BookingApprovals::pending_for_owner( (int) $user->ID );
		if ( $pending_approvals ) :
			?>
			<section class="pp-card pp-taskhint">
				<h3 class="pp-card__title">
					<?php esc_html_e( 'Equipment approvals', 'project-prepper' ); ?>
					<span class="pp-team-chip pp-team-chip--invited"><?php echo (int) count( $pending_approvals ); ?></span>
				</h3>
				<div class="pp-rows">
					<?php foreach ( $pending_approvals as $pa ) : ?>
						<a class="pp-row pp-row--link" href="<?php echo esc_url( self::view_url( 'approvals' ) ); ?>">
							<span class="pp-row__main"><?php echo esc_html( (string) $pa->item_name ); ?></span>
							<span class="pp-row__meta">
								<?php
								echo esc_html( (string) $pa->project_name );
								if ( '' !== (string) $pa->requester_name ) {
									echo ' · ' . esc_html( (string) $pa->requester_name );
								}
								?>
							</span>
							<span class="pp-portal__chip"><?php esc_html_e( 'Approve or reject', 'project-prepper' ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<section class="pp-app__section">
			<div class="pp-app__section-head">
				<h2 class="pp-portal__subtitle">
					<?php esc_html_e( 'Your collectives', 'project-prepper' ); ?>
					<?php if ( $members_online > 0 ) : ?>
						<span class="pp-portal__online-count">
							<span class="pp-portal__online-dot" aria-hidden="true"></span>
							<?php
							/* translators: %d: number of collective members currently online. */
							echo esc_html( sprintf( _n( '%d member online', '%d members online', $members_online, 'project-prepper' ), $members_online ) );
							?>
						</span>
					<?php endif; ?>
				</h2>
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Navigation
		$cat  = isset( $_GET['pp_cat'] ) ? (int) $_GET['pp_cat'] : 0;
		// Zeitraum-Filter (v0.41.0, aus dem entfallenen „Stöbern"-Reiter übernommen):
		// ohne Zeitraum zeigt „Verfügbar" den heutigen Stand aus dem gemeinsamen
		// out_now-Zähler (eine Abfrage). MIT Zeitraum wird je Artikel exakt
		// gerechnet (Availability) und das Leih-Formular ist vorbelegt.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reiner Anzeige-Filter ohne Schreibvorgang.
		$pf   = isset( $_GET['pp_bfrom'] ) ? sanitize_text_field( wp_unslash( $_GET['pp_bfrom'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reiner Anzeige-Filter ohne Schreibvorgang.
		$pt   = isset( $_GET['pp_bto'] ) ? sanitize_text_field( wp_unslash( $_GET['pp_bto'] ) ) : '';
		$period_ok = self::is_ymd( $pf ) && self::is_ymd( $pt ) && $pf <= $pt;
		$args = [ 'shared_with_group' => $group_id ];
		if ( '' !== trim( $q ) ) {
			$args['search'] = trim( $q );
		}
		$all_items    = Inventory::items( $args );
		$conditions   = Shortcodes::condition_labels();
		$uid          = (int) $user->ID;
		$base_url     = self::portal_url();
		// KPI + Kategorie-Filter-Pills wie „Mein Inventar": Zählung über alle
		// (such-gefilterten) Artikel, die Tabelle zeigt die gewählte Kategorie.
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
		$items = $cat ? array_values( array_filter( $all_items, static function ( $it ) use ( $cat ) {
			return (int) ( $it->category_id ?? 0 ) === $cat;
		} ) ) : $all_items;
		// Sets (docs/07): Stücklisten + Teil-Bestände (inkl. out_now) für die
		// berechneten Set-Zahlen — Teile sind hier nicht zwingend selbst geteilt.
		$pp_bundles  = Bundles::for_items( array_map( static fn( $it ) => (int) $it->id, $all_items ) );
		$pp_part_ids = [];
		foreach ( $pp_bundles as $pp_bparts ) {
			foreach ( $pp_bparts as $pp_bp ) {
				$pp_part_ids[] = (int) $pp_bp->part_item_id;
			}
		}
		$pp_part_by_id = [];
		if ( $pp_part_ids ) {
			foreach ( Inventory::items( [ 'ids' => array_values( array_unique( $pp_part_ids ) ) ] ) as $pp_pi ) {
				$pp_part_by_id[ (int) $pp_pi->id ] = $pp_pi;
			}
		}
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title">
				<?php /* translators: %s: group name. */ printf( esc_html__( 'Inventory of %s', 'project-prepper' ), esc_html( $name ) ); ?>
			</h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Equipment that members have shared with this group.', 'project-prepper' ); ?></p>
		</header>

		<section class="pp-portal__section pp-ginv" data-pp-live-scope>
			<?php if ( ! $all_items && '' === $q ) : ?>
				<p class="pp-portal__empty">
					<?php esc_html_e( 'Nothing shared with this group yet. Members add equipment from their own inventory: switch your workspace to “Solo”, open “My inventory”, and use the share buttons on an item.', 'project-prepper' ); ?>
				</p>
			<?php else : ?>
				<form class="pp-inv-search" method="get" data-pp-live>
					<input type="hidden" name="pp_view" value="inventory">
					<input type="search" name="pp_q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Search this group’s inventory …', 'project-prepper' ); ?>">
					<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Search', 'project-prepper' ); ?></button>
				</form>

				<form class="pp-browse-period" method="get">
					<input type="hidden" name="pp_view" value="inventory">
					<?php if ( '' !== trim( $q ) ) : ?>
						<input type="hidden" name="pp_q" value="<?php echo esc_attr( $q ); ?>">
					<?php endif; ?>
					<?php if ( $cat ) : ?>
						<input type="hidden" name="pp_cat" value="<?php echo (int) $cat; ?>">
					<?php endif; ?>
					<label><?php esc_html_e( 'From', 'project-prepper' ); ?>
						<input type="date" name="pp_bfrom" value="<?php echo esc_attr( $pf ); ?>">
					</label>
					<label><?php esc_html_e( 'To', 'project-prepper' ); ?>
						<input type="date" name="pp_bto" value="<?php echo esc_attr( $pt ); ?>">
					</label>
					<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Check availability', 'project-prepper' ); ?></button>
					<?php if ( $period_ok ) : ?>
						<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" href="<?php echo esc_url( add_query_arg( array_filter( [ 'pp_view' => 'inventory', 'pp_q' => $q, 'pp_cat' => $cat ] ), $base_url ) ); ?>"><?php esc_html_e( 'Reset', 'project-prepper' ); ?></a>
					<?php endif; ?>
				</form>

				<?php if ( $all_items ) : ?>
					<p class="pp-inv-kpi">
						<?php
						$pp_dv = ( (float) $total_value === floor( (float) $total_value ) ) ? number_format_i18n( $total_value, 0 ) : number_format_i18n( $total_value, 2 );
						/* translators: 1: item count, 2: total pieces, 3: total daily value. */
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
						<span class="pp-col pp-col--owner"><?php esc_html_e( 'Owner', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--c"><?php esc_html_e( 'Quantity', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--c"><?php esc_html_e( 'Available', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--cond"><?php esc_html_e( 'Condition', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--r">€/<?php echo esc_html__( 'day', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--loc"><?php esc_html_e( 'Location', 'project-prepper' ); ?></span>
						<span class="pp-col pp-col--act"></span>
					</div>
					<?php
					foreach ( $items as $item ) :
						$is_mine  = ( (int) ( $item->owner_user_id ?? 0 ) === $uid );
						$owner    = $is_mine ? null : get_userdata( (int) ( $item->owner_user_id ?? 0 ) );
						$owner_lb = $is_mine ? __( 'You', 'project-prepper' ) : ( $owner ? $owner->display_name : '—' );
						// Set (docs/07): Menge/Verfügbar = berechnete Set-Zahlen aus den Teilen.
						$pp_parts = $pp_bundles[ (int) $item->id ] ?? [];
						if ( $pp_parts ) {
							$pp_qty_col = PHP_INT_MAX;
							$pp_avail   = PHP_INT_MAX;
							foreach ( $pp_parts as $pp_p ) {
								$pp_need    = max( 1, (int) $pp_p->quantity );
								$pp_pi      = $pp_part_by_id[ (int) $pp_p->part_item_id ] ?? null;
								$pp_pqty    = $pp_pi ? (int) $pp_pi->quantity : (int) ( $pp_p->part_total ?? 0 );
								$pp_pout    = $pp_pi ? (int) ( $pp_pi->out_now ?? 0 ) : 0;
								$pp_qty_col = min( $pp_qty_col, (int) floor( $pp_pqty / $pp_need ) );
								$pp_avail   = min( $pp_avail, (int) floor( max( 0, $pp_pqty - $pp_pout ) / $pp_need ) );
							}
							$pp_qty_col = PHP_INT_MAX === $pp_qty_col ? 0 : $pp_qty_col;
							$pp_avail   = PHP_INT_MAX === $pp_avail ? 0 : $pp_avail;
							$pp_sub     = Bundles::parts_label( $pp_parts );
						} else {
							$pp_qty_col = (int) $item->quantity;
							$pp_avail   = (int) max( 0, (int) $item->quantity - (int) ( $item->out_now ?? 0 ) );
							$pp_sub     = $item->model ?: ( $item->description ?? '' );
						}
						// Mit gewähltem Zeitraum exakt für DIESE Tage rechnen (statt „heute").
						if ( $period_ok ) {
							$pp_avail = $pp_parts
								? Borrowing::available_sets( $pp_parts, $pf, $pt )
								: Borrowing::available_units( (int) $item->id, $pf, $pt );
						}
						?>
						<div data-pp-search-row>
						<div class="pp-inv-row pp-ginv__row" data-pp-searchable>
							<span class="pp-col pp-col--name">
								<?php if ( ! empty( $item->image_url ) ) : ?><img class="pp-portal__item-thumb" src="<?php echo esc_url( $item->image_url ); ?>" alt="" loading="lazy"><?php else : ?><span class="pp-portal__item-thumb pp-portal__item-thumb--empty" aria-hidden="true"></span><?php endif; ?>
								<span class="pp-inv-name-wrap"><span class="pp-inv-name-top"><span class="pp-portal__group-name"><?php echo esc_html( $item->name ); ?></span> <?php if ( $pp_parts ) : ?><span class="pp-bundle-chip"><?php esc_html_e( 'Set', 'project-prepper' ); ?></span> <?php endif; ?><small class="pp-portal__item-num"><?php echo esc_html( $item->inventory_number ); ?></small></span><?php if ( '' !== trim( (string) $pp_sub ) ) : ?><small class="pp-inv-name-sub"><?php echo esc_html( (string) $pp_sub ); ?></small><?php endif; ?></span>
							</span>
							<span class="pp-col pp-col--cat" data-label="<?php esc_attr_e( 'Category', 'project-prepper' ); ?>"><?php echo $item->category_name ? esc_html( trim( ( $item->category_icon ? $item->category_icon . ' ' : '' ) . (string) $item->category_name ) ) : '—'; ?></span>
							<span class="pp-col pp-col--owner" data-label="<?php esc_attr_e( 'Owner', 'project-prepper' ); ?>"><?php echo esc_html( $owner_lb ); ?></span>
							<span class="pp-col pp-col--c" data-label="<?php esc_attr_e( 'Quantity', 'project-prepper' ); ?>"><?php echo (int) $pp_qty_col; ?></span>
							<span class="pp-col pp-col--c" data-label="<?php esc_attr_e( 'Available', 'project-prepper' ); ?>"><?php echo (int) $pp_avail; ?></span>
							<span class="pp-col pp-col--cond" data-label="<?php esc_attr_e( 'Condition', 'project-prepper' ); ?>"><?php echo esc_html( $conditions[ $item->item_condition ] ?? $item->item_condition ); ?></span>
							<span class="pp-col pp-col--r" data-label="€/<?php echo esc_attr__( 'day', 'project-prepper' ); ?>"><?php echo ( null !== $item->cost_per_day && '' !== $item->cost_per_day ) ? esc_html( number_format_i18n( (float) $item->cost_per_day, 2 ) . ' €' ) : '—'; ?></span>
							<span class="pp-col pp-col--loc" data-label="<?php esc_attr_e( 'Location', 'project-prepper' ); ?>"><?php echo ! empty( $item->location ) ? esc_html( (string) $item->location ) : '—'; ?></span>
							<span class="pp-col pp-col--act">
								<?php if ( $is_mine ) : ?>
									<span class="pp-portal__tag pp-portal__tag--muted"><?php esc_html_e( 'Yours', 'project-prepper' ); ?></span>
								<?php else : ?>
									<button type="button" class="pp-manage-btn" data-pp-modal="pp-borrow-<?php echo (int) $group_id; ?>-<?php echo (int) $item->id; ?>"><?php esc_html_e( 'Borrow', 'project-prepper' ); ?></button>
								<?php endif; ?>
							</span>
						</div>
						<?php if ( ! $is_mine ) : ?>
							<dialog class="pp-modal pp-modal--portal" id="pp-borrow-<?php echo (int) $group_id; ?>-<?php echo (int) $item->id; ?>">
								<div class="pp-modal-header">
									<h2 class="pp-modal__title"><?php echo esc_html( $item->name ); ?></h2>
									<button type="button" class="pp-modal-close" data-pp-modal-close aria-label="<?php esc_attr_e( 'Close', 'project-prepper' ); ?>">✕</button>
								</div>
								<div class="pp-modal-body">
									<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php self::action_fields( 'borrow_request' ); ?>
										<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
										<input type="hidden" name="pp_group" value="<?php echo (int) $group_id; ?>">
										<p class="pp-portal__hint">
											<?php
											/* translators: 1: owner display name, 2: number of pieces/sets available. */
											printf( esc_html__( 'Owner: %1$s · %2$d available', 'project-prepper' ), esc_html( $owner_lb ), (int) $pp_avail );
											?>
										</p>
										<?php if ( $pp_parts ) : ?>
											<p class="pp-portal__hint"><?php echo esc_html( Bundles::parts_label( $pp_parts ) ); ?></p>
											<label><?php esc_html_e( 'Number of sets', 'project-prepper' ); ?>
												<input type="number" name="pp_sets" min="1" value="1">
											</label>
										<?php endif; ?>
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
					<p class="pp-portal__empty" data-pp-search-none hidden><?php esc_html_e( 'No items match your search.', 'project-prepper' ); ?></p>
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

	/**
	 * Verleih & Leihen — drei Reiter statt einer langen Seite (Muster
	 * Projekt-Detail): Externe Verleihe (App: /rentals, Default) · Leih-Anfragen
	 * (eingehend, inkl. Netzwerk) · Meine Leihen (+ Historie).
	 *
	 * Der frühere Reiter „Stöbern" ist in v0.41.0 entfallen: er listete exakt das
	 * Kollektiv-Inventar ein zweites Mal. Der „Ausleihen"-Button sitzt jetzt dort,
	 * wo die Artikel ohnehin stehen (Inventar-Ansicht im Gruppen-Workspace) —
	 * inklusive Suche, Kategorie-Pills und Zeitraum-Prüfung.
	 */
	private static function view_lending( WP_User $user, array $groups ): void {
		$uid          = (int) $user->ID;
		$fed_incoming = FederatedBorrow::inbound_for_owner( $uid );

		// Zähler für die Reiter-Badges + Empty-States.
		$mine      = Borrowing::my_requests( $uid );
		$incoming  = Borrowing::incoming_requests( $uid );
		$my_active = array_filter( $mine, static fn( $r ) => in_array( $r->status, self::BORROW_ACTIVE, true ) );
		$in_active = array_filter( $incoming, static fn( $r ) => in_array( $r->status, self::BORROW_ACTIVE, true ) );
		$closed_n  = ( count( $mine ) + count( $incoming ) ) - ( count( $my_active ) + count( $in_active ) );
		$open_reqs = count( array_filter( $incoming, static fn( $r ) => 'requested' === $r->status ) )
			+ count( array_filter( $fed_incoming, static fn( $r ) => 'requested' === $r->status ) );

		$tabs = [
			/* translators: %d: number of external rentals that are reserved or handed out. */
			'rentals'  => sprintf( __( 'External rentals (%d)', 'project-prepper' ), (int) array_sum( array_intersect_key( MemberRentals::kpis( $uid ), [ 'reserved' => 1, 'active' => 1 ] ) ) ),
			/* translators: %d: number of open borrow requests for the member’s items. */
			'requests' => sprintf( __( 'Borrow requests (%d)', 'project-prepper' ), (int) $open_reqs ),
			/* translators: %d: number of the member’s own active borrows. */
			'borrows'  => sprintf( __( 'My borrows (%d)', 'project-prepper' ), (int) count( $my_active ) ),
		];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige-Auswahl.
		$tab = sanitize_key( wp_unslash( (string) ( $_GET['pp_tab'] ?? 'rentals' ) ) );
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'rentals';
		}
		$tab_base = add_query_arg( 'pp_view', 'lending', self::portal_url() );
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title"><?php esc_html_e( 'Borrowing & lending', 'project-prepper' ); ?></h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Manage borrow requests for your own equipment, keep an eye on what you borrowed — and lend your equipment out to externals.', 'project-prepper' ); ?></p>
		</header>

		<nav class="pp-proj-tabs" aria-label="<?php esc_attr_e( 'Lending sections', 'project-prepper' ); ?>">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a class="pp-proj-tabs__tab<?php echo $key === $tab ? ' pp-proj-tabs__tab--on' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url( 'rentals' === $key ? $tab_base : add_query_arg( 'pp_tab', $key, $tab_base ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php
		switch ( $tab ) {
			case 'requests':
				self::render_incoming_borrows( $user );
				self::render_incoming_fed_borrows( $fed_incoming );
				if ( ! $in_active && ! $fed_incoming ) {
					?>
					<p class="pp-portal__empty"><?php esc_html_e( 'No borrow requests for your items right now.', 'project-prepper' ); ?></p>
					<?php
				}
				break;
			case 'borrows':
				self::render_my_borrows( $user );
				self::render_borrow_history( $user );
				if ( ! $my_active && 0 === $closed_n ) {
					?>
					<p class="pp-portal__empty"><?php esc_html_e( 'You are not borrowing anything right now. Find equipment under “Inventory” while a collective workspace is active.', 'project-prepper' ); ?></p>
					<?php
				}
				break;
			default:
				self::render_external_rentals( $user );
		}
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
		// Sets sind seit v0.40.0 regulär verleihbar (docs/07 §6): ausgewählt wird
		// das Set, verliehen werden serverseitig seine Teile. $bundles trennt im
		// Formular die Set-Zeilen von den normalen Artikel-Zeilen.
		$bundles = $lendable ? Bundles::for_items( array_keys( $lendable ) ) : [];
		?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php esc_html_e( 'External lending', 'project-prepper' ); ?></h3>
			<p class="pp-portal__hint"><?php esc_html_e( 'Lend your own equipment to people outside the platform. Reservation, hand-out, return and billing — just like the app.', 'project-prepper' ); ?></p>

			<div class="pp-kpi-grid pp-kpi-grid--compact">
				<?php
				self::mini_kpi( __( 'Reserved', 'project-prepper' ), (string) (int) $kpis['reserved'], 'info' );
				self::mini_kpi( __( 'Handed out', 'project-prepper' ), (string) (int) $kpis['active'], 'warning' );
				self::mini_kpi( __( 'Returned', 'project-prepper' ), (string) (int) $kpis['returned'], 'success' );
				self::mini_kpi(
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
								<?php
								// Set-Positionen unter ihrem Set gruppieren (Marker
								// bundle_item_id) — verliehen werden die Teile, angezeigt
								// wird die Herkunft.
								$pp_shown_sets = [];
								foreach ( $full->items as $line ) :
									$pp_bid = (int) ( $line->bundle_item_id ?? 0 );
									if ( $pp_bid > 0 && ! isset( $pp_shown_sets[ $pp_bid ] ) ) :
										$pp_shown_sets[ $pp_bid ] = true;
										$pp_set_item  = Inventory::get_item( $pp_bid );
										$pp_set_parts = Bundles::parts( $pp_bid );
										$pp_set_count = self::bundle_line_sets( $full->items, $pp_bid, $pp_set_parts );
										?>
										<li>
											<span class="pp-bundle-chip"><?php esc_html_e( 'Set', 'project-prepper' ); ?></span>
											<?php echo esc_html( $pp_set_item ? $pp_set_item->name : ( '#' . $pp_bid ) ); ?>
											<?php if ( $pp_set_count > 1 ) : ?>
												<span class="pp-portal__item-meta"><?php echo (int) $pp_set_count; ?>×</span>
											<?php endif; ?>
										</li>
										<?php
									endif;
									?>
									<li class="<?php echo esc_attr( $pp_bid > 0 ? 'pp-portal__rental-line--part' : '' ); ?>">
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
							<?php if ( in_array( $full->status, [ 'reserved', 'active' ], true ) && $lendable ) : ?>
								<button type="button" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" data-pp-modal="pp-rental-edit-<?php echo (int) $full->id; ?>"><?php esc_html_e( 'Edit', 'project-prepper' ); ?></button>
								<dialog class="pp-modal pp-modal--portal pp-modal--wide" id="pp-rental-edit-<?php echo (int) $full->id; ?>">
									<div class="pp-modal-header">
										<h2 class="pp-modal__title"><?php echo esc_html( sprintf( /* translators: %s: rental number. */ __( 'Edit rental %s', 'project-prepper' ), $full->rental_number ) ); ?></h2>
										<button type="button" class="pp-modal-close" data-pp-modal-close aria-label="<?php esc_attr_e( 'Close', 'project-prepper' ); ?>">✕</button>
									</div>
									<div class="pp-modal-body">
										<?php self::rental_form( $lendable, $bundles, $full ); ?>
									</div>
								</dialog>
							<?php endif; ?>
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
					<?php self::rental_form( $lendable, $bundles ); ?>
				</details>
			<?php else : ?>
				<p class="pp-portal__hint"><?php esc_html_e( 'Add items to your inventory first — then you can lend them out.', 'project-prepper' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function mini_kpi( string $label, string $value, string $tone ): void {
		?>
		<div class="pp-kpi pp-kpi--<?php echo esc_attr( $tone ); ?> pp-kpi--static">
			<span class="pp-kpi__value"><?php echo esc_html( $value ); ?></span>
			<span class="pp-kpi__label"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php
	}

	/**
	 * GEMEINSAME Artikel-Zeile für alle Auswahl-Listen (v0.41.0): Technik-Picker
	 * im Projekt, Verleih-Formular, Kollektiv-Inventar. Immer gleich aufgebaut —
	 * Foto ganz links, daneben Name + Inventarnummer + Chips, darunter die
	 * Meta-Zeile (Verfügbarkeit, Tagessatz …), rechts die Steuerelemente des
	 * jeweiligen Kontexts.
	 *
	 * Die Slots sind Closures statt HTML-Strings: so escaped jeder Aufrufer sein
	 * eigenes Markup und die Vorlage bleibt frei von durchgereichtem HTML.
	 * `data-pp-searchable` sitzt auf der Zeile — damit filtert die Live-Suche
	 * (assets/js/live-search.js) jede dieser Listen ohne Zusatzarbeit.
	 *
	 * @param object   $item Inventar-Artikel (name, image_url, inventory_number).
	 * @param callable $pick Rendert den Auswahl-Input (Checkbox) im Label.
	 * @param array    $args {
	 *     @type string[] $meta     Meta-Bits, werden mit „ · " verbunden.
	 *     @type bool     $is_set   Set-Chip hinter dem Namen.
	 *     @type string   $sub      Unterzeile (z. B. Stückliste eines Sets).
	 *     @type string   $badge    Badge hinter dem Namen (z. B. „bereits gebucht").
	 *     @type bool     $booked   Zeile als „schon gebucht" kennzeichnen.
	 *     @type bool     $muted    Zeile dimmen (nichts mehr frei).
	 *     @type callable $after    Zusatz-Markup unter der Meta-Zeile (Bedingungen).
	 *     @type callable $controls Steuerelemente rechts (Menge, €/Tag …).
	 * }
	 */
	private static function picker_row( object $item, callable $pick, array $args = [] ): void {
		$classes = 'pp-book-item';
		if ( ! empty( $args['booked'] ) ) {
			$classes .= ' pp-book-item--booked';
		}
		if ( ! empty( $args['muted'] ) ) {
			$classes .= ' pp-book-item--unavailable';
		}
		$meta = implode( ' · ', array_filter( (array) ( $args['meta'] ?? [] ), static fn( $b ) => '' !== trim( (string) $b ) ) );
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" data-pp-searchable>
			<label class="pp-book-item__pick">
				<?php $pick(); ?>
				<?php if ( ! empty( $item->image_url ) ) : ?>
					<img class="pp-portal__item-thumb" src="<?php echo esc_url( (string) $item->image_url ); ?>" alt="" loading="lazy">
				<?php else : ?>
					<span class="pp-portal__item-thumb pp-portal__item-thumb--empty" aria-hidden="true"></span>
				<?php endif; ?>
				<span class="pp-book-item__text">
					<span class="pp-book-item__name">
						<?php echo esc_html( (string) $item->name ); ?>
						<?php if ( ! empty( $args['is_set'] ) ) : ?>
							<span class="pp-bundle-chip"><?php esc_html_e( 'Set', 'project-prepper' ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== (string) ( $item->inventory_number ?? '' ) ) : ?>
							<small class="pp-portal__item-num"><?php echo esc_html( (string) $item->inventory_number ); ?></small>
						<?php endif; ?>
						<?php if ( '' !== (string) ( $args['badge'] ?? '' ) ) : ?>
							<span class="pp-book-item__badge"><?php echo esc_html( (string) $args['badge'] ); ?></span>
						<?php endif; ?>
					</span>
					<?php if ( '' !== $meta ) : ?>
						<span class="pp-book-item__meta"><?php echo esc_html( $meta ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== (string) ( $args['sub'] ?? '' ) ) : ?>
						<span class="pp-book-item__meta pp-book-item__meta--set"><?php echo esc_html( (string) $args['sub'] ); ?></span>
					<?php endif; ?>
					<?php
					if ( isset( $args['after'] ) && is_callable( $args['after'] ) ) {
						$args['after']();
					}
					?>
				</span>
			</label>
			<?php
			if ( isset( $args['controls'] ) && is_callable( $args['controls'] ) ) {
				$args['controls']();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Auswahl-Anzeige über einer Picker-Liste („was ist schon ausgewählt?").
	 * Der Startzustand kommt serverseitig ($picked = fertige Label-Texte), damit
	 * die Anzeige auch ohne JS stimmt; portal.js hält die Chips beim Anklicken
	 * live aktuell (data-pp-picker-summary im umgebenden [data-pp-picker]).
	 *
	 * @param string[] $picked Bereits gewählte Positionen als Anzeigetext.
	 */
	private static function picker_summary( array $picked = [] ): void {
		?>
		<p class="pp-picker-summary" data-pp-picker-summary>
			<span class="pp-picker-summary__label"><?php esc_html_e( 'Selected:', 'project-prepper' ); ?></span>
			<span class="pp-picker-summary__chips" data-pp-picker-chips>
				<?php foreach ( $picked as $label ) : ?>
					<span class="pp-picker-summary__chip"><?php echo esc_html( (string) $label ); ?></span>
				<?php endforeach; ?>
			</span>
			<span class="pp-picker-summary__empty" data-pp-picker-empty<?php echo $picked ? ' hidden' : ''; ?>><?php esc_html_e( 'nothing yet — tick the items below', 'project-prepper' ); ?></span>
		</p>
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
	 * Anlege-/Bearbeiten-Formular für einen externen Verleih. Person + Zeitraum +
	 * Geld + eine Auswahl der eigenen Artikel (Checkbox + Menge + Tagessatz pro
	 * Zeile). Beim Bearbeiten sind vorhandene Positionen vorgewählt; ihre
	 * Zeilen-ID läuft als pp_item[…][line] mit, damit {@see Rentals::update} per
	 * Diff aktualisiert statt neu anzulegen.
	 *
	 * Sets (v0.40.0) stehen als eigene Zeile oben: Menge = Anzahl SETS, Auswahl
	 * läuft über `pp_set[…]`; expandiert wird serverseitig (docs/07 §6).
	 *
	 * @param array<int,object>        $lendable Eigene Artikel (ID → Objekt).
	 * @param array<int,array<object>> $bundles  Stücklisten der eigenen Sets.
	 * @param object|null              $rental   Bestehender Verleih (inkl. items) oder null.
	 */
	private static function rental_form( array $lendable, array $bundles = [], ?object $rental = null ): void {
		$line_by_item = [];
		foreach ( (array) ( $rental->items ?? [] ) as $line ) {
			// Set-Teil-Zeilen gehören zur SET-Zeile des Formulars — sie dürfen die
			// Einzel-Zeile ihres Artikels nicht vorbelegen (sonst würde das Teil beim
			// Speichern doppelt verliehen).
			if ( ! empty( $line->bundle_item_id ) ) {
				continue;
			}
			if ( ! isset( $line_by_item[ (int) $line->item_id ] ) ) {
				$line_by_item[ (int) $line->item_id ] = $line;
			}
		}
		// Bestehende Set-Positionen zurück in „n× Set" übersetzen.
		$sets_picked = [];
		foreach ( (array) ( $rental->items ?? [] ) as $line ) {
			$pp_bid = (int) ( $line->bundle_item_id ?? 0 );
			if ( $pp_bid > 0 && ! isset( $sets_picked[ $pp_bid ] ) ) {
				$sets_picked[ $pp_bid ] = self::bundle_line_sets( (array) $rental->items, $pp_bid, $bundles[ $pp_bid ] ?? Bundles::parts( $pp_bid ) );
			}
		}
		$val = static fn( string $f, $d = '' ) => $rental && isset( $rental->$f ) && null !== $rental->$f ? $rental->$f : $d;

		// Zeitraum (v0.41.0): beim Anlegen sichtbar vorbelegt (heute → +7 Tage,
		// wie bei der Projekt-Buchung). Nur MIT Zeitraum kann die Liste unten echte
		// Verfügbarkeiten zeigen statt bloßer Bestände.
		$today     = current_time( 'Y-m-d' );
		$rid       = (int) ( $rental->id ?? 0 );
		$from      = (string) $val( 'date_from', $today );
		$to        = (string) $val( 'date_to', gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) ) );
		$period_ok = Availability::is_valid_range( $from, $to );
		$period_lb = $period_ok ? self::fmt_range( $from, $to ) : '';
		?>
		<form class="pp-portal__form pp-book-form pp-rental-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-pp-live data-pp-live-scope>
			<?php self::action_fields( $rental ? 'rental_update' : 'rental_create' ); ?>
			<?php if ( $rental ) : ?>
				<input type="hidden" name="pp_rental" value="<?php echo (int) $rental->id; ?>">
			<?php endif; ?>
			<label><?php esc_html_e( 'Borrower name', 'project-prepper' ); ?>
				<input type="text" name="pp_borrower" value="<?php echo esc_attr( (string) $val( 'borrower_name' ) ); ?>" required>
			</label>
			<label><?php esc_html_e( 'Email', 'project-prepper' ); ?>
				<input type="email" name="pp_email" value="<?php echo esc_attr( (string) $val( 'borrower_email' ) ); ?>">
			</label>
			<label><?php esc_html_e( 'Phone', 'project-prepper' ); ?>
				<input type="text" name="pp_phone" value="<?php echo esc_attr( (string) $val( 'borrower_phone' ) ); ?>">
			</label>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'From', 'project-prepper' ); ?>
					<input type="date" name="pp_from" value="<?php echo esc_attr( $from ); ?>" required>
				</label>
				<label><?php esc_html_e( 'To', 'project-prepper' ); ?>
					<input type="date" name="pp_to" value="<?php echo esc_attr( $to ); ?>" required>
				</label>
			</div>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Deposit (€)', 'project-prepper' ); ?>
					<input type="number" name="pp_deposit" min="0" step="0.01" placeholder="0.00" value="<?php echo esc_attr( null !== ( $rental->deposit_amount ?? null ) ? number_format( (float) $rental->deposit_amount, 2, '.', '' ) : '' ); ?>">
				</label>
				<label><?php esc_html_e( 'Rental fee (€, gross — optional)', 'project-prepper' ); ?>
					<input type="number" name="pp_fee" min="0" step="0.01" placeholder="<?php esc_attr_e( 'auto from daily rates', 'project-prepper' ); ?>" value="<?php echo esc_attr( null !== ( $rental->rental_fee ?? null ) ? number_format( (float) $rental->rental_fee, 2, '.', '' ) : '' ); ?>">
				</label>
			</div>

			<fieldset class="pp-portal__rental-items" data-pp-picker>
				<legend><?php esc_html_e( 'Items to lend out', 'project-prepper' ); ?></legend>
				<?php
				// Startzustand der Auswahl-Anzeige (beim Bearbeiten): Sets zuerst,
				// dann Einzel-Positionen — dieselbe Reihenfolge wie die Liste unten.
				$picked_labels = [];
				foreach ( $sets_picked as $pp_sid => $pp_sn ) {
					$picked_labels[] = ( $pp_sn > 1 ? $pp_sn . '× ' : '' ) . ( isset( $lendable[ $pp_sid ] ) ? $lendable[ $pp_sid ]->name : '#' . (int) $pp_sid );
				}
				foreach ( $line_by_item as $pp_iid => $pp_line ) {
					$pp_n = max( 1, (int) $pp_line->quantity );
					$picked_labels[] = ( $pp_n > 1 ? $pp_n . '× ' : '' ) . ( isset( $lendable[ $pp_iid ] ) ? $lendable[ $pp_iid ]->name : '#' . (int) $pp_iid );
				}
				self::picker_summary( $picked_labels );
				?>
				<input type="search" class="pp-book-search" placeholder="<?php esc_attr_e( 'Search equipment…', 'project-prepper' ); ?>" aria-label="<?php esc_attr_e( 'Search equipment…', 'project-prepper' ); ?>">
				<?php if ( $period_ok ) : ?>
					<p class="pp-portal__hint" data-pp-avail-note data-pp-stale="<?php esc_attr_e( 'Period changed — the numbers below still refer to the old dates. Availability for the new period is checked when you save.', 'project-prepper' ); ?>">
						<?php
						/* translators: %s: date range, e.g. "26.08.2026 – 02.09.2026". */
						printf( esc_html__( 'Availability shown for %s.', 'project-prepper' ), esc_html( $period_lb ) );
						?>
					</p>
				<?php endif; ?>
				<div class="pp-book-list">
				<?php
				// Sets zuerst: eine Zeile je Set, Menge = Anzahl Sets. Verliehen
				// werden serverseitig die Teile (docs/07 §6); der Tagessatz ergibt
				// sich aus den Teil-Sätzen, ein Paketpreis läuft über „Leihgebühr".
				foreach ( $bundles as $set_id => $parts ) :
					if ( ! isset( $lendable[ $set_id ] ) ) {
						continue;
					}
					$set_item = $lendable[ $set_id ];
					$set_rate = Bundles::parts_daily_rate( $parts );
					$set_on   = isset( $sets_picked[ $set_id ] );
					// Freie SETS im Zeitraum (eigener Verleih beim Bearbeiten ausgenommen).
					$set_free = $period_ok ? Bundles::available_sets( $parts, $from, $to, 0, $rid ) : 0;
					$set_bits = [];
					if ( $period_ok ) {
						/* translators: %d: number of complete sets available in the period. */
						$set_bits[] = sprintf( __( '%d sets free', 'project-prepper' ), (int) $set_free );
					}
					$set_bits[] = null !== $set_rate
						/* translators: %s: daily rate per set in euro. */
						? sprintf( __( '%s €/day (from parts)', 'project-prepper' ), number_format_i18n( (float) $set_rate, 2 ) )
						: __( 'no daily rate on the parts', 'project-prepper' );
					$set_off = $period_ok && $set_free <= 0 && ! $set_on;
					self::picker_row(
						$set_item,
						static function () use ( $set_id, $set_on, $set_off ) {
							?>
							<input type="checkbox" name="pp_set[<?php echo (int) $set_id; ?>][on]" value="1" <?php checked( $set_on ); ?><?php disabled( $set_off ); ?>>
							<?php
						},
						[
							'is_set'   => true,
							'sub'      => Bundles::parts_label( $parts ),
							'meta'     => $set_bits,
							'muted'    => $set_off,
							'controls' => static function () use ( $set_id, $sets_picked, $set_off, $period_ok, $set_free ) {
								?>
								<input type="number" class="pp-book-item__qty" name="pp_set[<?php echo (int) $set_id; ?>][qty]" min="1"<?php echo $period_ok && $set_free > 0 ? ' max="' . (int) $set_free . '"' : ''; ?> value="<?php echo (int) ( $sets_picked[ $set_id ] ?? 1 ); ?>" aria-label="<?php esc_attr_e( 'Sets', 'project-prepper' ); ?>"<?php disabled( $set_off ); ?>>
								<?php
							},
						]
					);
				endforeach;
				?>
				<?php foreach ( $lendable as $item ) :
					if ( isset( $bundles[ (int) $item->id ] ) ) {
						continue; // oben schon als Set-Zeile ausgegeben.
					}
					$line = $line_by_item[ (int) $item->id ] ?? null;
					$rate = $line && null !== $line->daily_rate
						? (float) $line->daily_rate
						: ( isset( $item->cost_per_day ) && '' !== (string) $item->cost_per_day ? (float) $item->cost_per_day : '' );
					// Frei im Zeitraum — zählt Verleihe, Projekt-Buchungen und Leihen
					// (Availability). Der eigene Verleih ist beim Bearbeiten ausgenommen,
					// damit die schon gebuchte Menge nicht gegen sich selbst zählt.
					$free = $period_ok ? Availability::available_quantity( (int) $item->id, $from, $to, $rid ) : (int) $item->quantity;
					$bits = [];
					if ( $period_ok ) {
						/* translators: 1: free pieces in the period, 2: total quantity. */
						$bits[] = sprintf( __( '%1$d of %2$d free', 'project-prepper' ), (int) $free, (int) $item->quantity );
					} else {
						/* translators: %d: total quantity. */
						$bits[] = sprintf( __( '%d× total', 'project-prepper' ), (int) $item->quantity );
					}
					if ( '' !== (string) ( $item->location ?? '' ) ) {
						$bits[] = (string) $item->location;
					}
					$off = $period_ok && $free <= 0 && null === $line;
					self::picker_row(
						$item,
						static function () use ( $item, $line, $off ) {
							?>
							<input type="checkbox" name="pp_item[<?php echo (int) $item->id; ?>][on]" value="1" <?php checked( null !== $line ); ?><?php disabled( $off ); ?>>
							<?php
						},
						[
							'meta'     => $bits,
							'muted'    => $off,
							'controls' => static function () use ( $item, $line, $rate, $free, $off, $period_ok ) {
								if ( $line ) {
									?>
									<input type="hidden" name="pp_item[<?php echo (int) $item->id; ?>][line]" value="<?php echo (int) $line->id; ?>">
									<?php
								}
								?>
								<input type="number" class="pp-book-item__qty" name="pp_item[<?php echo (int) $item->id; ?>][qty]" min="1"<?php echo $period_ok && $free > 0 ? ' max="' . (int) $free . '"' : ''; ?> value="<?php echo (int) ( $line->quantity ?? 1 ); ?>" aria-label="<?php esc_attr_e( 'Qty', 'project-prepper' ); ?>"<?php disabled( $off ); ?>>
								<label class="pp-book-item__rate"><?php esc_html_e( '€/day', 'project-prepper' ); ?>
									<input type="number" name="pp_item[<?php echo (int) $item->id; ?>][rate]" min="0" step="0.01" value="<?php echo esc_attr( '' === $rate ? '' : number_format( (float) $rate, 2, '.', '' ) ); ?>"<?php disabled( $off ); ?>>
								</label>
								<?php
							},
						]
					);
				endforeach; ?>
				</div>
				<p class="pp-book-none pp-portal__hint" data-pp-search-none hidden><?php esc_html_e( 'No items match your search.', 'project-prepper' ); ?></p>
			</fieldset>

			<label><?php esc_html_e( 'Notes', 'project-prepper' ); ?>
				<textarea name="pp_notes" rows="2"><?php echo esc_textarea( (string) $val( 'notes' ) ); ?></textarea>
			</label>
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php echo $rental ? esc_html__( 'Save rental', 'project-prepper' ) : esc_html__( 'Create rental', 'project-prepper' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Aus expandierten Set-Positionen die Anzahl SETS zurückrechnen:
	 * min über alle Teile von floor( gebuchte Menge / Bedarf ). Wurde die
	 * Stückliste nach dem Verleih geändert, bleibt mindestens 1 stehen.
	 *
	 * @param array<object> $lines  Positionen des Verleihs.
	 * @param array<object> $parts  Stückliste des Sets.
	 */
	private static function bundle_line_sets( array $lines, int $bundle_id, array $parts ): int {
		$booked = [];
		foreach ( $lines as $line ) {
			if ( (int) ( $line->bundle_item_id ?? 0 ) === $bundle_id ) {
				$booked[ (int) $line->item_id ] = ( $booked[ (int) $line->item_id ] ?? 0 ) + max( 1, (int) $line->quantity );
			}
		}
		if ( ! $parts || ! $booked ) {
			return 1;
		}
		$sets = PHP_INT_MAX;
		foreach ( $parts as $part ) {
			$need = max( 1, (int) $part->quantity );
			$have = (int) ( $booked[ (int) $part->part_item_id ] ?? 0 );
			$sets = min( $sets, (int) floor( $have / $need ) );
		}
		return max( 1, PHP_INT_MAX === $sets ? 1 : $sets );
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
		// Set-Auswahl (v0.40.0): set_item_id => Anzahl Sets. Expandiert wird
		// serverseitig in MemberRentals::expand_sets — hier nur einsammeln.
		$sets = [];
		$raw_sets = isset( $_POST['pp_set'] ) && is_array( $_POST['pp_set'] ) ? wp_unslash( $_POST['pp_set'] ) : [];
		foreach ( $raw_sets as $set_id => $row ) {
			if ( empty( $row['on'] ) ) {
				continue;
			}
			$sets[ (int) $set_id ] = max( 1, (int) ( $row['qty'] ?? 1 ) );
		}
		$raw   = isset( $_POST['pp_item'] ) && is_array( $_POST['pp_item'] ) ? wp_unslash( $_POST['pp_item'] ) : [];
		foreach ( $raw as $item_id => $line ) {
			if ( empty( $line['on'] ) ) {
				continue;
			}
			$row = [
				'item_id'    => (int) $item_id,
				'quantity'   => max( 1, (int) ( $line['qty'] ?? 1 ) ),
				'daily_rate' => isset( $line['rate'] ) && '' !== $line['rate'] ? (float) $line['rate'] : '',
			];
			// Bestehende Positions-ID beim Bearbeiten mitgeben (Diff in Rentals::update).
			if ( ! empty( $line['line'] ) ) {
				$row['id'] = (int) $line['line'];
			}
			$items[] = $row;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return [ 'data' => $data, 'items' => $items, 'sets' => $sets ];
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
		// Einzel-Kollektiv-Detail (?pp_group=ID) — wie die App die zwei-Reiter-
		// Gruppendetailseite. Nur eigene Kollektive; Fremde/Unbekannte → Hinweis.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Navigation
		$gid = isset( $_GET['pp_group'] ) ? (int) $_GET['pp_group'] : 0;
		if ( $gid > 0 ) {
			$active = null;
			foreach ( $groups as $g ) {
				if ( (int) $g->id === $gid ) {
					$active = $g;
					break;
				}
			}
			$back = add_query_arg( 'pp_view', 'collectives', self::portal_url() );
			if ( ! $active ) {
				?>
				<p class="pp-proj-back"><a href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( '← Back to collectives', 'project-prepper' ); ?></a></p>
				<p class="pp-portal__empty"><?php esc_html_e( 'This collective is not available.', 'project-prepper' ); ?></p>
				<?php
				return;
			}
			self::view_collective_detail( $active, (int) $user->ID );
			return;
		}
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title"><?php esc_html_e( 'My collectives', 'project-prepper' ); ?></h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Found or join collectives and invite members to share resources.', 'project-prepper' ); ?></p>
		</header>
		<?php self::render_my_invitations( $user ); ?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php esc_html_e( 'Your collectives', 'project-prepper' ); ?></h3>
			<?php if ( $groups ) : ?>
				<div class="pp-collective-list">
					<?php foreach ( $groups as $group ) {
						self::render_collective_card( $group );
					} ?>
				</div>
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

	/** Eingaben des Anfrage-Formulars einsammeln (Feld-Parität zur App-Pipeline). */
	private static function inquiry_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		return [
			'name'             => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) ),
			'title'            => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_title'] ?? '' ) ) ),
			'contact_person'   => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_contact'] ?? '' ) ) ),
			'email'            => sanitize_email( wp_unslash( (string) ( $_POST['pp_email'] ?? '' ) ) ),
			'phone'            => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_phone'] ?? '' ) ) ),
			'message'          => sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_message'] ?? '' ) ) ),
			'venue_name'       => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_venue'] ?? '' ) ) ),
			'date_from'        => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_from'] ?? '' ) ) ),
			'date_to'          => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_to'] ?? '' ) ) ),
			'estimated_budget' => '' !== (string) ( $_POST['pp_budget'] ?? '' ) ? (float) $_POST['pp_budget'] : '',
			'offer_amount'     => '' !== (string) ( $_POST['pp_offer'] ?? '' ) ? (float) $_POST['pp_offer'] : '',
			'probability'      => '' !== (string) ( $_POST['pp_probability'] ?? '' ) ? (int) $_POST['pp_probability'] : '',
			'follow_up'        => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_follow_up'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Anfragen-Liste (App-Pendant src/app/(dashboard)/inquiries): KPI-Ministats,
	 * Reiter Pipeline/Archiv, klickbare Zeilen zur Detail-Ansicht (?pp_inquiry=…).
	 */
	private static function view_inquiries( WP_User $user, array $groups ): void {
		// Detail-Ansicht (Muster Projekt-Detail).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Navigation
		$inq_id = isset( $_GET['pp_inquiry'] ) ? (int) $_GET['pp_inquiry'] : 0;
		if ( $inq_id ) {
			self::view_inquiry_detail( $inq_id, $user, $groups );
			return;
		}

		$group_id  = self::active_group_id( $groups );
		$inquiries = MemberInquiries::for_owner( (int) $user->ID, $group_id );
		$labels    = self::inquiry_status_labels();

		// Offene „Bist du dabei?"-Anfragen an MICH → Hinweis-Chip in der Zeile.
		$pending_rsvp = $group_id > 0 ? InquiryTeam::pending_for_user( (int) $user->ID, $group_id ) : [];

		// Status-Zähler + Angebotswert (Summe offer_amount, verlorene/geschlossene raus — wie die App).
		$counts    = array_fill_keys( Inquiries::STATUSES, 0 );
		$open      = 0;
		$offer_sum = 0.0;
		foreach ( $inquiries as $inq ) {
			if ( isset( $counts[ $inq->status ] ) ) {
				++$counts[ $inq->status ];
			}
			if ( ! in_array( $inq->status, [ 'won', 'lost', 'closed' ], true ) ) {
				++$open;
			}
			if ( null !== ( $inq->offer_amount ?? null ) && ! in_array( $inq->status, [ 'lost', 'closed' ], true ) ) {
				$offer_sum += (float) $inq->offer_amount;
			}
		}
		$archived = count( $inquiries ) - $open;

		// Reiter Pipeline/Archiv (?pp_tab=…) — serverseitig, Muster Projekt-Detail.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige-Auswahl.
		$tab = sanitize_key( wp_unslash( (string) ( $_GET['pp_tab'] ?? 'pipeline' ) ) );
		if ( ! in_array( $tab, [ 'pipeline', 'archive' ], true ) ) {
			$tab = 'pipeline';
		}
		$tab_base = add_query_arg( 'pp_view', 'inquiries', self::portal_url() );
		$shown    = array_values( array_filter(
			$inquiries,
			static fn( $i ) => ( 'archive' === $tab ) === in_array( $i->status, [ 'won', 'lost', 'closed' ], true )
		) );
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title"><?php echo $group_id ? esc_html__( 'Inquiries', 'project-prepper' ) : esc_html__( 'My inquiries', 'project-prepper' ); ?></h1>
			<p class="pp-app__page-sub">
				<?php
				/* translators: 1: total number of inquiries, 2: number of open inquiries. */
				printf( esc_html__( '%1$d inquiries · %2$d open', 'project-prepper' ), (int) count( $inquiries ), (int) $open );
				?>
			</p>
		</header>

		<div class="pp-kpi-grid pp-kpi-grid--compact">
			<?php
			self::mini_kpi( $labels['new'], (string) $counts['new'], 'info' );
			self::mini_kpi( $labels['contacted'], (string) $counts['contacted'], 'primary' );
			self::mini_kpi( $labels['offer'], (string) $counts['offer'], 'warning' );
			self::mini_kpi( __( 'Offer value', 'project-prepper' ), number_format_i18n( $offer_sum, 2 ) . ' €', 'success' );
			?>
		</div>

		<nav class="pp-proj-tabs" aria-label="<?php esc_attr_e( 'Inquiry sections', 'project-prepper' ); ?>">
			<a class="pp-proj-tabs__tab<?php echo 'pipeline' === $tab ? ' pp-proj-tabs__tab--on' : ''; ?>"<?php echo 'pipeline' === $tab ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url( $tab_base ); ?>"><?php /* translators: %d: number of open inquiries. */ printf( esc_html__( 'Pipeline (%d)', 'project-prepper' ), (int) $open ); ?></a>
			<a class="pp-proj-tabs__tab<?php echo 'archive' === $tab ? ' pp-proj-tabs__tab--on' : ''; ?>"<?php echo 'archive' === $tab ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url( add_query_arg( 'pp_tab', 'archive', $tab_base ) ); ?>"><?php /* translators: %d: number of archived inquiries. */ printf( esc_html__( 'Archive (%d)', 'project-prepper' ), (int) $archived ); ?></a>
		</nav>

		<section class="pp-portal__section">
			<?php if ( ! $shown ) : ?>
				<p class="pp-portal__empty">
					<?php
					if ( 'archive' === $tab ) {
						esc_html_e( 'No archived inquiries yet — won and lost inquiries land here.', 'project-prepper' );
					} else {
						esc_html_e( 'No open inquiries. Create your first one below.', 'project-prepper' );
					}
					?>
				</p>
			<?php else : ?>
				<div class="pp-rows">
					<?php foreach ( $shown as $inq ) :
						$title = '' !== trim( (string) ( $inq->title ?? '' ) ) ? $inq->title : $inq->name;
						$bits  = [ $inq->name ];
						$range = self::fmt_range( $inq->date_from, $inq->date_to );
						if ( '' !== $range ) {
							$bits[] = $range;
						}
						if ( '' !== (string) ( $inq->venue_name ?? '' ) ) {
							$bits[] = $inq->venue_name;
						}
						$overdue = ! empty( $inq->follow_up ) && $inq->follow_up < current_time( 'Y-m-d' )
							&& ! in_array( $inq->status, [ 'won', 'lost', 'closed' ], true );
						$url     = add_query_arg( [ 'pp_view' => 'inquiries', 'pp_inquiry' => (int) $inq->id ], self::portal_url() );
						?>
						<a class="pp-row pp-row--link" href="<?php echo esc_url( $url ); ?>">
							<span class="pp-row__main"><?php echo esc_html( $title ); ?></span>
							<span class="pp-status pp-status--<?php echo esc_attr( $inq->status ); ?>"><?php echo esc_html( $labels[ $inq->status ] ?? $inq->status ); ?></span>
							<?php if ( in_array( (int) $inq->id, $pending_rsvp, true ) ) : ?>
								<span class="pp-team-chip pp-team-chip--invited"><?php esc_html_e( 'Are you in?', 'project-prepper' ); ?></span>
							<?php endif; ?>
							<span class="pp-row__meta">
								<?php
								echo esc_html( implode( ' · ', $bits ) );
								if ( $overdue ) {
									echo ' · ';
									?><span class="pp-inq-overdue"><?php esc_html_e( 'Follow-up due!', 'project-prepper' ); ?></span><?php
								}
								?>
							</span>
							<?php if ( null !== ( $inq->offer_amount ?? null ) ) : ?>
								<span class="pp-inq-amount"><?php echo esc_html( number_format_i18n( (float) $inq->offer_amount, 2 ) ); ?> €</span>
							<?php endif; ?>
							<?php if ( null !== ( $inq->probability ?? null ) ) :
								$p    = (int) $inq->probability;
								$tone = $p >= 70 ? 'hi' : ( $p >= 30 ? 'mid' : 'lo' ); ?>
								<span class="pp-prob pp-prob--<?php echo esc_attr( $tone ); ?>"><?php echo (int) $p; ?>%</span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="pp-portal__actions" style="margin-top:1rem">
				<button type="button" class="pp-portal__btn pp-portal__btn--sm" data-pp-modal="pp-inquiry-new"><?php esc_html_e( 'New inquiry', 'project-prepper' ); ?></button>
			</div>
			<dialog class="pp-modal pp-modal--portal" id="pp-inquiry-new">
				<div class="pp-modal-header">
					<h2 class="pp-modal__title"><?php esc_html_e( 'New inquiry', 'project-prepper' ); ?></h2>
					<button type="button" class="pp-modal-close" data-pp-modal-close aria-label="<?php esc_attr_e( 'Close', 'project-prepper' ); ?>">✕</button>
				</div>
				<div class="pp-modal-body">
					<?php self::inquiry_form( 'inquiry_create', null ); ?>
				</div>
			</dialog>
		</section>
		<?php
	}

	/**
	 * Anfrage-Detail (App-Pendant src/app/(dashboard)/inquiries/[id]): Pipeline-
	 * Leiste, Sektionen Kunde/Event/Angebot/Bewertung/Notizen, Aktionen
	 * (Projekt erstellen bzw. „Zum Projekt", Bearbeiten, Löschen).
	 */
	private static function view_inquiry_detail( int $id, WP_User $user, array $groups ): void {
		$group_id = self::active_group_id( $groups );
		$inq      = MemberInquiries::get_owned( $id, (int) $user->ID, $group_id );
		$back     = add_query_arg( 'pp_view', 'inquiries', self::portal_url() );
		if ( ! $inq ) {
			?>
			<p class="pp-proj-back"><a href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( '← Back to inquiries', 'project-prepper' ); ?></a></p>
			<p class="pp-portal__empty"><?php esc_html_e( 'This inquiry is not available.', 'project-prepper' ); ?></p>
			<?php
			return;
		}
		$labels    = self::inquiry_status_labels();
		$is_closed = in_array( $inq->status, [ 'won', 'lost', 'closed' ], true );
		if ( $is_closed ) {
			$back = add_query_arg( 'pp_tab', 'archive', $back );
		}
		$title = '' !== trim( (string) ( $inq->title ?? '' ) ) ? $inq->title : $inq->name;
		?>
		<p class="pp-proj-back"><a href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( '← Back to inquiries', 'project-prepper' ); ?></a></p>
		<header class="pp-app__page-head">
			<div class="pp-proj-detail-head">
				<h1 class="pp-app__page-title"><?php echo esc_html( $title ); ?></h1>
				<span class="pp-status pp-status--<?php echo esc_attr( $inq->status ); ?>"><?php echo esc_html( $labels[ $inq->status ] ?? $inq->status ); ?></span>
			</div>
			<p class="pp-app__page-sub"><?php echo esc_html( $inq->name . ' · ' . self::fmt_date( $inq->created_at ) ); ?></p>
		</header>

		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Pipeline', 'project-prepper' ); ?></h3>
			<div class="pp-inq-pipeline">
				<?php
				// Alle Stufen zeigen (wie die App); nur serverseitig erlaubte
				// Übergänge sind klickbar, der Rest ist stumm.
				$steps = [ 'new', 'contacted', 'offer', 'won', 'lost' ];
				if ( 'closed' === $inq->status ) {
					$steps[] = 'closed';
				}
				$allowed = Inquiries::TRANSITIONS[ $inq->status ] ?? [];
				foreach ( $steps as $st ) :
					if ( $st === $inq->status ) : ?>
						<span class="pp-portal__chip pp-portal__chip--on"><?php echo esc_html( $labels[ $st ] ); ?></span>
					<?php elseif ( in_array( $st, $allowed, true ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php self::action_fields( 'inquiry_status' ); ?>
							<input type="hidden" name="pp_inquiry" value="<?php echo (int) $inq->id; ?>">
							<input type="hidden" name="pp_status" value="<?php echo esc_attr( $st ); ?>">
							<button type="submit" class="pp-portal__chip"><?php echo esc_html( $labels[ $st ] ); ?></button>
						</form>
					<?php else : ?>
						<span class="pp-portal__chip pp-portal__chip--off"><?php echo esc_html( $labels[ $st ] ); ?></span>
					<?php endif;
				endforeach;
				?>
			</div>
		</section>

		<?php self::render_inquiry_team( $inq, $user ); ?>

		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Client', 'project-prepper' ); ?></h3>
			<dl class="pp-dl">
				<?php
				self::dl_row( __( 'Client name', 'project-prepper' ), (string) $inq->name );
				if ( '' !== (string) ( $inq->contact_person ?? '' ) ) {
					self::dl_row( __( 'Contact person', 'project-prepper' ), (string) $inq->contact_person );
				}
				if ( '' !== (string) $inq->email ) : ?>
					<dt><?php esc_html_e( 'Email', 'project-prepper' ); ?></dt>
					<dd><a href="<?php echo esc_url( 'mailto:' . $inq->email ); ?>"><?php echo esc_html( $inq->email ); ?></a></dd>
				<?php endif;
				if ( '' !== (string) $inq->phone ) {
					self::dl_row( __( 'Phone', 'project-prepper' ), (string) $inq->phone );
				}
				?>
			</dl>
		</section>

		<?php
		$range = self::fmt_range( $inq->date_from, $inq->date_to );
		if ( '' !== (string) ( $inq->venue_name ?? '' ) || '' !== $range ) :
			?>
			<section class="pp-card">
				<h3 class="pp-card__title"><?php esc_html_e( 'Event', 'project-prepper' ); ?></h3>
				<dl class="pp-dl">
					<?php
					if ( '' !== (string) ( $inq->venue_name ?? '' ) ) {
						self::dl_row( __( 'Venue', 'project-prepper' ), (string) $inq->venue_name );
					}
					if ( '' !== $range ) {
						self::dl_row( __( 'Event date', 'project-prepper' ), $range );
					}
					?>
				</dl>
			</section>
		<?php endif; ?>

		<?php
		$has_offer  = null !== ( $inq->estimated_budget ?? null ) || null !== ( $inq->offer_amount ?? null );
		$has_rating = null !== ( $inq->probability ?? null ) || ! empty( $inq->follow_up );
		if ( $has_offer || $has_rating ) :
			?>
			<section class="pp-card">
				<h3 class="pp-card__title"><?php esc_html_e( 'Offer & rating', 'project-prepper' ); ?></h3>
				<dl class="pp-dl">
					<?php
					if ( null !== ( $inq->estimated_budget ?? null ) ) {
						self::dl_row( __( 'Estimated budget', 'project-prepper' ), number_format_i18n( (float) $inq->estimated_budget, 2 ) . ' €' );
					}
					if ( null !== ( $inq->offer_amount ?? null ) ) {
						self::dl_row( __( 'Offer amount', 'project-prepper' ), number_format_i18n( (float) $inq->offer_amount, 2 ) . ' €' );
					}
					if ( null !== ( $inq->probability ?? null ) ) :
						$p    = (int) $inq->probability;
						$tone = $p >= 70 ? 'hi' : ( $p >= 30 ? 'mid' : 'lo' );
						?>
						<dt><?php esc_html_e( 'Probability', 'project-prepper' ); ?></dt>
						<dd><span class="pp-prob pp-prob--<?php echo esc_attr( $tone ); ?>"><?php echo (int) $p; ?>%</span></dd>
					<?php endif;
					if ( ! empty( $inq->follow_up ) ) :
						$overdue = $inq->follow_up < current_time( 'Y-m-d' ) && ! $is_closed;
						?>
						<dt><?php esc_html_e( 'Next follow-up', 'project-prepper' ); ?></dt>
						<dd>
							<?php
							echo esc_html( self::fmt_date( $inq->follow_up ) );
							if ( $overdue ) {
								echo ' ';
								?><span class="pp-inq-overdue"><?php esc_html_e( 'Follow-up due!', 'project-prepper' ); ?></span><?php
							}
							?>
						</dd>
					<?php endif; ?>
				</dl>
			</section>
		<?php endif; ?>

		<?php if ( '' !== trim( (string) $inq->message ) ) : ?>
			<section class="pp-card">
				<h3 class="pp-card__title"><?php esc_html_e( 'Notes', 'project-prepper' ); ?></h3>
				<p class="pp-portal__inq-msg"><?php echo esc_html( $inq->message ); ?></p>
			</section>
		<?php endif; ?>

		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Actions', 'project-prepper' ); ?></h3>
			<?php if ( (int) ( $inq->project_id ?? 0 ) > 0 ) : ?>
				<p class="pp-portal__hint">
					<?php esc_html_e( 'A project was created from this inquiry.', 'project-prepper' ); ?>
					<a class="pp-portal__btn pp-portal__btn--sm" href="<?php echo esc_url( add_query_arg( [ 'pp_view' => 'projects', 'pp_project' => (int) $inq->project_id ], self::portal_url() ) ); ?>"><?php esc_html_e( 'Go to project', 'project-prepper' ); ?></a>
				</p>
			<?php endif; ?>
			<div class="pp-portal__actions">
				<?php if ( $group_id > 0 && ! $is_closed && (int) ( $inq->project_id ?? 0 ) <= 0 ) : ?>
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
		</section>
		<?php
	}

	/**
	 * Team-Verfügbarkeit + Selbst-RSVP einer GRUPPEN-Anfrage (App:
	 * inquiry-rsvp-banner.tsx + inquiry-team-section.tsx). Solo-Anfragen haben
	 * kein Team — die Sektion entfällt komplett. Jedes Gruppenmitglied kann
	 * Mitglieder anfragen (flaches Gruppenmodell — die App beschränkt auf
	 * Ersteller/Admin, WP-Anfragen haben keinen Ersteller-Begriff).
	 */
	private static function render_inquiry_team( object $inq, WP_User $user ): void {
		$group_id = (int) ( $inq->owner_group_id ?? 0 );
		if ( $group_id <= 0 ) {
			return;
		}
		$uid     = (int) $user->ID;
		$team    = InquiryTeam::for_inquiry( (int) $inq->id );
		$members = Groups::members( $group_id );
		$mine    = isset( $team[ $uid ] ) ? (string) $team[ $uid ]->status : '';

		// Eigener RSVP-Banner (App: „Kannst du bei diesem Projekt mitwirken?").
		$states = [
			'accepted' => [ 'accepted', __( 'You are in', 'project-prepper' ) ],
			'maybe'    => [ 'maybe', __( 'Maybe', 'project-prepper' ) ],
			'declined' => [ 'declined', _x( 'Declined', 'inquiry team RSVP', 'project-prepper' ) ],
			'invited'  => [ 'invited', __( 'No answer yet', 'project-prepper' ) ],
			''         => [ 'none', __( 'No answer yet', 'project-prepper' ) ],
		];
		[ $state_class, $state_label ] = $states[ $mine ] ?? $states[''];
		?>
		<section class="pp-card pp-rsvp pp-rsvp--<?php echo esc_attr( $state_class ); ?>">
			<div class="pp-rsvp__head">
				<div>
					<h3 class="pp-card__title"><?php esc_html_e( 'Can you take part in this project?', 'project-prepper' ); ?></h3>
					<p class="pp-rsvp__hint"><?php esc_html_e( 'Your answer is immediately visible to the whole group.', 'project-prepper' ); ?></p>
				</div>
				<span class="pp-rsvp__state pp-rsvp__state--<?php echo esc_attr( $state_class ); ?>"><?php echo esc_html( $state_label ); ?></span>
			</div>
			<div class="pp-rsvp__actions">
				<?php
				$buttons = [
					'accepted' => [ __( 'Yes, I am in', 'project-prepper' ), 'yes' ],
					'maybe'    => [ __( 'Maybe', 'project-prepper' ), 'maybe' ],
					'declined' => [ __( 'Cannot make it', 'project-prepper' ), 'no' ],
				];
				foreach ( $buttons as $status => [ $label, $tone ] ) :
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php self::action_fields( 'inqteam_rsvp' ); ?>
						<input type="hidden" name="pp_inquiry" value="<?php echo (int) $inq->id; ?>">
						<input type="hidden" name="pp_rsvp" value="<?php echo esc_attr( $status ); ?>">
						<button type="submit" class="pp-rsvp__btn pp-rsvp__btn--<?php echo esc_attr( $tone ); ?><?php echo $mine === $status ? ' pp-rsvp__btn--on' : ''; ?>"><?php echo esc_html( $label ); ?></button>
					</form>
				<?php endforeach; ?>
			</div>
		</section>

		<?php
		// Team-Liste: alle Gruppenmitglieder, sortiert Zusagen → Vielleicht →
		// Angefragt → ohne Antwort → Absagen (App: statusOrder).
		$order = [ 'accepted' => 0, 'maybe' => 1, 'invited' => 2, '' => 3, 'declined' => 4 ];
		usort( $members, static function ( $a, $b ) use ( $team, $order ) {
			$sa = isset( $team[ (int) $a->user_id ] ) ? (string) $team[ (int) $a->user_id ]->status : '';
			$sb = isset( $team[ (int) $b->user_id ] ) ? (string) $team[ (int) $b->user_id ]->status : '';
			return ( $order[ $sa ] ?? 9 ) <=> ( $order[ $sb ] ?? 9 );
		} );
		$accepted = 0;
		foreach ( $team as $row ) {
			if ( 'accepted' === (string) $row->status ) {
				++$accepted;
			}
		}
		$chip = [
			// _x: „Zugesagt/Abgesagt" (RSVP) statt „Bestätigt/Abgelehnt" der
			// gleichnamigen Status-Labels anderswo.
			'accepted' => [ 'accepted', _x( 'Confirmed', 'inquiry team RSVP', 'project-prepper' ) ],
			'maybe'    => [ 'maybe', __( 'Maybe', 'project-prepper' ) ],
			'invited'  => [ 'invited', __( 'Requested', 'project-prepper' ) ],
			'declined' => [ 'declined', _x( 'Declined', 'inquiry team RSVP', 'project-prepper' ) ],
		];
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Team availability', 'project-prepper' ); ?>
				<span class="pp-team-count"><?php echo esc_html( $accepted . '/' . count( $members ) ); ?></span>
			</h3>
			<div class="pp-team">
				<?php foreach ( $members as $m ) :
					$mid    = (int) $m->user_id;
					$status = isset( $team[ $mid ] ) ? (string) $team[ $mid ]->status : '';
					?>
					<div class="pp-team-row pp-team-row--<?php echo esc_attr( '' === $status ? 'none' : $status ); ?>">
						<span class="pp-team-row__dot" aria-hidden="true"></span>
						<span class="pp-team-row__name">
							<?php echo esc_html( (string) $m->display_name ); ?>
							<?php if ( $mid === $uid ) : ?>
								<span class="pp-muted-inline"><?php esc_html_e( '(you)', 'project-prepper' ); ?></span>
							<?php endif; ?>
						</span>
						<span class="pp-team-row__aside">
							<?php if ( '' !== $status ) : ?>
								<span class="pp-team-chip pp-team-chip--<?php echo esc_attr( $chip[ $status ][0] ); ?>"><?php echo esc_html( $chip[ $status ][1] ); ?></span>
							<?php endif; ?>
							<?php if ( $mid !== $uid ) : ?>
								<?php if ( '' === $status ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php self::action_fields( 'inqteam_invite' ); ?>
										<input type="hidden" name="pp_inquiry" value="<?php echo (int) $inq->id; ?>">
										<input type="hidden" name="pp_user" value="<?php echo (int) $mid; ?>">
										<button type="submit" class="pp-portal__chip"><?php esc_html_e( 'Ask', 'project-prepper' ); ?></button>
									</form>
								<?php elseif ( 'invited' === $status ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php self::action_fields( 'inqteam_revoke' ); ?>
										<input type="hidden" name="pp_inquiry" value="<?php echo (int) $inq->id; ?>">
										<input type="hidden" name="pp_user" value="<?php echo (int) $mid; ?>">
										<button type="submit" class="pp-portal__chip"><?php esc_html_e( 'Withdraw', 'project-prepper' ); ?></button>
									</form>
								<?php elseif ( 'declined' === $status ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php self::action_fields( 'inqteam_invite' ); ?>
										<input type="hidden" name="pp_inquiry" value="<?php echo (int) $inq->id; ?>">
										<input type="hidden" name="pp_user" value="<?php echo (int) $mid; ?>">
										<button type="submit" class="pp-portal__chip"><?php esc_html_e( 'Ask again', 'project-prepper' ); ?></button>
									</form>
								<?php endif; ?>
							<?php endif; ?>
						</span>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	private static function inquiry_form( string $do, ?object $inq ): void {
		$val   = static fn( string $f, $d = '' ) => $inq && isset( $inq->$f ) && null !== $inq->$f ? $inq->$f : $d;
		$money = static fn( string $f ) => null !== ( $inq->$f ?? null ) ? number_format( (float) $inq->$f, 2, '.', '' ) : '';
		?>
		<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::action_fields( $do ); ?>
			<?php if ( $inq ) : ?>
				<input type="hidden" name="pp_inquiry" value="<?php echo (int) $inq->id; ?>">
			<?php endif; ?>
			<label><?php esc_html_e( 'Title', 'project-prepper' ); ?>
				<input type="text" name="pp_title" value="<?php echo esc_attr( (string) $val( 'title' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Company party Miller Ltd.', 'project-prepper' ); ?>">
			</label>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Client name', 'project-prepper' ); ?>
					<input type="text" name="pp_name" value="<?php echo esc_attr( (string) $val( 'name' ) ); ?>" required>
				</label>
				<label><?php esc_html_e( 'Contact person', 'project-prepper' ); ?>
					<input type="text" name="pp_contact" value="<?php echo esc_attr( (string) $val( 'contact_person' ) ); ?>">
				</label>
			</div>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Email', 'project-prepper' ); ?>
					<input type="email" name="pp_email" value="<?php echo esc_attr( (string) $val( 'email' ) ); ?>">
				</label>
				<label><?php esc_html_e( 'Phone', 'project-prepper' ); ?>
					<input type="text" name="pp_phone" value="<?php echo esc_attr( (string) $val( 'phone' ) ); ?>">
				</label>
			</div>
			<label><?php esc_html_e( 'Venue / location', 'project-prepper' ); ?>
				<input type="text" name="pp_venue" value="<?php echo esc_attr( (string) $val( 'venue_name' ) ); ?>">
			</label>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'From', 'project-prepper' ); ?>
					<input type="date" name="pp_from" value="<?php echo esc_attr( (string) $val( 'date_from' ) ); ?>">
				</label>
				<label><?php esc_html_e( 'To', 'project-prepper' ); ?>
					<input type="date" name="pp_to" value="<?php echo esc_attr( (string) $val( 'date_to' ) ); ?>">
				</label>
			</div>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Estimated budget (€)', 'project-prepper' ); ?>
					<input type="number" name="pp_budget" min="0" step="0.01" value="<?php echo esc_attr( $money( 'estimated_budget' ) ); ?>" placeholder="0.00">
				</label>
				<label><?php esc_html_e( 'Offer amount (€)', 'project-prepper' ); ?>
					<input type="number" name="pp_offer" min="0" step="0.01" value="<?php echo esc_attr( $money( 'offer_amount' ) ); ?>" placeholder="0.00">
				</label>
			</div>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Probability (%)', 'project-prepper' ); ?>
					<input type="number" name="pp_probability" min="0" max="100" step="5" value="<?php echo esc_attr( null !== ( $inq->probability ?? null ) ? (string) (int) $inq->probability : '' ); ?>" placeholder="50">
				</label>
				<label><?php esc_html_e( 'Next follow-up', 'project-prepper' ); ?>
					<input type="date" name="pp_follow_up" value="<?php echo esc_attr( (string) $val( 'follow_up' ) ); ?>">
				</label>
			</div>
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

	/**
	 * Buchbarer Pool eines Gruppen-Projekts = die mit dem Kollektiv geteilten
	 * Artikel (item_id → Item). Zugleich Sicherheits-Whitelist: nur diese IDs
	 * dürfen über das Portal gebucht werden.
	 *
	 * @return array<int,object>
	 */
	private static function bookable_pool( object $p ): array {
		$pool = [];
		foreach ( MemberInventory::items_shared_with_group( (int) $p->owner_group_id ) as $item ) {
			$pool[ (int) $item->id ] = $item;
		}
		return $pool;
	}

	/** Gemeinsame Zeilen-Eingaben der Buchungs-Formulare (Nonce im Dispatcher geprüft). */
	private static function booking_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		return [
			'quantity'  => max( 1, (int) ( $_POST['pp_quantity'] ?? 1 ) ),
			'date_from' => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_from'] ?? '' ) ) ),
			'date_to'   => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_to'] ?? '' ) ) ),
			'notes'     => sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_notes'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Buchungs-Zeitraum gegen den Projektzeitraum normalisieren: entspricht die
	 * Eingabe exakt dem Projektzeitraum, wird LEER gespeichert (= erbt weiter den
	 * Projektzeitraum, COALESCE in den Abfragen). Die Formulare belegen die Felder
	 * sichtbar mit dem Projektzeitraum vor — ohne diese Normalisierung würde jeder
	 * unveränderte Submit den Zeitraum „festnageln" und spätere Projekt-
	 * Verschiebungen nicht mehr mitmachen.
	 *
	 * @param array $input Buchungs-Eingaben (date_from/date_to werden ggf. geleert).
	 */
	private static function normalize_booking_dates( array $input, object $p ): array {
		$p_start = substr( (string) $p->date_start, 0, 10 );
		$p_end   = substr( (string) $p->date_end, 0, 10 );
		if ( '' !== $p_start && $input['date_from'] === $p_start && $input['date_to'] === $p_end ) {
			$input['date_from'] = '';
			$input['date_to']   = '';
		}
		return $input;
	}

	/**
	 * Technik für ein Projekt buchen — Mehrfachauswahl: alle angehakten Artikel
	 * aus dem Gruppen-Pool, je mit eigener Menge, gemeinsamer Zeitraum/Notiz.
	 *
	 * @return true|string|\WP_Error true = alle gebucht; 'booking_partial' =
	 *         teils gebucht (Rest nicht verfügbar); WP_Error = nichts gebucht.
	 */
	private static function member_book_equipment( int $pid ) {
		$p = self::member_owned_project( $pid );
		if ( ! $p ) {
			return new \WP_Error( 'pp_forbidden', __( 'This project is not available.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		$item_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $_POST['pp_items'] ?? [] ) ) ) ) );
		$qty_raw  = is_array( $_POST['pp_qty'] ?? null ) ? wp_unslash( $_POST['pp_qty'] ) : [];
		$shared   = [
			'date_from' => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_from'] ?? '' ) ) ),
			'date_to'   => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_to'] ?? '' ) ) ),
			'notes'     => sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_notes'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$shared = self::normalize_booking_dates( $shared, $p );

		if ( ! $item_ids ) {
			return new \WP_Error( 'pp_no_selection', __( 'Please tick at least one item to book.', 'project-prepper' ) );
		}

		$pool     = self::bookable_pool( $p );
		$uid      = get_current_user_id();
		$booked   = 0;
		$failed   = 0;
		$pending  = 0;
		$last_err = null;
		// Freigabepflichtige Zeilen PRO EIGENTÜMER sammeln — nach der Schleife gibt
		// es je Eigentümer EINE Sammel-Anfrage (statt einer Mail pro Gerät).
		$pending_by_owner = [];
		// Stücklisten der angehakten Artikel: Set-Positionen werden serverseitig in
		// Teil-Zeilen expandiert (Buchungs-Makro, docs/07 §2).
		$bundle_parts = Bundles::for_items( $item_ids );
		// Effektiver Prüf-Zeitraum für die Set-Verfügbarkeit: expliziter Zeitraum,
		// sonst der geerbte Projektzeitraum (normalize_booking_dates hat „gleich"
		// bereits geleert).
		$eff_from = '' !== $shared['date_from'] ? $shared['date_from'] : substr( (string) $p->date_start, 0, 10 );
		$eff_to   = '' !== $shared['date_to'] ? $shared['date_to'] : substr( (string) $p->date_end, 0, 10 );
		foreach ( $item_ids as $item_id ) {
			if ( ! isset( $pool[ $item_id ] ) ) {
				// Nicht aus dem Pool — harte Grenze (IDOR), sofort abbrechen.
				return new \WP_Error( 'pp_forbidden', __( 'Only equipment shared with this collective can be booked.', 'project-prepper' ), [ 'status' => 403 ] );
			}
			$pool_item = $pool[ $item_id ];
			$owner_id  = (int) ( $pool_item->shared_by ?? 0 );
			// Freigabe nötig, wenn der Artikel jemand ANDEREM gehört UND die Freigabe
			// die Bedingung „requires_approval" trägt. Eigene Artikel + freie Freigaben
			// werden sofort gebucht (auto-approved). Bei Sets folgt die Pflicht dem
			// SET-Share — die Teile selbst müssen nicht geteilt sein (docs/07 §4.4).
			$needs = $owner_id > 0 && $owner_id !== $uid && ! empty( $pool_item->requires_approval );

			$qty  = max( 1, (int) ( $qty_raw[ $item_id ] ?? 1 ) );
			$parts = $bundle_parts[ $item_id ] ?? [];
			if ( $parts ) {
				// SET: alles-oder-nichts (docs/07 §4.3). Erst die Set-Verfügbarkeit
				// prüfen, dann ALLE Teil-Zeilen anlegen; schlägt eine an (Race),
				// werden die bereits angelegten wieder entfernt.
				if ( Bundles::available_sets( $parts, $eff_from, $eff_to, $pid ) < $qty ) {
					$failed++;
					$last_err = new \WP_Error( 'pp_not_available', __( 'One of the items is not available in that period. Please adjust the dates or quantity.', 'project-prepper' ) );
					continue;
				}
				$created = [];
				$set_err = null;
				foreach ( $parts as $part ) {
					$line = [
						'item_id'        => (int) $part->part_item_id,
						'quantity'       => max( 1, (int) $part->quantity ) * $qty,
						'bundle_item_id' => $item_id,
					] + $shared;
					if ( $needs ) {
						$line['approval_status'] = 'pending';
						$line['requested_by']    = $uid;
					}
					$res = Projects::add_item( $pid, $line );
					if ( is_wp_error( $res ) ) {
						$set_err = $res;
						break;
					}
					$created[] = (int) $res;
				}
				if ( $set_err ) {
					foreach ( $created as $cid ) {
						Projects::remove_item( $pid, $cid );
					}
					$failed++;
					$last_err = $set_err;
					continue;
				}
				$booked++;
				if ( $needs ) {
					$pending++;
					foreach ( $created as $cid ) {
						$pending_by_owner[ $owner_id ][] = $cid;
					}
				}
				continue;
			}

			$line = [ 'item_id' => $item_id, 'quantity' => $qty ] + $shared;
			if ( $needs ) {
				$line['approval_status'] = 'pending';
				$line['requested_by']    = $uid;
			}
			$res = Projects::add_item( $pid, $line );
			if ( is_wp_error( $res ) ) {
				$failed++;
				$last_err = $res;
			} else {
				$booked++;
				if ( $needs ) {
					$pending++;
					$pending_by_owner[ $owner_id ][] = (int) $res;
				}
			}
		}

		// Freigabe-Anfragen an die Eigentümer (E-Mail + Portal-Eintrag, der über
		// den DB-Status geführt wird) — eine Sammel-Mail je Eigentümer. Fehler
		// beim Mailversand bleiben folgenlos.
		foreach ( $pending_by_owner as $po_owner => $po_lines ) {
			do_action( 'pp_booking_approvals_requested', $po_lines, (int) $po_owner, $uid );
		}

		if ( 0 === $booked ) {
			return $last_err ?: new \WP_Error( 'pp_not_available', __( 'One of the items is not available in that period. Please adjust the dates or quantity.', 'project-prepper' ) );
		}
		if ( $failed > 0 ) {
			return 'booking_partial';
		}
		// Alles gebucht — bei mind. einer Freigabe-Anfrage eigene Meldung.
		return $pending > 0 ? 'booking_pending' : true;
	}

	/**
	 * Buchungszeile ändern (Menge/Zeitraum/Notiz — der Artikel bleibt). Löst bei
	 * einer MATERIELLEN Änderung (Menge erhöht ODER Zeitraum geändert) an einer
	 * bereits freigegebenen Zeile eines fremden, freigabepflichtigen Artikels eine
	 * ERNEUTE Freigabe aus (Status zurück auf pending + Eigentümer-Mail). Nicht-
	 * materielle Änderungen (Menge gleich/kleiner bei gleichem Zeitraum, reine
	 * Notiz) lassen den Status unberührt — der Eigentümer wird nicht gespamt.
	 *
	 * @return true|string|\WP_Error 'booking_reapproval' = erneut freigabepflichtig.
	 */
	private static function member_update_booking( int $pid, int $line_id ) {
		$p = self::member_owned_project( $pid );
		if ( ! $p ) {
			return new \WP_Error( 'pp_forbidden', __( 'This project is not available.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$existing = Projects::get_item_line( $pid, $line_id );
		if ( ! $existing ) {
			return new \WP_Error( 'pp_not_found', __( 'Line item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( ! empty( $existing->bundle_item_id ) ) {
			// Set-Teil-Zeilen nur über die Set-Aktionen — Einzel-Änderungen würden
			// halbe Sets erzeugen und die Set-Freigabelogik umgehen (docs/07 §4).
			return new \WP_Error( 'pp_bundle_line', __( 'This line belongs to a set. Change or remove the whole set instead.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$input = self::normalize_booking_dates( self::booking_input(), $p );

		// Braucht der Artikel eine Freigabe (fremd + requires_approval)? Owner aus
		// dem Gruppen-Pool (shared_by = Eigentümer/Freigebende:r).
		$uid       = get_current_user_id();
		$pool      = self::bookable_pool( $p );
		$pool_item = $pool[ (int) $existing->item_id ] ?? null;
		$owner_id  = $pool_item ? (int) ( $pool_item->shared_by ?? 0 ) : 0;
		$needs     = $pool_item && $owner_id > 0 && $owner_id !== $uid && ! empty( $pool_item->requires_approval );

		$applied = self::apply_line_update( $pid, $existing, $input, $needs, $uid );
		if ( is_wp_error( $applied['res'] ) ) {
			return $applied['res'];
		}
		if ( $applied['reapproved'] ) {
			do_action( 'pp_booking_approval_requested', $line_id, $owner_id, $uid );
			return 'booking_reapproval';
		}
		return true;
	}

	/**
	 * Kern des Zeilen-Updates (Einzel-Buchung UND Set-Teil-Zeile): eine MATERIELLE
	 * Änderung (Menge erhöht ODER Zeitraum geändert) an einer bereits freigegebenen
	 * Zeile eines freigabepflichtigen Artikels setzt den Status zurück auf pending.
	 * pending-Zeilen behalten ihren Status (keine zweite Mail); ohne Freigabepflicht
	 * bleibt approved. Der Aufrufer verschickt die Anfrage-Mail(s).
	 *
	 * @return array{res: true|\WP_Error, reapproved: bool}
	 */
	private static function apply_line_update( int $pid, object $existing, array $input, bool $needs, int $uid ): array {
		$reapprove = false;
		if ( $needs && 'approved' === (string) $existing->approval_status ) {
			$material = BookingApprovals::is_material_change(
				(int) $existing->quantity,
				(int) $input['quantity'],
				(string) ( $existing->date_from ?? '' ),
				(string) ( $existing->date_to ?? '' ),
				(string) $input['date_from'],
				(string) $input['date_to']
			);
			if ( $material ) {
				$input['approval_status'] = 'pending';
				$input['requested_by']    = $uid;
				$input['decided_at']      = null;
				$reapprove                = true;
			}
		}
		$res = Projects::update_item( $pid, (int) $existing->id, $input );
		return [
			'res'        => is_wp_error( $res ) ? $res : true,
			'reapproved' => $reapprove,
		];
	}

	/**
	 * Set-Buchung ändern — Menge (in SETS), Zeitraum und Notiz werden auf ALLE
	 * Teil-Zeilen des Sets angewendet (Teil-Menge = Bedarf × Set-Anzahl).
	 * Re-Approval-Logik je Zeile wie bei Einzel-Buchungen; die erneuten
	 * Freigabe-Anfragen gehen als EINE Sammel-Mail an den Eigentümer.
	 *
	 * @return true|string|\WP_Error 'booking_reapproval' = erneut freigabepflichtig.
	 */
	private static function member_update_bundle( int $pid, int $bundle_id ) {
		$p = self::member_owned_project( $pid );
		if ( ! $p ) {
			return new \WP_Error( 'pp_forbidden', __( 'This project is not available.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$lines = array_values( array_filter( (array) ( $p->items ?? [] ), static function ( $l ) use ( $bundle_id ) {
			return (int) ( $l->bundle_item_id ?? 0 ) === $bundle_id;
		} ) );
		if ( ! $lines ) {
			return new \WP_Error( 'pp_not_found', __( 'Line item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$parts = Bundles::parts( $bundle_id );
		if ( ! $parts ) {
			return new \WP_Error( 'pp_not_found', __( 'Line item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$need_map = [];
		foreach ( $parts as $part ) {
			$need_map[ (int) $part->part_item_id ] = max( 1, (int) $part->quantity );
		}
		$input_base = self::normalize_booking_dates( self::booking_input(), $p );
		$sets       = max( 1, (int) $input_base['quantity'] );

		// Alles-oder-nichts-Vorprüfung (docs/07 §4.3): reicht der Bestand für die
		// neue Set-Anzahl im neuen Zeitraum? Eigene Projekt-Zeilen sind über
		// exclude_project ausgeklammert (wie beim Einzel-Update).
		$eff_from = '' !== $input_base['date_from'] ? $input_base['date_from'] : substr( (string) $p->date_start, 0, 10 );
		$eff_to   = '' !== $input_base['date_to'] ? $input_base['date_to'] : substr( (string) $p->date_end, 0, 10 );
		if ( Bundles::available_sets( $parts, $eff_from, $eff_to, $pid ) < $sets ) {
			return new \WP_Error( 'pp_not_available', __( 'One of the items is not available in that period. Please adjust the dates or quantity.', 'project-prepper' ) );
		}

		// Freigabepflicht folgt dem SET-Share (docs/07 §4.4).
		$uid       = get_current_user_id();
		$pool      = self::bookable_pool( $p );
		$pool_item = $pool[ $bundle_id ] ?? null;
		$owner_id  = $pool_item ? (int) ( $pool_item->shared_by ?? 0 ) : 0;
		$needs     = $pool_item && $owner_id > 0 && $owner_id !== $uid && ! empty( $pool_item->requires_approval );

		$reapproved = [];
		foreach ( $lines as $line ) {
			$input             = $input_base;
			$input['quantity'] = ( $need_map[ (int) $line->item_id ] ?? 1 ) * $sets;
			$applied           = self::apply_line_update( $pid, $line, $input, $needs, $uid );
			if ( is_wp_error( $applied['res'] ) ) {
				return $applied['res'];
			}
			if ( $applied['reapproved'] ) {
				$reapproved[] = (int) $line->id;
			}
		}
		if ( $reapproved && $owner_id > 0 ) {
			do_action( 'pp_booking_approvals_requested', $reapproved, $owner_id, $uid );
			return 'booking_reapproval';
		}
		return true;
	}

	/** Set-Buchung komplett entfernen — alle Teil-Zeilen des Sets in diesem Projekt. */
	private static function member_remove_bundle( int $pid, int $bundle_id ) {
		$p = self::member_owned_project( $pid );
		if ( ! $p ) {
			return new \WP_Error( 'pp_forbidden', __( 'This project is not available.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$removed = 0;
		foreach ( (array) ( $p->items ?? [] ) as $line ) {
			if ( (int) ( $line->bundle_item_id ?? 0 ) === $bundle_id ) {
				$res = Projects::remove_item( $pid, (int) $line->id );
				if ( ! is_wp_error( $res ) ) {
					$removed++;
				}
			}
		}
		return $removed > 0 ? true : new \WP_Error( 'pp_not_found', __( 'Line item not found.', 'project-prepper' ), [ 'status' => 404 ] );
	}

	/** Buchungszeile entfernen (Set-Teil-Zeilen nur über die Set-Aktion). */
	private static function member_remove_booking( int $pid, int $line_id ) {
		if ( ! self::member_owned_project( $pid ) ) {
			return new \WP_Error( 'pp_forbidden', __( 'This project is not available.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$existing = Projects::get_item_line( $pid, $line_id );
		if ( $existing && ! empty( $existing->bundle_item_id ) ) {
			return new \WP_Error( 'pp_bundle_line', __( 'This line belongs to a set. Change or remove the whole set instead.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		return Projects::remove_item( $pid, $line_id );
	}

	/** Packlisten-Status (gepackt/getestet) einer Buchungszeile umschalten (Gate: aktiver Workspace). */
	private static function member_toggle_flag( int $pid, int $line_id, string $flag, bool $on ) {
		if ( ! self::member_owned_project( $pid ) ) {
			return new \WP_Error( 'pp_forbidden', __( 'This project is not available.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		return Projects::set_line_flag( $pid, $line_id, $flag, $on );
	}

	/* ---------- Projekt-Unterlisten (Zeitplan/Aufgaben/…) ---------- */

	/**
	 * Alle pp_do-Aktionen der Projekt-Unterlisten. Der Dispatcher erzwingt für
	 * sie member_owned_project($proj_id) — Projekt im aktiven Gruppen-Workspace.
	 *
	 * @return array<string>
	 */
	private static function project_sub_actions(): array {
		return [
			'sched_add', 'sched_update', 'sched_delete', 'sched_move',
			'task_add', 'task_update', 'task_delete', 'task_accept', 'task_decline',
			'checklist_add', 'checklist_delete', 'checklist_move',
			'checkitem_add', 'checkitem_toggle', 'checkitem_delete', 'checkitem_move',
			'material_add', 'material_update', 'material_delete',
			'crew_add', 'crew_update', 'crew_delete',
			'contact_add', 'contact_update', 'contact_delete',
			'cost_add', 'cost_update', 'cost_delete',
			'profit_add', 'profit_update', 'profit_remove',
			'project_finance', 'file_detach', 'project_item_pack', 'project_item_test',
		];
	}

	/** IDOR-Schutz: gehört die Unterlisten-Zeile wirklich zu diesem Projekt? */
	private static function sub_belongs( ?object $row, int $pid ): bool {
		return $row && (int) ( $row->project_id ?? 0 ) === $pid && $pid > 0;
	}

	private static function forbidden_error(): \WP_Error {
		return new \WP_Error( 'pp_forbidden', __( 'This project is not available.', 'project-prepper' ), [ 'status' => 403 ] );
	}

	/** Geldbetrag aus POST: Komma→Punkt, leer bleibt leer (→ NULL im Service). */
	private static function money_field( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		$raw = trim( sanitize_text_field( wp_unslash( (string) ( $_POST[ $key ] ?? '' ) ) ) );
		return str_replace( ',', '.', $raw );
	}

	/** Eingaben des Zeitplan-Formulars (Nonce im Dispatcher geprüft). */
	private static function schedule_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		return [
			'title'         => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_title'] ?? '' ) ) ),
			'schedule_date' => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_date'] ?? '' ) ) ),
			'time_start'    => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_time_from'] ?? '' ) ) ),
			'time_end'      => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_time_to'] ?? '' ) ) ),
			'location'      => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_location'] ?? '' ) ) ),
			'notes'         => sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_notes'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Aufgabe anlegen/ändern. Nur tatsächlich gePOSTete Felder gehen ins
	 * partielle Update — so kann der Schnell-Status-Chip NUR pp_status senden.
	 * Zuweisung wird gegen die Mitglieder der besitzenden Gruppe validiert.
	 *
	 * @return int|true|\WP_Error
	 */
	private static function member_task_save( int $pid, int $task_id ) {
		$p = self::member_owned_project( $pid );
		if ( ! $p ) {
			return self::forbidden_error();
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		$data = [];
		if ( isset( $_POST['pp_title'] ) ) {
			$data['title'] = sanitize_text_field( wp_unslash( (string) $_POST['pp_title'] ) );
		}
		if ( isset( $_POST['pp_status'] ) ) {
			$data['task_status'] = sanitize_key( wp_unslash( (string) $_POST['pp_status'] ) );
		}
		if ( isset( $_POST['pp_priority'] ) ) {
			$data['priority'] = sanitize_key( wp_unslash( (string) $_POST['pp_priority'] ) );
		}
		if ( isset( $_POST['pp_due'] ) ) {
			$data['due_date'] = sanitize_text_field( wp_unslash( (string) $_POST['pp_due'] ) );
		}
		if ( isset( $_POST['pp_assignee'] ) ) {
			$assignee = (int) $_POST['pp_assignee'];
			if ( $assignee && ! Groups::is_member( (int) $p->owner_group_id, $assignee ) ) {
				return new \WP_Error( 'pp_not_group_member', __( 'This user is not a member of the project group.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$data['assigned_user'] = $assignee;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$old = $task_id ? Tasks::get( $task_id ) : null;
		if ( $task_id && ! self::sub_belongs( $old, $pid ) ) {
			return self::forbidden_error();
		}
		// Annahme-Flow (App tab-tasks): Zuweisung an eine ANDERE Person startet
		// als 'pending' (Annehmen/Ablehnen), an sich selbst oder niemanden als
		// 'accepted'. Unveränderte Zuweisung behält ihren Status.
		if ( array_key_exists( 'assigned_user', $data ) ) {
			$uid  = get_current_user_id();
			$new  = (int) $data['assigned_user'];
			$prev = $old ? (int) $old->assigned_user : 0;
			if ( ! $new || $new === $uid ) {
				$data['assignment_status'] = 'accepted';
			} elseif ( $new !== $prev ) {
				$data['assignment_status'] = 'pending';
			}
		}
		if ( $task_id ) {
			return Tasks::update( $task_id, $data );
		}
		return Tasks::create( $pid, $data );
	}

	/** Eingaben des Material-Formulars (Nonce im Dispatcher geprüft). */
	private static function consumable_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		return [
			'name'     => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) ),
			'quantity' => str_replace( ',', '.', sanitize_text_field( wp_unslash( (string) ( $_POST['pp_quantity'] ?? '' ) ) ) ),
			'unit'     => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_unit'] ?? '' ) ) ),
			'cost'     => self::money_field( 'pp_cost' ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/** Eingaben des Crew-Formulars (Nonce im Dispatcher geprüft). */
	private static function crew_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		return [
			'name'       => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) ),
			'role'       => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_role'] ?? '' ) ) ),
			'department' => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_department'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/** Eingaben des Kontakt-Formulars (Nonce im Dispatcher geprüft). */
	private static function contact_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		return [
			'name'    => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) ),
			'role'    => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_role'] ?? '' ) ) ),
			'company' => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_company'] ?? '' ) ) ),
			'email'   => sanitize_email( wp_unslash( (string) ( $_POST['pp_email'] ?? '' ) ) ),
			'phone'   => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_phone'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/** Eingaben des Kostenposten-Formulars (Nonce im Dispatcher geprüft). */
	private static function cost_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		return [
			'category'       => sanitize_key( wp_unslash( (string) ( $_POST['pp_category'] ?? 'other' ) ) ),
			'description'    => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_description'] ?? '' ) ) ),
			'amount_planned' => self::money_field( 'pp_planned' ),
			'amount_actual'  => self::money_field( 'pp_actual' ),
			'vat_rate'       => self::money_field( 'pp_vat' ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/** Eingaben des Gewinnanteil-Formulars (Nonce im Dispatcher geprüft). */
	private static function profit_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		return [
			'share_type'  => sanitize_key( wp_unslash( (string) ( $_POST['pp_share_type'] ?? 'percentage' ) ) ),
			'share_value' => self::money_field( 'pp_share_value' ),
			'note'        => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_note'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
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

	/* ---------- Freigaben (Technik-Buchungen mit Owner-Zustimmung) ---------- */

	/**
	 * Ansicht „Freigaben": offene Freigabe-Anfragen für die EIGENEN Artikel des
	 * Users (workspace-unabhängig — es geht um Eigentum, nicht um die aktive
	 * Gruppe). Pendant zur App-`use-booking-approvals`. Jede Anfrage zeigt Projekt,
	 * Anfrager, Menge, Zeitraum + die eigenen Bedingungen (Tagessatz/Tags/Text) und
	 * bietet Annehmen/Ablehnen. Nur der Eigentümer sieht diese Liste (Service-Gate).
	 */
	private static function view_approvals( WP_User $user ): void {
		$pending = BookingApprovals::pending_for_owner( (int) $user->ID );
		$presets = MemberInventory::condition_presets();
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title"><?php esc_html_e( 'Equipment approvals', 'project-prepper' ); ?></h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Requests to use your equipment in a collective project — approve them on your own terms.', 'project-prepper' ); ?></p>
		</header>

		<?php if ( ! $pending ) : ?>
			<div class="pp-card">
				<p class="pp-portal__empty"><?php esc_html_e( 'No open approval requests. When someone books your equipment on approval, it shows up here.', 'project-prepper' ); ?></p>
			</div>
		<?php else : ?>
			<?php // Sammel-Formular: je Anfrage Freigeben/Ablehnen/Später wählen, EIN
			// Absenden entscheidet alles zusammen — der Anfrager bekommt EINE Mail
			// mit der ganzen Liste (statt einer Mail pro Gerät). ?>
			<form class="pp-approvals-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return !this.querySelector('input[value=reject]:checked') || confirm('<?php echo esc_js( __( 'Reject the selected requests? Those bookings will be removed.', 'project-prepper' ) ); ?>');">
				<?php self::action_fields( 'booking_decide_bulk' ); ?>
			<div class="pp-approvals">
				<?php foreach ( $pending as $r ) :
					$range = self::fmt_range( $r->date_from_eff, $r->date_to_eff );
					$rate  = '' !== (string) ( $r->share_daily_rate ?? '' ) ? $r->share_daily_rate : '';
					$text  = trim( (string) ( $r->conditions ?? '' ) );
					?>
					<div class="pp-approval">
						<div class="pp-approval__head">
							<span class="pp-approval__item"><?php echo esc_html( (string) $r->item_name ); ?></span>
							<?php if ( ! empty( $r->inventory_number ) ) : ?>
								<span class="pp-portal__item-num"><?php echo esc_html( (string) $r->inventory_number ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $r->bundle_name ) ) : ?>
								<span class="pp-bundle-chip"><?php
									/* translators: %s: name of the set this booking line belongs to. */
									printf( esc_html__( 'Set “%s”', 'project-prepper' ), esc_html( (string) $r->bundle_name ) );
								?></span>
							<?php endif; ?>
							<span class="pp-appr-chip pp-appr--pending"><?php esc_html_e( 'Pending', 'project-prepper' ); ?></span>
						</div>
						<div class="pp-approval__meta">
							<?php
							echo esc_html( (string) $r->project_name );
							if ( '' !== (string) $r->requester_name ) {
								/* translators: %s: requester name. */
								echo ' · ' . esc_html( sprintf( __( 'requested by %s', 'project-prepper' ), $r->requester_name ) );
							}
							/* translators: %d: quantity. */
							echo ' · ' . esc_html( sprintf( __( 'Qty %d', 'project-prepper' ), (int) $r->quantity ) );
							if ( '' !== $range ) {
								echo ' · ' . esc_html( $range );
							}
							?>
						</div>
						<?php if ( ! empty( $r->conditions_tags ) || '' !== (string) $rate || '' !== $text ) : ?>
							<div class="pp-approval__cond">
								<span class="pp-approval__cond-label"><?php esc_html_e( 'Your terms:', 'project-prepper' ); ?></span>
								<?php self::render_condition_chips( (array) $r->conditions_tags, $presets ); ?>
								<?php if ( '' !== (string) $rate ) : ?>
									<span class="pp-cond-chip">
										<?php
										/* translators: %s: daily rate in euros. */
										echo esc_html( sprintf( __( '%s €/day', 'project-prepper' ), number_format_i18n( (float) $rate, 2 ) ) );
										?>
									</span>
								<?php endif; ?>
								<?php if ( '' !== $text ) : ?>
									<span class="pp-approval__cond-text"><?php echo esc_html( $text ); ?></span>
								<?php endif; ?>
							</div>
						<?php endif; ?>
						<div class="pp-portal__actions pp-approval__decide" role="radiogroup" aria-label="<?php esc_attr_e( 'Decision', 'project-prepper' ); ?>">
							<label class="pp-portal__chip"><input type="radio" name="pp_decide[<?php echo (int) $r->line_id; ?>]" value="approve" hidden> <?php esc_html_e( 'Approve', 'project-prepper' ); ?></label>
							<label class="pp-portal__chip"><input type="radio" name="pp_decide[<?php echo (int) $r->line_id; ?>]" value="reject" hidden> <?php esc_html_e( 'Reject', 'project-prepper' ); ?></label>
							<label class="pp-portal__chip"><input type="radio" name="pp_decide[<?php echo (int) $r->line_id; ?>]" value="" hidden checked> <?php esc_html_e( 'Decide later', 'project-prepper' ); ?></label>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="pp-portal__actions pp-approvals-form__submit">
				<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Send decisions', 'project-prepper' ); ?></button>
				<span class="pp-portal__hint"><?php esc_html_e( 'Each requester gets one email listing all your decisions.', 'project-prepper' ); ?></span>
			</div>
			</form>
		<?php endif; ?>
		<?php
	}

	/** Bedingungs-Tags als kleine Chips (Preset-Label, Fallback = Schlüssel). */
	private static function render_condition_chips( array $tags, array $presets ): void {
		foreach ( $tags as $tag ) {
			$tag = (string) $tag;
			echo '<span class="pp-cond-chip">' . esc_html( $presets[ $tag ] ?? $tag ) . '</span>';
		}
	}

	/**
	 * Freigabe-Status-Chip einer Buchungszeile (Anfrager-Seite). „Freigegeben" wird
	 * nur gezeigt, wenn die Zeile tatsächlich einen Freigabe-Durchlauf hatte
	 * (decided_at gesetzt) — auto-freigegebene Buchungen bleiben unmarkiert.
	 */
	private static function approval_chip( object $line ): void {
		$status = (string) ( $line->approval_status ?? 'approved' );
		if ( 'pending' === $status ) {
			echo '<span class="pp-appr-chip pp-appr--pending">' . esc_html__( 'Pending', 'project-prepper' ) . '</span>';
		} elseif ( 'approved' === $status && ! empty( $line->decided_at ) ) {
			echo '<span class="pp-appr-chip pp-appr--approved">' . esc_html__( 'Approved', 'project-prepper' ) . '</span>';
		}
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

		<?php
		// Reiter wie die App (gleiche Aufteilung + Reihenfolge). Auswahl über
		// ?pp_tab=…, serverseitig gerendert — funktioniert ohne JS; der
		// Dispatcher reicht den aktiven Reiter über Redirects weiter (Referer).
		$tabs = [
			'overview'   => __( 'Overview', 'project-prepper' ),
			'schedule'   => __( 'Schedule', 'project-prepper' ),
			'equipment'  => __( 'Equipment', 'project-prepper' ),
			'packlist'   => __( 'Packing list', 'project-prepper' ),
			'team'       => __( 'Team & contacts', 'project-prepper' ),
			'materials'  => __( 'Materials', 'project-prepper' ),
			'costs'      => __( 'Costs', 'project-prepper' ),
			'checklists' => __( 'Checklists', 'project-prepper' ),
			'tasks'      => __( 'Tasks', 'project-prepper' ),
			'polls'      => __( 'Polls', 'project-prepper' ),
			'agreement'  => __( 'Agreement', 'project-prepper' ),
			'files'      => __( 'Files', 'project-prepper' ),
			'profit'     => __( 'Profit', 'project-prepper' ),
		];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige-Auswahl.
		$tab = sanitize_key( wp_unslash( (string) ( $_GET['pp_tab'] ?? 'overview' ) ) );
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}
		$tab_base = add_query_arg( [ 'pp_view' => 'projects', 'pp_project' => (int) $p->id ], self::portal_url() );
		?>
		<nav class="pp-proj-tabs" aria-label="<?php esc_attr_e( 'Project sections', 'project-prepper' ); ?>">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a class="pp-proj-tabs__tab<?php echo $key === $tab ? ' pp-proj-tabs__tab--on' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url( 'overview' === $key ? $tab_base : add_query_arg( 'pp_tab', $key, $tab_base ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php if ( 'overview' === $tab && (int) $p->owner_group_id === self::active_workspace_group() ) : ?>
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
		if ( 'overview' === $tab && $has_overview ) :
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

		// Bearbeiten-Gate aller interaktiven Sektionen: das Projekt gehört zum
		// AKTIVEN Gruppen-Workspace (sonst read-only, Muster Equipment-Buchung).
		$can_edit = (int) $p->owner_group_id === self::active_workspace_group();

		// 2) Gebuchtes Equipment — im aktiven Workspace kann direkt gebucht,
		// geändert und entfernt werden (Pendant zum Equipment-Tab der App).
		$can_book = $can_edit;
		$pool     = 'equipment' === $tab && $can_book ? self::bookable_pool( $p ) : [];
		if ( 'equipment' === $tab && ( ! empty( $p->items ) || $can_book ) ) :
			$has_period = '' !== (string) $p->date_start && '' !== (string) $p->date_end;
			// Im Projekt bereits gebuchte Stückzahl je Artikel — für „noch frei".
			// Set-Zeilen (bundle_item_id) zählen über ihre TEILE mit und werden
			// zusätzlich je Set gesammelt (gruppierte Anzeige + „Sets gebucht").
			$booked_qty    = [];
			$single_lines  = [];
			$bundle_lines  = [];
			foreach ( (array) $p->items as $line ) {
				$booked_qty[ (int) $line->item_id ] = ( $booked_qty[ (int) $line->item_id ] ?? 0 ) + (int) $line->quantity;
				if ( ! empty( $line->bundle_item_id ) ) {
					$bundle_lines[ (int) $line->bundle_item_id ][] = $line;
				} else {
					$single_lines[] = $line;
				}
			}
			$booked_bundle_parts = $bundle_lines ? Bundles::for_items( array_keys( $bundle_lines ) ) : [];
			// Gebuchte SET-Anzahl je Set = min über Teil-Zeilen floor(Menge/Bedarf).
			$booked_sets = [];
			foreach ( $bundle_lines as $pp_bid => $pp_blines ) {
				$pp_need = [];
				foreach ( $booked_bundle_parts[ $pp_bid ] ?? [] as $pp_part ) {
					$pp_need[ (int) $pp_part->part_item_id ] = max( 1, (int) $pp_part->quantity );
				}
				$pp_min = PHP_INT_MAX;
				foreach ( $pp_blines as $pp_l ) {
					$pp_min = min( $pp_min, (int) floor( (int) $pp_l->quantity / ( $pp_need[ (int) $pp_l->item_id ] ?? 1 ) ) );
				}
				$booked_sets[ $pp_bid ] = PHP_INT_MAX === $pp_min ? 0 : max( 0, $pp_min );
			}
			?>
			<section class="pp-card">
				<h3 class="pp-card__title"><?php esc_html_e( 'Booked equipment', 'project-prepper' ); ?></h3>
				<?php if ( empty( $p->items ) ) : ?>
					<p class="pp-portal__empty"><?php esc_html_e( 'No equipment booked yet.', 'project-prepper' ); ?></p>
				<?php else : ?>
					<div class="pp-rows">
						<?php foreach ( $single_lines as $line ) :
							$lrange = self::fmt_range( $line->date_from, $line->date_to ); ?>
							<div class="pp-row">
								<span class="pp-row__main"><?php echo esc_html( $line->item_name ?: ( '#' . (int) $line->item_id ) ); ?></span>
								<?php if ( ! empty( $line->inventory_number ) ) : ?>
									<span class="pp-portal__item-num"><?php echo esc_html( $line->inventory_number ); ?></span>
								<?php endif; ?>
								<?php self::approval_chip( $line ); ?>
								<span class="pp-row__meta">
									<?php
									/* translators: %d: quantity. */
									printf( esc_html__( 'Qty %d', 'project-prepper' ), (int) $line->quantity );
									if ( '' !== $lrange ) {
										echo ' · ' . esc_html( $lrange );
									}
									if ( '' !== trim( (string) $line->notes ) ) {
										echo ' · ' . esc_html( $line->notes );
									}
									?>
								</span>
								<?php if ( $can_book ) : ?>
									<details class="pp-portal__edit">
										<summary class="pp-portal__chip"><?php esc_html_e( 'Edit', 'project-prepper' ); ?></summary>
										<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<?php self::action_fields( 'project_item_update' ); ?>
											<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
											<input type="hidden" name="pp_line" value="<?php echo (int) $line->id; ?>">
											<div class="pp-portal__form-row">
												<label><?php esc_html_e( 'Qty', 'project-prepper' ); ?>
													<input type="number" name="pp_quantity" min="1" value="<?php echo (int) $line->quantity; ?>">
												</label>
												<label><?php esc_html_e( 'From', 'project-prepper' ); ?>
													<input type="date" name="pp_from" value="<?php echo esc_attr( '' !== (string) $line->date_from ? (string) $line->date_from : substr( (string) $p->date_start, 0, 10 ) ); ?>">
												</label>
												<label><?php esc_html_e( 'To', 'project-prepper' ); ?>
													<input type="date" name="pp_to" value="<?php echo esc_attr( '' !== (string) $line->date_to ? (string) $line->date_to : substr( (string) $p->date_end, 0, 10 ) ); ?>">
												</label>
											</div>
											<label><?php esc_html_e( 'Notes', 'project-prepper' ); ?>
												<input type="text" name="pp_notes" value="<?php echo esc_attr( (string) $line->notes ); ?>">
											</label>
											<p class="pp-portal__hint"><?php esc_html_e( 'Prefilled with the project period — only change this if these items are needed for a different period.', 'project-prepper' ); ?></p>
											<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
										</form>
									</details>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this booking?', 'project-prepper' ) ); ?>');">
										<?php self::action_fields( 'project_item_remove' ); ?>
										<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
										<input type="hidden" name="pp_line" value="<?php echo (int) $line->id; ?>">
										<button type="submit" class="pp-portal__chip"><?php esc_html_e( 'Remove', 'project-prepper' ); ?></button>
									</form>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>

						<?php // Gebuchte SETS: eine Gruppe je Set — die Teil-Zeilen darunter,
						// Aktionen (Ändern/Entfernen) nur auf Set-Ebene (docs/07 §5).
						foreach ( $bundle_lines as $pp_bid => $pp_blines ) :
							$pp_first  = $pp_blines[0];
							$pp_binfo  = Inventory::get_item( (int) $pp_bid );
							$pp_bname  = $pp_binfo ? (string) $pp_binfo->name : ( '#' . (int) $pp_bid );
							$pp_sets_n = $booked_sets[ $pp_bid ] ?? 1;
							$pp_range  = self::fmt_range( $pp_first->date_from, $pp_first->date_to );
							$pp_pend   = (bool) count( array_filter( $pp_blines, static fn( $l ) => 'pending' === (string) $l->approval_status ) );
							?>
							<div class="pp-row pp-bundle-group">
								<span class="pp-row__main"><?php echo esc_html( $pp_bname ); ?></span>
								<span class="pp-bundle-chip"><?php esc_html_e( 'Set', 'project-prepper' ); ?></span>
								<?php if ( $pp_pend ) : ?>
									<span class="pp-appr-chip pp-appr--pending"><?php esc_html_e( 'Pending', 'project-prepper' ); ?></span>
								<?php else : ?>
									<?php self::approval_chip( $pp_first ); ?>
								<?php endif; ?>
								<span class="pp-row__meta">
									<?php
									/* translators: %d: number of sets booked. */
									printf( esc_html__( '%d× set', 'project-prepper' ), (int) $pp_sets_n );
									if ( '' !== $pp_range ) {
										echo ' · ' . esc_html( $pp_range );
									}
									if ( '' !== trim( (string) $pp_first->notes ) ) {
										echo ' · ' . esc_html( $pp_first->notes );
									}
									?>
								</span>
								<?php if ( $can_book ) : ?>
									<details class="pp-portal__edit">
										<summary class="pp-portal__chip"><?php esc_html_e( 'Edit', 'project-prepper' ); ?></summary>
										<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<?php self::action_fields( 'project_bundle_update' ); ?>
											<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
											<input type="hidden" name="pp_bundle" value="<?php echo (int) $pp_bid; ?>">
											<div class="pp-portal__form-row">
												<label><?php esc_html_e( 'Sets', 'project-prepper' ); ?>
													<input type="number" name="pp_quantity" min="1" value="<?php echo (int) max( 1, $pp_sets_n ); ?>">
												</label>
												<label><?php esc_html_e( 'From', 'project-prepper' ); ?>
													<input type="date" name="pp_from" value="<?php echo esc_attr( '' !== (string) $pp_first->date_from ? (string) $pp_first->date_from : substr( (string) $p->date_start, 0, 10 ) ); ?>">
												</label>
												<label><?php esc_html_e( 'To', 'project-prepper' ); ?>
													<input type="date" name="pp_to" value="<?php echo esc_attr( '' !== (string) $pp_first->date_to ? (string) $pp_first->date_to : substr( (string) $p->date_end, 0, 10 ) ); ?>">
												</label>
											</div>
											<label><?php esc_html_e( 'Notes', 'project-prepper' ); ?>
												<input type="text" name="pp_notes" value="<?php echo esc_attr( (string) $pp_first->notes ); ?>">
											</label>
											<p class="pp-portal__hint"><?php esc_html_e( 'Changes apply to all items of this set.', 'project-prepper' ); ?></p>
											<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
										</form>
									</details>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this set booking? All its items will be removed.', 'project-prepper' ) ); ?>');">
										<?php self::action_fields( 'project_bundle_remove' ); ?>
										<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
										<input type="hidden" name="pp_bundle" value="<?php echo (int) $pp_bid; ?>">
										<button type="submit" class="pp-portal__chip"><?php esc_html_e( 'Remove', 'project-prepper' ); ?></button>
									</form>
								<?php endif; ?>
								<div class="pp-bundle-group__parts">
									<?php foreach ( $pp_blines as $pp_l ) : ?>
										<span class="pp-bundle-group__part">
											<?php
											/* translators: 1: quantity, 2: item name. */
											echo esc_html( sprintf( __( '%1$d× %2$s', 'project-prepper' ), (int) $pp_l->quantity, (string) ( $pp_l->item_name ?: ( '#' . (int) $pp_l->item_id ) ) ) );
											?>
										</span>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

			</section>

			<?php if ( $can_book && $pool ) : ?>
				<section class="pp-card">
					<h3 class="pp-card__title"><?php esc_html_e( 'Book equipment', 'project-prepper' ); ?></h3>
					<p class="pp-portal__hint"><?php esc_html_e( 'Tick every item you want and set its quantity — you can book several at once.', 'project-prepper' ); ?></p>
					<form class="pp-portal__form pp-book-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-pp-live data-pp-live-scope>
						<?php self::action_fields( 'project_item_add' ); ?>
						<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
						<div data-pp-picker>
						<?php self::picker_summary(); ?>
						<input type="search" class="pp-book-search" placeholder="<?php esc_attr_e( 'Search equipment…', 'project-prepper' ); ?>" aria-label="<?php esc_attr_e( 'Search equipment…', 'project-prepper' ); ?>">
						<div class="pp-book-list">
							<?php $presets = MemberInventory::condition_presets(); ?>
							<?php $pool_bundles = Bundles::for_items( array_keys( $pool ) ); ?>
							<?php foreach ( $pool as $item ) :
								// Inventarnummer rendert die gemeinsame Zeile selbst (picker_row).
								$bits = [];
								if ( '' !== (string) ( $item->owner_name ?? '' ) ) {
									/* translators: %s: owner name of the shared item. */
									$bits[] = sprintf( __( 'by %s', 'project-prepper' ), $item->owner_name );
								}
								$pp_parts = $pool_bundles[ (int) $item->id ] ?? [];
								if ( $pp_parts ) {
									// SET (docs/07 §3): verfügbare Set-Anzahl aus den Teilen —
									// min über floor(frei(Teil)/Bedarf), minus bereits gebuchte Sets.
									$free = Bundles::available_sets(
										$pp_parts,
										$has_period ? substr( (string) $p->date_start, 0, 10 ) : '',
										$has_period ? substr( (string) $p->date_end, 0, 10 ) : '',
										(int) $p->id
									);
									$free = max( 0, $free - (int) ( $booked_sets[ (int) $item->id ] ?? 0 ) );
									/* translators: %d: number of complete sets available. */
									$bits[] = sprintf( __( '%d sets free', 'project-prepper' ), $free );
								} elseif ( $has_period ) {
									$free = Availability::available_quantity( (int) $item->id, (string) $p->date_start, (string) $p->date_end, 0, (int) $p->id );
									$free = max( 0, $free - ( $booked_qty[ (int) $item->id ] ?? 0 ) );
									/* translators: %d: available quantity in the project period. */
									$bits[] = sprintf( __( '%d free', 'project-prepper' ), $free );
								} else {
									/* translators: %d: total quantity. */
									$bits[] = sprintf( __( '%d× total', 'project-prepper' ), (int) $item->quantity );
								}
								$rate = '' !== (string) ( $item->share_daily_rate ?? '' ) ? $item->share_daily_rate : ( $item->cost_per_day ?? '' );
								if ( '' !== (string) $rate ) {
									/* translators: %s: daily rate in euros. */
									$bits[] = sprintf( __( '%s €/day', 'project-prepper' ), number_format_i18n( (float) $rate, 2 ) );
								}
								// Ist dieser Artikel für dieses Projekt bereits gebucht? (Sets: in SETS gezählt.)
								$already = $pp_parts ? (int) ( $booked_sets[ (int) $item->id ] ?? 0 ) : (int) ( $booked_qty[ (int) $item->id ] ?? 0 );
								// Noch buchbare Menge: Sets immer berechnet; sonst im Zeitraum
								// frei bzw. Gesamtbestand minus schon gebucht.
								$avail = ( $pp_parts || $has_period ) ? (int) $free : max( 0, (int) $item->quantity - $already );
								self::picker_row(
									$item,
									static function () use ( $item, $avail ) {
										?>
										<input type="checkbox" name="pp_items[]" value="<?php echo (int) $item->id; ?>"<?php disabled( $avail <= 0 ); ?>>
										<?php
									},
									[
										'is_set'   => (bool) $pp_parts,
										'meta'     => $bits,
										/* translators: %s: list of set parts, e.g. "3× link · 1× feed". */
										'sub'      => $pp_parts ? sprintf( __( 'Set of %s', 'project-prepper' ), Bundles::parts_label( $pp_parts ) ) : '',
										/* translators: %d: quantity already booked for this project. */
										'badge'    => $already > 0 ? sprintf( __( 'already booked (%d×)', 'project-prepper' ), (int) $already ) : '',
										'booked'   => $already > 0,
										'muted'    => $avail <= 0,
										'after'    => static function () use ( $item, $presets ) {
											// Freigabe-Bedingungen des Eigentümers sichtbar machen (Pendant
											// zum App-Leih-Modal): Bedingungs-Chips, Freitext, Freigabe-Hinweis.
											$cond_tags = (array) ( $item->conditions_tags ?? [] );
											$cond_text = trim( (string) ( $item->conditions ?? '' ) );
											// „Freigabe erforderlich" nur, wenn der Artikel jemand ANDEREM
											// gehört — für eigene Artikel wird sowieso auto-freigegeben
											// (spiegelt die Entscheidung in member_book_equipment).
											$req_appr = ! empty( $item->requires_approval ) && (int) ( $item->shared_by ?? 0 ) !== get_current_user_id();
											if ( ! $cond_tags && '' === $cond_text && ! $req_appr ) {
												return;
											}
											?>
											<span class="pp-book-item__cond">
												<?php if ( $req_appr ) : ?>
													<span class="pp-book-item__approval"><?php esc_html_e( 'Requires approval', 'project-prepper' ); ?></span>
												<?php endif; ?>
												<?php self::render_condition_chips( $cond_tags, $presets ); ?>
												<?php if ( '' !== $cond_text ) : ?>
													<span class="pp-book-item__cond-text" title="<?php echo esc_attr( $cond_text ); ?>"><?php echo esc_html( $cond_text ); ?></span>
												<?php endif; ?>
											</span>
											<?php
										},
										'controls' => static function () use ( $item, $avail ) {
											?>
											<input type="number" class="pp-book-item__qty" name="pp_qty[<?php echo (int) $item->id; ?>]" min="1" max="<?php echo (int) $avail; ?>" value="1" aria-label="<?php esc_attr_e( 'Quantity', 'project-prepper' ); ?>"<?php disabled( $avail <= 0 ); ?>>
											<?php
										},
									]
								);
								?>
							<?php endforeach; ?>
						</div>
						<p class="pp-book-none pp-portal__hint" data-pp-search-none hidden><?php esc_html_e( 'No items match your search.', 'project-prepper' ); ?></p>
						</div>
						<div class="pp-portal__form-row">
							<label><?php esc_html_e( 'From', 'project-prepper' ); ?>
								<input type="date" name="pp_from" value="<?php echo esc_attr( substr( (string) $p->date_start, 0, 10 ) ); ?>">
							</label>
							<label><?php esc_html_e( 'To', 'project-prepper' ); ?>
								<input type="date" name="pp_to" value="<?php echo esc_attr( substr( (string) $p->date_end, 0, 10 ) ); ?>">
							</label>
						</div>
						<p class="pp-portal__hint"><?php echo $has_period
							? esc_html__( 'Prefilled with the project period — only change this if these items are needed for a different period.', 'project-prepper' )
							: esc_html__( 'Leave both dates empty to book for the whole project period.', 'project-prepper' ); ?></p>
						<label><?php esc_html_e( 'Notes', 'project-prepper' ); ?>
							<input type="text" name="pp_notes">
						</label>
						<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Book selected equipment', 'project-prepper' ); ?></button>
					</form>
				</section>
			<?php elseif ( $can_book ) : ?>
				<section class="pp-card">
					<p class="pp-portal__hint"><?php esc_html_e( 'No equipment is shared with this collective yet. Members share items from “My inventory” in their solo workspace.', 'project-prepper' ); ?></p>
				</section>
			<?php endif;
		endif;

		// Übrige Reiter — Zuordnung wie die App-Tabs. Kosten/Gewinn: alle
		// Betrachter dieses Details sind aktive Mitglieder der besitzenden
		// Gruppe (oben erzwungen) — WP-Pendant zu canViewCosts=isMember der
		// App, kein Finanz-Leak gegen Nicht-Mitglieder.
		$g_members = $can_edit ? Groups::members( (int) $p->owner_group_id ) : [];
		switch ( $tab ) {
			case 'packlist':
				self::render_project_packlist( $p, $can_edit );
				break;
			case 'schedule':
				self::render_project_schedule( $p, $can_edit );
				break;
			case 'tasks':
				self::render_project_tasks( $p, $can_edit, $g_members );
				break;
			case 'checklists':
				self::render_project_checklists( $p, $can_edit );
				break;
			case 'materials':
				self::render_project_materials( $p, $can_edit );
				break;
			case 'team':
				self::render_project_team( $p, $can_edit );
				self::render_project_contacts( $p, $can_edit );
				self::render_project_members( $p );
				break;
			case 'files':
				self::render_project_files_section( $p, $can_edit );
				break;
			case 'costs':
				self::render_project_costs( $p, $can_edit );
				break;
			case 'profit':
				self::render_project_profit( $p, $can_edit, $g_members );
				break;
			case 'agreement':
				self::render_project_agreement_summary( $p );
				self::render_decisions( $p );
				break;
			case 'polls':
				self::render_polls( $p );
				break;
		}
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

	/* ---------- Projekt-Unterlisten (interaktiv im aktiven Workspace) ---------- */

	/** Kleines Chip-Formular für eine Zeilen-Aktion (Löschen/Status). */
	private static function sub_chip_form( string $do, int $pid, array $hidden, string $label, string $confirm = '' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" <?php if ( '' !== $confirm ) : ?>onsubmit="return confirm('<?php echo esc_js( $confirm ); ?>');"<?php endif; ?>>
			<?php self::action_fields( $do ); ?>
			<input type="hidden" name="pp_project" value="<?php echo (int) $pid; ?>">
			<?php foreach ( $hidden as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<button type="submit" class="pp-portal__chip"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Hoch/Runter-Chips für manuelle Reihenfolge (App: Drag-Sortierung über
	 * sort_order — hier bewusst ohne Drag&Drop-Bibliothek). Die Pfeile sind
	 * die Funktion selbst, keine Dekoration.
	 */
	private static function move_chips( string $do, int $pid, array $hidden ): void {
		$dirs = [
			'up'   => [ '↑', __( 'Move up', 'project-prepper' ) ],
			'down' => [ '↓', __( 'Move down', 'project-prepper' ) ],
		];
		foreach ( $dirs as $dir => [ $glyph, $label ] ) :
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pp-move">
				<?php self::action_fields( $do ); ?>
				<input type="hidden" name="pp_project" value="<?php echo (int) $pid; ?>">
				<?php foreach ( $hidden as $name => $value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
				<?php endforeach; ?>
				<input type="hidden" name="pp_dir" value="<?php echo esc_attr( $dir ); ?>">
				<button type="submit" class="pp-portal__chip pp-move__btn" title="<?php echo esc_attr( $label ); ?>" aria-label="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $glyph ); ?></button>
			</form>
			<?php
		endforeach;
	}

	/**
	 * Packliste — druckfertige (A4) Liste des gebuchten Equipments. Spalten von
	 * links: Anzahl · Foto · Beschreibung + Inventar-Nr. · Zustand · gepackt.
	 * „gepackt" wird pro Zeile gespeichert (packed_at) und ist für alle sichtbar.
	 */
	private static function render_project_packlist( object $p, bool $can_edit ): void {
		$lines        = (array) ( $p->items ?? [] );
		$conditions   = Shortcodes::condition_labels();
		// Set-Herkunft als Vermerk (docs/07 §5): Namen der Sets, aus denen Zeilen
		// expandiert wurden — gepackt/getestet werden die Teile einzeln.
		$pp_bundle_ids   = array_values( array_unique( array_filter( array_map( static fn( $l ) => (int) ( $l->bundle_item_id ?? 0 ), $lines ) ) ) );
		$pp_bundle_names = [];
		foreach ( $pp_bundle_ids as $pp_bid ) {
			$pp_b = Inventory::get_item( $pp_bid );
			$pp_bundle_names[ $pp_bid ] = $pp_b ? (string) $pp_b->name : ( '#' . $pp_bid );
		}
		$packed_lines = 0;
		$tested_lines = 0;
		foreach ( $lines as $l ) {
			if ( ! empty( $l->packed_at ) ) {
				$packed_lines++;
			}
			if ( ! empty( $l->tested_at ) ) {
				$tested_lines++;
			}
		}
		$range = self::fmt_range( $p->date_start, $p->date_end );
		?>
		<section class="pp-card pp-packlist">
			<div class="pp-packlist__head">
				<h3 class="pp-card__title"><?php esc_html_e( 'Packing list', 'project-prepper' ); ?></h3>
				<?php if ( $lines ) : ?>
					<button type="button" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm pp-pack-print" onclick="window.print()"><?php esc_html_e( 'Print', 'project-prepper' ); ?></button>
				<?php endif; ?>
			</div>

			<?php // Nur im Druck sichtbarer Kopf (A4) — identifiziert das Projekt auf dem Ausdruck. ?>
			<div class="pp-packlist__print-head" aria-hidden="true">
				<strong><?php echo esc_html( $p->name ); ?></strong>
				<span><?php echo esc_html( $p->project_number . ( '' !== $range ? ' · ' . $range : '' ) ); ?></span>
			</div>

			<?php if ( ! $lines ) : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'No equipment booked yet — nothing to pack.', 'project-prepper' ); ?></p>
			<?php else : ?>
				<p class="pp-packlist__summary">
					<?php
					/* translators: 1: packed count, 2: total count, 3: tested count. */
					printf( esc_html__( '%1$d of %2$d packed · %3$d tested', 'project-prepper' ), (int) $packed_lines, count( $lines ), (int) $tested_lines );
					?>
				</p>
				<div class="pp-packlist__table" role="table">
					<div class="pp-pack-row pp-pack-row--head" role="row">
						<span class="pp-pack-col pp-pack-col--qty" role="columnheader"><?php esc_html_e( 'Qty', 'project-prepper' ); ?></span>
						<span class="pp-pack-col pp-pack-col--photo" role="columnheader"><?php esc_html_e( 'Photo', 'project-prepper' ); ?></span>
						<span class="pp-pack-col pp-pack-col--desc" role="columnheader"><?php esc_html_e( 'Item', 'project-prepper' ); ?></span>
						<span class="pp-pack-col pp-pack-col--cond" role="columnheader"><?php esc_html_e( 'Condition', 'project-prepper' ); ?></span>
						<span class="pp-pack-col pp-pack-col--pack" role="columnheader"><?php esc_html_e( 'Packed', 'project-prepper' ); ?></span>
						<span class="pp-pack-col pp-pack-col--test" role="columnheader"><?php esc_html_e( 'Tested', 'project-prepper' ); ?></span>
					</div>
					<?php foreach ( $lines as $line ) :
						$is_packed = ! empty( $line->packed_at );
						$img       = ! empty( $line->image_id ) ? ( wp_get_attachment_image_url( (int) $line->image_id, 'thumbnail' ) ?: '' ) : '';
						$cond      = $conditions[ $line->item_condition ] ?? (string) $line->item_condition;
						$desc      = trim( (string) ( $line->item_description ?? '' ) );
						?>
						<div class="pp-pack-row<?php echo $is_packed ? ' pp-pack-row--done' : ''; ?>" role="row">
							<?php $is_tested = ! empty( $line->tested_at ); ?>
							<span class="pp-pack-col pp-pack-col--qty" role="cell"><?php echo (int) $line->quantity; ?>×</span>
							<span class="pp-pack-col pp-pack-col--photo" role="cell">
								<?php if ( '' !== $img ) : ?>
									<img class="pp-pack-thumb" src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy">
								<?php else : ?>
									<span class="pp-pack-thumb pp-pack-thumb--empty" aria-hidden="true"></span>
								<?php endif; ?>
							</span>
							<span class="pp-pack-col pp-pack-col--desc" role="cell">
								<span class="pp-pack-name"><?php echo esc_html( $line->item_name ?: ( '#' . (int) $line->item_id ) ); ?></span>
								<?php if ( ! empty( $line->inventory_number ) ) : ?>
									<span class="pp-pack-num"><?php echo esc_html( $line->inventory_number ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $line->bundle_item_id ) && isset( $pp_bundle_names[ (int) $line->bundle_item_id ] ) ) : ?>
									<span class="pp-pack-desc"><?php
										/* translators: %s: name of the set this line was booked from. */
										printf( esc_html__( 'from set “%s”', 'project-prepper' ), esc_html( $pp_bundle_names[ (int) $line->bundle_item_id ] ) );
									?></span>
								<?php endif; ?>
								<?php if ( '' !== $desc ) : ?>
									<span class="pp-pack-desc"><?php echo esc_html( $desc ); ?></span>
								<?php endif; ?>
							</span>
							<span class="pp-pack-col pp-pack-col--cond" role="cell"><?php echo esc_html( $cond ); ?></span>
							<?php
							self::packlist_flag_cell( $p, $line, $is_packed, $can_edit, [
								'col'       => 'pack',
								'action'    => 'project_item_pack',
								'field'     => 'pp_packed',
								'label_on'  => __( 'Packed', 'project-prepper' ),
								'label_off' => __( 'Mark packed', 'project-prepper' ),
							] );
							self::packlist_flag_cell( $p, $line, $is_tested, $can_edit, [
								'col'       => 'test',
								'action'    => 'project_item_test',
								'field'     => 'pp_tested',
								'label_on'  => __( 'Tested', 'project-prepper' ),
								'label_off' => __( 'Mark tested', 'project-prepper' ),
							] );
							?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Eine Packlisten-Statuszelle (gepackt oder getestet): Druck-Kästchen +
	 * Toggle-Button (bzw. read-only Status). $cfg: col ('pack'|'test'), action
	 * (pp_do), field (Hidden-Feldname), label_on/label_off (bereits übersetzt).
	 */
	private static function packlist_flag_cell( object $p, object $line, bool $on, bool $can_edit, array $cfg ): void {
		?>
		<span class="pp-pack-col pp-pack-col--<?php echo esc_attr( $cfg['col'] ); ?>" role="cell">
			<?php // Kästchen für den Ausdruck: gefüllt = erledigt. ?>
			<span class="pp-pack-box<?php echo $on ? ' pp-pack-box--on' : ''; ?>" aria-hidden="true"></span>
			<?php if ( $can_edit ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pp-pack-form">
					<?php self::action_fields( $cfg['action'] ); ?>
					<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
					<input type="hidden" name="pp_line" value="<?php echo (int) $line->id; ?>">
					<input type="hidden" name="<?php echo esc_attr( $cfg['field'] ); ?>" value="<?php echo $on ? '0' : '1'; ?>">
					<button type="submit" class="pp-portal__chip<?php echo $on ? ' pp-portal__chip--done' : ''; ?>"><?php
						echo $on ? esc_html( $cfg['label_on'] ) . ' ✓' : esc_html( $cfg['label_off'] );
					?></button>
				</form>
			<?php else : ?>
				<span class="pp-pack-status"><?php echo $on ? esc_html( $cfg['label_on'] ) : esc_html__( 'Open', 'project-prepper' ); ?></span>
			<?php endif; ?>
		</span>
		<?php
	}

	/** Zeitplan — nach Tag gruppiert (wie der Zeitplan-Tab der App). */
	private static function render_project_schedule( object $p, bool $can_edit ): void {
		$entries = (array) ( $p->schedule ?? [] );
		if ( ! $entries && ! $can_edit ) {
			return;
		}
		$days = [];
		foreach ( $entries as $s ) {
			$days[ (string) ( $s->schedule_date ?? '' ) ][] = $s;
		}
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Schedule', 'project-prepper' ); ?></h3>
			<?php if ( ! $entries ) : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'No schedule entries yet.', 'project-prepper' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $days as $day => $list ) : ?>
				<p class="pp-sched-day"><?php echo esc_html( '' !== $day ? self::fmt_date( $day ) : __( 'No date', 'project-prepper' ) ); ?></p>
				<div class="pp-rows">
					<?php foreach ( $list as $s ) :
						$time = trim( substr( (string) $s->time_start, 0, 5 ) . ( ! empty( $s->time_end ) ? '–' . substr( (string) $s->time_end, 0, 5 ) : '' ), '–' ); ?>
						<div class="pp-row">
							<span class="pp-row__main"><?php echo esc_html( $s->title ); ?></span>
							<?php if ( ! empty( $s->location ) ) : ?>
								<span class="pp-muted-inline"><?php echo esc_html( $s->location ); ?></span>
							<?php endif; ?>
							<span class="pp-row__meta">
								<?php
								echo esc_html( $time );
								if ( '' !== trim( (string) $s->notes ) ) {
									echo ( '' !== $time ? ' · ' : '' ) . esc_html( $s->notes );
								}
								?>
							</span>
							<?php if ( $can_edit ) : ?>
								<?php self::move_chips( 'sched_move', (int) $p->id, [ 'pp_entry' => (int) $s->id ] ); ?>
								<details class="pp-portal__edit">
									<summary class="pp-portal__chip"><?php esc_html_e( 'Edit', 'project-prepper' ); ?></summary>
									<?php self::schedule_form( $p, $s ); ?>
								</details>
								<?php self::sub_chip_form( 'sched_delete', (int) $p->id, [ 'pp_entry' => (int) $s->id ], __( 'Remove', 'project-prepper' ), __( 'Remove this schedule entry?', 'project-prepper' ) ); ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
			<?php if ( $can_edit ) : ?>
				<details class="pp-portal__add">
					<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Add schedule entry', 'project-prepper' ); ?></summary>
					<?php self::schedule_form( $p, null ); ?>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function schedule_form( object $p, ?object $s ): void {
		?>
		<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::action_fields( $s ? 'sched_update' : 'sched_add' ); ?>
			<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
			<?php if ( $s ) : ?>
				<input type="hidden" name="pp_entry" value="<?php echo (int) $s->id; ?>">
			<?php endif; ?>
			<label><?php esc_html_e( 'Title', 'project-prepper' ); ?>
				<input type="text" name="pp_title" value="<?php echo esc_attr( $s ? (string) $s->title : '' ); ?>" required>
			</label>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Date', 'project-prepper' ); ?>
					<input type="date" name="pp_date" value="<?php echo esc_attr( $s ? (string) $s->schedule_date : (string) $p->date_start ); ?>">
				</label>
				<label><?php esc_html_e( 'Start time', 'project-prepper' ); ?>
					<input type="time" name="pp_time_from" value="<?php echo esc_attr( $s ? substr( (string) $s->time_start, 0, 5 ) : '' ); ?>">
				</label>
				<label><?php esc_html_e( 'End time', 'project-prepper' ); ?>
					<input type="time" name="pp_time_to" value="<?php echo esc_attr( $s ? substr( (string) $s->time_end, 0, 5 ) : '' ); ?>">
				</label>
			</div>
			<label><?php esc_html_e( 'Location', 'project-prepper' ); ?>
				<input type="text" name="pp_location" value="<?php echo esc_attr( $s ? (string) $s->location : '' ); ?>">
			</label>
			<label><?php esc_html_e( 'Notes', 'project-prepper' ); ?>
				<input type="text" name="pp_notes" value="<?php echo esc_attr( $s ? (string) $s->notes : '' ); ?>">
			</label>
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
		</form>
		<?php
	}

	/** Aufgaben — Status-Schnellwechsel + volle Bearbeitung + Zuweisung. */
	private static function render_project_tasks( object $p, bool $can_edit, array $members ): void {
		$tasks = (array) ( $p->tasks ?? [] );
		if ( ! $tasks && ! $can_edit ) {
			return;
		}
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Tasks', 'project-prepper' ); ?></h3>
			<?php if ( ! $tasks ) : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'No tasks yet.', 'project-prepper' ); ?></p>
			<?php else : ?>
				<div class="pp-rows">
					<?php
					$uid = get_current_user_id();
					foreach ( $tasks as $t ) :
						$assignee = $t->assigned_user ? get_userdata( (int) $t->assigned_user ) : null;
						$assign   = (string) ( $t->assignment_status ?? 'accepted' );
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
							<?php
							// Zuweisungs-Status (App: Badges „Ausstehend"/„Abgelehnt";
							// angenommene Zuweisungen tragen kein Badge).
							if ( $assignee && 'pending' === $assign ) : ?>
								<span class="pp-team-chip pp-team-chip--invited"><?php esc_html_e( 'Awaiting response', 'project-prepper' ); ?></span>
							<?php elseif ( $assignee && 'declined' === $assign ) : ?>
								<span class="pp-team-chip pp-team-chip--declined"><?php esc_html_e( 'Declined', 'project-prepper' ); ?></span>
							<?php endif; ?>
							<?php if ( $can_edit && $assignee && 'pending' === $assign && (int) $t->assigned_user === $uid ) : ?>
								<?php self::sub_chip_form( 'task_accept', (int) $p->id, [ 'pp_entry' => (int) $t->id ], __( 'Accept', 'project-prepper' ) ); ?>
								<?php self::sub_chip_form( 'task_decline', (int) $p->id, [ 'pp_entry' => (int) $t->id ], __( 'Decline', 'project-prepper' ) ); ?>
							<?php endif; ?>
							<?php if ( $can_edit ) : ?>
								<?php
								// Schnell-Status: offen→Start, in Arbeit→Erledigt, erledigt→Wieder öffnen.
								$next = [
									'open'  => [ 'doing', __( 'Start', 'project-prepper' ) ],
									'doing' => [ 'done', __( 'Mark done', 'project-prepper' ) ],
									'done'  => [ 'open', __( 'Reopen', 'project-prepper' ) ],
								];
								if ( isset( $next[ (string) $t->task_status ] ) ) {
									[ $to, $label ] = $next[ (string) $t->task_status ];
									self::sub_chip_form( 'task_update', (int) $p->id, [ 'pp_entry' => (int) $t->id, 'pp_status' => $to ], $label );
								}
								?>
								<details class="pp-portal__edit">
									<summary class="pp-portal__chip"><?php esc_html_e( 'Edit', 'project-prepper' ); ?></summary>
									<?php self::task_form( $p, $members, $t ); ?>
								</details>
								<?php self::sub_chip_form( 'task_delete', (int) $p->id, [ 'pp_entry' => (int) $t->id ], __( 'Remove', 'project-prepper' ), __( 'Remove this task?', 'project-prepper' ) ); ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( $can_edit ) : ?>
				<details class="pp-portal__add">
					<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Add task', 'project-prepper' ); ?></summary>
					<?php self::task_form( $p, $members, null ); ?>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function task_form( object $p, array $members, ?object $t ): void {
		?>
		<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::action_fields( $t ? 'task_update' : 'task_add' ); ?>
			<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
			<?php if ( $t ) : ?>
				<input type="hidden" name="pp_entry" value="<?php echo (int) $t->id; ?>">
			<?php endif; ?>
			<label><?php esc_html_e( 'Title', 'project-prepper' ); ?>
				<input type="text" name="pp_title" value="<?php echo esc_attr( $t ? (string) $t->title : '' ); ?>" required>
			</label>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Status', 'project-prepper' ); ?>
					<select name="pp_status">
						<?php foreach ( Tasks::STATUSES as $st ) : ?>
							<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $t ? (string) $t->task_status : 'open', $st ); ?>><?php echo esc_html( self::task_status_label( $st ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Priority', 'project-prepper' ); ?>
					<select name="pp_priority">
						<?php foreach ( Tasks::PRIORITIES as $prio ) : ?>
							<option value="<?php echo esc_attr( $prio ); ?>" <?php selected( $t ? (string) $t->priority : 'normal', $prio ); ?>><?php echo esc_html( self::task_priority_label( $prio ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Due date', 'project-prepper' ); ?>
					<input type="date" name="pp_due" value="<?php echo esc_attr( $t ? (string) $t->due_date : '' ); ?>">
				</label>
			</div>
			<label><?php esc_html_e( 'Assigned to', 'project-prepper' ); ?>
				<select name="pp_assignee">
					<option value="0"><?php esc_html_e( 'Nobody', 'project-prepper' ); ?></option>
					<?php foreach ( $members as $m ) : ?>
						<option value="<?php echo (int) $m->user_id; ?>" <?php selected( $t ? (int) $t->assigned_user : 0, (int) $m->user_id ); ?>><?php echo esc_html( (string) $m->display_name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
		</form>
		<?php
	}

	/** Checklisten — Abhaken per Klick auf die Box, Punkte + Listen verwalten. */
	private static function render_project_checklists( object $p, bool $can_edit ): void {
		$lists = (array) ( $p->checklists ?? [] );
		if ( ! $lists && ! $can_edit ) {
			return;
		}
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Checklists', 'project-prepper' ); ?></h3>
			<?php if ( ! $lists && $can_edit ) : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'No checklists yet.', 'project-prepper' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $lists as $list ) : ?>
				<div class="pp-checklist">
					<div class="pp-checklist__name">
						<?php echo esc_html( $list->name ); ?>
						<?php if ( $can_edit ) : ?>
							<?php self::move_chips( 'checklist_move', (int) $p->id, [ 'pp_list' => (int) $list->id ] ); ?>
							<?php self::sub_chip_form( 'checklist_delete', (int) $p->id, [ 'pp_list' => (int) $list->id ], __( 'Remove', 'project-prepper' ), __( 'Remove this checklist including all items?', 'project-prepper' ) ); ?>
						<?php endif; ?>
					</div>
					<?php foreach ( (array) $list->items as $ci ) :
						$done = ! empty( $ci->is_checked ); ?>
						<div class="pp-checkitem<?php echo $done ? ' pp-checkitem--done' : ''; ?>">
							<?php if ( $can_edit ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php self::action_fields( 'checkitem_toggle' ); ?>
									<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
									<input type="hidden" name="pp_citem" value="<?php echo (int) $ci->id; ?>">
									<button type="submit" class="pp-checkitem__box<?php echo $done ? ' pp-checkitem__box--on' : ''; ?>" aria-label="<?php echo esc_attr( $done ? __( 'Mark as not done', 'project-prepper' ) : __( 'Mark as done', 'project-prepper' ) ); ?>"><?php echo $done ? '✓' : ''; ?></button>
								</form>
							<?php else : ?>
								<span class="pp-checkitem__box<?php echo $done ? ' pp-checkitem__box--on' : ''; ?>"><?php echo $done ? '✓' : ''; ?></span>
							<?php endif; ?>
							<span class="pp-checkitem__label"><?php echo esc_html( $ci->label ); ?></span>
							<?php if ( $can_edit ) : ?>
								<span class="pp-checkitem__actions">
									<?php self::move_chips( 'checkitem_move', (int) $p->id, [ 'pp_citem' => (int) $ci->id ] ); ?>
									<?php self::sub_chip_form( 'checkitem_delete', (int) $p->id, [ 'pp_citem' => (int) $ci->id ], __( 'Remove', 'project-prepper' ) ); ?>
								</span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
					<?php if ( $can_edit ) : ?>
						<form class="pp-checkitem-add" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php self::action_fields( 'checkitem_add' ); ?>
							<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
							<input type="hidden" name="pp_list" value="<?php echo (int) $list->id; ?>">
							<input type="text" name="pp_label" placeholder="<?php esc_attr_e( 'New checklist item…', 'project-prepper' ); ?>" aria-label="<?php esc_attr_e( 'New checklist item…', 'project-prepper' ); ?>" required>
							<button type="submit" class="pp-portal__chip"><?php esc_html_e( 'Add checklist item', 'project-prepper' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			<?php if ( $can_edit ) : ?>
				<details class="pp-portal__add">
					<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'New checklist', 'project-prepper' ); ?></summary>
					<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php self::action_fields( 'checklist_add' ); ?>
						<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
						<label><?php esc_html_e( 'Name', 'project-prepper' ); ?>
							<input type="text" name="pp_name" required>
						</label>
						<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
					</form>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Material & Transport — Positionen mit Menge/Einheit/Kosten. */
	private static function render_project_materials( object $p, bool $can_edit ): void {
		$rows = (array) ( $p->consumables ?? [] );
		if ( ! $rows && ! $can_edit ) {
			return;
		}
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Materials', 'project-prepper' ); ?></h3>
			<?php if ( ! $rows ) : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'No materials yet.', 'project-prepper' ); ?></p>
			<?php else : ?>
				<div class="pp-rows">
					<?php foreach ( $rows as $c ) :
						$qty  = (float) $c->quantity;
						$meta = trim( number_format_i18n( $qty, $qty == (int) $qty ? 0 : 2 ) . ' ' . (string) $c->unit );
						if ( null !== $c->cost && '' !== (string) $c->cost ) {
							$meta .= ' · ' . number_format_i18n( (float) $c->cost, 2 ) . ' €';
						} ?>
						<div class="pp-row">
							<span class="pp-row__main"><?php echo esc_html( $c->name ); ?></span>
							<span class="pp-row__meta"><?php echo esc_html( $meta ); ?></span>
							<?php if ( $can_edit ) : ?>
								<details class="pp-portal__edit">
									<summary class="pp-portal__chip"><?php esc_html_e( 'Edit', 'project-prepper' ); ?></summary>
									<?php self::material_form( $p, $c ); ?>
								</details>
								<?php self::sub_chip_form( 'material_delete', (int) $p->id, [ 'pp_entry' => (int) $c->id ], __( 'Remove', 'project-prepper' ), __( 'Remove this material?', 'project-prepper' ) ); ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( $can_edit ) : ?>
				<details class="pp-portal__add">
					<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Add material', 'project-prepper' ); ?></summary>
					<?php self::material_form( $p, null ); ?>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function material_form( object $p, ?object $c ): void {
		?>
		<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::action_fields( $c ? 'material_update' : 'material_add' ); ?>
			<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
			<?php if ( $c ) : ?>
				<input type="hidden" name="pp_entry" value="<?php echo (int) $c->id; ?>">
			<?php endif; ?>
			<label><?php esc_html_e( 'Name', 'project-prepper' ); ?>
				<input type="text" name="pp_name" value="<?php echo esc_attr( $c ? (string) $c->name : '' ); ?>" required>
			</label>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Qty', 'project-prepper' ); ?>
					<input type="number" name="pp_quantity" min="0" step="0.01" value="<?php echo esc_attr( $c ? (string) ( 0 + (float) $c->quantity ) : '1' ); ?>">
				</label>
				<label><?php esc_html_e( 'Unit', 'project-prepper' ); ?>
					<input type="text" name="pp_unit" value="<?php echo esc_attr( $c ? (string) $c->unit : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. pcs, m, kg', 'project-prepper' ); ?>">
				</label>
				<label><?php esc_html_e( 'Cost (€)', 'project-prepper' ); ?>
					<input type="text" inputmode="decimal" name="pp_cost" value="<?php echo esc_attr( $c && null !== $c->cost ? (string) ( 0 + (float) $c->cost ) : '' ); ?>">
				</label>
			</div>
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
		</form>
		<?php
	}

	/** Team (Crew) — Freitext-Einträge Name/Rolle/Gewerk. */
	private static function render_project_team( object $p, bool $can_edit ): void {
		$rows = (array) ( $p->team ?? [] );
		if ( ! $rows && ! $can_edit ) {
			return;
		}
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Team', 'project-prepper' ); ?></h3>
			<?php if ( ! $rows ) : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'No team members yet.', 'project-prepper' ); ?></p>
			<?php else : ?>
				<div class="pp-rows">
					<?php foreach ( $rows as $m ) :
						$meta = trim( (string) $m->role . ( '' !== (string) $m->department ? ' · ' . $m->department : '' ), ' ·' ); ?>
						<div class="pp-row">
							<span class="pp-row__main"><?php echo esc_html( $m->name ); ?></span>
							<?php if ( '' !== $meta ) : ?>
								<span class="pp-row__meta"><?php echo esc_html( $meta ); ?></span>
							<?php endif; ?>
							<?php if ( $can_edit ) : ?>
								<details class="pp-portal__edit">
									<summary class="pp-portal__chip"><?php esc_html_e( 'Edit', 'project-prepper' ); ?></summary>
									<?php self::crew_form( $p, $m ); ?>
								</details>
								<?php self::sub_chip_form( 'crew_delete', (int) $p->id, [ 'pp_entry' => (int) $m->id ], __( 'Remove', 'project-prepper' ), __( 'Remove this team member?', 'project-prepper' ) ); ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( $can_edit ) : ?>
				<details class="pp-portal__add">
					<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Add team member', 'project-prepper' ); ?></summary>
					<?php self::crew_form( $p, null ); ?>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function crew_form( object $p, ?object $m ): void {
		?>
		<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::action_fields( $m ? 'crew_update' : 'crew_add' ); ?>
			<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
			<?php if ( $m ) : ?>
				<input type="hidden" name="pp_entry" value="<?php echo (int) $m->id; ?>">
			<?php endif; ?>
			<label><?php esc_html_e( 'Name', 'project-prepper' ); ?>
				<input type="text" name="pp_name" value="<?php echo esc_attr( $m ? (string) $m->name : '' ); ?>" required>
			</label>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Role', 'project-prepper' ); ?>
					<input type="text" name="pp_role" value="<?php echo esc_attr( $m ? (string) $m->role : '' ); ?>">
				</label>
				<label><?php esc_html_e( 'Department', 'project-prepper' ); ?>
					<input type="text" name="pp_department" value="<?php echo esc_attr( $m ? (string) $m->department : '' ); ?>">
				</label>
			</div>
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
		</form>
		<?php
	}

	/** Externe Kontakte — Name/Rolle/Firma/Email/Telefon. */
	private static function render_project_contacts( object $p, bool $can_edit ): void {
		$rows = (array) ( $p->contacts ?? [] );
		if ( ! $rows && ! $can_edit ) {
			return;
		}
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Contacts', 'project-prepper' ); ?></h3>
			<?php if ( ! $rows ) : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'No contacts yet.', 'project-prepper' ); ?></p>
			<?php else : ?>
				<div class="pp-rows">
					<?php foreach ( $rows as $c ) :
						$meta = implode( ' · ', array_filter( [ $c->role, $c->company, $c->email, $c->phone ] ) ); ?>
						<div class="pp-row">
							<span class="pp-row__main"><?php echo esc_html( $c->name ); ?></span>
							<?php if ( '' !== $meta ) : ?>
								<span class="pp-row__meta"><?php echo esc_html( $meta ); ?></span>
							<?php endif; ?>
							<?php if ( $can_edit ) : ?>
								<details class="pp-portal__edit">
									<summary class="pp-portal__chip"><?php esc_html_e( 'Edit', 'project-prepper' ); ?></summary>
									<?php self::contact_form( $p, $c ); ?>
								</details>
								<?php self::sub_chip_form( 'contact_delete', (int) $p->id, [ 'pp_entry' => (int) $c->id ], __( 'Remove', 'project-prepper' ), __( 'Remove this contact?', 'project-prepper' ) ); ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( $can_edit ) : ?>
				<details class="pp-portal__add">
					<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Add contact', 'project-prepper' ); ?></summary>
					<?php self::contact_form( $p, null ); ?>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function contact_form( object $p, ?object $c ): void {
		?>
		<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::action_fields( $c ? 'contact_update' : 'contact_add' ); ?>
			<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
			<?php if ( $c ) : ?>
				<input type="hidden" name="pp_entry" value="<?php echo (int) $c->id; ?>">
			<?php endif; ?>
			<label><?php esc_html_e( 'Name', 'project-prepper' ); ?>
				<input type="text" name="pp_name" value="<?php echo esc_attr( $c ? (string) $c->name : '' ); ?>" required>
			</label>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Role', 'project-prepper' ); ?>
					<input type="text" name="pp_role" value="<?php echo esc_attr( $c ? (string) $c->role : '' ); ?>">
				</label>
				<label><?php esc_html_e( 'Company', 'project-prepper' ); ?>
					<input type="text" name="pp_company" value="<?php echo esc_attr( $c ? (string) $c->company : '' ); ?>">
				</label>
			</div>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Email', 'project-prepper' ); ?>
					<input type="email" name="pp_email" value="<?php echo esc_attr( $c ? (string) $c->email : '' ); ?>">
				</label>
				<label><?php esc_html_e( 'Phone', 'project-prepper' ); ?>
					<input type="text" name="pp_phone" value="<?php echo esc_attr( $c ? (string) $c->phone : '' ); ?>">
				</label>
			</div>
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
		</form>
		<?php
	}

	/** Dateien — Upload (PDF/Bilder) + Entfernen der Verknüpfung. */
	private static function render_project_files_section( object $p, bool $can_edit ): void {
		$rows = (array) ( $p->files ?? [] );
		if ( ! $rows && ! $can_edit ) {
			return;
		}
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Files', 'project-prepper' ); ?></h3>
			<?php
			// Bilder als Vorschau-Grid mit Lightbox (App: tab-files), der Rest
			// (PDFs etc.) als Zeilen mit Link.
			$images = [];
			$others = [];
			foreach ( $rows as $f ) {
				if ( ! empty( $f->url ) && wp_attachment_is_image( (int) $f->attachment_id ) ) {
					$images[] = $f;
				} else {
					$others[] = $f;
				}
			}
			if ( ! $rows ) : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'No files yet.', 'project-prepper' ); ?></p>
			<?php endif; ?>
			<?php if ( $images ) : ?>
				<div class="pp-file-grid">
					<?php foreach ( $images as $f ) :
						$label = '' !== (string) $f->title ? $f->title : ( $f->filename ?: __( 'File', 'project-prepper' ) );
						$thumb = wp_get_attachment_image_url( (int) $f->attachment_id, 'medium' );
						?>
						<button type="button" class="pp-file-thumb" data-pp-modal="pp-lightbox-<?php echo (int) $f->id; ?>">
							<img src="<?php echo esc_url( $thumb ?: $f->url ); ?>" alt="<?php echo esc_attr( $label ); ?>" loading="lazy">
							<span class="pp-file-thumb__label"><?php echo esc_html( $label ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
				<?php foreach ( $images as $f ) :
					$label = '' !== (string) $f->title ? $f->title : ( $f->filename ?: __( 'File', 'project-prepper' ) );
					$large = wp_get_attachment_image_url( (int) $f->attachment_id, 'large' );
					?>
					<dialog class="pp-modal pp-modal--portal pp-modal--lightbox" id="pp-lightbox-<?php echo (int) $f->id; ?>">
						<div class="pp-modal-header">
							<h2 class="pp-modal__title"><?php echo esc_html( $label ); ?></h2>
							<button type="button" class="pp-modal-close" data-pp-modal-close aria-label="<?php esc_attr_e( 'Close', 'project-prepper' ); ?>">✕</button>
						</div>
						<div class="pp-modal-body pp-lightbox__body">
							<img src="<?php echo esc_url( $large ?: $f->url ); ?>" alt="<?php echo esc_attr( $label ); ?>" loading="lazy">
							<?php // div statt p: ein <form> in <p> wäre invalid — der Parser würde das p vorzeitig schließen. ?>
							<div class="pp-lightbox__actions">
								<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" href="<?php echo esc_url( $f->url ); ?>" download><?php esc_html_e( 'Download', 'project-prepper' ); ?></a>
								<?php if ( $can_edit ) : ?>
									<?php self::sub_chip_form( 'file_detach', (int) $p->id, [ 'pp_entry' => (int) $f->id ], __( 'Remove', 'project-prepper' ), __( 'Remove this file from the project? The media file itself is kept.', 'project-prepper' ) ); ?>
								<?php endif; ?>
							</div>
						</div>
					</dialog>
				<?php endforeach; ?>
			<?php endif; ?>
			<?php if ( $others ) : ?>
				<div class="pp-rows">
					<?php foreach ( $others as $f ) :
						$label = '' !== (string) $f->title ? $f->title : ( $f->filename ?: __( 'File', 'project-prepper' ) );
						$meta  = [];
						if ( ! empty( $f->filesize ) ) {
							$meta[] = size_format( (int) $f->filesize );
						} ?>
						<div class="pp-row">
							<?php if ( ! empty( $f->url ) ) : ?>
								<a class="pp-row__main" href="<?php echo esc_url( $f->url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $label ); ?></a>
							<?php else : ?>
								<span class="pp-row__main"><?php echo esc_html( $label ); ?></span>
								<span class="pp-row__meta"><?php esc_html_e( 'missing', 'project-prepper' ); ?></span>
							<?php endif; ?>
							<?php if ( $meta ) : ?>
								<span class="pp-row__meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></span>
							<?php endif; ?>
							<?php if ( $can_edit ) : ?>
								<?php self::sub_chip_form( 'file_detach', (int) $p->id, [ 'pp_entry' => (int) $f->id ], __( 'Remove', 'project-prepper' ), __( 'Remove this file from the project? The media file itself is kept.', 'project-prepper' ) ); ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( $can_edit ) : ?>
				<details class="pp-portal__add">
					<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Upload file', 'project-prepper' ); ?></summary>
					<form class="pp-portal__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pp_project_file">
						<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
						<?php wp_nonce_field( 'pp_project_file', 'pp_nonce' ); ?>
						<label><?php esc_html_e( 'Title (optional)', 'project-prepper' ); ?>
							<input type="text" name="pp_title">
						</label>
						<label><?php esc_html_e( 'File (PDF or image)', 'project-prepper' ); ?>
							<input type="file" name="pp_file" accept="application/pdf,image/*" required>
						</label>
						<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Upload file', 'project-prepper' ); ?></button>
					</form>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Kosten + Budget/Gewinn. Mitglieder-Sicht = canViewCosts der App. */
	private static function render_project_costs( object $p, bool $can_edit = false ): void {
		$items   = (array) ( $p->cost_items ?? [] );
		$summary = (array) ( $p->cost_summary ?? [] );
		$has_money = $items
			|| null !== ( $summary['budget_planned'] ?? null )
			|| null !== ( $summary['revenue_actual'] ?? null );
		if ( ! $has_money && ! $can_edit ) {
			return;
		}
		$eur = static fn( $v ) => number_format_i18n( (float) $v, 2 ) . ' €';
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Costs & budget', 'project-prepper' ); ?></h3>

			<?php
			// Budget-Balken (wie die App): Geplant-Netto gegen das Projektbudget.
			$budget  = $summary['budget_planned'] ?? null;
			$planned = (float) ( $summary['planned_net'] ?? 0 );
			if ( null !== $budget && (float) $budget > 0 ) :
				$pct  = min( $planned / (float) $budget * 100, 100 );
				$over = $planned > (float) $budget;
				?>
				<div class="pp-budget-bar" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: planned costs, 2: budget. */ __( '%1$s of %2$s budget planned', 'project-prepper' ), $eur( $planned ), $eur( $budget ) ) ); ?>">
					<div class="pp-budget-bar__fill<?php echo $over ? ' pp-budget-bar__fill--over' : ''; ?>" style="width:<?php echo esc_attr( number_format( $pct, 1, '.', '' ) ); ?>%"></div>
				</div>
				<div class="pp-budget-meta">
					<span><?php echo esc_html( $eur( $planned ) ); ?></span>
					<span><?php echo esc_html( $eur( $budget ) ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( ! $items && $can_edit ) : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'No cost items yet.', 'project-prepper' ); ?></p>
			<?php endif; ?>
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
							<?php if ( $can_edit ) : ?>
								<details class="pp-portal__edit">
									<summary class="pp-portal__chip"><?php esc_html_e( 'Edit', 'project-prepper' ); ?></summary>
									<?php self::cost_form( $p, $c ); ?>
								</details>
								<?php self::sub_chip_form( 'cost_delete', (int) $p->id, [ 'pp_entry' => (int) $c->id ], __( 'Remove', 'project-prepper' ), __( 'Remove this cost item?', 'project-prepper' ) ); ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $can_edit ) : ?>
				<div class="pp-portal__actions" style="margin-top:.6rem">
					<details class="pp-portal__add" style="margin-top:0">
						<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Add cost item', 'project-prepper' ); ?></summary>
						<?php self::cost_form( $p, null ); ?>
					</details>
					<details class="pp-portal__add" style="margin-top:0">
						<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Set budget', 'project-prepper' ); ?></summary>
						<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php self::action_fields( 'project_finance' ); ?>
							<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
							<label><?php esc_html_e( 'Budget (net, €)', 'project-prepper' ); ?>
								<input type="text" inputmode="decimal" name="pp_budget" value="<?php echo esc_attr( null !== $budget ? (string) ( 0 + (float) $budget ) : '' ); ?>">
							</label>
							<p class="pp-portal__hint"><?php esc_html_e( 'Leave empty to remove the budget.', 'project-prepper' ); ?></p>
							<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
						</form>
					</details>
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

	private static function cost_form( object $p, ?object $c ): void {
		?>
		<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::action_fields( $c ? 'cost_update' : 'cost_add' ); ?>
			<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
			<?php if ( $c ) : ?>
				<input type="hidden" name="pp_entry" value="<?php echo (int) $c->id; ?>">
			<?php endif; ?>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Category', 'project-prepper' ); ?>
					<select name="pp_category">
						<?php foreach ( Costs::CATEGORIES as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $c ? (string) $c->category : 'other', $cat ); ?>><?php echo esc_html( self::cost_category_label( $cat ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Description', 'project-prepper' ); ?>
					<input type="text" name="pp_description" value="<?php echo esc_attr( $c ? (string) $c->description : '' ); ?>">
				</label>
			</div>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Planned (net, €)', 'project-prepper' ); ?>
					<input type="text" inputmode="decimal" name="pp_planned" value="<?php echo esc_attr( $c ? (string) ( 0 + (float) $c->amount_planned ) : '' ); ?>">
				</label>
				<label><?php esc_html_e( 'Actual (net, €)', 'project-prepper' ); ?>
					<input type="text" inputmode="decimal" name="pp_actual" value="<?php echo esc_attr( $c && null !== $c->amount_actual ? (string) ( 0 + (float) $c->amount_actual ) : '' ); ?>">
				</label>
				<label><?php esc_html_e( 'VAT %', 'project-prepper' ); ?>
					<input type="text" inputmode="decimal" name="pp_vat" value="<?php echo esc_attr( $c ? (string) ( 0 + (float) $c->vat_rate ) : '19' ); ?>">
				</label>
			</div>
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
		</form>
		<?php
	}

	/** Gewinnverteilung — Umsatz erfassen + Anteile je Mitglied verwalten. */
	private static function render_project_profit( object $p, bool $can_edit = false, array $members = [] ): void {
		$shares  = (array) ( $p->profit_shares ?? [] );
		if ( ! $shares && ! $can_edit ) {
			return;
		}
		$summary = (array) ( $p->profit_summary ?? [] );
		$eur     = static fn( $v ) => number_format_i18n( (float) $v, 2 ) . ' €';
		$revenue = $p->revenue_actual ?? null;
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Profit distribution', 'project-prepper' ); ?></h3>
			<?php if ( ! $shares && $can_edit ) : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'No profit shares yet.', 'project-prepper' ); ?></p>
			<?php endif; ?>
			<?php if ( $shares ) : ?>
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
						<?php if ( $can_edit ) : ?>
							<details class="pp-portal__edit">
								<summary class="pp-portal__chip"><?php esc_html_e( 'Edit', 'project-prepper' ); ?></summary>
								<?php self::profit_form( $p, $members, $s ); ?>
							</details>
							<?php self::sub_chip_form( 'profit_remove', (int) $p->id, [ 'pp_entry' => (int) $s->id ], __( 'Remove', 'project-prepper' ), __( 'Remove this profit share?', 'project-prepper' ) ); ?>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php if ( $can_edit ) : ?>
				<div class="pp-portal__actions" style="margin-top:.6rem">
					<details class="pp-portal__add" style="margin-top:0">
						<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Add profit share', 'project-prepper' ); ?></summary>
						<?php self::profit_form( $p, $members, null ); ?>
					</details>
					<details class="pp-portal__add" style="margin-top:0">
						<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Set revenue', 'project-prepper' ); ?></summary>
						<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php self::action_fields( 'project_finance' ); ?>
							<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
							<label><?php esc_html_e( 'Revenue (actual, €)', 'project-prepper' ); ?>
								<input type="text" inputmode="decimal" name="pp_revenue" value="<?php echo esc_attr( null !== $revenue ? (string) ( 0 + (float) $revenue ) : '' ); ?>">
							</label>
							<p class="pp-portal__hint"><?php esc_html_e( 'Profit pool = revenue minus actual net costs.', 'project-prepper' ); ?></p>
							<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
						</form>
					</details>
				</div>
			<?php endif; ?>
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

	private static function profit_form( object $p, array $members, ?object $s ): void {
		?>
		<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::action_fields( $s ? 'profit_update' : 'profit_add' ); ?>
			<input type="hidden" name="pp_project" value="<?php echo (int) $p->id; ?>">
			<?php if ( $s ) : ?>
				<input type="hidden" name="pp_entry" value="<?php echo (int) $s->id; ?>">
			<?php else : ?>
				<label><?php esc_html_e( 'Member', 'project-prepper' ); ?>
					<select name="pp_user" required>
						<?php foreach ( $members as $m ) : ?>
							<option value="<?php echo (int) $m->user_id; ?>"><?php echo esc_html( (string) $m->display_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Share type', 'project-prepper' ); ?>
					<select name="pp_share_type">
						<option value="percentage" <?php selected( $s ? (string) $s->share_type : 'percentage', 'percentage' ); ?>><?php esc_html_e( 'Percentage of pool', 'project-prepper' ); ?></option>
						<option value="fixed" <?php selected( $s ? (string) $s->share_type : 'percentage', 'fixed' ); ?>><?php esc_html_e( 'Fixed amount (€)', 'project-prepper' ); ?></option>
					</select>
				</label>
				<label><?php esc_html_e( 'Value', 'project-prepper' ); ?>
					<input type="text" inputmode="decimal" name="pp_share_value" value="<?php echo esc_attr( $s ? (string) ( 0 + (float) $s->share_value ) : '' ); ?>">
				</label>
			</div>
			<label><?php esc_html_e( 'Note', 'project-prepper' ); ?>
				<input type="text" name="pp_note" value="<?php echo esc_attr( $s ? (string) ( $s->note ?? '' ) : '' ); ?>">
			</label>
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
		</form>
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
	 *
	 * App-Pendant PollCard/PollDateGrid: Teilnehmer-Matrix (wer hat wie
	 * gestimmt), eigene Zeile mit Zyklus-Button pro Option (leer→Ja→Vielleicht→
	 * Nein→Ja …), Zusagen-Zeile mit Hervorhebung der besten Option, Meta-Zeile
	 * (Ersteller · Teilnehmer · Frist) und Schließen/Öffnen/Löschen.
	 */
	private static function render_polls_list( array $polls, array $ctx ): void {
		$pre = $ctx['prefix'];
		$uid = get_current_user_id();
		if ( ! $polls ) : ?>
			<p class="pp-portal__empty pp-gov__empty"><?php echo esc_html( (string) ( $ctx['empty'] ?? __( 'No polls yet.', 'project-prepper' ) ) ); ?></p>
		<?php else :
			foreach ( $polls as $poll ) :
				$open    = ( 'open' === $poll->status );
				$expired = Polls::deadline_passed( $poll );
				$votable = $open && $poll->can_vote && ! $expired;
				$voters  = Polls::voters( (int) $poll->id );
				$options = (array) $poll->options;

				// Eigene Zeile immer zuerst, danach die übrigen Teilnehmer.
				$others = array_values( array_filter( $voters, static fn( $v ) => (int) $v->user_id !== $uid ) );
				$n_part = count( $voters ) + ( ( $poll->can_vote && count( $others ) === count( $voters ) ) ? 1 : 0 );

				// Beste Option = meiste Zusagen (App: grüne Hervorhebung).
				$best = 0;
				foreach ( $options as $opt ) {
					$best = max( $best, (int) $opt->yes );
				}

				$meta   = [];
				$author = ! empty( $poll->created_by ) ? get_userdata( (int) $poll->created_by ) : null;
				if ( $author ) {
					/* translators: %s: name of the poll creator. */
					$meta[] = sprintf( __( 'by %s', 'project-prepper' ), $author->display_name );
				}
				/* translators: %d: number of poll participants. */
				$meta[] = sprintf( _n( '%d participant', '%d participants', $n_part, 'project-prepper' ), $n_part );
				if ( ! empty( $poll->deadline ) && '0000-00-00 00:00:00' !== (string) $poll->deadline ) {
					/* translators: %s: poll deadline (date and time). */
					$meta[] = sprintf( __( 'until %s', 'project-prepper' ), date_i18n( (string) get_option( 'date_format' ) . ' H:i', strtotime( (string) $poll->deadline ) ) );
				}
				?>
				<div class="pp-gov">
					<div class="pp-gov__head">
						<span class="pp-gov__title"><?php echo esc_html( $poll->title ); ?></span>
						<span class="pp-status pp-status--<?php echo $open ? 'open' : 'done'; ?>"><?php echo esc_html( $open ? __( 'Open', 'project-prepper' ) : __( 'Closed', 'project-prepper' ) ); ?></span>
						<span class="pp-gov__mode"><?php echo esc_html( 'date' === $poll->poll_type ? __( 'Date poll', 'project-prepper' ) : __( 'Choice poll', 'project-prepper' ) ); ?></span>
					</div>
					<p class="pp-gov__meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></p>
					<?php if ( '' !== (string) $poll->description ) : ?>
						<p class="pp-gov__desc"><?php echo nl2br( esc_html( $poll->description ) ); ?></p>
					<?php endif; ?>
					<?php if ( $open && $expired ) : ?>
						<p class="pp-portal__hint"><?php esc_html_e( 'The deadline for this poll has passed — voting is closed.', 'project-prepper' ); ?></p>
					<?php endif; ?>

					<div class="pp-pollgrid">
						<table class="pp-pollgrid__table">
							<thead>
								<tr>
									<th class="pp-pollgrid__who"></th>
									<?php foreach ( $options as $opt ) : ?>
										<th class="pp-pollgrid__opt"><?php self::poll_option_head( (string) $poll->poll_type, $opt ); ?></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php if ( $poll->can_vote ) : ?>
									<tr class="pp-pollgrid__me">
										<td class="pp-pollgrid__who">
											<?php echo esc_html( wp_get_current_user()->display_name ); ?>
											<span class="pp-muted-inline"><?php esc_html_e( '(you)', 'project-prepper' ); ?></span>
										</td>
										<?php foreach ( $options as $opt ) :
											$mine = (string) ( $poll->my_votes->{(string) $opt->id} ?? '' );
											if ( $votable ) :
												// Zyklus wie die App: leer→Ja→Vielleicht→Nein→leer
												// ('none' entfernt die Stimme wieder).
												$next = [ '' => 'yes', 'yes' => 'maybe', 'maybe' => 'no', 'no' => 'none' ][ $mine ] ?? 'yes';
												$hint = [ 'yes' => __( 'Yes', 'project-prepper' ), 'maybe' => __( 'Maybe', 'project-prepper' ), 'no' => __( 'No — click to clear', 'project-prepper' ) ];
												?>
												<td>
													<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
														<?php self::action_fields( $pre . '_vote' ); ?>
														<?php self::poll_ctx_hidden( $ctx ); ?>
														<input type="hidden" name="pp_option" value="<?php echo (int) $opt->id; ?>">
														<input type="hidden" name="pp_vote" value="<?php echo esc_attr( $next ); ?>">
														<button type="submit"
															class="pp-pollgrid__btn<?php echo '' !== $mine ? ' pp-pollgrid__btn--' . esc_attr( $mine ) : ''; ?>"
															title="<?php echo esc_attr( '' !== $mine ? $hint[ $mine ] : __( 'Click to vote', 'project-prepper' ) ); ?>"><?php echo esc_html( self::poll_vote_glyph( $mine ) ); ?></button>
													</form>
												</td>
											<?php else : ?>
												<td><span class="pp-pollgrid__cell<?php echo '' !== $mine ? ' pp-pollgrid__btn--' . esc_attr( $mine ) : ''; ?>"><?php echo esc_html( self::poll_vote_glyph( $mine, '' ) ); ?></span></td>
											<?php endif; ?>
										<?php endforeach; ?>
									</tr>
								<?php endif; ?>
								<?php foreach ( $others as $voter ) : ?>
									<tr>
										<td class="pp-pollgrid__who"><?php echo esc_html( $voter->display_name ); ?></td>
										<?php foreach ( $options as $opt ) :
											$v = (string) ( $voter->votes->{(string) $opt->id} ?? '' ); ?>
											<td><span class="pp-pollgrid__cell<?php echo '' !== $v ? ' pp-pollgrid__btn--' . esc_attr( $v ) : ''; ?>"><?php echo esc_html( self::poll_vote_glyph( $v, '' ) ); ?></span></td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
								<tr class="pp-pollgrid__sums">
									<td class="pp-pollgrid__who"><?php esc_html_e( 'Yes votes', 'project-prepper' ); ?></td>
									<?php foreach ( $options as $opt ) : ?>
										<td class="<?php echo ( $best > 0 && (int) $opt->yes === $best ) ? 'pp-pollgrid__best' : ''; ?>"><?php echo (int) $opt->yes; ?></td>
									<?php endforeach; ?>
								</tr>
							</tbody>
						</table>
					</div>

					<?php if ( self::gov_can_manage( $poll ) ) : ?>
						<div class="pp-gov__manage">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php self::action_fields( $open ? $pre . '_close' : $pre . '_reopen' ); ?>
								<?php self::poll_ctx_hidden( $ctx ); ?>
								<input type="hidden" name="pp_poll" value="<?php echo (int) $poll->id; ?>">
								<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php echo esc_html( $open ? __( 'Close', 'project-prepper' ) : __( 'Reopen', 'project-prepper' ) ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
								onsubmit="return confirm('<?php echo esc_js( __( 'Delete this poll including all votes?', 'project-prepper' ) ); ?>');">
								<?php self::action_fields( $pre . '_delete' ); ?>
								<?php self::poll_ctx_hidden( $ctx ); ?>
								<input type="hidden" name="pp_poll" value="<?php echo (int) $poll->id; ?>">
								<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Delete', 'project-prepper' ); ?></button>
							</form>
						</div>
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
				<label><?php esc_html_e( 'Deadline (optional)', 'project-prepper' ); ?>
					<input type="datetime-local" name="pp_deadline">
				</label>
				<label><?php esc_html_e( 'Description (optional)', 'project-prepper' ); ?>
					<textarea name="pp_description" rows="2"></textarea>
				</label>
				<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Create poll', 'project-prepper' ); ?></button>
			</form>
			</div>
		</dialog>
		<?php
	}

	/** Kopfzelle einer Umfrage-Option: Termin (Wochentag/Datum/Zeit) oder Label. */
	private static function poll_option_head( string $poll_type, object $opt ): void {
		if ( 'date' === $poll_type && ! empty( $opt->option_date ) ) {
			$ts = strtotime( (string) $opt->option_date );
			echo '<span class="pp-pollgrid__dow">' . esc_html( $ts ? date_i18n( 'D', $ts ) : '' ) . '</span>';
			echo '<span class="pp-pollgrid__date">' . esc_html( $ts ? date_i18n( 'j.n.', $ts ) : (string) $opt->option_date ) . '</span>';
			if ( ! empty( $opt->option_time ) ) {
				echo '<span class="pp-pollgrid__time">' . esc_html( substr( (string) $opt->option_time, 0, 5 ) ) . '</span>';
			}
			return;
		}
		echo '<span class="pp-pollgrid__label">' . esc_html( (string) $opt->label ) . '</span>';
	}

	/** Symbol für eine Stimme in der Matrix (App: ✓ / ? / ✗, leer = ·). */
	private static function poll_vote_glyph( string $vote, string $empty = '·' ): string {
		$map = [ 'yes' => '✓', 'maybe' => '?', 'no' => '✕' ];
		return $map[ $vote ] ?? $empty;
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
			self::mini_kpi( __( 'Projects', 'project-prepper' ), (string) count( $projects ), 'info' );
			self::mini_kpi( __( 'Planned costs', 'project-prepper' ), $eur( $planned ), 'primary' );
			self::mini_kpi( __( 'Actual costs', 'project-prepper' ), $eur( $actual ), $actual > $planned ? 'warning' : 'success' );
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
						// Projekt-Link direkt in den Kosten-Reiter des Projekts (App: ?tab=costs).
						$purl = add_query_arg( [ 'pp_view' => 'projects', 'pp_project' => (int) $c->project_id, 'pp_tab' => 'costs' ], $base );
						$desc = '' !== (string) $c->description ? $c->description : ''; ?>
						<div class="pp-row pp-row--costs">
							<span class="pp-row__main">
								<a class="pp-row__link" href="<?php echo esc_url( $purl ); ?>"><?php echo esc_html( $c->project_name ?: '—' ); ?></a>
								<span class="pp-row__sub"><?php echo esc_html( trim( self::cost_category_label( (string) $c->category ) . ( '' !== $desc ? ' · ' . $desc : '' ) ) ); ?></span>
							</span>
							<span class="pp-row__meta">
								<?php
								// Plan- UND Ist-Betrag getrennt wie die App-Tabelle.
								/* translators: %s: planned amount. */
								echo esc_html( sprintf( __( 'Plan %s', 'project-prepper' ), $eur( (float) $c->amount_planned ) ) );
								echo ' · ';
								if ( null !== $c->amount_actual ) {
									/* translators: %s: actual amount. */
									echo '<strong>' . esc_html( sprintf( __( 'Actual %s', 'project-prepper' ), $eur( (float) $c->amount_actual ) ) ) . '</strong>';
								} else {
									echo esc_html( __( 'Actual', 'project-prepper' ) . ' —' );
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
		$uid   = get_current_user_id();
		$polls = Polls::for_group( $active, $uid ?: null );

		// Filter-Reiter wie die App (Aktiv/Alle/Geschlossen/Meine).
		$open_polls   = array_values( array_filter( $polls, static fn( $p ) => 'open' === $p->status ) );
		$closed_polls = array_values( array_filter( $polls, static fn( $p ) => 'open' !== $p->status ) );
		$mine_polls   = array_values( array_filter( $polls, static fn( $p ) => (int) $p->created_by === $uid ) );
		$tabs         = [
			/* translators: %d: number of active polls. */
			'active' => sprintf( __( 'Active (%d)', 'project-prepper' ), count( $open_polls ) ),
			'all'    => __( 'All', 'project-prepper' ),
			/* translators: %d: number of closed polls. */
			'closed' => sprintf( __( 'Closed (%d)', 'project-prepper' ), count( $closed_polls ) ),
			/* translators: %d: number of polls created by the current user. */
			'mine'   => sprintf( __( 'Mine (%d)', 'project-prepper' ), count( $mine_polls ) ),
		];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige-Auswahl.
		$tab = sanitize_key( wp_unslash( (string) ( $_GET['pp_tab'] ?? 'active' ) ) );
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'active';
		}
		$tab_base = self::view_url( 'polls' );
		switch ( $tab ) {
			case 'all':
				$shown = $polls;
				$empty = __( 'No polls found.', 'project-prepper' );
				break;
			case 'closed':
				$shown = $closed_polls;
				$empty = __( 'No closed polls.', 'project-prepper' );
				break;
			case 'mine':
				$shown = $mine_polls;
				$empty = __( 'You have not created any polls yet.', 'project-prepper' );
				break;
			default:
				$shown = $open_polls;
				$empty = __( 'No active polls.', 'project-prepper' );
		}
		?>
		<nav class="pp-proj-tabs" aria-label="<?php esc_attr_e( 'Poll filters', 'project-prepper' ); ?>">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a class="pp-proj-tabs__tab<?php echo $key === $tab ? ' pp-proj-tabs__tab--on' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url( 'active' === $key ? $tab_base : add_query_arg( 'pp_tab', $key, $tab_base ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<section class="pp-card">
			<?php self::render_polls_list( $shown, [ 'prefix' => 'gpoll', 'group' => $active, 'empty' => $empty ] ); ?>
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
		$cals   = CalendarEvents::calendars( (int) $user->ID, self::active_group_id( $groups ) );
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
						self::cal_chip( $ev );
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

			<?php self::calendar_legend( $cals ); ?>
		</div>
		<?php
		self::render_calendar_manage( $user, $groups, $cals, $month_start, $month_end );
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
		$cals   = CalendarEvents::calendars( (int) $user->ID, self::active_group_id( $groups ) );
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

			<?php self::render_week_time_grid( $monday_ts, $today, $by_day ); ?>

			<?php self::calendar_legend( $cals ); ?>
		</div>
		<?php
		self::render_calendar_manage( $user, $groups, $cals, $week_start, $week_end );
	}

	/**
	 * Wochen-Zeitraster (App: WeekTimeGrid in calendar/page.tsx) — Stunden-Achse
	 * 06–23 Uhr, Termine mit Uhrzeit als positionierte Blöcke in Kalenderfarbe,
	 * ganztägige/zeitlose Einträge in einer „Ganztags“-Zeile. Rein server-
	 * gerendert: Position/Höhe aus time_start/time_end, Überlappungen werden
	 * in Spalten nebeneinander gelegt (Greedy wie layoutEvents der App).
	 */
	private static function render_week_time_grid( int $monday_ts, string $today, array $by_day ): void {
		$hour_h  = 48;  // px pro Stunde
		$start_h = 6;   // Rasterfenster 06:00 …
		$end_h   = 23;  // … 23:00
		$grid_h  = ( $end_h - $start_h ) * $hour_h;

		// Fallback-Farben pro Typ für Blöcke ohne Kalenderfarbe (Hex nötig für
		// die #RRGGBBAA-Tönung — Werte = --pp-info / --pp-success / --pp-primary).
		$type_colors = [
			'event'    => CalendarEvents::COLORS[0],
			'schedule' => '#3B82F6',
			'borrow'   => '#10B981',
			'project'  => '#6366F1',
		];

		// Pro Tag in zeitgebundene (→ Raster) und ganztägige (→ Ganztags-Zeile)
		// Einträge trennen.
		$days = [];
		$has_allday = false;
		for ( $i = 0; $i < 7; $i++ ) {
			$day_ts = strtotime( "+$i day", $monday_ts );
			$key    = gmdate( 'Y-m-d', $day_ts );
			$timed  = [];
			$allday = [];
			foreach ( $by_day[ $key ] ?? [] as $ev ) {
				if ( '' !== (string) ( $ev['time_start'] ?? '' ) ) {
					$timed[] = $ev;
				} else {
					$allday[] = $ev;
				}
			}
			$has_allday   = $has_allday || $allday;
			$days[] = [
				'ts'     => $day_ts,
				'key'    => $key,
				'today'  => ( $key === $today ),
				'wknd'   => $i >= 5,
				'timed'  => $timed,
				'allday' => $allday,
			];
		}

		$now_min  = (int) current_time( 'H' ) * 60 + (int) current_time( 'i' );
		$now_top  = (int) round( ( $now_min - $start_h * 60 ) / 60 * $hour_h );
		$show_now = $now_min >= $start_h * 60 && $now_min <= $end_h * 60;
		?>
		<div class="pp-cal__tg">
			<div class="pp-cal__tg-head">
				<div class="pp-cal__tg-gutter"></div>
				<?php foreach ( $days as $d ) : ?>
					<div class="pp-cal__tg-dayhead<?php echo $d['today'] ? ' pp-cal__tg-dayhead--today' : ''; ?>">
						<span class="pp-cal__tg-dow"><?php echo esc_html( date_i18n( 'D', $d['ts'] ) ); ?></span>
						<span class="pp-cal__tg-daynum"><?php echo (int) gmdate( 'j', $d['ts'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( $has_allday ) : ?>
				<div class="pp-cal__tg-allday">
					<div class="pp-cal__tg-gutter pp-cal__tg-alllabel"><?php esc_html_e( 'All-day', 'project-prepper' ); ?></div>
					<?php foreach ( $days as $d ) : ?>
						<div class="pp-cal__tg-allcol">
							<?php foreach ( $d['allday'] as $ev ) {
								self::cal_chip( $ev );
							} ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="pp-cal__tg-body">
				<div class="pp-cal__tg-hours" style="height:<?php echo (int) $grid_h; ?>px">
					<?php for ( $h = $start_h + 1; $h < $end_h; $h++ ) : ?>
						<span class="pp-cal__tg-hour" style="top:<?php echo (int) ( ( $h - $start_h ) * $hour_h ); ?>px"><?php echo esc_html( sprintf( '%02d:00', $h ) ); ?></span>
					<?php endfor; ?>
				</div>
				<?php foreach ( $days as $d ) : ?>
					<div class="pp-cal__tg-daycol<?php echo $d['today'] ? ' pp-cal__tg-daycol--today' : ( $d['wknd'] ? ' pp-cal__tg-daycol--wknd' : '' ); ?>" style="height:<?php echo (int) $grid_h; ?>px">
						<?php if ( $d['today'] && $show_now ) : ?>
							<span class="pp-cal__tg-now" style="top:<?php echo (int) $now_top; ?>px"></span>
						<?php endif; ?>
						<?php foreach ( self::tg_layout( $d['timed'], $start_h, $end_h ) as $blk ) :
							$ev    = $blk['ev'];
							$color = (string) ( $ev['color'] ?? '' ) ?: ( $type_colors[ $ev['type'] ] ?? $type_colors['event'] );
							$top   = (int) round( ( $blk['sm'] - $start_h * 60 ) / 60 * $hour_h );
							$hgt   = max( 22, (int) round( ( $blk['em'] - $blk['sm'] ) / 60 * $hour_h ) );
							$width = 100 / $blk['cols'];
							$left  = $blk['col'] * $width;
							$time  = $ev['time_start'] . ( '' !== (string) ( $ev['time_end'] ?? '' ) ? ' – ' . $ev['time_end'] : '' );
							$style = sprintf(
								'top:%dpx;height:%dpx;left:%.4F%%;width:%.4F%%;background:%s26;border-left-color:%s;color:%s',
								$top,
								$hgt,
								$left,
								$width,
								$color,
								$color,
								$color
							);
							$label = (string) ( $ev['title'] ?? $ev['label'] );
							$tag   = ! empty( $ev['url'] ) ? 'a' : 'span';
							$href  = ! empty( $ev['url'] ) ? ' href="' . esc_url( $ev['url'] ) . '"' : '';
							echo '<' . $tag . ' class="pp-cal__tg-ev"' . $href . ' style="' . esc_attr( $style ) . '" title="' . esc_attr( $label . ( $time ? ' · ' . $time : '' ) ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $href ist esc_url-escaped.
							echo '<span class="pp-cal__tg-ev-title">' . esc_html( $label ) . '</span>';
							if ( $hgt > 34 && $time ) {
								echo '<span class="pp-cal__tg-ev-time">' . esc_html( $time ) . '</span>';
							}
							echo '</' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fester Tag-Name.
						endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Zeitgebundene Tages-Einträge in Überlappungs-Spalten legen (Greedy wie
	 * layoutEvents der App): sortiert nach Start, jede Spalte nimmt den nächsten
	 * passenden Eintrag; Breite = 100 % / Spaltenzahl.
	 *
	 * @param array<int,array> $events Einträge mit time_start (HH:MM) gesetzt.
	 * @return array<int,array{ev:array,sm:int,em:int,col:int,cols:int}>
	 */
	private static function tg_layout( array $events, int $start_h, int $end_h ): array {
		if ( ! $events ) {
			return [];
		}
		$win_start = $start_h * 60;
		$win_end   = $end_h * 60;
		$items     = [];
		foreach ( $events as $ev ) {
			$sm = self::tg_minutes( (string) $ev['time_start'] );
			$em = '' !== (string) ( $ev['time_end'] ?? '' ) ? self::tg_minutes( (string) $ev['time_end'] ) : $sm + 60;
			// Ins Rasterfenster klemmen (Termine vor 06:00 / nach 23:00 bleiben sichtbar).
			$sm = max( $win_start, min( $sm, $win_end - 15 ) );
			$em = max( $sm + 15, min( $em, $win_end ) );
			$items[] = [ 'ev' => $ev, 'sm' => $sm, 'em' => $em ];
		}
		usort( $items, static fn( $a, $b ) => ( $a['sm'] <=> $b['sm'] ) ?: ( $a['em'] <=> $b['em'] ) );

		$col_ends = []; // Ende-Minute der letzten Belegung je Spalte.
		foreach ( $items as &$item ) {
			$placed = false;
			foreach ( $col_ends as $ci => $end ) {
				if ( $end <= $item['sm'] ) {
					$item['col']     = $ci;
					$col_ends[ $ci ] = $item['em'];
					$placed          = true;
					break;
				}
			}
			if ( ! $placed ) {
				$item['col'] = count( $col_ends );
				$col_ends[]  = $item['em'];
			}
		}
		unset( $item );

		$cols = count( $col_ends );
		foreach ( $items as &$item ) {
			$item['cols'] = $cols;
		}
		unset( $item );
		return $items;
	}

	/** "HH:MM" → Minuten seit Mitternacht (ungültig → 0). */
	private static function tg_minutes( string $time ): int {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})/', $time, $m ) ) {
			return 0;
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}

	/** Ein Kalender-Chip im Monats-/Wochenraster (mit optionaler Kalender-Farbe). */
	private static function cal_chip( array $ev ): void {
		$cls   = 'pp-cal__chip pp-cal__chip--' . $ev['type'];
		$style = ! empty( $ev['color'] )
			? ' style="background:' . esc_attr( $ev['color'] ) . ';border-left-color:' . esc_attr( $ev['color'] ) . ';color:#fff"'
			: '';
		if ( ! empty( $ev['url'] ) ) {
			echo '<a class="' . esc_attr( $cls ) . '"' . $style . ' href="' . esc_url( $ev['url'] ) . '" title="' . esc_attr( $ev['label'] ) . '">' . esc_html( $ev['label'] ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $style ist oben esc_attr-escaped.
		} else {
			echo '<span class="' . esc_attr( $cls ) . '"' . $style . ' title="' . esc_attr( $ev['label'] ) . '">' . esc_html( $ev['label'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $style ist oben esc_attr-escaped.
		}
	}

	/** Gemeinsame Kalender-Legende (Monat + Woche) — inkl. eigener Kalender. */
	private static function calendar_legend( array $calendars = [] ): void {
		?>
		<div class="pp-cal__legend">
			<?php foreach ( $calendars as $c ) : ?>
				<span class="pp-cal__legend-item"><span class="pp-cal__dot" style="background:<?php echo esc_attr( $c->color ); ?>"></span><?php echo esc_html( $c->name ); ?></span>
			<?php endforeach; ?>
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
	 * Termine + Kalender des aktiven Arbeitsbereichs unter dem Raster (App:
	 * Events CRUD + GroupManager): Termin-Liste im sichtbaren Zeitraum mit
	 * Inline-Edit/Löschen, „Neuer Termin"-Modal im App-Look und die
	 * Kalender-Verwaltung (Name + Farbe aus der festen App-Palette).
	 */
	private static function render_calendar_manage( WP_User $user, array $groups, array $cals, string $from, string $to ): void {
		$active = self::active_group_id( $groups );
		$events = CalendarEvents::events_between( (int) $user->ID, $active, $from, $to );
		?>
		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Your events', 'project-prepper' ); ?></h3>
			<p class="pp-portal__hint"><?php echo esc_html( $active ? __( 'Events of your active group — visible to all its members.', 'project-prepper' ) : __( 'Your personal events (solo workspace).', 'project-prepper' ) ); ?></p>
			<?php if ( ! $events ) : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'No events in this period.', 'project-prepper' ); ?></p>
			<?php else : ?>
				<div class="pp-rows">
					<?php foreach ( $events as $e ) :
						$color = (string) ( $e->calendar_color ?: CalendarEvents::COLORS[0] );
						$time  = trim( substr( (string) $e->time_start, 0, 5 ) . ( '' !== (string) $e->time_end ? '–' . substr( (string) $e->time_end, 0, 5 ) : '' ), '–' );
						$meta  = array_filter( [
							self::fmt_range( (string) $e->date_from, (string) $e->date_to ),
							$time,
							(string) $e->location,
							(string) ( $e->calendar_name ?? '' ),
						], static fn( $v ) => '' !== (string) $v );
						?>
						<div class="pp-row pp-row--event" id="pp-ev-<?php echo (int) $e->id; ?>">
							<span class="pp-ev-dot" style="background:<?php echo esc_attr( $color ); ?>"></span>
							<span class="pp-row__main">
								<?php echo esc_html( $e->title ); ?>
								<span class="pp-row__sub"><?php echo esc_html( implode( ' · ', $meta ) ); ?></span>
							</span>
							<details class="pp-portal__edit">
								<summary class="pp-portal__chip"><?php esc_html_e( 'Edit', 'project-prepper' ); ?></summary>
								<?php self::event_form( $cals, $e ); ?>
							</details>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
								onsubmit="return confirm('<?php echo esc_js( __( 'Delete this event?', 'project-prepper' ) ); ?>');">
								<?php self::action_fields( 'event_delete' ); ?>
								<input type="hidden" name="pp_event" value="<?php echo (int) $e->id; ?>">
								<button type="submit" class="pp-portal__chip"><?php esc_html_e( 'Delete', 'project-prepper' ); ?></button>
							</form>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<button type="button" class="pp-portal__btn pp-portal__btn--sm" data-pp-modal="pp-event-create"><?php esc_html_e( 'New event', 'project-prepper' ); ?></button>
			<dialog class="pp-modal pp-modal--portal" id="pp-event-create">
				<div class="pp-modal-header">
					<h2 class="pp-modal__title"><?php esc_html_e( 'New event', 'project-prepper' ); ?></h2>
					<button type="button" class="pp-modal-close" data-pp-modal-close aria-label="<?php esc_attr_e( 'Close', 'project-prepper' ); ?>">✕</button>
				</div>
				<div class="pp-modal-body">
					<?php self::event_form( $cals, null ); ?>
				</div>
			</dialog>
		</section>

		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Calendars', 'project-prepper' ); ?></h3>
			<p class="pp-portal__hint"><?php esc_html_e( 'Color-code your events with calendars — like the calendar groups in the app.', 'project-prepper' ); ?></p>
			<?php if ( $cals ) : ?>
				<div class="pp-rows">
					<?php foreach ( $cals as $c ) : ?>
						<div class="pp-row">
							<span class="pp-ev-dot" style="background:<?php echo esc_attr( $c->color ); ?>"></span>
							<span class="pp-row__main"><?php echo esc_html( $c->name ); ?></span>
							<details class="pp-portal__edit">
								<summary class="pp-portal__chip"><?php esc_html_e( 'Edit', 'project-prepper' ); ?></summary>
								<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php self::action_fields( 'calgroup_update' ); ?>
									<input type="hidden" name="pp_calendar" value="<?php echo (int) $c->id; ?>">
									<label><?php esc_html_e( 'Name', 'project-prepper' ); ?>
										<input type="text" name="pp_name" value="<?php echo esc_attr( $c->name ); ?>" required>
									</label>
									<?php self::color_swatches( (string) $c->color ); ?>
									<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
								</form>
							</details>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
								onsubmit="return confirm('<?php echo esc_js( __( 'Delete this calendar? Its events will be kept without a calendar.', 'project-prepper' ) ); ?>');">
								<?php self::action_fields( 'calgroup_delete' ); ?>
								<input type="hidden" name="pp_calendar" value="<?php echo (int) $c->id; ?>">
								<button type="submit" class="pp-portal__chip"><?php esc_html_e( 'Delete', 'project-prepper' ); ?></button>
							</form>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<details class="pp-portal__add">
				<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Add calendar', 'project-prepper' ); ?></summary>
				<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php self::action_fields( 'calgroup_create' ); ?>
					<label><?php esc_html_e( 'Name', 'project-prepper' ); ?>
						<input type="text" name="pp_name" required>
					</label>
					<?php self::color_swatches( '' ); ?>
					<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Add calendar', 'project-prepper' ); ?></button>
				</form>
			</details>
		</section>

		<section class="pp-card">
			<h3 class="pp-card__title"><?php esc_html_e( 'Subscribe to your calendar', 'project-prepper' ); ?></h3>
			<p class="pp-portal__hint"><?php esc_html_e( 'Add this address in Apple Calendar (File → New Calendar Subscription) or Google Calendar (Other calendars → From URL) to see your events there. The feed is read-only and personal — it contains your solo events and the events of all your groups.', 'project-prepper' ); ?></p>
			<div class="pp-cal__sub">
				<input type="text" class="pp-cal__sub-url" id="pp-cal-sub-url" readonly
					value="<?php echo esc_attr( CalendarController::user_feed_url( (int) $user->ID ) ); ?>"
					onclick="this.select()">
				<button type="button" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"
					data-pp-copy="pp-cal-sub-url"
					data-copied-label="<?php esc_attr_e( 'Copied!', 'project-prepper' ); ?>"><?php esc_html_e( 'Copy', 'project-prepper' ); ?></button>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					onsubmit="return confirm('<?php echo esc_js( __( 'Create a new feed URL? The old URL stops working and subscriptions must be updated.', 'project-prepper' ) ); ?>');">
					<?php self::action_fields( 'ical_rotate' ); ?>
					<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Renew URL', 'project-prepper' ); ?></button>
				</form>
			</div>

			<h4 class="pp-cal__sub-title"><?php esc_html_e( 'CalDAV (two-way sync)', 'project-prepper' ); ?></h4>
			<p class="pp-portal__hint">
				<?php esc_html_e( 'For clients that can write back changes (Apple Calendar, Thunderbird, DAVx5): add a CalDAV account with the address below. Unlike the read-only feed, events created or edited there sync back into your calendars.', 'project-prepper' ); ?>
				<br>
				<?php
				printf(
					/* translators: %s: WordPress login name. */
					esc_html__( 'Username: %s — Password: your calendar token (the same secret as in the feed URL above, the part after “token=”).', 'project-prepper' ),
					'<strong>' . esc_html( $user->user_login ) . '</strong>'
				);
				?>
			</p>
			<div class="pp-cal__sub">
				<input type="text" class="pp-cal__sub-url" id="pp-caldav-url" readonly
					value="<?php echo esc_attr( CalDavServer::base_url() ); ?>"
					onclick="this.select()">
				<button type="button" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"
					data-pp-copy="pp-caldav-url"
					data-copied-label="<?php esc_attr_e( 'Copied!', 'project-prepper' ); ?>"><?php esc_html_e( 'Copy', 'project-prepper' ); ?></button>
			</div>
		</section>
		<?php
	}

	/** Termin-Formular (Neu + Bearbeiten) — im Modal 2-spaltig, inline gestapelt. */
	private static function event_form( array $cals, ?object $e ): void {
		?>
		<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::action_fields( $e ? 'event_update' : 'event_create' ); ?>
			<?php if ( $e ) : ?>
				<input type="hidden" name="pp_event" value="<?php echo (int) $e->id; ?>">
			<?php endif; ?>
			<label><?php esc_html_e( 'Title', 'project-prepper' ); ?>
				<input type="text" name="pp_title" value="<?php echo esc_attr( $e ? (string) $e->title : '' ); ?>" required>
			</label>
			<label><?php esc_html_e( 'Calendar', 'project-prepper' ); ?>
				<select name="pp_calendar">
					<option value="0"><?php esc_html_e( 'No calendar', 'project-prepper' ); ?></option>
					<?php foreach ( $cals as $c ) : ?>
						<option value="<?php echo (int) $c->id; ?>"<?php selected( $e && (int) $e->calendar_group_id === (int) $c->id ); ?>><?php echo esc_html( $c->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'From', 'project-prepper' ); ?>
					<input type="date" name="pp_from" value="<?php echo esc_attr( $e ? (string) $e->date_from : '' ); ?>" required>
				</label>
				<label><?php esc_html_e( 'To', 'project-prepper' ); ?>
					<input type="date" name="pp_to" value="<?php echo esc_attr( $e ? (string) ( $e->date_to ?: '' ) : '' ); ?>">
				</label>
			</div>
			<div class="pp-portal__form-row">
				<label><?php esc_html_e( 'Start time', 'project-prepper' ); ?>
					<input type="time" name="pp_time_start" value="<?php echo esc_attr( $e ? (string) $e->time_start : '' ); ?>">
				</label>
				<label><?php esc_html_e( 'End time', 'project-prepper' ); ?>
					<input type="time" name="pp_time_end" value="<?php echo esc_attr( $e ? (string) $e->time_end : '' ); ?>">
				</label>
			</div>
			<p class="pp-portal__hint"><?php esc_html_e( 'Leave the times empty for an all-day event.', 'project-prepper' ); ?></p>
			<label><?php esc_html_e( 'Location', 'project-prepper' ); ?>
				<input type="text" name="pp_location" value="<?php echo esc_attr( $e ? (string) $e->location : '' ); ?>">
			</label>
			<label><?php esc_html_e( 'Description (optional)', 'project-prepper' ); ?>
				<textarea name="pp_description" rows="2"><?php echo esc_textarea( $e ? (string) $e->description : '' ); ?></textarea>
			</label>
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php echo esc_html( $e ? __( 'Save', 'project-prepper' ) : __( 'Create event', 'project-prepper' ) ); ?></button>
		</form>
		<?php
	}

	/** Farb-Auswahl als Radio-Swatches (feste App-Palette). */
	private static function color_swatches( string $current ): void {
		$current = '' !== $current ? strtoupper( $current ) : CalendarEvents::COLORS[0];
		if ( ! in_array( $current, CalendarEvents::COLORS, true ) ) {
			$current = CalendarEvents::COLORS[0];
		}
		?>
		<fieldset class="pp-swatches">
			<legend><?php esc_html_e( 'Color', 'project-prepper' ); ?></legend>
			<?php foreach ( CalendarEvents::COLORS as $c ) : ?>
				<label class="pp-swatch">
					<input type="radio" name="pp_color" value="<?php echo esc_attr( $c ); ?>"<?php checked( $current, $c ); ?>>
					<span class="pp-swatch__dot" style="background:<?php echo esc_attr( $c ); ?>"></span>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Events des Monats [ms..me] nach Tag (Y-m-d) gruppiert — gruppen-gescoped:
	 * eigene Gruppen-Projekte (+ deren Zeitplan) und eigene Ausleihen. KEINE
	 * site-weiten Verleihe (das ist Admin-Sache).
	 */
	private static function calendar_events( WP_User $user, array $groups, string $ms, string $me ): array {
		$by_day   = [];
		$projects = self::member_projects( $groups );

		// Eigene Termine des aktiven Arbeitsbereichs (v0.29.0) — in der Farbe
		// ihres Kalenders; Chip springt zur Termin-Liste unter dem Raster.
		$active = self::active_group_id( $groups );
		foreach ( CalendarEvents::events_between( (int) $user->ID, $active, $ms, $me ) as $e ) {
			$label = trim( ( '' !== (string) $e->time_start ? $e->time_start . ' ' : '' ) . $e->title );
			self::cal_span( $by_day, (string) $e->date_from, (string) ( $e->date_to ?: $e->date_from ), $ms, $me, [
				'type'       => 'event',
				'label'      => $label,
				'title'      => (string) $e->title,
				'time_start' => (string) $e->time_start,
				'time_end'   => (string) $e->time_end,
				'url'        => '#pp-ev-' . (int) $e->id,
				'color'      => (string) ( $e->calendar_color ?: CalendarEvents::COLORS[0] ),
			] );
		}

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
					'type'       => 'schedule',
					'label'      => $label,
					'title'      => (string) $s->title,
					'time_start' => ! empty( $s->time_start ) ? substr( (string) $s->time_start, 0, 5 ) : '',
					'time_end'   => ! empty( $s->time_end ) ? substr( (string) $s->time_end, 0, 5 ) : '',
					'url'        => add_query_arg( [ 'pp_view' => 'projects', 'pp_project' => (int) $p->id ], self::portal_url() ),
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

		// Pro Tag stabil sortieren: Termin, Projekt, Zeitplan, Verleih.
		$order = [ 'event' => 0, 'project' => 1, 'schedule' => 2, 'borrow' => 3 ];
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

		<?php // Live-Suche: der Scope umschließt Formular UND Instanz-Liste. ?>
		<div class="pp-net-live" data-pp-live-scope>
		<form class="pp-net-search" method="get" data-pp-live>
			<input type="hidden" name="pp_view" value="network">
			<input type="search" name="pp_q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Filter by item, postal code or topic …', 'project-prepper' ); ?>">
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Filter', 'project-prepper' ); ?></button>
		</form>

		<div class="pp-net-list">
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
			<section class="pp-net-inst" data-pp-searchable>
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
			<p class="pp-portal__empty" data-pp-search-none hidden><?php esc_html_e( 'Nothing matched your filter.', 'project-prepper' ); ?></p>
		</div>
		</div>
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

	/** Kompakte, verlinkte Kollektiv-Karte in der „Alle Gruppen"-Liste. */
	private static function render_collective_card( object $group ): void {
		$gid      = (int) $group->id;
		$role     = (string) $group->member_role;
		$members  = Groups::members( $gid );
		$count    = count( $members );
		$online   = count( Presence::online_user_ids( array_map( static fn( $m ) => (int) $m->user_id, $members ) ) );
		$logo_url = self::group_logo_url( (int) ( $group->logo_id ?? 0 ) );
		$desc     = trim( (string) ( $group->description ?? '' ) );
		$url      = add_query_arg( [ 'pp_view' => 'collectives', 'pp_group' => $gid ], self::portal_url() );
		?>
		<a class="pp-collective-card" href="<?php echo esc_url( $url ); ?>">
			<span class="pp-collective-card__logo">
				<?php if ( $logo_url ) : ?>
					<img src="<?php echo esc_url( $logo_url ); ?>" alt="">
				<?php else : ?>
					<span class="pp-collective-card__initial"><?php echo esc_html( self::initials( $group->name ) ); ?></span>
				<?php endif; ?>
			</span>
			<span class="pp-collective-card__body">
				<span class="pp-collective-card__head">
					<span class="pp-collective-card__name"><?php echo esc_html( $group->name ); ?></span>
					<span class="pp-portal__tag<?php echo 'founder' === $role ? '' : ' pp-portal__tag--muted'; ?>"><?php echo esc_html( 'founder' === $role ? __( 'Founder', 'project-prepper' ) : __( 'Member', 'project-prepper' ) ); ?></span>
				</span>
				<?php if ( '' !== $desc ) : ?>
					<span class="pp-collective-card__desc"><?php echo esc_html( $desc ); ?></span>
				<?php endif; ?>
				<span class="pp-collective-card__meta">
					<?php
					/* translators: %d: number of members. */
					echo esc_html( sprintf( _n( '%d member', '%d members', $count, 'project-prepper' ), $count ) );
					if ( $online > 0 ) :
						?>
						<span class="pp-portal__online-count">
							<span class="pp-portal__online-dot" aria-hidden="true"></span>
							<?php
							/* translators: %d: number of members currently online. */
							echo esc_html( sprintf( _n( '%d online', '%d online', $online, 'project-prepper' ), $online ) );
							?>
						</span>
					<?php endif; ?>
				</span>
			</span>
		</a>
		<?php
	}

	/**
	 * Einzel-Kollektiv-Detail mit zwei Reitern (Übersicht/Einstellungen) — Pendant
	 * zur Gruppendetailseite der App. Reiterwahl über ?pp_ctab; die Governance-
	 * Aktionen kehren über den Referer in den jeweiligen Reiter zurück.
	 */
	private static function view_collective_detail( object $group_row, int $user_id ): void {
		$gid   = (int) $group_row->id;
		$role  = (string) $group_row->member_role;
		$group = Groups::get( $gid ); // volle Zeile inkl. created_at/logo_id + ->members.
		$back  = add_query_arg( 'pp_view', 'collectives', self::portal_url() );
		if ( ! $group ) {
			?>
			<p class="pp-proj-back"><a href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( '← Back to collectives', 'project-prepper' ); ?></a></p>
			<p class="pp-portal__empty"><?php esc_html_e( 'This collective is not available.', 'project-prepper' ); ?></p>
			<?php
			return;
		}
		$members       = $group->members;
		$active_count  = count( $members );
		$founder_count = 0;
		foreach ( $members as $m ) {
			if ( 'founder' === $m->member_role ) {
				$founder_count++;
			}
		}
		$can_leave = ( 'founder' !== $role ) || $founder_count > 1;
		$logo_url  = self::group_logo_url( (int) ( $group->logo_id ?? 0 ) );
		$created   = self::fmt_date( $group->created_at ?? '' );

		$tabs = [
			'overview' => __( 'Overview', 'project-prepper' ),
			'settings' => __( 'Settings', 'project-prepper' ),
		];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige-Auswahl.
		$ctab = sanitize_key( wp_unslash( (string) ( $_GET['pp_ctab'] ?? 'overview' ) ) );
		if ( ! isset( $tabs[ $ctab ] ) ) {
			$ctab = 'overview';
		}
		$tab_base = add_query_arg( [ 'pp_view' => 'collectives', 'pp_group' => $gid ], self::portal_url() );
		?>
		<p class="pp-proj-back"><a href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( '← Back to collectives', 'project-prepper' ); ?></a></p>
		<header class="pp-app__page-head">
			<div class="pp-collective-detail__head">
				<span class="pp-collective-card__logo pp-collective-detail__logo">
					<?php if ( $logo_url ) : ?>
						<img src="<?php echo esc_url( $logo_url ); ?>" alt="">
					<?php else : ?>
						<span class="pp-collective-card__initial"><?php echo esc_html( self::initials( $group->name ) ); ?></span>
					<?php endif; ?>
				</span>
				<div>
					<div class="pp-proj-detail-head">
						<h1 class="pp-app__page-title"><?php echo esc_html( $group->name ); ?></h1>
						<span class="pp-portal__tag<?php echo 'founder' === $role ? '' : ' pp-portal__tag--muted'; ?>"><?php echo esc_html( 'founder' === $role ? __( 'Founder', 'project-prepper' ) : __( 'Member', 'project-prepper' ) ); ?></span>
					</div>
					<p class="pp-app__page-sub">
						<?php
						/* translators: %d: number of active members. */
						echo esc_html( sprintf( _n( '%d active member', '%d active members', $active_count, 'project-prepper' ), $active_count ) );
						if ( '' !== $created ) {
							/* translators: %s: date the collective was founded. */
							echo esc_html( ' · ' . sprintf( __( 'founded on %s', 'project-prepper' ), $created ) );
						}
						?>
					</p>
				</div>
			</div>
		</header>

		<nav class="pp-proj-tabs" aria-label="<?php esc_attr_e( 'Collective sections', 'project-prepper' ); ?>">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a class="pp-proj-tabs__tab<?php echo $key === $ctab ? ' pp-proj-tabs__tab--on' : ''; ?>"<?php echo $key === $ctab ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url( 'overview' === $key ? $tab_base : add_query_arg( 'pp_ctab', $key, $tab_base ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php
		if ( 'settings' === $ctab ) {
			self::render_collective_settings( $group, $role, $can_leave, $logo_url );
		} else {
			self::render_collective_overview( $gid, $role, $user_id, $members );
		}
	}

	/** Übersicht-Reiter: Mitglieder (mit E-Mail) + Einladen + Einladungen. */
	private static function render_collective_overview( int $group_id, string $role, int $user_id, array $members ): void {
		$online_ids = Presence::online_user_ids( array_map( static fn( $m ) => (int) $m->user_id, $members ) );
		$online_set = array_fill_keys( $online_ids, true );
		$online     = count( $online_ids );
		$open       = Governance::invitations_for_group( $group_id, [ 'pending', 'voting' ] );
		$recent     = Governance::recent_approved_for_group( $group_id );
		?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle">
				<?php
				/* translators: %d: number of members. */
				echo esc_html( sprintf( _n( 'Members (%d)', 'Members (%d)', count( $members ), 'project-prepper' ), count( $members ) ) );
				if ( $online > 0 ) :
					?>
					<span class="pp-portal__online-count">
						<span class="pp-portal__online-dot" aria-hidden="true"></span>
						<?php
						/* translators: %d: number of members currently online. */
						echo esc_html( sprintf( _n( '%d online', '%d online', $online, 'project-prepper' ), $online ) );
						?>
					</span>
				<?php endif; ?>
			</h3>
			<div class="pp-portal__memberlist">
				<?php
				foreach ( $members as $m ) :
					$is_founder = ( 'founder' === $m->member_role );
					$is_self    = ( (int) $m->user_id === $user_id );
					$is_online  = isset( $online_set[ (int) $m->user_id ] );
					$joined     = self::fmt_date( $m->joined_at );
					?>
					<div class="pp-portal__member">
						<span class="pp-collective-member__id">
							<span class="pp-portal__member-name">
								<?php if ( $is_online ) : ?>
									<span class="pp-portal__online-dot" title="<?php esc_attr_e( 'Online', 'project-prepper' ); ?>"></span>
									<span class="screen-reader-text"><?php esc_html_e( 'Online', 'project-prepper' ); ?></span>
								<?php endif; ?>
								<?php echo esc_html( $m->display_name ); ?>
								<?php if ( $is_self ) : ?>
									<span class="pp-portal__member-you"><?php esc_html_e( '(you)', 'project-prepper' ); ?></span>
								<?php endif; ?>
							</span>
							<?php if ( '' !== (string) $m->user_email ) : ?>
								<span class="pp-collective-member__email"><?php echo esc_html( $m->user_email ); ?></span>
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
							<?php // Gründer können andere Mitglieder entfernen (nicht sich selbst → Austreten). ?>
							<?php if ( 'founder' === $role && ! $is_self ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
									onsubmit="return confirm('<?php echo esc_js( sprintf( /* translators: %s: member name. */ __( 'Remove %s from the collective?', 'project-prepper' ), $m->display_name ) ); ?>');">
									<?php self::action_fields( 'member_remove' ); ?>
									<input type="hidden" name="pp_group" value="<?php echo (int) $group_id; ?>">
									<input type="hidden" name="pp_member" value="<?php echo (int) $m->user_id; ?>">
									<button type="submit" class="pp-portal__chip"><?php esc_html_e( 'Remove', 'project-prepper' ); ?></button>
								</form>
							<?php endif; ?>
						</span>
					</div>
				<?php endforeach; ?>
			</div>

			<details class="pp-portal__add" style="margin-top:.75rem">
				<summary class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Invite a member', 'project-prepper' ); ?></summary>
				<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php self::action_fields( 'invite' ); ?>
					<input type="hidden" name="pp_group" value="<?php echo (int) $group_id; ?>">
					<p class="pp-portal__hint" style="border:0;padding:0;margin:0 0 .2rem"><?php esc_html_e( 'All existing members must approve unanimously.', 'project-prepper' ); ?></p>
					<label><?php esc_html_e( 'Email address', 'project-prepper' ); ?>
						<input type="email" name="pp_email" placeholder="email@example.com" required>
					</label>
					<label><?php esc_html_e( 'Message (optional)', 'project-prepper' ); ?>
						<textarea name="pp_message" rows="2" placeholder="<?php esc_attr_e( 'Add a personal note to the invitation…', 'project-prepper' ); ?>"></textarea>
					</label>
					<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Send invitation', 'project-prepper' ); ?></button>
				</form>
			</details>
		</section>

		<?php if ( $open || $recent ) : ?>
			<section class="pp-portal__section">
				<h3 class="pp-portal__subtitle">
					<?php
					/* translators: %d: number of open invitations. */
					printf( esc_html__( 'Invitations (%d open)', 'project-prepper' ), count( $open ) );
					?>
				</h3>
				<div class="pp-invite-list">
					<?php
					foreach ( $open as $inv ) {
						self::render_invitation_card( $inv, $user_id );
					}
					foreach ( $recent as $inv ) {
						self::render_invitation_card( $inv, $user_id );
					}
					?>
				</div>
			</section>
		<?php endif; ?>
		<?php
	}

	/** Einstellungen-Reiter: Kollektiv bearbeiten (Gründer) + Austreten/Auflösen. */
	private static function render_collective_settings( object $group, string $role, bool $can_leave, ?string $logo_url ): void {
		$group_id   = (int) $group->id;
		$name       = (string) $group->name;
		$description = (string) ( $group->description ?? '' );
		$tg_chat_id = 'founder' === $role ? Telegram::chat_id( $group_id ) : '';
		$tg_has_bot = 'founder' === $role ? Telegram::has_bot_token() : false;
		?>
		<section class="pp-portal__section">
			<?php if ( 'founder' === $role ) : ?>
				<h3 class="pp-portal__subtitle"><?php esc_html_e( 'Edit collective', 'project-prepper' ); ?></h3>
				<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php self::action_fields( 'group_update' ); ?>
					<input type="hidden" name="pp_group" value="<?php echo (int) $group_id; ?>">
					<label><?php esc_html_e( 'Collective name', 'project-prepper' ); ?>
						<input type="text" name="pp_name" value="<?php echo esc_attr( $name ); ?>" required>
					</label>
					<label><?php esc_html_e( 'Description (optional)', 'project-prepper' ); ?>
						<textarea name="pp_description" rows="2"><?php echo esc_textarea( $description ); ?></textarea>
					</label>
					<label><?php esc_html_e( 'Telegram chat ID (optional)', 'project-prepper' ); ?>
						<input type="text" name="pp_telegram_chat_id" value="<?php echo esc_attr( $tg_chat_id ); ?>" placeholder="-1001234567890" inputmode="text">
					</label>
					<p class="pp-portal__hint">
						<?php esc_html_e( 'Send short notifications (new inquiries, bookings) to your collective’s Telegram group. Add the instance bot to the group, then paste the group’s chat ID here.', 'project-prepper' ); ?>
						<?php if ( ! $tg_has_bot ) : ?>
							<br><strong><?php esc_html_e( 'The operator has not set up a Telegram bot yet — notifications stay off until they do.', 'project-prepper' ); ?></strong>
						<?php endif; ?>
					</p>
					<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
				</form>
				<?php if ( '' !== $tg_chat_id && $tg_has_bot ) : ?>
					<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php self::action_fields( 'telegram_test' ); ?>
						<input type="hidden" name="pp_group" value="<?php echo (int) $group_id; ?>">
						<button type="submit" class="pp-portal__chip"><?php esc_html_e( 'Send test message', 'project-prepper' ); ?></button>
					</form>
				<?php endif; ?>
				<div class="pp-collective-settings__logo">
					<span class="pp-collective-card__logo pp-collective-detail__logo">
						<?php if ( $logo_url ) : ?>
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="">
						<?php else : ?>
							<span class="pp-collective-card__initial"><?php echo esc_html( self::initials( $name ) ); ?></span>
						<?php endif; ?>
					</span>
					<form class="pp-portal__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pp_group_logo">
						<?php wp_nonce_field( 'pp_group_logo', 'pp_nonce' ); ?>
						<input type="hidden" name="pp_group" value="<?php echo (int) $group_id; ?>">
						<label><?php echo esc_html( $logo_url ? __( 'Replace logo', 'project-prepper' ) : __( 'Logo (optional)', 'project-prepper' ) ); ?>
							<input type="file" name="pp_logo" accept="image/*" required>
						</label>
						<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Upload logo', 'project-prepper' ); ?></button>
					</form>
					<?php if ( $logo_url ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="pp_group_logo">
							<?php wp_nonce_field( 'pp_group_logo', 'pp_nonce' ); ?>
							<input type="hidden" name="pp_group" value="<?php echo (int) $group_id; ?>">
							<input type="hidden" name="pp_remove" value="1">
							<button type="submit" class="pp-portal__chip"><?php esc_html_e( 'Remove logo', 'project-prepper' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<h3 class="pp-portal__subtitle"><?php esc_html_e( 'Settings', 'project-prepper' ); ?></h3>
				<?php if ( '' !== trim( $description ) ) : ?>
					<p class="pp-portal__collective-desc"><?php echo nl2br( esc_html( $description ) ); ?></p>
				<?php endif; ?>
				<p class="pp-portal__hint" style="border:0;padding:0"><?php esc_html_e( 'Only founders can change the collective’s settings.', 'project-prepper' ); ?></p>
			<?php endif; ?>

			<div class="pp-portal__collective-foot">
				<?php if ( $can_leave ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php echo esc_js( __( 'Leave this group? You will lose access to its shared inventory and projects.', 'project-prepper' ) ); ?>');">
						<?php self::action_fields( 'group_leave' ); ?>
						<input type="hidden" name="pp_group" value="<?php echo (int) $group_id; ?>">
						<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm pp-portal__btn--danger"><?php esc_html_e( 'Leave group', 'project-prepper' ); ?></button>
					</form>
				<?php elseif ( 'founder' === $role ) : ?>
					<p class="pp-portal__hint"><?php esc_html_e( 'You are the only founder. Appoint another founder before you can leave — or dissolve the collective below.', 'project-prepper' ); ?></p>
				<?php endif; ?>

				<?php /* Gründer können die Gruppe auflösen; Projekte bleiben (fallen auf Site-Ebene). */ ?>
				<?php if ( 'founder' === $role ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php echo esc_js( __( 'Dissolve this collective for everyone? Members lose access to the shared inventory. Projects are kept and move to the site level. This cannot be undone.', 'project-prepper' ) ); ?>');">
						<?php self::action_fields( 'group_delete' ); ?>
						<input type="hidden" name="pp_group" value="<?php echo (int) $group_id; ?>">
						<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm pp-portal__btn--danger"><?php esc_html_e( 'Dissolve collective', 'project-prepper' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/** Status-Chip-Klasse + Label für eine Einladung. */
	private static function invitation_status_meta( string $status ): array {
		$map = [
			'pending'   => [ 'pp-portal__tag--warn', __( 'Waiting for answer', 'project-prepper' ) ],
			'voting'    => [ 'pp-portal__tag--info', __( 'Voting in progress', 'project-prepper' ) ],
			'approved'  => [ 'pp-portal__tag--ok', __( 'Joined', 'project-prepper' ) ],
			'rejected'  => [ 'pp-portal__tag--muted', __( 'Rejected by a member', 'project-prepper' ) ],
			'cancelled' => [ 'pp-portal__tag--muted', __( 'Withdrawn', 'project-prepper' ) ],
		];
		return $map[ $status ] ?? [ 'pp-portal__tag--muted', $status ];
	}

	/** Eine Einladungs-Karte im Übersicht-Reiter (offen: aktiv, approved: read-only). */
	private static function render_invitation_card( object $inv, int $user_id ): void {
		$status        = (string) $inv->status;
		[ $cls, $label ] = self::invitation_status_meta( $status );
		$is_invitee    = ( (int) $inv->invited_user_id === $user_id );
		$reminders     = (int) ( $inv->reminder_count ?? 0 );
		$vreminders    = (int) ( $inv->voting_reminder_count ?? 0 );
		$created       = self::fmt_date( $inv->created_at );
		$msg           = trim( (string) ( $inv->message ?? '' ) );
		$inviter       = (string) ( $inv->inviter_name ?? '' );
		$title         = (string) ( $inv->invitee_name ?? '' );
		if ( '' === $title ) {
			$title = (string) $inv->invited_email;
		}
		$show_email    = ( '' !== (string) $inv->invited_email && $title !== (string) $inv->invited_email );
		$pending       = isset( $inv->pending_voter_names ) && is_array( $inv->pending_voter_names ) ? $inv->pending_voter_names : [];
		?>
		<div class="pp-invite-card">
			<div class="pp-invite-card__top">
				<div class="pp-invite-card__id">
					<span class="pp-invite-card__name"><?php echo esc_html( $title ); ?></span>
					<?php if ( $show_email ) : ?>
						<span class="pp-invite-card__email"><?php echo esc_html( $inv->invited_email ); ?></span>
					<?php endif; ?>
				</div>
				<span class="pp-portal__tag <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $label ); ?></span>
			</div>

			<div class="pp-invite-card__meta">
				<span>
					<?php
					if ( '' !== $inviter && '' !== $created ) {
						/* translators: 1: inviter name, 2: date. */
						printf( esc_html__( 'Invited by %1$s · %2$s', 'project-prepper' ), esc_html( $inviter ), esc_html( $created ) );
					} elseif ( '' !== $created ) {
						echo esc_html( $created );
					}
					?>
				</span>
				<?php if ( $reminders > 0 ) : ?>
					<?php /* translators: %d: number of reminder emails sent. */ ?>
					<span class="pp-invite-card__chip"><?php echo esc_html( sprintf( __( 'Reminded %d×', 'project-prepper' ), $reminders ) ); ?></span>
				<?php endif; ?>
				<?php if ( $vreminders > 0 ) : ?>
					<?php /* translators: %d: number of voting reminders sent. */ ?>
					<span class="pp-invite-card__chip pp-invite-card__chip--info"><?php echo esc_html( sprintf( __( 'Voting reminder %d×', 'project-prepper' ), $vreminders ) ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( '' !== $msg ) : ?>
				<p class="pp-invite-card__quote"><?php echo esc_html( '„' . $msg . '“' ); ?></p>
			<?php endif; ?>

			<?php if ( 'voting' === $status ) :
				$needed = max( 1, (int) $inv->needed );
				$pct    = min( 100, (int) round( (int) $inv->approvals / $needed * 100 ) );
				?>
				<div class="pp-invite-card__voting">
					<div class="pp-invite-card__progress-label">
						<?php
						/* translators: 1: approvals, 2: members needed. */
						printf( esc_html__( '%1$d / %2$d approvals', 'project-prepper' ), (int) $inv->approvals, (int) $inv->needed );
						?>
					</div>
					<div class="pp-invite-card__bar"><span class="pp-invite-card__fill" style="width:<?php echo esc_attr( $pct ); ?>%"></span></div>

					<?php if ( $pending ) : ?>
						<div class="pp-invite-card__pending">
							<span class="pp-invite-card__pending-names">
								<?php
								/* translators: %s: comma-separated member names. */
								printf( esc_html__( 'Awaiting: %s', 'project-prepper' ), esc_html( implode( ', ', $pending ) ) );
								?>
							</span>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php self::action_fields( 'invite_remind_voters' ); ?>
								<input type="hidden" name="pp_invitation" value="<?php echo (int) $inv->id; ?>">
								<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Remind', 'project-prepper' ); ?></button>
							</form>
						</div>
					<?php endif; ?>

					<?php if ( ! $is_invitee ) : ?>
						<?php if ( $inv->my_vote ) : ?>
							<div class="pp-invite-card__myvote">
								<?php
								/* translators: %s: the user's own vote (approved/rejected). */
								printf( esc_html__( 'Your vote: %s', 'project-prepper' ), esc_html( self::vote_label( $inv->my_vote ) ) );
								?>
							</div>
						<?php else : ?>
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
				</div>
			<?php endif; ?>

			<?php if ( in_array( $status, [ 'pending', 'voting' ], true ) ) : ?>
				<div class="pp-invite-card__actions">
					<?php if ( 'pending' === $status ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php self::action_fields( 'invite_resend' ); ?>
							<input type="hidden" name="pp_invitation" value="<?php echo (int) $inv->id; ?>">
							<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Remind', 'project-prepper' ); ?></button>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Withdraw this invitation?', 'project-prepper' ) ); ?>');">
						<?php self::action_fields( 'cancel' ); ?>
						<input type="hidden" name="pp_invitation" value="<?php echo (int) $inv->id; ?>">
						<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm pp-portal__btn--danger"><?php esc_html_e( 'Withdraw invitation', 'project-prepper' ); ?></button>
					</form>
				</div>
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
		$items     = $shown_items;
		$base_url  = self::portal_url();
		$own_cats   = MemberInventory::own_categories( (int) $user->ID );
		$tpl_cats   = MemberInventory::template_categories();
		$categories = [ 'own' => $own_cats, 'templates' => $tpl_cats ];
		$conditions = Shortcodes::condition_labels();
		// Sets (docs/07): Stücklisten + Teile-Kandidaten IMMER über den UNGEFILTERTEN
		// Bestand — die „Set-Inhalt"-Formulare ersetzen die Stückliste komplett und
		// müssen deshalb jeden möglichen Teil-Artikel enthalten (sonst würden bei
		// aktiver Suche nicht gelistete Teile beim Speichern verloren gehen).
		$pp_all_own  = '' === $q ? $all_items : MemberInventory::my_items( (int) $user->ID );
		$pp_by_id    = [];
		foreach ( $pp_all_own as $pp_it ) {
			$pp_by_id[ (int) $pp_it->id ] = $pp_it;
		}
		$bundles_map = Bundles::for_items( array_keys( $pp_by_id ) );
		// Kandidaten für den Set-Inhalt: eigene Artikel, die selbst kein Set sind.
		$bundle_candidates = array_values( array_filter( $pp_all_own, static function ( $it ) use ( $bundles_map ) {
			return ! isset( $bundles_map[ (int) $it->id ] );
		} ) );
		?>
		<section class="pp-portal__section" data-pp-live-scope>
			<?php if ( $heading ) : ?>
				<h3 class="pp-portal__subtitle"><?php esc_html_e( 'My inventory', 'project-prepper' ); ?></h3>
			<?php endif; ?>

			<?php self::render_inventory_tools( $categories, $conditions, $own_cats, $tpl_cats, $user, $groups, $bundle_candidates ); ?>

			<?php if ( $all_items || '' !== $q ) : ?>
				<form class="pp-inv-search" method="get" data-pp-live>
					<input type="hidden" name="pp_view" value="inventory">
					<input type="search" name="pp_q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Search your inventory …', 'project-prepper' ); ?>">
					<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Search', 'project-prepper' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( $all_items ) : ?>
				<p class="pp-inv-kpi">
					<?php
					$pp_dv = ( (float) $total_value === floor( (float) $total_value ) ) ? number_format_i18n( $total_value, 0 ) : number_format_i18n( $total_value, 2 );
					/* translators: 1: item count, 2: total pieces, 3: total daily value in euros. */
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
					<?php
					$shared = $groups ? MemberInventory::shared_group_ids( (int) $item->id ) : [];
					$shared_names = [];
					foreach ( $groups as $pp_g ) {
						if ( in_array( (int) $pp_g->id, $shared, true ) ) {
							$shared_names[] = $pp_g->name;
						}
					}
					// Set (docs/07): Menge = komplette Sets aus dem Teil-Bestand,
					// Verfügbar = Sets aus dem, was von den Teilen JETZT da ist.
					$pp_parts     = $bundles_map[ (int) $item->id ] ?? [];
					$pp_set_total = 0;
					$pp_set_free  = 0;
					if ( $pp_parts ) {
						$pp_set_total = PHP_INT_MAX;
						$pp_set_free  = PHP_INT_MAX;
						foreach ( $pp_parts as $pp_p ) {
							$pp_need      = max( 1, (int) $pp_p->quantity );
							$pp_part_item = $pp_by_id[ (int) $pp_p->part_item_id ] ?? null;
							$pp_qty       = $pp_part_item ? (int) $pp_part_item->quantity : (int) ( $pp_p->part_total ?? 0 );
							$pp_out       = $pp_part_item ? (int) ( $pp_part_item->out_now ?? 0 ) : 0;
							$pp_set_total = min( $pp_set_total, (int) floor( $pp_qty / $pp_need ) );
							$pp_set_free  = min( $pp_set_free, (int) floor( max( 0, $pp_qty - $pp_out ) / $pp_need ) );
						}
					}
					?>
					<div class="pp-portal__item pp-portal__item--row" data-pp-search-row>
						<div class="pp-inv-row pp-portal__item-head pp-inv-row--click" role="button" tabindex="0" data-pp-modal="pp-item-<?php echo (int) $item->id; ?>" data-pp-searchable>
							<span class="pp-col pp-col--name">
								<?php if ( ! empty( $item->image_url ) ) : ?><img class="pp-portal__item-thumb" src="<?php echo esc_url( $item->image_url ); ?>" alt="" loading="lazy"><?php else : ?><span class="pp-portal__item-thumb pp-portal__item-thumb--empty" aria-hidden="true"></span><?php endif; ?>
								<span class="pp-inv-name-wrap"><span class="pp-inv-name-top"><span class="pp-portal__group-name"><?php echo esc_html( $item->name ); ?></span> <?php if ( $pp_parts ) : ?><span class="pp-bundle-chip"><?php esc_html_e( 'Set', 'project-prepper' ); ?></span> <?php endif; ?><small class="pp-portal__item-num"><?php echo esc_html( $item->inventory_number ); ?></small></span><?php $pp_sub = $pp_parts ? Bundles::parts_label( $pp_parts ) : ( $item->model ?: ( $item->description ?? '' ) ); if ( '' !== trim( (string) $pp_sub ) ) : ?><small class="pp-inv-name-sub"><?php echo esc_html( (string) $pp_sub ); ?></small><?php endif; ?></span>
							</span>
							<span class="pp-col pp-col--cat" data-label="<?php esc_attr_e( 'Category', 'project-prepper' ); ?>"><?php echo $item->category_name ? esc_html( trim( ( $item->category_icon ? $item->category_icon . ' ' : '' ) . (string) $item->category_name ) ) : '—'; ?></span>
							<span class="pp-col pp-col--c" data-label="<?php esc_attr_e( 'Quantity', 'project-prepper' ); ?>"><?php echo (int) ( $pp_parts ? $pp_set_total : $item->quantity ); ?></span>
							<span class="pp-col pp-col--c" data-label="<?php esc_attr_e( 'Available', 'project-prepper' ); ?>"><?php echo (int) ( $pp_parts ? $pp_set_free : max( 0, (int) $item->quantity - (int) ( $item->out_now ?? 0 ) ) ); ?></span>
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
							<?php // EIN Formular für alles (Feedback: kein Speichern-Button
							// pro Abschnitt): Foto, Stammdaten und Kollektiv-Freigaben
							// werden zusammen gespeichert — beim Klick auf „Speichern"
							// oder automatisch beim Schließen (portal.js, data-pp-autosave). ?>
							<form class="pp-portal__form pp-item-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-pp-autosave>
								<?php self::action_fields( 'item_save_all' ); ?>
								<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
								<div class="pp-modal-photo">
									<?php if ( ! empty( $item->image_url ) ) : ?>
										<img class="pp-modal-photo__img" src="<?php echo esc_url( $item->image_url ); ?>" alt="">
									<?php endif; ?>
									<label class="pp-modal-photo__pick"><?php echo esc_html( empty( $item->image_url ) ? __( 'Photo (optional)', 'project-prepper' ) : __( 'Replace photo', 'project-prepper' ) ); ?>
										<input type="file" name="pp_photo" accept="image/*">
									</label>
									<?php if ( ! empty( $item->image_url ) ) : ?>
										<label class="pp-modal-photo__removecb"><input type="checkbox" name="pp_photo_remove" value="1"> <?php esc_html_e( 'Remove photo', 'project-prepper' ); ?></label>
									<?php endif; ?>
								</div>
								<?php self::item_fields( $categories, $conditions, $item ); ?>
								<?php self::item_bundle_fields( $bundle_candidates, $bundles_map[ (int) $item->id ] ?? [], $item ); ?>
								<?php self::item_share_fields( $groups, $groups ? MemberInventory::share_settings( (int) $item->id ) : [] ); ?>
								<div class="pp-item-form__save">
									<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
									<span class="pp-portal__hint"><?php esc_html_e( 'Changes are also saved automatically when you close this window.', 'project-prepper' ); ?></span>
								</div>
							</form>
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
				<p class="pp-portal__empty" data-pp-search-none hidden><?php esc_html_e( 'No items match your search.', 'project-prepper' ); ?></p>
			<?php else : ?>
				<?php if ( '' !== $q ) : ?>
					<p class="pp-portal__empty"><?php esc_html_e( 'No items match your search.', 'project-prepper' ); ?></p>
				<?php else : ?>
					<p class="pp-portal__empty"><?php esc_html_e( 'You have no personal inventory yet. Add your first item below.', 'project-prepper' ); ?></p>
				<?php endif; ?>
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
	/**
	 * Formular „Artikel anlegen" — bewusst ALLES in einem Schritt: Stammdaten,
	 * optionales Foto und die Kollektiv-Freigaben (Feedback: nicht erst anlegen
	 * und dann über „Verwalten" nachpflegen). Multipart wegen des Foto-Felds.
	 */
	private static function item_form( string $do, array $categories, array $conditions, ?object $item, array $groups = [], array $bundle_candidates = [] ): void {
		?>
		<form class="pp-portal__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php self::action_fields( $do ); ?>
			<?php if ( $item ) : ?>
				<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
			<?php endif; ?>
			<?php self::item_fields( $categories, $conditions, $item ); ?>
			<label><?php esc_html_e( 'Photo (optional)', 'project-prepper' ); ?>
				<input type="file" name="pp_photo" accept="image/*">
			</label>
			<?php self::item_bundle_fields( $bundle_candidates, [], $item ); ?>
			<?php self::item_share_fields( $groups, [] ); ?>
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save item', 'project-prepper' ); ?></button>
		</form>
		<?php
	}

	/** Stammdaten-Felder eines Artikels (gemeinsam für Anlegen + Verwalten-Modal). */
	private static function item_fields( array $categories, array $conditions, ?object $item ): void {
		$val      = static fn( string $field, $default = '' ) => $item && isset( $item->$field ) ? $item->$field : $default;
		$selected = (int) $val( 'category_id', 0 );
		?>
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
		<?php
	}

	/**
	 * Freigabe-Blöcke „Mit deinen Kollektiven teilen" für das vereinte Artikel-
	 * Formular (Anlegen + Verwalten-Modal). Feldnamen sind pro Gruppe indexiert
	 * (pp_share_on[<gid>] …) und werden von apply_share_input() ausgewertet.
	 * „Freigabe erforderlich" ist bei NEUEN Freigaben bewusst vorangehakt —
	 * Feedback: die Freigabe durch den Eigentümer soll der Standard sein.
	 *
	 * @param array<object>     $groups  Gruppen des Users (leer → keine Ausgabe).
	 * @param array<int,object> $cfg_map group_id => bestehende Freigabe-Konditionen.
	 */
	private static function item_share_fields( array $groups, array $cfg_map ): void {
		if ( ! $groups ) {
			return;
		}
		$presets = MemberInventory::condition_presets();
		?>
		<div class="pp-share">
			<h4 class="pp-share__title"><?php esc_html_e( 'Share with your collectives', 'project-prepper' ); ?></h4>
			<?php foreach ( $groups as $g ) :
				$gid = (int) $g->id;
				$cfg = $cfg_map[ $gid ] ?? null; ?>
				<div class="pp-share__group">
					<label class="pp-share__head">
						<input type="checkbox" name="pp_share_on[<?php echo (int) $gid; ?>]" value="1" data-pp-share-toggle <?php checked( null !== $cfg ); ?>>
						<span class="pp-share__name"><?php echo esc_html( $g->name ); ?></span>
					</label>
					<div class="pp-share__body">
						<div class="pp-share__fields">
							<label class="pp-share__rate"><?php esc_html_e( 'Daily rate (€)', 'project-prepper' ); ?>
								<input type="number" step="0.01" min="0" name="pp_share_rate[<?php echo (int) $gid; ?>]" value="<?php echo ( $cfg && null !== $cfg->daily_rate ) ? esc_attr( number_format( (float) $cfg->daily_rate, 2, '.', '' ) ) : ''; ?>">
							</label>
							<label class="pp-share__approval"><input type="checkbox" name="pp_share_approval[<?php echo (int) $gid; ?>]" value="1" <?php checked( $cfg ? ! empty( $cfg->requires_approval ) : true ); ?>> <?php esc_html_e( 'Requires approval', 'project-prepper' ); ?></label>
						</div>
						<div class="pp-share__conds">
							<?php foreach ( $presets as $pp_key => $pp_label ) :
								$pp_on = $cfg && in_array( $pp_key, (array) $cfg->conditions_tags, true ); ?>
								<label class="pp-portal__chip"><input type="checkbox" name="pp_share_cond[<?php echo (int) $gid; ?>][]" value="<?php echo esc_attr( $pp_key ); ?>" <?php checked( $pp_on ); ?> hidden><?php echo esc_html( $pp_label ); ?></label>
							<?php endforeach; ?>
						</div>
						<label class="pp-share__notes"><?php esc_html_e( 'Notes (optional)', 'project-prepper' ); ?>
							<textarea name="pp_share_notes[<?php echo (int) $gid; ?>]" rows="2"><?php echo esc_textarea( $cfg->conditions ?? '' ); ?></textarea>
						</label>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Abschnitt „Set-Inhalt" des vereinten Artikel-Formulars (docs/07 §5): eigene
	 * Nicht-Set-Artikel mit Stückzahl-Feld (0 = nicht enthalten). Sobald Teile
	 * gewählt sind, ist der Artikel ein Set und wird bei Buchungen automatisch in
	 * seine Teile aufgelöst. Artikel, die selbst TEIL eines Sets sind, können kein
	 * Set werden (kein Set im Set) — dann nur ein Hinweis.
	 *
	 * @param array<object>     $candidates Eigene Nicht-Set-Artikel.
	 * @param array<object>     $current    Bestehende Stückliste (aus Bundles).
	 * @param object|null       $item       Der Artikel (null = Anlegen).
	 */
	private static function item_bundle_fields( array $candidates, array $current, ?object $item ): void {
		if ( $item ) {
			$pp_in = Bundles::part_of_bundle_names( (int) $item->id );
			if ( $pp_in ) {
				?>
				<p class="pp-portal__hint pp-bundle-edit__blocked"><?php
					/* translators: %s: names of the sets this item is part of. */
					printf( esc_html__( 'This item is part of the set “%s” and cannot be a set itself.', 'project-prepper' ), esc_html( implode( '”, “', $pp_in ) ) );
				?></p>
				<?php
				return;
			}
		}
		$item_id    = $item ? (int) $item->id : 0;
		$candidates = array_values( array_filter( $candidates, static fn( $c ) => (int) $c->id !== $item_id ) );
		if ( ! $candidates ) {
			return;
		}
		$current_map = [];
		foreach ( $current as $part ) {
			$current_map[ (int) $part->part_item_id ] = (int) $part->quantity;
		}
		?>
		<details class="pp-bundle-edit" <?php echo $current_map ? 'open' : ''; ?>>
			<summary class="pp-bundle-edit__head"><?php esc_html_e( 'Set contents', 'project-prepper' ); ?><?php if ( $current_map ) : ?> (<?php echo (int) count( $current_map ); ?>)<?php endif; ?></summary>
			<input type="hidden" name="pp_bundle_present" value="1">
			<p class="pp-portal__hint"><?php esc_html_e( 'Pick quantities to turn this item into a set — bookings then automatically book its parts. 0 removes a part.', 'project-prepper' ); ?></p>
			<div class="pp-bundle-edit__list">
				<?php foreach ( $candidates as $cand ) : ?>
					<label class="pp-bundle-edit__row">
						<input type="number" name="pp_bundle_qty[<?php echo (int) $cand->id; ?>]" min="0" value="<?php echo (int) ( $current_map[ (int) $cand->id ] ?? 0 ); ?>">
						<span class="pp-bundle-edit__name"><?php echo esc_html( $cand->name ); ?> <small class="pp-portal__item-num"><?php echo esc_html( (string) $cand->inventory_number ); ?></small></span>
					</label>
				<?php endforeach; ?>
			</div>
		</details>
		<?php
	}

	/**
	 * „Set-Inhalt"-Eingaben des vereinten Artikel-Formulars anwenden. Fehlt der
	 * Sektions-Marker (pp_bundle_present), bleibt die Stückliste unangetastet —
	 * das Formular hatte den Abschnitt nicht (z.B. Artikel ist Teil eines Sets).
	 *
	 * @return true|\WP_Error
	 */
	private static function apply_bundle_input( int $user_id, int $item_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		if ( empty( $_POST['pp_bundle_present'] ) ) {
			return true;
		}
		$raw = is_array( $_POST['pp_bundle_qty'] ?? null ) ? wp_unslash( $_POST['pp_bundle_qty'] ) : [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$parts = [];
		foreach ( $raw as $pid => $qty ) {
			$parts[ (int) $pid ] = (int) $qty;
		}
		return Bundles::set_parts( $user_id, $item_id, $parts );
	}

	/* ---------- Mein Inventar: CSV-Export / -Import ---------- */

	/** Werkzeugleiste über dem eigenen Inventar: Export-Link + Import-Formular. */
	private static function render_inventory_tools( array $categories, array $conditions, array $own_cats, array $tpl_cats, ?WP_User $user = null, array $groups = [], array $bundle_candidates = [] ): void {
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
				<?php self::item_form( 'item_create', $categories, $conditions, null, $groups, $bundle_candidates ); ?>
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
									<?php // „Freigabe erforderlich" bewusst vorangehakt — Feedback:
									// die Freigabe durch den Eigentümer soll der Standard sein. ?>
									<label class="pp-share__approval"><input type="checkbox" name="pp_approval" value="1" checked> <?php esc_html_e( 'Requires approval', 'project-prepper' ); ?></label>
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
			// CSV-/Formula-Injection abwehren (gemeinsamer Helfer).
			fputcsv( $out, array_map( [ \ProjectPrepper\Rest\ImportExportController::class, 'csv_safe' ], $row ), ';' );
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
		// Kategorie-Namen → ID (eigene Kategorien + Betreiber-Vorlagen; unbekannte
		// Namen werden beim Import als eigene Kategorien angelegt — wie in der App).
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
			$cat_name = trim( (string) ( $d['category_name'] ?? '' ) );
			if ( '' !== $cat_name && ! isset( $cat_map[ self::norm_head( $cat_name ) ] ) ) {
				$new_cat = MemberInventory::create_category( $user_id, [
					'name'   => $cat_name,
					'prefix' => mb_strtoupper( mb_substr( $cat_name, 0, 3 ) ),
				] );
				if ( ! is_wp_error( $new_cat ) ) {
					$cat_map[ self::norm_head( $cat_name ) ] = (int) $new_cat;
				}
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

		$attach_id = self::create_photo_attachment();
		if ( is_wp_error( $attach_id ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'photo_failed', $back ) );
			exit;
		}
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
	 * Gruppen-Logo hochladen/ersetzen/entfernen (App: groups.logo_url) — eigener
	 * multipart-Handler nach dem Avatar-Muster. Nur Gründer der Gruppe; das
	 * alte Attachment wird beim Ersetzen/Entfernen mit aufgeräumt.
	 */
	public static function handle_group_logo(): void {
		$back = add_query_arg( 'pp_view', 'collectives', self::portal_url() );
		if ( ! is_user_logged_in() || ! isset( $_POST['pp_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pp_nonce'] ), 'pp_group_logo' ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'error', $back ) );
			exit;
		}

		// IDOR-Gate: nur Gründer DIESER Gruppe dürfen das Logo ändern.
		$group_id = (int) ( $_POST['pp_group'] ?? 0 );
		if ( ! $group_id || ! self::is_group_founder( $group_id, get_current_user_id() ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'error', $back ) );
			exit;
		}
		// Zurück in den Einstellungen-Reiter des jeweiligen Kollektiv-Details.
		$back = add_query_arg(
			[ 'pp_view' => 'collectives', 'pp_group' => $group_id, 'pp_ctab' => 'settings' ],
			self::portal_url()
		);
		$group  = Groups::get( $group_id );
		$old_id = $group ? (int) ( $group->logo_id ?? 0 ) : 0;

		// Entfernen.
		if ( ! empty( $_POST['pp_remove'] ) ) {
			Groups::update( $group_id, [ 'logo_id' => 0 ] );
			if ( $old_id ) {
				wp_delete_attachment( $old_id, true );
			}
			wp_safe_redirect( add_query_arg( 'pp_msg', 'logo_removed', $back ) );
			exit;
		}

		if ( empty( $_FILES['pp_logo']['tmp_name'] ) || ! is_uploaded_file( $_FILES['pp_logo']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'logo_failed', $back ) );
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
		$moved = wp_handle_upload( $_FILES['pp_logo'], $overrides );
		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'logo_failed', $back ) );
			exit;
		}

		$attach_id = wp_insert_attachment( [
			'post_mime_type' => $moved['type'],
			'post_title'     => sanitize_file_name( wp_basename( $moved['file'] ) ),
			'post_status'    => 'inherit',
		], $moved['file'] );
		if ( ! $attach_id || is_wp_error( $attach_id ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'logo_failed', $back ) );
			exit;
		}
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( (int) $attach_id, $moved['file'] ) );
		Groups::update( $group_id, [ 'logo_id' => (int) $attach_id ] );
		if ( $old_id ) {
			wp_delete_attachment( $old_id, true );
		}

		wp_safe_redirect( add_query_arg( 'pp_msg', 'logo_saved', $back ) );
		exit;
	}

	/** URL des Gruppen-Logos oder null (kein Logo gesetzt / Attachment weg). */
	private static function group_logo_url( int $logo_id, string $size = 'thumbnail' ): ?string {
		if ( ! $logo_id ) {
			return null;
		}
		$url = wp_get_attachment_image_url( $logo_id, $size );
		return $url ?: null;
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

	/**
	 * Projekt-Datei hochladen (PDF/Bilder, wie handle_inventory_doc) — eigener
	 * Handler, weil der Kollektiv-Dispatcher kein multipart verarbeitet.
	 * Gate: Projekt im aktiven Gruppen-Workspace (member_owned_project).
	 */
	public static function handle_project_file(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nur Redirect-Ziel; Nonce folgt direkt.
		$pid  = (int) ( $_POST['pp_project'] ?? 0 );
		$back = add_query_arg( [ 'pp_view' => 'projects', 'pp_project' => $pid, 'pp_tab' => 'files' ], self::portal_url() );
		if ( ! is_user_logged_in() || ! isset( $_POST['pp_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pp_nonce'] ), 'pp_project_file' ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'error', $back ) );
			exit;
		}
		if ( ! self::member_owned_project( $pid ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'error', $back ) );
			exit;
		}

		if ( empty( $_FILES['pp_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['pp_file']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'import_nofile', $back ) );
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// PDF + gängige Bildformate zulassen (wie Inventar-Dokumente).
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
		$moved = wp_handle_upload( $_FILES['pp_file'], $overrides );
		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'pfile_failed', $back ) );
			exit;
		}

		$attach_id = wp_insert_attachment( [
			'post_mime_type' => $moved['type'],
			'post_title'     => sanitize_file_name( wp_basename( $moved['file'] ) ),
			'post_status'    => 'inherit',
		], $moved['file'] );
		if ( ! $attach_id || is_wp_error( $attach_id ) ) {
			wp_safe_redirect( add_query_arg( 'pp_msg', 'pfile_failed', $back ) );
			exit;
		}
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( (int) $attach_id, $moved['file'] ) );
		Files::attach( $pid, (int) $attach_id, sanitize_text_field( wp_unslash( (string) ( $_POST['pp_title'] ?? '' ) ) ) );

		wp_safe_redirect( add_query_arg( 'pp_msg', 'pfile_saved', $back ) );
		exit;
	}

	/* ---------- Stöbern & Leihen (Phase 4) ---------- */

	/** Strikte YYYY-MM-DD-Prüfung (für GET-Datums-Filter). */
	private static function is_ymd( string $v ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v );
	}

	/** Aktive Vorgänge (offen/laufend) vs. abgeschlossene (Historie). */
	private const BORROW_ACTIVE = [ 'requested', 'approved' ];
	private const BORROW_CLOSED = [ 'returned', 'declined', 'cancelled' ];

	/**
	 * Leih-Vorgänge für die Anzeige klammern: Die Teil-Zeilen einer SET-Anfrage
	 * (gleiche `bundle_ref`) werden zu EINEM Eintrag zusammengefasst — Titel ist
	 * der Set-Name, die Teile stehen als Unterzeile. Aktionen greifen dadurch
	 * automatisch auf den ganzen Vorgang (Borrowing::siblings).
	 *
	 * @param array<object> $rows
	 * @return array<object> Einträge mit ->pp_set_name und ->pp_lines.
	 */
	private static function group_borrow_rows( array $rows ): array {
		$out = [];
		$idx = [];
		foreach ( $rows as $row ) {
			$ref              = (int) ( $row->bundle_ref ?? 0 );
			$row->pp_lines    = [];
			$row->pp_set_name = '';
			if ( $ref <= 0 ) {
				$out[] = $row;
				continue;
			}
			$part = [
				'name'     => (string) ( $row->item_name ?? '' ),
				'quantity' => max( 1, (int) ( $row->quantity ?? 1 ) ),
			];
			if ( isset( $idx[ $ref ] ) ) {
				$out[ $idx[ $ref ] ]->pp_lines[] = $part;
				continue;
			}
			$set              = Inventory::get_item( (int) ( $row->bundle_item_id ?? 0 ) );
			$row->pp_set_name = $set ? $set->name : (string) $row->item_name;
			$row->pp_lines    = [ $part ];
			$idx[ $ref ]      = count( $out );
			$out[]            = $row;
		}
		return $out;
	}

	/** Titelzeile eines Leih-Eintrags: Set-Name mit Chip, sonst der Artikelname. */
	private static function borrow_title( object $r ): void {
		?>
		<span class="pp-portal__group-name">
			<?php if ( '' !== (string) $r->pp_set_name ) : ?>
				<span class="pp-bundle-chip"><?php esc_html_e( 'Set', 'project-prepper' ); ?></span>
				<?php echo esc_html( $r->pp_set_name ); ?>
				<small class="pp-inv-name-sub">
					<?php
					$bits = [];
					foreach ( (array) $r->pp_lines as $part ) {
						$bits[] = sprintf( '%d× %s', (int) $part['quantity'], (string) $part['name'] );
					}
					echo esc_html( implode( ' · ', $bits ) );
					?>
				</small>
			<?php else : ?>
				<?php echo esc_html( $r->item_name ); ?>
				<?php if ( (int) ( $r->quantity ?? 1 ) > 1 ) : ?>
					<span class="pp-portal__item-meta"><?php echo (int) $r->quantity; ?>×</span>
				<?php endif; ?>
			<?php endif; ?>
		</span>
		<?php
	}

	private static function render_my_borrows( WP_User $user ): void {
		$requests = self::group_borrow_rows( array_filter(
			Borrowing::my_requests( (int) $user->ID ),
			static fn( $r ) => in_array( $r->status, self::BORROW_ACTIVE, true )
		) );
		if ( ! $requests ) {
			return;
		}
		?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php esc_html_e( 'My borrow requests', 'project-prepper' ); ?></h3>
			<?php foreach ( $requests as $r ) : ?>
				<div class="pp-portal__invite">
					<?php self::borrow_title( $r ); ?>
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
		$requests = self::group_borrow_rows( array_filter(
			Borrowing::incoming_requests( (int) $user->ID ),
			static fn( $r ) => in_array( $r->status, self::BORROW_ACTIVE, true )
		) );
		if ( ! $requests ) {
			return;
		}
		?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php esc_html_e( 'Borrow requests for your items', 'project-prepper' ); ?></h3>
			<?php foreach ( $requests as $r ) : ?>
				<div class="pp-portal__invite">
					<?php self::borrow_title( $r ); ?>
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
		$rows = self::group_borrow_rows( $rows );
		?>
		<section class="pp-portal__section">
			<details class="pp-portal__edit">
				<summary class="pp-portal__subtitle pp-history__summary"><?php esc_html_e( 'Borrowing history', 'project-prepper' ); ?> (<?php echo (int) count( $rows ); ?>)</summary>
				<div class="pp-history">
					<?php foreach ( $rows as $r ) : ?>
						<div class="pp-portal__invite">
							<?php self::borrow_title( $r ); ?>
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
