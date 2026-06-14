<?php
namespace ProjectPrepper;

use ProjectPrepper\Services\Groups;

defined( 'ABSPATH' ) || exit;

/**
 * Föderation — Slice 1 (Fernziel aus docs/05): macht diese Instanz für andere
 * wp-prepper-Instanzen **auffindbar** (Opt-in). Andere Instanzen lesen das
 * öffentliche Profil über den Discovery-Endpoint und können nach Postleitzahl /
 * Thema eingrenzen.
 *
 * **Opt-in, per Default AUS.** Solange aus, liefert der Endpoint 404 und es wird
 * nichts veröffentlicht. Das Profil enthält bewusst nur grobe, nicht-personen-
 * bezogene Eckdaten (Name, PLZ, Thema, Anzahl Kollektive/Mitglieder).
 *
 * Instanzübergreifendes Stöbern/Leihen ist ein späterer Slice.
 */
class Federation {

	const OPTION = 'pp_federation';

	public static function defaults(): array {
		return [
			'enabled'       => false,
			'postal_code'   => '',
			'topic'         => '',
			'contact_email' => '',
		];
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	public static function enabled(): bool {
		return (bool) self::all()['enabled'];
	}

	/**
	 * Öffentliches Profil für den Discovery-Endpoint — nur grobe Eckdaten.
	 */
	public static function public_profile(): array {
		$cfg = self::all();
		return [
			'name'        => get_bloginfo( 'name' ),
			'url'         => home_url( '/' ),
			'postal_code' => (string) $cfg['postal_code'],
			'topic'       => (string) $cfg['topic'],
			'collectives' => count( Groups::all() ),
			'members'     => self::member_count(),
			'contact'     => (string) $cfg['contact_email'],
		];
	}

	private static function member_count(): int {
		$q = new \WP_User_Query( [ 'role' => 'pp_member', 'number' => 0, 'count_total' => true, 'fields' => 'ID' ] );
		return (int) $q->get_total();
	}

	/* ===================== Admin-Formular ===================== */

	public static function init(): void {
		add_action( 'admin_post_pp_save_federation', [ self::class, 'handle_save' ] );
	}

	public static function handle_save(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ||
			! isset( $_POST['pp_fed_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['pp_fed_nonce'] ), 'pp_save_federation' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'project-prepper' ) );
		}

		update_option( self::OPTION, [
			'enabled'       => ! empty( $_POST['enabled'] ),
			'postal_code'   => sanitize_text_field( wp_unslash( (string) ( $_POST['postal_code'] ?? '' ) ) ),
			'topic'         => sanitize_text_field( wp_unslash( (string) ( $_POST['topic'] ?? '' ) ) ),
			'contact_email' => sanitize_email( wp_unslash( (string) ( $_POST['contact_email'] ?? '' ) ) ),
		] );

		wp_safe_redirect( add_query_arg( 'pp_fed', 'saved', admin_url( 'admin.php?page=pp-federation' ) ) );
		exit;
	}
}
