# WP-Modal — Standard für alle Popups / Modals der WordPress-Edition

Verbindlicher Bauplan für **jedes** Popup im Plugin — Mitglieder-Portal **und** wp-admin.
Nutze diesen Skill, bevor du ein Modal anlegst, umbaust oder dessen Größe anfasst.

**Kernregel:** Es gibt **eine** Modal-Breite für die ganze Instanz. Standard **75 % der
Fensterbreite**, einstellbar unter *wp-admin → Project Prepper → Einstellungen → Darstellung*.
Kein Modal bekommt eine eigene Breite.

---

## Woher die Breite kommt

| Schicht | Stelle | Inhalt |
|---------|--------|--------|
| Option | `wp_options.pp_modal_width` | Ganzzahl in Prozent, 40–100, Standard 75 |
| PHP | `ProjectPrepper\Settings::modal_width()` | einzige Lesestelle; `clamp_modal_width()` säubert (0/leer → 75) |
| Ausgabe | `Settings::attach_modal_width( $handle )` | hängt `:root{--pp-modal-width:75vw}` per `wp_add_inline_style` an `pp-frontend` und `pp-admin` |
| REST | `GET/PUT /project-prepper/v1/settings` | Feld `modal_width` (+ `modal_width_min/max` nur lesend) |
| Backend-UI | `admin/js/admin.js` → `renderSettings()` | Karte „Darstellung", Zahlenfeld |
| CSS Portal | `assets/css/frontend.css` → `.pp-modal` | `width: var(--pp-modal-width, 75vw); max-width: 96vw;` |
| CSS Backend | `admin/css/admin.css` → `.pp-modal` | `max-width: var(--pp-modal-width, 75vw);` |
| Aufräumen | `uninstall.php` | `pp_modal_width` steht in der Options-Liste |

Der Fallback `75vw` in beiden Stylesheets muss zum PHP-Standard passen — wer
`MODAL_WIDTH_DEFAULT` ändert, ändert beide CSS-Fallbacks mit.

**Neues Stylesheet-Handle mit Modals?** Direkt nach `wp_register_style`/`wp_enqueue_style`
`\ProjectPrepper\Settings::attach_modal_width( '<handle>' );` aufrufen (je Handle idempotent).

---

## Regeln

1. **Keine eigene Breite je Modal.** Kein `style="width:…"`, kein neuer `--wide`/`--narrow`-Modifier,
   kein `max-width` am `<dialog>`. Die frühere Variante `.pp-modal--wide` ist entfernt.
2. **Zwei Ausnahmen, sonst keine** — beide lesen die Variable bewusst nicht:
   - `.pp-modal--full` — Vollbild für rechnungsartige Formulare (Verleih neu/bearbeiten).
   - `.pp-modal--lightbox` — Bild-/PDF-Betrachter, richtet sich nach dem Inhalt.
   Eine dritte Ausnahme nur nach Rückfrage beim User.
3. **Handy:** unter 768 px immer `96vw` (Portal) bzw. volle Breite (Backend, < 782 px). Nicht überschreiben.
4. **Inhalt füllt das Modal.** Formulare im Modal haben keinen 480-px-Deckel
   (`.pp-modal .pp-portal__form { max-width: none }`). Direkte Formulare im Body sind ein
   Auto-Raster: `repeat(auto-fit, minmax(min(240px, 100%), 1fr))` → 4 Spalten bei 75 % auf
   großem Monitor, 2 im schmalen Modal, 1 auf dem Handy.
   Was eine volle Zeile braucht (Textarea, Artikel-Liste, Hinweis, Buttons): `grid-column: 1 / -1`.
   **Nie** auf eine feste Spaltenzahl bauen (`nth-child`, „zweite Spalte") — die Breite ist einstellbar.
5. **Höhe:** `max-height: 85vh`, Kopf und Fuß fix, nur `.pp-modal-body` scrollt.
6. **`display` nur für `[open]`** setzen (`.pp-modal[open] { display:flex }`) — sonst sind alle
   Dialoge gleichzeitig sichtbar.
7. **Keine Icons ungefragt** in Modal-Titel oder -Buttons (UI-Regel des Projekts).

---

## Portal: Markup-Vorlage (natives `<dialog>`)

```php
<button type="button" class="pp-portal__btn" data-pp-modal="pp-beispiel"><?php esc_html_e( 'Open', 'project-prepper' ); ?></button>

<dialog class="pp-modal pp-modal--portal" id="pp-beispiel">
	<div class="pp-modal-header">
		<h2 class="pp-modal__title"><?php esc_html_e( 'Title', 'project-prepper' ); ?></h2>
		<button type="button" class="pp-modal-close" data-pp-modal-close aria-label="<?php esc_attr_e( 'Close', 'project-prepper' ); ?>">✕</button>
	</div>
	<div class="pp-modal-body">
		<form class="pp-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'pp_action', 'pp_nonce' ); ?>
			<label><?php esc_html_e( 'Name', 'project-prepper' ); ?> <input type="text" name="pp_name" required></label>
			<button type="submit" class="pp-portal__btn"><?php esc_html_e( 'Save', 'project-prepper' ); ?></button>
		</form>
	</div>
	<!-- optional: <div class="pp-modal-footer">…</div> -->
</dialog>
```

Verhalten liefert `assets/js/portal.js` — nichts davon je Modal nachbauen:

| Attribut / Mechanik | Wirkung |
|---------------------|---------|
| `data-pp-modal="<id>"` | Klick (oder Enter/Leertaste bei `role="button"`) öffnet per `showModal()` |
| `data-pp-modal-close` | schließt das umgebende `<dialog>` |
| Backdrop-Klick, Esc | schließen ebenfalls |
| `form[data-pp-autosave]` | geänderte Felder werden beim Schließen gespeichert statt verworfen |
| `?pp_open=<id>` in der URL | öffnet das Modal beim Laden (Dashboard-Schnellaktionen) |
| ungespeicherte Änderungen | `beforeunload`-Warnung |

IDs mit dynamischem Teil am Ausgabepunkt casten: `id="pp-item-<?php echo (int) $item->id; ?>"`
(sonst Plugin-Check-ERROR EscapeOutput).

## Backend: `openModal()` in `admin/js/admin.js`

```js
var modal = openModal( __( "Title", "project-prepper" ), bodyNode, footerNode );
```

Baut Backdrop + `.pp-modal` selbst; Bausteine `pp-modal-grid`, `pp-modal-section`,
`pp-modal-footer`. Breite kommt aus derselben Variable — am Knoten **kein** `style.maxWidth` setzen.

---

## Prüfen (wp-env, ohne Login)

Portal-Ansicht als Testnutzer rendern und als statische Seite ansehen:

```bash
npx @wordpress/env run cli wp eval 'wp_set_current_user(41); $_GET["pp_view"]="inventory"; echo do_shortcode("[pp_member_portal]");' > body.html
```

Mit `<link rel="stylesheet" href="frontend.css">` umhüllen, per `docker cp` nach
`/var/www/html/<ordner>/` in den WordPress-Container legen, im Browser öffnen und das Modal
per Konsole öffnen: `document.getElementById('pp-item-16').showModal()`.
Solo-Arbeitsbereich erzwingen (ohne Daten zu ändern):
`add_filter("get_user_metadata", fn($v,$id,$k) => "pp_active_group"===$k ? ["solo"] : $v, 10, 3);`

Checkliste:
- [ ] Breite = eingestellter Prozentwert (`dlg.offsetWidth / innerWidth`)
- [ ] Formular füllt den Body, kein horizontales Scrollen im Body
- [ ] Bei 375 px: Modal 96vw, Felder einspaltig
- [ ] Wert ändern (`wp option update pp_modal_width 60`) → `curl -sL …/portal/ | grep pp-modal-width` zeigt `60vw`; danach `wp option delete pp_modal_width`
- [ ] Dark Mode lesbar (Dark-Fixes gehören ans **Dateiende** von `frontend.css`)
