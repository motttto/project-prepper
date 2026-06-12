<?php
/**
 * Title: Features
 * Slug: prepper-site/features
 * Categories: featured, columns
 * Description: Three columns explaining how renting works.
 */
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"72px","bottom":"72px","left":"clamp(20px, 4vw, 48px)","right":"clamp(20px, 4vw, 48px)"}}},"backgroundColor":"surface","layout":{"type":"constrained","contentSize":"1200px"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:72px;padding-right:clamp(20px, 4vw, 48px);padding-bottom:72px;padding-left:clamp(20px, 4vw, 48px)">
	<!-- wp:heading {"textAlign":"center","fontSize":"x-large"} -->
	<h2 class="wp-block-heading has-text-align-center has-x-large-font-size"><?php esc_html_e( 'How it works', 'prepper-site' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:columns {"align":"wide","style":{"spacing":{"margin":{"top":"48px"},"blockGap":{"left":"40px"}}}} -->
	<div class="wp-block-columns alignwide" style="margin-top:48px">
		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:paragraph {"fontSize":"xx-large"} -->
			<p class="has-xx-large-font-size">1</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"large"} -->
			<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e( 'Browse the catalog', 'prepper-site' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"muted"} -->
			<p class="has-muted-color has-text-color"><?php esc_html_e( 'Search our equipment by name, category or tags — with daily rates and live availability.', 'prepper-site' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:paragraph {"fontSize":"xx-large"} -->
			<p class="has-xx-large-font-size">2</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"large"} -->
			<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e( 'Send your request', 'prepper-site' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"muted"} -->
			<p class="has-muted-color has-text-color"><?php esc_html_e( 'Pick the items and your rental period — we get back to you with a quote.', 'prepper-site' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:paragraph {"fontSize":"xx-large"} -->
			<p class="has-xx-large-font-size">3</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"large"} -->
			<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e( 'Pick up & play', 'prepper-site' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"muted"} -->
			<p class="has-muted-color has-text-color"><?php esc_html_e( 'Collect your gear on the agreed date — checked, tested and ready to go.', 'prepper-site' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
