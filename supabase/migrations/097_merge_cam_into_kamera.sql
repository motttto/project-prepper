-- Migration 097: Kategorien CAM + Mikroskop-Cam in Kamera zusammenfuehren
--
-- Hintergrund: Doppelte Bezeichnungen ("CAM", "Mikroskop-Cam") fuer Items,
-- die alle unter "Kamera" gehoeren. Vereinheitlichung damit die Liste
-- konsistent bleibt (Feedback Jan, 2026-05).

-- Items umbiegen
UPDATE public.inventory_items
SET category = 'Kamera'
WHERE category IN ('CAM', 'Mikroskop-Cam');

-- Kategorie-Zeile fuer "CAM" entfernen.
-- "Mikroskop-Cam" hat keine Zeile in inventory_categories (war freier Text).
DELETE FROM public.inventory_categories
WHERE name = 'CAM';
