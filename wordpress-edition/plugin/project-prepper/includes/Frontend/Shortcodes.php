<?php
namespace ProjectPrepper\Frontend;

use ProjectPrepper\Services\Availability;
use ProjectPrepper\Services\Inquiries;
use ProjectPrepper\Services\Inventory;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend-Shortcodes (Server-Rendering über die Services — KEINE Admin-REST-Calls).
 *
 *   [pp_inventory category="licht" show_rates="yes" search="yes"]
 *   [pp_availability item="3"]
 *   [pp_request_form show_items="yes"]
 *
 * Öffentlich sichtbar sind nur unkritische Felder (kein Kaufpreis, keine
 * Seriennummer, keine Leiher-Daten) — das ist der Capability-Ersatz fürs Frontend.
 * Defekte/ausgemusterte Artikel (broken/retired) sind standardmäßig ausgeblendet;
 * show_all="yes" übersteuert das pro Shortcode/Block.
 */
class Shortcodes {

	/**
	 * Öffentliche Zustands-Labels — Methode statt const, damit __() greift.
	 */
	public static function condition_labels(): array {
		return [
			'new'         => __( 'New', 'project-prepper' ),
			'good'        => __( 'Good', 'project-prepper' ),
			'fair'        => __( 'Used', 'project-prepper' ),
			'poor'        => __( 'Poor', 'project-prepper' ),
			// Betriebszustände (v0.134.0): sperren den Artikel, siehe
			// Inventory::BLOCKED_CONDITIONS.
			'maintenance' => __( 'In maintenance', 'project-prepper' ),
			'broken'      => __( 'Broken', 'project-prepper' ),
			'lost'        => __( 'Missing', 'project-prepper' ),
			'retired'     => __( 'Retired', 'project-prepper' ),
		];
	}

	public static function init(): void {
		add_shortcode( 'pp_inventory', [ self::class, 'inventory' ] );
		add_shortcode( 'pp_availability', [ self::class, 'availability' ] );
		add_shortcode( 'pp_request_form', [ self::class, 'request_form' ] );

		// Formular-Submit (eingeloggt + öffentlich).
		add_action( 'admin_post_pp_inquiry', [ self::class, 'handle_inquiry_submit' ] );
		add_action( 'admin_post_nopriv_pp_inquiry', [ self::class, 'handle_inquiry_submit' ] );

		add_action( 'wp_enqueue_scripts', [ self::class, 'register_assets' ] );
	}

	public static function register_assets(): void {
		wp_register_style( 'pp-frontend', PP_PLUGIN_URL . 'assets/css/frontend.css', [], PP_VERSION );
		// Live-Suche der öffentlichen Inventarliste (gemeinsames Script mit dem Portal).
		wp_register_script( 'pp-live-search', PP_PLUGIN_URL . 'assets/js/live-search.js', [], PP_VERSION, true );
	}

	/**
	 * Template laden — im Theme überschreibbar via {theme}/project-prepper/{file}.
	 */
	public static function render_template( string $file, array $vars = [] ): string {
		$theme_template = locate_template( 'project-prepper/' . $file );
		$template       = $theme_template ?: PP_PLUGIN_DIR . 'templates/' . $file;
		if ( ! file_exists( $template ) ) {
			return '';
		}
		wp_enqueue_style( 'pp-frontend' );
		wp_enqueue_script( 'pp-live-search' );
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		ob_start();
		include $template;
		return (string) ob_get_clean();
	}

	/* ---------- [pp_inventory] ---------- */

	public static function inventory( $atts ): string {
		$atts = shortcode_atts( [
			'category'   => '',
			'show_rates' => 'no',
			'search'     => 'no',
			'show_all'   => 'no',
		], $atts, 'pp_inventory' );

		$category_id = 0;
		if ( '' !== $atts['category'] ) {
			foreach ( Inventory::template_categories() as $cat ) {
				if ( 0 === strcasecmp( $cat->name, $atts['category'] ) || 0 === strcasecmp( $cat->prefix, $atts['category'] ) ) {
					$category_id = (int) $cat->id;
					break;
				}
			}
		}

		$search = 'yes' === $atts['search'] ? sanitize_text_field( wp_unslash( (string) ( $_GET['pp_q'] ?? '' ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$items = Inventory::items( array_filter( [
			'category_id' => $category_id,
			'search'      => $search,
			'usable_only' => 'yes' !== $atts['show_all'],
		] ) );

		return self::render_template( 'inventory-list.php', [
			'items'            => array_map( [ self::class, 'public_item' ], $items ),
			'show_rates'       => 'yes' === $atts['show_rates'],
			'show_search'      => 'yes' === $atts['search'],
			'search_value'     => $search,
			'condition_labels' => self::condition_labels(),
		] );
	}

	/**
	 * Öffentliche Sicht auf einen Artikel — Whitelist statt Vollobjekt.
	 * Public, damit die Föderation (Federation::public_inventory) dieselbe
	 * Whitelist als einzige Quelle nutzt (kein Daten-Leak, keine Dublette).
	 */
	public static function public_item( object $item ): array {
		return [
			'id'            => (int) $item->id,
			'name'          => $item->name,
			'description'   => $item->description,
			'category_name' => $item->category_name,
			'category_icon' => $item->category_icon,
			'condition'     => $item->condition,
			'quantity'      => (int) $item->quantity,
			'cost_per_day'  => $item->cost_per_day,
			'image_url'     => $item->image_url,
			'tags'          => (array) $item->tags,
			'detail_url'    => ItemDetail::url( $item->inventory_number ),
		];
	}

	/* ---------- [pp_availability] ---------- */

	public static function availability( $atts ): string {
		$atts = shortcode_atts( [
			'item'     => '',
			'show_all' => 'no',
		], $atts, 'pp_availability' );

		// phpcs:disable WordPress.Security.NonceVerification -- reines Lese-Formular (GET)
		$item_id = (int) ( $atts['item'] ?: absint( wp_unslash( $_GET['pp_item'] ?? 0 ) ) );
		$from    = sanitize_text_field( wp_unslash( (string) ( $_GET['pp_from'] ?? '' ) ) );
		$to      = sanitize_text_field( wp_unslash( (string) ( $_GET['pp_to'] ?? '' ) ) );
		// phpcs:enable

		$result = null;
		if ( $item_id && Availability::is_valid_range( $from, $to ) ) {
			$item = Inventory::get_item( $item_id );
			if ( $item ) {
				$result = [
					'item_name' => $item->name,
					'available' => Availability::available_quantity( $item_id, $from, $to ),
				];
			}
		}

		return self::render_template( 'availability.php', [
			'fixed_item' => (bool) $atts['item'],
			'item_id'    => $item_id,
			'items'      => $atts['item'] ? [] : array_map(
				[ self::class, 'public_item' ],
				Inventory::items( 'yes' === $atts['show_all'] ? [] : [ 'usable_only' => true ] )
			),
			'from'       => $from,
			'to'         => $to,
			'result'     => $result,
		] );
	}

	/* ---------- [pp_request_form] ---------- */

	public static function request_form( $atts ): string {
		$atts = shortcode_atts( [
			'show_items' => 'yes',
			'show_all'   => 'no',
		], $atts, 'pp_request_form' );

		$state = sanitize_text_field( wp_unslash( (string) ( $_GET['pp_inquiry'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification

		return self::render_template( 'request-form.php', [
			'items'   => 'yes' === $atts['show_items'] ? array_map(
				[ self::class, 'public_item' ],
				Inventory::items( 'yes' === $atts['show_all'] ? [] : [ 'usable_only' => true ] )
			) : [],
			'success' => 'ok' === $state,
			'error'   => 'error' === $state,
		] );
	}

	public static function handle_inquiry_submit(): void {
		$back = wp_get_referer() ?: home_url();

		// Nonce + Honeypot (Spam-Schutz).
		$nonce_ok = isset( $_POST['pp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['pp_nonce'] ), 'pp_inquiry' );
		$honeypot = ! empty( $_POST['pp_website'] );
		if ( ! $nonce_ok || $honeypot ) {
			wp_safe_redirect( add_query_arg( 'pp_inquiry', 'error', $back ) );
			exit;
		}

		$items     = [];
		$raw_items = array_map( 'intval', (array) ( $_POST['pp_items'] ?? [] ) );
		foreach ( $raw_items as $item_id ) {
			$item = Inventory::get_item( $item_id );
			if ( $item ) {
				$items[] = [ 'item_id' => $item_id, 'quantity' => 1, 'name' => $item->name ];
			}
		}

		$result = Inquiries::create( [
			'name'      => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_name'] ?? '' ) ) ),
			'email'     => sanitize_email( wp_unslash( (string) ( $_POST['pp_email'] ?? '' ) ) ),
			'phone'     => sanitize_text_field( wp_unslash( (string) ( $_POST['pp_phone'] ?? '' ) ) ),
			'message'   => sanitize_textarea_field( wp_unslash( (string) ( $_POST['pp_message'] ?? '' ) ) ),
			'date_from' => self::valid_date( sanitize_text_field( wp_unslash( (string) ( $_POST['pp_from'] ?? '' ) ) ) ),
			'date_to'   => self::valid_date( sanitize_text_field( wp_unslash( (string) ( $_POST['pp_to'] ?? '' ) ) ) ),
			'items'     => $items,
		] );

		wp_safe_redirect( add_query_arg( 'pp_inquiry', is_wp_error( $result ) ? 'error' : 'ok', $back ) );
		exit;
	}

	private static function valid_date( string $value ): string {
		$value = sanitize_text_field( $value );
		$d     = \DateTime::createFromFormat( 'Y-m-d', $value );
		return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $value : '';
	}
}
