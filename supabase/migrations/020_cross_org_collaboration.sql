-- ── Migration 020: Cross-Org Collaboration (IDEMPOTENT) ─────────────────────
-- Ermöglicht Freelancer-Collaboration: Mehrere Orgs können als Partner an
-- einem Projekt teilnehmen und gegenseitig Equipment buchen (mit Genehmigung).
--
-- Neue Tabelle: project_orgs (Projekt ↔ Org Zuordnung)
-- Neue Spalten: bookings bekommt Approval-Workflow
-- RLS-Updates: Cross-Org Zugriff auf Projekte + Inventar + Kind-Tabellen
--
-- HINWEIS: Diese Migration ist idempotent und kann mehrfach ausgeführt werden.

-- ============================================================================
-- 1. NEUE TABELLE: project_orgs
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.project_orgs (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  project_id uuid REFERENCES public.projects(id) ON DELETE CASCADE NOT NULL,
  org_id uuid REFERENCES public.organizations(id) ON DELETE CASCADE NOT NULL,
  role text NOT NULL DEFAULT 'partner'
    CHECK (role IN ('creator', 'partner')),
  status text NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending', 'accepted', 'declined', 'removed')),
  invited_by uuid REFERENCES public.profiles(id),
  responded_at timestamptz,
  created_at timestamptz DEFAULT now(),
  UNIQUE(project_id, org_id)
);

CREATE INDEX IF NOT EXISTS idx_project_orgs_project ON public.project_orgs(project_id);
CREATE INDEX IF NOT EXISTS idx_project_orgs_org ON public.project_orgs(org_id);
CREATE INDEX IF NOT EXISTS idx_project_orgs_status ON public.project_orgs(project_id, status);
ALTER TABLE public.project_orgs ENABLE ROW LEVEL SECURITY;

-- ============================================================================
-- 2. TRIGGER: Auto-Insert creator org bei Projekt-Erstellung
-- ============================================================================

CREATE OR REPLACE FUNCTION public.handle_new_project_org()
RETURNS trigger AS $$
BEGIN
  INSERT INTO public.project_orgs (project_id, org_id, role, status, invited_by, responded_at)
  VALUES (NEW.id, NEW.org_id, 'creator', 'accepted', NEW.created_by, now())
  ON CONFLICT (project_id, org_id) DO NOTHING;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

DROP TRIGGER IF EXISTS on_project_created_add_org ON public.projects;
CREATE TRIGGER on_project_created_add_org
  AFTER INSERT ON public.projects
  FOR EACH ROW EXECUTE PROCEDURE public.handle_new_project_org();

-- ============================================================================
-- 3. BACKFILL: Bestehende Projekte → project_orgs
-- ============================================================================

INSERT INTO public.project_orgs (project_id, org_id, role, status, responded_at)
SELECT id, org_id, 'creator', 'accepted', now()
FROM public.projects
ON CONFLICT (project_id, org_id) DO NOTHING;

-- ============================================================================
-- 4. BOOKING APPROVAL SPALTEN
-- ============================================================================

ALTER TABLE public.bookings ADD COLUMN IF NOT EXISTS requested_by uuid REFERENCES public.profiles(id);
ALTER TABLE public.bookings ADD COLUMN IF NOT EXISTS approval_status text DEFAULT 'approved';
-- CHECK Constraint separat (idempotent via DO block)
DO $$ BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.check_constraints
    WHERE constraint_name = 'bookings_approval_status_check'
  ) THEN
    ALTER TABLE public.bookings ADD CONSTRAINT bookings_approval_status_check
      CHECK (approval_status IN ('pending', 'approved', 'rejected'));
  END IF;
END $$;
ALTER TABLE public.bookings ADD COLUMN IF NOT EXISTS approved_by uuid REFERENCES public.profiles(id);
ALTER TABLE public.bookings ADD COLUMN IF NOT EXISTS approved_at timestamptz;
ALTER TABLE public.bookings ADD COLUMN IF NOT EXISTS rejection_reason text;
ALTER TABLE public.bookings ADD COLUMN IF NOT EXISTS source_org_id uuid REFERENCES public.organizations(id);

CREATE INDEX IF NOT EXISTS idx_bookings_approval ON public.bookings(approval_status);
CREATE INDEX IF NOT EXISTS idx_bookings_source_org ON public.bookings(source_org_id);

-- ============================================================================
-- 5. HELPER-FUNKTIONEN
-- ============================================================================

-- Prüft ob der aktuelle User über eine seiner Orgs an einem Projekt beteiligt ist
CREATE OR REPLACE FUNCTION public.is_project_org_member(p_project_id uuid)
RETURNS boolean AS $$
BEGIN
  RETURN EXISTS (
    SELECT 1
    FROM public.project_orgs po
    JOIN public.org_memberships om ON om.org_id = po.org_id
    WHERE po.project_id = p_project_id
      AND po.status = 'accepted'
      AND om.profile_id = auth.uid()
      AND om.is_active = true
  ) OR EXISTS (
    SELECT 1 FROM public.profiles
    WHERE id = auth.uid() AND is_system = true
  );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

-- Gibt die Org-IDs zurück über die der User Zugang zu einem Projekt hat
CREATE OR REPLACE FUNCTION public.get_user_project_org_ids(p_project_id uuid)
RETURNS SETOF uuid AS $$
  SELECT po.org_id
  FROM public.project_orgs po
  JOIN public.org_memberships om ON om.org_id = po.org_id
  WHERE po.project_id = p_project_id
    AND po.status = 'accepted'
    AND om.profile_id = auth.uid()
    AND om.is_active = true;
$$ LANGUAGE sql SECURITY DEFINER STABLE;

-- ============================================================================
-- 6. RLS POLICIES — project_orgs
-- ============================================================================

-- SELECT: Sichtbar für alle Projekt-Beteiligten (eigene Org ODER akzeptierter Partner)
DROP POLICY IF EXISTS "project_orgs_select" ON public.project_orgs;
CREATE POLICY "project_orgs_select" ON public.project_orgs
  FOR SELECT TO authenticated
  USING (
    public.is_org_member(org_id)
    OR public.is_project_org_member(project_id)
  );

-- INSERT: Nur Projekt-Owner oder Org-Admin der Creator-Org
DROP POLICY IF EXISTS "project_orgs_insert" ON public.project_orgs;
CREATE POLICY "project_orgs_insert" ON public.project_orgs
  FOR INSERT TO authenticated
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = project_orgs.project_id
      AND public.is_org_member(p.org_id)
    )
    AND (
      EXISTS (
        SELECT 1 FROM public.project_members pm
        WHERE pm.project_id = project_orgs.project_id
        AND pm.profile_id = auth.uid()
        AND pm.role = 'owner'
      )
      OR public.is_admin()
    )
  );

-- UPDATE: Eingeladene Org kann annehmen/ablehnen (Mitglied der eingeladenen Org)
DROP POLICY IF EXISTS "project_orgs_update" ON public.project_orgs;
CREATE POLICY "project_orgs_update" ON public.project_orgs
  FOR UPDATE TO authenticated
  USING (
    public.is_org_member(org_id)
    OR public.is_org_admin(org_id)
  );

-- DELETE: Creator-Org Owner/Admin
DROP POLICY IF EXISTS "project_orgs_delete" ON public.project_orgs;
CREATE POLICY "project_orgs_delete" ON public.project_orgs
  FOR DELETE TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = project_orgs.project_id
      AND public.is_org_member(p.org_id)
    )
    AND (
      EXISTS (
        SELECT 1 FROM public.project_members pm
        WHERE pm.project_id = project_orgs.project_id
        AND pm.profile_id = auth.uid()
        AND pm.role = 'owner'
      )
      OR public.is_admin()
    )
  );

-- ============================================================================
-- 7. RLS UPDATES — Projects (Cross-Org SELECT)
-- ============================================================================

DROP POLICY IF EXISTS "projects_select" ON public.projects;
CREATE POLICY "projects_select" ON public.projects
  FOR SELECT TO authenticated
  USING (
    public.is_org_member(org_id)
    OR public.is_project_org_member(id)
  );

-- INSERT/UPDATE/DELETE bleiben: nur Creator-Org Mitglieder

-- ============================================================================
-- 8. RLS UPDATES — Inventory (Cross-Org SELECT für Partner-Projekte)
-- ============================================================================

-- Bestehende inventory_select bleibt (eigene Org sieht alles)
-- Neue Policy: Partner-Org Inventar sichtbar wenn beide Orgs in einem Projekt sind
DROP POLICY IF EXISTS "inventory_select_cross_org" ON public.inventory_items;
CREATE POLICY "inventory_select_cross_org" ON public.inventory_items
  FOR SELECT TO authenticated
  USING (
    EXISTS (
      -- Es gibt ein Projekt wo SOWOHL die Item-Org als auch MEINE Org Partner sind
      SELECT 1
      FROM public.project_orgs po_item
      WHERE po_item.org_id = inventory_items.org_id
        AND po_item.status = 'accepted'
        AND EXISTS (
          SELECT 1 FROM public.project_orgs po_me
          JOIN public.org_memberships om ON om.org_id = po_me.org_id
          WHERE po_me.project_id = po_item.project_id
            AND po_me.status = 'accepted'
            AND om.profile_id = auth.uid()
            AND om.is_active = true
            AND po_me.org_id != po_item.org_id
        )
    )
  );

-- INSERT/UPDATE/DELETE bleiben: nur eigene Org

-- ============================================================================
-- 9. RLS UPDATES — Bookings (Cross-Org)
-- ============================================================================

DROP POLICY IF EXISTS "bookings_select" ON public.bookings;
DROP POLICY IF EXISTS "bookings_insert" ON public.bookings;
DROP POLICY IF EXISTS "bookings_update" ON public.bookings;
DROP POLICY IF EXISTS "bookings_delete" ON public.bookings;

CREATE POLICY "bookings_select" ON public.bookings
  FOR SELECT TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = bookings.project_id
      AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))
    )
  );

CREATE POLICY "bookings_insert" ON public.bookings
  FOR INSERT TO authenticated
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = bookings.project_id
      AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))
    )
  );

-- UPDATE: Requester, Source-Org Mitglied, oder Creator-Org Mitglied
CREATE POLICY "bookings_update" ON public.bookings
  FOR UPDATE TO authenticated
  USING (
    requested_by = auth.uid()
    OR (source_org_id IS NOT NULL AND public.is_org_member(source_org_id))
    OR EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = bookings.project_id
      AND public.is_org_member(p.org_id)
    )
  );

CREATE POLICY "bookings_delete" ON public.bookings
  FOR DELETE TO authenticated
  USING (
    requested_by = auth.uid()
    OR EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = bookings.project_id
      AND public.is_org_member(p.org_id)
    )
  );

-- ============================================================================
-- 10. RLS UPDATES — Cost Items (Cross-Org)
-- ============================================================================

DROP POLICY IF EXISTS "cost_items_select" ON public.cost_items;
DROP POLICY IF EXISTS "cost_items_insert" ON public.cost_items;
DROP POLICY IF EXISTS "cost_items_update" ON public.cost_items;
DROP POLICY IF EXISTS "cost_items_delete" ON public.cost_items;

CREATE POLICY "cost_items_select" ON public.cost_items
  FOR SELECT TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = cost_items.project_id
      AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))
    )
    AND (public.is_project_member(project_id) OR public.is_admin())
  );

CREATE POLICY "cost_items_insert" ON public.cost_items
  FOR INSERT TO authenticated
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = cost_items.project_id
      AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))
    )
    AND (public.is_project_member(project_id) OR public.is_admin())
  );

CREATE POLICY "cost_items_update" ON public.cost_items
  FOR UPDATE TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = cost_items.project_id
      AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))
    )
    AND (public.is_project_member(project_id) OR public.is_admin())
  );

CREATE POLICY "cost_items_delete" ON public.cost_items
  FOR DELETE TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = cost_items.project_id
      AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))
    )
    AND (public.is_project_member(project_id) OR public.is_admin())
  );

-- ============================================================================
-- 11. RLS UPDATES — Kind-Tabellen (Cross-Org SELECT/INSERT/UPDATE/DELETE)
-- ============================================================================

-- Makro-Pattern: is_org_member(p.org_id) OR is_project_org_member(p.id)

-- ── project_schedule ──
DROP POLICY IF EXISTS "schedule_select" ON public.project_schedule;
DROP POLICY IF EXISTS "schedule_insert" ON public.project_schedule;
DROP POLICY IF EXISTS "schedule_update" ON public.project_schedule;
DROP POLICY IF EXISTS "schedule_delete" ON public.project_schedule;

CREATE POLICY "schedule_select" ON public.project_schedule
  FOR SELECT TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_schedule.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "schedule_insert" ON public.project_schedule
  FOR INSERT TO authenticated
  WITH CHECK (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_schedule.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "schedule_update" ON public.project_schedule
  FOR UPDATE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_schedule.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

CREATE POLICY "schedule_delete" ON public.project_schedule
  FOR DELETE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_schedule.project_id AND (public.is_org_member(p.org_id) OR public.is_project_org_member(p.id))));

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
-- 12. TRIGGER: Org-Removal → Pending Bookings ablehnen
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

DROP TRIGGER IF EXISTS on_project_org_status_change ON public.project_orgs;
CREATE TRIGGER on_project_org_status_change
  AFTER UPDATE ON public.project_orgs
  FOR EACH ROW
  WHEN (OLD.status IS DISTINCT FROM NEW.status)
  EXECUTE PROCEDURE public.handle_project_org_removal();

-- ============================================================================
-- 13. REALTIME
-- ============================================================================

-- Idempotent: ignoriert Fehler wenn Table schon in Publication ist
DO $$ BEGIN
  ALTER PUBLICATION supabase_realtime ADD TABLE public.project_orgs;
EXCEPTION WHEN duplicate_object THEN
  NULL;
END $$;

-- ============================================================================
-- 14. FERTIG
-- ============================================================================
-- Bestehende Daten bleiben vollständig intakt:
-- - Alle Projekte haben creator-Eintrag in project_orgs (via Backfill)
-- - Alle bestehenden Bookings haben approval_status='approved' (Default)
-- - Bestehende Single-Org-Workflows funktionieren unverändert
