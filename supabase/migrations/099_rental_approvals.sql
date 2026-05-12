-- Migration 099: Verleih-Freigaben pro Item
--
-- Konzept: Owner eines Items bestimmt pro Item, wie eine Ausleihe durch andere
-- (z.B. Gruppen-Mitglied verleiht in einer Gruppe, in der das Item geteilt ist)
-- ablaeuft.
--
-- Modi auf inventory_items.loan_approval_mode:
--   - 'open'    Frei zur Verfuegung, kein Approval, keine Info
--   - 'notify'  Laeuft auto, Owner wird informiert (Status approved, mit Info-Flag)
--   - 'manual'  Owner muss explizit zustimmen (Status pending bis Approval)
--
-- Auf rental_items: approval_status pro Zeile + audit-Felder. Wer eigenes
-- Equipment in eigene Verleihe packt, hat immer 'auto'.

-- ════════════════════════════════════════════════════════════════════════════
-- 1. inventory_items: loan_approval_mode
-- ════════════════════════════════════════════════════════════════════════════

ALTER TABLE public.inventory_items
  ADD COLUMN IF NOT EXISTS loan_approval_mode text NOT NULL DEFAULT 'manual'
    CHECK (loan_approval_mode IN ('open', 'notify', 'manual'));

COMMENT ON COLUMN public.inventory_items.loan_approval_mode IS
  'Wie wird die Ausleihe durch andere User (ueber Gruppen-Sharing) behandelt: open=frei, notify=info, manual=Freigabe erforderlich.';

-- ════════════════════════════════════════════════════════════════════════════
-- 2. rental_items: approval-Felder
-- ════════════════════════════════════════════════════════════════════════════

ALTER TABLE public.rental_items
  ADD COLUMN IF NOT EXISTS approval_status text NOT NULL DEFAULT 'auto'
    CHECK (approval_status IN ('auto', 'pending', 'approved', 'rejected')),
  ADD COLUMN IF NOT EXISTS approved_by uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS approved_at timestamptz,
  ADD COLUMN IF NOT EXISTS rejection_reason text;

CREATE INDEX IF NOT EXISTS idx_rental_items_approval_status
  ON public.rental_items(approval_status);

-- ════════════════════════════════════════════════════════════════════════════
-- 3. Trigger: approval_status beim Insert anhand des Item-Modus initialisieren
-- ════════════════════════════════════════════════════════════════════════════
--
-- Logik:
--   - Item gehoert dem aktuellen User                       → auto
--   - Item-Modus open                                       → auto
--   - Item-Modus notify                                     → approved (mit Info)
--   - Item-Modus manual                                     → pending
--
-- Hinweis: "aktueller User" = auth.uid(). Bei serverseitigem Insert ohne Auth
-- (z.B. Edge Function mit Service Role) fallen wir auf den Item-Modus zurueck.

CREATE OR REPLACE FUNCTION public.rental_items_init_approval()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE
  v_owner uuid;
  v_mode text;
  v_uid uuid := auth.uid();
BEGIN
  -- Bei Update den Status nicht ueberschreiben (das macht die App ueber explizite
  -- approve/reject-Funktionen). Trigger greift nur bei INSERT.
  IF TG_OP <> 'INSERT' THEN
    RETURN NEW;
  END IF;

  SELECT owner_profile_id, loan_approval_mode
    INTO v_owner, v_mode
  FROM public.inventory_items
  WHERE id = NEW.inventory_item_id;

  -- Eigenes Item → kein Approval noetig
  IF v_owner IS NOT NULL AND v_uid IS NOT NULL AND v_owner = v_uid THEN
    NEW.approval_status := 'auto';
    RETURN NEW;
  END IF;

  -- Group-Owned Item (kein Solo-Owner) → kein Approval pro Item noetig
  IF v_owner IS NULL THEN
    NEW.approval_status := 'auto';
    RETURN NEW;
  END IF;

  -- Solo-Owned Item, anderer User leiht aus → nach Modus
  CASE COALESCE(v_mode, 'manual')
    WHEN 'open'    THEN NEW.approval_status := 'auto';
    WHEN 'notify'  THEN NEW.approval_status := 'approved';
                        NEW.approved_at := now();
    WHEN 'manual'  THEN NEW.approval_status := 'pending';
    ELSE                NEW.approval_status := 'pending';
  END CASE;

  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_rental_items_init_approval ON public.rental_items;
CREATE TRIGGER trg_rental_items_init_approval
  BEFORE INSERT ON public.rental_items
  FOR EACH ROW EXECUTE FUNCTION public.rental_items_init_approval();

-- ════════════════════════════════════════════════════════════════════════════
-- 4. RLS-Erweiterung: Item-Owner darf seine rental_items sehen/updaten
-- ════════════════════════════════════════════════════════════════════════════
--
-- Bisher: Sicht/Edit nur via parent rental (Owner des Verleihs). Jetzt zusaetzlich:
-- Wer Item-Owner ist (auch wenn er den Verleih nicht angelegt hat), sieht die
-- Zeile und kann approval_status aendern (Approve/Reject).

DROP POLICY IF EXISTS rental_items_select ON public.rental_items;
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
    OR EXISTS (
      SELECT 1 FROM public.inventory_items i
      WHERE i.id = inventory_item_id
        AND i.owner_profile_id = auth.uid()
    )
  );

DROP POLICY IF EXISTS rental_items_update ON public.rental_items;
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
    OR EXISTS (
      SELECT 1 FROM public.inventory_items i
      WHERE i.id = inventory_item_id
        AND i.owner_profile_id = auth.uid()
    )
  );

-- Auch fuer rentals (Header) erlauben wir SELECT, wenn man Owner eines Items
-- in einem rental_item ist — sonst kann man die Verleih-Details (Borrower,
-- Zeitraum, etc.) nicht sehen.
DROP POLICY IF EXISTS rentals_select ON public.rentals;
CREATE POLICY rentals_select ON public.rentals
  FOR SELECT TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND public.is_group_member(owner_group_id))
    OR EXISTS (
      SELECT 1 FROM public.rental_items ri
      JOIN public.inventory_items i ON i.id = ri.inventory_item_id
      WHERE ri.rental_id = rentals.id
        AND i.owner_profile_id = auth.uid()
    )
  );

-- ════════════════════════════════════════════════════════════════════════════
-- 5. RPC: approve/reject pro rental_item
-- ════════════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION public.approve_rental_item(
  p_rental_item_id uuid
) RETURNS public.rental_items
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE
  v_uid uuid := auth.uid();
  v_owner uuid;
  v_row public.rental_items;
BEGIN
  IF v_uid IS NULL THEN
    RAISE EXCEPTION 'not authenticated';
  END IF;

  SELECT i.owner_profile_id
    INTO v_owner
  FROM public.rental_items ri
  JOIN public.inventory_items i ON i.id = ri.inventory_item_id
  WHERE ri.id = p_rental_item_id;

  IF v_owner IS NULL OR v_owner <> v_uid THEN
    RAISE EXCEPTION 'only item owner can approve';
  END IF;

  UPDATE public.rental_items
     SET approval_status = 'approved',
         approved_by     = v_uid,
         approved_at     = now(),
         rejection_reason = NULL
   WHERE id = p_rental_item_id
   RETURNING * INTO v_row;

  RETURN v_row;
END;
$$;

CREATE OR REPLACE FUNCTION public.reject_rental_item(
  p_rental_item_id uuid,
  p_reason text DEFAULT NULL
) RETURNS public.rental_items
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE
  v_uid uuid := auth.uid();
  v_owner uuid;
  v_row public.rental_items;
BEGIN
  IF v_uid IS NULL THEN
    RAISE EXCEPTION 'not authenticated';
  END IF;

  SELECT i.owner_profile_id
    INTO v_owner
  FROM public.rental_items ri
  JOIN public.inventory_items i ON i.id = ri.inventory_item_id
  WHERE ri.id = p_rental_item_id;

  IF v_owner IS NULL OR v_owner <> v_uid THEN
    RAISE EXCEPTION 'only item owner can reject';
  END IF;

  UPDATE public.rental_items
     SET approval_status = 'rejected',
         approved_by     = v_uid,
         approved_at     = now(),
         rejection_reason = p_reason
   WHERE id = p_rental_item_id
   RETURNING * INTO v_row;

  RETURN v_row;
END;
$$;

GRANT EXECUTE ON FUNCTION public.approve_rental_item(uuid) TO authenticated;
GRANT EXECUTE ON FUNCTION public.reject_rental_item(uuid, text) TO authenticated;

-- ════════════════════════════════════════════════════════════════════════════
-- 6. check_inventory_availability: rejected items als "frei" zaehlen
-- ════════════════════════════════════════════════════════════════════════════

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
      AND ri.approval_status != 'rejected'
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

COMMENT ON COLUMN public.rental_items.approval_status IS
  'auto: kein Approval noetig (eigenes Item oder Modus open). approved: freigegeben (notify=auto-info, manual=durch Owner). pending: wartet auf Owner. rejected: vom Owner abgelehnt — zaehlt nicht in Verfuegbarkeit.';
