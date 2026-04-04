-- 048: Org-level Gästeverzeichnis
-- Gäste werden auf Org-Ebene verwaltet und Projekten zugewiesen

-- Org-Gäste (Verzeichnis)
CREATE TABLE IF NOT EXISTS org_guests (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  org_id UUID NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  company TEXT,
  role TEXT,
  email TEXT,
  phone TEXT,
  notes TEXT,
  created_at TIMESTAMPTZ DEFAULT now()
);

-- RLS
ALTER TABLE org_guests ENABLE ROW LEVEL SECURITY;
CREATE POLICY "org_guests_select" ON org_guests FOR SELECT TO authenticated USING (true);
CREATE POLICY "org_guests_insert" ON org_guests FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "org_guests_update" ON org_guests FOR UPDATE TO authenticated USING (true);
CREATE POLICY "org_guests_delete" ON org_guests FOR DELETE TO authenticated USING (true);

-- Realtime
ALTER PUBLICATION supabase_realtime ADD TABLE org_guests;

-- project_guests: Referenz auf org_guests + org-spezifische Felder entfernen
-- Neue Spalte org_guest_id für Verknüpfung
ALTER TABLE project_guests ADD COLUMN IF NOT EXISTS org_guest_id UUID REFERENCES org_guests(id) ON DELETE CASCADE;

-- Name optional machen (kommt jetzt aus org_guests)
ALTER TABLE project_guests ALTER COLUMN name DROP NOT NULL;
