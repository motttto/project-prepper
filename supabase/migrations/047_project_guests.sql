-- 047: Gästeliste für Projekte
-- Gäste/Einladungen pro Event-Projekt

CREATE TABLE IF NOT EXISTS project_guests (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  project_id UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  company TEXT,
  role TEXT,
  email TEXT,
  phone TEXT,
  plus_ones INTEGER DEFAULT 0,
  notes TEXT,
  status TEXT NOT NULL DEFAULT 'invited'
    CHECK (status IN ('invited', 'confirmed', 'declined', 'attended')),
  created_at TIMESTAMPTZ DEFAULT now()
);

-- RLS
ALTER TABLE project_guests ENABLE ROW LEVEL SECURITY;

CREATE POLICY "project_guests_select" ON project_guests FOR SELECT TO authenticated USING (true);
CREATE POLICY "project_guests_insert" ON project_guests FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "project_guests_update" ON project_guests FOR UPDATE TO authenticated USING (true);
CREATE POLICY "project_guests_delete" ON project_guests FOR DELETE TO authenticated USING (true);

-- Realtime
ALTER PUBLICATION supabase_realtime ADD TABLE project_guests;
