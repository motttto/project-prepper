-- Migration 041: Org-Partnerschaften (bilaterale Struktur)
-- Dauerhafte Beziehungen zwischen Organisationen + Equipment-Sharing

-- Org-zu-Org Partnerschaften
CREATE TABLE IF NOT EXISTS public.org_partnerships (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  org_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE CASCADE,
  partner_org_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE CASCADE,
  status text NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending', 'active', 'paused', 'ended')),
  -- Berechtigungen
  share_inventory boolean DEFAULT false,       -- Inventar gegenseitig sichtbar
  share_team_contacts boolean DEFAULT false,   -- Team-Kontakte teilen
  allow_equipment_requests boolean DEFAULT true, -- Equipment-Anfragen erlauben
  -- Meta
  invited_by uuid REFERENCES public.profiles(id),
  accepted_by uuid REFERENCES public.profiles(id),
  notes text,
  started_at timestamptz,
  ended_at timestamptz,
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now(),
  UNIQUE(org_id, partner_org_id),
  CHECK (org_id != partner_org_id)
);

-- Indizes
CREATE INDEX IF NOT EXISTS idx_org_partnerships_org ON public.org_partnerships(org_id);
CREATE INDEX IF NOT EXISTS idx_org_partnerships_partner ON public.org_partnerships(partner_org_id);
CREATE INDEX IF NOT EXISTS idx_org_partnerships_status ON public.org_partnerships(status);

-- Equipment-Sharing: Artikel als teilbar markieren
ALTER TABLE public.inventory_items
  ADD COLUMN IF NOT EXISTS is_shareable boolean DEFAULT false,
  ADD COLUMN IF NOT EXISTS sharing_notes text;

-- Equipment-Anfragen zwischen Orgs (ohne Projektbezug)
CREATE TABLE IF NOT EXISTS public.equipment_requests (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  requesting_org_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE CASCADE,
  supplying_org_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE CASCADE,
  inventory_item_id uuid NOT NULL REFERENCES public.inventory_items(id) ON DELETE CASCADE,
  quantity integer NOT NULL DEFAULT 1,
  -- Zeitraum
  date_from date NOT NULL,
  date_to date NOT NULL,
  -- Status
  status text NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled', 'returned')),
  -- Details
  purpose text,
  rejection_reason text,
  requested_by uuid NOT NULL REFERENCES public.profiles(id),
  responded_by uuid REFERENCES public.profiles(id),
  responded_at timestamptz,
  -- Zustand
  condition_at_pickup text CHECK (condition_at_pickup IN ('new', 'good', 'fair', 'poor', 'broken')),
  condition_at_return text CHECK (condition_at_return IN ('new', 'good', 'fair', 'poor', 'broken')),
  return_notes text,
  returned_at timestamptz,
  created_at timestamptz DEFAULT now()
);

-- Indizes
CREATE INDEX IF NOT EXISTS idx_equip_req_requesting ON public.equipment_requests(requesting_org_id);
CREATE INDEX IF NOT EXISTS idx_equip_req_supplying ON public.equipment_requests(supplying_org_id);
CREATE INDEX IF NOT EXISTS idx_equip_req_item ON public.equipment_requests(inventory_item_id);
CREATE INDEX IF NOT EXISTS idx_equip_req_status ON public.equipment_requests(status);

-- RLS
ALTER TABLE public.org_partnerships ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.equipment_requests ENABLE ROW LEVEL SECURITY;

CREATE POLICY "partnerships_select" ON public.org_partnerships
  FOR SELECT TO authenticated USING (true);
CREATE POLICY "partnerships_insert" ON public.org_partnerships
  FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "partnerships_update" ON public.org_partnerships
  FOR UPDATE TO authenticated USING (true);
CREATE POLICY "partnerships_delete" ON public.org_partnerships
  FOR DELETE TO authenticated USING (true);

CREATE POLICY "equip_req_select" ON public.equipment_requests
  FOR SELECT TO authenticated USING (true);
CREATE POLICY "equip_req_insert" ON public.equipment_requests
  FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "equip_req_update" ON public.equipment_requests
  FOR UPDATE TO authenticated USING (true);
CREATE POLICY "equip_req_delete" ON public.equipment_requests
  FOR DELETE TO authenticated USING (true);

-- Trigger: updated_at für Partnerschaften
CREATE OR REPLACE FUNCTION update_org_partnership_timestamp()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_org_partnership_updated
  BEFORE UPDATE ON public.org_partnerships
  FOR EACH ROW EXECUTE FUNCTION update_org_partnership_timestamp();

-- RPC: Partner-Inventar laden (nur teilbare Artikel von aktiven Partnern)
CREATE OR REPLACE FUNCTION get_partner_inventory(p_org_id uuid)
RETURNS TABLE(
  item_id uuid,
  item_name text,
  inventory_number text,
  category text,
  quantity integer,
  condition text,
  cost_per_day numeric,
  image_url text,
  sharing_notes text,
  partner_org_id uuid,
  partner_org_name text
) AS $$
BEGIN
  RETURN QUERY
  SELECT
    ii.id as item_id,
    ii.name::text as item_name,
    ii.inventory_number::text,
    ii.category::text,
    ii.quantity,
    ii.condition::text,
    ii.cost_per_day,
    ii.image_url::text,
    ii.sharing_notes::text,
    o.id as partner_org_id,
    o.name::text as partner_org_name
  FROM inventory_items ii
  JOIN organizations o ON o.id = ii.org_id
  JOIN org_partnerships op ON (
    (op.org_id = p_org_id AND op.partner_org_id = ii.org_id)
    OR (op.partner_org_id = p_org_id AND op.org_id = ii.org_id)
  )
  WHERE op.status = 'active'
    AND op.share_inventory = true
    AND ii.is_shareable = true
  ORDER BY o.name, ii.category, ii.name;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- RPC: Partnership-Statistiken
CREATE OR REPLACE FUNCTION get_partnership_stats(p_org_id uuid)
RETURNS TABLE(
  partner_org_id uuid,
  partner_org_name text,
  shared_projects bigint,
  active_equipment_requests bigint,
  total_equipment_requests bigint
) AS $$
BEGIN
  RETURN QUERY
  SELECT
    CASE
      WHEN op.org_id = p_org_id THEN op.partner_org_id
      ELSE op.org_id
    END as partner_org_id,
    o.name::text as partner_org_name,
    (SELECT COUNT(DISTINCT po.project_id)
     FROM project_orgs po
     WHERE po.org_id IN (op.org_id, op.partner_org_id)
       AND po.status = 'accepted'
    ) as shared_projects,
    (SELECT COUNT(*)
     FROM equipment_requests er
     WHERE er.status IN ('pending', 'approved')
       AND (
         (er.requesting_org_id = p_org_id AND er.supplying_org_id = CASE WHEN op.org_id = p_org_id THEN op.partner_org_id ELSE op.org_id END)
         OR (er.supplying_org_id = p_org_id AND er.requesting_org_id = CASE WHEN op.org_id = p_org_id THEN op.partner_org_id ELSE op.org_id END)
       )
    ) as active_equipment_requests,
    (SELECT COUNT(*)
     FROM equipment_requests er
     WHERE (er.requesting_org_id = p_org_id AND er.supplying_org_id = CASE WHEN op.org_id = p_org_id THEN op.partner_org_id ELSE op.org_id END)
       OR (er.supplying_org_id = p_org_id AND er.requesting_org_id = CASE WHEN op.org_id = p_org_id THEN op.partner_org_id ELSE op.org_id END)
    ) as total_equipment_requests
  FROM org_partnerships op
  JOIN organizations o ON o.id = CASE WHEN op.org_id = p_org_id THEN op.partner_org_id ELSE op.org_id END
  WHERE (op.org_id = p_org_id OR op.partner_org_id = p_org_id)
    AND op.status = 'active';
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Realtime
ALTER PUBLICATION supabase_realtime ADD TABLE public.org_partnerships;
ALTER PUBLICATION supabase_realtime ADD TABLE public.equipment_requests;
