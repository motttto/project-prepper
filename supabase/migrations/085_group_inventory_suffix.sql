-- Migration 085: Group-spezifischer Inventarnummer-Suffix
-- =======================================================
-- Im Group-Modus sollen neue Inventar-Items den Gruppen-Suffix bekommen
-- (z.B. 'DKS' fuer Dunkelstrom). Im Solo-Modus weiterhin den User-Suffix.

ALTER TABLE public.groups
  ADD COLUMN IF NOT EXISTS inventory_suffix text;

ALTER TABLE public.groups
  ADD CONSTRAINT groups_inventory_suffix_format
  CHECK (inventory_suffix IS NULL OR inventory_suffix ~ '^[A-Z0-9]{2,6}$');

COMMENT ON COLUMN public.groups.inventory_suffix IS
  'Gruppen-Suffix fuer Inventarnummern (z.B. DKS). 2-6 Grossbuchstaben/Ziffern. Wird im Group-Modus statt des User-Suffix verwendet.';

-- Dunkelstrom → DKS
UPDATE public.groups
SET inventory_suffix = 'DKS'
WHERE name = 'Dunkelstrom'
  AND inventory_suffix IS NULL;
