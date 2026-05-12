-- Migration 098: Verleih (Rentals)
--
-- Externer Equipment-Verleih, eigenstaendig (nicht projekt-gebunden).
-- Header: rentals       — Leiher (extern oder intern), Zeitraum, Kaution, Status
-- Lines:  rental_items  — mehrere Geraete + Mengen pro Verleih
-- RPC:    check_inventory_availability — prueft gegen bookings + rentals

-- ════════════════════════════════════════════════════════════════════════════
-- 1. Tabelle rentals (Header)
-- ════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS public.rentals (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),

  -- Ownership: User XOR Group (analog inquiries/projects)
  owner_profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE,
  owner_group_id uuid REFERENCES public.groups(id) ON DELETE CASCADE,

  -- Externer Leiher (Pflicht: borrower_name)
  borrower_name text NOT NULL,
  borrower_email text,
  borrower_phone text,
  borrower_address text,
  -- Optional: Verknuepfung zu internem User (falls Leiher in der App existiert)
  borrower_profile_id uuid REFERENCES public.profiles(id) ON DELETE SET NULL,

  -- Zeitraum
  date_from date NOT NULL,
  date_to date NOT NULL,

  -- Geld
  deposit_amount numeric(10,2) NOT NULL DEFAULT 0,
  rental_fee numeric(10,2),

  -- Status
  status text NOT NULL DEFAULT 'reserved'
    CHECK (status IN ('reserved','active','returned','cancelled')),

  notes text,

  created_by uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT rentals_owner_xor CHECK (
    (owner_profile_id IS NOT NULL AND owner_group_id IS NULL) OR
    (owner_profile_id IS NULL AND owner_group_id IS NOT NULL)
  ),
  CONSTRAINT rentals_dates_check CHECK (date_to >= date_from)
);

CREATE INDEX IF NOT EXISTS idx_rentals_owner_profile
  ON public.rentals(owner_profile_id) WHERE owner_profile_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_rentals_owner_group
  ON public.rentals(owner_group_id) WHERE owner_group_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_rentals_dates
  ON public.rentals(date_from, date_to);
CREATE INDEX IF NOT EXISTS idx_rentals_status
  ON public.rentals(status);

-- ════════════════════════════════════════════════════════════════════════════
-- 2. Tabelle rental_items (Lines)
-- ════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS public.rental_items (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  rental_id uuid NOT NULL REFERENCES public.rentals(id) ON DELETE CASCADE,
  inventory_item_id uuid NOT NULL REFERENCES public.inventory_items(id) ON DELETE RESTRICT,
  unit_id uuid REFERENCES public.inventory_units(id) ON DELETE SET NULL,
  quantity integer NOT NULL DEFAULT 1 CHECK (quantity > 0),
  notes text,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_rental_items_rental
  ON public.rental_items(rental_id);
CREATE INDEX IF NOT EXISTS idx_rental_items_item
  ON public.rental_items(inventory_item_id);

-- ════════════════════════════════════════════════════════════════════════════
-- 3. updated_at Trigger
-- ════════════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION public.rentals_set_updated_at()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_rentals_updated_at ON public.rentals;
CREATE TRIGGER trg_rentals_updated_at
  BEFORE UPDATE ON public.rentals
  FOR EACH ROW EXECUTE FUNCTION public.rentals_set_updated_at();

-- ════════════════════════════════════════════════════════════════════════════
-- 4. RLS-Policies
-- ════════════════════════════════════════════════════════════════════════════

ALTER TABLE public.rentals ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.rental_items ENABLE ROW LEVEL SECURITY;

-- rentals: owner (User oder Group-Mitglied) sieht/aendert
CREATE POLICY rentals_select ON public.rentals
  FOR SELECT TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND public.is_group_member(owner_group_id))
  );

CREATE POLICY rentals_insert ON public.rentals
  FOR INSERT TO authenticated WITH CHECK (
    (owner_profile_id = auth.uid() AND owner_group_id IS NULL)
    OR (owner_group_id IS NOT NULL AND public.is_group_member(owner_group_id) AND owner_profile_id IS NULL)
  );

CREATE POLICY rentals_update ON public.rentals
  FOR UPDATE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND public.is_group_member(owner_group_id))
  );

CREATE POLICY rentals_delete ON public.rentals
  FOR DELETE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND public.is_group_member(owner_group_id))
  );

-- rental_items: ueber Parent rental authorisiert
CREATE POLICY rental_items_select ON public.rental_items
  FOR SELECT TO authenticated USING (
    EXISTS (
      SELECT 1 FROM public.rentals r
      WHERE r.id = rental_id
        AND (
          r.owner_profile_id = auth.uid()
          OR (r.owner_group_id IS NOT NULL AND public.is_group_member(r.owner_group_id))
        )
    )
  );

CREATE POLICY rental_items_insert ON public.rental_items
  FOR INSERT TO authenticated WITH CHECK (
    EXISTS (
      SELECT 1 FROM public.rentals r
      WHERE r.id = rental_id
        AND (
          r.owner_profile_id = auth.uid()
          OR (r.owner_group_id IS NOT NULL AND public.is_group_member(r.owner_group_id))
        )
    )
  );

CREATE POLICY rental_items_update ON public.rental_items
  FOR UPDATE TO authenticated USING (
    EXISTS (
      SELECT 1 FROM public.rentals r
      WHERE r.id = rental_id
        AND (
          r.owner_profile_id = auth.uid()
          OR (r.owner_group_id IS NOT NULL AND public.is_group_member(r.owner_group_id))
        )
    )
  );

CREATE POLICY rental_items_delete ON public.rental_items
  FOR DELETE TO authenticated USING (
    EXISTS (
      SELECT 1 FROM public.rentals r
      WHERE r.id = rental_id
        AND (
          r.owner_profile_id = auth.uid()
          OR (r.owner_group_id IS NOT NULL AND public.is_group_member(r.owner_group_id))
        )
    )
  );

-- ════════════════════════════════════════════════════════════════════════════
-- 5. Realtime aktivieren
-- ════════════════════════════════════════════════════════════════════════════

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_publication_tables
    WHERE pubname = 'supabase_realtime' AND tablename = 'rentals'
  ) THEN
    ALTER PUBLICATION supabase_realtime ADD TABLE public.rentals;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM pg_publication_tables
    WHERE pubname = 'supabase_realtime' AND tablename = 'rental_items'
  ) THEN
    ALTER PUBLICATION supabase_realtime ADD TABLE public.rental_items;
  END IF;
END $$;

-- ════════════════════════════════════════════════════════════════════════════
-- 6. RPC: Verfuegbarkeitspruefung
-- ════════════════════════════════════════════════════════════════════════════
--
-- Prueft fuer ein Inventar-Item, wie viele Stueck im Zeitraum bereits
-- gebucht (Projekte) oder verliehen (Rentals) sind.
-- Returns:
--   total     — Gesamtbestand des Items
--   reserved  — Bereits belegt im Zeitraum
--   available — Verfuegbar (total - reserved)
--
-- Mit p_exclude_rental_id / p_exclude_booking_id kann eine bestehende
-- Buchung beim Update ignoriert werden (sonst wuerde sie sich selbst
-- als Konflikt zaehlen).

CREATE OR REPLACE FUNCTION public.check_inventory_availability(
  p_item_id uuid,
  p_date_from date,
  p_date_to date,
  p_exclude_rental_id uuid DEFAULT NULL,
  p_exclude_booking_id uuid DEFAULT NULL
)
RETURNS TABLE(total integer, reserved integer, available integer)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  WITH item AS (
    SELECT quantity FROM public.inventory_items WHERE id = p_item_id
  ),
  bookings_qty AS (
    SELECT COALESCE(SUM(b.quantity), 0)::int AS qty
    FROM public.bookings b
    WHERE b.inventory_item_id = p_item_id
      AND b.approval_status != 'rejected'
      AND b.status != 'returned'
      AND b.date_from <= p_date_to
      AND b.date_to   >= p_date_from
      AND (p_exclude_booking_id IS NULL OR b.id != p_exclude_booking_id)
  ),
  rentals_qty AS (
    SELECT COALESCE(SUM(ri.quantity), 0)::int AS qty
    FROM public.rental_items ri
    JOIN public.rentals r ON r.id = ri.rental_id
    WHERE ri.inventory_item_id = p_item_id
      AND r.status NOT IN ('returned','cancelled')
      AND r.date_from <= p_date_to
      AND r.date_to   >= p_date_from
      AND (p_exclude_rental_id IS NULL OR r.id != p_exclude_rental_id)
  )
  SELECT
    item.quantity::int                                                AS total,
    (bookings_qty.qty + rentals_qty.qty)::int                         AS reserved,
    (item.quantity - bookings_qty.qty - rentals_qty.qty)::int         AS available
  FROM item, bookings_qty, rentals_qty;
$$;

GRANT EXECUTE ON FUNCTION public.check_inventory_availability(uuid, date, date, uuid, uuid) TO authenticated;

COMMENT ON TABLE public.rentals IS
  'Externer Equipment-Verleih (nicht projekt-gebunden). Header pro Leiher mit Zeitraum, Kaution und Status.';
COMMENT ON TABLE public.rental_items IS
  'Geraete pro Verleih (mehrere Items + Mengen).';
COMMENT ON FUNCTION public.check_inventory_availability(uuid, date, date, uuid, uuid) IS
  'Prueft Verfuegbarkeit eines Inventar-Items im Zeitraum. Beruecksichtigt sowohl projekt-Bookings als auch Rentals.';
