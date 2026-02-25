-- ── Migration 020b: Repair — Rest der Cross-Org RLS Policies ──────────────────
-- Dieses Script vervollständigt Migration 020, die bei project_team_members
-- fehlschlug (Tabelle heißt project_team).

-- ── project_team ──
DROP POLICY IF EXISTS "team_select" ON public.project_team;
DROP POLICY IF EXISTS "team_insert" ON public.project_team;
DROP POLICY IF EXISTS "team_update" ON public.project_team;
DROP POLICY IF EXISTS "team_delete" ON public.project_team;

CREATE POLICY "team_select" ON public.project_team
  FOR SELECT TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_team.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "team_insert" ON public.project_team
  FOR INSERT TO authenticated
  WITH CHECK (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_team.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "team_update" ON public.project_team
  FOR UPDATE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_team.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "team_delete" ON public.project_team
  FOR DELETE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_team.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

-- ── project_contacts ──
DROP POLICY IF EXISTS "contacts_select" ON public.project_contacts;
DROP POLICY IF EXISTS "contacts_insert" ON public.project_contacts;
DROP POLICY IF EXISTS "contacts_update" ON public.project_contacts;
DROP POLICY IF EXISTS "contacts_delete" ON public.project_contacts;

CREATE POLICY "contacts_select" ON public.project_contacts
  FOR SELECT TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_contacts.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "contacts_insert" ON public.project_contacts
  FOR INSERT TO authenticated
  WITH CHECK (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_contacts.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "contacts_update" ON public.project_contacts
  FOR UPDATE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_contacts.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "contacts_delete" ON public.project_contacts
  FOR DELETE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_contacts.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

-- ── project_consumables ──
DROP POLICY IF EXISTS "consumables_select" ON public.project_consumables;
DROP POLICY IF EXISTS "consumables_insert" ON public.project_consumables;
DROP POLICY IF EXISTS "consumables_update" ON public.project_consumables;
DROP POLICY IF EXISTS "consumables_delete" ON public.project_consumables;

CREATE POLICY "consumables_select" ON public.project_consumables
  FOR SELECT TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_consumables.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "consumables_insert" ON public.project_consumables
  FOR INSERT TO authenticated
  WITH CHECK (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_consumables.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "consumables_update" ON public.project_consumables
  FOR UPDATE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_consumables.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "consumables_delete" ON public.project_consumables
  FOR DELETE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_consumables.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

-- ── project_checklists ──
DROP POLICY IF EXISTS "checklists_select" ON public.project_checklists;
DROP POLICY IF EXISTS "checklists_insert" ON public.project_checklists;
DROP POLICY IF EXISTS "checklists_update" ON public.project_checklists;
DROP POLICY IF EXISTS "checklists_delete" ON public.project_checklists;

CREATE POLICY "checklists_select" ON public.project_checklists
  FOR SELECT TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_checklists.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "checklists_insert" ON public.project_checklists
  FOR INSERT TO authenticated
  WITH CHECK (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_checklists.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "checklists_update" ON public.project_checklists
  FOR UPDATE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_checklists.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "checklists_delete" ON public.project_checklists
  FOR DELETE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_checklists.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

-- ── project_checklist_items (erbt via checklists → projects) ──
DROP POLICY IF EXISTS "checklist_items_select" ON public.project_checklist_items;
DROP POLICY IF EXISTS "checklist_items_insert" ON public.project_checklist_items;
DROP POLICY IF EXISTS "checklist_items_update" ON public.project_checklist_items;
DROP POLICY IF EXISTS "checklist_items_delete" ON public.project_checklist_items;

CREATE POLICY "checklist_items_select" ON public.project_checklist_items
  FOR SELECT TO authenticated
  USING (EXISTS (
    SELECT 1 FROM public.project_checklists cl
    JOIN public.projects p ON p.id = cl.project_id
    WHERE cl.id = project_checklist_items.checklist_id
    AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))
  ));

CREATE POLICY "checklist_items_insert" ON public.project_checklist_items
  FOR INSERT TO authenticated
  WITH CHECK (EXISTS (
    SELECT 1 FROM public.project_checklists cl
    JOIN public.projects p ON p.id = cl.project_id
    WHERE cl.id = project_checklist_items.checklist_id
    AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))
  ));

CREATE POLICY "checklist_items_update" ON public.project_checklist_items
  FOR UPDATE TO authenticated
  USING (EXISTS (
    SELECT 1 FROM public.project_checklists cl
    JOIN public.projects p ON p.id = cl.project_id
    WHERE cl.id = project_checklist_items.checklist_id
    AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))
  ));

CREATE POLICY "checklist_items_delete" ON public.project_checklist_items
  FOR DELETE TO authenticated
  USING (EXISTS (
    SELECT 1 FROM public.project_checklists cl
    JOIN public.projects p ON p.id = cl.project_id
    WHERE cl.id = project_checklist_items.checklist_id
    AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))
  ));

-- ── project_tasks ──
DROP POLICY IF EXISTS "project_tasks_select" ON public.project_tasks;
DROP POLICY IF EXISTS "project_tasks_insert" ON public.project_tasks;
DROP POLICY IF EXISTS "project_tasks_update" ON public.project_tasks;
DROP POLICY IF EXISTS "project_tasks_delete" ON public.project_tasks;

CREATE POLICY "project_tasks_select" ON public.project_tasks
  FOR SELECT TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_tasks.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "project_tasks_insert" ON public.project_tasks
  FOR INSERT TO authenticated
  WITH CHECK (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_tasks.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "project_tasks_update" ON public.project_tasks
  FOR UPDATE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_tasks.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "project_tasks_delete" ON public.project_tasks
  FOR DELETE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_tasks.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

-- ── project_members (Cross-Org SELECT + Owner/Admin Mgmt) ──
DROP POLICY IF EXISTS "members_select" ON public.project_members;
DROP POLICY IF EXISTS "members_insert" ON public.project_members;
DROP POLICY IF EXISTS "members_delete" ON public.project_members;

CREATE POLICY "members_select" ON public.project_members
  FOR SELECT TO authenticated
  USING (EXISTS (
    SELECT 1 FROM public.projects p
    WHERE p.id = project_members.project_id
    AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))
  ));

CREATE POLICY "members_insert" ON public.project_members
  FOR INSERT TO authenticated
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = project_members.project_id
      AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))
    )
    AND (
      EXISTS (
        SELECT 1 FROM project_members pm
        WHERE pm.project_id = project_members.project_id
        AND pm.profile_id = auth.uid()
        AND pm.role = 'owner'
      )
      OR public.is_admin()
    )
  );

CREATE POLICY "members_delete" ON public.project_members
  FOR DELETE TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = project_members.project_id
      AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))
    )
    AND (
      EXISTS (
        SELECT 1 FROM project_members pm
        WHERE pm.project_id = project_members.project_id
        AND pm.profile_id = auth.uid()
        AND pm.role = 'owner'
      )
      OR public.is_admin()
    )
  );

-- ── project_invitations (Cross-Org) ──
DROP POLICY IF EXISTS "invitations_select" ON public.project_invitations;
DROP POLICY IF EXISTS "invitations_insert" ON public.project_invitations;
DROP POLICY IF EXISTS "invitations_update" ON public.project_invitations;

CREATE POLICY "invitations_select" ON public.project_invitations
  FOR SELECT TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = project_invitations.project_id
      AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))
    )
    AND (invited_profile_id = auth.uid() OR invited_by = auth.uid() OR public.is_admin())
  );

CREATE POLICY "invitations_insert" ON public.project_invitations
  FOR INSERT TO authenticated
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = project_invitations.project_id
      AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))
    )
    AND (
      EXISTS (
        SELECT 1 FROM project_members
        WHERE project_id = project_invitations.project_id
        AND profile_id = auth.uid()
        AND role = 'owner'
      )
      OR public.is_admin()
    )
  );

CREATE POLICY "invitations_update" ON public.project_invitations
  FOR UPDATE TO authenticated
  USING (invited_profile_id = auth.uid());

-- ============================================================================
-- TRIGGER: Org-Removal → Pending Bookings ablehnen
-- ============================================================================

CREATE OR REPLACE FUNCTION public.handle_project_org_removal()
RETURNS trigger AS $$
BEGIN
  -- Wenn eine Partner-Org entfernt/abgelehnt wird
  IF NEW.status IN ('removed', 'declined') AND OLD.status = 'accepted' THEN
    -- Pending Buchungsanfragen von Equipment dieser Org ablehnen
    UPDATE public.bookings
    SET approval_status = 'rejected',
        rejection_reason = 'Partner-Organisation wurde vom Projekt entfernt'
    WHERE project_id = NEW.project_id
      AND approval_status = 'pending'
      AND source_org_id = NEW.org_id;
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE TRIGGER on_project_org_status_change
  AFTER UPDATE ON public.project_orgs
  FOR EACH ROW
  WHEN (OLD.status IS DISTINCT FROM NEW.status)
  EXECUTE PROCEDURE public.handle_project_org_removal();

-- ============================================================================
-- REALTIME
-- ============================================================================

ALTER PUBLICATION supabase_realtime ADD TABLE public.project_orgs;
