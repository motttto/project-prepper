-- Migration 016: Profilbilder (Avatare)
-- ======================================
-- Neues avatar_url Feld + Storage Bucket für Profilbilder

-- ── 1. Profiles erweitern ──────────────────────────────────────────────────

ALTER TABLE public.profiles
  ADD COLUMN IF NOT EXISTS avatar_url text;

-- ── 2. Storage Bucket für Avatare ──────────────────────────────────────────

INSERT INTO storage.buckets (id, name, public)
VALUES ('avatars', 'avatars', true)
ON CONFLICT DO NOTHING;

-- ── 3. Storage RLS Policies ────────────────────────────────────────────────

-- Public Read
CREATE POLICY "avatar_select" ON storage.objects
  FOR SELECT TO public
  USING (bucket_id = 'avatars');

-- Authentifizierte User können hochladen
CREATE POLICY "avatar_insert" ON storage.objects
  FOR INSERT TO authenticated
  WITH CHECK (bucket_id = 'avatars');

-- Eigene Dateien aktualisieren
CREATE POLICY "avatar_update" ON storage.objects
  FOR UPDATE TO authenticated
  USING (bucket_id = 'avatars');

-- Eigene Dateien löschen
CREATE POLICY "avatar_delete" ON storage.objects
  FOR DELETE TO authenticated
  USING (bucket_id = 'avatars');
