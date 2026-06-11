# WP-Frontend — Öffentliche Ausgabe des WordPress-Plugins bauen

Frontend-Entwicklung der WordPress-Edition: Gutenberg-Blöcke, Shortcodes und Templates,
mit denen Plugin-Inhalte (Inventar, Verleih, Anfragen) auf der öffentlichen Website erscheinen.

## Ist-Zustand (2026-06-11)

- Frontend = **unverändertes Default-Theme** (Twenty Twenty-Five, „Hello world!"-Blog). Das Plugin rendert dort noch **nichts**.
- Das gesamte Plugin-UI lebt bisher im **WP-Admin** (`admin/js/admin.js`, Vanilla JS gegen REST).
- Laut Portierungs-Doku (Dok 02 §2/§5) geplant: **Gutenberg-Blöcke + Shortcodes** für die Frontend-Ausgabe, `templates/` im Theme überschreibbar.

## Architektur-Regeln

1. **Server-seitig rendern** (dynamische Blöcke / Shortcodes mit PHP-Callback, die direkt die Services nutzen: `Inventory::items()`, `Availability::available_quantity()` …).
   **NICHT** die Admin-REST-Endpoints öffentlich machen — die verlangen Capabilities (RLS-Ersatz!). Wenn ein Frontend-Endpoint nötig ist (z. B. Live-Verfügbarkeit), einen **eigenen, bewusst öffentlichen** Read-only-Endpoint anlegen, der nur unkritische Felder liefert (keine Kaufpreise, Seriennummern, Leiher-Daten).
2. **Shortcode zuerst, Block als Wrapper:** jeder Baustein als Shortcode (`[pp_inventory]`, `[pp_request_form]`), der Gutenberg-Block ruft denselben PHP-Renderer (`render_callback`). Kein JS-Build-Step (kein `@wordpress/scripts`), Block-Registrierung via `register_block_type` mit `render_callback` + `block.json` light.
3. **Design-Tokens wiederverwenden:** gleiche `--pp-*`-Variablen wie `admin/css/admin.css` (Indigo `#6366f1`, Light/Dark via `prefers-color-scheme`). Eigenes Stylesheet `assets/css/frontend.css`, nur auf Seiten mit Plugin-Ausgabe enqueuen (`has_shortcode()` / `has_block()`-Check).
4. **Dateistruktur** (im Plugin):
   ```
   includes/Frontend/Shortcodes.php   ← Registrierung + Renderer
   includes/Frontend/Blocks.php       ← register_block_type-Wrapper
   templates/inventory-list.php       ← überschreibbar via {theme}/project-prepper/…
   assets/css/frontend.css
   ```
   Template-Loader: erst `locate_template('project-prepper/' . $file)`, Fallback Plugin-`templates/`.
5. **Formulare** (Anfrage/Leihwunsch): klassisches POST mit Nonce (`wp_verify_nonce`), Honeypot-Feld gegen Spam, Erfolg/Fehler als Notice. Eingehende Anfragen → E-Mail an Admin (`Notifications`-Service erweitern) und später in eigene Tabelle (`pp_inquiries`, Anfragen-Pipeline Dok 01 §11).

## Roadmap (Reihenfolge)

1. `[pp_inventory]` — öffentliche Inventarliste: Foto, Name, Kategorie, Zustand, optional Tagessatz (`show_rates="yes"`), Filter per Attribut (`category="licht"`), Suche optional
2. `[pp_availability item="123"]` — Verfügbarkeits-Anzeige mit Datums-Pickern
3. `[pp_request_form]` — Anfrage-/Leihwunsch-Formular (Name, E-Mail, Zeitraum, Wunsch-Items)
4. Gutenberg-Blöcke als Wrapper für 1–3
5. Detailseite pro Artikel (Rewrite-Endpoint `/equipment/{inventory_number}` oder Modal)

## Testen

- wp-env läuft? Sonst `wordpress-edition/wp-project-prepper-start.command`
- Test-Seite anlegen/aktualisieren per WP-CLI:
  ```bash
  npx @wordpress/env run cli wp post create --post_type=page --post_title='Equipment' --post_content='[pp_inventory]' --post_status=publish --porcelain
  ```
- Browser-Check mit `/wp-checkup`-Vorgehen (Control-Chrome-MCP), zusätzlich Frontend-URL `http://localhost:8888/?page_id={id}` prüfen: Shortcode gerendert (kein roher `[pp_…]`-Text!), CSS geladen, Dark Mode, keine Capability-Leaks (Kaufpreis/Seriennummer dürfen ohne `show_rates` nicht im HTML stehen)
