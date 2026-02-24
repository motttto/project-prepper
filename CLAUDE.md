# CLAUDE.md — Projektplanner

> Zentrale Referenz für Claude Code Sessions. Lies diese Datei immer zuerst.

## Projekt-Steckbrief

| Key | Value |
|-----|-------|
| **Name** | Dunkelstrom Projektplanner |
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
│   │   ├── team/page.tsx              # Team-Verwaltung + Freigaben
│   │   ├── dashboard/page.tsx         # KPI-Dashboard
│   │   ├── profile/page.tsx           # Profil bearbeiten (Name, Email, Avatar)
│   │   ├── projects/page.tsx          # Projektliste
│   │   ├── projects/[id]/page.tsx     # Projekt-Detail (8 Tabs)
│   │   ├── inventory/page.tsx         # Inventar + Excel-Import + Detail-Modal
│   │   ├── costs/page.tsx             # Globale Kostenübersicht
│   │   └── layout.tsx                 # Sidebar + Main-Wrapper
│   ├── auth/callback/                 # OAuth Callback
│   ├── globals.css                    # CSS Variables + Dark Mode
│   ├── layout.tsx                     # Root Layout (Inter Font)
│   └── page.tsx                       # Landing → /dashboard oder /login
├── components/
│   ├── layout/
│   │   ├── sidebar.tsx                # Nav + User + InvitationBell
│   │   └── invitation-bell.tsx        # Einladungs-Dropdown
│   ├── projects/
│   │   ├── tab-overview.tsx           # Projektdetails + Budget
│   │   ├── tab-schedule.tsx           # Zeitplan / Ablauf
│   │   ├── tab-costs.tsx              # Kostenposten + MwSt
│   │   ├── tab-equipment.tsx          # Inventar-Buchungen
│   │   ├── tab-team.tsx               # Team + Kontakte
│   │   ├── tab-materials.tsx          # Verbrauchsmaterial + Transport
│   │   ├── tab-checklists.tsx         # Checklisten
│   │   ├── tab-tasks.tsx              # Aufgaben (Zuweisen, Prio, Status)
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
│   ├── use-current-user.ts            # Auth User + Org-Rolle
│   ├── use-project-role.ts            # Projekt-Rolle (owner/editor/viewer/admin/none)
│   ├── use-invitations.ts             # Einladungen + accept/decline
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
| `inventory_items` | Equipment-Inventar | inventory_number (auto), name, category, quantity, condition, cost_per_day |
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
| `project_tasks` | Aufgaben pro Projekt | project_id, title, status, priority, assigned_to, due_date, sort_order |

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

### Effektive Rolle (useProjectRole)
1. Admin Check (Org-Level) → return `admin`
2. project_members Lookup → return `owner`/`editor`/`viewer`
3. Fallback → `none`

### Team-Freigabe
- **Registrierung:** offen, erster User wird automatisch Admin + aktiv
- **Neubeitritt:** Alle aktiven Mitglieder müssen zustimmen (Unanimous Vote)
- **Admin-Override:** Admins können sofort freigeben
- **Inaktive User:** `is_active=false` → Redirect zu `/pending`, kein App-Zugang
- **Middleware:** Prüft `profiles.is_active` bei jedem Request

---

## Realtime-fähige Tabellen
Alle 14 Tabellen sind in `supabase_realtime` Publication:
- projects, inventory_items, bookings, cost_items
- project_schedule, project_team_members, project_contacts
- project_consumables, project_checklists, project_checklist_items
- project_members, project_invitations, project_tasks
- team_votes

---

## Environment

```env
# .env.local
NEXT_PUBLIC_SUPABASE_URL=https://xxx.supabase.co
NEXT_PUBLIC_SUPABASE_ANON_KEY=eyJ...
```

---

## Storage (Supabase)

| Bucket | Zweck | Public |
|--------|-------|--------|
| `inventory-images` | Fotos für Inventar-Artikel | Ja (public read) |
| `avatars` | Profilbilder für User | Ja (public read) |

Bilder werden client-seitig komprimiert via `browser-image-compression`:
- **Inventar:** max 800px, WebP, <200KB → `{itemId}/{timestamp}.webp`
- **Avatare:** max 400px, WebP, <100KB → `{userId}/{timestamp}.webp`

---

## Nächste Schritte / Offene Punkte
- [ ] Weitere Features nach Bedarf
