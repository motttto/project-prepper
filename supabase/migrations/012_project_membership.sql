-- Migration 012: Projekt-Mitgliedschaft, Einladungen & Sichtbarkeitssteuerung
-- ============================================================================

-- ── 1. Tabellen ──────────────────────────────────────────────────────────────

-- Projekt-Mitglieder: verknüpft Profiles mit Projekten
CREATE TABLE public.project_members (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  project_id uuid REFERENCES public.projects(id) ON DELETE CASCADE NOT NULL,
  profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE NOT NULL,
  role text NOT NULL DEFAULT 'editor'
    CHECK (role IN ('owner', 'editor', 'viewer')),
  created_at timestamptz DEFAULT now(),
  UNIQUE(project_id, profile_id)
);

CREATE INDEX idx_project_members_project ON public.project_members(project_id);
CREATE INDEX idx_project_members_profile ON public.project_members(profile_id);

-- Einladungen zu Projekten
CREATE TABLE public.project_invitations (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  project_id uuid REFERENCES public.projects(id) ON DELETE CASCADE NOT NULL,
  invited_by uuid REFERENCES public.profiles(id) ON DELETE CASCADE NOT NULL,
  invited_profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE NOT NULL,
  role text NOT NULL DEFAULT 'editor'
    CHECK (role IN ('editor', 'viewer')),
  status text NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending', 'accepted', 'declined')),
  created_at timestamptz DEFAULT now(),
  responded_at timestamptz,
  UNIQUE(project_id, invited_profile_id)
);

CREATE INDEX idx_project_invitations_invited ON public.project_invitations(invited_profile_id, status);
CREATE INDEX idx_project_invitations_project ON public.project_invitations(project_id);

-- ── 2. Trigger: Auto-Owner bei Projekterstellung ─────────────────────────────

CREATE OR REPLACE FUNCTION public.handle_new_project_owner()
RETURNS trigger AS $$
BEGIN
  IF NEW.created_by IS NOT NULL THEN
    INSERT INTO public.project_members (project_id, profile_id, role)
    VALUES (NEW.id, NEW.created_by, 'owner')
    ON CONFLICT (project_id, profile_id) DO NOTHING;
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE TRIGGER on_project_created
  AFTER INSERT ON public.projects
  FOR EACH ROW EXECUTE PROCEDURE public.handle_new_project_owner();

-- ── 3. Backfill: Bestehende Projekte ─────────────────────────────────────────

INSERT INTO public.project_members (project_id, profile_id, role)
SELECT id, created_by, 'owner'
FROM public.projects
WHERE created_by IS NOT NULL
ON CONFLICT (project_id, profile_id) DO NOTHING;

-- ── 4. Helper-Funktionen für RLS ─────────────────────────────────────────────

CREATE OR REPLACE FUNCTION public.is_project_member(p_project_id uuid)
RETURNS boolean AS $$
BEGIN
  RETURN EXISTS (
    SELECT 1 FROM public.project_members
    WHERE project_id = p_project_id
    AND profile_id = auth.uid()
  );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

CREATE OR REPLACE FUNCTION public.is_admin()
RETURNS boolean AS $$
BEGIN
  RETURN EXISTS (
    SELECT 1 FROM public.profiles p
    JOIN public.roles r ON r.id = p.role_id
    WHERE p.id = auth.uid()
    AND r.name = 'admin'
  );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

-- ── 5. RLS: cost_items einschränken ──────────────────────────────────────────

-- Alte offene Policies entfernen
DROP POLICY IF EXISTS "Kosten lesen" ON public.cost_items;
DROP POLICY IF EXISTS "Kosten erstellen" ON public.cost_items;
DROP POLICY IF EXISTS "Kosten bearbeiten" ON public.cost_items;
DROP POLICY IF EXISTS "Kosten löschen" ON public.cost_items;

-- Neue Policies: nur Mitglieder + Admins
CREATE POLICY "cost_items_select" ON public.cost_items
  FOR SELECT TO authenticated
  USING (
    public.is_project_member(project_id) OR public.is_admin()
  );

CREATE POLICY "cost_items_insert" ON public.cost_items
  FOR INSERT TO authenticated
  WITH CHECK (
    public.is_project_member(project_id) OR public.is_admin()
  );

CREATE POLICY "cost_items_update" ON public.cost_items
  FOR UPDATE TO authenticated
  USING (
    public.is_project_member(project_id) OR public.is_admin()
  );

CREATE POLICY "cost_items_delete" ON public.cost_items
  FOR DELETE TO authenticated
  USING (
    public.is_project_member(project_id) OR public.is_admin()
  );

-- ── 6. RLS: project_members ─────────────────────────────────────────────────

ALTER TABLE public.project_members ENABLE ROW LEVEL SECURITY;

-- Alle können Mitgliedschaften sehen (für Checks + Anzeige)
CREATE POLICY "members_select" ON public.project_members
  FOR SELECT TO authenticated
  USING (true);

-- Nur Owner + Admins können Mitglieder hinzufügen
CREATE POLICY "members_insert" ON public.project_members
  FOR INSERT TO authenticated
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM public.project_members pm
      WHERE pm.project_id = project_members.project_id
      AND pm.profile_id = auth.uid()
      AND pm.role = 'owner'
    ) OR public.is_admin()
  );

-- Nur Owner + Admins können Mitglieder entfernen
CREATE POLICY "members_delete" ON public.project_members
  FOR DELETE TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.project_members pm
      WHERE pm.project_id = project_members.project_id
      AND pm.profile_id = auth.uid()
      AND pm.role = 'owner'
    ) OR public.is_admin()
  );

-- ── 7. RLS: project_invitations ──────────────────────────────────────────────

ALTER TABLE public.project_invitations ENABLE ROW LEVEL SECURITY;

-- Eingeladener, Einladender, oder Admin kann sehen
CREATE POLICY "invitations_select" ON public.project_invitations
  FOR SELECT TO authenticated
  USING (
    invited_profile_id = auth.uid()
    OR invited_by = auth.uid()
    OR public.is_admin()
  );

-- Nur Owner + Admins können einladen
CREATE POLICY "invitations_insert" ON public.project_invitations
  FOR INSERT TO authenticated
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM public.project_members
      WHERE project_id = project_invitations.project_id
      AND profile_id = auth.uid()
      AND role = 'owner'
    ) OR public.is_admin()
  );

-- Nur Eingeladener kann Status ändern (accept/decline)
CREATE POLICY "invitations_update" ON public.project_invitations
  FOR UPDATE TO authenticated
  USING (
    invited_profile_id = auth.uid()
  );

-- ── 8. Realtime aktivieren ───────────────────────────────────────────────────

ALTER PUBLICATION supabase_realtime ADD TABLE public.project_members;
ALTER PUBLICATION supabase_realtime ADD TABLE public.project_invitations;
