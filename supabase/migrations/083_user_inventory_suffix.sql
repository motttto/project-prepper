-- Migration 083: User-spezifischer Inventarnummer-Suffix
-- =====================================================
-- 1. Verwaiste Inventar-Items (owner_profile_id IS NULL) dem Superadmin
--    zuordnen, damit sie im Solo-Modus sichtbar sind.
-- 2. Neue Spalte profiles.inventory_suffix (z.B. 'MOT' fuer motto).
-- 3. Bestehende Nummern um '-{suffix}' ergaenzen.
-- 4. UNIQUE-Constraint von global auf (owner_profile_id, inventory_number)
--    umstellen, damit andere User eigene Nummern haben koennen.

-- ── 1. Suffix-Spalte auf profiles ────────────────────────────────────
ALTER TABLE public.profiles
  ADD COLUMN IF NOT EXISTS inventory_suffix text;

ALTER TABLE public.profiles
  ADD CONSTRAINT profiles_inventory_suffix_format
  CHECK (inventory_suffix IS NULL OR inventory_suffix ~ '^[A-Z0-9]{2,6}$');

COMMENT ON COLUMN public.profiles.inventory_suffix IS
  'User-spezifischer Suffix fuer Inventarnummern (z.B. MOT). 2-6 Grossbuchstaben/Ziffern.';

-- ── 2. Suffix fuer Superadmin (motto) setzen ─────────────────────────
UPDATE public.profiles
SET inventory_suffix = 'MOT'
WHERE id = '4a94b14d-0460-4a99-a4b2-bd3bec643275'
  AND inventory_suffix IS NULL;

-- ── 3. Verwaiste Items dem Superadmin zuordnen ───────────────────────
UPDATE public.inventory_items
SET owner_profile_id = '4a94b14d-0460-4a99-a4b2-bd3bec643275'
WHERE owner_profile_id IS NULL;

-- ── 4. Bestehende Nummern um Suffix ergaenzen ────────────────────────
-- Nur Items, die noch keinen Suffix haben (Format CAT-NNN ohne weiteres '-')
-- Wir matchen genau ^[A-Z]+-\d+$ und haengen dann den Owner-Suffix an.
UPDATE public.inventory_items i
SET inventory_number = i.inventory_number || '-' || p.inventory_suffix
FROM public.profiles p
WHERE i.owner_profile_id = p.id
  AND p.inventory_suffix IS NOT NULL
  AND i.inventory_number ~ '^[A-Z]+-[0-9]+$';

-- ── 5. UNIQUE-Constraint umstellen ───────────────────────────────────
ALTER TABLE public.inventory_items
  DROP CONSTRAINT IF EXISTS inventory_items_inventory_number_key;

ALTER TABLE public.inventory_items
  ADD CONSTRAINT inventory_items_owner_number_unique
  UNIQUE (owner_profile_id, inventory_number);
