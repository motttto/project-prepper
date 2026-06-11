<?php
/**
 * Template: Öffentliche Inventarliste — [pp_inventory]
 * Überschreibbar via {theme}/project-prepper/inventory-list.php
 *
 * @var array  $items            Whitelist-Felder pro Artikel
 * @var bool   $show_rates
 * @var bool   $show_search
 * @var string $search_value
 * @var array  $condition_labels
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pp-front">
	<?php if ( $show_search ) : ?>
		<form class="pp-front-search" method="get" action="">
			<input type="search" name="pp_q" value="<?php echo esc_attr( $search_value ); ?>" placeholder="<?php esc_attr_e( 'Equipment suchen …', 'project-prepper' ); ?>">
			<button type="submit" class="pp-front-btn"><?php esc_html_e( 'Suchen', 'project-prepper' ); ?></button>
		</form>
	<?php endif; ?>

	<?php if ( ! $items ) : ?>
		<p class="pp-front-empty"><?php esc_html_e( 'Kein Equipment gefunden.', 'project-prepper' ); ?></p>
	<?php else : ?>
		<div class="pp-front-grid">
			<?php foreach ( $items as $item ) : ?>
				<div class="pp-front-card">
					<div class="pp-front-card-media">
						<?php if ( $item['image_url'] ) : ?>
							<img src="<?php echo esc_url( $item['image_url'] ); ?>" alt="<?php echo esc_attr( $item['name'] ); ?>" loading="lazy">
						<?php else : ?>
							<span class="pp-front-card-icon"><?php echo esc_html( $item['category_icon'] ?: '📦' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="pp-front-card-body">
						<h3 class="pp-front-card-title"><?php echo esc_html( $item['name'] ); ?></h3>
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
							<p class="pp-front-card-text"><?php echo esc_html( wp_trim_words( $item['description'], 20 ) ); ?></p>
						<?php endif; ?>
						<?php if ( $show_rates && null !== $item['cost_per_day'] && '' !== $item['cost_per_day'] ) : ?>
							<div class="pp-front-card-rate"><?php echo esc_html( number_format_i18n( (float) $item['cost_per_day'], 2 ) ); ?> € <span><?php esc_html_e( '/ Tag', 'project-prepper' ); ?></span></div>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
