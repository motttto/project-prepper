<?php
namespace ProjectPrepper\Frontend;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Security;
use ProjectPrepper\Services\Groups;
use ProjectPrepper\Services\GroupGovernance as Governance;
use ProjectPrepper\Services\Inventory;
use ProjectPrepper\Services\MemberInventory;
use ProjectPrepper\Services\Borrowing;
use ProjectPrepper\Services\Projects;
use ProjectPrepper\Services\Schedule;
use ProjectPrepper\Services\Decisions;
use ProjectPrepper\Services\Polls;
use ProjectPrepper\Services\FederatedBorrow;
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

		// Offene E-Mail-Einladungen beim Registrieren verknüpfen.
		add_action( 'user_register', [ Governance::class, 'link_user_on_register' ] );

		// Vollbild-App-Shell: die Portal-Seite bekommt ein eigenes Template
		// (theme-unabhängig, Sidebar + Topbar wie die Next.js-App).
		add_filter( 'template_include', [ self::class, 'portal_template' ], 99 );
	}

	public static function register_assets(): void {
		// Portal nutzt das gemeinsame Frontend-Stylesheet (enthält die .pp-portal-Regeln).
		wp_register_style( 'pp-frontend', PP_PLUGIN_URL . 'assets/css/frontend.css', [], PP_VERSION );
		// Kleine progressive Erweiterung (z. B. „+ Option" beim Umfrage-Anlegen).
		wp_register_script( 'pp-portal', PP_PLUGIN_URL . 'assets/js/portal.js', [], PP_VERSION, true );

		// Auf der Portal-Seite das Stylesheet hier einreihen — das Vollbild-Template
		// rendert erst nach wp_head(), ein späteres enqueue käme zu spät.
		if ( self::is_portal_page() ) {
			wp_enqueue_style( 'pp-frontend' );
			wp_enqueue_script( 'pp-portal' );
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
		if ( in_array( $do, [ 'fedborrow_approve', 'fedborrow_decline' ], true ) ) {
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
			} else {
				$msg = 'error';
			}
		} else {
			$msg = $ok_msg;
		}
		wp_safe_redirect( add_query_arg( 'pp_msg', $msg, $back ) );
		exit;
	}

	/** Statusmeldungen für ?pp_msg — Code → menschenlesbarer Text. */
	private static function messages(): array {
		return [
			'founded'   => [ 'ok', __( 'Collective founded. You are its founder.', 'project-prepper' ) ],
			'invited'   => [ 'ok', __( 'Invitation sent.', 'project-prepper' ) ],
			'accepted'  => [ 'ok', __( 'Invitation accepted.', 'project-prepper' ) ],
			'declined'  => [ 'ok', __( 'Invitation declined.', 'project-prepper' ) ],
			'cancelled' => [ 'ok', __( 'Invitation cancelled.', 'project-prepper' ) ],
			'voted'         => [ 'ok', __( 'Your vote was recorded.', 'project-prepper' ) ],
			'item_saved'    => [ 'ok', __( 'Item saved.', 'project-prepper' ) ],
			'item_deleted'  => [ 'ok', __( 'Item deleted.', 'project-prepper' ) ],
			'item_shared'   => [ 'ok', __( 'Item shared with the collective.', 'project-prepper' ) ],
			'item_unshared'    => [ 'ok', __( 'Item is no longer shared.', 'project-prepper' ) ],
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
		return self::render_app();
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
							case 'calendar':
								self::view_calendar( $user, $groups );
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
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** Erlaubte Views — Default Dashboard. */
	private static function current_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Navigation
		$view    = isset( $_GET['pp_view'] ) ? sanitize_key( wp_unslash( $_GET['pp_view'] ) ) : 'dashboard';
		$allowed = [ 'dashboard', 'inventory', 'lending', 'projects', 'calendar', 'polls', 'network', 'collectives' ];
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
			[ 'view' => 'calendar',  'icon' => 'calendar',  'label' => __( 'Calendar', 'project-prepper' ) ],
		];
		// Eigenständige Umfragen NUR im Gruppen-Modus (wie die App).
		if ( ! $solo ) {
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
				<div class="pp-app__user">
					<span class="pp-app__avatar"><?php echo esc_html( self::initials( $user->display_name ) ); ?></span>
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
			'admin'     => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
			'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
			'info'      => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
		];
		$path = $icons[ $name ] ?? '';
		echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- statisches Icon-Markup.
	}

	/* ---------- Views ---------- */

	private static function view_dashboard( WP_User $user, array $groups ): void {
		$inv_count  = count( MemberInventory::my_items( (int) $user->ID ) );
		$grp_count  = count( $groups );
		$proj_count = count( self::member_projects( $groups ) );
		$incoming   = Borrowing::incoming_requests( (int) $user->ID );
		$open_reqs  = count( array_filter( $incoming, static fn( $r ) => 'requested' === $r->status ) );
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
			self::kpi_card( 'collectives', $grp_count, __( 'Collectives', 'project-prepper' ), 'info', 'users' );
			self::kpi_card( 'lending', $open_reqs, __( 'Open borrow requests', 'project-prepper' ), 'success', 'package' );
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
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title"><?php esc_html_e( 'My inventory', 'project-prepper' ); ?></h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Your personal equipment — share items with your collectives.', 'project-prepper' ); ?></p>
		</header>
		<?php
		self::render_my_inventory( $user, $groups, false );
	}

	private static function view_lending( WP_User $user, array $groups ): void {
		?>
		<header class="pp-app__page-head">
			<h1 class="pp-app__page-title"><?php esc_html_e( 'Borrowing & lending', 'project-prepper' ); ?></h1>
			<p class="pp-app__page-sub"><?php esc_html_e( 'Browse what your collectives share, request items and manage requests for your own.', 'project-prepper' ); ?></p>
		</header>
		<?php
		$fed_incoming = FederatedBorrow::inbound_for_owner( (int) $user->ID );
		if ( ! $groups
			&& ! Borrowing::my_requests( (int) $user->ID )
			&& ! Borrowing::incoming_requests( (int) $user->ID )
			&& ! $fed_incoming ) {
			echo '<p class="pp-portal__empty">' . esc_html__( 'Join a collective to browse and borrow shared equipment.', 'project-prepper' ) . '</p>';
			return;
		}
		// Stöbern ist auf den aktiven Workspace begrenzt (Solo → kein Stöbern).
		$active        = self::active_group_id( $groups );
		$active_groups = array_values( array_filter( $groups, static fn( $g ) => (int) $g->id === $active ) );
		self::render_browse( $user, $active_groups );
		self::render_my_borrows( $user );
		self::render_incoming_borrows( $user );
		self::render_incoming_fed_borrows( $fed_incoming );
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
		<?php endif;
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

		// 10) Beschlüsse + 11) Umfragen — interaktiv (Voting), nur Gruppenmitglieder.
		self::render_decisions( $p );
		self::render_polls( $p );
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

		<details class="pp-portal__add">
			<summary class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'New poll', 'project-prepper' ); ?></summary>
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
		</details>
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

	/* ---------- Kalender (read-only Monatsraster) ---------- */

	private static function view_calendar( WP_User $user, array $groups ): void {
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

			<div class="pp-cal__legend">
				<span class="pp-cal__legend-item"><span class="pp-cal__dot pp-cal__dot--project"></span><?php esc_html_e( 'Project', 'project-prepper' ); ?></span>
				<span class="pp-cal__legend-item"><span class="pp-cal__dot pp-cal__dot--schedule"></span><?php esc_html_e( 'Schedule', 'project-prepper' ); ?></span>
				<span class="pp-cal__legend-item"><span class="pp-cal__dot pp-cal__dot--borrow"></span><?php esc_html_e( 'Loan', 'project-prepper' ); ?></span>
			</div>
		</div>
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

			<p class="pp-portal__members">
				<?php
				$names = array_map( static fn( $m ) => $m->display_name, $members );
				echo esc_html( implode( ', ', $names ) );
				?>
			</p>

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
		$all_items = MemberInventory::my_items( (int) $user->ID, $q );
		$per_page  = 12;
		$total     = count( $all_items );
		$pages     = max( 1, (int) ceil( $total / $per_page ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Navigation
		$page      = isset( $_GET['pp_p'] ) ? max( 1, min( $pages, (int) $_GET['pp_p'] ) ) : 1;
		$items     = array_slice( $all_items, ( $page - 1 ) * $per_page, $per_page );
		$categories = Inventory::categories();
		$conditions = Shortcodes::condition_labels();
		?>
		<section class="pp-portal__section">
			<?php if ( $heading ) : ?>
				<h3 class="pp-portal__subtitle"><?php esc_html_e( 'My inventory', 'project-prepper' ); ?></h3>
			<?php endif; ?>

			<?php self::render_inventory_tools(); ?>

			<?php if ( $all_items || '' !== $q ) : ?>
				<form class="pp-inv-search" method="get">
					<input type="hidden" name="pp_view" value="inventory">
					<input type="search" name="pp_q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Search your inventory …', 'project-prepper' ); ?>">
					<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Search', 'project-prepper' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( $items ) : ?>
				<?php foreach ( $items as $item ) : ?>
					<div class="pp-portal__item">
						<div class="pp-portal__item-head">
							<?php if ( ! empty( $item->image_url ) ) : ?>
								<img class="pp-portal__item-thumb" src="<?php echo esc_url( $item->image_url ); ?>" alt="" loading="lazy">
							<?php endif; ?>
							<span class="pp-portal__group-name"><?php echo esc_html( $item->name ); ?></span>
							<span class="pp-portal__item-num"><?php echo esc_html( $item->inventory_number ); ?></span>
							<span class="pp-portal__item-meta">
								<?php
								echo esc_html( $conditions[ $item->condition ] ?? $item->condition );
								echo ' · ';
								/* translators: %d: quantity. */
								printf( esc_html__( 'Qty %d', 'project-prepper' ), (int) $item->quantity );
								if ( null !== $item->cost_per_day && '' !== $item->cost_per_day ) {
									echo ' · ' . esc_html( number_format_i18n( (float) $item->cost_per_day, 2 ) ) . ' €';
								}
								?>
							</span>
						</div>

						<?php if ( $groups ) : ?>
							<?php $shared = MemberInventory::shared_group_ids( (int) $item->id ); ?>
							<div class="pp-portal__share-row">
								<span class="pp-portal__share-label"><?php esc_html_e( 'Shared with:', 'project-prepper' ); ?></span>
								<?php foreach ( $groups as $g ) :
									$is_shared = in_array( (int) $g->id, $shared, true ); ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php self::action_fields( $is_shared ? 'item_unshare' : 'item_share' ); ?>
										<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
										<input type="hidden" name="pp_group" value="<?php echo (int) $g->id; ?>">
										<button type="submit" class="pp-portal__chip <?php echo $is_shared ? 'pp-portal__chip--on' : ''; ?>">
											<?php echo esc_html( $g->name ); ?><?php echo $is_shared ? ' ✕' : ' +'; ?>
										</button>
									</form>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<div class="pp-portal__actions">
							<details class="pp-portal__edit">
								<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Edit', 'project-prepper' ); ?></summary>
								<?php self::item_form( 'item_update', $categories, $conditions, $item ); ?>
							</details>
							<details class="pp-portal__edit">
								<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Photo', 'project-prepper' ); ?></summary>
								<form class="pp-portal__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="pp_member_photo">
									<?php wp_nonce_field( 'pp_member_photo', 'pp_nonce' ); ?>
									<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
									<label><?php esc_html_e( 'Image file', 'project-prepper' ); ?>
										<input type="file" name="pp_photo" accept="image/*" required>
									</label>
									<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save photo', 'project-prepper' ); ?></button>
								</form>
								<?php if ( ! empty( $item->image_url ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.4rem;">
										<input type="hidden" name="action" value="pp_member_photo">
										<?php wp_nonce_field( 'pp_member_photo', 'pp_nonce' ); ?>
										<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
										<input type="hidden" name="pp_remove" value="1">
										<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Remove photo', 'project-prepper' ); ?></button>
									</form>
								<?php endif; ?>
							</details>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this item?', 'project-prepper' ) ); ?>');">
								<?php self::action_fields( 'item_delete' ); ?>
								<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
								<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Delete', 'project-prepper' ); ?></button>
							</form>
						</div>
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

			<details class="pp-portal__add">
				<summary class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Add item', 'project-prepper' ); ?></summary>
				<?php self::item_form( 'item_create', $categories, $conditions, null ); ?>
			</details>
		</section>
		<?php
	}

	/**
	 * Formular zum Anlegen/Bearbeiten eines eigenen Items.
	 *
	 * @param array<object> $categories
	 * @param array<string,string> $conditions
	 */
	private static function item_form( string $do, array $categories, array $conditions, ?object $item ): void {
		$val = static fn( string $field, $default = '' ) => $item && isset( $item->$field ) ? $item->$field : $default;
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
					<?php foreach ( $categories as $cat ) : ?>
						<option value="<?php echo (int) $cat->id; ?>" <?php selected( (int) $val( 'category_id', 0 ), (int) $cat->id ); ?>><?php echo esc_html( $cat->name ); ?></option>
					<?php endforeach; ?>
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
	private static function render_inventory_tools(): void {
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=pp_member_export' ), 'pp_member_export', 'pp_nonce' );
		?>
		<div class="pp-inv-tools">
			<a class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export (CSV)', 'project-prepper' ); ?></a>
			<details class="pp-portal__add">
				<summary class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Import (CSV)', 'project-prepper' ); ?></summary>
				<form class="pp-portal__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pp_member_import">
					<?php wp_nonce_field( 'pp_member_import', 'pp_nonce' ); ?>
					<label><?php esc_html_e( 'CSV file', 'project-prepper' ); ?>
						<input type="file" name="pp_file" accept=".csv,text/csv" required>
					</label>
					<p class="pp-poll-opthint"><?php esc_html_e( 'Use the same columns as the export (semicolon-separated). Each row becomes one of your items; the “Name” column is required.', 'project-prepper' ); ?></p>
					<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Import', 'project-prepper' ); ?></button>
				</form>
			</details>
		</div>
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
		// Kategorie-Namen → ID (nur vorhandene; keine Auto-Anlage durch Mitglieder).
		$cat_map = [];
		foreach ( Inventory::categories() as $cat ) {
			$cat_map[ self::norm_head( $cat->name ) ] = (int) $cat->id;
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
			'name'           => sanitize_text_field( $d['name'] ?? '' ),
			'description'    => sanitize_textarea_field( $d['description'] ?? '' ),
			'manufacturer'   => sanitize_text_field( $d['manufacturer'] ?? '' ),
			'model'          => sanitize_text_field( $d['model'] ?? '' ),
			'serial_number'  => sanitize_text_field( $d['serial_number'] ?? '' ),
			'location'       => sanitize_text_field( $d['location'] ?? '' ),
			'dimensions'     => sanitize_text_field( $d['dimensions'] ?? '' ),
			'accessories'    => sanitize_text_field( $d['accessories'] ?? '' ),
			'notes'          => sanitize_textarea_field( $d['notes'] ?? '' ),
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

	/* ---------- Stöbern & Leihen (Phase 4) ---------- */

	/** @param array<object> $groups */
	private static function render_browse( WP_User $user, array $groups ): void {
		if ( ! $groups ) {
			return;
		}
		$conditions = Shortcodes::condition_labels();
		$any        = false;
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
								$pp_today = current_time( 'Y-m-d' );
								$pp_avail = Borrowing::available_units( (int) $item->id, $pp_today, $pp_today );
								echo ' · ';
								/* translators: 1: free units today, 2: total quantity. */
								printf( esc_html__( '%1$d of %2$d free today', 'project-prepper' ), (int) $pp_avail, (int) $item->quantity );
								?>
							</span>
						</div>
						<?php if ( $is_mine ) : ?>
							<span class="pp-portal__tag pp-portal__tag--muted"><?php esc_html_e( 'Your item', 'project-prepper' ); ?></span>
						<?php else : ?>
							<details class="pp-portal__edit">
								<summary class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Borrow', 'project-prepper' ); ?></summary>
								<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php self::action_fields( 'borrow_request' ); ?>
									<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
									<input type="hidden" name="pp_group" value="<?php echo (int) $group->id; ?>">
									<label><?php esc_html_e( 'From', 'project-prepper' ); ?>
										<input type="date" name="pp_from" required>
									</label>
									<label><?php esc_html_e( 'To', 'project-prepper' ); ?>
										<input type="date" name="pp_to" required>
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
			<?php
		}
		$html = (string) ob_get_clean();
		if ( ! $any ) {
			return;
		}
		?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php esc_html_e( 'Available in your collectives', 'project-prepper' ); ?></h3>
			<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intern erzeugtes, bereits escaptes Markup. ?>
		</section>
		<?php
	}

	private static function render_my_borrows( WP_User $user ): void {
		$requests = Borrowing::my_requests( (int) $user->ID );
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
		$requests = Borrowing::incoming_requests( (int) $user->ID );
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
