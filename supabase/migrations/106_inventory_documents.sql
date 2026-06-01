-- Migration 106: PDF-Dokumente fuer Inventar-Artikel
-- ====================================================
-- Ziel: Pro Inventar-Artikel mehrere PDFs hochladen + oeffnen
-- (Handbuch, Datenblatt, Rechnung, ...).
--
-- Sichtbarkeit "wie das Inventar selbst":
--   - Lesen/Oeffnen: jeder, der das Item sehen darf (Owner, Gruppen-Mitglied,
--     Item ueber Gruppe geteilt, Superadmin)
--   - Hochladen/Loeschen: nur wer das Item bearbeiten darf
--     (Owner, Gruppen-Mitglied, Superadmin)
--
-- Storage-Bucket 'inventory-documents' ist PRIVAT → Zugriff nur via Signed-URL
-- (createSignedUrl). Pfad-Schema: {item_id}/{timestamp}.pdf

-- ════════════════════════════════════════════════════════════════════════════
-- 1. Helper: Zugriff (lesen) auf ein Inventar-Item
-- ════════════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION public.can_access_inventory_item(p_item_id uuid)
RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  SELECT EXISTS (
    SELECT 1 FROM public.inventory_items i
    WHERE i.id = p_item_id
      AND (
        i.owner_profile_id = auth.uid()
        OR (i.owner_group_id IS NOT NULL AND public.is_group_member(i.owner_group_id))
        OR EXISTS (
          SELECT 1 FROM public.inventory_group_shares igs
          WHERE igs.inventory_item_id = i.id
            AND igs.revoked_at IS NULL
            AND public.is_group_member(igs.group_id)
        )
        OR EXISTS (
          SELECT 1 FROM public.profiles pr
          WHERE pr.id = auth.uid() AND pr.is_system = true
        )
      )
  );
$$;

GRANT EXECUTE ON FUNCTION public.can_access_inventory_item(uuid) TO authenticated;

COMMENT ON FUNCTION public.can_access_inventory_item(uuid) IS
  'RLS-Helper: darf auth.uid() das Inventar-Item SEHEN (Owner, Group-Member, ueber Gruppe geteilt, Superadmin). SECURITY DEFINER umgeht RLS-Rekursion.';

-- ════════════════════════════════════════════════════════════════════════════
-- 2. Helper: Bearbeiten (schreiben) eines Inventar-Items
-- ════════════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION public.can_edit_inventory_item(p_item_id uuid)
RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  SELECT EXISTS (
    SELECT 1 FROM public.inventory_items i
    WHERE i.id = p_item_id
      AND (
        i.owner_profile_id = auth.uid()
        OR (i.owner_group_id IS NOT NULL AND public.is_group_member(i.owner_group_id))
        OR EXISTS (
          SELECT 1 FROM public.profiles pr
          WHERE pr.id = auth.uid() AND pr.is_system = true
        )
      )
  );
$$;

GRANT EXECUTE ON FUNCTION public.can_edit_inventory_item(uuid) TO authenticated;

COMMENT ON FUNCTION public.can_edit_inventory_item(uuid) IS
  'RLS-Helper: darf auth.uid() das Inventar-Item BEARBEITEN (Owner, Group-Member, Superadmin). SECURITY DEFINER umgeht RLS-Rekursion.';

-- ════════════════════════════════════════════════════════════════════════════
-- 3. Tabelle: inventory_documents
-- ════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS public.inventory_documents (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  item_id uuid NOT NULL REFERENCES public.inventory_items(id) ON DELETE CASCADE,
  file_name text NOT NULL,            -- Anzeigename, z.B. "Handbuch.pdf"
  storage_path text NOT NULL,         -- Pfad im Bucket: {item_id}/{timestamp}.pdf
  file_size integer,                  -- Bytes (optional)
  uploaded_by uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_inventory_documents_item
  ON public.inventory_documents(item_id);

ALTER TABLE public.inventory_documents ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS inv_docs_select ON public.inventory_documents;
DROP POLICY IF EXISTS inv_docs_insert ON public.inventory_documents;
DROP POLICY IF EXISTS inv_docs_update ON public.inventory_documents;
DROP POLICY IF EXISTS inv_docs_delete ON public.inventory_documents;

CREATE POLICY inv_docs_select ON public.inventory_documents
  FOR SELECT TO authenticated USING (
    public.can_access_inventory_item(item_id)
  );

CREATE POLICY inv_docs_insert ON public.inventory_documents
  FOR INSERT TO authenticated WITH CHECK (
    public.can_edit_inventory_item(item_id)
    AND uploaded_by = auth.uid()
  );

CREATE POLICY inv_docs_update ON public.inventory_documents
  FOR UPDATE TO authenticated USING (
    public.can_edit_inventory_item(item_id)
  );

CREATE POLICY inv_docs_delete ON public.inventory_documents
  FOR DELETE TO authenticated USING (
    public.can_edit_inventory_item(item_id)
  );

-- ════════════════════════════════════════════════════════════════════════════
-- 4. Storage-Bucket 'inventory-documents' (PRIVAT) + Policies
--    Pfad-Schema: {item_id}/{timestamp}.pdf  → foldername[1] = item_id
-- ════════════════════════════════════════════════════════════════════════════

INSERT INTO storage.buckets (id, name, public)
VALUES ('inventory-documents', 'inventory-documents', false)
ON CONFLICT (id) DO NOTHING;

DROP POLICY IF EXISTS "inventory_documents_storage_select" ON storage.objects;
DROP POLICY IF EXISTS "inventory_documents_storage_insert" ON storage.objects;
DROP POLICY IF EXISTS "inventory_documents_storage_update" ON storage.objects;
DROP POLICY IF EXISTS "inventory_documents_storage_delete" ON storage.objects;

CREATE POLICY "inventory_documents_storage_select" ON storage.objects
  FOR SELECT TO authenticated USING (
    bucket_id = 'inventory-documents'
    AND public.can_access_inventory_item(
      ((storage.foldername(name))[1])::uuid
    )
  );

CREATE POLICY "inventory_documents_storage_insert" ON storage.objects
  FOR INSERT TO authenticated WITH CHECK (
    bucket_id = 'inventory-documents'
    AND public.can_edit_inventory_item(
      ((storage.foldername(name))[1])::uuid
    )
  );

CREATE POLICY "inventory_documents_storage_update" ON storage.objects
  FOR UPDATE TO authenticated USING (
    bucket_id = 'inventory-documents'
    AND public.can_edit_inventory_item(
      ((storage.foldername(name))[1])::uuid
    )
  );

CREATE POLICY "inventory_documents_storage_delete" ON storage.objects
  FOR DELETE TO authenticated USING (
    bucket_id = 'inventory-documents'
    AND public.can_edit_inventory_item(
      ((storage.foldername(name))[1])::uuid
    )
  );
