# CLAUDE.md — Project Prepper

> Zentrale Referenz für Claude Code Sessions. Lies diese Datei immer zuerst.

## Projekt-Steckbrief

| Key | Value |
|-----|-------|
| **Name** | Project Prepper |
| **Stack** | Next.js 16.1.6 (App Router) · React 19 · TypeScript 5.9 · Supabase · Tailwind CSS 4.2 |
| **Sprache (UI)** | Deutsch |
| **Supabase** | Auth + PostgreSQL + Realtime + RLS + Storage + Edge Functions |
| **Build** | `npm run build` — Turbopack |
| **Dev** | `npm run dev` |
| **Deployed** | https://project-prepper.dunkelstrom.net (Vercel, Auto-Deploy auf main) |

---

## Architektur

```
src/
├── app/
│   ├── (auth)/login/page.tsx          # Login/Register
│   ├── (auth)/pending/page.tsx        # Warteseite (Freigabe ausstehend)
│   ├── (auth)/join/page.tsx           # Gruppe/Org beitreten
│   ├── (auth)/forgot-password/        # Passwort vergessen
│   ├── (auth)/reset-password/         # Passwort zurücksetzen
│   ├── (auth)/partner-invite/         # Partner-Einladungs-Flow
│   ├── (auth)/mfa/setup|verify/       # MFA einrichten / verifizieren
│   ├── onboarding/page.tsx            # Onboarding-Flow (neue User)
│   ├── org/choose|new/page.tsx        # Org/Workspace wählen / erstellen (Layout außerhalb Dashboard)
│   ├── (dashboard)/                   # Protected layout mit Sidebar
│   │   ├── team/page.tsx              # Team-Verwaltung + Freigaben + Permissions + Impersonation
│   │   ├── team/activity/page.tsx     # Aktivitätsprotokoll
│   │   ├── dashboard/page.tsx         # KPI-Dashboard
│   │   ├── profile/page.tsx           # Profil bearbeiten (Name, Email, Avatar, MFA, DSGVO-Export/Löschung)
│   │   ├── profile/telegram-link/     # Telegram-Account verknüpfen
│   │   ├── projects/page.tsx          # Projektliste
│   │   ├── projects/[id]/page.tsx     # Projekt-Detail (12 Tabs)
│   │   ├── inventory/page.tsx         # Inventar + Excel-Import + Kategorien + Sharing + Erträge
│   │   ├── inquiries/page.tsx         # Anfragen-Pipeline
│   │   ├── inquiries/[id]/page.tsx    # Anfrage-Detail + Team + RSVP
│   │   ├── rentals/page.tsx           # Verleih (externe Leihgaben) — Liste
│   │   ├── rentals/[id]/page.tsx      # Verleih-Detail + Item-Freigaben + Tagessätze
│   │   ├── groups/page.tsx            # Gruppen-Verwaltung
│   │   ├── groups/new/page.tsx        # Neue Gruppe gründen
│   │   ├── groups/[id]/page.tsx       # Gruppen-Detail (Mitglieder, Einladungen, Voting)
│   │   ├── costs/page.tsx             # Globale Kostenübersicht
│   │   ├── calendar/page.tsx          # Kalender + CalDAV
│   │   ├── polls/page.tsx             # Umfragen (Terminumfragen + Allgemein)
│   │   ├── admin/page.tsx             # Admin-Panel (Superadmin-Tabs)
│   │   ├── org/page.tsx               # Org/Workspace-Einstellungen
│   │   └── layout.tsx                 # Sidebar + Main-Wrapper + ImpersonateProvider
│   ├── api/health/route.ts            # Health-Check-Endpoint
│   ├── api/calendar/feed|delete|debug # CalDAV-Feed / Delete / Debug
│   ├── agb|datenschutz|impressum/     # Rechtstexte (Legal-Pages)
│   ├── error.tsx, global-error.tsx    # Error-Boundaries
│   ├── not-found.tsx                  # 404-Seite
│   ├── auth/callback/                 # OAuth Callback
│   ├── globals.css                    # CSS Variables + Dark Mode
│   ├── layout.tsx                     # Root Layout (Inter Font)
│   └── page.tsx                       # Landing → /dashboard oder /login
├── components/
│   ├── layout/
│   │   ├── sidebar.tsx                # Nav + User + Permission-Filter
│   │   ├── top-bar.tsx                # User-Info + Logout
│   │   ├── invitation-bell.tsx        # Einladungs-Dropdown
│   │   ├── impersonate-banner.tsx     # "Du siehst als..." Banner
│   │   ├── org-switcher.tsx           # Workspace/Org/Gruppen-Switcher
│   │   └── online-indicator.tsx       # Online-Status
│   ├── projects/                       # 12 Tab-Komponenten + Panels
│   │   ├── tab-overview.tsx           # Projektdetails + Budget (Auto-Save)
│   │   ├── tab-schedule.tsx           # Zeitplan / Ablauf
│   │   ├── tab-costs.tsx              # Kostenposten + MwSt (nur mit canViewCosts)
│   │   ├── tab-equipment.tsx          # Inventar-Buchungen
│   │   ├── tab-team.tsx               # Team + Kontakte
│   │   ├── tab-materials.tsx          # Verbrauchsmaterial + Transport
│   │   ├── tab-checklists.tsx         # Checklisten
│   │   ├── tab-tasks.tsx              # Aufgaben (Intern/Crew/Extern, Annahme-Flow)
│   │   ├── tab-files.tsx              # Dateien / Grundrisse (Upload + Lightbox)
│   │   ├── tab-polls.tsx              # Umfragen pro Projekt
│   │   ├── tab-profit.tsx             # Gewinnverteilung (nur mit canViewCosts)
│   │   ├── tab-agreement.tsx          # Kooperationsvereinbarung / Vertrag
│   │   ├── agreement-wizard.tsx       # Vereinbarungs-Wizard
│   │   ├── project-members-panel.tsx  # Mitglieder verwalten + Email-Einladung
│   │   └── project-partners-panel.tsx # Partner-Verwaltung
│   ├── rentals/
│   │   └── equipment-picker.tsx       # Equipment-Auswahl für Verleih
│   ├── inquiries/
│   │   ├── inquiry-team-section.tsx   # Team-Zuweisung
│   │   ├── inquiry-rsvp-banner.tsx    # RSVP-Status-Banner
│   │   └── telegram-share-button.tsx  # An Telegram teilen
│   ├── admin/
│   │   ├── users-overview-tab.tsx     # User & Gruppen (Superadmin)
│   │   ├── email-templates-tab.tsx    # Email-Templates (Superadmin)
│   │   ├── feedback-tab.tsx           # User-Feedback (Superadmin)
│   │   └── monetisation-tab.tsx       # Monetarisierungs-Roadmap (Superadmin)
│   ├── polls/                          # poll-card, poll-create-modal, poll-date-grid, poll-choice-view
│   ├── decisions/decision-panel.tsx   # Beschlüsse + Abstimmung + "Im Namen von"
│   ├── org/org-partnerships.tsx       # Cross-Org Partnerschaften
│   ├── team/exit-settlement-wizard.tsx# Austritts-Abrechnung
│   ├── feedback/feedback-modal.tsx    # Feedback-Dialog
│   ├── dashboard/                      # dashboard-card, how-it-works-banner
│   ├── inventory/
│   │   ├── excel-import.tsx           # Mehrstufiger Excel-Import
│   │   ├── inventory-detail-modal.tsx # Detail-Modal (Foto + Bearbeiten)
│   │   ├── inventory-image-upload.tsx # Foto-Upload mit Komprimierung
│   │   ├── inventory-earnings-overview.tsx # Verleih-Erträge gesamt
│   │   ├── item-earnings-section.tsx  # Erträge pro Item
│   │   ├── equipment-loans-panel.tsx  # Leihgaben-Verwaltung
│   │   ├── loan-request-modal.tsx     # Leih-Anfrage
│   │   ├── full-share-modal.tsx       # Gesamtinventar freigeben
│   │   └── share-with-group-modal.tsx # Item mit Gruppe teilen
│   └── ui/
│       ├── icons.tsx                  # Alle SVG-Icons (inline)
│       ├── tabs.tsx                   # TabBar-Komponente
│       ├── role-badge.tsx             # Rollen-Badges (Superadmin/Admin/Manager/Member)
│       └── presence-avatars.tsx       # Online-User Avatare
├── hooks/
│   ├── use-current-user.ts            # Auth User + Org-Rolle + Permissions + isSuperadmin + isOrgAdmin()
│   ├── use-project-role.ts            # Projekt-Rolle (owner/editor/viewer/none) + canViewCosts
│   ├── use-invitations.ts             # Einladungen + accept/decline
│   ├── use-inquiry-invitations.ts     # Anfrage-Team-Einladungen
│   ├── use-task-notifications.ts      # Task-Zuweisungs-Notifications
│   ├── use-booking-approvals.ts       # Buchungs-Freigabe-Workflow
│   ├── use-realtime-table.ts          # Generische Realtime-Subscription
│   ├── use-presence.ts                # Presence Tracking pro Projekt
│   ├── use-debounced-save.ts          # Auto-Save mit Debounce
│   ├── use-field-tracking.ts          # Dirty-State-Tracking für Felder
│   ├── use-project-orgs.ts            # Multi-Org/Gruppen-Projektzugriff
│   └── use-toast.ts                   # Toast-Notifications
├── contexts/
│   ├── org-context.tsx                # Workspace-Switcher (Solo / Org / Gruppe)
│   └── impersonate-context.tsx        # Admin-Impersonation
├── lib/
│   ├── supabase.ts                    # createClient() — Browser
│   ├── supabase-server.ts             # createServerSupabaseClient() — Server
│   └── activity-log.ts                # logActivity() Helper
├── middleware.ts                      # Auth Guard + Session Refresh + is_active Check
└── types/
    └── database.ts                    # Alle TypeScript Types + Permissions
```

---

## Datenbank-Schema

> **Owner-Modell (ab Migration 069–086):** Die App ist von org-zentriert auf **User-First + Gruppen-Overlay** umgestellt. Jedes Kern-Objekt (`inventory_items`, `projects`, `inquiries`, `inventory_categories`) hat **genau einen** Owner — entweder `owner_profile_id` (Solo-Modus) **XOR** `owner_group_id` (Gruppen-Modus), per CHECK-Constraint erzwungen. `org_id` ist Legacy/nullable. RLS gewährt Zugriff, wenn der User Owner ist ODER aktives Gruppen-Mitglied. Siehe Skills `/modi`, `/ownership`, `/rls`.

### Tabellen

| Tabelle | Zweck | Key-Felder |
|---------|-------|------------|
| `roles` | Org-Rollen (admin/manager/member) | name, permissions (JSONB) |
| `profiles` | User-Profile (extends auth.users) | id, email, name, role_id, is_active, is_system (Superadmin), avatar_url |
| `team_votes` | Abstimmungen für Neubeitritte | candidate_id, voter_id, org_id, UNIQUE |
| `projects` | Projekte mit Venue/Client/Budget | **owner_profile_id XOR owner_group_id**, group_id, status, date_start/end, venue_*, client_*, budget_*, revenue_actual |
| `inventory_items` | Equipment-Inventar | inventory_number (auto, +User/Group-Suffix), **owner_profile_id XOR owner_group_id**, name, category, tags[], quantity, condition, cost_per_day, loan_approval_mode, current_value |
| `inventory_categories` | Dynamische Kategorien | **owner_profile_id XOR owner_group_id**, name, icon (Emoji), prefix, sort_order |
| `inventory_units` | Einzelstücke-Tracking | item_id, unit_number, condition, notes |
| `bookings` | Equipment-Reservierungen | project_id, inventory_item_id, quantity, date_from/to, status, approval_status |
| `cost_items` | Kostenposten pro Projekt | project_id, category, amount_planned, amount_actual, vat_rate |
| `project_schedule` | Zeitplan-Einträge | project_id, title, schedule_date, time_start/end, sort_order |
| `project_team_members` | Team-Mitglieder | project_id, name, role, department |
| `project_contacts` | Externe Kontakte | project_id, name, role, company |
| `project_consumables` | Verbrauchsmaterial | project_id, name, quantity, unit, cost |
| `project_checklists` | Checklisten | project_id, name, sort_order |
| `project_checklist_items` | Checklist-Items | checklist_id, label, checked, sort_order |
| `project_members` | Projekt-Mitgliedschaft | project_id, profile_id, role (owner/editor/viewer) |
| `project_invitations` | Einladungen | project_id, invited_profile_id, role, status, send_count, last_sent_at |
| `project_tasks` | Aufgaben pro Projekt | project_id, title, status, priority, assigned_to, assignment_status, due_date |
| `task_notifications` | Task-Zuweisungs-Notifications | task_id, profile_id, type, is_read |
| `project_files` | Projekt-Dateien | project_id, org_id, file_name, file_url, file_type, uploaded_by |
| `project_profit_shares` | Gewinnverteilung | project_id, profile_id, share_type, calculated_amount |
| `organizations` | Multi-Tenant Orgs | name, slug, description, logo_url, telegram_chat_id |
| `org_memberships` | User→Org Zugehörigkeit | org_id, profile_id, role_id, is_active, permissions (JSONB) |
| `org_invitations` | Org-Einladungen | org_id, email, invited_by, role_id, status |
| `org_decisions` | Beschlüsse/Abstimmungen | org_id, title, decision_type, status, requires_unanimous, related_project_id |
| `org_decision_votes` | Stimmen zu Beschlüssen | decision_id, voter_id, vote (approve/reject/abstain), comment |
| `org_polls` | Umfragen (Termin + Allgemein) | org_id, project_id, title, poll_type (date/choice), status, deadline |
| `org_poll_options` | Umfrage-Optionen | poll_id, label, date_value, time_value, sort_order |
| `org_poll_votes` | Umfrage-Stimmen | poll_id, option_id, voter_id, vote (yes/no/maybe) |
| `org_email_config` | SMTP/IMAP pro Org | org_id, smtp_host/port/user/pass/security/auth, imap_*, sender_*, bcc_email |
| `org_activity_log` | Aktivitätsprotokoll | org_id, actor_id, action, entity_type, entity_id, metadata |
| `inquiries` | Projektanfragen-Pipeline | **owner_profile_id XOR owner_group_id**, group_id, status, client_*, title, venue_*, event_date_*, offer_*, telegram_message_id |
| `inquiry_invitations` | Anfrage-Team | inquiry_id, invited_profile_id, status |
| `equipment_loans` | Leihgaben | org_id, inventory_item_id, borrower_id, status, due_date |
| `org_partnerships` | Cross-Org Partnerschaften | org_id, partner_org_id, status, share_inventory |
| `equipment_requests` | Partner-Equipment-Anfragen | requesting_org_id, supplying_org_id, inventory_item_id, status |
| `calendar_groups` | Kalender-Gruppen | org_id, name, color |
| `calendar_events` | Kalender-Events | group_id, title, start_date, end_date, etag |
| `exit_settlements` | Austritts-Auslöse | org_id, profile_id, status, total_payout |

#### Gruppen (Migration 069+)

| Tabelle | Zweck | Key-Felder |
|---------|-------|------------|
| `groups` | Kollektive Workspaces | name, slug, founded_by, description, logo_url, archived_at, inventory_suffix, telegram_chat_id |
| `group_memberships` | Mitgliedschaft + Voting-Status | group_id, profile_id, is_active, is_founder, joined_at, left_at |
| `group_invitations` | Einladung mit Voting-Phase | group_id, invited_by, invited_email/profile_id, status (pending→accepted_by_user→voting_in_progress→approved/rejected) |
| `group_invitation_votes` | Stimme pro Mitglied | invitation_id, voter_id, vote (approve/reject/abstain) |

#### Verleih / Sharing / Erträge (Migrationen 071, 090, 092, 098)

| Tabelle | Zweck | Key-Felder |
|---------|-------|------------|
| `rentals` | Externe Leihgaben (Header) | **owner_profile_id XOR owner_group_id**, borrower_name/email/phone, date_from/to, status (reserved/active/returned/cancelled), deposit_amount, rental_fee |
| `rental_items` | Equipment-Zeilen pro Verleih | rental_id, inventory_item_id, unit_id, quantity, approval_status (auto/pending/approved/rejected), proposed_rate, agreed_rate |
| `inventory_group_shares` | Item mit Gruppe teilen | inventory_item_id, group_id, daily_rate, requires_approval, conditions_tags, revoked_at |
| `inventory_project_grants` | Item für Projekt freigeben | inventory_item_id, project_id, quantity_allowed, daily_rate |
| `inventory_full_shares` | Gesamtinventar-Freigabe (Owner-Level) | source_owner_*, target_*, daily_rate_default, requires_approval_default |
| `inventory_item_earnings` | Ertrags-Snapshot bei Projektabschluss | item_id, project_id, agreement_id, daily_rate, gross_contribution, owner_payout, formula_snapshot |

#### Vereinbarungen / Kooperation (Migrationen 065–067)

| Tabelle | Zweck | Key-Felder |
|---------|-------|------------|
| `cooperation_agreements` | Formale Projekt-Beteiligung | project_id, status, profit_formula (jsonb), exit_rules (jsonb), version |
| `agreement_inventory_contributions` | Beigesteuerte Items | agreement_id, inventory_item_id, contributor_id, daily_rate, quantity |
| `agreement_roles` | Beteiligten-Rollen | agreement_id, profile_id, role_title, hourly_rate, capital_contribution |
| `agreement_signatures` | Signatur-Tracking | agreement_id, profile_id, signed_at, declined_at |
| `agreement_amendments` | Änderungen via Beschluss | agreement_id, decision_id, changes_json, status |
| `partnership_invitations` | Partner-Einladung per Email | org_id, invited_by, email, status, share_inventory |
| `collaboration_acceptances` | Zustimmung zu Kollab-AGB | profile_id, version, accepted_at |

#### Sonstiges (Migrationen 079, 087, 088)

| Tabelle | Zweck | Key-Felder |
|---------|-------|------------|
| `email_templates` | Editierbare Email-Templates | key (PK), subject, html_body, text_body, available_vars[] |
| `app_feedback` | User-Feedback an Superadmin | profile_id, feedback_type (bug/idea/other), message, app_route, status |
| `app_settings` | Globale Single-Row-Config | id (immer true), mfa_enabled |

---

## Zugriffssystem

### Owner-Modi (Solo XOR Gruppe)
Jeder User hat einen **Solo-Workspace** (eigene Daten via `owner_profile_id`). Zusätzlich kann er in **Gruppen** sein (Daten via `owner_group_id`). Der Workspace-Switcher (`org-context.tsx`) bestimmt den aktiven Kontext; Queries filtern auf den jeweiligen Owner.
- **Gruppen-Beitritt** läuft über Einladung + **Voting** der bestehenden Mitglieder (einstimmig). Kein Auto-Join.
- **Gruppen-Gründer** (`is_founder`) hat erweiterte Rechte; aktive Gründer können ihren Account nicht via DSGVO-Self-Service löschen.
- RLS-Helper `is_group_member()` / `is_group_founder()` (SECURITY DEFINER) verhindern Rekursion.

### Rollen-Hierarchie

| Rolle | Scope | Rechte |
|-------|-------|--------|
| **Superadmin** | App-weit | Alle Orgs sehen/verwalten, alle Rechte überall. Flag: `profiles.is_system = true` |
| **Admin** | Pro Org | Org verwalten, Rollen/Permissions, Team, E-Mail-Config |
| **Manager** | Pro Org | Projekte CRUD, Inventar CRU, Kosten CRUD |
| **Member** | Pro Org | Eingeschränkt per feingranularen Permissions |

### Admin-Check im Code
```typescript
// Zentrale Funktion — IMMER diese nutzen:
import { isOrgAdmin } from "@/hooks/use-current-user";
const isAdmin = isOrgAdmin(currentUser); // true für Admin-Rolle ODER Superadmin
```

### Feingranulare Permissions (pro User, Migration 033)
JSONB `permissions` auf `org_memberships` — 15 Checkboxen in 6 Gruppen:
- **Projekte:** `projects_view`, `projects_edit`
- **Inventar:** `inventory_view`, `inventory_edit`, `excel_export`, `excel_import`
- **Finanzen:** `costs_view`, `costs_edit`
- **Team:** `team_view`, `team_manage`
- **Anfragen:** `inquiries_view`, `inquiries_edit`, `inquiries_create`
- **Umfragen:** `polls_view`, `polls_create`

`null` = Rollen-Default, Admin/Superadmin hat immer alles. Sidebar filtert Nav-Items.

### Projekt-Rollen (project_members.role)
| Rolle | Rechte |
|-------|--------|
| `owner` | Alles + Mitglieder verwalten + einladen |
| `editor` | Projekt bearbeiten + Kosten sehen |
| `viewer` | Nur lesen + Kosten sehen |
| `none` | Projekt sichtbar, aber KEINE Kosten/Budget |

---

## Edge Functions (Supabase)

| Function | Zweck | JWT |
|----------|-------|-----|
| `telegram-bot` | Anfragen an Telegram-Gruppe, /start, /info, /help | --no-verify-jwt |
| `send-project-invite` | HTML-Email bei Projekt-Einladung (nodemailer) | --no-verify-jwt |
| `send-group-invite` | Email bei Gruppen-Einladung | --no-verify-jwt |
| `send-partnership-invite` | Email bei Partner-Einladung | --no-verify-jwt |
| `send-notification` | Generischer Notification-Dispatcher | --no-verify-jwt |
| `test-smtp` | SMTP-Verbindungstest mit Test-Email | --no-verify-jwt |
| `send-invite-email` | Org-Einladung via Supabase Auth | Standard |

> `_shared/validation.ts` — gemeinsame Input-Validierung für Edge Functions.

### Edge Function Deploy
```bash
SUPABASE_ACCESS_TOKEN=$(security find-generic-password -s "supabase-deploy-token" -w) \
  npx supabase functions deploy <function-name> --no-verify-jwt --project-ref wiywvuurxzkctvpwkncj
```

---

## Migrations (001–105)

| # | Beschreibung |
|---|-------------|
| 001 | Initial Schema (Roles, Profiles, Projects, Inventory, Bookings, Costs) |
| 002–003 | Seed-Daten |
| 004–007 | Inventar-Erweiterungen (Owner, Conditions, Nummern) |
| 008 | Project Event-Hub (Schedule, Team, Contacts, Consumables, Checklists) |
| 009–011 | Budget-Kategorien, MwSt, Realtime |
| 012 | Projekt-Mitgliedschaft + Einladungen + RLS |
| 013–016 | Storage Buckets, Tasks, Team-Approval, Avatare |
| 017–018 | System-User (is_system), is_admin() erweitert |
| 019–020 | Organizations (Multi-Tenant), Cross-Org Collaboration |
| 025–029 | Inventar-Details, URLs, Einzelstücke, Kategorien |
| 030–034 | Org-Einladungen, Testuser, Permissions, Projekt-Dateien |
| 036–041 | Eigentum, Beschlüsse, Austritt, Gewinn, Leihgaben, Partnerschaften |
| 042–050 | RLS-Fixes, Gäste, Admin-Voting, CalDAV, Activity-Log |
| 051–056 | Kalender (CalDAV, Feed-Tokens, Delete-Policy), Member Last Sign-In |
| 057 | Umfragen (org_polls, org_poll_options, org_poll_votes) |
| 058–061 | Email-System (org_email_config, SMTP/IMAP, BCC, Security, RLS-Fix) |
| 062 | Einladung send_count + last_sent_at (Erneut senden) |
| 063–064 | Org-Einladung erfordert Admin-Freigabe; Auto-Join-Trigger entfernt |
| 065–067 | Partnership-Einladungen, Kooperationsvereinbarungen, Kollab-Zustimmung |
| **068** | **COMPLETE RESET** — alle Non-Superadmin-User + Daten gelöscht (Schema bleibt) |
| **069–072** | **Groups-Schema** (groups, memberships, invitations, votes) + Voting-Trigger |
| **070, 073** | **User-First Owner-Modell** (`owner_profile_id`) + RLS auf User-First umgestellt |
| 071, 081 | Equipment-Sharing User↔Gruppe (group_shares, project_grants, Conditions) |
| 074 | Alte Project-Trigger entfernt + `project_orgs` gedroppt |
| 075–076 | group_id auf Polls/Decisions/Calendar; email_config wird user-level |
| 077–078 | `delete_user_completely()` RPC (v2) |
| 079 | app_settings (mfa_enabled) |
| 080 | Group-Einladungen beim Signup verlinken |
| 082–086 | Gruppen-Settings, Inventar-Nummer-Suffix (User/Group/-DKS), **Group-Ownership XOR** |
| 087–088 | Editierbare Email-Templates; App-Feedback + Notification-Prefs |
| 089–093 | Verleih-Anfragen user-level, Gesamt-Sharing, Item-Erträge-Snapshot, Group-Logos-Bucket |
| 094–097 | Inquiry Self-RSVP, Projekt-Team-Vereinfachung, Inventar-Tags, Kategorien-Merge |
| **098–101** | **Verleih (Rentals)** — Header + Lines, Item-Freigaben, RLS-Fix, Tagessatz-Verhandlung |
| 102–104 | Security: project_files-RLS, search_path für SECURITY DEFINER, FK ON DELETE SET NULL |
| **105** | **DSGVO-Self-Service** — `delete_my_account()` + `export_my_data()` |

---

## Coding Conventions

### Styling
- **CSS Variables** für alle Farben: `var(--color-primary)`, `var(--color-surface)` etc.
- **Inline `style={{}}`** für dynamische/theme Farben, Tailwind für Layout
- **Kein** CSS-in-JS Library, keine Styled Components
- Dark Mode über `prefers-color-scheme` in globals.css

### State Management
- **Kein** Redux/Zustand — alles `useState` + `useCallback`
- Supabase Queries in `useCallback`, getriggert via `useEffect`
- Formulare: raw `useState` pro Feld, `e.preventDefault()` Handler

### Components
- Alle `"use client"` — kein Server Component Rendering für interaktive Seiten
- Icons: Inline SVG in `icons.tsx`, einheitliches `IconProps` Interface
- Tabs: Dynamische Tab-Arrays, conditional rendering

### Realtime
- `useRealtimeTable` für postgres_changes (INSERT/UPDATE/DELETE)
- `usePresence` für Online-Status pro Projekt
- Callback-Ref Pattern um Re-Subscriptions zu vermeiden

### Sprache
- UI-Texte: **Deutsch**
- Code/Variablen: **Englisch**
- Kommentare: **Deutsch oder Englisch** (gemischt)

---

## Storage (Supabase)

| Bucket | Zweck | Public |
|--------|-------|--------|
| `inventory-images` | Fotos für Inventar-Artikel | Ja |
| `avatars` | Profilbilder für User | Ja |
| `project-files` | Projekt-Dateien (Grundrisse, PDFs) | Ja (RLS, Migration 102) |
| `group-logos` | Logos für Gruppen | Ja |

Bilder werden client-seitig komprimiert via `browser-image-compression`:
- **Inventar:** max 800px, WebP, <200KB → `{itemId}/{timestamp}.webp`
- **Avatare:** max 400px, WebP, <100KB → `{userId}/{timestamp}.webp`

---

## DB-Funktionen (SECURITY DEFINER RPCs)

| Funktion | Zweck |
|----------|-------|
| `handle_new_user()` | Trigger: Profil erstellen bei neuem Auth-User |
| `handle_new_org()` | Trigger: Rollen kopieren + Creator als Admin |
| `create_test_user(org_id, name, email, role_id)` | RPC: Dummy auth.users + profiles + org_membership |
| `remove_org_member(org_id, profile_id, delete_user)` | RPC: Membership löschen, bei Testusern komplett |
| `vote_as_user(decision_id, voter_id, vote, comment)` | RPC: Admin stimmt im Namen eines anderen Users ab |
| `check_decision_complete()` | Trigger: Auto-Resolve bei einstimmiger/Mehrheits-Abstimmung |
| `is_admin()` | Helper: Admin-Rolle ODER is_system Check für RLS |
| `is_org_admin(org_id)` | Helper: Org-Admin ODER Superadmin Check |
| `is_org_member(org_id)` | Helper: Org-Mitglied ODER Superadmin |
| `is_group_member(group_id)` / `is_group_founder(group_id)` | Helper: Gruppen-Mitglied / Gründer (verhindert RLS-Rekursion) |
| `handle_new_group()` | Trigger: Gründer als aktives Mitglied bei Gruppen-Erstellung |
| `check_group_invitation_complete()` | Trigger: aktiviert Mitgliedschaft bei einstimmigem Voting |
| `handle_invitation_user_acceptance()` | Trigger: startet Voting / Sofort-Approval bei Annahme |
| `link_group_invitations_to_new_profile()` | Trigger: offene Email-Einladungen beim Signup verlinken |
| `delete_user_completely(user_id)` | RPC (Superadmin): User + Cascade hart löschen |
| `snapshot_project_earnings(project_id)` | RPC: Ertrags-Snapshots für alle Items eines Projekts |
| `check_inventory_availability(item_id, from, to, ...)` | Verfügbarkeit über Bookings + Rentals |
| `approve_rental_item()` / `reject_rental_item()` | RPC: Item-Owner gibt Verleih-Position frei / lehnt ab |
| `user_can_access_project(project_id)` | Helper: einheitlicher Projekt-Zugriffscheck (Migration 102) |
| `delete_my_account()` / `export_my_data()` | RPC: DSGVO-Self-Service (Art. 17 / Art. 15+20) |

> Alle SECURITY DEFINER Functions haben `SET search_path = public` (Migration 103). Auto-Join-Trigger wurde in 064 entfernt.

---

## Multi-Machine Workflow

Dieses Projekt wird von **mehreren Rechnern** aus bearbeitet (gleicher GitHub-Account, SSH-Auth).

### Zwei Entwicklungsebenen (Branches)

| Branch | Zweck |
|--------|-------|
| `main` | Haupt-App (Next.js/Supabase) — deployt automatisch auf Vercel |
| `wordpress-edition` | WordPress-Plugin-Entwicklung (Doku: `wordpress-edition/`) |

### Session-Start (PFLICHT)
1. **User fragen, welcher Branch bearbeitet werden soll** (`main` oder `wordpress-edition`)
2. Dann:
```bash
git checkout <branch>
git pull origin <branch>
```
**Immer zuerst pullen**, bevor Änderungen gemacht werden.

### Session-Ende
```bash
git add .
git commit -m "Beschreibung der Änderungen"
git push origin <branch>
```

### Regeln
- **Zwei Branches:** `main` (App) + `wordpress-edition` (WordPress) — darüber hinaus keine Feature-Branches, kein PR-Workflow
- App-Änderungen → `main`; WordPress-Code → `wordpress-edition`; Doku in `wordpress-edition/docs/` → `main`
- **Immer pushen** am Ende einer Session (auf den aktiven Branch)
- **Immer pullen** am Anfang einer Session

---

## Environment

```env
# .env.local
NEXT_PUBLIC_SUPABASE_URL=https://wiywvuurxzkctvpwkncj.supabase.co
NEXT_PUBLIC_SUPABASE_ANON_KEY=eyJ...
```

### Supabase Edge Function Secrets
- `APP_URL` = `https://project-prepper.dunkelstrom.net`
- `TELEGRAM_BOT_TOKEN` — Telegram Bot
- `TELEGRAM_WEBHOOK_SECRET` — Webhook-Verifizierung
- `SUPABASE_SERVICE_ROLE_KEY` — für Edge Functions (automatisch)

### Deploy-Token
Supabase Access Token in macOS Keychain: `supabase-deploy-token`
```bash
security find-generic-password -s "supabase-deploy-token" -w
```
