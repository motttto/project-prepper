=== Prepper Site ===
Contributors: motttto
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: block-patterns, full-site-editing, one-column, custom-colors, custom-menu

A complete rental-business website theme designed for the Project Prepper plugin.

== Description ==

Prepper Site is a block theme that turns a WordPress installation with the
Project Prepper plugin into a complete rental-business website:

* Front page with hero, "how it works" section, live equipment list and request call-to-action
* Works with the plugin's blocks (equipment list, availability check, request form) and the public item detail pages
* Indigo color palette matching the plugin admin, fully editable in the site editor
* Translation-ready; a complete German (de_DE) translation is bundled

The theme also works without the plugin as a clean one-column block theme —
the equipment section on the front page simply stays empty until the plugin
is activated.

== Installation ==

1. Upload the theme ZIP via Appearance → Themes → Add New → Upload Theme.
2. Activate it. For the full experience, install and activate the Project Prepper plugin.
3. Create two pages for the call-to-action buttons: "Equipment" (slug `equipment`, e.g. with the equipment list block) and "Request" (slug `request`, with the request form block) — or adjust the button links in the site editor.

== Changelog ==

= 0.2.0 =
* Bundled Inter as the body font (self-hosted, SIL OFL) so the site typography matches the Project Prepper plugin admin and web app; registered via theme.json fontFace, no external request.

= 0.1.0 =
* Initial release: front page (hero, features, equipment teaser, request CTA), header/footer parts, page/single/index/404 templates, German translation.

== Credits ==

Inter font by Rasmus Andersson, licensed under the SIL Open Font License 1.1
(see assets/fonts/LICENSE). https://rsms.me/inter/
