-- 026: Erweiterte Task-Zuweisung (Crew + Externe + Annahme-Flow + Notifications)

-- 1. Neue Spalten auf project_tasks
ALTER TABLE public.project_tasks
  ADD COLUMN IF NOT EXISTS assigned_to_team_id uuid REFERENCES public.project_team(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS assigned_to_contact_id uuid REFERENCES public.project_contacts(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS assignment_status text NOT NULL DEFAULT 'pending'
    CHECK (assignment_status IN ('pending', 'accepted', 'declined')),
  ADD COLUMN IF NOT EXISTS assigned_at timestamptz;

-- 2. Constraint: maximal EIN Assignee-Typ gleichzeitig
ALTER TABLE public.project_tasks
  ADD CONSTRAINT chk_single_assignee CHECK (
    (CASE WHEN assigned_to IS NOT NULL THEN 1 ELSE 0 END
     + CASE WHEN assigned_to_team_id IS NOT NULL THEN 1 ELSE 0 END
     + CASE WHEN assigned_to_contact_id IS NOT NULL THEN 1 ELSE 0 END) <= 1
  );

-- 3. Indexes für die neuen FKs
CREATE INDEX IF NOT EXISTS idx_project_tasks_team_assignee ON public.project_tasks(assigned_to_team_id);
CREATE INDEX IF NOT EXISTS idx_project_tasks_contact_assignee ON public.project_tasks(assigned_to_contact_id);

-- 4. Backfill: Bestehende zugewiesene Tasks auf 'accepted' setzen
UPDATE public.project_tasks
  SET assignment_status = 'accepted', assigned_at = created_at
  WHERE assigned_to IS NOT NULL;

-- 5. Task Notifications Tabelle
CREATE TABLE IF NOT EXISTS public.task_notifications (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  task_id uuid REFERENCES public.project_tasks(id) ON DELETE CASCADE NOT NULL,
  profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE NOT NULL,
  type text NOT NULL CHECK (type IN ('assigned', 'unassigned')),
  is_read boolean NOT NULL DEFAULT false,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_task_notifications_profile ON public.task_notifications(profile_id, is_read);
CREATE INDEX IF NOT EXISTS idx_task_notifications_task ON public.task_notifications(task_id);

-- 6. RLS für task_notifications
ALTER TABLE public.task_notifications ENABLE ROW LEVEL SECURITY;

CREATE POLICY "task_notif_select" ON public.task_notifications
  FOR SELECT TO authenticated
  USING (profile_id = auth.uid());

CREATE POLICY "task_notif_insert" ON public.task_notifications
  FOR INSERT TO authenticated
  WITH CHECK (true);

CREATE POLICY "task_notif_update" ON public.task_notifications
  FOR UPDATE TO authenticated
  USING (profile_id = auth.uid());

CREATE POLICY "task_notif_delete" ON public.task_notifications
  FOR DELETE TO authenticated
  USING (profile_id = auth.uid());

-- 7. Realtime aktivieren
ALTER PUBLICATION supabase_realtime ADD TABLE task_notifications;
