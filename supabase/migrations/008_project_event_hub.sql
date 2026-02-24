-- =============================================
-- 008: Project Event Hub - Neue Tabellen + Erweiterungen
-- =============================================

-- ========== 1. PROJECTS TABLE ERWEITERN ==========

ALTER TABLE public.projects
  -- Venue / Spielstätte
  ADD COLUMN venue_name text,
  ADD COLUMN venue_address text,
  ADD COLUMN venue_contact_person text,
  ADD COLUMN venue_phone text,
  ADD COLUMN venue_notes text,
  -- Client / Auftraggeber
  ADD COLUMN client_name text,
  ADD COLUMN client_contact_person text,
  ADD COLUMN client_phone text,
  ADD COLUMN client_email text,
  -- Show Details
  ADD COLUMN show_date date,
  ADD COLUMN arrival_time time,
  ADD COLUMN departure_time time,
  ADD COLUMN setup_date date,
  ADD COLUMN teardown_date date,
  -- Budget
  ADD COLUMN budget_planned numeric(10,2),
  -- Notes
  ADD COLUMN transport_notes text,
  ADD COLUMN internal_notes text;

-- ========== 2. ZEITPLAN / SCHEDULE ==========

CREATE TABLE public.project_schedule (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  project_id uuid REFERENCES public.projects(id) ON DELETE CASCADE NOT NULL,
  title text NOT NULL,
  schedule_date date NOT NULL,
  time_start time,
  time_end time,
  description text,
  sort_order integer NOT NULL DEFAULT 0,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX idx_project_schedule_project ON public.project_schedule(project_id);
CREATE INDEX idx_project_schedule_sort ON public.project_schedule(project_id, schedule_date, sort_order);

-- ========== 3. TEAM-MITGLIEDER ==========

CREATE TABLE public.project_team (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  project_id uuid REFERENCES public.projects(id) ON DELETE CASCADE NOT NULL,
  name text NOT NULL,
  role text NOT NULL DEFAULT '',
  phone text,
  email text,
  notes text,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX idx_project_team_project ON public.project_team(project_id);

-- ========== 4. EXTERNE KONTAKTE ==========

CREATE TABLE public.project_contacts (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  project_id uuid REFERENCES public.projects(id) ON DELETE CASCADE NOT NULL,
  name text NOT NULL,
  role text NOT NULL DEFAULT '',
  company text,
  phone text,
  email text,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX idx_project_contacts_project ON public.project_contacts(project_id);

-- ========== 5. VERBRAUCHSMATERIAL ==========

CREATE TABLE public.project_consumables (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  project_id uuid REFERENCES public.projects(id) ON DELETE CASCADE NOT NULL,
  name text NOT NULL,
  quantity numeric(10,2) NOT NULL DEFAULT 1,
  unit text NOT NULL DEFAULT 'Stk',
  cost numeric(10,2),
  notes text,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX idx_project_consumables_project ON public.project_consumables(project_id);

-- ========== 6. CHECKLISTEN ==========

CREATE TABLE public.project_checklists (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  project_id uuid REFERENCES public.projects(id) ON DELETE CASCADE NOT NULL,
  name text NOT NULL,
  sort_order integer NOT NULL DEFAULT 0,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX idx_project_checklists_project ON public.project_checklists(project_id);

CREATE TABLE public.project_checklist_items (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  checklist_id uuid REFERENCES public.project_checklists(id) ON DELETE CASCADE NOT NULL,
  label text NOT NULL,
  checked boolean NOT NULL DEFAULT false,
  sort_order integer NOT NULL DEFAULT 0,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX idx_checklist_items_checklist ON public.project_checklist_items(checklist_id);

-- ========== 7. ROW LEVEL SECURITY ==========

ALTER TABLE public.project_schedule ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.project_team ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.project_contacts ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.project_consumables ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.project_checklists ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.project_checklist_items ENABLE ROW LEVEL SECURITY;

CREATE POLICY "schedule_select" ON public.project_schedule FOR SELECT TO authenticated USING (true);
CREATE POLICY "schedule_insert" ON public.project_schedule FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "schedule_update" ON public.project_schedule FOR UPDATE TO authenticated USING (true);
CREATE POLICY "schedule_delete" ON public.project_schedule FOR DELETE TO authenticated USING (true);

CREATE POLICY "team_select" ON public.project_team FOR SELECT TO authenticated USING (true);
CREATE POLICY "team_insert" ON public.project_team FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "team_update" ON public.project_team FOR UPDATE TO authenticated USING (true);
CREATE POLICY "team_delete" ON public.project_team FOR DELETE TO authenticated USING (true);

CREATE POLICY "contacts_select" ON public.project_contacts FOR SELECT TO authenticated USING (true);
CREATE POLICY "contacts_insert" ON public.project_contacts FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "contacts_update" ON public.project_contacts FOR UPDATE TO authenticated USING (true);
CREATE POLICY "contacts_delete" ON public.project_contacts FOR DELETE TO authenticated USING (true);

CREATE POLICY "consumables_select" ON public.project_consumables FOR SELECT TO authenticated USING (true);
CREATE POLICY "consumables_insert" ON public.project_consumables FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "consumables_update" ON public.project_consumables FOR UPDATE TO authenticated USING (true);
CREATE POLICY "consumables_delete" ON public.project_consumables FOR DELETE TO authenticated USING (true);

CREATE POLICY "checklists_select" ON public.project_checklists FOR SELECT TO authenticated USING (true);
CREATE POLICY "checklists_insert" ON public.project_checklists FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "checklists_update" ON public.project_checklists FOR UPDATE TO authenticated USING (true);
CREATE POLICY "checklists_delete" ON public.project_checklists FOR DELETE TO authenticated USING (true);

CREATE POLICY "checklist_items_select" ON public.project_checklist_items FOR SELECT TO authenticated USING (true);
CREATE POLICY "checklist_items_insert" ON public.project_checklist_items FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "checklist_items_update" ON public.project_checklist_items FOR UPDATE TO authenticated USING (true);
CREATE POLICY "checklist_items_delete" ON public.project_checklist_items FOR DELETE TO authenticated USING (true);
