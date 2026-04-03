-- Migration 039: Projekt-Gewinnverteilung
-- Einnahmen, Gewinn + Verteilung pro Mitglied

-- Neue Felder auf projects
ALTER TABLE public.projects
  ADD COLUMN IF NOT EXISTS revenue_actual numeric(10,2),
  ADD COLUMN IF NOT EXISTS profit_distribution_status text DEFAULT 'pending'
    CHECK (profit_distribution_status IN ('pending', 'distributed', 'settled'));

-- Gewinn-Anteile pro Mitglied
CREATE TABLE IF NOT EXISTS public.project_profit_shares (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  project_id uuid NOT NULL REFERENCES public.projects(id) ON DELETE CASCADE,
  profile_id uuid NOT NULL REFERENCES public.profiles(id),
  share_type text NOT NULL DEFAULT 'percentage'
    CHECK (share_type IN ('fixed', 'percentage', 'hourly')),
  share_value numeric(10,2) NOT NULL DEFAULT 0, -- Fixbetrag ODER Prozent ODER Stundensatz
  hours_worked numeric(6,2), -- nur bei hourly
  calculated_amount numeric(10,2) DEFAULT 0,
  is_paid boolean DEFAULT false,
  paid_at timestamptz,
  notes text,
  created_at timestamptz DEFAULT now(),
  UNIQUE(project_id, profile_id)
);

-- Indizes
CREATE INDEX IF NOT EXISTS idx_profit_shares_project ON public.project_profit_shares(project_id);
CREATE INDEX IF NOT EXISTS idx_profit_shares_profile ON public.project_profit_shares(profile_id);

-- RLS
ALTER TABLE public.project_profit_shares ENABLE ROW LEVEL SECURITY;

CREATE POLICY "profit_shares_select" ON public.project_profit_shares
  FOR SELECT TO authenticated USING (true);
CREATE POLICY "profit_shares_insert" ON public.project_profit_shares
  FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "profit_shares_update" ON public.project_profit_shares
  FOR UPDATE TO authenticated USING (true);
CREATE POLICY "profit_shares_delete" ON public.project_profit_shares
  FOR DELETE TO authenticated USING (true);

-- RPC: Gewinn berechnen (Einnahmen - Kosten)
CREATE OR REPLACE FUNCTION calculate_project_profit(p_project_id uuid)
RETURNS TABLE(
  revenue numeric,
  total_costs numeric,
  profit numeric,
  cost_breakdown jsonb
) AS $$
BEGIN
  RETURN QUERY
  SELECT
    COALESCE((SELECT revenue_actual FROM projects WHERE id = p_project_id), 0)::numeric as revenue,
    COALESCE(SUM(ci.amount_actual), 0)::numeric as total_costs,
    (COALESCE((SELECT revenue_actual FROM projects WHERE id = p_project_id), 0) -
     COALESCE(SUM(ci.amount_actual), 0))::numeric as profit,
    COALESCE(jsonb_agg(jsonb_build_object(
      'category', ci.category,
      'amount', ci.amount_actual
    )) FILTER (WHERE ci.amount_actual IS NOT NULL), '[]'::jsonb) as cost_breakdown
  FROM cost_items ci
  WHERE ci.project_id = p_project_id;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Realtime
ALTER PUBLICATION supabase_realtime ADD TABLE public.project_profit_shares;
