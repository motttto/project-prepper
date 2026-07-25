<?php
namespace ProjectPrepper\Frontend;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Schema;
use ProjectPrepper\Services\Inventory;

defined( 'ABSPATH' ) || exit;

/**
 * Öffentliche Artikel-Detailseite: /equipment-item/{inventory_number}
 *
 * Rewrite-Endpoint ohne eigene Seite/CPT. Sichtbar sind nur Whitelist-Felder
 * (kein Kaufpreis, keine Seriennummer, keine Leiher-Daten); der Tagessatz nur,
 * wenn die Option pp_public_show_rates aktiv ist. Defekte/ausgemusterte Artikel
 * liefern 404 — außer für eingeloggte User mit pp_inventory_view.
 *
 * Template templates/item-detail.php ist im Theme überschreibbar
 * ({theme}/project-prepper/item-detail.php) und rendert die komplette Seite
 * (get_header/get_footer).
 */
class ItemDetail {

	const QUERY_VAR = 'pp_item_number';

	public static function init(): void {
		add_action( 'init', [ self::class, 'register_rewrite' ] );
		add_filter( 'query_vars', [ self::class, 'register_query_var' ] );
		add_filter( 'pre_handle_404', [ self::class, 'prevent_404' ], 10, 2 );
		add_action( 'template_redirect', [ self::class, 'maybe_render' ] );
	}

	public static function register_rewrite(): void {
		add_rewrite_rule( '^equipment-item/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );

		// Einmaliger Flush nach Schema-/Versions-Upgrade (Flag setzt Schema::migrate()).
		if ( get_option( Schema::FLUSH_FLAG ) ) {
			flush_rewrite_rules();
			delete_option( Schema::FLUSH_FLAG );
		}
	}

	public static function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * URL der Detailseite für eine Inventarnummer.
	 */
	public static function url( string $inventory_number ): string {
		return home_url( '/equipment-item/' . rawurlencode( $inventory_number ) . '/' );
	}

	/**
	 * Artikel auflösen — null, wenn unbekannt oder öffentlich nicht sichtbar.
	 * Die Nummer kommt URL-codiert aus der Rewrite-Rule (z. B. Umlaute in Prefixen).
	 */
	private static function resolve_item( string $number ): ?object {
		$item = Inventory::get_item_by_number( rawurldecode( $number ) );
		if ( ! $item ) {
			return null;
		}
		// broken/retired nur für eingeloggte User mit Inventar-Leserecht.
		if ( in_array( $item->condition, [ 'broken', 'retired' ], true ) && ! current_user_can( Capabilities::VIEW_INVENTORY ) ) {
			return null;
		}
		return $item;
	}

	/**
	 * WP würde die Rewrite-URL als 404 behandeln (kein Post dahinter) —
	 * bei sichtbarem Artikel das 404-Handling abschalten.
	 *
	 * @param bool      $preempt
	 * @param \WP_Query $wp_query
	 */
	public static function prevent_404( $preempt, $wp_query ) {
		$number = (string) $wp_query->get( self::QUERY_VAR );
		if ( '' !== $number && self::resolve_item( $number ) ) {
			return true;
		}
		return $preempt;
	}

	public static function maybe_render(): void {
		$number = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $number ) {
			return;
		}

		$row = self::resolve_item( $number );
		if ( ! $row ) {
			// Unbekannt oder ausgeblendet → normales 404 der Website.
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		add_filter( 'pre_get_document_title', static function () use ( $row ) {
			// Selbst escapen: der Filter kurzschließt wp_get_document_title() vor
			// dessen internem esc_html; bei klassischen Themes echot der Core den
			// Rückgabewert ungeescaped in <title> (Defense-in-Depth).
			return esc_html( $row->name . ' – ' . get_bloginfo( 'name' ) );
		} );

		wp_enqueue_style( 'pp-frontend' );

		$theme_template = locate_template( 'project-prepper/item-detail.php' );
		$template       = $theme_template ?: PP_PLUGIN_DIR . 'templates/item-detail.php';

		// Template-Variablen — Whitelist-Felder (kein Kaufpreis, keine Seriennummer, keine internen Notizen).
		$item = [
			'name'             => $row->name,
			'inventory_number' => $row->inventory_number,
			'description'      => $row->description,
			'accessories'      => $row->accessories,
			'category_name'    => $row->category_name,
			'category_icon'    => $row->category_icon,
			'condition'        => $row->condition,
			'quantity'         => (int) $row->quantity,
			'tags'             => (array) $row->tags,
			'image_url'        => $row->image_id ? ( wp_get_attachment_image_url( (int) $row->image_id, 'large' ) ?: $row->image_url ) : null,
			// Tagessatz nur, wenn öffentlich freigegeben (Einstellungen).
			'cost_per_day'     => get_option( 'pp_public_show_rates', false ) ? $row->cost_per_day : null,
		];
		$condition_labels = Shortcodes::condition_labels();

		include $template;
		exit;
	}
}
