-- Migration 100: Fix RLS-Endlosrekursion zwischen rentals und rental_items
--
-- Migration 099 fuehrte eine Policy-Querverbindung ein:
--   - rentals_select prueft via EXISTS(rental_items + inventory_items)
--   - rental_items_select prueft via EXISTS(rentals)
-- Beide referenzieren sich gegenseitig → Postgres meldet
-- "infinite recursion detected in policy".
--
-- Loesung: SECURITY DEFINER-Helper, die RLS umgehen, in den Policies einsetzen.

CREATE OR REPLACE FUNCTION public.user_owns_item_in_rental(p_rental_id uuid)
RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  SELECT EXISTS (
    SELECT 1
    FROM public.rental_items ri
    JOIN public.inventory_items i ON i.id = ri.inventory_item_id
    WHERE ri.rental_id = p_rental_id
      AND i.owner_profile_id = auth.uid()
  );
$$;

GRANT EXECUTE ON FUNCTION public.user_owns_item_in_rental(uuid) TO authenticated;

CREATE OR REPLACE FUNCTION public.user_owns_rental_item(p_rental_item_id uuid)
RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  SELECT EXISTS (
    SELECT 1
    FROM public.rental_items ri
    JOIN public.inventory_items i ON i.id = ri.inventory_item_id
    WHERE ri.id = p_rental_item_id
      AND i.owner_profile_id = auth.uid()
  );
$$;

GRANT EXECUTE ON FUNCTION public.user_owns_rental_item(uuid) TO authenticated;

CREATE OR REPLACE FUNCTION public.user_is_rental_owner(p_rental_id uuid)
RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  SELECT EXISTS (
    SELECT 1 FROM public.rentals r
    WHERE r.id = p_rental_id
      AND (
        r.owner_profile_id = auth.uid()
        OR (r.owner_group_id IS NOT NULL AND public.is_group_member(r.owner_group_id))
      )
  );
$$;

GRANT EXECUTE ON FUNCTION public.user_is_rental_owner(uuid) TO authenticated;

-- ── rentals_select neu: nutzt SECURITY DEFINER-Helper statt EXISTS auf rental_items ──
DROP POLICY IF EXISTS rentals_select ON public.rentals;
CREATE POLICY rentals_select ON public.rentals
  FOR SELECT TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND public.is_group_member(owner_group_id))
    OR public.user_owns_item_in_rental(id)
  );

-- ── rental_items_select neu: nutzt Helper statt EXISTS auf rentals ──
DROP POLICY IF EXISTS rental_items_select ON public.rental_items;
CREATE POLICY rental_items_select ON public.rental_items
  FOR SELECT TO authenticated USING (
    public.user_is_rental_owner(rental_id)
    OR public.user_owns_rental_item(id)
  );

-- ── rental_items_update analog entkoppeln ──
DROP POLICY IF EXISTS rental_items_update ON public.rental_items;
CREATE POLICY rental_items_update ON public.rental_items
  FOR UPDATE TO authenticated USING (
    public.user_is_rental_owner(rental_id)
    OR public.user_owns_rental_item(id)
  );

DROP POLICY IF EXISTS rental_items_insert ON public.rental_items;
CREATE POLICY rental_items_insert ON public.rental_items
  FOR INSERT TO authenticated WITH CHECK (
    public.user_is_rental_owner(rental_id)
  );

DROP POLICY IF EXISTS rental_items_delete ON public.rental_items;
CREATE POLICY rental_items_delete ON public.rental_items
  FOR DELETE TO authenticated USING (
    public.user_is_rental_owner(rental_id)
  );
