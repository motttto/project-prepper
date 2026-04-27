-- Migration 092: Item-Erträge fixieren bei Projektabschluss
-- ===========================================================
-- Schreibt pro Inventar-Item, das ueber ein Cooperation-Agreement
-- in einem Projekt eingebracht wurde, eine Snapshot-Zeile mit dem
-- berechneten Owner-Payout. Wird genau dann gesetzt, wenn das Projekt
-- auf profit_distribution_status='distributed' wechselt.

CREATE TABLE IF NOT EXISTS public.inventory_item_earnings (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  item_id uuid NOT NULL REFERENCES public.inventory_items(id) ON DELETE CASCADE,
  project_id uuid NOT NULL REFERENCES public.projects(id) ON DELETE CASCADE,
  agreement_id uuid REFERENCES public.cooperation_agreements(id) ON DELETE SET NULL,
  contributor_id uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
  daily_rate numeric NOT NULL,
  quantity integer NOT NULL,
  project_days integer NOT NULL,
  -- Brutto-Beitrag des Items (Tagessatz × Menge × Tage)
  gross_contribution numeric NOT NULL,
  -- Item-Anteil am Inventar-Topf des Agreements (0..1)
  share_of_inventory numeric NOT NULL,
  -- Auszahlung an den Owner — nach Anwendung der Profit-Formel
  owner_payout numeric NOT NULL,
  -- Snapshot des Profit-Kontextes
  revenue_at_distribution numeric NOT NULL,
  net_profit_at_distribution numeric NOT NULL,
  formula_snapshot jsonb,
  distributed_at timestamptz NOT NULL DEFAULT now(),
  created_at timestamptz DEFAULT now(),
  -- Pro Item+Projekt nur ein Eintrag (verhindert Doppel-Snapshots)
  UNIQUE (item_id, project_id)
);

CREATE INDEX inventory_item_earnings_item_idx ON public.inventory_item_earnings(item_id);
CREATE INDEX inventory_item_earnings_contributor_idx ON public.inventory_item_earnings(contributor_id);

ALTER TABLE public.inventory_item_earnings ENABLE ROW LEVEL SECURITY;

-- Sehen: Item-Owner, Contributor, Group-Mitglieder des Items, Superadmin
CREATE POLICY "inv_earnings_select" ON public.inventory_item_earnings
  FOR SELECT TO authenticated USING (
    contributor_id = auth.uid()
    OR EXISTS (
      SELECT 1 FROM public.inventory_items i
      WHERE i.id = inventory_item_earnings.item_id
        AND (
          i.owner_profile_id = auth.uid()
          OR (i.owner_group_id IS NOT NULL AND is_group_member(i.owner_group_id))
        )
    )
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

-- ── RPC: Snapshot fuer ein Projekt anlegen ──────────────────────────────────
-- Wird vom Trigger und ggf. manuell aufgerufen. Idempotent dank UNIQUE.

CREATE OR REPLACE FUNCTION public.snapshot_project_earnings(p_project_id uuid)
RETURNS integer
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
DECLARE
  v_revenue numeric := 0;
  v_costs numeric := 0;
  v_net_profit numeric := 0;
  v_pre_deductions numeric := 0;
  v_inventory_total numeric := 0;
  v_inventory_weight numeric := 0;
  v_weight_sum numeric := 0;
  v_project_days integer := 1;
  v_agreement_id uuid;
  v_formula jsonb;
  v_inserted integer := 0;
  contrib_record record;
  pre_d jsonb;
BEGIN
  -- Aktives Cooperation-Agreement fuer dieses Projekt
  SELECT id, profit_formula INTO v_agreement_id, v_formula
  FROM cooperation_agreements
  WHERE project_id = p_project_id
    AND status = 'active'
  ORDER BY created_at DESC
  LIMIT 1;

  IF v_agreement_id IS NULL THEN
    RETURN 0;
  END IF;

  -- Revenue + Kosten
  SELECT COALESCE(revenue_actual, 0) INTO v_revenue FROM projects WHERE id = p_project_id;
  SELECT COALESCE(SUM(amount_actual), 0) INTO v_costs FROM cost_items WHERE project_id = p_project_id;

  -- Pre-Deductions aus Formel
  IF v_formula ? 'pre_deductions' THEN
    FOR pre_d IN SELECT * FROM jsonb_array_elements(v_formula->'pre_deductions')
    LOOP
      v_pre_deductions := v_pre_deductions + COALESCE((pre_d->>'amount')::numeric, 0);
    END LOOP;
  END IF;

  v_net_profit := v_revenue - v_costs - v_pre_deductions;

  IF v_net_profit <= 0 THEN
    -- Kein Gewinn → trotzdem Snapshot mit owner_payout=0 (zur Historie)
    v_net_profit := 0;
  END IF;

  -- Project-Days (vereinfacht: date_end - date_start, mind. 1)
  SELECT GREATEST(1, COALESCE(date_end::date - date_start::date, 1) + 1)
    INTO v_project_days
    FROM projects WHERE id = p_project_id;

  -- Gewichtungen aus Formel
  v_inventory_weight := COALESCE((v_formula->'weights'->>'inventory')::numeric, 0);
  v_weight_sum :=
    COALESCE((v_formula->'weights'->>'hours')::numeric, 0)
    + v_inventory_weight
    + COALESCE((v_formula->'weights'->>'capital')::numeric, 0)
    + COALESCE((v_formula->'weights'->>'fixed')::numeric, 0);

  -- Wenn keine Inventar-Gewichtung → keine Item-Payouts
  IF v_inventory_weight = 0 OR v_weight_sum = 0 THEN
    -- Schreibe trotzdem 0-Snapshots fuer Historie
    NULL;
  END IF;

  -- Summe aller Inventar-Beiträge (Tagessatz × Menge × Tage) im Agreement
  SELECT COALESCE(SUM(daily_rate * quantity * v_project_days), 0)
    INTO v_inventory_total
    FROM agreement_inventory_contributions
    WHERE agreement_id = v_agreement_id;

  -- Pro Contribution: Snapshot
  FOR contrib_record IN
    SELECT id, inventory_item_id, contributor_id, daily_rate, quantity
    FROM agreement_inventory_contributions
    WHERE agreement_id = v_agreement_id
  LOOP
    DECLARE
      gross numeric;
      share numeric;
      payout numeric := 0;
    BEGIN
      gross := contrib_record.daily_rate * contrib_record.quantity * v_project_days;
      share := CASE WHEN v_inventory_total > 0 THEN gross / v_inventory_total ELSE 0 END;
      IF v_weight_sum > 0 AND v_inventory_weight > 0 THEN
        payout := v_net_profit * (v_inventory_weight / v_weight_sum) * share;
      END IF;

      INSERT INTO inventory_item_earnings (
        item_id, project_id, agreement_id, contributor_id,
        daily_rate, quantity, project_days,
        gross_contribution, share_of_inventory, owner_payout,
        revenue_at_distribution, net_profit_at_distribution,
        formula_snapshot
      ) VALUES (
        contrib_record.inventory_item_id, p_project_id, v_agreement_id,
        contrib_record.contributor_id,
        contrib_record.daily_rate, contrib_record.quantity, v_project_days,
        gross, share, ROUND(payout::numeric, 2),
        v_revenue, v_net_profit, v_formula
      )
      ON CONFLICT (item_id, project_id) DO NOTHING;
      v_inserted := v_inserted + 1;
    END;
  END LOOP;

  RETURN v_inserted;
END;
$$;

GRANT EXECUTE ON FUNCTION public.snapshot_project_earnings(uuid) TO authenticated;

-- ── Trigger: bei Wechsel auf 'distributed' Snapshot anlegen ─────────────────

CREATE OR REPLACE FUNCTION public.trg_snapshot_on_distribution()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
  IF NEW.profit_distribution_status = 'distributed'
     AND (OLD.profit_distribution_status IS DISTINCT FROM 'distributed') THEN
    PERFORM public.snapshot_project_earnings(NEW.id);
  END IF;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS projects_snapshot_on_distribution ON public.projects;
CREATE TRIGGER projects_snapshot_on_distribution
  AFTER UPDATE OF profit_distribution_status ON public.projects
  FOR EACH ROW EXECUTE FUNCTION public.trg_snapshot_on_distribution();
