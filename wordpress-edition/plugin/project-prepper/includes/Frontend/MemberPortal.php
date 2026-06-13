<?php
namespace ProjectPrepper\Frontend;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\Groups;
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

			<?php if ( self::has_backend_access( $user ) ) : ?>
				<div class="pp-portal__banner">
					<span><?php esc_html_e( 'You have management access to this platform.', 'project-prepper' ); ?></span>
					<a class="pp-portal__btn pp-portal__btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=project-prepper' ) ); ?>"><?php esc_html_e( 'Open admin area', 'project-prepper' ); ?></a>
				</div>
			<?php endif; ?>

			<section class="pp-portal__section">
				<h3 class="pp-portal__subtitle"><?php esc_html_e( 'Your collectives', 'project-prepper' ); ?></h3>
				<?php if ( $groups ) : ?>
					<ul class="pp-portal__groups">
						<?php foreach ( $groups as $group ) : ?>
							<li class="pp-portal__group">
								<span class="pp-portal__group-name"><?php echo esc_html( $group->name ); ?></span>
								<?php if ( 'founder' === $group->member_role ) : ?>
									<span class="pp-portal__tag"><?php esc_html_e( 'Founder', 'project-prepper' ); ?></span>
								<?php else : ?>
									<span class="pp-portal__tag pp-portal__tag--muted"><?php esc_html_e( 'Member', 'project-prepper' ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="pp-portal__empty"><?php esc_html_e( 'You are not part of any collective yet. Found one or join an existing collective to start sharing inventory.', 'project-prepper' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="pp-portal__section">
				<h3 class="pp-portal__subtitle"><?php esc_html_e( 'What you can do here', 'project-prepper' ); ?></h3>
				<div class="pp-portal__tiles">
					<?php
					self::tile(
						__( 'Found a collective', 'project-prepper' ),
						__( 'Start your own collective and invite others to share resources.', 'project-prepper' )
					);
					self::tile(
						__( 'Join a collective', 'project-prepper' ),
						__( 'Get invited to an existing collective — the members decide together.', 'project-prepper' )
					);
					self::tile(
						__( 'My inventory', 'project-prepper' ),
						__( 'Add your own equipment and share it with your collectives.', 'project-prepper' )
					);
					?>
				</div>
			</section>

			<footer class="pp-portal__footer">
				<a class="pp-portal__btn pp-portal__btn--ghost" href="<?php echo esc_url( wp_logout_url( self::portal_url() ) ); ?>"><?php esc_html_e( 'Sign out', 'project-prepper' ); ?></a>
			</footer>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** Eine „Coming soon"-Kachel — ehrlich als in Vorbereitung markiert (Phase 2-3). */
	private static function tile( string $title, string $desc ): void {
		?>
		<div class="pp-portal__tile pp-portal__tile--soon">
			<span class="pp-portal__tile-title"><?php echo esc_html( $title ); ?></span>
			<span class="pp-portal__tile-desc"><?php echo esc_html( $desc ); ?></span>
			<span class="pp-portal__tile-soon"><?php esc_html_e( 'Coming soon', 'project-prepper' ); ?></span>
		</div>
		<?php
	}
}
