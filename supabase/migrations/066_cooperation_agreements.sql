-- Migration 066: Kooperationsvereinbarungen (Cooperation Agreements)
-- ==================================================================
-- Formale Vereinbarung zwischen Projekt-Beteiligten VOR Projektstart:
-- - Wer macht mit (Rollen, Verantwortlichkeiten)
-- - Was wird eingebracht (Inventar-Beiträge mit Tagessatz)
-- - Wie wird der Gewinn aufgeteilt (flexible Formel)
-- - Was passiert bei Mid-Project-Exit (Exit-Regeln)
-- Alle Beteiligten müssen digital zustimmen. Amendments via bestehendes Decisions-System.

-- Haupt-Vereinbarung
CREATE TABLE IF NOT EXISTS public.cooperation_agreements (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id uuid UNIQUE NOT NULL REFERENCES public.projects(id) ON DELETE CASCADE,
  org_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE CASCADE,
  status text NOT NULL DEFAULT 'draft'
    CHECK (status IN ('draft', 'signing', 'active', 'amended', 'terminated')),
  version integer NOT NULL DEFAULT 1,
  -- Gewinnverteilungs-Formel
  profit_formula jsonb NOT NULL DEFAULT '{"method":"mixed","weights":{"hours":0.5,"inventory":0.3,"capital":0.1,"fixed":0.1}}',
  -- Exit-Regeln
  exit_rules jsonb NOT NULL DEFAULT '{"forfeit_if_exit_before_event":false,"inventory_return_window_days":7}',
  -- Freitext
  additional_terms text,
  -- Meta
  created_by uuid NOT NULL REFERENCES public.profiles(id),
  activated_at timestamptz,
  terminated_at timestamptz,
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now()
);

-- Inventar-Beiträge
CREATE TABLE IF NOT EXISTS public.agreement_inventory_contributions (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  agreement_id uuid NOT NULL REFERENCES public.cooperation_agreements(id) ON DELETE CASCADE,
  inventory_item_id uuid NOT NULL REFERENCES public.inventory_items(id),
  contributor_id uuid NOT NULL REFERENCES public.profiles(id),
  daily_rate numeric(10,2) NOT NULL DEFAULT 0,
  quantity integer NOT NULL DEFAULT 1,
  notes text,
  created_at timestamptz DEFAULT now()
);

-- Rollen / Verantwortlichkeiten
CREATE TABLE IF NOT EXISTS public.agreement_roles (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  agreement_id uuid NOT NULL REFERENCES public.cooperation_agreements(id) ON DELETE CASCADE,
  profile_id uuid NOT NULL REFERENCES public.profiles(id),
  role_title text NOT NULL,
  responsibilities text,
  hourly_rate numeric(10,2) DEFAULT 0,
  hours_estimate numeric(6,2) DEFAULT 0,
  capital_contribution numeric(10,2) DEFAULT 0,
  fixed_amount numeric(10,2) DEFAULT 0,
  created_at timestamptz DEFAULT now(),
  UNIQUE(agreement_id, profile_id)
);

-- Signaturen
CREATE TABLE IF NOT EXISTS public.agreement_signatures (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  agreement_id uuid NOT NULL REFERENCES public.cooperation_agreements(id) ON DELETE CASCADE,
  profile_id uuid NOT NULL REFERENCES public.profiles(id),
  signed_at timestamptz,
  declined_at timestamptz,
  comment text,
  created_at timestamptz DEFAULT now(),
  UNIQUE(agreement_id, profile_id)
);

-- Amendments
CREATE TABLE IF NOT EXISTS public.agreement_amendments (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  agreement_id uuid NOT NULL REFERENCES public.cooperation_agreements(id) ON DELETE CASCADE,
  decision_id uuid REFERENCES public.org_decisions(id) ON DELETE SET NULL,
  summary text NOT NULL,
  changes_json jsonb NOT NULL DEFAULT '{}',
  status text DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
  created_by uuid REFERENCES public.profiles(id),
  created_at timestamptz DEFAULT now(),
  resolved_at timestamptz
);

-- Indizes
CREATE INDEX IF NOT EXISTS idx_coop_agreements_project ON public.cooperation_agreements(project_id);
CREATE INDEX IF NOT EXISTS idx_coop_agreements_org ON public.cooperation_agreements(org_id);
CREATE INDEX IF NOT EXISTS idx_coop_agreements_status ON public.cooperation_agreements(status);
CREATE INDEX IF NOT EXISTS idx_agreement_contrib_agreement ON public.agreement_inventory_contributions(agreement_id);
CREATE INDEX IF NOT EXISTS idx_agreement_roles_agreement ON public.agreement_roles(agreement_id);
CREATE INDEX IF NOT EXISTS idx_agreement_signatures_agreement ON public.agreement_signatures(agreement_id);
CREATE INDEX IF NOT EXISTS idx_agreement_amendments_agreement ON public.agreement_amendments(agreement_id);

-- RLS
ALTER TABLE public.cooperation_agreements ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.agreement_inventory_contributions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.agreement_roles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.agreement_signatures ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.agreement_amendments ENABLE ROW LEVEL SECURITY;

-- Policies: Alle aktiven Org-Mitglieder duerfen lesen
CREATE POLICY "coop_agreements_select" ON public.cooperation_agreements
  FOR SELECT TO authenticated USING (
    EXISTS (
      SELECT 1 FROM org_memberships om
      WHERE om.profile_id = auth.uid()
        AND om.org_id = cooperation_agreements.org_id
        AND om.is_active = true
    )
    OR EXISTS (SELECT 1 FROM profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "coop_agreements_insert" ON public.cooperation_agreements
  FOR INSERT TO authenticated WITH CHECK (created_by = auth.uid());

CREATE POLICY "coop_agreements_update" ON public.cooperation_agreements
  FOR UPDATE TO authenticated USING (
    created_by = auth.uid()
    OR EXISTS (
      SELECT 1 FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      WHERE om.profile_id = auth.uid()
        AND om.org_id = cooperation_agreements.org_id
        AND r.name = 'admin'
        AND om.is_active = true
    )
    OR EXISTS (SELECT 1 FROM profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "coop_agreements_delete" ON public.cooperation_agreements
  FOR DELETE TO authenticated USING (
    created_by = auth.uid() AND status = 'draft'
  );

-- Tochtertabellen: offener Select, Writes nur wenn Agreement editierbar
CREATE POLICY "agreement_contrib_select" ON public.agreement_inventory_contributions
  FOR SELECT TO authenticated USING (true);
CREATE POLICY "agreement_contrib_all" ON public.agreement_inventory_contributions
  FOR ALL TO authenticated USING (true) WITH CHECK (true);

CREATE POLICY "agreement_roles_select" ON public.agreement_roles
  FOR SELECT TO authenticated USING (true);
CREATE POLICY "agreement_roles_all" ON public.agreement_roles
  FOR ALL TO authenticated USING (true) WITH CHECK (true);

CREATE POLICY "agreement_signatures_select" ON public.agreement_signatures
  FOR SELECT TO authenticated USING (true);
CREATE POLICY "agreement_signatures_insert" ON public.agreement_signatures
  FOR INSERT TO authenticated WITH CHECK (profile_id = auth.uid());
CREATE POLICY "agreement_signatures_update" ON public.agreement_signatures
  FOR UPDATE TO authenticated USING (profile_id = auth.uid());
CREATE POLICY "agreement_signatures_delete" ON public.agreement_signatures
  FOR DELETE TO authenticated USING (profile_id = auth.uid());

CREATE POLICY "agreement_amendments_select" ON public.agreement_amendments
  FOR SELECT TO authenticated USING (true);
CREATE POLICY "agreement_amendments_all" ON public.agreement_amendments
  FOR ALL TO authenticated USING (true) WITH CHECK (true);

-- updated_at Trigger
CREATE OR REPLACE FUNCTION public.update_coop_agreement_timestamp()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_coop_agreement_updated ON public.cooperation_agreements;
CREATE TRIGGER trg_coop_agreement_updated
  BEFORE UPDATE ON public.cooperation_agreements
  FOR EACH ROW EXECUTE FUNCTION public.update_coop_agreement_timestamp();

-- Trigger: Status auf "active" setzen wenn alle Signaturen vorliegen
CREATE OR REPLACE FUNCTION public.check_agreement_signatures()
RETURNS TRIGGER AS $$
DECLARE
  v_agreement cooperation_agreements%ROWTYPE;
  v_required_count integer;
  v_signed_count integer;
BEGIN
  SELECT * INTO v_agreement FROM cooperation_agreements WHERE id = NEW.agreement_id;
  IF v_agreement.status NOT IN ('signing', 'draft') THEN
    RETURN NEW;
  END IF;

  -- Anzahl erforderlicher Signaturen = Anzahl Projekt-Mitglieder
  SELECT COUNT(*) INTO v_required_count
  FROM project_members WHERE project_id = v_agreement.project_id;

  -- Anzahl geleisteter Signaturen
  SELECT COUNT(*) INTO v_signed_count
  FROM agreement_signatures
  WHERE agreement_id = NEW.agreement_id AND signed_at IS NOT NULL;

  -- Wenn jemand ablehnt -> zurueck zu draft
  IF NEW.declined_at IS NOT NULL THEN
    UPDATE cooperation_agreements
    SET status = 'draft'
    WHERE id = NEW.agreement_id;
    RETURN NEW;
  END IF;

  -- Alle haben signiert -> active
  IF v_required_count > 0 AND v_signed_count >= v_required_count THEN
    UPDATE cooperation_agreements
    SET status = 'active', activated_at = now()
    WHERE id = NEW.agreement_id;
  END IF;

  RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

DROP TRIGGER IF EXISTS trg_check_agreement_signatures ON public.agreement_signatures;
CREATE TRIGGER trg_check_agreement_signatures
  AFTER INSERT OR UPDATE ON public.agreement_signatures
  FOR EACH ROW EXECUTE FUNCTION public.check_agreement_signatures();

-- Realtime
ALTER PUBLICATION supabase_realtime ADD TABLE public.cooperation_agreements;
ALTER PUBLICATION supabase_realtime ADD TABLE public.agreement_inventory_contributions;
ALTER PUBLICATION supabase_realtime ADD TABLE public.agreement_roles;
ALTER PUBLICATION supabase_realtime ADD TABLE public.agreement_signatures;
ALTER PUBLICATION supabase_realtime ADD TABLE public.agreement_amendments;
