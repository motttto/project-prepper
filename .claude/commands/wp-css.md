# WP-CSS — CSS-Regeln der WordPress-Edition

Verbindliche CSS-Regeln für `assets/css/frontend.css` (Portal + öffentliche Ausgabe) und
`admin/css/admin.css` (wp-admin). Lies diesen Skill, bevor du Layout, Listen, Modals oder
Container mit fester Höhe anfasst. Farben/Tokens: `/app-design`. Popups: `/wp-modal`.

---

## 1. Scrollbars vermeiden

**Grundsatz:** Ein Scrollbalken ist ein Zeichen, dass das Layout den Platz nicht nutzt.
Erst das Layout ändern — der Balken ist die letzte Wahl, nicht die erste.

### Reihenfolge, bevor ein `overflow: auto` entsteht

1. **Breite nutzen.** Lange Listen/Formulare mehrspaltig:
   `grid-template-columns: repeat(auto-fill, minmax(min(260px, 100%), 1fr))`.
   (So wurde die Set-Stückliste von einer 16rem-Scrollbox zur vierspaltigen Liste.)
2. **Einklappen.** Seltene Abschnitte in `<details>` — zu, solange leer.
3. **Umbrechen statt schieben.** `flex-wrap: wrap`, `min-width: 0` an Flex-/Grid-Kindern,
   `overflow-wrap: anywhere` für lange Wörter/URLs, Tabellenzeilen unter 640 px stapeln
   (`data-label`-Muster der `.pp-list`/`.pp-inv-row`).
4. **Mehr Höhe geben.** Modals wachsen bis `90dvh`; keine festen `height`-Werte an Inhaltscontainern.
5. Erst dann: scrollen — und zwar **genau ein** Scroller je Ebene.

### Ein Scroller je Ebene

| Ebene | Wer scrollt | Alles andere |
|-------|-------------|--------------|
| Seite | das Dokument | kein `overflow`/`max-height` an Seitenabschnitten |
| Modal | nur `.pp-modal-body` | **keine** Scrollbox im Body (kein Scroll-in-Scroll) |
| Sidebar | `.pp-app__sidebar` | — |

Ein offenes Modal sperrt die Seite dahinter (`html:has(dialog.pp-modal[open]) { overflow: hidden }`);
`scrollbar-gutter: stable` auf der Portal-Seite verhindert dabei den Layout-Sprung.

### Verboten

- `overflow: scroll` (Balken immer sichtbar) — wenn, dann `auto`.
- Horizontaler Seiten-Scroll. Ursache suchen (fehlendes `min-width: 0`, feste Breiten,
  `100vw` neben einem vertikalen Balken → `100%` nehmen), nicht mit `overflow-x: hidden` zudecken.
- `max-height` + `overflow-y: auto` an Listen **innerhalb** eines Modals oder einer Karte.
- Eigene `::-webkit-scrollbar`-Gestaltung: erzwingt auf macOS dauerhaft sichtbare Balken statt der
  einblendenden System-Balken.

### Erlaubte Ausnahmen (bestehend — keine neue ohne Rückfrage)

| Selektor | Warum |
|----------|-------|
| `.pp-book-list` | Artikel-Picker mit Live-Suche: breite Zeilen (Foto, Verfügbarkeit, Menge), mehrspaltig nicht möglich |
| `.pp-cal__tg-body`, `.pp-cal__tg` | Wochen-Zeitraster (24 h) bzw. 600 px Mindestbreite auf dem Handy |
| `.pp-pollgrid` | Teilnehmer-Matrix kann breiter als das Handy sein |
| `.pp-proj-tabs` | Reiterleiste auf schmalen Screens |
| `.pp-terms` | AGB-Text im Zustimmungsschirm |
| `admin.css`: `.pp-import-preview`, `.pp-modal-backdrop` | Excel-Vorschau; Backdrop scrollt das ganze Backend-Modal |

### Wo ein Balken bleibt: unauffällig

Steht zentral am **Dateiende** von `frontend.css` — nicht je Komponente wiederholen:

```css
@media (prefers-color-scheme: dark) {
	.pp-front, .pp-app { color-scheme: dark; }            /* sonst HELLE Balken im Dark Mode */
	html:has(body.pp-app-body) { color-scheme: dark; }    /* Seiten-Balken */
}
.pp-front, .pp-app { scrollbar-color: color-mix(in srgb, var(--pp-muted-foreground) 45%, transparent) transparent; }
.pp-front *, .pp-app * { scrollbar-width: thin; }        /* vererbt sich nicht → `*` */
```

`color-scheme` färbt auch Datumswähler und Selects — nie weglassen, wenn ein neuer Wurzel-Container
mit eigenem Dark-Block dazukommt.

### Prüfen

```js
// im geöffneten Modal: darf nichts außer dem Body scrollen
[...dlg.querySelectorAll('.pp-modal-body *')].filter(e => /(auto|scroll)/.test(getComputedStyle(e).overflowY)
  && e.scrollHeight > e.clientHeight + 1 && e.tagName !== 'TEXTAREA').map(e => e.className)   // → []
// Seite: kein Horizontal-Scroll (auch bei 375 px prüfen)
document.documentElement.scrollWidth === document.documentElement.clientWidth                  // → true
```

---

## 2. Weitere feste Regeln (aus früheren Fehlern)

- **Farben nur über `--pp-*`-Tokens.** Dann braucht der Dark Mode keine Sonderregel.
  Unvermeidbare Dark-Overrides gehören ans **Dateiende** (sonst überstimmt sie eine spätere Regel).
- **`[hidden]` verliert gegen `display: grid/flex`.** Wer per JS ein-/ausblendet, braucht
  `.klasse[hidden] { display: none; }`.
- **`display` am `<dialog>` nur für `[open]`** — sonst sind alle Modals gleichzeitig sichtbar.
- **Vor jedem neuen BEM-Block greppen.** `.pp-cal__bar` gab es schon als Navigationsleiste;
  der gleichnamige Balken hat sie zerdrückt.
- **`grid-row: 1 / -1` trifft nur EXPLIZITE Zeilen.** Bei implizit erzeugten Zeilen die
  Zeilenvorlage in PHP bauen und jede Position explizit setzen.
- **Keine feste Spaltenzahl annehmen** (`nth-child`, „zweite Spalte") — Formulare sind Auto-Raster
  und die Modal-Breite ist einstellbar (`/wp-modal`).
- **Keine Icons ungefragt**, auch nicht per CSS (`::before`-Symbole).
- **Beim Testen Cache umgehen:** `frontend.css?ver=…` bleibt in der Sitzung gecacht — im wp-env einen
  Cache-Buster an den Link hängen, sonst misst man den alten Stand.
- **„Sieht schlecht aus" ≠ Layoutfehler.** Erst klären, ob ein Bug oder die Gestaltung gemeint ist.
