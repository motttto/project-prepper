-- Migration 096: Tags / Suchbegriffe pro Inventar-Item
--
-- Hintergrund (Feedback Jan, 2026-05):
--   "Manche suchen nach 'Beamer', andere nach Modellbezeichnung,
--    wieder andere nach 'Bildprojektor', 'Gobo-Projektor' o. Ä."
--
-- Loesung: Mehrwert-Feld `tags` als text[] auf inventory_items
--   - leeres Array als Default (kein NULL-Check noetig)
--   - GIN-Index fuer schnelle ARRAY-Suche
--   - kein Constraint auf Inhalt (frei waehlbar pro Org)

ALTER TABLE public.inventory_items
  ADD COLUMN IF NOT EXISTS tags text[] NOT NULL DEFAULT ARRAY[]::text[];

CREATE INDEX IF NOT EXISTS idx_inventory_items_tags
  ON public.inventory_items USING GIN (tags);

COMMENT ON COLUMN public.inventory_items.tags IS
  'Frei waehlbare Suchbegriffe / Aliase (z.B. "Beamer", "Projektor", Modellname). Hilft Duplikate zu vermeiden und macht Items unter mehreren Begriffen findbar.';
