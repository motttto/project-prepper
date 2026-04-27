-- Migration 090: Gesamtinventar-Freigabe (Owner-Level Sharing)
-- =============================================================
-- Bisher: inventory_group_shares teilt einzelne Items mit Gruppen.
-- Neu: Owner (User oder Group) kann sein KOMPLETTES Inventar einer
-- anderen Person/Gruppe freigeben — automatisch fuer alle aktuellen
-- und zukuenftigen Items.

CREATE TABLE IF NOT EXISTS public.inventory_full_shares (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  -- Quelle (XOR): genau eine Owner-Seite gesetzt
  source_owner_profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE,
  source_owner_group_id uuid REFERENCES public.groups(id) ON DELETE CASCADE,
  -- Ziel (XOR): genau eine Empfaenger-Seite gesetzt
  target_profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE,
  target_group_id uuid REFERENCES public.groups(id) ON DELETE CASCADE,
  -- Default-Settings fuer alle Items
  daily_rate_default numeric DEFAULT 0,
  requires_approval_default boolean DEFAULT true,
  conditions text,
  -- Meta
  shared_by uuid NOT NULL REFERENCES public.profiles(id),
  shared_at timestamptz DEFAULT now(),
  revoked_at timestamptz,

  CONSTRAINT inventory_full_shares_source_xor CHECK (
    (CASE WHEN source_owner_profile_id IS NOT NULL THEN 1 ELSE 0 END +
     CASE WHEN source_owner_group_id IS NOT NULL THEN 1 ELSE 0 END) = 1
  ),
  CONSTRAINT inventory_full_shares_target_xor CHECK (
    (CASE WHEN target_profile_id IS NOT NULL THEN 1 ELSE 0 END +
     CASE WHEN target_group_id IS NOT NULL THEN 1 ELSE 0 END) = 1
  ),
  CONSTRAINT inventory_full_shares_no_self CHECK (
    -- User darf nicht sich selbst freigeben
    (source_owner_profile_id IS NULL OR source_owner_profile_id != target_profile_id)
    AND (source_owner_group_id IS NULL OR source_owner_group_id != target_group_id)
  )
);

CREATE UNIQUE INDEX inventory_full_shares_unique_active
  ON public.inventory_full_shares (
    COALESCE(source_owner_profile_id, '00000000-0000-0000-0000-000000000000'::uuid),
    COALESCE(source_owner_group_id, '00000000-0000-0000-0000-000000000000'::uuid),
    COALESCE(target_profile_id, '00000000-0000-0000-0000-000000000000'::uuid),
    COALESCE(target_group_id, '00000000-0000-0000-0000-000000000000'::uuid)
  ) WHERE revoked_at IS NULL;

ALTER TABLE public.inventory_full_shares ENABLE ROW LEVEL SECURITY;

-- Source-Owner und Target sehen ihre Shares
CREATE POLICY "inventory_full_shares_select" ON public.inventory_full_shares
  FOR SELECT TO authenticated USING (
    source_owner_profile_id = auth.uid()
    OR target_profile_id = auth.uid()
    OR (source_owner_group_id IS NOT NULL AND is_group_member(source_owner_group_id))
    OR (target_group_id IS NOT NULL AND is_group_member(target_group_id))
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

-- Source-Owner kann freigeben
CREATE POLICY "inventory_full_shares_insert" ON public.inventory_full_shares
  FOR INSERT TO authenticated WITH CHECK (
    shared_by = auth.uid()
    AND (
      source_owner_profile_id = auth.uid()
      OR (source_owner_group_id IS NOT NULL AND is_group_member(source_owner_group_id))
    )
  );

-- Source-Owner kann widerrufen
CREATE POLICY "inventory_full_shares_update" ON public.inventory_full_shares
  FOR UPDATE TO authenticated USING (
    source_owner_profile_id = auth.uid()
    OR (source_owner_group_id IS NOT NULL AND is_group_member(source_owner_group_id))
  );

-- ── inventory_items RLS erweitern: Full-Shares respektieren ──

DROP POLICY IF EXISTS inventory_items_select ON public.inventory_items;

CREATE POLICY "inventory_items_select" ON public.inventory_items
  FOR SELECT TO authenticated USING (
    -- Eigene Items (Solo)
    owner_profile_id = auth.uid()
    -- Group-Items wo ich Member bin
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id))
    -- Per-Item Share an meine Group
    OR EXISTS (
      SELECT 1 FROM public.inventory_group_shares igs
      WHERE igs.inventory_item_id = inventory_items.id
        AND igs.revoked_at IS NULL
        AND is_group_member(igs.group_id)
    )
    -- Full-Share (Owner-Level): alle Items des Source-Owners gehen an Target
    OR EXISTS (
      SELECT 1 FROM public.inventory_full_shares ifs
      WHERE ifs.revoked_at IS NULL
        AND (
          (ifs.source_owner_profile_id IS NOT NULL AND ifs.source_owner_profile_id = inventory_items.owner_profile_id)
          OR (ifs.source_owner_group_id IS NOT NULL AND ifs.source_owner_group_id = inventory_items.owner_group_id)
        )
        AND (
          ifs.target_profile_id = auth.uid()
          OR (ifs.target_group_id IS NOT NULL AND is_group_member(ifs.target_group_id))
        )
    )
    -- Superadmin
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );
