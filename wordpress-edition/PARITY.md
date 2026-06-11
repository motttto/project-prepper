# Parity-Matrix — Live-App ↔ WordPress-Edition

> Stand: 2026-06-11, Plugin v0.3.0, vor Lauf 1
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

## Verleih (Parität: ~65 %)

| App-Feature (Quelle) | WP-Status | Lücke | Prio |
|---|---|---|---|
| Anlegen + Verfügbarkeits-Check + Status-Flow (§9.1/9.4) | ✅ v0.1.0 | — | — |
| Abrechnung Brutto/Netto/USt, Kaution (§9.4) | ✅ v0.2.0 | — | — |
| **Tagessatz pro Position** im Anlege-UI (App: equipment-picker) | ⚠️ | REST kann `daily_rate`, Admin-UI bietet kein Feld | B |
| Verleih **bearbeiten** mit Diff-Logik (§9.4) | ❌ | nur anlegen/Status/löschen | A |
| Freigabe-Logik pro Position (§9.2/9.3, approval_status) | ❌ | braucht Multi-Owner | blockiert |
| "Mein Equipment unterwegs" (§9.3) | ❌ | braucht Multi-Owner | blockiert |

## Anfragen (Parität: ~50 %)

| App-Feature (Quelle) | WP-Status | Lücke | Prio |
|---|---|---|---|
| Öffentliches Formular → Pipeline (§11) | ✅ v0.3.0 | — | — |
| Status-Pipeline der App (new→contacted→offer→won/lost) | ⚠️ | WP nur new/contacted/closed | C |
| Anfrage-**Detail** (App: inquiries/[id]) | ❌ | nur Tabellenzeile | B |
| **Anfrage → Verleih konvertieren** (§11 Konvertierung) | ❌ | fehlt | A |

## Öffentliches Frontend (WP-only, kein App-Pendant — App ist komplett auth-geschützt)

| Feature | WP-Status | Lücke | Prio |
|---|---|---|---|
| [pp_inventory], [pp_availability], [pp_request_form] + Blöcke | ✅ v0.3.0 | — | — |
| Defekte/ausgemusterte Artikel öffentlich ausblenden | ❌ | zeigt alles; `show_all`-Attribut fehlt | B |
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

- [ ] A: Verleih bearbeiten (Diff-Logik) — Backend (Update-Service + REST PUT) + Admin-UI (Modal editierbar)
- [ ] A: Anfrage → Verleih konvertieren — Backend (Service + REST) + Button im Anfragen-Admin
- [ ] B: Defekte/ausgemusterte Artikel im öffentlichen Frontend ausblenden (`show_all="yes"` zum Übersteuern)

## Blockiert / Entscheidungen

- Freigabe-Logik §9.2/9.3 + "Mein Equipment unterwegs": braucht Multi-Owner-Modell (Gruppen, v2.x) — Architektur-Entscheidung nötig, ob WP-Edition Multi-Owner überhaupt bekommt.

## Log

- Vor Lauf 1 (2026-06-11): Matrix initial erstellt aus v0.3.0-Stand + Funktionsmapping.
