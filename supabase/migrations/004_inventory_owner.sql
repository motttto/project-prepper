-- =============================================
-- INVENTAR: Eigentümer + Kaufdatum
-- Führe dieses SQL im Supabase SQL Editor aus
-- =============================================

-- Neue Spalten hinzufügen
ALTER TABLE public.inventory_items
  ADD COLUMN owner text,
  ADD COLUMN purchased_at date;

-- Kommentare für Klarheit
COMMENT ON COLUMN public.inventory_items.owner IS 'Eigentümer des Artikels (Person oder Kollektiv)';
COMMENT ON COLUMN public.inventory_items.purchased_at IS 'Kaufdatum des Artikels';

-- Bekannte Eigentümer aus der Excel setzen
-- (Basierend auf den Projekt-Beschreibungen und dem Inventarliste-Kontext)
UPDATE public.inventory_items SET owner = 'Kollektiv' WHERE owner IS NULL;
