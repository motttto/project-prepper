<?php
/**
 * Template: Öffentliche Artikel-Detailseite — /equipment-item/{inventory_number}
 * Überschreibbar via {theme}/project-prepper/item-detail.php
 *
 * Rendert die komplette Seite (get_header/get_footer).
 *
 * @var array $item             Whitelist-Felder: name, inventory_number, description,
 *                              accessories, category_name, category_icon, condition,
 *                              quantity, tags, image_url, cost_per_day (null = nicht öffentlich)
 * @var array $condition_labels
 */

defined( 'ABSPATH' ) || exit;

// Block-Themes (FSE) haben kein header.php — eigene Seiten-Hülle mit den
// Header-/Footer-Template-Parts des Themes; klassische Themes via get_header().
$pp_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Template läuft im Funktions-Scope, keine echte Globale.
if ( $pp_block_theme ) {
	?>
	<!DOCTYPE html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<?php if ( ! current_theme_supports( 'title-tag' ) ) : // Block-Themes rendern den Titel sonst nur über die Block-Template-Pipeline. ?>
			<title><?php echo esc_html( wp_get_document_title() ); ?></title>
		<?php endif; ?>
		<?php wp_head(); ?>
	</head>
	<body <?php body_class(); ?>>
	<?php wp_body_open(); ?>
	<div class="wp-site-blocks">
	<?php block_template_part( 'header' ); ?>
	<?php
} else {
	get_header();
}
?>
<div class="pp-front pp-front-detail">
	<div class="pp-front-detail-media">
		<?php if ( $item['image_url'] ) : ?>
			<img src="<?php echo esc_url( $item['image_url'] ); ?>" alt="<?php echo esc_attr( $item['name'] ); ?>">
		<?php else : ?>
			<span class="pp-front-card-icon"><?php echo esc_html( $item['category_icon'] ?: '📦' ); ?></span>
		<?php endif; ?>
	</div>

	<div class="pp-front-detail-body">
		<h1 class="pp-front-detail-title"><?php echo esc_html( $item['name'] ); ?></h1>

		<div class="pp-front-card-meta">
			<?php if ( $item['category_name'] ) : ?>
				<span class="pp-front-chip"><?php echo esc_html( trim( ( $item['category_icon'] ? $item['category_icon'] . ' ' : '' ) . $item['category_name'] ) ); ?></span>
			<?php endif; ?>
			<span class="pp-front-chip pp-front-chip-<?php echo esc_attr( $item['condition'] ); ?>"><?php echo esc_html( $condition_labels[ $item['condition'] ] ?? $item['condition'] ); ?></span>
			<?php if ( $item['quantity'] > 1 ) : ?>
				<span class="pp-front-chip"><?php echo esc_html( $item['quantity'] ); ?>×</span>
			<?php endif; ?>
		</div>

		<?php if ( $item['description'] ) : ?>
			<p class="pp-front-detail-text"><?php echo nl2br( esc_html( $item['description'] ) ); ?></p>
		<?php endif; ?>

		<?php if ( $item['accessories'] ) : ?>
			<h2 class="pp-front-detail-subtitle"><?php esc_html_e( 'Accessories', 'project-prepper' ); ?></h2>
			<p class="pp-front-detail-text"><?php echo nl2br( esc_html( $item['accessories'] ) ); ?></p>
		<?php endif; ?>

		<?php if ( $item['tags'] ) : ?>
			<div class="pp-front-card-meta">
				<?php foreach ( $item['tags'] as $tag ) : ?>
					<span class="pp-front-chip"><?php echo esc_html( $tag ); ?></span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( null !== $item['cost_per_day'] && '' !== $item['cost_per_day'] ) : ?>
			<div class="pp-front-card-rate"><?php echo esc_html( number_format_i18n( (float) $item['cost_per_day'], 2 ) ); ?> € <span><?php esc_html_e( '/ day', 'project-prepper' ); ?></span></div>
		<?php endif; ?>
	</div>
</div>
<?php
if ( $pp_block_theme ) {
	block_template_part( 'footer' );
	?>
	</div>
	<?php wp_footer(); ?>
	</body>
	</html>
	<?php
} else {
	get_footer();
}
