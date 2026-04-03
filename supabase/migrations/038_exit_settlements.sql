-- Migration 038: Austritts-/Auslöse-System
-- Automatische Berechnung + Beschluss-Integration

CREATE TABLE IF NOT EXISTS public.exit_settlements (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  org_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE CASCADE,
  profile_id uuid NOT NULL REFERENCES public.profiles(id),
  status text NOT NULL DEFAULT 'draft'
    CHECK (status IN ('draft', 'pending_approval', 'approved', 'completed', 'cancelled')),
  decision_id uuid REFERENCES public.org_decisions(id) ON DELETE SET NULL,
  exit_date date NOT NULL DEFAULT CURRENT_DATE,
  -- Berechnete Summen
  total_owned_value numeric(10,2) DEFAULT 0,
  total_funded_value numeric(10,2) DEFAULT 0,
  total_payout numeric(10,2) DEFAULT 0,
  -- Details
  settlement_method text DEFAULT 'contribution_based'
    CHECK (settlement_method IN ('contribution_based', 'proportional', 'zero', 'custom')),
  notes text,
  created_by uuid NOT NULL REFERENCES public.profiles(id),
  resolved_at timestamptz,
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.exit_settlement_items (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  settlement_id uuid NOT NULL REFERENCES public.exit_settlements(id) ON DELETE CASCADE,
  inventory_item_id uuid NOT NULL REFERENCES public.inventory_items(id),
  ownership_type text NOT NULL, -- 'owner' oder 'funder'
  current_value numeric(10,2) NOT NULL DEFAULT 0,
  action text NOT NULL DEFAULT 'buyout'
    CHECK (action IN ('buyout', 'return', 'keep', 'donate')),
  buyout_price numeric(10,2),
  notes text,
  created_at timestamptz DEFAULT now()
);

-- Indizes
CREATE INDEX IF NOT EXISTS idx_exit_settlements_org ON public.exit_settlements(org_id);
CREATE INDEX IF NOT EXISTS idx_exit_settlements_profile ON public.exit_settlements(profile_id);
CREATE INDEX IF NOT EXISTS idx_exit_settlement_items_settlement ON public.exit_settlement_items(settlement_id);

-- RLS
ALTER TABLE public.exit_settlements ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.exit_settlement_items ENABLE ROW LEVEL SECURITY;

CREATE POLICY "exit_settlements_select" ON public.exit_settlements
  FOR SELECT TO authenticated USING (true);
CREATE POLICY "exit_settlements_insert" ON public.exit_settlements
  FOR INSERT TO authenticated WITH CHECK (created_by = auth.uid());
CREATE POLICY "exit_settlements_update" ON public.exit_settlements
  FOR UPDATE TO authenticated USING (true);

CREATE POLICY "exit_settlement_items_select" ON public.exit_settlement_items
  FOR SELECT TO authenticated USING (true);
CREATE POLICY "exit_settlement_items_insert" ON public.exit_settlement_items
  FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "exit_settlement_items_update" ON public.exit_settlement_items
  FOR UPDATE TO authenticated USING (true);
CREATE POLICY "exit_settlement_items_delete" ON public.exit_settlement_items
  FOR DELETE TO authenticated USING (true);

-- RPC: Auslöse für ein Mitglied berechnen
CREATE OR REPLACE FUNCTION calculate_exit_settlement(
  p_org_id uuid,
  p_profile_id uuid
) RETURNS jsonb AS $$
DECLARE
  v_result jsonb;
  v_owned_items jsonb;
  v_funded_items jsonb;
  v_total_owned numeric := 0;
  v_total_funded numeric := 0;
BEGIN
  -- Items wo das Mitglied Eigentümer ist
  SELECT COALESCE(jsonb_agg(jsonb_build_object(
    'id', id,
    'name', name,
    'inventory_number', inventory_number,
    'purchase_price', purchase_price,
    'current_value', current_value,
    'ownership_type', ownership_type,
    'condition', condition
  )), '[]'::jsonb),
  COALESCE(SUM(current_value), 0)
  INTO v_owned_items, v_total_owned
  FROM inventory_items
  WHERE org_id = p_org_id
    AND owner_profile_id = p_profile_id;

  -- Items wo das Mitglied Finanzier ist (funding_source = 'self', purchased_by = name)
  SELECT COALESCE(jsonb_agg(jsonb_build_object(
    'id', id,
    'name', name,
    'inventory_number', inventory_number,
    'purchase_price', purchase_price,
    'current_value', current_value,
    'ownership_type', ownership_type,
    'funding_source', funding_source,
    'condition', condition
  )), '[]'::jsonb),
  COALESCE(SUM(current_value), 0)
  INTO v_funded_items, v_total_funded
  FROM inventory_items
  WHERE org_id = p_org_id
    AND funding_source = 'self'
    AND ownership_type = 'organization'
    AND purchased_by = (SELECT name FROM profiles WHERE id = p_profile_id);

  v_result := jsonb_build_object(
    'profile_id', p_profile_id,
    'owned_items', v_owned_items,
    'funded_items', v_funded_items,
    'total_owned_value', v_total_owned,
    'total_funded_value', v_total_funded,
    'total_settlement', v_total_owned + v_total_funded
  );

  RETURN v_result;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Realtime
ALTER PUBLICATION supabase_realtime ADD TABLE public.exit_settlements;
ALTER PUBLICATION supabase_realtime ADD TABLE public.exit_settlement_items;
