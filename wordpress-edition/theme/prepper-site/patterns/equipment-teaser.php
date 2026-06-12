<?php
/**
 * Title: Equipment teaser
 * Slug: prepper-site/equipment-teaser
 * Categories: featured
 * Description: Equipment list from the Project Prepper plugin with a heading and a link to the full catalog.
 */
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"72px","bottom":"72px","left":"clamp(20px, 4vw, 48px)","right":"clamp(20px, 4vw, 48px)"}}},"layout":{"type":"constrained","contentSize":"1200px"}} -->
<div class="wp-block-group alignfull" style="padding-top:72px;padding-right:clamp(20px, 4vw, 48px);padding-bottom:72px;padding-left:clamp(20px, 4vw, 48px)">
	<!-- wp:heading {"textAlign":"center","fontSize":"x-large"} -->
	<h2 class="wp-block-heading has-text-align-center has-x-large-font-size"><?php esc_html_e( 'Our equipment', 'prepper-site' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:group {"align":"wide","style":{"spacing":{"margin":{"top":"40px"}}},"layout":{"type":"constrained"}} -->
	<div class="wp-block-group alignwide" style="margin-top:40px">
		<!-- wp:project-prepper/inventory {"showRates":true} /-->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
