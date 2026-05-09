-- Migration 095: Projekt-Team Vereinfachung + Voting fuer Gaeste/Kontakte
--
-- Phase 1: Mitglieder gleichberechtigt
--   - Bestehende 'viewer' werden zu 'editor' hochgestuft
--   - RLS so umgestellt, dass jedes aktive Gruppenmitglied
--     project_members hinzufuegen/entfernen darf (kein Owner-Privileg mehr)
--
-- Phase 2: Vier-Augen-Prinzip fuer Gaeste + externe Kontakte
--   - Neue Spalten approval_status, proposed_by, approved_by, approved_at
--   - Default-Status fuer neue Eintraege: 'proposed'
--   - Bestandseintraege werden auf 'confirmed' gesetzt
--   - Eine Zustimmung eines anderen Gruppenmitglieds bestaetigt den Eintrag

-- ─────────────────────────────────────────────────────────────────────────
-- Phase 1: project_members
-- ─────────────────────────────────────────────────────────────────────────

-- Viewer auf Editor hochstufen
UPDATE public.project_members
SET role = 'editor'
WHERE role = 'viewer';

-- INSERT: jedes aktive Gruppenmitglied (oder Solo-Owner) darf hinzufuegen
DROP POLICY IF EXISTS "members_insert" ON public.project_members;
CREATE POLICY "members_insert" ON public.project_members
  FOR INSERT TO authenticated
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = project_members.project_id
      AND (
        -- Group-Modell (Migration 086)
        (p.owner_group_id IS NOT NULL AND public.is_group_member(p.owner_group_id))
        -- Solo-Modell
        OR (p.owner_profile_id IS NOT NULL AND p.owner_profile_id = auth.uid())
        -- Legacy: bestehendes Mitglied im Projekt
        OR EXISTS (
          SELECT 1 FROM public.project_members pm
          WHERE pm.project_id = p.id AND pm.profile_id = auth.uid()
        )
        -- Superadmin
        OR EXISTS (
          SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true
        )
      )
    )
  );

-- DELETE: jedes aktive Gruppenmitglied (oder Solo-Owner) darf entfernen
DROP POLICY IF EXISTS "members_delete" ON public.project_members;
CREATE POLICY "members_delete" ON public.project_members
  FOR DELETE TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = project_members.project_id
      AND (
        (p.owner_group_id IS NOT NULL AND public.is_group_member(p.owner_group_id))
        OR (p.owner_profile_id IS NOT NULL AND p.owner_profile_id = auth.uid())
        OR EXISTS (
          SELECT 1 FROM public.project_members pm
          WHERE pm.project_id = p.id AND pm.profile_id = auth.uid()
        )
        OR EXISTS (
          SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true
        )
      )
    )
  );

-- UPDATE: gleiche Regel fuer Rolle aendern
DROP POLICY IF EXISTS "members_update" ON public.project_members;
CREATE POLICY "members_update" ON public.project_members
  FOR UPDATE TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = project_members.project_id
      AND (
        (p.owner_group_id IS NOT NULL AND public.is_group_member(p.owner_group_id))
        OR (p.owner_profile_id IS NOT NULL AND p.owner_profile_id = auth.uid())
        OR EXISTS (
          SELECT 1 FROM public.project_members pm
          WHERE pm.project_id = p.id AND pm.profile_id = auth.uid()
        )
        OR EXISTS (
          SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true
        )
      )
    )
  );

-- ─────────────────────────────────────────────────────────────────────────
-- Phase 2: Voting-Spalten fuer project_guests + project_contacts
-- ─────────────────────────────────────────────────────────────────────────

ALTER TABLE public.project_guests
  ADD COLUMN IF NOT EXISTS approval_status text
    NOT NULL DEFAULT 'proposed'
    CHECK (approval_status IN ('proposed', 'confirmed', 'rejected')),
  ADD COLUMN IF NOT EXISTS proposed_by uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS approved_by uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS approved_at timestamptz;

ALTER TABLE public.project_contacts
  ADD COLUMN IF NOT EXISTS approval_status text
    NOT NULL DEFAULT 'proposed'
    CHECK (approval_status IN ('proposed', 'confirmed', 'rejected')),
  ADD COLUMN IF NOT EXISTS proposed_by uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS approved_by uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS approved_at timestamptz;

-- Bestandsschutz: alle existierenden Eintraege gelten als bestaetigt
UPDATE public.project_guests
SET approval_status = 'confirmed', approved_at = COALESCE(approved_at, created_at)
WHERE approval_status = 'proposed';

UPDATE public.project_contacts
SET approval_status = 'confirmed', approved_at = COALESCE(approved_at, created_at)
WHERE approval_status = 'proposed';
