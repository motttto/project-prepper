<?php
namespace ProjectPrepper\Frontend;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\Groups;
use ProjectPrepper\Services\GroupGovernance as Governance;
use ProjectPrepper\Services\Inventory;
use ProjectPrepper\Services\MemberInventory;
use ProjectPrepper\Services\Borrowing;
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

		// Offene E-Mail-Einladungen beim Registrieren verknüpfen.
		add_action( 'user_register', [ Governance::class, 'link_user_on_register' ] );
	}

	public static function register_assets(): void {
		// Portal nutzt das gemeinsame Frontend-Stylesheet (enthält die .pp-portal-Regeln).
		wp_register_style( 'pp-frontend', PP_PLUGIN_URL . 'assets/css/frontend.css', [], PP_VERSION );
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
		$back = self::portal_url();

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
		}

		$msg = is_wp_error( $result ) ? 'error' : $ok_msg;
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
			'error'            => [ 'err', __( 'Something went wrong. Please try again.', 'project-prepper' ) ],
		];
	}

	/** Sanitisierte Item-Felder aus dem Inventar-Formular (Nonce bereits geprüft). */
	private static function item_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird im Dispatcher geprüft.
		return [
			'name'         => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) ),
			'category_id'  => (int) ( $_POST['pp_category'] ?? 0 ),
			'quantity'     => max( 1, (int) ( $_POST['pp_quantity'] ?? 1 ) ),
			'condition'    => sanitize_key( wp_unslash( (string) ( $_POST['pp_condition'] ?? 'good' ) ) ),
			'cost_per_day' => '' !== ( $_POST['pp_cost'] ?? '' ) ? (float) $_POST['pp_cost'] : '',
			'description'  => sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_description'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/* ===================== Rendering ===================== */

	public static function render(): string {
		wp_enqueue_style( 'pp-frontend' );

		if ( ! is_user_logged_in() ) {
			return self::render_login();
		}
		return self::render_member();
	}

	private static function render_login(): string {
		ob_start();
		?>
		<div class="pp-front pp-portal pp-portal--login">
			<h2 class="pp-portal__title"><?php esc_html_e( 'Member login', 'project-prepper' ); ?></h2>
			<p class="pp-portal__lead"><?php esc_html_e( 'Sign in to manage your inventory, your collectives and shared resources.', 'project-prepper' ); ?></p>
			<?php
			wp_login_form( [
				'redirect'       => self::portal_url(),
				'label_username' => __( 'Email or username', 'project-prepper' ),
				'label_password' => __( 'Password', 'project-prepper' ),
				'label_log_in'   => __( 'Sign in', 'project-prepper' ),
				'remember'       => true,
			] );
			?>
			<p class="pp-portal__note">
				<?php esc_html_e( 'Access is by invitation only. Ask the platform operators to set up an account for you.', 'project-prepper' ); ?>
				<br>
				<a href="<?php echo esc_url( wp_lostpassword_url( self::portal_url() ) ); ?>"><?php esc_html_e( 'Forgot your password?', 'project-prepper' ); ?></a>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_member(): string {
		$user   = wp_get_current_user();
		$groups = Groups::user_groups( (int) $user->ID );

		ob_start();
		?>
		<div class="pp-front pp-portal">
			<header class="pp-portal__header">
				<h2 class="pp-portal__title">
					<?php
					/* translators: %s: member display name. */
					printf( esc_html__( 'Hello %s', 'project-prepper' ), esc_html( $user->display_name ) );
					?>
				</h2>
				<p class="pp-portal__lead"><?php esc_html_e( 'Welcome to your collective platform.', 'project-prepper' ); ?></p>
			</header>

			<?php
			self::render_message();

			if ( self::has_backend_access( $user ) ) :
				?>
				<div class="pp-portal__banner">
					<span><?php esc_html_e( 'You have management access to this platform.', 'project-prepper' ); ?></span>
					<a class="pp-portal__btn pp-portal__btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=project-prepper' ) ); ?>"><?php esc_html_e( 'Open admin area', 'project-prepper' ); ?></a>
				</div>
			<?php endif; ?>

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

			<?php self::render_my_inventory( $user, $groups ); ?>

			<?php self::render_browse( $user, $groups ); ?>

			<?php self::render_my_borrows( $user ); ?>

			<?php self::render_incoming_borrows( $user ); ?>

			<footer class="pp-portal__footer">
				<a class="pp-portal__btn pp-portal__btn--ghost" href="<?php echo esc_url( wp_logout_url( self::portal_url() ) ); ?>"><?php esc_html_e( 'Sign out', 'project-prepper' ); ?></a>
			</footer>
		</div>
		<?php
		return (string) ob_get_clean();
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
	private static function render_my_inventory( WP_User $user, array $groups ): void {
		$items      = MemberInventory::my_items( (int) $user->ID );
		$categories = Inventory::categories();
		$conditions = Shortcodes::condition_labels();
		?>
		<section class="pp-portal__section">
			<h3 class="pp-portal__subtitle"><?php esc_html_e( 'My inventory', 'project-prepper' ); ?></h3>

			<?php if ( $items ) : ?>
				<?php foreach ( $items as $item ) : ?>
					<div class="pp-portal__item">
						<div class="pp-portal__item-head">
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
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this item?', 'project-prepper' ) ); ?>');">
								<?php self::action_fields( 'item_delete' ); ?>
								<input type="hidden" name="pp_item" value="<?php echo (int) $item->id; ?>">
								<button type="submit" class="pp-portal__btn pp-portal__btn--ghost pp-portal__btn--sm"><?php esc_html_e( 'Delete', 'project-prepper' ); ?></button>
							</form>
						</div>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<p class="pp-portal__empty"><?php esc_html_e( 'You have no personal inventory yet. Add your first item below.', 'project-prepper' ); ?></p>
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
			<label><?php esc_html_e( 'Description (optional)', 'project-prepper' ); ?>
				<textarea name="pp_description" rows="2"><?php echo esc_textarea( (string) $val( 'description' ) ); ?></textarea>
			</label>
			<button type="submit" class="pp-portal__btn pp-portal__btn--sm"><?php esc_html_e( 'Save item', 'project-prepper' ); ?></button>
		</form>
		<?php
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
