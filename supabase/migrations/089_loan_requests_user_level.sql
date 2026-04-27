-- Migration 089: Verleih-Anfragen auf User-Ebene
-- ================================================
-- Bisher: equipment_requests nur zwischen Orgs (requesting_org_id, supplying_org_id NOT NULL).
-- Neu: Anfragen koennen auch zwischen Solo-Usern oder User<->Group laufen.
-- Schema: NOT NULL auf den Org-Spalten droppen, profile-Spalten hinzufuegen,
--         CHECK-Constraint dass jeweils EINE Seite gesetzt ist (org ODER profile).

-- Org-Spalten nullable machen (Legacy-Daten bleiben erhalten)
ALTER TABLE public.equipment_requests
  ALTER COLUMN requesting_org_id DROP NOT NULL,
  ALTER COLUMN supplying_org_id DROP NOT NULL;

-- Neue Profile-Spalten als Alternative zu Org
ALTER TABLE public.equipment_requests
  ADD COLUMN IF NOT EXISTS requesting_profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE,
  ADD COLUMN IF NOT EXISTS supplying_profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE,
  ADD COLUMN IF NOT EXISTS requesting_group_id uuid REFERENCES public.groups(id) ON DELETE CASCADE,
  ADD COLUMN IF NOT EXISTS supplying_group_id uuid REFERENCES public.groups(id) ON DELETE CASCADE,
  ADD COLUMN IF NOT EXISTS message text;

-- CHECK: jede Seite hat genau eine Quelle (Org legacy ODER Group ODER Profile)
ALTER TABLE public.equipment_requests
  ADD CONSTRAINT equipment_requests_requesting_xor CHECK (
    (CASE WHEN requesting_org_id IS NOT NULL THEN 1 ELSE 0 END +
     CASE WHEN requesting_group_id IS NOT NULL THEN 1 ELSE 0 END +
     CASE WHEN requesting_profile_id IS NOT NULL THEN 1 ELSE 0 END) = 1
  );

ALTER TABLE public.equipment_requests
  ADD CONSTRAINT equipment_requests_supplying_xor CHECK (
    (CASE WHEN supplying_org_id IS NOT NULL THEN 1 ELSE 0 END +
     CASE WHEN supplying_group_id IS NOT NULL THEN 1 ELSE 0 END +
     CASE WHEN supplying_profile_id IS NOT NULL THEN 1 ELSE 0 END) = 1
  );

-- ── RLS-Policies ──
-- Anfragender + Item-Owner (Solo-Profile/Group) sehen die Anfrage
DROP POLICY IF EXISTS equipment_requests_select ON public.equipment_requests;
DROP POLICY IF EXISTS "equipment_requests_select" ON public.equipment_requests;

CREATE POLICY "equipment_requests_select" ON public.equipment_requests
  FOR SELECT TO authenticated USING (
    requested_by = auth.uid()
    OR requesting_profile_id = auth.uid()
    OR supplying_profile_id = auth.uid()
    OR (requesting_group_id IS NOT NULL AND is_group_member(requesting_group_id))
    OR (supplying_group_id IS NOT NULL AND is_group_member(supplying_group_id))
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

DROP POLICY IF EXISTS equipment_requests_insert ON public.equipment_requests;
CREATE POLICY "equipment_requests_insert" ON public.equipment_requests
  FOR INSERT TO authenticated WITH CHECK (
    requested_by = auth.uid()
    AND (
      requesting_profile_id = auth.uid()
      OR (requesting_group_id IS NOT NULL AND is_group_member(requesting_group_id))
    )
  );

-- Update: Item-Owner kann annehmen/ablehnen
DROP POLICY IF EXISTS equipment_requests_update ON public.equipment_requests;
CREATE POLICY "equipment_requests_update" ON public.equipment_requests
  FOR UPDATE TO authenticated USING (
    supplying_profile_id = auth.uid()
    OR (supplying_group_id IS NOT NULL AND is_group_member(supplying_group_id))
    OR requested_by = auth.uid()
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );
