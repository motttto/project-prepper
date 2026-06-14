<?php
/**
 * Vollbild-App-Shell für die Member-Portal-Seite (per template_include).
 *
 * Rendert eine eigene, theme-unabhängige HTML-Seite — dunkle Sidebar + Topbar
 * + Inhalt, passend zur Next.js-Dashboard-App. wp_head()/wp_footer() bleiben
 * aktiv (Styles, Admin-Bar für Manager). Der gesamte Body kommt aus
 * MemberPortal::render_body() (eingeloggt → App-Shell, sonst → Login-Karte).
 */

use ProjectPrepper\Frontend\MemberPortal;

defined( 'ABSPATH' ) || exit;

$pp_logged_in = is_user_logged_in();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( $pp_logged_in ? 'pp-app-body' : 'pp-app-body pp-app-body--login' ); ?>>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intern erzeugtes, bereits escaptes Markup.
	echo MemberPortal::render_body();
	wp_footer();
	?>
</body>
</html>
