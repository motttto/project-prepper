-- 028: Hersteller-URL, Manual-URL + Einzelstücke-Tracking
-- Neue Felder auf inventory_items
ALTER TABLE inventory_items
  ADD COLUMN IF NOT EXISTS manufacturer_url text,
  ADD COLUMN IF NOT EXISTS manual_url text;

-- Einzelstücke-Tabelle für Mehrfach-Artikel
CREATE TABLE IF NOT EXISTS inventory_units (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  item_id uuid NOT NULL REFERENCES inventory_items(id) ON DELETE CASCADE,
  org_id uuid NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
  unit_number integer NOT NULL,
  condition text NOT NULL DEFAULT 'good'
    CHECK (condition IN ('new', 'good', 'fair', 'poor', 'broken', 'retired')),
  notes text,
  created_at timestamptz DEFAULT now(),
  UNIQUE (item_id, unit_number)
);

-- RLS
ALTER TABLE inventory_units ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Authenticated users can select inventory_units"
  ON inventory_units FOR SELECT TO authenticated USING (true);

CREATE POLICY "Authenticated users can insert inventory_units"
  ON inventory_units FOR INSERT TO authenticated WITH CHECK (true);

CREATE POLICY "Authenticated users can update inventory_units"
  ON inventory_units FOR UPDATE TO authenticated USING (true) WITH CHECK (true);

CREATE POLICY "Authenticated users can delete inventory_units"
  ON inventory_units FOR DELETE TO authenticated USING (true);

-- Realtime
ALTER PUBLICATION supabase_realtime ADD TABLE inventory_units;
