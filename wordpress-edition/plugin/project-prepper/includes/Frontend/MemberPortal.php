<?php
namespace ProjectPrepper\Frontend;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\Groups;
use ProjectPrepper\Services\GroupGovernance as Governance;
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
			'voted'     => [ 'ok', __( 'Your vote was recorded.', 'project-prepper' ) ],
			'error'     => [ 'err', __( 'Something went wrong. Please try again.', 'project-prepper' ) ],
		];
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

			<section class="pp-portal__section">
				<div class="pp-portal__tiles">
					<div class="pp-portal__tile pp-portal__tile--soon">
						<span class="pp-portal__tile-title"><?php esc_html_e( 'My inventory', 'project-prepper' ); ?></span>
						<span class="pp-portal__tile-desc"><?php esc_html_e( 'Add your own equipment and share it with your collectives.', 'project-prepper' ); ?></span>
						<span class="pp-portal__tile-soon"><?php esc_html_e( 'Coming soon', 'project-prepper' ); ?></span>
					</div>
				</div>
			</section>

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
}
