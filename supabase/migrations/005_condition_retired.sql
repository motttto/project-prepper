-- =============================================
-- INVENTAR: Zustand "retired" (ausgesondert)
-- Führe dieses SQL im Supabase SQL Editor aus
-- =============================================

-- Alten CHECK Constraint entfernen und neuen mit "retired" setzen
ALTER TABLE public.inventory_items
  DROP CONSTRAINT IF EXISTS inventory_items_condition_check;

ALTER TABLE public.inventory_items
  ADD CONSTRAINT inventory_items_condition_check
  CHECK (condition IN ('new','good','fair','poor','broken','retired'));
