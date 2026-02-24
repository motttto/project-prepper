-- Migration 013: Supabase Storage Bucket für Inventar-Fotos
-- ==========================================================

-- 1. Storage Bucket erstellen (public = Bilder ohne Auth-Token abrufbar)
INSERT INTO storage.buckets (id, name, public)
VALUES ('inventory-images', 'inventory-images', true)
ON CONFLICT (id) DO NOTHING;

-- 2. RLS Policies

-- Authentifizierte User können Bilder hochladen
CREATE POLICY "Authenticated users can upload inventory images"
  ON storage.objects FOR INSERT
  TO authenticated
  WITH CHECK (bucket_id = 'inventory-images');

-- Authentifizierte User können Bilder aktualisieren
CREATE POLICY "Authenticated users can update inventory images"
  ON storage.objects FOR UPDATE
  TO authenticated
  USING (bucket_id = 'inventory-images');

-- Authentifizierte User können Bilder löschen
CREATE POLICY "Authenticated users can delete inventory images"
  ON storage.objects FOR DELETE
  TO authenticated
  USING (bucket_id = 'inventory-images');

-- Öffentlicher Lesezugriff (für Anzeige im Browser)
CREATE POLICY "Public read access for inventory images"
  ON storage.objects FOR SELECT
  TO public
  USING (bucket_id = 'inventory-images');
