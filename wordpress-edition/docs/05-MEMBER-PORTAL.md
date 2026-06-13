# 05 — Member-Portal (Frontend-Self-Service) & Föderation

> Architektur + Roadmap für die geklärte Vision (User 2026-06-13/14, siehe Memory
> [[grundkonzept]]): jede WP-Instanz = **Plattform**, auf der Single-User Kollektive
> (= Gruppen) **selbst gründen/​beitreten**; **Mitglieder loggen sich im Frontend ein**
> (nicht wp-admin), besitzen **eigenes Inventar**, teilen in Gruppen; Beitritt per
> Mitglieder-Voting; nichtkommerzielles Ressourcen-Teilen; Fernziel **Föderation**.
> Verbindlich vor weiterem Frontend-Bau.

## Leitprinzip (präzisiert 2026-06-13)
Eine Installation = eine **Plattform/Architektur** (self-hosted, Datenhoheit, open source), auf der sich **mehrere Kollektive selbst bilden**. Ein **Kollektiv = eine vom User gegründete Gruppe** — die Instanz ist NICHT a priori einem Kollektiv zugeordnet; url.xyz ist der Startpunkt für Single-User, um ein Kollektiv zu gründen/​beizutreten. **Admin = Plattform-Betreiber** (stellt die Architektur, einziger mit wp-admin, Moderation). **Mitglieder erleben alles im Frontend** (Theme), kein wp-admin. → faktisch das **Multi-Tenant-Modell der App (User-First + selbst gegründete Gruppen), self-hosted** — es braucht eine vollwertige **Front-End-Mitglieder-App**.

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
- Login/Registrierung im Frontend (WP-Auth, Custom-Login-Seiten; Registrierungs-Modus konfigurierbar, Default Admin-Freigabe — s. „Offene Detail-Entscheidungen").

### 4. Sharing-Modell (Kern der Vision)
- Mitglied teilt **eigenes** Inventar in eine **Gruppe** (vorhandenes Gruppen-Overlay erweitern: Items mit owner_user_id, sichtbar/leihbar für Gruppenmitglieder).
- **Nichtkommerziell:** Fokus auf Leihen/Tauschen (ggf. „Gegenleistung"-Freitext statt Preis), nicht Verkauf.

### 5. Föderation (Fernziel, NICHT jetzt)
- Eigene **REST-API zur Instanz-Erkennung**: Instanzen finden sich (opt-in), eingrenzbar per **PLZ**, sortiert nach **Thema**; instanzübergreifende Inventar-Sichtbarkeit. Eigener Lauf weit später; Datenschutz/Opt-in kritisch.

## Umsetzungsstand
- **Phase 0 + 1 umgesetzt (Plugin v0.25.0 / Schema 0.16.0 / Theme „Prepper Site" v0.3.0, 2026-06-14):**
  - Theme-Frontpage als Plattform-Landing (Hero „Ressourcen als Kollektiv teilen", 3-Schritte „Gründen/Beitreten · Inventar einbringen · Verleihen", CTA → `/portal/`).
  - `owner_user_id` (NULL = Kollektiv) auf `pp_items` + `pp_categories` — additiv, Bestandsdaten unverändert.
  - Rolle `pp_member` wird vom wp-admin ferngehalten (Redirect auf Portal + Admin-Bar aus); Admin/Manager behalten Backend (`MemberPortal::is_member_only()`).
  - Front-End-Portal `[pp_member_portal]` (Auto-Seite „portal"): Login (WP-Auth) + Einladungs-Hinweis · für Mitglieder Begrüßung + eigene Kollektive + ehrliche „In Vorbereitung"-Kacheln (Gründen/Beitreten/Mein Inventar).
  - Einladungs-only by design (kein offenes Signup); security by design (Portal liest nur eigene Daten).
  - de_DE vollständig (POMO-kompiliert), Plugin Check sauber (nur dev-only `.wp-env.json` + erwarteter Stable-Tag, beide ok).
- **Offen / als Nächstes:** Phase 2 (Gründen/Beitreten + Beitritts-Voting im Frontend) — die Portal-Kacheln aktivieren.

## Roadmap (gestuft, jede Phase testbar/ausrollbar)

| Phase | Inhalt | Aufwand |
|---|---|---|
| **0 — Ehrliche Startseite** | Theme-Frontpage als **Plattform-Landing**: „Gründe ein Kollektiv oder tritt einem bei" + Registrieren/Login-CTA (statt Single-Anbieter-Katalog). Klein, sofort. | S |
| **1 — Fundament** | `owner_user_id` auf pp_items; `pp_member`-Rolle ohne wp-admin (Redirect); **Einladungs-basierter Zugang** (Admin lädt Seed-User, kein offenes Signup) + Front-End-Login + Mitglieder-Landingpage. Security by design (scoped REST, kein wp-admin-Leak). | M |
| **2 — Kollektiv gründen/​beitreten (Frontend)** | Onboarding: registrierter User **gründet eine Gruppe (= Kollektiv)** oder tritt einer bei — vorne, ohne wp-admin. (Gruppen-Overlay existiert backend-seitig; hier Front-End + User-darf-gründen.) | M |
| **3 — Mein Inventar + Teilen (Frontend)** | Mitglied verwaltet **eigenes** Inventar vorne (anlegen/Foto/bearbeiten, REST scoped auf owner_user_id) und **teilt es in seine Gruppe(n)**. | L |
| **4 — Stöbern & Leihen (Frontend)** | Mitglieder durchsuchen geteiltes Inventar des Kollektivs, **Leih-Anfrage** (an Verleih/Leihgaben gekoppelt), nichtkommerziell. | L |
| **5+ — Föderation** | Instanz-Discovery-API (PLZ/Thema), instanzübergreifend. Weit später. | XL |

## Offene Detail-Entscheidungen (vor Phase 1/2 zu klären)
- **Bestandsinventar**: bleibt `owner_user_id=NULL` (Kollektiv-Inventar, Admin verwaltet) — ok? Oder Admin-User zuweisen?
- **Registrierung: ENTSCHIEDEN = (c) nur per Einladung** (2026-06-14). Plattform-Account: Admin lädt Seed-User ein (kein offenes Signup). Gruppen-Beitritt: Mitglieder laden ein + Gruppen-Voting (einstimmig) + Superadmin-Override. (a/b später denkbar/konfigurierbar.)
- **Front-End-App im Theme vs. Plugin**: UI-Templates im Theme, Logik/Daten im Plugin (Shortcodes/Blöcke + REST) — Theme bleibt der „Skin", Plugin das Backend. So bleibt das Plugin theme-unabhängig nutzbar.
- **„vollwertiges Theme zum Testen"** (User-Wunsch): Theme „Prepper Site" wird zum Träger der Member-App ausgebaut; nach Phase 1–2 mit dem eigenen Kollektiv testbar.

## Status der bisherigen Arbeit (Einordnung)
Plugin v0.24.0 hat: Inventar/Verleih/Anfragen/Projekte/Gruppen/Beschlüsse/Umfragen/Gewinn/Vereinbarung — aber **Admin-zentriert, site-weites Inventar**. Das bleibt als **Admin-/Kollektiv-Backend** wertvoll; das Member-Portal setzt **davor/darauf** (Frontend + per-User-Ownership). Kein Wegwerfen — Erweiterung.

## Governance & Mechaniken (aus der Next.js-App verifiziert, 2026-06-14)

### Gruppen-Beitritt = Mitglieder-Voting (App-Mechanik, deckt User-Vorgabe)
Quelle: `supabase/migrations/072_voting_triggers.sql`.
1. **Mitglied lädt ein** → `group_invitations` status `pending`.
2. Eingeladener **akzeptiert** → wenn nur 1 aktives Mitglied (Gründer): sofort `approved` (Auto-Join); sonst `voting_in_progress`.
3. Bestehende aktive Mitglieder stimmen (`group_invitation_votes`, approve/reject).
4. Auflösung: **eine Ablehnung → rejected_by_member**; **alle aktiven Mitglieder approve (approvals ≥ total_active) → approved + Mitgliedschaft aktiv** → **Einstimmigkeit**.
- **WP-Umsetzung (neu, Phase 2/3):** Mitglieder (nicht nur Admin) laden im Frontend ein; Gruppe stimmt ab (gleiche Einstimmigkeits-Logik wie der bereits gebaute Beschlüsse-Service); **Superadmin/wp-admin kann im Backend overrulen** (hart aufnehmen/entfernen, Voting übergehen). Aktuell ist Gruppen-/Mitglieder-Verwaltung nur admin-seitig → muss ins Frontend + User-darf-einladen.

### Abstimmungen/Beschlüsse — bereits WP-konform
Einstimmig (alle approve / eine reject) ODER Mehrheit (alle abgestimmt, approve>reject). In WP gebaut (Beschlüsse-Tab). ✓

### Umfragen — bereits WP-konform
Termin/Auswahl, Ja/Nein/Vielleicht pro Option. In WP gebaut (Umfragen-Tab). ✓

### Gewinnverteilung — App reicher als aktuelle WP-Variante
Quelle: `066_cooperation_agreements.sql` + `database.ts` (ProfitFormula).
- Vereinbarung: `profit_formula` jsonb, `method` ∈ hours|inventory|capital|fixed|**mixed**; mixed-Default-Gewichte `{hours:0.5, inventory:0.3, capital:0.1, fixed:0.1}`. Plus `exit_rules` (forfeit_if_exit_before_event, inventory_return_window_days).
- Beteiligten-Daten: `agreement_roles` (hourly_rate, hours_estimate, capital_contribution, fixed_amount) + `agreement_inventory_contributions` (daily_rate × quantity).
- **System verteilt den Pool automatisch** je Dimension gewichtet → contribution-based, transparent.
- **WP aktuell:** manuell Prozent/Fest (pp_project_profit_shares). Für App-Treue: **formelbasiertes Modell** (Dimensionen Stunden/Inventar/Kapital/Fix + Gewichte, Auto-Berechnung aus Beiträgen) als Erweiterung der Gewinn-Phase. → eigener späterer Lauf.

### Konsequenz für die Roadmap
- Phase 2 „Kollektiv gründen/​beitreten" wird zu **„Gründen + Einladen + Beitritts-Voting"** (Mitglieder laden ein, Gruppe stimmt ab, Superadmin-Override).
- Gewinn-Phase (später) bekommt das **formelbasierte Verteilmodell** statt nur Prozent/Fest.

## Sicherheit & Missbrauch (Backlog — User-Vorgabe 2026-06-14)
- **Sicherheit „unbedingt", aber später als eigener Lauf:** 2FA, Verschlüsselung (at rest / in transit), Härtung. **JETZT beim Member-Portal-Bau aber schon sauber** (security by design): Frontend-Auth korrekt, **per-User-REST-Scoping** (Mitglied sieht/ändert NUR Eigenes + Gruppen-Geteiltes), **kein wp-admin-Zugang für Mitglieder**, Nonces + Capability-Checks auf jeder Route, keine ID-enumerierbaren Leaks.
- **Schneeball/Missbrauch:** Einladungen (Plattform + Gruppe) können sich schneeballartig ausbreiten → evtl. später **Limit: Anzahl Gruppen pro User** und/oder Einladungs-Kontingente. Erst beobachten, Hook/Setting offenhalten.
