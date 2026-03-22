-- Migration 034: Projekt-Dateien (Grundrisse, Fotos, PDFs)

-- 1. Storage Bucket für Projekt-Dateien
INSERT INTO storage.buckets (id, name, public)
VALUES ('project-files', 'project-files', true)
ON CONFLICT (id) DO NOTHING;

-- Storage RLS Policies
CREATE POLICY "Authenticated users can upload project files"
ON storage.objects FOR INSERT TO authenticated
WITH CHECK (bucket_id = 'project-files');

CREATE POLICY "Authenticated users can update project files"
ON storage.objects FOR UPDATE TO authenticated
USING (bucket_id = 'project-files');

CREATE POLICY "Authenticated users can delete project files"
ON storage.objects FOR DELETE TO authenticated
USING (bucket_id = 'project-files');

CREATE POLICY "Public read access for project files"
ON storage.objects FOR SELECT TO public
USING (bucket_id = 'project-files');

-- 2. Tabelle für Datei-Metadaten
CREATE TABLE IF NOT EXISTS project_files (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  project_id uuid NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  org_id uuid NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
  file_name text NOT NULL,
  file_path text NOT NULL,
  file_url text NOT NULL,
  file_type text NOT NULL,
  file_size integer,
  uploaded_by uuid REFERENCES auth.users(id) ON DELETE SET NULL,
  created_at timestamptz DEFAULT now()
);

-- RLS
ALTER TABLE project_files ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Authenticated users can select project files"
ON project_files FOR SELECT TO authenticated USING (true);

CREATE POLICY "Authenticated users can insert project files"
ON project_files FOR INSERT TO authenticated WITH CHECK (true);

CREATE POLICY "Authenticated users can update project files"
ON project_files FOR UPDATE TO authenticated USING (true);

CREATE POLICY "Authenticated users can delete project files"
ON project_files FOR DELETE TO authenticated USING (true);

-- Realtime
ALTER PUBLICATION supabase_realtime ADD TABLE project_files;
