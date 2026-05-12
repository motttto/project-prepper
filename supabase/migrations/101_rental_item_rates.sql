-- Migration 101: Tagessatz-Verhandlung pro rental_item
--
-- Beim Verleih eines fremden Items (z.B. Solo-User-Item in Gruppen-Verleih)
-- gibt es einen "Vergleich" zwischen Verleiher und Owner:
--   - proposed_rate: vom Verleiher vorgeschlagener Tagessatz (Default:
--     inventory_group_shares.daily_rate, sonst cost_per_day des Items)
--   - agreed_rate:   vom Owner bestaetigter Tagessatz. Kann == proposed_rate
--                    sein, kann ueberschrieben werden, oder 0 (= Verzicht).
--
-- agreed_rate ist NULL bis der Owner approved. Bei eigenen Items
-- (approval_status='auto') und bei Modus 'open'/'notify' wird agreed_rate
-- automatisch gleich proposed_rate gesetzt.

ALTER TABLE public.rental_items
  ADD COLUMN IF NOT EXISTS proposed_rate numeric(10,2),
  ADD COLUMN IF NOT EXISTS agreed_rate numeric(10,2);

COMMENT ON COLUMN public.rental_items.proposed_rate IS
  'Vom Verleiher vorgeschlagener Tagessatz fuer dieses Item (Default aus inventory_group_shares oder cost_per_day).';
COMMENT ON COLUMN public.rental_items.agreed_rate IS
  'Vom Owner bestaetigter Tagessatz. 0 = Verzicht. NULL = noch nicht vereinbart.';

-- ════════════════════════════════════════════════════════════════════════════
-- Trigger erweitern: setzt proposed_rate + agreed_rate beim Insert
-- ════════════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION public.rental_items_init_approval()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE
  v_owner uuid;
  v_mode  text;
  v_uid   uuid := auth.uid();
  v_group uuid;
  v_rate  numeric(10,2);
BEGIN
  IF TG_OP <> 'INSERT' THEN
    RETURN NEW;
  END IF;

  SELECT owner_profile_id, loan_approval_mode
    INTO v_owner, v_mode
  FROM public.inventory_items
  WHERE id = NEW.inventory_item_id;

  -- approval_status setzen
  IF v_owner IS NOT NULL AND v_uid IS NOT NULL AND v_owner = v_uid THEN
    -- Eigenes Item
    NEW.approval_status := 'auto';
  ELSIF v_owner IS NULL THEN
    -- Group-Owned Item → kein Approval pro Item noetig
    NEW.approval_status := 'auto';
  ELSE
    -- Solo-Owned Item, anderer User leiht aus
    CASE COALESCE(v_mode, 'manual')
      WHEN 'open'    THEN NEW.approval_status := 'auto';
      WHEN 'notify'  THEN NEW.approval_status := 'approved';
                          NEW.approved_at := now();
      WHEN 'manual'  THEN NEW.approval_status := 'pending';
      ELSE                NEW.approval_status := 'pending';
    END CASE;
  END IF;

  -- proposed_rate initialisieren (sofern nicht explizit gesetzt)
  IF NEW.proposed_rate IS NULL THEN
    SELECT r.owner_group_id INTO v_group FROM public.rentals r WHERE r.id = NEW.rental_id;

    IF v_group IS NOT NULL THEN
      SELECT s.daily_rate INTO v_rate
      FROM public.inventory_group_shares s
      WHERE s.inventory_item_id = NEW.inventory_item_id
        AND s.group_id = v_group
        AND s.revoked_at IS NULL
      LIMIT 1;
    END IF;

    IF v_rate IS NULL THEN
      SELECT i.cost_per_day INTO v_rate
      FROM public.inventory_items i
      WHERE i.id = NEW.inventory_item_id;
    END IF;

    NEW.proposed_rate := COALESCE(v_rate, 0);
  END IF;

  -- agreed_rate bei auto/approved automatisch = proposed_rate
  IF NEW.approval_status IN ('auto', 'approved') AND NEW.agreed_rate IS NULL THEN
    NEW.agreed_rate := NEW.proposed_rate;
  END IF;

  RETURN NEW;
END;
$$;

-- Trigger bleibt unveraendert (BEFORE INSERT auf rental_items)

-- ════════════════════════════════════════════════════════════════════════════
-- approve_rental_item: optional p_agreed_rate akzeptieren
-- ════════════════════════════════════════════════════════════════════════════
-- Drop alte Signatur, dann neue anlegen
DROP FUNCTION IF EXISTS public.approve_rental_item(uuid);

CREATE OR REPLACE FUNCTION public.approve_rental_item(
  p_rental_item_id uuid,
  p_agreed_rate numeric DEFAULT NULL
) RETURNS public.rental_items
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE
  v_uid uuid := auth.uid();
  v_owner uuid;
  v_proposed numeric(10,2);
  v_final numeric(10,2);
  v_row public.rental_items;
BEGIN
  IF v_uid IS NULL THEN
    RAISE EXCEPTION 'not authenticated';
  END IF;

  SELECT i.owner_profile_id, ri.proposed_rate
    INTO v_owner, v_proposed
  FROM public.rental_items ri
  JOIN public.inventory_items i ON i.id = ri.inventory_item_id
  WHERE ri.id = p_rental_item_id;

  IF v_owner IS NULL OR v_owner <> v_uid THEN
    RAISE EXCEPTION 'only item owner can approve';
  END IF;

  v_final := COALESCE(p_agreed_rate, v_proposed, 0);
  IF v_final < 0 THEN v_final := 0; END IF;

  UPDATE public.rental_items
     SET approval_status = 'approved',
         approved_by     = v_uid,
         approved_at     = now(),
         rejection_reason = NULL,
         agreed_rate     = v_final
   WHERE id = p_rental_item_id
   RETURNING * INTO v_row;

  RETURN v_row;
END;
$$;

GRANT EXECUTE ON FUNCTION public.approve_rental_item(uuid, numeric) TO authenticated;
