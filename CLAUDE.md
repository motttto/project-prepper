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
│   ├── (dashboard)/                   # Protected layout mit Sidebar
│   │   ├── team/page.tsx              # Team-Verwaltung + Freigaben + Permissions + Impersonation
│   │   ├── team/activity/page.tsx     # Aktivitätsprotokoll
│   │   ├── dashboard/page.tsx         # KPI-Dashboard
│   │   ├── profile/page.tsx           # Profil bearbeiten (Name, Email, Avatar)
│   │   ├── profile/telegram-link/     # Telegram-Account verknüpfen
│   │   ├── projects/page.tsx          # Projektliste
│   │   ├── projects/[id]/page.tsx     # Projekt-Detail (11 Tabs)
│   │   ├── inventory/page.tsx         # Inventar + Excel-Import + Kategorien-Manager
│   │   ├── inquiries/page.tsx         # Anfragen-Pipeline
│   │   ├── inquiries/[id]/page.tsx    # Anfrage-Detail + Team
│   │   ├── costs/page.tsx             # Globale Kostenübersicht
│   │   ├── calendar/page.tsx          # Kalender + CalDAV
│   │   ├── polls/page.tsx             # Umfragen (Terminumfragen + Allgemein)
│   │   ├── admin/page.tsx             # Admin-Panel (5 Tabs)
│   │   ├── org/page.tsx               # Org-Einstellungen
│   │   ├── org/new/page.tsx           # Neue Organisation erstellen
│   │   └── layout.tsx                 # Sidebar + Main-Wrapper + ImpersonateProvider
│   ├── auth/callback/                 # OAuth Callback
│   ├── globals.css                    # CSS Variables + Dark Mode
│   ├── layout.tsx                     # Root Layout (Inter Font)
│   └── page.tsx                       # Landing → /dashboard oder /login
├── components/
│   ├── layout/
│   │   ├── sidebar.tsx                # Nav + User + Permission-Filter
│   │   ├── top-bar.tsx                # User-Info + Logout
│   │   ├── invitation-bell.tsx        # Einladungs-Dropdown
│   │   └── impersonate-banner.tsx     # "Du siehst als..." Banner
│   ├── projects/
│   │   ├── tab-overview.tsx           # Projektdetails + Budget (Auto-Save)
│   │   ├── tab-schedule.tsx           # Zeitplan / Ablauf
│   │   ├── tab-costs.tsx              # Kostenposten + MwSt
│   │   ├── tab-equipment.tsx          # Inventar-Buchungen
│   │   ├── tab-team.tsx               # Team + Kontakte
│   │   ├── tab-materials.tsx          # Verbrauchsmaterial + Transport
│   │   ├── tab-checklists.tsx         # Checklisten
│   │   ├── tab-tasks.tsx              # Aufgaben (Intern/Crew/Extern, Annahme-Flow)
│   │   ├── tab-files.tsx              # Dateien / Grundrisse (Upload + Lightbox)
│   │   ├── tab-polls.tsx              # Umfragen pro Projekt
│   │   ├── tab-profit.tsx             # Gewinnverteilung
│   │   └── project-members-panel.tsx  # Mitglieder verwalten + Email-Einladung
│   ├── polls/
│   │   ├── poll-card.tsx              # Umfrage-Karte mit expandierbarer Abstimmung
│   │   ├── poll-create-modal.tsx      # Erstellen-Dialog (Typ-Wahl, Optionen, Deadline)
│   │   ├── poll-date-grid.tsx         # Doodle-Grid (Ja/Nein/Vielleicht)
│   │   └── poll-choice-view.tsx       # Checkbox-Umfrage mit Ergebnis-Balken
│   ├── decisions/
│   │   └── decision-panel.tsx         # Beschlüsse + Abstimmung + "Im Namen von"
│   ├── inventory/
│   │   ├── excel-import.tsx           # Mehrstufiger Excel-Import
│   │   ├── inventory-detail-modal.tsx # Detail-Modal (Foto + Bearbeiten)
│   │   └── inventory-image-upload.tsx # Foto-Upload mit Komprimierung
│   └── ui/
│       ├── icons.tsx                  # Alle SVG-Icons (inline)
│       ├── tabs.tsx                   # TabBar-Komponente
│       ├── role-badge.tsx             # Rollen-Badges (Superadmin/Admin/Manager/Member)
│       └── presence-avatars.tsx       # Online-User Avatare
├── hooks/
│   ├── use-current-user.ts            # Auth User + Org-Rolle + Permissions + isSuperadmin + isOrgAdmin()
│   ├── use-project-role.ts            # Projekt-Rolle (owner/editor/viewer/admin/none)
│   ├── use-invitations.ts             # Einladungen + accept/decline
│   ├── use-task-notifications.ts      # Task-Zuweisungs-Notifications
│   ├── use-realtime-table.ts          # Generische Realtime-Subscription
│   └── use-presence.ts                # Presence Tracking pro Projekt
├── contexts/
│   ├── org-context.tsx                # Org-Switcher Context
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

### Tabellen

| Tabelle | Zweck | Key-Felder |
|---------|-------|------------|
| `roles` | Org-Rollen (admin/manager/member) | name, permissions (JSONB) |
| `profiles` | User-Profile (extends auth.users) | id, email, name, role_id, is_active, is_system (Superadmin), avatar_url |
| `team_votes` | Abstimmungen für Neubeitritte | candidate_id, voter_id, org_id, UNIQUE |
| `projects` | Projekte mit Venue/Client/Budget | status, date_start/end, venue_*, client_*, budget_*, revenue_actual |
| `inventory_items` | Equipment-Inventar | inventory_number (auto), name, category, quantity, condition, cost_per_day, ownership_type, current_value |
| `inventory_categories` | Dynamische Kategorien | org_id, name, icon (Emoji), prefix, sort_order |
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
| `inquiries` | Projektanfragen-Pipeline | org_id, status, client_*, title, venue_*, event_date_*, offer_*, telegram_message_id |
| `inquiry_invitations` | Anfrage-Team | inquiry_id, invited_profile_id, status |
| `equipment_loans` | Leihgaben | org_id, inventory_item_id, borrower_id, status, due_date |
| `org_partnerships` | Cross-Org Partnerschaften | org_id, partner_org_id, status, share_inventory |
| `equipment_requests` | Partner-Equipment-Anfragen | requesting_org_id, supplying_org_id, inventory_item_id, status |
| `calendar_groups` | Kalender-Gruppen | org_id, name, color |
| `calendar_events` | Kalender-Events | group_id, title, start_date, end_date, etag |
| `exit_settlements` | Austritts-Auslöse | org_id, profile_id, status, total_payout |

---

## Zugriffssystem

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
| `test-smtp` | SMTP-Verbindungstest mit Test-Email | --no-verify-jwt |
| `send-invite-email` | Org-Einladung via Supabase Auth | Standard |

### Edge Function Deploy
```bash
SUPABASE_ACCESS_TOKEN=$(security find-generic-password -s "supabase-deploy-token" -w) \
  npx supabase functions deploy <function-name> --no-verify-jwt --project-ref wiywvuurxzkctvpwkncj
```

---

## Migrations (001–062)

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
| `project-files` | Projekt-Dateien (Grundrisse, PDFs) | Ja |

Bilder werden client-seitig komprimiert via `browser-image-compression`:
- **Inventar:** max 800px, WebP, <200KB → `{itemId}/{timestamp}.webp`
- **Avatare:** max 400px, WebP, <100KB → `{userId}/{timestamp}.webp`

---

## DB-Funktionen (SECURITY DEFINER RPCs)

| Funktion | Zweck |
|----------|-------|
| `handle_new_user()` | Trigger: Profil erstellen bei neuem Auth-User |
| `handle_new_org()` | Trigger: Rollen kopieren + Creator als Admin |
| `handle_org_invitation_auto_join()` | Trigger: Bei Registrierung offene Einladungen auto-akzeptieren |
| `create_test_user(org_id, name, email, role_id)` | RPC: Dummy auth.users + profiles + org_membership |
| `remove_org_member(org_id, profile_id, delete_user)` | RPC: Membership löschen, bei Testusern komplett |
| `vote_as_user(decision_id, voter_id, vote, comment)` | RPC: Admin stimmt im Namen eines anderen Users ab |
| `check_decision_complete()` | Trigger: Auto-Resolve bei einstimmiger/Mehrheits-Abstimmung |
| `is_admin()` | Helper: Admin-Rolle ODER is_system Check für RLS |
| `is_org_admin(org_id)` | Helper: Org-Admin ODER Superadmin Check |
| `is_org_member(org_id)` | Helper: Org-Mitglied ODER Superadmin |

---

## Multi-Machine Workflow

Dieses Projekt wird von **mehreren Rechnern** aus bearbeitet (gleicher GitHub-Account, SSH-Auth).

### Session-Start (PFLICHT)
```bash
git pull origin main
```
**Immer zuerst pullen**, bevor Änderungen gemacht werden.

### Session-Ende
```bash
git add .
git commit -m "Beschreibung der Änderungen"
git push origin main
```

### Regeln
- **Ein Branch:** `main` — keine Feature-Branches, kein PR-Workflow
- **Immer pushen** am Ende einer Session
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
