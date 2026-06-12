# Parity-Matrix — Live-App ↔ WordPress-Edition

> Stand: 2026-06-12, Plugin v0.6.0, nach Lauf 3
> Gepflegt vom Agenten `wp-parity` (.claude/agents/wp-parity.md). App = Referenz, WP = Ziel.

> **🎯 MVP-Parität erreicht (Lauf 3):** Im MVP-Scope (Inventar, Verleih, Anfragen, E-Mail,
> Kalender-Feed, Einstellungen, DSGVO) sind keine A- oder B-Lücken mehr offen. Übrig sind nur
> C-Lücken (Kosmetik/Komfort) und die blockierten Multi-Owner-Punkte. Weitere Läufe nur noch
> auf expliziten Wunsch (C-Lücken oder v1.x-Module laut MVP-Schnitt Dok 02 §4).

## Inventar (Parität: ~90 %)

| App-Feature (Quelle) | WP-Status | Lücke | Prio |
|---|---|---|---|
| KPIs, Kategorie-Pills, Volltextsuche (§8.5) | ✅ v0.2.0 | — | — |
| Alle Artikel-Felder inkl. SN/Maße/Watt/URLs (§8.1) | ✅ v0.2.0 | — | — |
| Foto + PDFs im Detail-Modal (§8.1) | ✅ v0.2.0 | — | — |
| Foto + PDFs direkt im **Anlege-Formular** (Commit 60eb81b) | ✅ v0.5.0 | — (POST /items, danach Media-Endpoints; Upload-Fehler als Toast, Artikel bleibt angelegt) | — |
| "PDF anzeigen"-Link in der **Liste** (Commit e9fe5b8) | ❌ | nur Zähler "n PDF" | C |
| Filter "Ausgeliehen" (§8.5, aggregiert aus Rentals) | ✅ v0.5.0 | — (out_now als Subquery-JOIN, Toggle-Pill + Badge "n unterwegs", REST ?out_only=1) | — |
| Einzelstücke (§8.4) | ✅ v0.2.0 | — | — |
| Abschreibungs-/Eigentums-Felder (§8.7: ownership_type, funding_source, depreciation_*) | ❌ | Spalten + UI fehlen | C |
| Kategorien-Merge (Migration 097) | ❌ | nur Löschen (Items → ohne Kategorie) | C |
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

## Anfragen (Parität: ~85 %)

| App-Feature (Quelle) | WP-Status | Lücke | Prio |
|---|---|---|---|
| Öffentliches Formular → Pipeline (§11) | ✅ v0.3.0 | — | — |
| Status-Pipeline der App (new→contacted→offer→won/lost) | ⚠️ | WP nur new/contacted/closed | C |
| Anfrage-**Detail** (App: inquiries/[id]) | ✅ v0.5.0 | — (Zeile klickbar, Modal mit allen Feldern, mailto-Link, voller Nachricht, Equipment-Liste, Status-/Konvertieren-/Löschen-Aktionen) | — |
| **Anfrage → Verleih konvertieren** (§11 Konvertierung) | ✅ v0.4.0 | — (Button im Admin, beide Edit-Caps nötig, ohne Zeitraum deaktiviert; Anfrage → closed, Verlinkung im Activity-Log) | — |

## Öffentliches Frontend (WP-only, kein App-Pendant — App ist komplett auth-geschützt)

| Feature | WP-Status | Lücke | Prio |
|---|---|---|---|
| [pp_inventory], [pp_availability], [pp_request_form] + Blöcke | ✅ v0.3.0 | — | — |
| Defekte/ausgemusterte Artikel öffentlich ausblenden | ✅ v0.4.0 | — (`show_all="yes"` bzw. Block-Toggle übersteuert) | — |
| Artikel-Detailseite (/equipment/{nummer}) | ❌ | fehlt | C |

## E-Mail / Kalender / DSGVO / Einstellungen (Parität: ~85 %)

| App-Feature (Quelle) | WP-Status | Lücke | Prio |
|---|---|---|---|
| Editierbare Templates mit {{vars}} (§16) | ✅ v0.2.0 | — | — |
| iCal-Feed Token (§13) | ✅ v0.2.0 | CalDAV bewusst nicht portiert (Dok 02) | — |
| DSGVO Export/Löschung (§17) | ✅ v0.2.0 | — | — |
| SMTP-Konfiguration pro Betreiber (§16) | ⚠️ | wp_mail nutzt Server-Mail; Hinweis auf WP-Mail-SMTP-Plugin genügt vorerst | C |

## Nicht begonnen (v1.x/v2.x laut MVP-Schnitt Dok 02 §4 — erst nach MVP-Parität)

Projekte (12 Tabs), Kosten-Übersicht, Dashboard, Umfragen, Telegram, Team-/Rollen-UI,
Gruppen + Voting, Vereinbarungen + Gewinnverteilung, Sharing/Erträge, Aktivitätsprotokoll-UI.

## Nächster Lauf

**MVP-Parität erreicht: keine A/B-Lücken mehr offen.** Weitere Läufe nur auf expliziten Wunsch.
Falls C-Lücken angegangen werden sollen, in dieser Reihenfolge:

- [ ] C: "PDF anzeigen"-Link in der Inventar-**Liste** (Commit e9fe5b8 der App) — statt nur Zähler "n PDF"
- [ ] C: Artikel-Detailseite im öffentlichen Frontend (/equipment/{nummer}) — Whitelist-Felder beachten
- [ ] C: Status-Pipeline der Anfragen erweitern (new→contacted→offer→won/lost wie die App, statt new/contacted/closed)
- [ ] C: Abschreibungs-/Eigentums-Felder (§8.7: ownership_type, funding_source, depreciation_*)
- [ ] C: Kategorien-Merge (Migration 097) — statt nur Löschen

Alternativ: v1.x-Module laut MVP-Schnitt (Projekte, Kosten, Dashboard, Umfragen, Telegram, Team-/Rollen-UI).

## Blockiert / Entscheidungen

- Freigabe-Logik §9.2/9.3 + "Mein Equipment unterwegs": braucht Multi-Owner-Modell (Gruppen, v2.x) — Architektur-Entscheidung nötig, ob WP-Edition Multi-Owner überhaupt bekommt.

## Log

- Lauf 3 (2026-06-12, v0.6.0): **XLSX-Import/-Export via SheetJS — MVP-Parität erreicht** (letzte B-Lücke geschlossen). SheetJS Community Edition 0.20.3 (Apache-2.0) als `admin/js/vendor/xlsx.full.min.js` lokal gebündelt (kein CDN, wordpress.org-tauglich; Credits-Abschnitt in readme.txt), in Menu.php als Dependency von `pp-admin` enqueued. Export-Button "Export" erzeugt client-seitig `inventar-JJJJ-MM-TT.xlsx` (Sheet "Inventar", 19 Spalten + deutsche Header identisch zu EXPORT_COLUMNS des CSV-Endpoints, Zustands-Labels, Tags kommasepariert) aus `GET /items` mit den aktiven Such-/Kategorie-/Ausgeliehen-Filtern; CSV-Export bleibt als zweiter Button (jetzt ebenfalls mit out_only-Filter + Datum im Dateinamen). Import-Modal akzeptiert zusätzlich .xlsx/.xls: ArrayBuffer → `XLSX.read(cellDates:true)` → erstes Sheet → `sheet_to_json(header:1)`, Datums-Zellen als JJJJ-MM-TT normalisiert, danach derselbe Mapping-Editor + `POST /import` wie bei CSV. Nebenbei behoben: Server-Import verwarf `purchase_date` (jetzt inkl. TT.MM.JJJJ-Parsing). Tests grün: node --check + php -l; Node-Roundtrip mit der gebündelten Lib (deutsche Header + 2 Zeilen, "defekt"/"2,50" exakt zurück); REST-E2E per curl (Import 2 Zeilen → DB: broken/2.50/2024-03-15, Kategorie auto-angelegt; danach via DELETE entfernt); CSV-Export weiter 200 mit BOM + Content-Disposition; UI via Chrome (XLSX 0.20.3 in der Seiten-World definiert — Achtung: AppleScript-JS läuft in isolierter World, Page-Globals nur via injiziertem Inline-Script + DOM-Attribut prüfbar; Export/CSV-Export/Import-Buttons da, Import-Modal mit .xlsx im accept; In-Browser-Roundtrip write(array)→read mit Date-Zelle → "2024-03-15").
- Vor Lauf 1 (2026-06-11): Matrix initial erstellt aus v0.3.0-Stand + Funktionsmapping.
- Lauf 2 (2026-06-12, v0.5.0): Inventar-Filter "Ausgeliehen" (`Inventory::items()` mit `out_now` als aggregiertem Subquery-JOIN über reserved/active-Rentals des heutigen Tags — kein N+1; REST `GET /items?out_only=1`; Toggle-Pill neben den Kategorie-Pills + Badge "n unterwegs" in der Liste). Anfrage-Detail-Modal (REST `GET /inquiries/{id}`; Tabellenzeile klickbar, Modal mit allen Feldern, E-Mail als mailto-Link, vollständiger Nachricht, Equipment-Liste, Status-Aktionen + "In Verleih übernehmen" + Löschen im Footer; Aktionen in Zeile und Modal aus gemeinsamer `inquiryActions()`-Funktion). Foto + PDFs direkt im Inventar-Anlege-Formular (erst `POST /items`, dann sequentiell `POST /items/{id}/image` + `/documents`; Upload-Fehler als Toast, Artikel bleibt angelegt, Inputs werden geleert). Schema unverändert (0.3.0). Tests grün: php -l + node --check, wp-eval (Test-Verleih über heute → out_now=2 / out_only liefert genau das Item → nach Storno wieder 0), REST via App-Password (out_only 200/401, inquiries/1 mit items-Array, inquiries/999 → 404), Medien-Flow per curl (PNG + PDF hoch- und wieder runtergeladen/gelöscht, image_url/documents korrekt), UI via Chrome (Ausgeliehen-Pill + Foto/PDF-Inputs vorhanden, Toggle filtert, Anfrage-Modal mit allen Sektionen, Aktions-Buttons je nach Status, Rentals-Seite rendert).
- Lauf 1 (2026-06-11, v0.4.0): Verleih bearbeiten (`Rentals::update()` mit Header- + Positions-Diff, Verfügbarkeit via exclude_rental_id, nur reserved/active; REST `PUT /rentals/{id}`; Detail-Modal editierbar inkl. Menge/Tagessatz pro Position + Position hinzufügen/entfernen). Tagessatz pro Position auch im Anlege-Formular (mit Vorschlag aus Artikel-Tagessatz). Anfrage → Verleih (`Inquiries::convert_to_rental()`, REST `POST /inquiries/{id}/convert` mit Doppel-Cap-Check, Button im Anfragen-Admin, ohne Zeitraum deaktiviert). Öffentliches Frontend blendet broken/retired aus (`usable_only` in `Inventory::items()`, `show_all`-Attribut auf allen 3 Shortcodes + Block-Toggle "Auch defekte/ausgemusterte zeigen"). Alle Tests grün (14 wp-eval-Service-Tests, REST-Tests inkl. 401/403/409, Frontend-HTML-Check, 5 Admin-Seiten). Schema unverändert (0.3.0).
