-- Migration 044: Toggle für Kostenposten in der Gewinnberechnung
-- Erlaubt es, einzelne Kostenposten von der Gewinnberechnung auszuschließen.

ALTER TABLE public.cost_items
  ADD COLUMN IF NOT EXISTS exclude_from_profit boolean DEFAULT false;

-- RPC: Gewinn berechnen — berücksichtigt exclude_from_profit
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
  WHERE ci.project_id = p_project_id
    AND ci.exclude_from_profit IS NOT TRUE;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
