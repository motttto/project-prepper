# CLAUDE.md — Project Prepper

> Zentrale Referenz für Claude Code Sessions. Lies diese Datei immer zuerst.

## Projekt-Steckbrief

| Key | Value |
|-----|-------|
| **Name** | Project Prepper |
| **Stack** | Next.js 16.1.6 (App Router) · React 19 · TypeScript 5.9 · Supabase · Tailwind CSS 4.2 |
| **Sprache (UI)** | Deutsch |
| **Supabase** | Auth + PostgreSQL + Realtime + RLS + Storage |
| **Build** | `npm run build` — Turbopack |
| **Dev** | `npm run dev` |

---

## Architektur

```
src/
├── app/
│   ├── (auth)/login/page.tsx          # Login/Register
│   ├── (auth)/pending/page.tsx        # Warteseite (Freigabe ausstehend)
│   ├── (dashboard)/                   # Protected layout mit Sidebar
│   │   ├── team/page.tsx              # Team-Verwaltung + Freigaben + Permissions + Impersonation
│   │   ├── dashboard/page.tsx         # KPI-Dashboard
│   │   ├── profile/page.tsx           # Profil bearbeiten (Name, Email, Avatar)
│   │   ├── profile/telegram-link/     # Telegram-Account verknüpfen
│   │   ├── projects/page.tsx          # Projektliste
│   │   ├── projects/[id]/page.tsx     # Projekt-Detail (8 Tabs)
│   │   ├── inventory/page.tsx         # Inventar + Excel-Import + Kategorien-Manager
│   │   ├── inquiries/page.tsx         # Anfragen-Pipeline
│   │   ├── inquiries/[id]/page.tsx    # Anfrage-Detail + Team
│   │   ├── costs/page.tsx             # Globale Kostenübersicht
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
│   │   ├── invitation-bell.tsx        # Einladungs-Dropdown
│   │   └── impersonate-banner.tsx     # "Du siehst als..." Banner
│   ├── projects/
│   │   ├── tab-overview.tsx           # Projektdetails + Budget
│   │   ├── tab-schedule.tsx           # Zeitplan / Ablauf
│   │   ├── tab-costs.tsx              # Kostenposten + MwSt
│   │   ├── tab-equipment.tsx          # Inventar-Buchungen
│   │   ├── tab-team.tsx               # Team + Kontakte
│   │   ├── tab-materials.tsx          # Verbrauchsmaterial + Transport
│   │   ├── tab-checklists.tsx         # Checklisten
│   │   ├── tab-tasks.tsx              # Aufgaben (Intern/Crew/Extern, Annahme-Flow)
│   │   ├── tab-files.tsx              # Dateien / Grundrisse (Upload + Lightbox)
│   │   └── project-members-panel.tsx  # Mitglieder verwalten
│   ├── inventory/
│   │   ├── excel-import.tsx           # Mehrstufiger Excel-Import
│   │   ├── inventory-detail-modal.tsx # Detail-Modal (Foto + Bearbeiten)
│   │   └── inventory-image-upload.tsx # Foto-Upload mit Komprimierung
│   └── ui/
│       ├── icons.tsx                  # Alle SVG-Icons (inline)
│       ├── tabs.tsx                   # TabBar-Komponente
│       └── presence-avatars.tsx       # Online-User Avatare
├── hooks/
│   ├── use-current-user.ts            # Auth User + Org-Rolle + Permissions + hasPermission()
│   ├── use-project-role.ts            # Projekt-Rolle (owner/editor/viewer/admin/none)
│   ├── use-invitations.ts             # Einladungen + accept/decline
│   ├── use-task-notifications.ts      # Task-Zuweisungs-Notifications
│   ├── use-realtime-table.ts          # Generische Realtime-Subscription
│   └── use-presence.ts                # Presence Tracking pro Projekt
├── lib/
│   ├── supabase.ts                    # createClient() — Browser
│   └── supabase-server.ts             # createServerSupabaseClient() — Server
├── middleware.ts                      # Auth Guard + Session Refresh + is_active Check
└── types/
    └── database.ts                    # Alle TypeScript Types
```

---

## Datenbank-Schema

### Tabellen

| Tabelle | Zweck | Key-Felder |
|---------|-------|------------|
| `roles` | Org-Rollen (admin/manager/member) | name, permissions (JSONB) |
| `profiles` | User-Profile (extends auth.users) | id (FK auth.users), email, name, role_id, is_active, approved_at, avatar_url |
| `team_votes` | Abstimmungen für Neubeitritte | candidate_id (FK profiles), voter_id (FK profiles), UNIQUE |
| `projects` | Projekte mit Venue/Client/Budget | status, date_start/end, venue_*, client_*, budget_*, created_by |
| `inventory_items` | Equipment-Inventar | inventory_number (auto), name, category, quantity, condition, cost_per_day, device_name, serial_number, purchase_price, dimensions, power_watts, accessories (text[]), custom_field, manufacturer_url, manual_url |
| `inventory_categories` | Dynamische Kategorien | org_id, name, icon (Emoji), prefix, sort_order |
| `inventory_units` | Einzelstücke-Tracking | item_id, unit_number, condition, notes |
| `bookings` | Equipment-Reservierungen | project_id, inventory_item_id, quantity, date_from/to, status |
| `cost_items` | Kostenposten pro Projekt | project_id, category, amount_planned, amount_actual, vat_rate |
| `project_schedule` | Zeitplan-Einträge | project_id, title, schedule_date, time_start/end, sort_order |
| `project_team_members` | Team-Mitglieder | project_id, name, role, department, phone, email |
| `project_contacts` | Externe Kontakte | project_id, name, role, company, phone, email |
| `project_consumables` | Verbrauchsmaterial | project_id, name, quantity, unit, cost |
| `project_checklists` | Checklisten | project_id, name, sort_order |
| `project_checklist_items` | Checklist-Items | checklist_id, label, checked, sort_order |
| `project_members` | Projekt-Mitgliedschaft | project_id, profile_id, role (owner/editor/viewer) |
| `project_invitations` | Einladungen | project_id, invited_by, invited_profile_id, role, status |
| `project_tasks` | Aufgaben pro Projekt | project_id, title, status, priority, assigned_to, assigned_to_team_id, assigned_to_contact_id, assignment_status, assigned_at, due_date, sort_order |
| `task_notifications` | Task-Zuweisungs-Notifications | task_id, profile_id, type (assigned/unassigned), is_read |
| `organizations` | Multi-Tenant Orgs | name, slug, description, logo_url, telegram_chat_id, created_by |
| `org_memberships` | User→Org Zugehörigkeit | org_id, profile_id, role_id, is_active, approved_at, permissions (JSONB) |
| `org_invitations` | Org-Einladungen (Link-basiert) | org_id, email, invited_by, role_id, status, accepted_at |
| `inquiries` | Projektanfragen-Pipeline | org_id, status, client_*, title, venue_*, event_date_*, offer_*, probability, telegram_message_id |
| `inquiry_invitations` | Anfrage-Team | inquiry_id, invited_profile_id, status |
| `project_files` | Projekt-Dateien (Grundrisse, PDFs) | project_id, org_id, file_name, file_url, file_type, uploaded_by |

### Projekt-Budget Felder (auf `projects`)
- `budget_planned` — Gesamtbudget
- `budget_honorar` — Honorar/Gage
- `budget_technik` — Technik
- `budget_transport` — Transport

### Cost-Item Kategorien
`personnel` · `material` · `inventory` · `external` · `other`

### Inventory Conditions
`new` · `good` · `fair` · `poor` · `broken` · `retired`

### Task-Status
`todo` · `in_progress` · `done`

### Task-Priorität
`low` · `medium` · `high`

### Task-Assignment-Status
`pending` · `accepted` · `declined`

### Projekt-Status
`draft` · `planning` · `active` · `completed` · `cancelled`

---

## RLS (Row Level Security)

### Offene Tabellen (alle authentifizierten User)
- `projects` — alle können Projekte sehen/erstellen
- `bookings` — alle können Buchungen verwalten
- `inventory_items` — alle können Inventar sehen/bearbeiten
- `project_schedule`, `project_team_members`, `project_contacts`, `project_consumables`, `project_checklists`, `project_checklist_items`, `project_tasks` — offen
- `team_votes` — SELECT: alle; INSERT: nur aktive User (voter_id=auth.uid()); DELETE: eigene

### Eingeschränkte Tabellen
- **`cost_items`** — nur Mitglieder des Projekts ODER Admins
  - Policies nutzen: `is_project_member(project_id) OR is_admin()`
- **`project_members`** — SELECT: alle; INSERT/DELETE: nur Owner + Admins
- **`project_invitations`** — SELECT: Eingeladener/Einladender/Admin; INSERT: Owner/Admin; UPDATE: nur Eingeladener

### Helper-Funktionen (SECURITY DEFINER)
```sql
is_project_member(p_project_id uuid) → boolean
is_admin() → boolean
```

### Budget-Sichtbarkeit
Budget-Spalten auf `projects` können nicht per RLS versteckt werden → werden **im UI** ausgeblendet (`canViewBudget` Prop).

---

## Migrations

| # | Datei | Beschreibung |
|---|-------|-------------|
| 001 | `001_initial_schema.sql` | Roles, Profiles, Projects, Inventory, Bookings, Costs + RLS + Trigger |
| 002 | `002_seed_inventory.sql` | Test-Inventar |
| 003 | `003_seed_projects.sql` | Test-Projekte |
| 004 | `004_inventory_owner.sql` | Owner-Feld auf inventory_items |
| 005 | `005_condition_retired.sql` | "retired" Condition |
| 006 | `006_purchased_by.sql` | purchased_by + purchased_at |
| 007 | `007_inventory_number.sql` | inventory_number mit Auto-Generierung |
| 008 | `008_project_event_hub.sql` | Schedule, Team, Contacts, Consumables, Checklists |
| 009 | `009_budget_categories.sql` | budget_honorar, budget_technik, budget_transport |
| 010 | `010_vat_team_department.sql` | MwSt (vat_rate) + Department |
| 011 | `011_enable_realtime.sql` | Realtime für alle Tabellen |
| 012 | `012_project_membership.sql` | Mitgliedschaft, Einladungen, RLS |
| 013 | `013_inventory_images_bucket.sql` | Storage Bucket für Inventar-Fotos |
| 014 | `014_project_tasks.sql` | Aufgaben-Tabelle (Status, Priorität, Zuweisung) |
| 015 | `015_team_approval.sql` | Team-Freigabe: is_active, team_votes, Trigger-Update |
| 016 | `016_profile_avatars.sql` | avatar_url auf profiles + Storage Bucket `avatars` |
| 025 | `025_inventory_details.sql` | Gerätebezeichnung, Seriennummer, Kaufpreis, Abmaße, Leistung, Zubehör, Freifeld |
| 026 | `026_task_assignment_extended.sql` | Erweiterte Task-Zuweisung (Crew/Extern), Annahme-Flow, Task-Notifications |
| 027 | `027_setup_teardown_ranges.sql` | Auf-/Abbau: Einzeldaten zu Zeiträumen (setup_date_end, teardown_date_end) |
| 028 | `028_inventory_urls_and_units.sql` | Hersteller-URL, Manual-URL + Einzelstücke-Tabelle (inventory_units) |
| 029 | `029_inventory_categories.sql` | Dynamische Inventar-Kategorien (inventory_categories) |
| 030 | `030_org_invitations_and_testusers.sql` | Org-Einladungen + Auto-Join-Trigger bei Registrierung |
| 031 | `031_testuser_function.sql` | create_test_user() RPC (Dummy auth.users + profiles + membership) |
| 032 | `032_delete_user_function.sql` | remove_org_member() RPC (bei Testusern komplett löschen) |
| 033 | `033_user_permissions.sql` | Pro-User Permissions JSONB auf org_memberships |
| 034 | `034_project_files.sql` | Projekt-Dateien: Storage Bucket + Tabelle + RLS + Realtime |

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

## Zugriffssystem

### Org-Rollen (profiles.role_id → roles)
| Rolle | Rechte |
|-------|--------|
| `admin` | Alles sehen + bearbeiten, unabhängig von Mitgliedschaft |
| `manager` | Projekte CRUD, Inventar CRU, Kosten CRUD |
| `member` | Projekte RU, Inventar R, Kosten R |

### Projekt-Rollen (project_members.role)
| Rolle | Rechte |
|-------|--------|
| `owner` | Alles + Mitglieder verwalten + einladen |
| `editor` | Projekt bearbeiten + Kosten sehen |
| `viewer` | Nur lesen + Kosten sehen |
| `none` | Projekt sichtbar, aber KEINE Kosten/Budget |

### Feingranulare Permissions (pro User, Migration 033)
JSONB `permissions` auf `org_memberships` — 13 Checkboxen in 5 Gruppen:
- **Projekte:** `projects_view`, `projects_edit`
- **Inventar:** `inventory_view`, `inventory_edit`, `excel_export`, `excel_import`
- **Finanzen:** `costs_view`, `costs_edit`
- **Team:** `team_view`, `team_manage`
- **Anfragen:** `inquiries_view`, `inquiries_edit`, `inquiries_create`

`null` = Rollen-Default, Admin hat immer alles. Sidebar filtert Nav-Items.

### Effektive Rolle (useProjectRole)
1. Admin Check (Org-Level) → return `admin`
2. project_members Lookup → return `owner`/`editor`/`viewer`
3. Fallback → `none`

### Team-Freigabe
- **Registrierung:** offen, erster User wird automatisch Admin + aktiv
- **Neubeitritt:** Alle aktiven Mitglieder müssen zustimmen (Unanimous Vote)
- **Admin-Override:** Admins können sofort freigeben
- **Org-Einladung:** Admin lädt per E-Mail ein → Auto-Join bei Registrierung
- **Testuser:** `create_test_user()` RPC → Dummy-User für UI-Tests
- **Impersonation:** Admin kann App als anderer User sehen (ImpersonateProvider)
- **Inaktive User:** `is_active=false` → Redirect zu `/pending`, kein App-Zugang
- **Middleware:** Prüft `org_memberships.is_active` bei jedem Request

---

## Realtime-fähige Tabellen
Alle Tabellen in `supabase_realtime` Publication:
- projects, inventory_items, inventory_categories, inventory_units, bookings, cost_items
- project_schedule, project_team_members, project_contacts
- project_consumables, project_checklists, project_checklist_items
- project_members, project_invitations, project_tasks
- task_notifications, team_votes, org_invitations, project_files

---

## Environment

```env
# .env.local
NEXT_PUBLIC_SUPABASE_URL=https://xxx.supabase.co
NEXT_PUBLIC_SUPABASE_ANON_KEY=eyJ...
```

---

## Multi-Machine Workflow

Dieses Projekt wird von **mehreren Rechnern** aus bearbeitet (gleicher GitHub-Account, SSH-Auth).

### Session-Start (PFLICHT)
```bash
git pull origin main
```
**Immer zuerst pullen**, bevor Änderungen gemacht werden. Verhindert Merge-Konflikte.

### Session-Ende
Alle Änderungen committen und pushen, damit der andere Rechner den aktuellen Stand hat:
```bash
git add .
git commit -m "Beschreibung der Änderungen"
git push origin main
```

### Regeln
- **Ein Branch:** `main` — keine Feature-Branches, kein PR-Workflow
- **Immer pushen** am Ende einer Session — nichts uncommitted lassen
- **Immer pullen** am Anfang einer Session — vor jeder Code-Änderung
- **Bei Konflikten:** Nicht blind mergen, sondern Dateien vergleichen und manuell lösen

### Claude Code Hinweis
Wenn Claude Code eine neue Session auf einem Rechner startet, soll als **erstes** `git pull origin main` ausgeführt werden, um sicherzustellen, dass der lokale Stand aktuell ist.

---

## Storage (Supabase)

| Bucket | Zweck | Public |
|--------|-------|--------|
| `inventory-images` | Fotos für Inventar-Artikel | Ja (public read) |
| `avatars` | Profilbilder für User | Ja (public read) |
| `project-files` | Projekt-Dateien (Grundrisse, PDFs) | Ja (public read) |

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
| `is_org_admin(org_id)` | Helper: Org-Admin Check für RLS |
| `is_org_member(org_id)` | Helper: Org-Mitglied Check für RLS |

---

## Nächste Schritte / Offene Punkte
- [ ] Weitere Features nach Bedarf
