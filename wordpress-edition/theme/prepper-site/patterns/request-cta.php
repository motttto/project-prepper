<?php
/**
 * Title: Request call-to-action
 * Slug: prepper-site/request-cta
 * Categories: featured, call-to-action
 * Description: Indigo call-to-action band linking to the request form.
 */
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"64px","bottom":"64px","left":"clamp(20px, 4vw, 48px)","right":"clamp(20px, 4vw, 48px)"}}},"backgroundColor":"primary","textColor":"surface","layout":{"type":"constrained","contentSize":"1200px"}} -->
<div class="wp-block-group alignfull has-surface-color has-primary-background-color has-text-color has-background" style="padding-top:64px;padding-right:clamp(20px, 4vw, 48px);padding-bottom:64px;padding-left:clamp(20px, 4vw, 48px)">
	<!-- wp:heading {"textAlign":"center","textColor":"surface","fontSize":"x-large"} -->
	<h2 class="wp-block-heading has-text-align-center has-surface-color has-text-color has-x-large-font-size"><?php esc_html_e( 'Already a member?', 'prepper-site' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:paragraph {"align":"center","textColor":"tint"} -->
	<p class="has-text-align-center has-tint-color has-text-color"><?php esc_html_e( 'Sign in to manage your inventory and your collectives. New here? Access is by invitation — ask the platform operators.', 'prepper-site' ); ?></p>
	<!-- /wp:paragraph -->
	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"style":{"spacing":{"margin":{"top":"24px"}}}} -->
	<div class="wp-block-buttons" style="margin-top:24px">
		<!-- wp:button {"backgroundColor":"surface","textColor":"primary-dark"} -->
		<div class="wp-block-button"><a class="wp-block-button__link has-primary-dark-color has-surface-background-color has-text-color has-background wp-element-button" href="/portal/"><?php esc_html_e( 'Go to member login', 'prepper-site' ); ?></a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
