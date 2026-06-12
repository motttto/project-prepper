# Parity-Matrix — Live-App ↔ WordPress-Edition

> Stand: 2026-06-12, Plugin v0.8.0, nach Release-Lauf 1 (i18n)
> Gepflegt vom Agenten `wp-parity` (.claude/agents/wp-parity.md). App = Referenz, WP = Ziel.

> **🎯 MVP-Parität erreicht (Lauf 3), C-Lücken geschlossen (Lauf 4):** Im MVP-Scope (Inventar,
> Verleih, Anfragen, E-Mail, Kalender-Feed, Einstellungen, DSGVO) sind keine A-, B- oder
> C-Lücken mehr offen. Übrig sind nur die blockierten Multi-Owner-Punkte. Weitere Läufe nur
> noch auf expliziten Wunsch (v1.x-Module laut MVP-Schnitt Dok 02 §4).

## Inventar (Parität: ~95 %)

| App-Feature (Quelle) | WP-Status | Lücke | Prio |
|---|---|---|---|
| KPIs, Kategorie-Pills, Volltextsuche (§8.5) | ✅ v0.2.0 | — | — |
| Alle Artikel-Felder inkl. SN/Maße/Watt/URLs (§8.1) | ✅ v0.2.0 | — | — |
| Foto + PDFs im Detail-Modal (§8.1) | ✅ v0.2.0 | — | — |
| Foto + PDFs direkt im **Anlege-Formular** (Commit 60eb81b) | ✅ v0.5.0 | — (POST /items, danach Media-Endpoints; Upload-Fehler als Toast, Artikel bleibt angelegt) | — |
| "PDF anzeigen"-Link in der **Liste** (Commit e9fe5b8) | ✅ v0.7.0 | — (1 PDF → direkter Link target=_blank, mehrere → "PDFs (n)" öffnet Detail-Modal; stopPropagation gegen Zeilen-Klick) | — |
| Filter "Ausgeliehen" (§8.5, aggregiert aus Rentals) | ✅ v0.5.0 | — (out_now als Subquery-JOIN, Toggle-Pill + Badge "n unterwegs", REST ?out_only=1) | — |
| Einzelstücke (§8.4) | ✅ v0.2.0 | — | — |
| Abschreibungs-/Eigentums-Felder (§8.7: ownership_type, funding_source, depreciation_*) | ✅ v0.7.0 | — (Schema 0.4.0, 5 Spalten; Modal-Sektion "Eigentum & Abschreibung"; bewusst NICHT in Export/Import — 19-Spalten-Parität zur App bleibt) | — |
| Kategorien-Merge (Migration 097) | ✅ v0.7.0 | — (POST /categories/{id}/merge; Button "Zusammenführen…" + Modal mit Ziel-Select und Item-Anzahl; Activity-Log category_merged) | — |
| Excel **XLSX** Import/Export (§8.6) | ✅ v0.6.0 | — (SheetJS CE 0.20.3 lokal gebündelt, Export mit aktuellen Filtern als inventar-JJJJ-MM-TT.xlsx, Import .xlsx/.xls über denselben Mapping-Editor, Datums-Zellen → JJJJ-MM-TT; CSV bleibt als Fallback) | — |

## Verleih (Parität: ~85 %)

| App-Feature (Quelle) | WP-Status | Lücke | Prio |
|---|---|---|---|
| Anlegen + Verfügbarkeits-Check + Status-Flow (§9.1/9.4) | ✅ v0.1.0 | — | — |
| Abrechnung Brutto/Netto/USt, Kaution (§9.4) | ✅ v0.2.0 | — | — |
| **Tagessatz pro Position** im Anlege-UI (App: equipment-picker) | ✅ v0.4.0 | — (inkl. Vorschlag aus Artikel-Tagessatz) | — |
| Verleih **bearbeiten** mit Diff-Logik (§9.4) | ✅ v0.4.0 | — (Header + Positionen, nur reserved/active, Verfügbarkeit mit exclude_rental_id) | — |
| Freigabe-Logik pro Position (§9.2/9.3, approval_status) | ❌ | braucht Multi-Owner | blockiert |
| "Mein Equipment unterwegs" (§9.3) | ❌ | braucht Multi-Owner | blockiert |

## Anfragen (Parität: ~90 %)

| App-Feature (Quelle) | WP-Status | Lücke | Prio |
|---|---|---|---|
| Öffentliches Formular → Pipeline (§11) | ✅ v0.3.0 | — | — |
| Status-Pipeline der App (new→contacted→offer→won/lost) | ✅ v0.7.0 | — (Transitions serverseitig erzwungen, won/lost/closed = Endstati; 'closed' bleibt als Legacy-Wert lesbar; Konvertieren setzt 'won') | — |
| Anfrage-**Detail** (App: inquiries/[id]) | ✅ v0.5.0 | — (Zeile klickbar, Modal mit allen Feldern, mailto-Link, voller Nachricht, Equipment-Liste, Status-/Konvertieren-/Löschen-Aktionen) | — |
| **Anfrage → Verleih konvertieren** (§11 Konvertierung) | ✅ v0.4.0 | — (Button im Admin, beide Edit-Caps nötig, ohne Zeitraum deaktiviert; Anfrage → closed, Verlinkung im Activity-Log) | — |

## Öffentliches Frontend (WP-only, kein App-Pendant — App ist komplett auth-geschützt)

| Feature | WP-Status | Lücke | Prio |
|---|---|---|---|
| [pp_inventory], [pp_availability], [pp_request_form] + Blöcke | ✅ v0.3.0 | — | — |
| Defekte/ausgemusterte Artikel öffentlich ausblenden | ✅ v0.4.0 | — (`show_all="yes"` bzw. Block-Toggle übersteuert) | — |
| Artikel-Detailseite (/equipment-item/{nummer}) | ✅ v0.7.0 | — (Rewrite-Endpoint + Theme-überschreibbares Template, Block- und Classic-Theme-tauglich; Whitelist-Felder, Tagessatz nur mit Option pp_public_show_rates; broken/retired → 404 außer mit pp_inventory_view; Karten verlinken) | — |

## E-Mail / Kalender / DSGVO / Einstellungen (Parität: ~85 %)

| App-Feature (Quelle) | WP-Status | Lücke | Prio |
|---|---|---|---|
| Editierbare Templates mit {{vars}} (§16) | ✅ v0.2.0 | — | — |
| iCal-Feed Token (§13) | ✅ v0.2.0 | CalDAV bewusst nicht portiert (Dok 02) | — |
| DSGVO Export/Löschung (§17) | ✅ v0.2.0 | — | — |
| SMTP-Konfiguration pro Betreiber (§16) | ⚠️ entschieden | wp_mail nutzt Server-Mail; SMTP via Standard-Plugins (z. B. WP Mail SMTP) — bewusst nicht selbst gebaut (WordPress-üblich) | — |

## Nicht begonnen (v1.x/v2.x laut MVP-Schnitt Dok 02 §4 — erst nach MVP-Parität)

Projekte (12 Tabs), Kosten-Übersicht, Dashboard, Umfragen, Telegram, Team-/Rollen-UI,
Gruppen + Voting, Vereinbarungen + Gewinnverteilung, Sharing/Erträge, Aktivitätsprotokoll-UI.

## Nächster Lauf

**Release-Vorbereitung läuft.** Erledigt: i18n (Release-Lauf 1, v0.8.0 — Quelle englisch,
de_DE vollständig). Für die wordpress.org-Submission fehlen noch:

- [ ] Assets fürs Plugin-Verzeichnis: `banner-1544x500.png`, `banner-772x250.png`, `icon-256x256.png`, Screenshots (`screenshot-1.png` …) + `== Screenshots ==`-Abschnitt in readme.txt
- [x] Plugin-Check-Plugin (PCP) durchlaufen lassen (Release-Lauf 2, v0.8.1 — 0 relevante ERRORs)
- [ ] wordpress.org-Account + Plugin einreichen → nach Freigabe SVN-Repo (trunk/tags/assets) bestücken
- [ ] Optional: Demo-Site + Lizenzmodell (Freemius/EDD) für Pro-Features

Alternativ (Produktentscheidung): v1.x-Module laut MVP-Schnitt Dok 02 §4 (Projekte,
Kosten-Übersicht, Dashboard, Umfragen, Telegram, Team-/Rollen-UI).

## Blockiert / Entscheidungen

- Freigabe-Logik §9.2/9.3 + "Mein Equipment unterwegs": braucht Multi-Owner-Modell (Gruppen, v2.x) — Architektur-Entscheidung nötig, ob WP-Edition Multi-Owner überhaupt bekommt.

## Log

- Release-Lauf 2 (2026-06-12, v0.8.1): **Plugin Check (PCP) bestanden — wordpress.org-Review-Vorprüfung.** PCP 1.6.x in wp-env installiert und via `wp plugin check project-prepper` laufen lassen; Start: 70 ERRORs + ~110 WARNINGs, Ende: 0 relevante ERRORs (einziger Rest: `hidden_files` für `.wp-env.json` — Dev-Datei, via build.sh nie im ZIP). Fixes: (1) readme.txt "Tested up to" 6.8→7.0 (war der einzige Blocker-ERROR der readme-Checks). (2) Alle ~22 `Schema::table()`-String-Konkatenationen in `$wpdb->prepare()`-Queries auf den `%i`-Identifier-Platzhalter (WP 6.2+, wir verlangen 6.4) umgestellt — betroffen: Privacy, Inventory, Rentals, Inquiries, Units, Availability, Numbering, ActivityLog; bei den dynamischen Query-Buildern (Inventory::items, Rentals::all, Inquiries::all) Tabellennamen per `array_unshift` vor die übrigen Params (Reihenfolge: %i-Tabellen → Subquery-Datums-%s → WHERE-Params); implode($where)-Zeilen mit gezieltem phpcs:ignore (statische Bedingungs-Strings). (3) Shortcodes.php: `wp_unslash()` vor jeder Sanitization von $_GET/$_POST (10 Stellen), `pp_item` via absint. (4) Schema::upgrade_data-Migrationen + uninstall.php-DROPs auf prepare(%i) + begründete phpcs:ignores (einmalige Migration / Opt-in-Uninstall). (5) Template-Loop-Variablen + load_plugin_textdomain: begründete phpcs:ignores (Methoden-Scope bzw. gebündelte Übersetzungen ohne wp.org-Language-Packs). Verbleibende ~100 WARNINGs bewertet und akzeptiert: DirectQuery/NoCaching auf Plugin-eigenen Tabellen (üblich, kein Review-Blocker). Tests grün: php -l (12 Dateien); Service-Smoke via wp eval-file (items/search/out_only/categories/stats/get_item/get_by_number/units/avail/next_inv_no „BÜH-0002"/next_rental_no/rentals+status/rental_get/inquiries+status/activity/privacy_export — alle OK, d.h. %i-Param-Reihenfolge stimmt überall); Frontend-curl /equipment/ inkl. Suche mit Umlaut+Quotes und Verfügbarkeits-GET → 200, 0 PHP-Fehler. **Nächster Schritt: Verzeichnis-Assets (Banner/Icon/Screenshots), danach wordpress.org-Einreichung (braucht User-Account).**
- Release-Lauf 1 (2026-06-12, v0.8.0): **Internationalisierung für wordpress.org — englische Quell-Strings, vollständige de_DE-Übersetzung.** Alle deutschen Quell-Strings (≈110 PHP in includes/ + templates/, ≈160 JS in admin.js/blocks-editor.js) auf Englisch geflippt; deutsche Installationen sehen über `languages/project-prepper-de_DE.po` (270 Einträge, vollständig) weiter exakt die bisherigen Texte. Const-Label-Arrays zu Methoden umgebaut, damit `__()` greift: `Shortcodes::condition_labels()` (auch von ItemDetail + ImportExport genutzt), `ImportExportController::export_columns()` (CSV-Header lokalisiert, de_DE = bisherige 19 deutschen Excel-Header); `CONDITION_MAP` (Import-Keywords de+en) bewusst unverändert. E-Mail-Default-Templates (Notifications::default_templates) je Subject/Body durch `__()` mit englischem Default ersetzt, `{{vars}}` bleiben wörtlich (translators-Kommentare warnen davor, sie zu übersetzen). JS: `var __ = wp.i18n.__` + `_x`/`sprintf`, Dependency `wp-i18n` auf `pp-admin`, `wp_set_script_translations()` für `pp-admin` UND `pp-blocks-editor`; msgid-Kollisionen aufgelöst (Status-Aktion „Stornieren" via `_x('Cancel','rental status action')` vs. Button „Abbrechen"; Privacy-Gruppe „Verleihvorgänge" → eigener msgid `Rental transactions`; Pill „Ausgeliehen"=`Out now` vs. Badge „Verliehen"=`On loan` vs. „unterwegs"=`on loan`). Import-AUTO_MAP erkennt jetzt deutsche UND englische Spaltenköpfe (dabei Alt-Bug gefixt: /nummer/ fing „Seriennummer" als Inventarnummer — jetzt verankert `^nummer$|^number$`). Übersetzungs-Dateien: `wp i18n make-pot` (0 Warnings nach translators-Kommentaren), de_DE.po komplett übersetzt, make-mo + make-json (--no-purge). **Achtung Learning:** das gebündelte wp-cli make-json mangelt `admin.js` zu `a.js` (unescaped-dot beim `.min.js`-Strip) → JSON-Hash falsch; Datei manuell auf `md5('admin/js/admin.js')` = `b41de59f…` umbenannt (blocks-editor.js war korrekt). readme.txt komplett englisch (Description, Installation, 4 FAQ, Credits; Changelog ab 0.8.0 englisch, ältere deutsche Einträge bleiben). Version 0.8.0 (Header + PP_VERSION + Stable tag). Tests grün: php -l (alle geänderten Dateien) + node --check; en_US: Admin-H1 „Inventory", Menü „Rentals", Frontend „Search equipment …"/„Check availability"/„Good"; de_DE (wp language core install + wp site switch-language): Admin-H1 „Inventar", Menü „Verleih/Einstellungen", JS-Übersetzungen inline (`pp-admin-js-translations` mit „Ausgeliehen"/„Gespeichert."), Chrome-DOM-Check Inventar (Pills „Ausgeliehen/Alle", KPIs „Artikel/Teile gesamt/…", Badge „Defekt", Buttons „Export/CSV-Export/Import", „Neuer Artikel") + Verleih („Neuer Verleih", „Verleih anlegen", „Leiher *", Aktionen „Ausgeben/Rücknahme/Stornieren"), Frontend „Equipment suchen …"/„Gut"/„Anfrage senden", Detailseite LIC-0001 mit „Gut"; wp eval de_DE: Subject „Reservierung {{rental_number}} — {{site_name}}", Body „Hallo …/Zeitraum: … bis …", CSV-Header „Inventarnummer;Name;…", broken→„Defekt". build.sh: ZIP 0.8.0 enthält languages/ (.pot, .po, .mo, 2 JSON). Site-Sprache bleibt auf de_DE (Zielmarkt).
- Lauf 4 (2026-06-12, v0.7.0): **C-Lücken-Sammel-Lauf — alle 5 verbliebenen C-Lücken geschlossen.** (1) "PDF anzeigen" in der Inventar-Liste: Doku-Spalte rendert bei genau 1 Dokument einen direkten Link (target=_blank, rel=noopener, stopPropagation), bei mehreren den Button "PDFs (n)" → öffnet das Detail-Modal (App-Verhalten aus Commit e9fe5b8). (2) Öffentliche Artikel-Detailseite `/equipment-item/{nummer}`: Rewrite-Rule + query_var in neuem `Frontend\ItemDetail`, einmaliger Rewrite-Flush nach Schema-Upgrade via Option-Flag (`pp_flush_rewrite_pending`, gesetzt in `Schema::migrate()`); `pre_handle_404`-Filter verhindert den WP-404 bei sichtbaren Artikeln; Template `templates/item-detail.php` rendert die komplette Seite und unterstützt Block-Themes (eigene Hülle mit `block_template_part('header'/'footer')` + manuellem `<title>`, da TT5 kein title-tag-Support hat) wie Classic-Themes (get_header/get_footer); Whitelist-Felder (kein Kaufpreis/SN/Notizen), Tagessatz nur bei neuer Option `pp_public_show_rates` (Checkbox in Einstellungen → "Öffentliches Frontend"); broken/retired → 404 außer eingeloggt mit `pp_inventory_view`; Inventar-Karten (Bild + Titel) verlinken auf die Detailseite; Achtung: Inventarnummer kommt URL-codiert aus der Rewrite-Rule → `rawurldecode()` (Umlaut-Prefixe wie BÜH). (3) Anfragen-Pipeline new→contacted→offer→won/lost mit serverseitig erzwungenen TRANSITIONS (`set_status` gibt WP_Error 409 bei ungültigem Übergang), 'closed' bleibt Legacy-Endstatus; `convert_to_rental` setzt 'won' und blockiert won/lost/closed; Admin-Badges: offer=Primärfarbe (wie App offer_sent), won=success, lost=muted. (4) Schema 0.4.0: `ownership_type`/`funding_source`/`depreciation_method`/`depreciation_years`/`residual_value` auf pp_items, Enum-Validierung im Service (own/loaned/funded/other bzw. linear/degressive/none — die App-Enums organization/member/shared passen nicht aufs Single-Owner-WP), Modal-Sektion "Eigentum & Abschreibung", bewusst nicht in Export/Import. (5) Kategorien-Merge: `Inventory::merge_categories()` + `POST /categories/{id}/merge` (400 bei self, 404 unbekannt), Admin-Modal mit Ziel-Select + Item-Anzahl, Activity-Log `category_merged`. Tests grün: php -l/node --check; wp-eval (Felder speichern/lesen inkl. ''→NULL und Enum-Abwehr, Merge verschiebt 2 Items + löscht Quelle, offer→won ok / won→contacted → pp_invalid_transition, convert → won + Doppel-Convert 409); REST (merge 200/401/400, PUT items mit allen 5 Feldern, Settings-Toggle); Frontend-curl (Detailseite 200 mit Titel-Tag, SN/Kaufpreis nie im HTML, Tagessatz nur mit Option, BÜH-0001 anonym 404 / eingeloggt 200, Karten-Links auf /equipment/); UI via Chrome (PDF-Link + "PDFs (2)"-Button, Abschreibungs-Sektion im Modal, Merge-Modal mit Anzahl, Pipeline-Buttons + Badge-Wechsel Neu→Angebot→Gewonnen, Settings-Checkbox speichert).
- Lauf 3 (2026-06-12, v0.6.0): **XLSX-Import/-Export via SheetJS — MVP-Parität erreicht** (letzte B-Lücke geschlossen). SheetJS Community Edition 0.20.3 (Apache-2.0) als `admin/js/vendor/xlsx.full.min.js` lokal gebündelt (kein CDN, wordpress.org-tauglich; Credits-Abschnitt in readme.txt), in Menu.php als Dependency von `pp-admin` enqueued. Export-Button "Export" erzeugt client-seitig `inventar-JJJJ-MM-TT.xlsx` (Sheet "Inventar", 19 Spalten + deutsche Header identisch zu EXPORT_COLUMNS des CSV-Endpoints, Zustands-Labels, Tags kommasepariert) aus `GET /items` mit den aktiven Such-/Kategorie-/Ausgeliehen-Filtern; CSV-Export bleibt als zweiter Button (jetzt ebenfalls mit out_only-Filter + Datum im Dateinamen). Import-Modal akzeptiert zusätzlich .xlsx/.xls: ArrayBuffer → `XLSX.read(cellDates:true)` → erstes Sheet → `sheet_to_json(header:1)`, Datums-Zellen als JJJJ-MM-TT normalisiert, danach derselbe Mapping-Editor + `POST /import` wie bei CSV. Nebenbei behoben: Server-Import verwarf `purchase_date` (jetzt inkl. TT.MM.JJJJ-Parsing). Tests grün: node --check + php -l; Node-Roundtrip mit der gebündelten Lib (deutsche Header + 2 Zeilen, "defekt"/"2,50" exakt zurück); REST-E2E per curl (Import 2 Zeilen → DB: broken/2.50/2024-03-15, Kategorie auto-angelegt; danach via DELETE entfernt); CSV-Export weiter 200 mit BOM + Content-Disposition; UI via Chrome (XLSX 0.20.3 in der Seiten-World definiert — Achtung: AppleScript-JS läuft in isolierter World, Page-Globals nur via injiziertem Inline-Script + DOM-Attribut prüfbar; Export/CSV-Export/Import-Buttons da, Import-Modal mit .xlsx im accept; In-Browser-Roundtrip write(array)→read mit Date-Zelle → "2024-03-15").
- Vor Lauf 1 (2026-06-11): Matrix initial erstellt aus v0.3.0-Stand + Funktionsmapping.
- Lauf 2 (2026-06-12, v0.5.0): Inventar-Filter "Ausgeliehen" (`Inventory::items()` mit `out_now` als aggregiertem Subquery-JOIN über reserved/active-Rentals des heutigen Tags — kein N+1; REST `GET /items?out_only=1`; Toggle-Pill neben den Kategorie-Pills + Badge "n unterwegs" in der Liste). Anfrage-Detail-Modal (REST `GET /inquiries/{id}`; Tabellenzeile klickbar, Modal mit allen Feldern, E-Mail als mailto-Link, vollständiger Nachricht, Equipment-Liste, Status-Aktionen + "In Verleih übernehmen" + Löschen im Footer; Aktionen in Zeile und Modal aus gemeinsamer `inquiryActions()`-Funktion). Foto + PDFs direkt im Inventar-Anlege-Formular (erst `POST /items`, dann sequentiell `POST /items/{id}/image` + `/documents`; Upload-Fehler als Toast, Artikel bleibt angelegt, Inputs werden geleert). Schema unverändert (0.3.0). Tests grün: php -l + node --check, wp-eval (Test-Verleih über heute → out_now=2 / out_only liefert genau das Item → nach Storno wieder 0), REST via App-Password (out_only 200/401, inquiries/1 mit items-Array, inquiries/999 → 404), Medien-Flow per curl (PNG + PDF hoch- und wieder runtergeladen/gelöscht, image_url/documents korrekt), UI via Chrome (Ausgeliehen-Pill + Foto/PDF-Inputs vorhanden, Toggle filtert, Anfrage-Modal mit allen Sektionen, Aktions-Buttons je nach Status, Rentals-Seite rendert).
- Lauf 1 (2026-06-11, v0.4.0): Verleih bearbeiten (`Rentals::update()` mit Header- + Positions-Diff, Verfügbarkeit via exclude_rental_id, nur reserved/active; REST `PUT /rentals/{id}`; Detail-Modal editierbar inkl. Menge/Tagessatz pro Position + Position hinzufügen/entfernen). Tagessatz pro Position auch im Anlege-Formular (mit Vorschlag aus Artikel-Tagessatz). Anfrage → Verleih (`Inquiries::convert_to_rental()`, REST `POST /inquiries/{id}/convert` mit Doppel-Cap-Check, Button im Anfragen-Admin, ohne Zeitraum deaktiviert). Öffentliches Frontend blendet broken/retired aus (`usable_only` in `Inventory::items()`, `show_all`-Attribut auf allen 3 Shortcodes + Block-Toggle "Auch defekte/ausgemusterte zeigen"). Alle Tests grün (14 wp-eval-Service-Tests, REST-Tests inkl. 401/403/409, Frontend-HTML-Check, 5 Admin-Seiten). Schema unverändert (0.3.0).
