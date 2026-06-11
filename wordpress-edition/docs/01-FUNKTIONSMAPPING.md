# Project Prepper — Komplettes Funktionsmapping

> **Stand:** 2026-06-11 · Migration 105 · Commit `e9fe5b8`
> **Zweck:** Vollständige Inventur aller Funktionen als Grundlage für die WordPress-Edition (siehe [02-WORDPRESS-PORTIERUNG.md](02-WORDPRESS-PORTIERUNG.md)).
> **Stack der Quell-App:** Next.js 16 (App Router) · React 19 · TypeScript · Supabase (Auth, PostgreSQL, RLS, Realtime, Storage, Edge Functions) · Tailwind CSS 4 · Cloudflare Worker (CalDAV)

---

## Inhaltsverzeichnis

1. [Gesamtarchitektur & Kernkonzepte](#1-gesamtarchitektur--kernkonzepte)
2. [Auth & User-Lifecycle](#2-auth--user-lifecycle)
3. [Workspace-Modell: Solo XOR Gruppe](#3-workspace-modell-solo-xor-gruppe)
4. [Gruppen & Einstimmigkeits-Voting](#4-gruppen--einstimmigkeits-voting)
5. [Organisationen, Team & Rechte-System](#5-organisationen-team--rechte-system)
6. [Projekte (Liste + 12 Tabs)](#6-projekte-liste--12-tabs)
7. [Kooperationsvereinbarung & Gewinnverteilung](#7-kooperationsvereinbarung--gewinnverteilung)
8. [Inventar](#8-inventar)
9. [Verleih (Rentals)](#9-verleih-rentals)
10. [Sharing & Erträge](#10-sharing--erträge)
11. [Anfragen-Pipeline (Inquiries)](#11-anfragen-pipeline-inquiries)
12. [Telegram-Integration](#12-telegram-integration)
13. [Kalender & CalDAV](#13-kalender--caldav)
14. [Umfragen (Polls)](#14-umfragen-polls)
15. [Kosten-Übersicht & Dashboard](#15-kosten-übersicht--dashboard)
16. [E-Mail-System](#16-e-mail-system)
17. [Admin-Panel, Feedback & DSGVO](#17-admin-panel-feedback--dsgvo)
18. [Querschnittssysteme](#18-querschnittssysteme)
19. [Daten-Inventar (Tabellen, RPCs, Storage, Edge Functions)](#19-daten-inventar)

---

## 1. Gesamtarchitektur & Kernkonzepte

**Was die App ist:** Ein kollaboratives Planungs-Tool für Veranstaltungstechnik-Kollektive: Projekte (Events) planen, Equipment-Inventar verwalten und verleihen, Anfragen-Pipeline führen, Gewinne fair verteilen — mit demokratischen Entscheidungsprozessen (einstimmiges Voting).

**Die fünf tragenden Konzepte:**

1. **Owner-Modell (Solo XOR Gruppe):** Jedes Kern-Objekt (`projects`, `inventory_items`, `inquiries`, `rentals`, `inventory_categories`) hat genau **einen** Owner — entweder einen User (`owner_profile_id`, Solo-Modus) ODER eine Gruppe (`owner_group_id`, Gruppen-Modus). Per DB-CHECK-Constraint erzwungen.
2. **Workspace-Switcher:** Der User arbeitet immer in genau einem Kontext (sein Solo-Workspace oder eine seiner Gruppen). Alle Queries filtern auf den aktiven Owner. Persistenz per Cookie `pp_workspace`.
3. **Einstimmigkeit:** Gruppen-Beitritt und wichtige Beschlüsse erfordern Zustimmung **aller** aktiven Mitglieder. Eine einzige Ablehnung beendet den Vorgang.
4. **Row-Level Security (RLS):** Jede Zugriffskontrolle ist in der Datenbank verankert (Postgres-Policies), nicht nur im Frontend. Helper-Funktionen (`is_group_member()`, `is_admin()`, …) als SECURITY DEFINER.
5. **Realtime-Kollaboration:** Live-Updates über Postgres-Changes-Subscriptions, Presence (wer ist online / wer editiert welches Feld), Auto-Save mit Konflikt-Merging.

**Rollen-Hierarchie:**

| Rolle | Scope | Rechte |
|-------|-------|--------|
| Superadmin (`profiles.is_system`) | App-weit | Alles, überall; Impersonation; Admin-Panel |
| Gründer (`group_memberships.is_founder`) | Pro Gruppe | Gruppen-Settings, erweiterte Rechte; kann Gruppe nicht verlassen; kann Account nicht selbst löschen solange Gruppe aktiv |
| Mitglied (`is_active=true`) | Pro Gruppe | Gleichberechtigt: Daten der Gruppe sehen/bearbeiten, einladen, abstimmen |
| Solo-User | Eigener Workspace | Alles auf eigene Daten |

Zusätzlich: 15 feingranulare Permission-Keys (JSONB auf `org_memberships`) in 6 Gruppen — `projects_view/edit`, `inventory_view/edit`, `excel_export/import`, `costs_view/edit`, `team_view/manage`, `inquiries_view/edit/create`, `polls_view/create`. `null` = Rollen-Default; Admin/Superadmin hat immer alles; die Sidebar filtert Navigationspunkte danach.

---

## 2. Auth & User-Lifecycle

### 2.1 Registrierung
- E-Mail + Passwort (min. 6 Zeichen) + Name → Supabase `signUp` → Bestätigungs-Mail → `/auth/callback` Code-Exchange.
- DB-Trigger `handle_new_user()` legt automatisch `profiles`-Zeile an (Name aus Metadata oder E-Mail-Prefix).
- Trigger `link_group_invitations_to_new_profile()` verknüpft offene Gruppen-Einladungen, die auf diese E-Mail lauteten.
- Offene Org-Einladungen werden verlinkt (Annahme bleibt explizit, kein Auto-Join seit Migration 064).

### 2.2 Login + MFA (TOTP)
- Login mit Passwort; danach prüft die Middleware das **globale MFA-Flag** (`app_settings.mfa_enabled`, Single-Row-Tabelle, nur Superadmin änderbar).
- MFA an + kein verifizierter Faktor → Zwang zu `/mfa/setup` (QR-Code/Secret, 6-stelliger Code, Supabase TOTP-Enrollment).
- MFA an + Faktor vorhanden + Session nicht AAL2 → `/mfa/verify` (Challenge + Verify, hebt Session auf `aal2`).
- Superadmins sind vom MFA-Zwang ausgenommen.
- Redirect-Parameter werden validiert (muss mit `/` beginnen, nicht `//` — Open-Redirect-Schutz).

### 2.3 Passwort vergessen / zurücksetzen
- `/forgot-password` → `resetPasswordForEmail` → Mail mit Recovery-Link → `/reset-password` lauscht auf `PASSWORD_RECOVERY`-Event → neues Passwort (2× eingeben, min. 6 Zeichen) → `updateUser`.

### 2.4 Onboarding (Kollaborationsbasis)
- Pflicht-Flow für neue User: 4 Schritte (Willkommen → "Dein Inventar bleibt dein Eigentum" → "Gewinn wird kollektiv entschieden" → Zustimmungs-Checkbox).
- Speichert Version + Zeitstempel auf `profiles` und Audit-Eintrag in `collaboration_acceptances` (inkl. User-Agent). Middleware erzwingt den Flow.

### 2.5 Profil
- Name, E-Mail (Änderung via Auth-API + Bestätigungsmail), Avatar (Upload mit Client-Komprimierung: WebP, max. 400 px, < 100 KB; altes Avatar wird gelöscht).
- **Inventar-Suffix:** 2–6 Großbuchstaben/Ziffern, wird an Inventarnummern angehängt (`PRO-001-MOT`).
- **Benachrichtigungs-Präferenzen** (`notification_prefs` JSONB): Voting / Polls / Loans, Opt-Out-Modell, vom E-Mail-System respektiert.
- 2FA-Verwaltung (Status, neu einrichten), Telegram-Verknüpfung (siehe §12).

### 2.6 DSGVO-Selbstservice (Migration 105)
- **Datenexport** (Art. 15/20): RPC `export_my_data()` sammelt alle userbezogenen Zeilen aus ~12 Tabellen als JSON → Browser-Download `project-prepper-export-<datum>.json`.
- **Account-Löschung** (Art. 17): RPC `delete_my_account()` — zwei Bestätigungs-Dialoge; **blockiert**, wenn User Gründer einer aktiven Gruppe ist; löscht alle Owner-Daten + `auth.users` (Cascade), setzt nullable Fremdschlüssel (`created_by`, `invited_by`, …) auf NULL.

### 2.7 Einladungs-Flows (Beitritt)
- **Org-Join** (`/join?token=…`): Einladung laden (Status `pending`), akzeptieren → `org_memberships` (Duplikat-tolerant) → Status `accepted`.
- **Pending-Warteseite:** User mit `is_active=false` landen auf `/pending` mit Live-Fortschrittsbalken "X von Y Stimmen" (Realtime auf `team_votes` und `profiles.is_active`).
- **Partner-Invite** (`/partner-invite?token=…`): Cross-Org-Partnerschaft annehmen — User wählt eigene Admin-Org, Annahme erzeugt `org_partnerships` (sofort `active`).

### 2.8 Impersonation (Superadmin)
- Rein clientseitig: `ImpersonateContext` lässt `useCurrentUser()` die Daten eines anderen Users laden; die echte Auth-Session bleibt Superadmin. Banner "Du siehst als …", Admin-Navigation wird währenddessen versteckt.

**Dateien:** `src/app/(auth)/*`, `src/app/onboarding/`, `src/app/auth/callback/`, `src/middleware.ts`, `src/app/(dashboard)/profile/*`, `src/hooks/use-current-user.ts`, `src/contexts/impersonate-context.tsx`
**Tabellen:** `profiles`, `app_settings`, `collaboration_acceptances`, `team_votes`, `org_invitations`, `partnership_invitations`, `telegram_link_tokens`, Supabase-`auth.*`

---

## 3. Workspace-Modell: Solo XOR Gruppe

- **Kontext:** `org-context.tsx` hält den aktiven Workspace (`null` = Solo, sonst Gruppen-ID). Cookie `pp_workspace` (365 Tage, SameSite Lax).
- **Switcher** in der Sidebar: Solo-Workspace → aktive Gruppen → "+ Neue Gruppe". Wechsel setzt Cookie, refresht Router, alle Seiten laden mit neuem Owner-Filter.
- **URL-Priorität:** Auf `/groups/{id}`-Routen gewinnt die URL über das Cookie.
- **Fallback:** Existiert die Gruppe aus dem Cookie nicht mehr (verlassen/archiviert) → Solo + Cookie löschen.
- **Navigation pro Kontext:** Solo zeigt "Mein Inventar / Meine Projekte / …"; Gruppe zeigt zusätzlich Kalender + Umfragen (nur in Gruppen verfügbar).
- **Query-Muster überall:** `WHERE owner_profile_id = userId` (Solo) bzw. `WHERE owner_group_id = groupId` (Gruppe).

---

## 4. Gruppen & Einstimmigkeits-Voting

### 4.1 Gruppe gründen
- `/groups/new`: Name (Pflicht) + Beschreibung; Slug auto-generiert (`slugify(name)-<timestamp36>`), eindeutig, unveränderlich.
- Trigger `handle_new_group()`: Gründer wird aktives Mitglied mit `is_founder=true`. Workspace wechselt sofort auf die neue Gruppe.

### 4.2 Gruppen-Detail (`/groups/[id]`)
- Tabs **Übersicht** (Mitglieder, offene Einladungen mit Voting-Status, Beschlüsse, Presence-Avatare) und **Einstellungen** (nur Gründer): Name, Beschreibung, Logo (WebP-komprimiert, Bucket `group-logos`), Telegram Chat-ID + Thread-ID, Inventar-Suffix (`/^[A-Z0-9]{2,6}$/`).

### 4.3 Einladung + Voting (Status-Maschine)
```
pending ──User lehnt ab──────────────► declined_by_user
pending ──User akzeptiert──► accepted_by_user ──► voting_in_progress
voting_in_progress ──alle approve──► approved   (Membership wird aktiviert)
voting_in_progress ──eine reject───► rejected_by_member
pending ──Einladender zieht zurück─► cancelled
pending ──30 Tage────────────────► expired
```
- Jedes aktive Mitglied darf einladen (E-Mail oder bestehendes Profil). Mail via Edge Function `send-group-invite`.
- Voting: Stimmen `approve`/`reject`/`abstain` in `group_invitation_votes` (UNIQUE pro Voter). **Quorum: 100 % einstimmig** — eine Ablehnung beendet sofort. Trigger `check_group_invitation_complete()` wertet nach jeder Stimme aus und aktiviert bei Erfolg die Mitgliedschaft.
- **Reminder-System:** "Erinnern"-Buttons für (a) den Eingeladenen (Template `group_invite_pending_reminder`, Zähler `send_count`/`last_sent_at`) und (b) noch nicht abstimmende Mitglieder (`voting_reminder_count`).

### 4.4 Mitgliedschaft beenden
- **Verlassen:** Nur Nicht-Gründer; löscht eigene Membership, Workspace fällt auf Solo zurück. Gründer hat keinen Verlassen-Button.
- **Exit-Settlement (Austritts-Abrechnung):** Wizard berechnet eigene + finanzierte Items (RPC `calculate_exit_settlement`), pro Item Aktion `buyout`/`return`/`keep`/`donate`, ergibt Gesamtauslöse; erzeugt einen Beschluss (`org_decisions`, Typ `exit_settlement`) zur Abstimmung.

**Dateien:** `src/app/(dashboard)/groups/*`, `src/components/team/exit-settlement-wizard.tsx`, `src/components/layout/invitation-bell.tsx`
**Tabellen:** `groups`, `group_memberships`, `group_invitations`, `group_invitation_votes`, `exit_settlements`, `exit_settlement_items`

---

## 5. Organisationen, Team & Rechte-System

> Organisationen sind das **Legacy-Modell** (Multi-Tenant), das schrittweise vom Gruppen-Modell abgelöst wird; Teile (E-Mail-Config, Activity-Log, Kalender, Partnerschaften) hängen noch daran.

### 5.1 Team-Verwaltung (`/team`)
- Mitgliederliste mit Avatar, Rolle (Dropdown), Beitrittsdatum, Last-Sign-In, Aktiv-Toggle, Entfernen.
- **Permissions-Editor:** Pro Mitglied aufklappbares Grid mit den 15 Checkboxen (JSONB auf `org_memberships`).
- **Testuser:** RPC `create_test_user()` erzeugt Dummy-Auth-User + Profil + Membership (markiert "(Test)").
- **Entfernen:** RPC `remove_org_member()` (löscht bei Testusern komplett).
- **Last-Admin-Guard:** Der letzte Admin kann weder deaktiviert noch entfernt werden.
- **Impersonation-Button** (Auge-Icon) pro Mitglied.

### 5.2 Aktivitätsprotokoll (`/team/activity`)
- Append-only Audit-Trail `org_activity_log`: ~20 Action-Typen (member.*, project.*, inventory.*, decision.*, guest.*, invitation.*, booking.*), Filter nach Kategorie, Pagination, Metadata-Badges (z. B. alte→neue Rolle). Nur für Admins.

### 5.3 Beschlüsse (`org_decisions`)
- Typen: `general`, `asset_purchase`, `asset_disposal`, `profit_distribution`, `exit_settlement`, `policy`.
- Abstimmung `approve`/`reject`/`abstain` + Kommentar; `requires_unanimous` steuert: einstimmig (eine Ablehnung kippt) oder Mehrheit.
- Trigger `check_decision_complete()` resolved automatisch. RPC `vote_as_user()`: Admin stimmt "im Namen von" ab.

### 5.4 Cross-Org-Partnerschaften
- Org A lädt Org B per E-Mail ein (`partnership_invitations`, Edge Function `send-partnership-invite`); Annahme erzeugt `org_partnerships` (Status `pending→active→paused/ended`).
- Optionen: `share_inventory` (Items mit `is_shareable=true` werden für Partner sichtbar, RPC `get_partner_inventory`), `share_team_contacts`, `allow_equipment_requests`.
- **Equipment-Requests zwischen Partnern:** `equipment_requests` mit Status `pending→approved/rejected→returned`, inkl. Zustand bei Abholung/Rückgabe.

**Dateien:** `src/app/(dashboard)/team/*`, `src/app/(dashboard)/org/page.tsx`, `src/components/org/org-partnerships.tsx`, `src/components/decisions/decision-panel.tsx`, `src/lib/activity-log.ts`
**Tabellen:** `organizations`, `org_memberships`, `org_invitations`, `roles`, `org_decisions`, `org_decision_votes`, `org_activity_log`, `org_partnerships`, `partnership_invitations`, `equipment_requests`

---

## 6. Projekte (Liste + 12 Tabs)

### 6.1 Projektliste (`/projects`)
- Lädt nach Owner-Modell; Filter: Status (`draft/planning/active/completed/cancelled`), View (Alle / Meine = Mitglied in `project_members` / Team = Rest), Volltextsuche; Gruppierung nach Jahr (aus `date_start`).
- Anlegen (Name, Beschreibung, Datum, Status), Status-Schnellwechsel per Dropdown, Löschen mit Bestätigung.

### 6.2 Projekt-Rollen
`project_members.role`: `owner` (alles + Mitglieder verwalten) · `editor` (bearbeiten + Kosten sehen) · `viewer` (lesen + Kosten sehen) · `none` (Projekt sichtbar, **keine** Kosten/Budget). Hook `useProjectRole` liefert `isMember`, `canViewCosts`, `canEditProject`, `isOwner`. Seit Migration 095 dürfen alle Mitglieder Team/Kontakte/Gäste verwalten.

### 6.3 Die 12 Tabs

| # | Tab | Kernfunktionen |
|---|-----|----------------|
| 1 | **Übersicht** | Alle Stammdaten (Venue, Auftraggeber, Termine inkl. Aufbau/Abbau/Show, Budgets, Notizen) mit **Auto-Save**: Field-Level-Dirty-Tracking, 1,5 s Debounce, Save-Indicator (pending→saving→saved), Remote-Merge (nur saubere Felder werden von Realtime-Updates überschrieben), Stale-Data-Banner, Flush bei Tab-Wechsel, 2 s Echo-Cooldown. Budgets nur mit `canViewCosts`, `revenue_actual` nur Owner. |
| 2 | **Zeitplan** | Einträge pro Datum (Titel, Start-/Endzeit, Beschreibung, sort_order), Dauer-Berechnung, **Standard-Ablauf-Vorlage** (Anreise→Aufbau→Soundcheck→Show→Abbau→Abreise, gespeist aus setup/show/teardown-Daten). |
| 3 | **Equipment** | Buchungen (`bookings`: Menge, Von–Bis, Status `reserved→checked_out→returned`). Item-Quellen: eigenes Inventar + Gruppen-Inventar + via `inventory_group_shares` freigegebene Items (Tagessatz aus Share, sonst `cost_per_day`). Approval-Workflow über `useBookingApprovals`. |
| 4 | **Team & Kontakte** | (a) Crew (`project_team`: Name/Rolle/Abteilung mit Abteilungsfarben), (b) externe Kontakte (`project_contacts`, **Vier-Augen-Prinzip**: proposed→confirmed), (c) Projektmitglieder (Panel, s. u.), (d) Gäste aus Org-Pool (`project_guests`: invited/confirmed/declined/attended, plus_ones), (e) Zusagen aus verknüpfter Anfrage (`inquiry_invitations` mit `accepted`). |
| 5 | **Material & Transport** | Verbrauchsmaterial (`project_consumables`: Menge, Einheit aus Liste, Stückkosten, Summen) + Transport-Notizen (Auto-Save in `projects.transport_notes`). |
| 6 | **Kosten** | Kostenposten (`cost_items`: Kategorie personnel/material/inventory/external/other, Plan-/Ist-Betrag, MwSt-Satz, `exclude_from_profit`-Flag). Netto/MwSt/Brutto-Summen; Budget-Vergleich pro Kategorie (Honorar↔personnel, Technik↔inventory+material, Transport↔external) mit Fortschrittsbalken und Überschreitungs-Warnung. Nur mit `canViewCosts`. |
| 7 | **Checklisten** | Checklisten mit Items (sort_order, optimistisches Abhaken mit asynchronem DB-Sync). |
| 8 | **Aufgaben** | Tasks mit Priorität (high/medium/low), Status (todo/in_progress/done), Fälligkeit; Zuweisung an genau einen von drei Typen: Projektmitglied / Crew / Kontakt. **Annahme-Flow**: Zuweisung → `assignment_status=pending` → Notification (`task_notifications`, Bell-UI) → Empfänger akzeptiert/lehnt ab. Filter "Meine Aufgaben". |
| 9 | **Dateien** | Upload per Drag&Drop (JPEG/PNG/WebP/GIF/PDF, max. 10 MB); Bilder → WebP-Komprimierung (max. 0,5 MB, 1200 px, Web Worker); Storage-Pfad `{orgId}/{projectId}/{timestamp}_{safeName}`; Lightbox für Bilder, Download, Löschen (DB + Storage). |
| 10 | **Umfragen** | Projekt-gebundene Polls (`org_polls.project_id`), gleiche Komponenten wie globale Umfragen (§14). |
| 11 | **Vereinbarung** | Kooperationsvereinbarung anzeigen/erstellen/unterschreiben — Details in §7. |
| 12 | **Gewinn** | Gewinnberechnung + Verteilung + Auszahlungs-Beschluss — Details in §7. Nur mit `canViewCosts`. |

### 6.4 Mitglieder-Panel & Einladungen
- Slide-In: Mitglieder (Rollen owner/editor/viewer), offene Einladungen (mit "Erneut senden", `send_count`/`last_sent_at`), Hinzufügen aus Profil-Liste (nur Nicht-Mitglieder), Gäste.
- Einladung: `project_invitations` (`pending`) + Edge Function `send-project-invite` (HTML-Mail, fire-and-forget); Annahme erzeugt `project_members`-Eintrag.

### 6.5 Presence & Realtime
- Presence-Channel `presence:project:{id}`: Online-Avatare + "editiert gerade Feld X" (`editingSection`).
- Alle Tabs nutzen `useRealtimeTable` (postgres_changes mit `project_id`-Filter) für Live-Reloads.

**Dateien:** `src/app/(dashboard)/projects/*`, `src/components/projects/*` (15 Komponenten), `src/hooks/use-project-role.ts`, `use-presence.ts`, `use-field-tracking.ts`, `use-debounced-save.ts`, `use-task-notifications.ts`, `use-booking-approvals.ts`

---

## 7. Kooperationsvereinbarung & Gewinnverteilung

### 7.1 Vereinbarungs-Wizard (4 Schritte)
1. **Beteiligte** wählen (aus Projektmitgliedern, min. 1).
2. **Inventar-Beiträge**: Items + Tagessatz + Menge + Notizen (optional).
3. **Rollen**: pro Person Titel, Verantwortlichkeiten, Stundensatz, Stunden-Schätzung, Kapitaleinlage, Festbetrag.
4. **Formel & Exit-Regeln**: Gewichtungen `hours/inventory/capital/fixed` (müssen 100 % ±1 ergeben), Pre-Deductions (z. B. Lizenzgebühren), Exit-Bedingungen, Freitextklauseln.

Speichern erzeugt: `cooperation_agreements` (Status `signing`) + `agreement_roles` + `agreement_inventory_contributions` + leere `agreement_signatures` für alle Beteiligten.

### 7.2 Signatur-Flow
- Status-Maschine: `draft → signing → active → amended → terminated`.
- Jeder Beteiligte unterschreibt (`signed_at`) oder lehnt ab (`declined_at`), optional mit Kommentar. Alle unterschrieben → `active`.
- Änderungen laufen als `agreement_amendments` über einen Beschluss (`org_decisions`).

### 7.3 Gewinnformel (Tab Gewinn)
```
Brutto-Gewinn = revenue_actual − Σ Kosten (ohne exclude_from_profit)
Netto-Gewinn  = Brutto − Pre-Deductions

Pro Person:
  hours_value     = hours_estimate × hourly_rate
  inventory_value = Σ(daily_rate × quantity × project_days)
  capital_value   = capital_contribution
  fixed_value     = fixed_amount

  anteil = ( hours_share×w_hours + inventory_share×w_inventory
           + capital_share×w_capital + fixed_share×w_fixed ) / Σw

  auszahlung = Netto-Gewinn × anteil
```
- `project_days = (date_end − date_start) + 1` (inklusiv), Fallback 1.
- "Auszahlung starten" erzeugt einen einstimmigen Beschluss `profit_distribution` mit komplettem Verteilungs-Breakdown in den Metadata.
- Bei `profit_distribution_status='distributed'` schreibt ein Trigger Ertrags-Snapshots pro Item (§10.3).

**Dateien:** `src/components/projects/tab-agreement.tsx`, `agreement-wizard.tsx`, `tab-profit.tsx`, `src/lib/agreement-calc.ts`
**Tabellen:** `cooperation_agreements`, `agreement_roles`, `agreement_inventory_contributions`, `agreement_signatures`, `agreement_amendments`, `project_profit_shares`

---

## 8. Inventar

### 8.1 Artikel-Verwaltung
- **Anlegen** (Inline-Form): Name + Kategorie (Pflicht); optional Inventarnummer (sonst Auto), Foto, PDFs, Gerätename, Seriennummer, Kaufpreis, Maße, Leistung (Watt), Zubehör, Tags, Freifeld, Hersteller-/Manual-URL, Lagerort, Menge, Zustand, Tagessatz.
- **Detail-Modal:** Bearbeiten, Foto, Einzelstücke, Freigaben, Erträge, Abschreibungs-Felder.
- **Zustands-Enum:** `new/good/fair/poor/broken/retired` (UI lokalisiert).
- **Foto:** Client-Komprimierung WebP, max. 800 px, < 200 KB, Bucket `inventory-images` (public), Pfad `{itemId}/{timestamp}.webp`.
- **PDFs pro Artikel:** Bucket `inventory-documents` (**privat**, Signed-URLs 60 s), max. 20 MB/Datei, Pfad `{itemId}/{timestamp}.pdf`; RLS über Pfad-Ordner = Item-ID. In der Liste: 1 PDF → direkt öffnen, mehrere → Modal.

### 8.2 Inventarnummern
- Muster `{PREFIX}-{COUNTER}-{SUFFIX}`: Prefix aus Kategorie (z. B. PRO, LIC, AUD …), Counter pro Kategorie auto-inkrementiert (3-stellig gepolstert), Suffix aus Gruppe oder User-Profil (z. B. `-DKS`, `-MOT`). Unique-Constraint gegen Duplikate.

### 8.3 Kategorien
- Dynamisch pro Owner (`inventory_categories`): Name, Emoji-Icon, Prefix, sort_order; Manager-Modal; Auto-Seed mit 10 Default-Kategorien bei leerer Tabelle; Kategorien-Merge (Migration 097).

### 8.4 Einzelstücke (`inventory_units`)
- Optionales Tracking pro Stück bei Menge > 1: fortlaufende `unit_number`, eigener Zustand, Notizen.

### 8.5 Suche, Filter, Statistik
- Echtzeit-Volltextsuche über ~12 Felder (Nummer, Name, Beschreibung, Ort, Tags, Zubehör, URLs, Seriennummer, …); Kategorie-Pills; Filter "Ausgeliehen" (aggregiert aus überlappenden Bookings + Rentals); Pate-Filter per Query-Param.
- KPIs: Artikelzahl, Gesamtteile, ausgeliehen, Tageswert.

### 8.6 Excel-Import/-Export
- **Export:** Gefilterte Liste als XLSX, 19 Spalten. Permission `excel_export`.
- **Import:** Mehrstufig — Datei wählen → Auto-Spalten-Mapping (deutsche Header-Keywords) → Mapping-Editor → Vorschau (inkl. Auto-Nummern) → Batch-Import (20er-Gruppen) mit Fortschritt und Fehlerliste pro Zeile. Zustands-Mapping ("neu"→new, "defekt"→broken …). Permission `excel_import`.

### 8.7 Abschreibung / Eigentum (Tracking-Felder)
- `ownership_type`, `funding_source`, `depreciation_method` (linear/…), `depreciation_years` (Default 7), `residual_value`. Aktuell reine Dokumentation, keine automatische Buchung.

**Dateien:** `src/app/(dashboard)/inventory/page.tsx`, `src/components/inventory/*` (9+ Komponenten)
**Tabellen:** `inventory_items`, `inventory_categories`, `inventory_units`, `inventory_documents`

---

## 9. Verleih (Rentals)

### 9.1 Verleih anlegen
- Header (`rentals`): Leiher (Name Pflicht, E-Mail/Telefon/Adresse), Zeitraum, Kaution, Leihgebühr, Notizen; Owner nach Workspace (Solo XOR Gruppe).
- Equipment-Picker: Verfügbarkeit live per RPC `check_inventory_availability` (Bestand − überlappende Bookings − überlappende Rentals), Owner-Label bei Fremd-Items, Konflikt-Warnung.

### 9.2 Freigabe-Logik pro Position (Trigger bei Insert)
| Konstellation | `approval_status` |
|---|---|
| Eigenes Item | `auto` (agreed_rate = proposed_rate) |
| Gruppen-Item in Gruppen-Verleih | `auto` |
| Fremd-Item, Owner-Modus `open` | `auto` |
| Fremd-Item, Owner-Modus `notify` | `approved` (automatisch, mit Info) |
| Fremd-Item, Owner-Modus `manual` | `pending` (wartet auf Owner) |

- `loan_approval_mode` steht am Inventar-Item. `proposed_rate` Default: Tagessatz aus `inventory_group_shares`, sonst `cost_per_day`.

### 9.3 Tagessatz-Verhandlung & Owner-Freigabe
- Item-Owner sieht pending Positionen (im Verleih-Detail **und** in "Mein Equipment unterwegs" auf der Verleih-Liste, Solo-Sicht) und kann: Tagessatz setzen + **Akzeptieren** (RPC `approve_rental_item`, setzt `agreed_rate`), **Verzichten** (agreed_rate = 0), **Ablehnen** (RPC `reject_rental_item`, mit Grund).
- **Ausgabe-Sperre:** Status kann nicht auf `active` wechseln, solange Positionen `pending` sind.

### 9.4 Status & Abrechnung
- Status-Flow: `reserved → active → returned | cancelled`.
- Bearbeiten mit Diff-Logik (nur geänderte Positionen anfassen, `approval_status` bleibt erhalten).
- **Kostenrechnung:** 19 % USt; Brutto = Leihgebühr (Fallback: Σ agreed_rate × Tage × Menge); Netto/USt ausgewiesen; Kaution = durchlaufender Posten (steuerfrei); **Auszahlung pro Item-Owner** getrennt berechnet (nur `agreed_rate` zählt); Rest verbleibt beim Verleiher.

**Dateien:** `src/app/(dashboard)/rentals/*`, `src/components/rentals/equipment-picker.tsx`
**Tabellen:** `rentals`, `rental_items` · **RPCs:** `check_inventory_availability`, `approve_rental_item`, `reject_rental_item`

---

## 10. Sharing & Erträge

### 10.1 Item-Sharing mit Gruppe (`inventory_group_shares`)
- Owner gibt einzelnes Item für eine Gruppe frei: Tagessatz, Bedingungen (Freitext), Soft-Delete via `revoked_at`, UNIQUE (item, group). Wirkung: Item erscheint im Equipment-Picker der Gruppe.

### 10.2 Gesamtinventar-Freigabe (`inventory_full_shares`)
- Owner-Level: Quelle (User XOR Gruppe) → Ziel (User XOR Gruppe), Default-Tagessatz, `requires_approval_default`, Bedingungen. Self-Share verboten. Wirkung: **alle aktuellen und zukünftigen Items** des Quell-Owners für das Ziel sichtbar (RLS-Erweiterung).

### 10.3 Ertrags-Snapshots (`inventory_item_earnings`)
- Bei Gewinn-Ausschüttung eines Projekts: Trigger/RPC `snapshot_project_earnings` schreibt pro eingebrachtem Item: `gross_contribution = daily_rate × quantity × project_days`, Anteil am Inventar-Pool, `owner_payout = Netto-Gewinn × (w_inventory/Σw) × share_of_inventory`, plus Formel-Snapshot. Idempotent (UNIQUE item+project).

### 10.4 Erträge-Auswertung
- **Übersicht (Modal):** KPIs (Gesamt-Auszahlung, Gesamt erwirtschaftet, aktive Items, Projekt-Einsätze), sortierbare Tabelle (Payout / **ROI %** = payout/Kaufpreis / Projekte), Aggregation pro Kategorie.
- **Pro Item:** Ertrags-Sektion im Detail-Modal.

### 10.5 Leihgaben & Projekt-Grants
- `equipment_loans` (interne Leihgaben mit Fälligkeit), `loan-request-modal` (Leih-Anfrage), `inventory_project_grants` (Item gezielt für ein Projekt freigeben: Menge, optionaler Raten-Override).

**Dateien:** `src/components/inventory/share-with-group-modal.tsx`, `full-share-modal.tsx`, `inventory-earnings-overview.tsx`, `item-earnings-section.tsx`, `equipment-loans-panel.tsx`, `loan-request-modal.tsx`, `src/lib/inventory-earnings.ts`

---

## 11. Anfragen-Pipeline (Inquiries)

### 11.1 Pipeline
- Status: `new → reviewing → offer_sent → accepted | rejected → archived` (Farb-Dots, klickbares Status-Dropdown direkt in der Liste; Detail-Seite hat eine 6-Stufen-Pipeline-Leiste).
- Liste: KPIs (Counts pro Status, Σ Angebotswert), Volltextsuche, Status-Filter, Team-Zusagen-Badge "X/Y zugesagt", Wahrscheinlichkeits-Badge (≥70 grün / ≥30 orange / <30 rot), Follow-Up-Überfälligkeits-Indikator.

### 11.2 Anlegen
- Titel + Kundenname (Pflicht); Kontakt, Venue, Event-Datum von/bis, geschätztes Budget, Wahrscheinlichkeit, Notizen.
- **Ziel-Auswahl:** Solo (nur ich) oder Gruppe → setzt Owner XOR; bei Gruppe mit Telegram-Config wird die Anfrage automatisch in den Telegram-Chat gepostet.

### 11.3 Detail (Auto-Save)
- 6 Karten (Details, Kunde, Event, Angebot, Bewertung, Notizen) mit Field-Tracking + 1,5 s Debounce + Stale-Banner (wie Projekt-Übersicht). Wahrscheinlichkeit als Slider mit Farbverlauf. Permission-Guard `inquiries_view`.

### 11.4 Team-RSVP
- **Self-RSVP-Banner:** "Kannst du mitwirken?" → Ja (`accepted`) / Vielleicht (`pending`) / Nein (`declined`); Upsert auf `inquiry_invitations` (UNIQUE inquiry+profile, Migration 094 erlaubt Self-Insert).
- **Team-Sektion:** Alle Gruppenmitglieder mit Status-Dots (accepted grün, pending orange-pulsierend, declined grau); Ersteller/Admin kann einladen, Einladung zurückziehen, erneut anfragen.

### 11.5 Anfrage → Projekt
- Button "Projekt erstellen": Owner wird geerbt (Gruppe→Gruppe, Solo→Solo), Feld-Mapping (title→name, client_*, venue_*, event_date→date_start/end, estimated_budget→budget_planned mit Fallback offer_amount, offer_amount→revenue_actual, Status `planning`); danach Anfrage auf `accepted` + `project_id` verknüpft, Redirect ins Projekt. Fehler-Dialog bei Misserfolg.

**Dateien:** `src/app/(dashboard)/inquiries/*`, `src/components/inquiries/*`, `src/hooks/use-inquiry-invitations.ts`
**Tabellen:** `inquiries`, `inquiry_invitations`

---

## 12. Telegram-Integration

- **Edge Function `telegram-bot`** (Webhook, --no-verify-jwt):
  - **Anfrage posten** (`?action=send_inquiry`, JWT-geprüft): formatiert Markdown-Nachricht (Titel, Kunde, Datum, Venue, Beschreibung) mit Inline-Buttons (Deep-Links zu `/inquiries/{id}#rsvp` und Detail). Erste Sendung speichert `telegram_message_id`; erneutes Senden **editiert** dieselbe Nachricht (Fallback auf neu senden, wenn Edit scheitert).
  - **`/start`:** Erzeugt One-Time-Link-Token (`telegram_link_tokens`, 32 Byte hex, 15 min gültig) und schickt Verknüpfungs-Link.
  - **`/info`:** Zeigt Verknüpfungs-Status (Name/E-Mail). **`/help`:** Befehlsübersicht.
- **Verknüpfungs-Seite** `/profile/telegram-link?token=…`: validiert Token (nicht verbraucht, nicht abgelaufen), schreibt `profiles.telegram_user_id` (UNIQUE), markiert Token konsumiert. Trennen-Button im Profil setzt auf NULL.
- **Konfiguration:** `groups.telegram_chat_id` (+ optional `telegram_thread_id` für Forum-Topics), von Gründern in den Gruppen-Settings gepflegt.

---

## 13. Kalender & CalDAV

- **Team-Kalender** (nur Gruppen-Kontext): Monats-/Wochenansicht (Apple-Calendar-Stil), Kalender-Gruppen mit Farben (ein-/ausblendbar), Events (Titel, Start/Ende, ganztägig, Ort, Beschreibung), Multi-Day-Events mit Lane-Allokation; **Optimistic Delete**.
- **Synthetische Events:** Equipment-Buchungen (`bookings`) und Verleihe (`rentals`) werden als virtuelle Kalender-Einträge eingeblendet (Status/Leiher sichtbar).
- **iCal-Feed (read-only):** `GET /api/calendar/feed?token=…&group_id=…` — Token-Auth (`calendar_feed_tokens`, 32-Zeichen), RFC-5545-Output; für Google Calendar/Outlook.
- **CalDAV-Zweiwege-Sync:** Standalone **Cloudflare Worker** (`caldav-proxy.post-cd8.workers.dev`) spricht direkt mit der Supabase REST-API (keine Vercel-Abhängigkeit); ETag/CTag-Mechanik; funktioniert mit Apple Calendar, Thunderbird, DAVx5. Debug-Endpoint kann CTags bumpen (erzwingt Re-Sync).
- **Rechte:** Lesen/Erstellen alle Org-Mitglieder; Löschen nur Ersteller oder Admin; Kalender-Gruppen anlegen/löschen nur Admins, Farben für alle änderbar.

**Tabellen:** `calendar_groups`, `calendar_events`, `calendar_feed_tokens`

---

## 14. Umfragen (Polls)

- Zwei Typen: **Terminumfrage** (`date`, Doodle-Grid: Optionen = Datum+Uhrzeit, Stimmen Ja/Nein/Vielleicht) und **Allgemein** (`choice`, Text-Optionen).
- Optionen: Mehrfachauswahl (`allows_multiple`), **anonym** (`is_anonymous` — Namen verborgen, Unique-Prüfung bleibt), Deadline (danach UI gesperrt, Ergebnisse lesbar), Status `active/closed/archived`.
- Scope: gruppenweit (`/polls`) oder projektgebunden (Tab). Erstellen mit Permission `polls_create`; Schließen durch Ersteller oder Admin.
- Bei Erstellung: E-Mail-Notification an aktive Mitglieder (Template `new_poll`, Pref-Key `polls`).
- UNIQUE-Constraint: 1 Stimme pro User+Option.

**Tabellen:** `org_polls`, `org_poll_options`, `org_poll_votes`

---

## 15. Kosten-Übersicht & Dashboard

### 15.1 Globale Kosten (`/costs`)
- Solo: Verweis auf die Projekt-Tabs. Gruppe: alle Projekte + Kostenposten aggregiert; KPIs (Projekte, Σ Plan, Σ Ist, rot bei Überschreitung); Kategorie-Filter; Tabelle mit Projekt-Link.

### 15.2 Dashboard (`/dashboard`)
- Begrüßung + Workspace-Anzeige; KPI-Karten (Inventar/Anfragen/Projekte-Counts).
- **Offene Gruppen-Einladungen** mit Akzeptieren/Ablehnen direkt auf dem Dashboard (Annahme startet Voting + Notification an Mitglieder).
- **Abstimmungs-Aufforderungen** (Einladungen `voting_in_progress`, bei denen man noch nicht gestimmt hat).
- Gruppen-Liste mit Status; "How it works"-Banner für neue User.

---

## 16. E-Mail-System

- **SMTP pro Org/User** (`org_email_config`, Thunderbird-Stil): Host/Port/User/Pass/Security, IMAP-Felder, Absender, optional BCC, `is_enabled`; **Fallback** auf System-Admin-SMTP, wenn deaktiviert. Verbindungstest via Edge Function `test-smtp`.
- **Editierbare Templates** (`email_templates`, Superadmin): Key, Betreff, HTML+Text-Body, `{{variable}}`-Ersetzung, Live-Vorschau. Keys u. a. `group_invite_voting_needed`, `group_invite_pending_reminder`, `new_poll`, `loan_request_received`, `feedback_received`.
- **Edge Functions:** `send-notification` (generischer Dispatcher: template_key + Empfänger-Profile + Vars + optionaler `pref_key`-Filter gegen `notification_prefs`; Best-Effort-Versand), `send-invite-email` (Org: existierender User → In-App, sonst Supabase-Auth-Invite mit `/join`-Link), `send-project-invite`, `send-group-invite`, `send-partnership-invite`, `test-smtp`. Gemeinsame Input-Validierung in `_shared/validation.ts` (requireString/Uuid/Email).

---

## 17. Admin-Panel, Feedback & DSGVO

- **Admin-Panel** (`/admin`, Superadmin-Tabs): User & Gruppen-Übersicht, E-Mail-Templates-Editor, **Feedback-Verwaltung** (Status `new→in_review→resolved/wontfix`, Antwort-Feld, resolved_by/at), Monetarisierungs-Roadmap-Tracker, **System-Tab** (Health-Checks für Supabase DB/Auth/Realtime, Vercel, CalDAV-Worker; DB-Statistiken; Kalender-Debug).
- **Feedback-Modal** (überall erreichbar): Typ Bug/Idee/Sonstiges + Nachricht; Route wird automatisch erfasst; Notification an alle Superadmins.
- **Health-Endpoint** `GET /api/health` (DB-Ping + Latenz, HTTP 200/503) für Uptime-Monitoring.
- **Rechtstexte:** `/agb`, `/datenschutz`, `/impressum`; Error-Boundaries (`error.tsx`, `global-error.tsx`), 404-Seite.

---

## 18. Querschnittssysteme

| System | Umsetzung |
|--------|-----------|
| **Realtime** | `useRealtimeTable` (generische postgres_changes-Subscription mit Spalten-Filter, Callback-Ref-Pattern gegen Re-Subscribes); ~25 Tabellen in der Realtime-Publikation |
| **Presence** | `usePresence` — Channels `presence:project:{id}` / `presence:group:{id}`, Avatare + editingSection |
| **Auto-Save** | `useFieldTracking` (Dirty-Felder, Remote-Merge) + `useDebouncedSave` (1,5 s, Save-State-Maschine, Flush bei Unmount) |
| **Toasts** | `useToast` (success/error/info) |
| **Storage** | 5 Buckets: `inventory-images`, `avatars`, `project-files`, `group-logos` (public) · `inventory-documents` (privat, Signed-URLs); Client-seitige Bildkomprimierung (browser-image-compression, WebP) |
| **RLS** | Owner-XOR-Pattern + SECURITY-DEFINER-Helper (`is_group_member`, `is_group_founder`, `is_admin`, `is_org_admin`, `is_org_member`, `user_can_access_project`, `can_access/edit_inventory_item`); alle mit `SET search_path = public` (Migration 103) |
| **Activity-Log** | `logActivity()`-Helper → `org_activity_log` (append-only) |
| **Icons/UI** | Inline-SVG-Icons (`icons.tsx`), TabBar, Role-Badges, Presence-Avatare; CSS-Variablen + Dark Mode via `prefers-color-scheme` |
| **Sicherheit** | MFA-Enforcement (global schaltbar), Open-Redirect-Schutz, Session-Refresh in Middleware, `is_active`-Gate, FK `ON DELETE SET NULL` (Migration 104) |

---

## 19. Daten-Inventar

### 19.1 Tabellen (≈ 60)

**Identität & Zugriff:** `profiles`, `roles`, `app_settings`, `collaboration_acceptances`, `team_votes`
**Organisationen (Legacy):** `organizations`, `org_memberships`, `org_invitations`, `org_email_config`, `org_activity_log`
**Gruppen:** `groups`, `group_memberships`, `group_invitations`, `group_invitation_votes`
**Beschlüsse & Umfragen:** `org_decisions`, `org_decision_votes`, `org_polls`, `org_poll_options`, `org_poll_votes`
**Projekte:** `projects`, `project_members`, `project_invitations`, `project_schedule`, `project_team_members`/`project_team`, `project_contacts`, `project_guests`, `project_consumables`, `project_checklists`, `project_checklist_items`, `project_tasks`, `task_notifications`, `project_files`, `project_profit_shares`
**Vereinbarungen:** `cooperation_agreements`, `agreement_roles`, `agreement_inventory_contributions`, `agreement_signatures`, `agreement_amendments`
**Inventar:** `inventory_items`, `inventory_categories`, `inventory_units`, `inventory_documents`, `inventory_item_earnings`
**Sharing:** `inventory_group_shares`, `inventory_project_grants`, `inventory_full_shares`, `equipment_loans`
**Verleih & Buchung:** `rentals`, `rental_items`, `bookings`
**Anfragen:** `inquiries`, `inquiry_invitations`
**Partnerschaften:** `org_partnerships`, `partnership_invitations`, `equipment_requests`, `exit_settlements`, `exit_settlement_items`
**Kalender:** `calendar_groups`, `calendar_events`, `calendar_feed_tokens`
**Kosten:** `cost_items`
**System:** `email_templates`, `app_feedback`, `telegram_link_tokens`

### 19.2 DB-Funktionen (RPCs/Trigger, SECURITY DEFINER)
`handle_new_user`, `handle_new_org`, `handle_new_group`, `link_group_invitations_to_new_profile`, `handle_invitation_user_acceptance`, `check_group_invitation_complete`, `check_decision_complete`, `vote_as_user`, `create_test_user`, `remove_org_member`, `delete_user_completely`, `delete_my_account`, `export_my_data`, `snapshot_project_earnings`, `check_inventory_availability`, `approve_rental_item`, `reject_rental_item`, `calculate_exit_settlement`, `get_partner_inventory`, `get_partnership_stats`, Helper: `is_admin`, `is_org_admin`, `is_org_member`, `is_group_member`, `is_group_founder`, `user_can_access_project`

### 19.3 Edge Functions (Deno)
`telegram-bot`, `send-project-invite`, `send-group-invite`, `send-partnership-invite`, `send-notification`, `send-invite-email`, `test-smtp` (+ `_shared/validation.ts`)

### 19.4 Externe Infrastruktur
- **Vercel** (Hosting, Auto-Deploy auf main), **Supabase** (Projekt `wiywvuurxzkctvpwkncj`), **Cloudflare Worker** (CalDAV-Server, standalone), **Telegram Bot API**, beliebiger **SMTP-Server** pro Org/User.

### 19.5 Seiten-Inventar (Routen)
```
/login /pending /join /forgot-password /reset-password /partner-invite
/mfa/setup /mfa/verify /auth/callback /onboarding /org/choose /org/new
/dashboard /projects /projects/[id] /inventory /inquiries /inquiries/[id]
/rentals /rentals/[id] /groups /groups/new /groups/[id] /costs /calendar
/polls /team /team/activity /profile /profile/telegram-link /org /admin
/agb /datenschutz /impressum /api/health /api/calendar/feed|delete|debug
```
