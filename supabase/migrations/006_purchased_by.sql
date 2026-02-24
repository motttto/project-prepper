-- =============================================
-- INVENTAR: "Angeschafft durch" Feld
-- Führe dieses SQL im Supabase SQL Editor aus
-- =============================================

ALTER TABLE public.inventory_items
  ADD COLUMN purchased_by text;

COMMENT ON COLUMN public.inventory_items.purchased_by IS 'Person die den Artikel angeschafft hat';
