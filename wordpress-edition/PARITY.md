# Parity-Matrix — Live-App ↔ WordPress-Edition

> Stand: 2026-06-11, Plugin v0.4.0, nach Lauf 1
> Gepflegt vom Agenten `wp-parity` (.claude/agents/wp-parity.md). App = Referenz, WP = Ziel.

## Inventar (Parität: ~70 %)

| App-Feature (Quelle) | WP-Status | Lücke | Prio |
|---|---|---|---|
| KPIs, Kategorie-Pills, Volltextsuche (§8.5) | ✅ v0.2.0 | — | — |
| Alle Artikel-Felder inkl. SN/Maße/Watt/URLs (§8.1) | ✅ v0.2.0 | — | — |
| Foto + PDFs im Detail-Modal (§8.1) | ✅ v0.2.0 | — | — |
| Foto + PDFs direkt im **Anlege-Formular** (Commit 60eb81b) | ❌ | Anlegen nur mit Basis-Feldern | B |
| "PDF anzeigen"-Link in der **Liste** (Commit e9fe5b8) | ❌ | nur Zähler "n PDF" | C |
| Filter "Ausgeliehen" (§8.5, aggregiert aus Rentals) | ❌ | fehlt komplett | B |
| Einzelstücke (§8.4) | ✅ v0.2.0 | — | — |
| Abschreibungs-/Eigentums-Felder (§8.7: ownership_type, funding_source, depreciation_*) | ❌ | Spalten + UI fehlen | C |
| Kategorien-Merge (Migration 097) | ❌ | nur Löschen (Items → ohne Kategorie) | C |
| Excel **XLSX** Import/Export (§8.6) | ⚠️ CSV | XLSX via SheetJS/PhpSpreadsheet | B |

## Verleih (Parität: ~85 %)

| App-Feature (Quelle) | WP-Status | Lücke | Prio |
|---|---|---|---|
| Anlegen + Verfügbarkeits-Check + Status-Flow (§9.1/9.4) | ✅ v0.1.0 | — | — |
| Abrechnung Brutto/Netto/USt, Kaution (§9.4) | ✅ v0.2.0 | — | — |
| **Tagessatz pro Position** im Anlege-UI (App: equipment-picker) | ✅ v0.4.0 | — (inkl. Vorschlag aus Artikel-Tagessatz) | — |
| Verleih **bearbeiten** mit Diff-Logik (§9.4) | ✅ v0.4.0 | — (Header + Positionen, nur reserved/active, Verfügbarkeit mit exclude_rental_id) | — |
| Freigabe-Logik pro Position (§9.2/9.3, approval_status) | ❌ | braucht Multi-Owner | blockiert |
| "Mein Equipment unterwegs" (§9.3) | ❌ | braucht Multi-Owner | blockiert |

## Anfragen (Parität: ~70 %)

| App-Feature (Quelle) | WP-Status | Lücke | Prio |
|---|---|---|---|
| Öffentliches Formular → Pipeline (§11) | ✅ v0.3.0 | — | — |
| Status-Pipeline der App (new→contacted→offer→won/lost) | ⚠️ | WP nur new/contacted/closed | C |
| Anfrage-**Detail** (App: inquiries/[id]) | ❌ | nur Tabellenzeile | B |
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

- [ ] B: Foto + PDFs direkt im Inventar-Anlege-Formular (Commit 60eb81b der App) — Admin-UI erweitern, Media-REST existiert bereits (`/items/{id}/image` + `/documents` nach dem Anlegen aufrufen)
- [ ] B: Inventar-Filter "Ausgeliehen" (§8.5) — aggregiert aus reserved/active-Rentals, als Pill/Toggle neben den Kategorie-Pills; Backend: `Inventory::items()` um `out_today`-Flag/Filter ergänzen
- [ ] B: Anfrage-Detail-Modal (App: inquiries/[id]) — Tabellenzeile klickbar, Modal mit allen Feldern, Items, Nachricht komplett, Status-Aktionen + Konvertieren-Button

## Blockiert / Entscheidungen

- Freigabe-Logik §9.2/9.3 + "Mein Equipment unterwegs": braucht Multi-Owner-Modell (Gruppen, v2.x) — Architektur-Entscheidung nötig, ob WP-Edition Multi-Owner überhaupt bekommt.

## Log

- Vor Lauf 1 (2026-06-11): Matrix initial erstellt aus v0.3.0-Stand + Funktionsmapping.
- Lauf 1 (2026-06-11, v0.4.0): Verleih bearbeiten (`Rentals::update()` mit Header- + Positions-Diff, Verfügbarkeit via exclude_rental_id, nur reserved/active; REST `PUT /rentals/{id}`; Detail-Modal editierbar inkl. Menge/Tagessatz pro Position + Position hinzufügen/entfernen). Tagessatz pro Position auch im Anlege-Formular (mit Vorschlag aus Artikel-Tagessatz). Anfrage → Verleih (`Inquiries::convert_to_rental()`, REST `POST /inquiries/{id}/convert` mit Doppel-Cap-Check, Button im Anfragen-Admin, ohne Zeitraum deaktiviert). Öffentliches Frontend blendet broken/retired aus (`usable_only` in `Inventory::items()`, `show_all`-Attribut auf allen 3 Shortcodes + Block-Toggle "Auch defekte/ausgemusterte zeigen"). Alle Tests grün (14 wp-eval-Service-Tests, REST-Tests inkl. 401/403/409, Frontend-HTML-Check, 5 Admin-Seiten). Schema unverändert (0.3.0).
