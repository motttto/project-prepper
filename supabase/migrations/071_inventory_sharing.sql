-- Migration 071: Equipment-Sharing zwischen User und Group (Phase 1)
-- ===================================================================
-- Konzept:
-- 1. inventory_group_shares: User gibt Item generell fuer Gruppe X frei
--    (Standard-Tagessatz, Bedingungen)
-- 2. inventory_project_grants: Owner erlaubt Item explizit fuer ein konkretes
--    Projekt der Gruppe (per-Projekt-Override moeglich)
--
-- Ein Item taucht im Projekt-Equipment-Picker nur auf, wenn:
--   a) es dem aktiven User gehoert (eigenes Inventar), ODER
--   b) ein inventory_group_shares Eintrag fuer die Projekt-Gruppe existiert
--      UND ein inventory_project_grants Eintrag fuer das Projekt existiert.

-- ── Generelle Group-Freigabe ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.inventory_group_shares (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  inventory_item_id uuid NOT NULL REFERENCES public.inventory_items(id) ON DELETE CASCADE,
  group_id uuid NOT NULL REFERENCES public.groups(id) ON DELETE CASCADE,
  daily_rate numeric(10,2) NOT NULL DEFAULT 0,
  conditions text,
  shared_by uuid NOT NULL REFERENCES public.profiles(id),
  shared_at timestamptz DEFAULT now(),
  revoked_at timestamptz,
  UNIQUE(inventory_item_id, group_id)
);

CREATE INDEX IF NOT EXISTS idx_inv_shares_item ON public.inventory_group_shares(inventory_item_id);
CREATE INDEX IF NOT EXISTS idx_inv_shares_group ON public.inventory_group_shares(group_id);

-- ── Per-Projekt-Erlaubnis ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.inventory_project_grants (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  inventory_item_id uuid NOT NULL REFERENCES public.inventory_items(id) ON DELETE CASCADE,
  project_id uuid NOT NULL REFERENCES public.projects(id) ON DELETE CASCADE,
  quantity_allowed integer NOT NULL DEFAULT 1,
  daily_rate numeric(10,2),                 -- Override (NULL = nimm Group-Rate)
  granted_by uuid NOT NULL REFERENCES public.profiles(id),
  granted_at timestamptz DEFAULT now(),
  revoked_at timestamptz,
  notes text,
  UNIQUE(inventory_item_id, project_id)
);

CREATE INDEX IF NOT EXISTS idx_inv_grants_item ON public.inventory_project_grants(inventory_item_id);
CREATE INDEX IF NOT EXISTS idx_inv_grants_project ON public.inventory_project_grants(project_id);

-- ── RLS ─────────────────────────────────────────────────────────────────────
ALTER TABLE public.inventory_group_shares ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.inventory_project_grants ENABLE ROW LEVEL SECURITY;

-- Group Shares: lesen alle Gruppen-Mitglieder. Schreiben nur Owner des Items.
CREATE POLICY "inv_shares_select" ON public.inventory_group_shares
  FOR SELECT TO authenticated USING (
    is_group_member(group_id)
    OR shared_by = auth.uid()
  );

CREATE POLICY "inv_shares_insert" ON public.inventory_group_shares
  FOR INSERT TO authenticated WITH CHECK (
    shared_by = auth.uid()
    AND EXISTS (
      SELECT 1 FROM public.inventory_items
      WHERE id = inventory_item_id AND owner_profile_id = auth.uid()
    )
  );

CREATE POLICY "inv_shares_update_owner" ON public.inventory_group_shares
  FOR UPDATE TO authenticated USING (shared_by = auth.uid());

CREATE POLICY "inv_shares_delete_owner" ON public.inventory_group_shares
  FOR DELETE TO authenticated USING (shared_by = auth.uid());

-- Project Grants: lesen alle Mitglieder der Projekt-Gruppe. Schreiben nur Item-Owner.
CREATE POLICY "inv_grants_select" ON public.inventory_project_grants
  FOR SELECT TO authenticated USING (
    granted_by = auth.uid()
    OR EXISTS (
      SELECT 1 FROM public.projects p
      WHERE p.id = project_id
        AND (p.group_id IS NULL OR is_group_member(p.group_id))
    )
  );

CREATE POLICY "inv_grants_insert" ON public.inventory_project_grants
  FOR INSERT TO authenticated WITH CHECK (
    granted_by = auth.uid()
    AND EXISTS (
      SELECT 1 FROM public.inventory_items
      WHERE id = inventory_item_id AND owner_profile_id = auth.uid()
    )
  );

CREATE POLICY "inv_grants_update_owner" ON public.inventory_project_grants
  FOR UPDATE TO authenticated USING (granted_by = auth.uid());

CREATE POLICY "inv_grants_delete_owner" ON public.inventory_project_grants
  FOR DELETE TO authenticated USING (granted_by = auth.uid());

-- ── Realtime ────────────────────────────────────────────────────────────────
ALTER PUBLICATION supabase_realtime ADD TABLE public.inventory_group_shares;
ALTER PUBLICATION supabase_realtime ADD TABLE public.inventory_project_grants;
