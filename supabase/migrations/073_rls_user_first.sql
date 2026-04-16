-- Migration 073: RLS-Policies an User-First-Modell anpassen
-- ===========================================================
-- Solo-Daten (inventory, inquiries, projects, categories) gehoeren dem
-- owner_profile_id und sind nur fuer den Owner zugreifbar (Solo) +
-- Mitglieder einer Group falls Daten der Gruppe zugeordnet sind.

-- ── projects ────────────────────────────────────────────────────────────────
DROP POLICY IF EXISTS projects_select ON public.projects;
DROP POLICY IF EXISTS projects_insert ON public.projects;
DROP POLICY IF EXISTS projects_update ON public.projects;
DROP POLICY IF EXISTS projects_delete ON public.projects;

CREATE POLICY "projects_select" ON public.projects
  FOR SELECT TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (group_id IS NOT NULL AND is_group_member(group_id))
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "projects_insert" ON public.projects
  FOR INSERT TO authenticated WITH CHECK (
    owner_profile_id = auth.uid()
  );

CREATE POLICY "projects_update" ON public.projects
  FOR UPDATE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (group_id IS NOT NULL AND is_group_member(group_id))
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "projects_delete" ON public.projects
  FOR DELETE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

-- ── inventory_items ─────────────────────────────────────────────────────────
DROP POLICY IF EXISTS inventory_items_select ON public.inventory_items;
DROP POLICY IF EXISTS inventory_items_insert ON public.inventory_items;
DROP POLICY IF EXISTS inventory_items_update ON public.inventory_items;
DROP POLICY IF EXISTS inventory_items_delete ON public.inventory_items;

CREATE POLICY "inventory_items_select" ON public.inventory_items
  FOR SELECT TO authenticated USING (
    owner_profile_id = auth.uid()
    OR EXISTS (
      SELECT 1 FROM public.inventory_group_shares igs
      WHERE igs.inventory_item_id = inventory_items.id
        AND is_group_member(igs.group_id)
    )
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "inventory_items_insert" ON public.inventory_items
  FOR INSERT TO authenticated WITH CHECK (
    owner_profile_id = auth.uid()
  );

CREATE POLICY "inventory_items_update" ON public.inventory_items
  FOR UPDATE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "inventory_items_delete" ON public.inventory_items
  FOR DELETE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

-- ── inventory_categories ────────────────────────────────────────────────────
DROP POLICY IF EXISTS inventory_categories_select ON public.inventory_categories;
DROP POLICY IF EXISTS inventory_categories_insert ON public.inventory_categories;
DROP POLICY IF EXISTS inventory_categories_update ON public.inventory_categories;
DROP POLICY IF EXISTS inventory_categories_delete ON public.inventory_categories;

CREATE POLICY "inv_cat_select" ON public.inventory_categories
  FOR SELECT TO authenticated USING (
    owner_profile_id = auth.uid()
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "inv_cat_insert" ON public.inventory_categories
  FOR INSERT TO authenticated WITH CHECK (
    owner_profile_id = auth.uid()
  );

CREATE POLICY "inv_cat_update" ON public.inventory_categories
  FOR UPDATE TO authenticated USING (
    owner_profile_id = auth.uid()
  );

CREATE POLICY "inv_cat_delete" ON public.inventory_categories
  FOR DELETE TO authenticated USING (
    owner_profile_id = auth.uid()
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
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "inquiries_insert" ON public.inquiries
  FOR INSERT TO authenticated WITH CHECK (
    owner_profile_id = auth.uid()
  );

CREATE POLICY "inquiries_update" ON public.inquiries
  FOR UPDATE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR created_by = auth.uid()
  );

CREATE POLICY "inquiries_delete" ON public.inquiries
  FOR DELETE TO authenticated USING (
    owner_profile_id = auth.uid()
  );
