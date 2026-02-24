-- Migration 014: Aufgaben (Tasks) pro Projekt
-- =============================================

-- 1. Tabelle
CREATE TABLE public.project_tasks (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  project_id uuid REFERENCES public.projects(id) ON DELETE CASCADE NOT NULL,
  title text NOT NULL,
  description text,
  status text NOT NULL DEFAULT 'todo'
    CHECK (status IN ('todo', 'in_progress', 'done')),
  priority text NOT NULL DEFAULT 'medium'
    CHECK (priority IN ('low', 'medium', 'high')),
  assigned_to uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
  due_date date,
  created_by uuid REFERENCES public.profiles(id),
  sort_order integer NOT NULL DEFAULT 0,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX idx_project_tasks_project ON public.project_tasks(project_id);
CREATE INDEX idx_project_tasks_assigned ON public.project_tasks(assigned_to);

-- 2. RLS
ALTER TABLE public.project_tasks ENABLE ROW LEVEL SECURITY;

CREATE POLICY "project_tasks_select" ON public.project_tasks
  FOR SELECT TO authenticated USING (true);

CREATE POLICY "project_tasks_insert" ON public.project_tasks
  FOR INSERT TO authenticated WITH CHECK (true);

CREATE POLICY "project_tasks_update" ON public.project_tasks
  FOR UPDATE TO authenticated USING (true);

CREATE POLICY "project_tasks_delete" ON public.project_tasks
  FOR DELETE TO authenticated USING (true);

-- 3. Realtime
ALTER PUBLICATION supabase_realtime ADD TABLE project_tasks;
