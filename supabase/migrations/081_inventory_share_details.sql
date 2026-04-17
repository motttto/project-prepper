-- Migration 081: Details fuer Gruppen-Sharing
-- ============================================================
-- inventory_group_shares bekommt structured Felder:
-- - requires_approval: boolean (Eigentümer muss jede Buchung zusagen)
-- - conditions_tags: text[] (Preset-Bedingungen)
-- - conditions bleibt freier Text für Zusatz-Hinweise

ALTER TABLE public.inventory_group_shares
  ADD COLUMN IF NOT EXISTS requires_approval boolean NOT NULL DEFAULT false,
  ADD COLUMN IF NOT EXISTS conditions_tags text[] NOT NULL DEFAULT ARRAY[]::text[];

-- Unique-Index: Ein Item pro Gruppe nur einmal (aktiv)
CREATE UNIQUE INDEX IF NOT EXISTS inventory_group_shares_active_unique
  ON public.inventory_group_shares (inventory_item_id, group_id)
  WHERE revoked_at IS NULL;
