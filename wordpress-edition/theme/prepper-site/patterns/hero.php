<?php
/**
 * Title: Hero
 * Slug: prepper-site/hero
 * Categories: featured, banner
 * Description: Dark hero section with headline, intro text and two call-to-action buttons.
 */
?>
<!-- wp:group {"align":"full","gradient":"hero","style":{"spacing":{"padding":{"top":"96px","bottom":"96px","left":"clamp(20px, 4vw, 48px)","right":"clamp(20px, 4vw, 48px)"}}},"layout":{"type":"constrained","contentSize":"1200px"}} -->
<div class="wp-block-group alignfull has-hero-gradient-background has-background" style="padding-top:96px;padding-right:clamp(20px, 4vw, 48px);padding-bottom:96px;padding-left:clamp(20px, 4vw, 48px)">
	<!-- wp:group {"align":"wide","layout":{"type":"constrained","contentSize":"760px","justifyContent":"left"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:heading {"level":1,"textColor":"surface","fontSize":"xx-large"} -->
		<h1 class="wp-block-heading has-surface-color has-text-color has-xx-large-font-size"><?php esc_html_e( 'Rent professional event equipment', 'prepper-site' ); ?></h1>
		<!-- /wp:heading -->
		<!-- wp:paragraph {"textColor":"accent","fontSize":"large"} -->
		<p class="has-accent-color has-text-color has-large-font-size"><?php esc_html_e( 'Lighting, sound and stage gear — check availability online and send your request in minutes.', 'prepper-site' ); ?></p>
		<!-- /wp:paragraph -->
		<!-- wp:buttons {"style":{"spacing":{"margin":{"top":"32px"}}}} -->
		<div class="wp-block-buttons" style="margin-top:32px">
			<!-- wp:button -->
			<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/equipment/"><?php esc_html_e( 'Browse equipment', 'prepper-site' ); ?></a></div>
			<!-- /wp:button -->
			<!-- wp:button {"backgroundColor":"night","textColor":"accent","style":{"border":{"color":"#a5b4fc","width":"1px"}}} -->
			<div class="wp-block-button"><a class="wp-block-button__link has-accent-color has-night-background-color has-text-color has-background has-border-color wp-element-button" style="border-color:#a5b4fc;border-width:1px" href="/request/"><?php esc_html_e( 'Send a request', 'prepper-site' ); ?></a></div>
			<!-- /wp:button -->
		</div>
		<!-- /wp:buttons -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
