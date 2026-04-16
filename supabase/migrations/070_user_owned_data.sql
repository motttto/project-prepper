-- Migration 070: User-Owned Solo-Daten (Phase 1)
-- ================================================
-- Inventar, Anfragen, Projekte, Kategorien werden zu User-eigenen Daten.
-- org_id Spalten bleiben vorerst (NULL erlaubt) — werden in Phase 3+ entfernt.
-- Frontend kann waehrend Phase 2 schrittweise auf owner_profile_id umstellen.
--
-- Da Migration 068 alle Daten geloescht hat, gibt's nichts zu migrieren —
-- nur neue Spalte hinzufuegen.

-- ── inventory_items ─────────────────────────────────────────────────────────
ALTER TABLE public.inventory_items
  ADD COLUMN IF NOT EXISTS owner_profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS idx_inventory_items_owner ON public.inventory_items(owner_profile_id);

-- org_id wird optional
ALTER TABLE public.inventory_items
  ALTER COLUMN org_id DROP NOT NULL;

-- ── inventory_categories ────────────────────────────────────────────────────
ALTER TABLE public.inventory_categories
  ADD COLUMN IF NOT EXISTS owner_profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS idx_inventory_categories_owner ON public.inventory_categories(owner_profile_id);

ALTER TABLE public.inventory_categories
  ALTER COLUMN org_id DROP NOT NULL;

-- ── inquiries ───────────────────────────────────────────────────────────────
ALTER TABLE public.inquiries
  ADD COLUMN IF NOT EXISTS owner_profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS idx_inquiries_owner ON public.inquiries(owner_profile_id);

ALTER TABLE public.inquiries
  ALTER COLUMN org_id DROP NOT NULL;

-- ── projects ────────────────────────────────────────────────────────────────
-- Projekte gehoeren einem User UND koennen optional einer Gruppe zugeordnet sein
ALTER TABLE public.projects
  ADD COLUMN IF NOT EXISTS owner_profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE,
  ADD COLUMN IF NOT EXISTS group_id uuid REFERENCES public.groups(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_projects_owner ON public.projects(owner_profile_id);
CREATE INDEX IF NOT EXISTS idx_projects_group ON public.projects(group_id);

ALTER TABLE public.projects
  ALTER COLUMN org_id DROP NOT NULL;

-- ── Hinweis fuer Phase 2 ────────────────────────────────────────────────────
-- Frontend muss umgestellt werden:
--   .eq("org_id", orgId)          ->  .eq("owner_profile_id", userId)
--   .insert({ org_id: orgId })    ->  .insert({ owner_profile_id: userId })
--
-- Group-Projekte zusaetzlich:
--   .eq("group_id", groupId) wenn aus Gruppen-Kontext
--
-- RLS-Update folgt in Migration 072.
