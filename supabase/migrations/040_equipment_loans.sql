-- Migration 040: Equipment-Leihgaben (persönliche Ausleihe)
-- Wer hat was ausgeliehen, wann kommt es zurück, Zustandsprotokoll

CREATE TABLE IF NOT EXISTS public.equipment_loans (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  org_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE CASCADE,
  inventory_item_id uuid NOT NULL REFERENCES public.inventory_items(id) ON DELETE CASCADE,
  unit_id uuid REFERENCES public.inventory_units(id),  -- optional: konkretes Einzelstück
  quantity integer NOT NULL DEFAULT 1,

  -- Wer leiht / wer genehmigt
  borrower_id uuid NOT NULL REFERENCES public.profiles(id),
  approved_by uuid REFERENCES public.profiles(id),

  -- Zeitraum
  borrowed_at timestamptz NOT NULL DEFAULT now(),
  due_date date,                    -- geplante Rückgabe
  returned_at timestamptz,          -- tatsächliche Rückgabe

  -- Status
  status text NOT NULL DEFAULT 'active'
    CHECK (status IN ('requested', 'active', 'returned', 'overdue', 'lost')),

  -- Zustandsprotokoll
  condition_at_checkout text CHECK (condition_at_checkout IN ('new', 'good', 'fair', 'poor', 'broken')),
  condition_at_return text CHECK (condition_at_return IN ('new', 'good', 'fair', 'poor', 'broken')),

  -- Zweck / Notizen
  purpose text,
  notes text,
  return_notes text,

  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now()
);

-- Indizes
CREATE INDEX IF NOT EXISTS idx_equipment_loans_org ON public.equipment_loans(org_id);
CREATE INDEX IF NOT EXISTS idx_equipment_loans_item ON public.equipment_loans(inventory_item_id);
CREATE INDEX IF NOT EXISTS idx_equipment_loans_borrower ON public.equipment_loans(borrower_id);
CREATE INDEX IF NOT EXISTS idx_equipment_loans_status ON public.equipment_loans(status);
CREATE INDEX IF NOT EXISTS idx_equipment_loans_due ON public.equipment_loans(due_date) WHERE status IN ('active', 'overdue');

-- RLS
ALTER TABLE public.equipment_loans ENABLE ROW LEVEL SECURITY;

CREATE POLICY "loans_select" ON public.equipment_loans
  FOR SELECT TO authenticated USING (true);
CREATE POLICY "loans_insert" ON public.equipment_loans
  FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "loans_update" ON public.equipment_loans
  FOR UPDATE TO authenticated USING (true);
CREATE POLICY "loans_delete" ON public.equipment_loans
  FOR DELETE TO authenticated USING (true);

-- Trigger: updated_at automatisch setzen
CREATE OR REPLACE FUNCTION update_equipment_loan_timestamp()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_equipment_loan_updated
  BEFORE UPDATE ON public.equipment_loans
  FOR EACH ROW EXECUTE FUNCTION update_equipment_loan_timestamp();

-- Trigger: Status automatisch auf 'overdue' setzen wenn due_date überschritten
-- (wird per Cron oder beim Laden geprüft — hier als RPC)
CREATE OR REPLACE FUNCTION check_overdue_loans(p_org_id uuid)
RETURNS integer AS $$
DECLARE
  updated_count integer;
BEGIN
  UPDATE equipment_loans
  SET status = 'overdue'
  WHERE org_id = p_org_id
    AND status = 'active'
    AND due_date < CURRENT_DATE
    AND returned_at IS NULL;
  GET DIAGNOSTICS updated_count = ROW_COUNT;
  RETURN updated_count;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- RPC: Leihgaben-Übersicht pro Mitglied
CREATE OR REPLACE FUNCTION get_member_loans_summary(p_org_id uuid)
RETURNS TABLE(
  borrower_id uuid,
  borrower_name text,
  active_loans bigint,
  overdue_loans bigint,
  total_items_out bigint
) AS $$
BEGIN
  RETURN QUERY
  SELECT
    el.borrower_id,
    COALESCE(p.name, 'Unbekannt')::text as borrower_name,
    COUNT(*) FILTER (WHERE el.status = 'active') as active_loans,
    COUNT(*) FILTER (WHERE el.status = 'overdue') as overdue_loans,
    COALESCE(SUM(CASE WHEN el.status IN ('active', 'overdue') THEN el.quantity ELSE 0 END), 0)::bigint as total_items_out
  FROM equipment_loans el
  JOIN profiles p ON p.id = el.borrower_id
  WHERE el.org_id = p_org_id
    AND el.status IN ('active', 'overdue')
  GROUP BY el.borrower_id, p.name
  ORDER BY overdue_loans DESC, active_loans DESC;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Realtime
ALTER PUBLICATION supabase_realtime ADD TABLE public.equipment_loans;
