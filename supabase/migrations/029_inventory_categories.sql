-- 029: Dynamische Inventar-Kategorien
CREATE TABLE IF NOT EXISTS inventory_categories (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  org_id uuid NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
  name text NOT NULL,
  icon text NOT NULL DEFAULT '📁',
  prefix text NOT NULL DEFAULT 'INV',
  sort_order integer NOT NULL DEFAULT 0,
  created_at timestamptz DEFAULT now(),
  UNIQUE (org_id, name)
);

-- RLS
ALTER TABLE inventory_categories ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Authenticated users can select inventory_categories"
  ON inventory_categories FOR SELECT TO authenticated USING (true);

CREATE POLICY "Authenticated users can insert inventory_categories"
  ON inventory_categories FOR INSERT TO authenticated WITH CHECK (true);

CREATE POLICY "Authenticated users can update inventory_categories"
  ON inventory_categories FOR UPDATE TO authenticated USING (true) WITH CHECK (true);

CREATE POLICY "Authenticated users can delete inventory_categories"
  ON inventory_categories FOR DELETE TO authenticated USING (true);

-- Realtime
ALTER PUBLICATION supabase_realtime ADD TABLE inventory_categories;
