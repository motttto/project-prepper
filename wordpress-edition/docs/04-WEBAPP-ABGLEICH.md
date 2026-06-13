# 04 — Web-App ↔ WP-Edition: Funktions- & Design-Abgleich

> Erstellt 2026-06-13 aus der **eingeloggten Live-App** (project-prepper.dunkelstrom.net,
> via Control-Chrome-MCP) + Quellcode `src/`. Screenshot-Analyse: Dashboard, Inventar,
> Projektliste, Projekt-Detail (Tabs+Formular), Kalender. Dient als Referenz für die
> optische Angleichung der WP-Admin-UI.

## 1. Exakte Design-Tokens der Live-App (aus dem Live-DOM)

```
--color-background    #fafafa      --color-sidebar         #1e1b4b
--color-foreground    #0f172a      --color-sidebar-hover   #312e81
--color-primary       #6366f1      --color-sidebar-text    #e0e7ff
--color-primary-hover #4f46e5      --color-sidebar-text-muted #a5b4fc
--color-primary-light #eef2ff      --color-sidebar-active  #4f46e5
--color-surface       #ffffff      --color-muted-foreground #64748b
--color-surface-hover #f8fafc      --color-border          #e2e8f0
--color-muted         #f1f5f9      --color-destructive     #ef4444
--color-success       #10b981      --color-warning         #f59e0b   --color-info #3b82f6
radius: sm 6 / md 8 / lg 12 / xl 16   shadow sm/md/lg definiert
Font: Inter, system-ui, sans-serif; Body 16px / lh 24px; h1 24px/700; Button 14px/600 radius 12 padding 8×16
```
**Die WP-`admin/css/admin.css` nutzt bereits dieselben Tokens** (`--pp-*`, Kommentar „aus globals.css übernommen"). Auf Token-Ebene ist die Angleichung also schon erfolgt; die Lücken sind Typografie (Inter lädt im WP-Admin evtl. nicht) und Layout-Muster.

## 2. Layout-Sprache der App (Angleichungs-Ziele)

- **Dunkle Indigo-Sidebar** (#1e1b4b) mit Icon-Navigation, heller Content. (Im WP-Admin ist die linke Leiste WP-eigen — nicht 1:1 angleichbar; Ziel ist die **Content-Fläche**.)
- **Weiße Karten** (radius 12, shadow-sm, border) auf #fafafa.
- **Uppercase, kleine, gedämpfte Sektionsüberschriften** (z. B. „PROJEKTDETAILS", „SPIELSTÄTTE / VENUE").
- **Formular:** Label klein/gedämpft über breitem Input; großzügiges Spacing.
- **Pills** rounded-full (Indigo aktiv) für Kategorie-/Status-Filter.
- **Badges:** Menge/Verfügbar als runde Badges; **Zustand als Farbtext** (Gut=grün, Befriedigend=amber, Schlecht=rot).
- **Projekt-Detail = horizontale TAB-LEISTE** (aktiv: Indigo-Unterstrich) statt langem Scroll. ← größter struktureller Unterschied zur WP-Modal-Variante.
- KPI-Karten-Reihe (Dashboard/Inventar).

## 3. Seiten- & Funktionskatalog (App → WP-Status)

| App-Seite | Kernfunktionen (Live) | WP-Edition |
|---|---|---|
| Dashboard | „Hallo {name}", Onboarding-Karte, KPIs | ✅ Dashboard (KPIs/Anstehend/Aktivität) |
| Übersicht/Team | Mitglieder, Freigaben, Permissions, Impersonation, Aktivitätsprotokoll | ⚠️ teils: WP-Rollen/Caps; **Impersonation/Voting-UI fehlen** |
| Inventar | Liste (Spalten Artikel/Kat/Menge/**Verfügbar**/Zustand/€Tag/**Eigentum/Geteilt/Pate**/Ort), Kategorie-Pills, Suche, Leihgaben, **Inventar freigeben**, Auswertung, Excel-Im/Export, Neuer Artikel | ✅ Kern; **Verfügbar-Spalte, Sharing (Geteilt/Pate/freigeben)** fehlen (Multi-Owner) |
| Anfragen | Pipeline new→…→won/lost, Detail, →Verleih | ✅ vollständig |
| Verleih | Liste/Detail, Status-Flow, Abrechnung, Item-Freigaben, Tagessätze | ✅ Kern (ohne partner-Freigaben) |
| Projekte | Liste (Status-Gruppen, Meine/Team/Alle), Detail mit **12 Tabs** | ✅ alle 12 Tabs (als Modal-Sektionen) |
| → Projekt-Tabs | Übersicht, Zeitplan, Equipment, Team & Kontakte, Material & Transport, Kosten, Checklisten, Aufgaben, Umfragen, Vereinbarung, Dateien, Gewinn | ✅ alle vorhanden |
| Kalender | Monat/Woche, Termine anlegen, Kalender-**Gruppen+Farben**, **Einbinden/Sync (CalDAV)** | ✅ read-only Monatsansicht + iCal-Feed; **kein Anlegen/Woche/CalDAV** |
| Umfragen | Termin-/Auswahl-Umfragen (org-weit + Projekt) | ✅ pro Projekt; **keine org-weite Seite** |
| Alle Gruppen | Gruppen, Einladung+Voting | ✅ Gruppen + Mitglieder; **kein Einladungs-/Voting-Beitritt** |
| Admin | Superadmin-Tabs (User&Gruppen, Email-Templates, Feedback, Monetarisierung) | ⚠️ teils: Einstellungen/Email-Templates ja; Superadmin-Panel nein |
| Profil | Name/Email/Avatar/MFA/DSGVO/Telegram-Link | ⚠️ via WP-Profil + DSGVO-Core; kein Plugin-Profil |
| Präsenz „1 online" | Supabase Realtime | ❌ |

## 4. Ausgelagerte Funktionen → WP-konforme Alternative (OHNE weitere externe Dienste)

| App nutzt (extern) | WP-konforme Alternative ohne externen Dienst |
|---|---|
| **CalDAV Zwei-Wege-Sync** (Cloudflare Worker ↔ Supabase) | Vorhanden: **read-only iCal-Feed** (Token-URL). Optional später: **CalDAV-Endpoint direkt im Plugin (PHP)** über die WP-REST-API — kein externer Worker. |
| **Telegram-Bot** (Supabase Edge Function) | **`wp_mail()`-Benachrichtigung** an Betreiber/Crew + Admin-Notice; optional ein **generischer Webhook** (Betreiber trägt eigene URL ein). Kein Bot/Drittdienst. |
| **E-Mail-Versand** (Edge Functions/nodemailer) | **`wp_mail()`** (vorhanden) + SMTP via Standard-Plugin (Betreiberwahl). |
| **Supabase Realtime / Presence** („1 online", Live-Updates) | **WP Heartbeat API** (admin-ajax) für Online-Indikator/Polling — kein externer Dienst. |
| **Supabase Storage** | **WP-Medienbibliothek** (Dateien) + Plugin-Uploads (Inventarbilder) — vorhanden. |
| **Supabase Auth + MFA** | **WP-User/Rollen** (vorhanden); MFA via Standard-Plugin. |
| **Impersonation** (Admin „sieht als …") | **User-Switching** über eine Plugin-Capability (serverseitig, kein Drittdienst) — noch offen. |

> Leitlinie (User): **keine weiteren externen Dienste.** Alles über WP-Core (REST, Heartbeat, Cron, wp_mail, Medienbibliothek, Rollen).

## 5. Optische Angleichung — konkrete Maßnahmen (Backlog, Prio)

1. **Inter lokal bündeln + enqueuen** (woff2 im Plugin, kein Google-Fonts-Request) → Typografie matcht die App. *(größter Sofort-Effekt)*
2. **Projekt-Detail-Modal → TAB-LEISTE** (die 12 Sektionen als Tabs mit Indigo-Unterstrich statt langem Scroll) → das prägende App-Layout.
3. **Uppercase, gedämpfte Sektionsüberschriften** im Detail (PROJEKTDETAILS / VENUE …).
4. **Zustand als Farbtext** in der Inventarliste (statt Badge), Nummer als Mono-Badge unter dem Namen.
5. Spacing/Karten-Feinschliff an die App-Werte (radius 12, shadow-sm, Abstände).

## 6. Bewusst NICHT angeglichen (Architektur)
Dunkle Sidebar (WP-Admin hat eigene Navigation), Multi-Owner-Sharing im Inventar (Single-Site), org-weite Umfragen, Realtime-Präsenz, externe Sync-Dienste.
