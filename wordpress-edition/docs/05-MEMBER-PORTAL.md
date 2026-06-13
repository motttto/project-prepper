# 05 — Member-Portal (Frontend-Self-Service) & Föderation

> Architektur + Roadmap für die geklärte Vision (User 2026-06-13, siehe Memory
> [[grundkonzept]]): jede WP-Instanz = ein Kollektiv; **Mitglieder loggen sich im
> Frontend ein** (nicht wp-admin), besitzen **eigenes Inventar**, gründen/teilen in
> Gruppen; nichtkommerzielles Ressourcen-Teilen; Fernziel **Föderation** mehrerer
> Instanzen. Verbindlich vor weiterem Frontend-Bau.

## Leitprinzip
Eine Installation = ein Kollektiv (self-hosted, Datenhoheit, open source). **Nur der Admin nutzt wp-admin.** Mitglieder erleben alles im **Frontend** (Theme). → Es braucht eine **Front-End-Mitglieder-App**, kein weiteres wp-admin.

## Architektur-Entscheidungen

### 1. Per-User-Inventar-Ownership (Fundament)
- Neue Spalte `owner_user_id bigint unsigned NULL` auf `pp_items` (und ggf. `pp_categories`).
  - `NULL` = **Kollektiv-/Haus-Inventar** (vom Admin verwaltet — bestehendes Verhalten, additiv/abwärtskompatibel).
  - gesetzt = **gehört diesem Mitglied**.
- Spiegelt das App-Modell (`owner_profile_id`), aber als additive Spalte — kein Bruch der bestehenden site-weiten Daten.

### 2. Mitglieder-Rolle ohne wp-admin
- Rolle `pp_member` (existiert schon als Cap-Träger) → **ohne `read`-Zugang zur wp-admin-UI**: bei `admin_init` Nicht-Admins (außer AJAX/REST) vom Backend auf die Frontend-Mitglieder-Seite umleiten. Admin behält wp-admin.
- Mitglieder-Caps: eigenes Inventar CRUD, eigene Gruppen, in Gruppen teilen, anfragen/leihen — alles **scoped auf `owner_user_id = aktueller User`**.

### 3. Front-End-Mitglieder-App (Theme + Plugin)
- Auslieferung über das **Theme „Prepper Site"** (Seiten/Templates) + Plugin-**Shortcodes/Blöcke** für die App-Bereiche (z. B. `[pp_member_dashboard]`, `[pp_my_inventory]`, `[pp_my_groups]`, `[pp_browse]`).
- Nutzt die **bestehende REST-API**, aber mit **per-User-Authorisierung in PHP** (jede Route prüft owner_user_id / Gruppen-Mitgliedschaft — kein RLS-Netz, Sicherheitsfläche sorgfältig).
- Login/Registrierung im Frontend (WP-Auth, Custom-Login-Seiten; Registrierung nur per Einladung durch Admin).

### 4. Sharing-Modell (Kern der Vision)
- Mitglied teilt **eigenes** Inventar in eine **Gruppe** (vorhandenes Gruppen-Overlay erweitern: Items mit owner_user_id, sichtbar/leihbar für Gruppenmitglieder).
- **Nichtkommerziell:** Fokus auf Leihen/Tauschen (ggf. „Gegenleistung"-Freitext statt Preis), nicht Verkauf.

### 5. Föderation (Fernziel, NICHT jetzt)
- Eigene **REST-API zur Instanz-Erkennung**: Instanzen finden sich (opt-in), eingrenzbar per **PLZ**, sortiert nach **Thema**; instanzübergreifende Inventar-Sichtbarkeit. Eigener Lauf weit später; Datenschutz/Opt-in kritisch.

## Roadmap (gestuft, jede Phase testbar/ausrollbar)

| Phase | Inhalt | Aufwand |
|---|---|---|
| **0 — Ehrliche Startseite** | Theme-Frontpage kommuniziert „Kollektiv teilt Ressourcen" + **Mitglieder-Login-CTA** (statt Single-Anbieter-Katalog). Klein, sofort. | S |
| **1 — Fundament** | `owner_user_id` auf pp_items; `pp_member`-Rolle ohne wp-admin (Redirect); Front-End-Login + Mitglieder-Landingpage. | M |
| **2 — Mein Inventar (Frontend)** | Mitglied verwaltet **eigenes** Inventar vorne (anlegen/bearbeiten/Foto/löschen), REST scoped auf owner_user_id. | L |
| **3 — Gruppen (Frontend)** | Mitglied sieht/gründet/betritt Gruppen, **teilt eigenes Inventar** in Gruppen. | L |
| **4 — Stöbern & Leihen (Frontend)** | Mitglieder durchsuchen geteiltes Inventar des Kollektivs, **Leih-Anfrage** (an Verleih/Leihgaben gekoppelt), nichtkommerziell. | L |
| **5+ — Föderation** | Instanz-Discovery-API (PLZ/Thema), instanzübergreifend. Weit später. | XL |

## Offene Detail-Entscheidungen (vor Phase 1/2 zu klären)
- **Bestandsinventar**: bleibt `owner_user_id=NULL` (Kollektiv-Inventar, Admin verwaltet) — ok? Oder Admin-User zuweisen?
- **Registrierung**: rein per Admin-Einladung (kein offenes Signup) für Phase 1–4 — passt zu „Admin lädt ein".
- **Front-End-App im Theme vs. Plugin**: UI-Templates im Theme, Logik/Daten im Plugin (Shortcodes/Blöcke + REST) — Theme bleibt der „Skin", Plugin das Backend. So bleibt das Plugin theme-unabhängig nutzbar.
- **„vollwertiges Theme zum Testen"** (User-Wunsch): Theme „Prepper Site" wird zum Träger der Member-App ausgebaut; nach Phase 1–2 mit dem eigenen Kollektiv testbar.

## Status der bisherigen Arbeit (Einordnung)
Plugin v0.24.0 hat: Inventar/Verleih/Anfragen/Projekte/Gruppen/Beschlüsse/Umfragen/Gewinn/Vereinbarung — aber **Admin-zentriert, site-weites Inventar**. Das bleibt als **Admin-/Kollektiv-Backend** wertvoll; das Member-Portal setzt **davor/darauf** (Frontend + per-User-Ownership). Kein Wegwerfen — Erweiterung.
