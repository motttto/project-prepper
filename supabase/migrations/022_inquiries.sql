-- ============================================================================
-- Migration 022: Anfragen-System (Inquiry Pipeline)
-- Kundenanfragen erfassen, Angebote tracken, zu Projekten konvertieren
-- ============================================================================

-- 0. Sicherstellen, dass handle_updated_at() existiert
CREATE OR REPLACE FUNCTION public.handle_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- 1. Tabelle: inquiries
CREATE TABLE IF NOT EXISTS public.inquiries (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  org_id UUID NOT NULL REFERENCES public.organizations(id) ON DELETE CASCADE,

  -- Status-Pipeline
  status TEXT NOT NULL DEFAULT 'new'
    CHECK (status IN ('new', 'reviewing', 'offer_sent', 'accepted', 'rejected', 'archived')),

  -- Kunde
  client_name TEXT NOT NULL,
  client_contact_person TEXT,
  client_phone TEXT,
  client_email TEXT,

  -- Event-Details
  title TEXT NOT NULL,
  description TEXT,
  venue_name TEXT,
  venue_address TEXT,
  event_date_start DATE,
  event_date_end DATE,

  -- Angebots-Tracking
  estimated_budget NUMERIC(12,2),
  offer_amount NUMERIC(12,2),
  offer_date DATE,
  offer_valid_until DATE,
  probability INTEGER CHECK (probability >= 0 AND probability <= 100),

  -- Follow-Up
  next_follow_up DATE,

  -- Notizen
  notes TEXT,

  -- Verknüpfung zu Projekt (nach Konvertierung)
  project_id UUID REFERENCES public.projects(id) ON DELETE SET NULL,

  -- Meta
  created_by UUID REFERENCES auth.users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- 2. Indexes
CREATE INDEX IF NOT EXISTS idx_inquiries_org_id ON public.inquiries(org_id);
CREATE INDEX IF NOT EXISTS idx_inquiries_status ON public.inquiries(org_id, status);
CREATE INDEX IF NOT EXISTS idx_inquiries_project_id ON public.inquiries(project_id);

-- 3. RLS aktivieren
ALTER TABLE public.inquiries ENABLE ROW LEVEL SECURITY;

-- Alle authentifizierten User der Org können lesen/schreiben
DROP POLICY IF EXISTS "inquiries_select" ON public.inquiries;
CREATE POLICY "inquiries_select" ON public.inquiries
  FOR SELECT TO authenticated
  USING (true);

DROP POLICY IF EXISTS "inquiries_insert" ON public.inquiries;
CREATE POLICY "inquiries_insert" ON public.inquiries
  FOR INSERT TO authenticated
  WITH CHECK (true);

DROP POLICY IF EXISTS "inquiries_update" ON public.inquiries;
CREATE POLICY "inquiries_update" ON public.inquiries
  FOR UPDATE TO authenticated
  USING (true);

DROP POLICY IF EXISTS "inquiries_delete" ON public.inquiries;
CREATE POLICY "inquiries_delete" ON public.inquiries
  FOR DELETE TO authenticated
  USING (true);

-- 4. updated_at Trigger
DROP TRIGGER IF EXISTS set_inquiries_updated_at ON public.inquiries;
CREATE TRIGGER set_inquiries_updated_at
  BEFORE UPDATE ON public.inquiries
  FOR EACH ROW EXECUTE FUNCTION public.handle_updated_at();

-- 5. Realtime aktivieren
ALTER PUBLICATION supabase_realtime ADD TABLE public.inquiries;
