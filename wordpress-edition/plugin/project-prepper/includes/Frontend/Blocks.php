<?php
namespace ProjectPrepper\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Gutenberg-Blöcke — dünne Wrapper um die Shortcode-Renderer (gleiche Ausgabe).
 * Editor-Vorschau über ServerSideRender, kein Build-Step (Vanilla-JS-Editor-Script).
 */
class Blocks {

	public static function init(): void {
		add_action( 'init', [ self::class, 'register' ] );
		add_action( 'enqueue_block_editor_assets', [ self::class, 'editor_assets' ] );
	}

	public static function register(): void {
		register_block_type( 'project-prepper/inventory', [
			'api_version'     => 3,
			'attributes'      => [
				'category'  => [ 'type' => 'string', 'default' => '' ],
				'showRates' => [ 'type' => 'boolean', 'default' => false ],
				'search'    => [ 'type' => 'boolean', 'default' => false ],
			],
			'render_callback' => static function ( array $attributes ): string {
				return Shortcodes::inventory( [
					'category'   => $attributes['category'] ?? '',
					'show_rates' => ! empty( $attributes['showRates'] ) ? 'yes' : 'no',
					'search'     => ! empty( $attributes['search'] ) ? 'yes' : 'no',
				] );
			},
		] );

		register_block_type( 'project-prepper/availability', [
			'api_version'     => 3,
			'attributes'      => [
				'item' => [ 'type' => 'string', 'default' => '' ],
			],
			'render_callback' => static function ( array $attributes ): string {
				return Shortcodes::availability( [ 'item' => $attributes['item'] ?? '' ] );
			},
		] );

		register_block_type( 'project-prepper/request-form', [
			'api_version'     => 3,
			'attributes'      => [
				'showItems' => [ 'type' => 'boolean', 'default' => true ],
			],
			'render_callback' => static function ( array $attributes ): string {
				return Shortcodes::request_form( [ 'show_items' => empty( $attributes['showItems'] ) ? 'no' : 'yes' ] );
			},
		] );
	}

	public static function editor_assets(): void {
		wp_enqueue_script(
			'pp-blocks-editor',
			PP_PLUGIN_URL . 'admin/js/blocks-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ],
			PP_VERSION,
			true
		);
		wp_enqueue_style( 'pp-frontend', PP_PLUGIN_URL . 'assets/css/frontend.css', [], PP_VERSION );
	}
}
