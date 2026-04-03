-- Migration 037: Generalisierte Beschlüsse (Voting-System)
-- Erweitert das bestehende team_votes auf alle Beschlussarten

-- Beschlüsse
CREATE TABLE IF NOT EXISTS public.org_decisions (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  org_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE CASCADE,
  title text NOT NULL,
  description text,
  decision_type text NOT NULL DEFAULT 'general'
    CHECK (decision_type IN (
      'general', 'asset_purchase', 'asset_disposal',
      'profit_distribution', 'exit_settlement', 'policy'
    )),
  status text NOT NULL DEFAULT 'open'
    CHECK (status IN ('open', 'approved', 'rejected', 'expired')),
  requires_unanimous boolean DEFAULT true,
  deadline timestamptz,
  related_item_id uuid REFERENCES public.inventory_items(id) ON DELETE SET NULL,
  related_project_id uuid REFERENCES public.projects(id) ON DELETE SET NULL,
  related_profile_id uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
  metadata jsonb,
  created_by uuid NOT NULL REFERENCES public.profiles(id),
  resolved_at timestamptz,
  created_at timestamptz DEFAULT now()
);

-- Stimmen
CREATE TABLE IF NOT EXISTS public.org_decision_votes (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  decision_id uuid NOT NULL REFERENCES public.org_decisions(id) ON DELETE CASCADE,
  voter_id uuid NOT NULL REFERENCES public.profiles(id),
  vote text NOT NULL CHECK (vote IN ('approve', 'reject', 'abstain')),
  comment text,
  created_at timestamptz DEFAULT now(),
  UNIQUE(decision_id, voter_id)
);

-- Indizes
CREATE INDEX IF NOT EXISTS idx_org_decisions_org_id ON public.org_decisions(org_id);
CREATE INDEX IF NOT EXISTS idx_org_decisions_status ON public.org_decisions(status);
CREATE INDEX IF NOT EXISTS idx_org_decision_votes_decision ON public.org_decision_votes(decision_id);

-- RLS
ALTER TABLE public.org_decisions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.org_decision_votes ENABLE ROW LEVEL SECURITY;

-- Policies: Alle authentifizierten Org-Mitglieder können sehen und abstimmen
CREATE POLICY "org_decisions_select" ON public.org_decisions
  FOR SELECT TO authenticated USING (true);

CREATE POLICY "org_decisions_insert" ON public.org_decisions
  FOR INSERT TO authenticated WITH CHECK (created_by = auth.uid());

CREATE POLICY "org_decisions_update" ON public.org_decisions
  FOR UPDATE TO authenticated USING (true);

CREATE POLICY "org_decision_votes_select" ON public.org_decision_votes
  FOR SELECT TO authenticated USING (true);

CREATE POLICY "org_decision_votes_insert" ON public.org_decision_votes
  FOR INSERT TO authenticated WITH CHECK (voter_id = auth.uid());

CREATE POLICY "org_decision_votes_delete" ON public.org_decision_votes
  FOR DELETE TO authenticated USING (voter_id = auth.uid());

-- Trigger: Nach jedem Vote prüfen ob Beschluss komplett ist
CREATE OR REPLACE FUNCTION check_decision_complete()
RETURNS TRIGGER AS $$
DECLARE
  v_decision org_decisions%ROWTYPE;
  v_total_active integer;
  v_total_votes integer;
  v_approvals integer;
  v_rejections integer;
BEGIN
  -- Beschluss laden
  SELECT * INTO v_decision FROM org_decisions WHERE id = NEW.decision_id;

  -- Nur offene Beschlüsse prüfen
  IF v_decision.status != 'open' THEN
    RETURN NEW;
  END IF;

  -- Aktive Mitglieder zählen
  SELECT COUNT(*) INTO v_total_active
  FROM org_memberships
  WHERE org_id = v_decision.org_id AND is_active = true;

  -- Stimmen zählen
  SELECT COUNT(*),
         COUNT(*) FILTER (WHERE vote = 'approve'),
         COUNT(*) FILTER (WHERE vote = 'reject')
  INTO v_total_votes, v_approvals, v_rejections
  FROM org_decision_votes
  WHERE decision_id = NEW.decision_id;

  -- Ergebnis prüfen
  IF v_decision.requires_unanimous THEN
    -- Einstimmig: Alle müssen zustimmen
    IF v_approvals = v_total_active THEN
      UPDATE org_decisions SET status = 'approved', resolved_at = now()
      WHERE id = NEW.decision_id;
    ELSIF v_rejections > 0 THEN
      UPDATE org_decisions SET status = 'rejected', resolved_at = now()
      WHERE id = NEW.decision_id;
    END IF;
  ELSE
    -- Mehrheit: > 50% der Stimmen
    IF v_total_votes = v_total_active THEN
      IF v_approvals > v_rejections THEN
        UPDATE org_decisions SET status = 'approved', resolved_at = now()
        WHERE id = NEW.decision_id;
      ELSE
        UPDATE org_decisions SET status = 'rejected', resolved_at = now()
        WHERE id = NEW.decision_id;
      END IF;
    END IF;
  END IF;

  RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

DROP TRIGGER IF EXISTS trg_check_decision_complete ON public.org_decision_votes;
CREATE TRIGGER trg_check_decision_complete
  AFTER INSERT OR UPDATE ON public.org_decision_votes
  FOR EACH ROW
  EXECUTE FUNCTION check_decision_complete();

-- Realtime aktivieren
ALTER PUBLICATION supabase_realtime ADD TABLE public.org_decisions;
ALTER PUBLICATION supabase_realtime ADD TABLE public.org_decision_votes;
