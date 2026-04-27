-- Migration 091: Storage-Bucket fuer Gruppen-Logos
-- ===================================================

INSERT INTO storage.buckets (id, name, public)
VALUES ('group-logos', 'group-logos', true)
ON CONFLICT (id) DO NOTHING;

-- RLS: Lesen fuer alle (Bucket ist public)
DROP POLICY IF EXISTS "group_logos_select" ON storage.objects;
CREATE POLICY "group_logos_select" ON storage.objects
  FOR SELECT TO public USING (bucket_id = 'group-logos');

-- Upload + Update + Delete: aktive Group-Member duerfen Logo der eigenen Gruppe verwalten
-- Pfad-Convention: {group_id}/{timestamp}.webp
DROP POLICY IF EXISTS "group_logos_insert" ON storage.objects;
CREATE POLICY "group_logos_insert" ON storage.objects
  FOR INSERT TO authenticated WITH CHECK (
    bucket_id = 'group-logos'
    AND is_group_member((string_to_array(name, '/'))[1]::uuid)
  );

DROP POLICY IF EXISTS "group_logos_update" ON storage.objects;
CREATE POLICY "group_logos_update" ON storage.objects
  FOR UPDATE TO authenticated USING (
    bucket_id = 'group-logos'
    AND is_group_member((string_to_array(name, '/'))[1]::uuid)
  );

DROP POLICY IF EXISTS "group_logos_delete" ON storage.objects;
CREATE POLICY "group_logos_delete" ON storage.objects
  FOR DELETE TO authenticated USING (
    bucket_id = 'group-logos'
    AND is_group_member((string_to_array(name, '/'))[1]::uuid)
  );
