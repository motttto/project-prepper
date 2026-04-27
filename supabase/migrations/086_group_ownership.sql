-- Migration 086: Group-Ownership fuer Inventar, Projekte, Anfragen, Kategorien
-- ============================================================================
-- Bisher: jedes Item gehoert genau einem User (owner_profile_id NOT NULL).
-- Gruppen waren nur Sharing-Overlay.
--
-- Neu: Owner kann auch eine Gruppe sein (Kollektivanschaffung).
--   - Spalte owner_group_id (nullable)
--   - CHECK: genau eines von owner_profile_id / owner_group_id ist gesetzt
--   - RLS: Group-Member sehen/bearbeiten Items ihrer Gruppe
--
-- Daten-Migration: Alle Dunkelstrom-Items wechseln von motto-owned zu group-owned.

-- ════════════════════════════════════════════════════════════════════════════
-- 1. Spalten hinzufuegen
-- ════════════════════════════════════════════════════════════════════════════

ALTER TABLE public.inventory_items
  ADD COLUMN IF NOT EXISTS owner_group_id uuid REFERENCES public.groups(id) ON DELETE CASCADE;

ALTER TABLE public.inventory_categories
  ADD COLUMN IF NOT EXISTS owner_group_id uuid REFERENCES public.groups(id) ON DELETE CASCADE;

ALTER TABLE public.projects
  ADD COLUMN IF NOT EXISTS owner_group_id uuid REFERENCES public.groups(id) ON DELETE CASCADE;

ALTER TABLE public.inquiries
  ADD COLUMN IF NOT EXISTS owner_group_id uuid REFERENCES public.groups(id) ON DELETE CASCADE;

-- ════════════════════════════════════════════════════════════════════════════
-- 2. Daten migrieren — Dunkelstrom-Items zu group-owned
-- ════════════════════════════════════════════════════════════════════════════

-- 2a. Inventar-Items (alle 66 sind DKS-shared) → group-owned
UPDATE public.inventory_items i
SET owner_group_id = '3cde30d0-e9b2-446f-97fd-46bbcccbacc8'::uuid,
    owner_profile_id = NULL
WHERE EXISTS (
  SELECT 1 FROM public.inventory_group_shares igs
  WHERE igs.inventory_item_id = i.id
    AND igs.group_id = '3cde30d0-e9b2-446f-97fd-46bbcccbacc8'::uuid
    AND igs.revoked_at IS NULL
);

-- 2b. Redundante Shares zur eigenen Gruppe entfernen
--     (Group-Owner sieht Items ueber Owner, nicht ueber Share)
DELETE FROM public.inventory_group_shares
WHERE group_id = '3cde30d0-e9b2-446f-97fd-46bbcccbacc8'::uuid
  AND inventory_item_id IN (
    SELECT id FROM public.inventory_items
    WHERE owner_group_id = '3cde30d0-e9b2-446f-97fd-46bbcccbacc8'::uuid
  );

-- 2c. Kategorien: motto's 12 Dunkelstrom-Kategorien → group-owned
UPDATE public.inventory_categories
SET owner_group_id = '3cde30d0-e9b2-446f-97fd-46bbcccbacc8'::uuid,
    owner_profile_id = NULL
WHERE owner_profile_id = '4a94b14d-0460-4a99-a4b2-bd3bec643275'::uuid;

-- 2d. Projekte mit group_id=DKS → group-owned
UPDATE public.projects
SET owner_group_id = '3cde30d0-e9b2-446f-97fd-46bbcccbacc8'::uuid,
    owner_profile_id = NULL
WHERE group_id = '3cde30d0-e9b2-446f-97fd-46bbcccbacc8'::uuid;

-- 2e. Inquiries mit group_id=DKS → group-owned
UPDATE public.inquiries
SET owner_group_id = '3cde30d0-e9b2-446f-97fd-46bbcccbacc8'::uuid,
    owner_profile_id = NULL
WHERE group_id = '3cde30d0-e9b2-446f-97fd-46bbcccbacc8'::uuid;

-- ════════════════════════════════════════════════════════════════════════════
-- 3. CHECK: genau einer der beiden Owner gesetzt
-- ════════════════════════════════════════════════════════════════════════════

ALTER TABLE public.inventory_items
  ADD CONSTRAINT inventory_items_owner_xor
  CHECK (
    (owner_profile_id IS NOT NULL AND owner_group_id IS NULL) OR
    (owner_profile_id IS NULL AND owner_group_id IS NOT NULL)
  );

ALTER TABLE public.inventory_categories
  ADD CONSTRAINT inventory_categories_owner_xor
  CHECK (
    (owner_profile_id IS NOT NULL AND owner_group_id IS NULL) OR
    (owner_profile_id IS NULL AND owner_group_id IS NOT NULL)
  );

ALTER TABLE public.projects
  ADD CONSTRAINT projects_owner_xor
  CHECK (
    (owner_profile_id IS NOT NULL AND owner_group_id IS NULL) OR
    (owner_profile_id IS NULL AND owner_group_id IS NOT NULL)
  );

ALTER TABLE public.inquiries
  ADD CONSTRAINT inquiries_owner_xor
  CHECK (
    (owner_profile_id IS NOT NULL AND owner_group_id IS NULL) OR
    (owner_profile_id IS NULL AND owner_group_id IS NOT NULL)
  );

-- ════════════════════════════════════════════════════════════════════════════
-- 4. UNIQUE-Constraints anpassen (Owner kann jetzt User ODER Group sein)
-- ════════════════════════════════════════════════════════════════════════════

-- Vorher (Migration 083): UNIQUE (owner_profile_id, inventory_number)
-- Jetzt: pro Owner (egal ob User oder Group) eindeutig
ALTER TABLE public.inventory_items
  DROP CONSTRAINT IF EXISTS inventory_items_owner_number_unique;

CREATE UNIQUE INDEX inventory_items_user_number_unique
  ON public.inventory_items (owner_profile_id, inventory_number)
  WHERE owner_profile_id IS NOT NULL;

CREATE UNIQUE INDEX inventory_items_group_number_unique
  ON public.inventory_items (owner_group_id, inventory_number)
  WHERE owner_group_id IS NOT NULL;

-- ════════════════════════════════════════════════════════════════════════════
-- 5. RLS-Policies neu schreiben (group-aware)
-- ════════════════════════════════════════════════════════════════════════════

-- ── inventory_items ─────────────────────────────────────────────────────────
DROP POLICY IF EXISTS inventory_items_select ON public.inventory_items;
DROP POLICY IF EXISTS inventory_items_insert ON public.inventory_items;
DROP POLICY IF EXISTS inventory_items_update ON public.inventory_items;
DROP POLICY IF EXISTS inventory_items_delete ON public.inventory_items;
-- Legacy org-basiert (pre-070)
DROP POLICY IF EXISTS inventory_select ON public.inventory_items;
DROP POLICY IF EXISTS inventory_insert ON public.inventory_items;
DROP POLICY IF EXISTS inventory_update ON public.inventory_items;
DROP POLICY IF EXISTS inventory_delete ON public.inventory_items;

CREATE POLICY "inventory_items_select" ON public.inventory_items
  FOR SELECT TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id))
    OR EXISTS (
      SELECT 1 FROM public.inventory_group_shares igs
      WHERE igs.inventory_item_id = inventory_items.id
        AND igs.revoked_at IS NULL
        AND is_group_member(igs.group_id)
    )
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "inventory_items_insert" ON public.inventory_items
  FOR INSERT TO authenticated WITH CHECK (
    (owner_profile_id = auth.uid() AND owner_group_id IS NULL)
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id) AND owner_profile_id IS NULL)
  );

CREATE POLICY "inventory_items_update" ON public.inventory_items
  FOR UPDATE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id))
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "inventory_items_delete" ON public.inventory_items
  FOR DELETE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id))
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

-- ── inventory_categories ────────────────────────────────────────────────────
DROP POLICY IF EXISTS inv_cat_select ON public.inventory_categories;
DROP POLICY IF EXISTS inv_cat_insert ON public.inventory_categories;
DROP POLICY IF EXISTS inv_cat_update ON public.inventory_categories;
DROP POLICY IF EXISTS inv_cat_delete ON public.inventory_categories;
-- Legacy: erlauben true (Sicherheits-Bug, jetzt entfernt)
DROP POLICY IF EXISTS "Authenticated users can select inventory_categories" ON public.inventory_categories;
DROP POLICY IF EXISTS "Authenticated users can insert inventory_categories" ON public.inventory_categories;
DROP POLICY IF EXISTS "Authenticated users can update inventory_categories" ON public.inventory_categories;
DROP POLICY IF EXISTS "Authenticated users can delete inventory_categories" ON public.inventory_categories;

CREATE POLICY "inv_cat_select" ON public.inventory_categories
  FOR SELECT TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id))
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "inv_cat_insert" ON public.inventory_categories
  FOR INSERT TO authenticated WITH CHECK (
    (owner_profile_id = auth.uid() AND owner_group_id IS NULL)
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id) AND owner_profile_id IS NULL)
  );

CREATE POLICY "inv_cat_update" ON public.inventory_categories
  FOR UPDATE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id))
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "inv_cat_delete" ON public.inventory_categories
  FOR DELETE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id))
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

-- ── projects ────────────────────────────────────────────────────────────────
DROP POLICY IF EXISTS projects_select ON public.projects;
DROP POLICY IF EXISTS projects_insert ON public.projects;
DROP POLICY IF EXISTS projects_update ON public.projects;
DROP POLICY IF EXISTS projects_delete ON public.projects;

CREATE POLICY "projects_select" ON public.projects
  FOR SELECT TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id))
    OR (group_id IS NOT NULL AND is_group_member(group_id))  -- Legacy-Feld bis Cleanup
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "projects_insert" ON public.projects
  FOR INSERT TO authenticated WITH CHECK (
    (owner_profile_id = auth.uid() AND owner_group_id IS NULL)
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id) AND owner_profile_id IS NULL)
  );

CREATE POLICY "projects_update" ON public.projects
  FOR UPDATE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id))
    OR (group_id IS NOT NULL AND is_group_member(group_id))
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "projects_delete" ON public.projects
  FOR DELETE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id))
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

-- ── inquiries ───────────────────────────────────────────────────────────────
DROP POLICY IF EXISTS inquiries_select ON public.inquiries;
DROP POLICY IF EXISTS inquiries_insert ON public.inquiries;
DROP POLICY IF EXISTS inquiries_update ON public.inquiries;
DROP POLICY IF EXISTS inquiries_delete ON public.inquiries;

CREATE POLICY "inquiries_select" ON public.inquiries
  FOR SELECT TO authenticated USING (
    owner_profile_id = auth.uid()
    OR created_by = auth.uid()
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id))
    OR (group_id IS NOT NULL AND is_group_member(group_id))  -- Legacy
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "inquiries_insert" ON public.inquiries
  FOR INSERT TO authenticated WITH CHECK (
    (owner_profile_id = auth.uid() AND owner_group_id IS NULL)
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id) AND owner_profile_id IS NULL)
  );

CREATE POLICY "inquiries_update" ON public.inquiries
  FOR UPDATE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR created_by = auth.uid()
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id))
    OR (group_id IS NOT NULL AND is_group_member(group_id))
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "inquiries_delete" ON public.inquiries
  FOR DELETE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND is_group_member(owner_group_id))
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );
