-- Migration 075: group_id auf Group-Features (Phase 4)
-- =====================================================
-- Polls, Decisions, Calendar bekommen group_id parallel zu org_id.
-- org_id bleibt vorerst (NULL erlaubt) — entfaellt in spaeterer Phase.
-- Daten sind eh leer (Reset in Migration 068), daher keine Migration noetig.

-- ── org_polls -> group_polls (semantisch) ──────────────────────────────────
ALTER TABLE public.org_polls
  ADD COLUMN IF NOT EXISTS group_id uuid REFERENCES public.groups(id) ON DELETE CASCADE;

ALTER TABLE public.org_polls
  ALTER COLUMN org_id DROP NOT NULL;

CREATE INDEX IF NOT EXISTS idx_org_polls_group ON public.org_polls(group_id);

-- ── org_decisions -> group_decisions ───────────────────────────────────────
ALTER TABLE public.org_decisions
  ADD COLUMN IF NOT EXISTS group_id uuid REFERENCES public.groups(id) ON DELETE CASCADE;

ALTER TABLE public.org_decisions
  ALTER COLUMN org_id DROP NOT NULL;

CREATE INDEX IF NOT EXISTS idx_org_decisions_group ON public.org_decisions(group_id);

-- ── calendar_groups: bleibt namentlich, aber semantisch group-zugeordnet ───
ALTER TABLE public.calendar_groups
  ADD COLUMN IF NOT EXISTS group_id uuid REFERENCES public.groups(id) ON DELETE CASCADE;

ALTER TABLE public.calendar_groups
  ALTER COLUMN org_id DROP NOT NULL;

CREATE INDEX IF NOT EXISTS idx_cal_groups_group ON public.calendar_groups(group_id);

-- ── RLS-Policies anpassen ──────────────────────────────────────────────────
-- Polls: Group-Mitglieder duerfen sehen + erstellen
DROP POLICY IF EXISTS org_polls_select ON public.org_polls;
DROP POLICY IF EXISTS org_polls_insert ON public.org_polls;
DROP POLICY IF EXISTS org_polls_update ON public.org_polls;
DROP POLICY IF EXISTS org_polls_delete ON public.org_polls;

CREATE POLICY "polls_select" ON public.org_polls
  FOR SELECT TO authenticated USING (
    (group_id IS NOT NULL AND is_group_member(group_id))
    OR created_by = auth.uid()
    OR EXISTS (SELECT 1 FROM profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "polls_insert" ON public.org_polls
  FOR INSERT TO authenticated WITH CHECK (
    created_by = auth.uid()
    AND (group_id IS NULL OR is_group_member(group_id))
  );

CREATE POLICY "polls_update" ON public.org_polls
  FOR UPDATE TO authenticated USING (
    created_by = auth.uid()
    OR (group_id IS NOT NULL AND is_group_member(group_id))
  );

CREATE POLICY "polls_delete" ON public.org_polls
  FOR DELETE TO authenticated USING (
    created_by = auth.uid()
  );

-- Decisions: gleiche Logik
DROP POLICY IF EXISTS org_decisions_select ON public.org_decisions;
DROP POLICY IF EXISTS org_decisions_insert ON public.org_decisions;
DROP POLICY IF EXISTS org_decisions_update ON public.org_decisions;
DROP POLICY IF EXISTS org_decisions_delete ON public.org_decisions;

CREATE POLICY "decisions_select" ON public.org_decisions
  FOR SELECT TO authenticated USING (
    (group_id IS NOT NULL AND is_group_member(group_id))
    OR created_by = auth.uid()
    OR EXISTS (SELECT 1 FROM profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "decisions_insert" ON public.org_decisions
  FOR INSERT TO authenticated WITH CHECK (
    created_by = auth.uid()
    AND (group_id IS NULL OR is_group_member(group_id))
  );

CREATE POLICY "decisions_update" ON public.org_decisions
  FOR UPDATE TO authenticated USING (
    created_by = auth.uid()
    OR (group_id IS NOT NULL AND is_group_member(group_id))
  );

CREATE POLICY "decisions_delete" ON public.org_decisions
  FOR DELETE TO authenticated USING (
    created_by = auth.uid()
  );

-- Calendar-Groups
DROP POLICY IF EXISTS calendar_groups_select ON public.calendar_groups;
DROP POLICY IF EXISTS calendar_groups_insert ON public.calendar_groups;
DROP POLICY IF EXISTS calendar_groups_update ON public.calendar_groups;
DROP POLICY IF EXISTS calendar_groups_delete ON public.calendar_groups;

CREATE POLICY "cal_groups_select" ON public.calendar_groups
  FOR SELECT TO authenticated USING (
    (group_id IS NOT NULL AND is_group_member(group_id))
    OR EXISTS (SELECT 1 FROM profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "cal_groups_insert" ON public.calendar_groups
  FOR INSERT TO authenticated WITH CHECK (
    group_id IS NULL OR is_group_member(group_id)
  );

CREATE POLICY "cal_groups_update" ON public.calendar_groups
  FOR UPDATE TO authenticated USING (
    group_id IS NULL OR is_group_member(group_id)
  );

CREATE POLICY "cal_groups_delete" ON public.calendar_groups
  FOR DELETE TO authenticated USING (
    group_id IS NULL OR is_group_member(group_id)
  );
