<?php
/**
 * Template: Anfrage-Formular — [pp_request_form]
 * Überschreibbar via {theme}/project-prepper/request-form.php
 *
 * @var array $items    Equipment zur Auswahl (leer wenn show_items="no")
 * @var bool  $success
 * @var bool  $error
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pp-front">
	<?php if ( $success ) : ?>
		<div class="pp-front-notice pp-front-notice-ok"><?php esc_html_e( 'Thank you! Your inquiry has been received — we will get back to you.', 'project-prepper' ); ?></div>
	<?php elseif ( $error ) : ?>
		<div class="pp-front-notice pp-front-notice-warn"><?php esc_html_e( 'Unfortunately, that did not work. Please check the required fields and try again.', 'project-prepper' ); ?></div>
	<?php endif; ?>

	<?php if ( ! $success ) : ?>
	<form class="pp-front-form pp-front-request" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="pp_inquiry">
		<?php wp_nonce_field( 'pp_inquiry', 'pp_nonce' ); ?>
		<!-- Honeypot (für Menschen unsichtbar, Bots füllen es aus) -->
		<input type="text" name="pp_website" value="" class="pp-front-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

		<div class="pp-front-form-grid">
			<label class="pp-front-field">
				<span><?php esc_html_e( 'Name *', 'project-prepper' ); ?></span>
				<input type="text" name="pp_name" required>
			</label>
			<label class="pp-front-field">
				<span><?php esc_html_e( 'Email *', 'project-prepper' ); ?></span>
				<input type="email" name="pp_email" required>
			</label>
			<label class="pp-front-field">
				<span><?php esc_html_e( 'Phone', 'project-prepper' ); ?></span>
				<input type="tel" name="pp_phone">
			</label>
			<label class="pp-front-field">
				<span><?php esc_html_e( 'From', 'project-prepper' ); ?></span>
				<input type="date" name="pp_from">
			</label>
			<label class="pp-front-field">
				<span><?php esc_html_e( 'To', 'project-prepper' ); ?></span>
				<input type="date" name="pp_to">
			</label>
		</div>

		<?php if ( $items ) : ?>
			<fieldset class="pp-front-items">
				<legend><?php esc_html_e( 'Requested equipment', 'project-prepper' ); ?></legend>
				<div class="pp-front-items-grid">
					<?php foreach ( $items as $item ) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Template läuft im Methoden-Scope, keine echte Globale. ?>
						<label class="pp-front-check">
							<input type="checkbox" name="pp_items[]" value="<?php echo esc_attr( $item['id'] ); ?>">
							<span><?php echo esc_html( $item['name'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
		<?php endif; ?>

		<label class="pp-front-field">
			<span><?php esc_html_e( 'Message', 'project-prepper' ); ?></span>
			<textarea name="pp_message" rows="4"></textarea>
		</label>

		<button type="submit" class="pp-front-btn pp-front-btn-primary"><?php esc_html_e( 'Send inquiry', 'project-prepper' ); ?></button>
	</form>
	<?php endif; ?>
</div>
