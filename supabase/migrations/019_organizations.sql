-- ── Migration 019: Multi-Tenant Organizations ──────────────────────────────
-- Fügt organizations + org_memberships hinzu.
-- Alle Top-Level-Tabellen bekommen org_id.
-- RLS wird auf Org-Scope umgestellt.
-- Bestehende Daten werden in eine Default-Org migriert.

-- ============================================================================
-- 1. NEUE TABELLEN
-- ============================================================================

-- 1a. Organizations
CREATE TABLE public.organizations (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  name text NOT NULL,
  slug text NOT NULL UNIQUE,
  description text,
  logo_url text,
  created_by uuid REFERENCES auth.users(id),
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now()
);

CREATE UNIQUE INDEX idx_organizations_slug ON public.organizations(slug);
ALTER TABLE public.organizations ENABLE ROW LEVEL SECURITY;

-- 1b. Org-Memberships (ersetzt profiles.role_id + profiles.is_active pro Org)
CREATE TABLE public.org_memberships (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  org_id uuid REFERENCES public.organizations(id) ON DELETE CASCADE NOT NULL,
  profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE NOT NULL,
  role_id uuid REFERENCES public.roles(id) NOT NULL,
  is_active boolean NOT NULL DEFAULT false,
  approved_at timestamptz,
  created_at timestamptz DEFAULT now(),
  UNIQUE(org_id, profile_id)
);

CREATE INDEX idx_org_memberships_org ON public.org_memberships(org_id);
CREATE INDEX idx_org_memberships_profile ON public.org_memberships(profile_id);
CREATE INDEX idx_org_memberships_lookup ON public.org_memberships(org_id, profile_id, is_active);
ALTER TABLE public.org_memberships ENABLE ROW LEVEL SECURITY;

-- ============================================================================
-- 2. ORG_ID AUF BESTEHENDEN TABELLEN (nullable erstmal)
-- ============================================================================

ALTER TABLE public.projects ADD COLUMN org_id uuid REFERENCES public.organizations(id);
ALTER TABLE public.inventory_items ADD COLUMN org_id uuid REFERENCES public.organizations(id);
ALTER TABLE public.team_votes ADD COLUMN org_id uuid REFERENCES public.organizations(id);
ALTER TABLE public.roles ADD COLUMN org_id uuid REFERENCES public.organizations(id);

CREATE INDEX idx_projects_org ON public.projects(org_id);
CREATE INDEX idx_inventory_org ON public.inventory_items(org_id);
CREATE INDEX idx_team_votes_org ON public.team_votes(org_id);
CREATE INDEX idx_roles_org ON public.roles(org_id);

-- ============================================================================
-- 3. DATENMIGRATION — Default-Org erstellen + bestehende Daten zuordnen
-- ============================================================================

-- Default-Org mit fester UUID
INSERT INTO public.organizations (id, name, slug, description)
VALUES (
  'a0000000-0000-0000-0000-000000000001',
  'Dunkelstrom',
  'dunkelstrom',
  'Standard-Organisation'
);

-- Bestehende Rollen der Default-Org zuordnen
UPDATE public.roles
SET org_id = 'a0000000-0000-0000-0000-000000000001'
WHERE org_id IS NULL;

-- roles.name war UNIQUE → für Multi-Org brauchen wir UNIQUE(name, org_id)
ALTER TABLE public.roles DROP CONSTRAINT IF EXISTS roles_name_key;
ALTER TABLE public.roles ADD CONSTRAINT roles_name_org_unique UNIQUE(name, org_id);

-- Bestehende Profile → org_memberships (role_id + is_active + approved_at übernehmen)
INSERT INTO public.org_memberships (org_id, profile_id, role_id, is_active, approved_at)
SELECT
  'a0000000-0000-0000-0000-000000000001',
  p.id,
  p.role_id,
  p.is_active,
  p.approved_at
FROM public.profiles p
WHERE p.role_id IS NOT NULL;

-- Alle bestehenden Projekte → Default-Org
UPDATE public.projects
SET org_id = 'a0000000-0000-0000-0000-000000000001'
WHERE org_id IS NULL;

-- Alle bestehenden Inventar-Items → Default-Org
UPDATE public.inventory_items
SET org_id = 'a0000000-0000-0000-0000-000000000001'
WHERE org_id IS NULL;

-- Alle bestehenden Team-Votes → Default-Org
UPDATE public.team_votes
SET org_id = 'a0000000-0000-0000-0000-000000000001'
WHERE org_id IS NULL;

-- NOT NULL Constraints setzen
ALTER TABLE public.projects ALTER COLUMN org_id SET NOT NULL;
ALTER TABLE public.inventory_items ALTER COLUMN org_id SET NOT NULL;
ALTER TABLE public.team_votes ALTER COLUMN org_id SET NOT NULL;

-- ============================================================================
-- 4. HELPER-FUNKTIONEN
-- ============================================================================

-- Prüft ob der aktuelle User Admin in einer bestimmten Org ist
CREATE OR REPLACE FUNCTION public.is_org_admin(p_org_id uuid)
RETURNS boolean AS $$
BEGIN
  RETURN EXISTS (
    SELECT 1 FROM public.org_memberships om
    JOIN public.roles r ON r.id = om.role_id
    WHERE om.org_id = p_org_id
      AND om.profile_id = auth.uid()
      AND r.name = 'admin'
  ) OR EXISTS (
    SELECT 1 FROM public.profiles
    WHERE id = auth.uid() AND is_system = true
  );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

-- Prüft ob der aktuelle User aktives Mitglied einer Org ist
CREATE OR REPLACE FUNCTION public.is_org_member(p_org_id uuid)
RETURNS boolean AS $$
BEGIN
  RETURN EXISTS (
    SELECT 1 FROM public.org_memberships
    WHERE org_id = p_org_id
      AND profile_id = auth.uid()
      AND is_active = true
  ) OR EXISTS (
    SELECT 1 FROM public.profiles
    WHERE id = auth.uid() AND is_system = true
  );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

-- Prüft ob der aktuelle User Mitglied einer Org ist (aktiv ODER inaktiv)
-- SECURITY DEFINER um RLS-Recursion zu vermeiden (wird in Policies benutzt)
CREATE OR REPLACE FUNCTION public.is_org_member_of(p_org_id uuid)
RETURNS boolean AS $$
  SELECT EXISTS (
    SELECT 1 FROM public.org_memberships
    WHERE org_id = p_org_id AND profile_id = auth.uid()
  ) OR EXISTS (
    SELECT 1 FROM public.profiles
    WHERE id = auth.uid() AND is_system = true
  );
$$ LANGUAGE sql SECURITY DEFINER STABLE;

-- is_admin() bleibt bestehen (für Backward-Compat), aber intern unverändert
-- (aus Migration 018: prüft roles.name='admin' OR is_system=true)

-- ============================================================================
-- 5. RLS POLICIES — Organizations
-- ============================================================================

-- Org sichtbar für Mitglieder (via SECURITY DEFINER Funktion, keine RLS-Recursion)
CREATE POLICY "org_select" ON public.organizations
  FOR SELECT TO authenticated
  USING (public.is_org_member_of(id));

-- Jeder authentifizierte User kann Org erstellen
CREATE POLICY "org_insert" ON public.organizations
  FOR INSERT TO authenticated
  WITH CHECK (true);

-- Nur Org-Admins können Org bearbeiten
CREATE POLICY "org_update" ON public.organizations
  FOR UPDATE TO authenticated
  USING (public.is_org_admin(id));

-- ============================================================================
-- 6. RLS POLICIES — Org-Memberships
-- ============================================================================

-- Mitglieder derselben Org sehen sich gegenseitig (via SECURITY DEFINER Funktion, keine RLS-Recursion)
CREATE POLICY "org_memberships_select" ON public.org_memberships
  FOR SELECT TO authenticated
  USING (public.is_org_member_of(org_id));

-- Eigene Mitgliedschaft erstellen (bei Org-Join) oder Admin fügt hinzu
CREATE POLICY "org_memberships_insert" ON public.org_memberships
  FOR INSERT TO authenticated
  WITH CHECK (
    profile_id = auth.uid()
    OR public.is_org_admin(org_id)
  );

-- Org-Admins können Mitgliedschaften bearbeiten (Rolle ändern, aktivieren)
CREATE POLICY "org_memberships_update" ON public.org_memberships
  FOR UPDATE TO authenticated
  USING (public.is_org_admin(org_id))
  WITH CHECK (public.is_org_admin(org_id));

-- User kann selbst austreten, Admin kann entfernen
CREATE POLICY "org_memberships_delete" ON public.org_memberships
  FOR DELETE TO authenticated
  USING (
    profile_id = auth.uid()
    OR public.is_org_admin(org_id)
  );

-- ============================================================================
-- 7. RLS POLICIES — Projects (Org-Scoped)
-- ============================================================================

DROP POLICY IF EXISTS "Projekte lesen" ON public.projects;
DROP POLICY IF EXISTS "Projekte erstellen" ON public.projects;
DROP POLICY IF EXISTS "Projekte bearbeiten" ON public.projects;
DROP POLICY IF EXISTS "Projekte löschen" ON public.projects;

CREATE POLICY "projects_select" ON public.projects
  FOR SELECT TO authenticated
  USING (public.is_org_member(org_id));

CREATE POLICY "projects_insert" ON public.projects
  FOR INSERT TO authenticated
  WITH CHECK (public.is_org_member(org_id));

CREATE POLICY "projects_update" ON public.projects
  FOR UPDATE TO authenticated
  USING (public.is_org_member(org_id));

CREATE POLICY "projects_delete" ON public.projects
  FOR DELETE TO authenticated
  USING (public.is_org_member(org_id));

-- ============================================================================
-- 8. RLS POLICIES — Inventory (Org-Scoped)
-- ============================================================================

DROP POLICY IF EXISTS "Inventar lesen" ON public.inventory_items;
DROP POLICY IF EXISTS "Inventar erstellen" ON public.inventory_items;
DROP POLICY IF EXISTS "Inventar bearbeiten" ON public.inventory_items;
DROP POLICY IF EXISTS "Inventar löschen" ON public.inventory_items;

CREATE POLICY "inventory_select" ON public.inventory_items
  FOR SELECT TO authenticated
  USING (public.is_org_member(org_id));

CREATE POLICY "inventory_insert" ON public.inventory_items
  FOR INSERT TO authenticated
  WITH CHECK (public.is_org_member(org_id));

CREATE POLICY "inventory_update" ON public.inventory_items
  FOR UPDATE TO authenticated
  USING (public.is_org_member(org_id));

CREATE POLICY "inventory_delete" ON public.inventory_items
  FOR DELETE TO authenticated
  USING (public.is_org_member(org_id));

-- ============================================================================
-- 9. RLS POLICIES — Bookings (erbt Org via projects FK)
-- ============================================================================

DROP POLICY IF EXISTS "Buchungen lesen" ON public.bookings;
DROP POLICY IF EXISTS "Buchungen erstellen" ON public.bookings;
DROP POLICY IF EXISTS "Buchungen bearbeiten" ON public.bookings;
DROP POLICY IF EXISTS "Buchungen löschen" ON public.bookings;

CREATE POLICY "bookings_select" ON public.bookings
  FOR SELECT TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = bookings.project_id
      AND public.is_org_member(p.org_id)
    )
  );

CREATE POLICY "bookings_insert" ON public.bookings
  FOR INSERT TO authenticated
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = bookings.project_id
      AND public.is_org_member(p.org_id)
    )
  );

CREATE POLICY "bookings_update" ON public.bookings
  FOR UPDATE TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = bookings.project_id
      AND public.is_org_member(p.org_id)
    )
  );

CREATE POLICY "bookings_delete" ON public.bookings
  FOR DELETE TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = bookings.project_id
      AND public.is_org_member(p.org_id)
    )
  );

-- ============================================================================
-- 10. RLS POLICIES — Cost Items (Org + Projekt-Mitglied/Admin)
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
      AND public.is_org_member(p.org_id)
    )
    AND (public.is_project_member(project_id) OR public.is_admin())
  );

CREATE POLICY "cost_items_insert" ON public.cost_items
  FOR INSERT TO authenticated
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = cost_items.project_id
      AND public.is_org_member(p.org_id)
    )
    AND (public.is_project_member(project_id) OR public.is_admin())
  );

CREATE POLICY "cost_items_update" ON public.cost_items
  FOR UPDATE TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = cost_items.project_id
      AND public.is_org_member(p.org_id)
    )
    AND (public.is_project_member(project_id) OR public.is_admin())
  );

CREATE POLICY "cost_items_delete" ON public.cost_items
  FOR DELETE TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = cost_items.project_id
      AND public.is_org_member(p.org_id)
    )
    AND (public.is_project_member(project_id) OR public.is_admin())
  );

-- ============================================================================
-- 11. RLS POLICIES — Team-Votes (Org-Scoped)
-- ============================================================================

DROP POLICY IF EXISTS "team_votes_select" ON public.team_votes;
DROP POLICY IF EXISTS "team_votes_insert" ON public.team_votes;
DROP POLICY IF EXISTS "team_votes_delete" ON public.team_votes;

CREATE POLICY "team_votes_select" ON public.team_votes
  FOR SELECT TO authenticated
  USING (public.is_org_member(org_id));

CREATE POLICY "team_votes_insert" ON public.team_votes
  FOR INSERT TO authenticated
  WITH CHECK (
    voter_id = auth.uid()
    AND public.is_org_member(org_id)
    AND EXISTS (
      SELECT 1 FROM public.org_memberships
      WHERE org_id = team_votes.org_id
      AND profile_id = auth.uid()
      AND is_active = true
    )
  );

CREATE POLICY "team_votes_delete" ON public.team_votes
  FOR DELETE TO authenticated
  USING (voter_id = auth.uid());

-- ============================================================================
-- 12. RLS POLICIES — Kind-Tabellen via projects (Org-Scoped)
-- ============================================================================

-- project_schedule
DROP POLICY IF EXISTS "schedule_select" ON public.project_schedule;
DROP POLICY IF EXISTS "schedule_insert" ON public.project_schedule;
DROP POLICY IF EXISTS "schedule_update" ON public.project_schedule;
DROP POLICY IF EXISTS "schedule_delete" ON public.project_schedule;

CREATE POLICY "schedule_select" ON public.project_schedule
  FOR SELECT TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_schedule.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "schedule_insert" ON public.project_schedule
  FOR INSERT TO authenticated
  WITH CHECK (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_schedule.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "schedule_update" ON public.project_schedule
  FOR UPDATE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_schedule.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "schedule_delete" ON public.project_schedule
  FOR DELETE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_schedule.project_id AND public.is_org_member(p.org_id)));

-- project_team
DROP POLICY IF EXISTS "team_select" ON public.project_team;
DROP POLICY IF EXISTS "team_insert" ON public.project_team;
DROP POLICY IF EXISTS "team_update" ON public.project_team;
DROP POLICY IF EXISTS "team_delete" ON public.project_team;

CREATE POLICY "team_select" ON public.project_team
  FOR SELECT TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_team.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "team_insert" ON public.project_team
  FOR INSERT TO authenticated
  WITH CHECK (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_team.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "team_update" ON public.project_team
  FOR UPDATE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_team.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "team_delete" ON public.project_team
  FOR DELETE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_team.project_id AND public.is_org_member(p.org_id)));

-- project_contacts
DROP POLICY IF EXISTS "contacts_select" ON public.project_contacts;
DROP POLICY IF EXISTS "contacts_insert" ON public.project_contacts;
DROP POLICY IF EXISTS "contacts_update" ON public.project_contacts;
DROP POLICY IF EXISTS "contacts_delete" ON public.project_contacts;

CREATE POLICY "contacts_select" ON public.project_contacts
  FOR SELECT TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_contacts.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "contacts_insert" ON public.project_contacts
  FOR INSERT TO authenticated
  WITH CHECK (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_contacts.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "contacts_update" ON public.project_contacts
  FOR UPDATE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_contacts.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "contacts_delete" ON public.project_contacts
  FOR DELETE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_contacts.project_id AND public.is_org_member(p.org_id)));

-- project_consumables
DROP POLICY IF EXISTS "consumables_select" ON public.project_consumables;
DROP POLICY IF EXISTS "consumables_insert" ON public.project_consumables;
DROP POLICY IF EXISTS "consumables_update" ON public.project_consumables;
DROP POLICY IF EXISTS "consumables_delete" ON public.project_consumables;

CREATE POLICY "consumables_select" ON public.project_consumables
  FOR SELECT TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_consumables.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "consumables_insert" ON public.project_consumables
  FOR INSERT TO authenticated
  WITH CHECK (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_consumables.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "consumables_update" ON public.project_consumables
  FOR UPDATE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_consumables.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "consumables_delete" ON public.project_consumables
  FOR DELETE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_consumables.project_id AND public.is_org_member(p.org_id)));

-- project_checklists
DROP POLICY IF EXISTS "checklists_select" ON public.project_checklists;
DROP POLICY IF EXISTS "checklists_insert" ON public.project_checklists;
DROP POLICY IF EXISTS "checklists_update" ON public.project_checklists;
DROP POLICY IF EXISTS "checklists_delete" ON public.project_checklists;

CREATE POLICY "checklists_select" ON public.project_checklists
  FOR SELECT TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_checklists.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "checklists_insert" ON public.project_checklists
  FOR INSERT TO authenticated
  WITH CHECK (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_checklists.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "checklists_update" ON public.project_checklists
  FOR UPDATE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_checklists.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "checklists_delete" ON public.project_checklists
  FOR DELETE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_checklists.project_id AND public.is_org_member(p.org_id)));

-- project_checklist_items (erbt via checklists → projects)
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
    AND public.is_org_member(p.org_id)
  ));

CREATE POLICY "checklist_items_insert" ON public.project_checklist_items
  FOR INSERT TO authenticated
  WITH CHECK (EXISTS (
    SELECT 1 FROM public.project_checklists cl
    JOIN public.projects p ON p.id = cl.project_id
    WHERE cl.id = project_checklist_items.checklist_id
    AND public.is_org_member(p.org_id)
  ));

CREATE POLICY "checklist_items_update" ON public.project_checklist_items
  FOR UPDATE TO authenticated
  USING (EXISTS (
    SELECT 1 FROM public.project_checklists cl
    JOIN public.projects p ON p.id = cl.project_id
    WHERE cl.id = project_checklist_items.checklist_id
    AND public.is_org_member(p.org_id)
  ));

CREATE POLICY "checklist_items_delete" ON public.project_checklist_items
  FOR DELETE TO authenticated
  USING (EXISTS (
    SELECT 1 FROM public.project_checklists cl
    JOIN public.projects p ON p.id = cl.project_id
    WHERE cl.id = project_checklist_items.checklist_id
    AND public.is_org_member(p.org_id)
  ));

-- project_tasks
DROP POLICY IF EXISTS "project_tasks_select" ON public.project_tasks;
DROP POLICY IF EXISTS "project_tasks_insert" ON public.project_tasks;
DROP POLICY IF EXISTS "project_tasks_update" ON public.project_tasks;
DROP POLICY IF EXISTS "project_tasks_delete" ON public.project_tasks;

CREATE POLICY "project_tasks_select" ON public.project_tasks
  FOR SELECT TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_tasks.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "project_tasks_insert" ON public.project_tasks
  FOR INSERT TO authenticated
  WITH CHECK (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_tasks.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "project_tasks_update" ON public.project_tasks
  FOR UPDATE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_tasks.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "project_tasks_delete" ON public.project_tasks
  FOR DELETE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_tasks.project_id AND public.is_org_member(p.org_id)));

-- project_members (Org-Check über projects FK)
DROP POLICY IF EXISTS "members_select" ON public.project_members;
DROP POLICY IF EXISTS "members_insert" ON public.project_members;
DROP POLICY IF EXISTS "members_delete" ON public.project_members;

CREATE POLICY "members_select" ON public.project_members
  FOR SELECT TO authenticated
  USING (EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_members.project_id AND public.is_org_member(p.org_id)));

CREATE POLICY "members_insert" ON public.project_members
  FOR INSERT TO authenticated
  WITH CHECK (
    EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_members.project_id AND public.is_org_member(p.org_id))
    AND (
      EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = project_members.project_id AND pm.profile_id = auth.uid() AND pm.role = 'owner')
      OR public.is_admin()
    )
  );

CREATE POLICY "members_delete" ON public.project_members
  FOR DELETE TO authenticated
  USING (
    EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_members.project_id AND public.is_org_member(p.org_id))
    AND (
      EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = project_members.project_id AND pm.profile_id = auth.uid() AND pm.role = 'owner')
      OR public.is_admin()
    )
  );

-- project_invitations (Org-Check über projects FK)
DROP POLICY IF EXISTS "invitations_select" ON public.project_invitations;
DROP POLICY IF EXISTS "invitations_insert" ON public.project_invitations;
DROP POLICY IF EXISTS "invitations_update" ON public.project_invitations;

CREATE POLICY "invitations_select" ON public.project_invitations
  FOR SELECT TO authenticated
  USING (
    EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_invitations.project_id AND public.is_org_member(p.org_id))
    AND (invited_profile_id = auth.uid() OR invited_by = auth.uid() OR public.is_admin())
  );

CREATE POLICY "invitations_insert" ON public.project_invitations
  FOR INSERT TO authenticated
  WITH CHECK (
    EXISTS (SELECT 1 FROM public.projects p WHERE p.id = project_invitations.project_id AND public.is_org_member(p.org_id))
    AND (
      EXISTS (SELECT 1 FROM project_members WHERE project_id = project_invitations.project_id AND profile_id = auth.uid() AND role = 'owner')
      OR public.is_admin()
    )
  );

CREATE POLICY "invitations_update" ON public.project_invitations
  FOR UPDATE TO authenticated
  USING (invited_profile_id = auth.uid());

-- ============================================================================
-- 13. TRIGGER — Neue Org → Creator wird Admin
-- ============================================================================

CREATE OR REPLACE FUNCTION public.handle_new_org()
RETURNS trigger AS $$
DECLARE
  v_admin_role_id uuid;
BEGIN
  -- Template-Rollen von Default-Org kopieren für neue Org
  INSERT INTO public.roles (name, permissions, org_id)
  SELECT name, permissions, NEW.id
  FROM public.roles
  WHERE org_id = 'a0000000-0000-0000-0000-000000000001'
  AND name IN ('admin', 'manager', 'member');

  -- Admin-Rolle der neuen Org finden
  SELECT id INTO v_admin_role_id
  FROM public.roles
  WHERE name = 'admin' AND org_id = NEW.id;

  -- Creator als Admin + aktiv eintragen
  IF NEW.created_by IS NOT NULL AND v_admin_role_id IS NOT NULL THEN
    INSERT INTO public.org_memberships (org_id, profile_id, role_id, is_active, approved_at)
    VALUES (NEW.id, NEW.created_by, v_admin_role_id, true, now())
    ON CONFLICT (org_id, profile_id) DO NOTHING;
  END IF;

  RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE TRIGGER on_org_created
  AFTER INSERT ON public.organizations
  FOR EACH ROW EXECUTE PROCEDURE public.handle_new_org();

-- ============================================================================
-- 14. REALTIME — Neue Tabellen zur Publication hinzufügen
-- ============================================================================

ALTER PUBLICATION supabase_realtime ADD TABLE public.organizations;
ALTER PUBLICATION supabase_realtime ADD TABLE public.org_memberships;

-- ============================================================================
-- 15. FERTIG
-- ============================================================================
-- HINWEIS: profiles.role_id, profiles.is_active, profiles.approved_at bleiben
-- vorerst bestehen (Backward-Compat). Cleanup kommt in einer späteren Migration.
