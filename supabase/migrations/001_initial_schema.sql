-- =============================================
-- PROJEKTPLANNER - Datenbank-Schema
-- Führe dieses SQL im Supabase SQL Editor aus:
-- Dashboard → SQL Editor → New Query → Einfügen → Run
-- =============================================

-- 1. ROLLEN-TABELLE
create table public.roles (
  id uuid default gen_random_uuid() primary key,
  name text not null unique,
  permissions jsonb not null default '[]',
  created_at timestamptz default now()
);

-- Standard-Rollen einfügen
insert into public.roles (name, permissions) values
  ('admin', '[
    {"module": "projects", "actions": ["create","read","update","delete"]},
    {"module": "inventory", "actions": ["create","read","update","delete"]},
    {"module": "costs", "actions": ["create","read","update","delete"]},
    {"module": "users", "actions": ["create","read","update","delete"]},
    {"module": "settings", "actions": ["create","read","update","delete"]}
  ]'),
  ('manager', '[
    {"module": "projects", "actions": ["create","read","update","delete"]},
    {"module": "inventory", "actions": ["create","read","update"]},
    {"module": "costs", "actions": ["create","read","update","delete"]},
    {"module": "users", "actions": ["read"]}
  ]'),
  ('member', '[
    {"module": "projects", "actions": ["read","update"]},
    {"module": "inventory", "actions": ["read"]},
    {"module": "costs", "actions": ["read"]}
  ]');

-- 2. USER-PROFILE (erweitert Supabase Auth)
create table public.profiles (
  id uuid references auth.users on delete cascade primary key,
  email text not null,
  name text not null default '',
  role_id uuid references public.roles(id),
  created_at timestamptz default now(),
  updated_at timestamptz default now()
);

-- 3. PROJEKTE
create table public.projects (
  id uuid default gen_random_uuid() primary key,
  name text not null,
  description text,
  status text not null default 'draft'
    check (status in ('draft','planning','active','completed','cancelled')),
  date_start date,
  date_end date,
  created_by uuid references public.profiles(id),
  created_at timestamptz default now(),
  updated_at timestamptz default now()
);

-- 4. INVENTAR
create table public.inventory_items (
  id uuid default gen_random_uuid() primary key,
  name text not null,
  description text,
  category text not null default 'Sonstiges',
  quantity integer not null default 1,
  condition text not null default 'good'
    check (condition in ('new','good','fair','poor','broken')),
  cost_per_day numeric(10,2) not null default 0,
  location text,
  image_url text,
  created_at timestamptz default now()
);

-- 5. BUCHUNGEN (Inventar → Projekt)
create table public.bookings (
  id uuid default gen_random_uuid() primary key,
  project_id uuid references public.projects(id) on delete cascade not null,
  inventory_item_id uuid references public.inventory_items(id) on delete cascade not null,
  quantity integer not null default 1,
  date_from date not null,
  date_to date not null,
  status text not null default 'reserved'
    check (status in ('reserved','checked_out','returned')),
  notes text,
  created_at timestamptz default now(),
  constraint valid_dates check (date_to >= date_from),
  constraint positive_quantity check (quantity > 0)
);

-- 6. KOSTENPOSITIONEN
create table public.cost_items (
  id uuid default gen_random_uuid() primary key,
  project_id uuid references public.projects(id) on delete cascade not null,
  category text not null default 'other'
    check (category in ('personnel','material','inventory','external','other')),
  description text not null,
  amount_planned numeric(10,2) not null default 0,
  amount_actual numeric(10,2),
  created_at timestamptz default now()
);

-- =============================================
-- ROW LEVEL SECURITY (RLS)
-- =============================================

-- RLS aktivieren
alter table public.profiles enable row level security;
alter table public.projects enable row level security;
alter table public.inventory_items enable row level security;
alter table public.bookings enable row level security;
alter table public.cost_items enable row level security;
alter table public.roles enable row level security;

-- Rollen: Jeder eingeloggte User kann Rollen lesen
create policy "Rollen sind für alle sichtbar"
  on public.roles for select
  to authenticated
  using (true);

-- Profile: User kann sein eigenes Profil lesen/bearbeiten
create policy "Eigenes Profil lesen"
  on public.profiles for select
  to authenticated
  using (true);

create policy "Eigenes Profil bearbeiten"
  on public.profiles for update
  to authenticated
  using (auth.uid() = id);

-- Projekte: Alle eingeloggten User können lesen, erstellen, bearbeiten
create policy "Projekte lesen"
  on public.projects for select
  to authenticated
  using (true);

create policy "Projekte erstellen"
  on public.projects for insert
  to authenticated
  with check (true);

create policy "Projekte bearbeiten"
  on public.projects for update
  to authenticated
  using (true);

create policy "Projekte löschen"
  on public.projects for delete
  to authenticated
  using (true);

-- Inventar: Alle eingeloggten User können alles
create policy "Inventar lesen"
  on public.inventory_items for select
  to authenticated
  using (true);

create policy "Inventar erstellen"
  on public.inventory_items for insert
  to authenticated
  with check (true);

create policy "Inventar bearbeiten"
  on public.inventory_items for update
  to authenticated
  using (true);

create policy "Inventar löschen"
  on public.inventory_items for delete
  to authenticated
  using (true);

-- Buchungen
create policy "Buchungen lesen"
  on public.bookings for select
  to authenticated
  using (true);

create policy "Buchungen erstellen"
  on public.bookings for insert
  to authenticated
  with check (true);

create policy "Buchungen bearbeiten"
  on public.bookings for update
  to authenticated
  using (true);

create policy "Buchungen löschen"
  on public.bookings for delete
  to authenticated
  using (true);

-- Kosten
create policy "Kosten lesen"
  on public.cost_items for select
  to authenticated
  using (true);

create policy "Kosten erstellen"
  on public.cost_items for insert
  to authenticated
  with check (true);

create policy "Kosten bearbeiten"
  on public.cost_items for update
  to authenticated
  using (true);

create policy "Kosten löschen"
  on public.cost_items for delete
  to authenticated
  using (true);

-- =============================================
-- TRIGGER: Automatisch Profil bei Registrierung
-- =============================================

-- Wenn sich ein neuer User registriert, wird automatisch
-- ein Profil mit der "member"-Rolle angelegt
create or replace function public.handle_new_user()
returns trigger as $$
declare
  default_role_id uuid;
begin
  select id into default_role_id from public.roles where name = 'member';

  insert into public.profiles (id, email, name, role_id)
  values (
    new.id,
    new.email,
    coalesce(new.raw_user_meta_data->>'name', split_part(new.email, '@', 1)),
    default_role_id
  );
  return new;
end;
$$ language plpgsql security definer;

create trigger on_auth_user_created
  after insert on auth.users
  for each row execute procedure public.handle_new_user();

-- Profile dürfen eingefügt werden (für den Trigger)
create policy "Profile erstellen (System)"
  on public.profiles for insert
  to authenticated
  with check (auth.uid() = id);
