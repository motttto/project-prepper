-- Migration 036: Asset-Ownership & Abschreibung
-- Klare Eigentumsverhältnisse + automatische Wertberechnung

-- Ownership-Type: Wem gehört das Equipment?
ALTER TABLE public.inventory_items
  ADD COLUMN IF NOT EXISTS ownership_type text DEFAULT 'organization'
    CHECK (ownership_type IN ('organization', 'member', 'shared')),
  ADD COLUMN IF NOT EXISTS owner_profile_id uuid REFERENCES public.profiles(id),
  ADD COLUMN IF NOT EXISTS ownership_shares jsonb,
  ADD COLUMN IF NOT EXISTS funding_source text DEFAULT 'organization'
    CHECK (funding_source IN ('organization', 'self', 'project', 'sponsor')),
  ADD COLUMN IF NOT EXISTS receipt_url text;

-- Abschreibung
ALTER TABLE public.inventory_items
  ADD COLUMN IF NOT EXISTS depreciation_method text DEFAULT 'linear'
    CHECK (depreciation_method IN ('linear', 'none')),
  ADD COLUMN IF NOT EXISTS depreciation_years integer DEFAULT 7,
  ADD COLUMN IF NOT EXISTS residual_value numeric(10,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS current_value numeric(10,2);

-- Defaults für Abschreibungsdauer nach Kategorie (informativ, wird im UI genutzt)
COMMENT ON COLUMN public.inventory_items.depreciation_years IS
  'Standard-AfA: Licht=7, Audio=7, Kabel=5, Rigging=10, Video=5, Strom=10, Werkzeug=10, Cases=10';

-- Funktion: Aktuellen Wert berechnen
CREATE OR REPLACE FUNCTION calculate_current_value(
  p_purchase_price numeric,
  p_purchased_at date,
  p_depreciation_method text,
  p_depreciation_years integer,
  p_residual_value numeric,
  p_condition text
) RETURNS numeric AS $$
DECLARE
  v_age_years numeric;
  v_annual_depreciation numeric;
  v_value numeric;
BEGIN
  -- Kein Kaufpreis → kein Wert berechenbar
  IF p_purchase_price IS NULL OR p_purchased_at IS NULL THEN
    RETURN NULL;
  END IF;

  -- Keine Abschreibung
  IF p_depreciation_method = 'none' THEN
    v_value := p_purchase_price;
  ELSE
    -- Lineare Abschreibung
    v_age_years := (CURRENT_DATE - p_purchased_at)::numeric / 365.25;
    v_annual_depreciation := (p_purchase_price - COALESCE(p_residual_value, 0))
                             / GREATEST(p_depreciation_years, 1);
    v_value := GREATEST(
      COALESCE(p_residual_value, 0),
      p_purchase_price - (v_annual_depreciation * v_age_years)
    );
  END IF;

  -- Zustandsabzug
  IF p_condition = 'poor' THEN
    v_value := v_value * 0.7;
  ELSIF p_condition = 'broken' THEN
    v_value := v_value * 0.1;
  ELSIF p_condition = 'retired' THEN
    v_value := 0;
  END IF;

  RETURN ROUND(v_value, 2);
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- Trigger: current_value automatisch aktualisieren
CREATE OR REPLACE FUNCTION update_inventory_current_value()
RETURNS TRIGGER AS $$
BEGIN
  NEW.current_value := calculate_current_value(
    NEW.purchase_price,
    NEW.purchased_at::date,
    NEW.depreciation_method,
    NEW.depreciation_years,
    NEW.residual_value,
    NEW.condition
  );
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_inventory_current_value ON public.inventory_items;
CREATE TRIGGER trg_inventory_current_value
  BEFORE INSERT OR UPDATE ON public.inventory_items
  FOR EACH ROW
  EXECUTE FUNCTION update_inventory_current_value();

-- Bestehende Items: current_value initial berechnen
UPDATE public.inventory_items
SET current_value = calculate_current_value(
  purchase_price,
  purchased_at::date,
  COALESCE(depreciation_method, 'linear'),
  COALESCE(depreciation_years, 7),
  COALESCE(residual_value, 0),
  condition
)
WHERE purchase_price IS NOT NULL AND purchased_at IS NOT NULL;

-- RPC: Gesamtwert pro Org berechnen
CREATE OR REPLACE FUNCTION get_inventory_total_value(p_org_id uuid)
RETURNS TABLE(
  total_purchase_value numeric,
  total_current_value numeric,
  total_depreciation numeric,
  item_count bigint
) AS $$
BEGIN
  RETURN QUERY
  SELECT
    COALESCE(SUM(purchase_price), 0)::numeric as total_purchase_value,
    COALESCE(SUM(current_value), 0)::numeric as total_current_value,
    COALESCE(SUM(purchase_price - COALESCE(current_value, purchase_price)), 0)::numeric as total_depreciation,
    COUNT(*)::bigint as item_count
  FROM public.inventory_items
  WHERE org_id = p_org_id
    AND purchase_price IS NOT NULL;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- RPC: Wert pro Mitglied (owner_profile_id)
CREATE OR REPLACE FUNCTION get_member_asset_value(p_org_id uuid, p_profile_id uuid)
RETURNS TABLE(
  total_purchase_value numeric,
  total_current_value numeric,
  item_count bigint
) AS $$
BEGIN
  RETURN QUERY
  SELECT
    COALESCE(SUM(purchase_price), 0)::numeric,
    COALESCE(SUM(current_value), 0)::numeric,
    COUNT(*)::bigint
  FROM public.inventory_items
  WHERE org_id = p_org_id
    AND owner_profile_id = p_profile_id;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
