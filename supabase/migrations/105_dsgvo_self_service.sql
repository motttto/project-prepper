-- Migration 105: DSGVO-Self-Service — Account-Loeschung + Daten-Export
--
-- Art. 17 DSGVO (Recht auf Loeschung): jeder User muss seinen eigenen
-- Account loeschen koennen — bisher ging das nur via Superadmin.
-- Art. 15/20 DSGVO (Auskunft / Datenuebertragbarkeit): User muss seine
-- Daten als strukturierten Export bekommen koennen.
--
-- Wir bauen zwei RPCs:
--   - delete_my_account()  → loescht eigenen User (auth.uid())
--   - export_my_data()     → returnt JSON mit allen User-bezogenen Daten

-- ════════════════════════════════════════════════════════════════════════════
-- delete_my_account: Self-Service-Loeschung
-- ════════════════════════════════════════════════════════════════════════════
--
-- Loescht alle eigenen Daten und den auth.users-Eintrag. CASCADE/SET NULL
-- aus Migration 104 sorgt fuer Konsistenz. RAISE EXCEPTION wenn der User
-- noch in einer Gruppe Founder ist (sonst waere die Gruppe verwaist).

CREATE OR REPLACE FUNCTION public.delete_my_account()
RETURNS json
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE
  v_uid uuid := auth.uid();
  v_founder_groups integer;
BEGIN
  IF v_uid IS NULL THEN
    RAISE EXCEPTION 'not authenticated';
  END IF;

  -- Founder-Gruppen pruefen
  SELECT count(*) INTO v_founder_groups
  FROM public.group_memberships
  WHERE profile_id = v_uid AND is_founder = true AND is_active = true;

  IF v_founder_groups > 0 THEN
    RAISE EXCEPTION 'Account-Loeschung blockiert: du bist Founder von % aktiven Gruppe(n). Uebertrage die Founder-Rolle oder loese die Gruppe(n) zuerst auf.', v_founder_groups;
  END IF;

  -- Eigene Daten loeschen (CASCADE-Tabellen werden mitgenommen)
  DELETE FROM public.inventory_items WHERE owner_profile_id = v_uid;
  DELETE FROM public.inventory_categories WHERE owner_profile_id = v_uid;
  DELETE FROM public.inquiries WHERE owner_profile_id = v_uid;
  DELETE FROM public.projects WHERE owner_profile_id = v_uid;
  DELETE FROM public.rentals WHERE owner_profile_id = v_uid;

  -- Memberships (CASCADE auf alles dependent)
  DELETE FROM public.group_memberships WHERE profile_id = v_uid;
  DELETE FROM public.org_memberships WHERE profile_id = v_uid;

  -- Endgueltig: auth.users → CASCADE auf profiles
  DELETE FROM auth.users WHERE id = v_uid;

  RETURN json_build_object('deleted', true, 'user_id', v_uid);
END;
$$;

REVOKE ALL ON FUNCTION public.delete_my_account() FROM public;
GRANT EXECUTE ON FUNCTION public.delete_my_account() TO authenticated;

-- ════════════════════════════════════════════════════════════════════════════
-- export_my_data: alle eigenen Daten als JSON
-- ════════════════════════════════════════════════════════════════════════════
--
-- Returnt ein JSON-Objekt mit allen Tabellen, die personenbezogene Daten
-- des aufrufenden Users enthalten — fuer DSGVO Art. 15 (Auskunft) und
-- Art. 20 (Datenuebertragbarkeit).

CREATE OR REPLACE FUNCTION public.export_my_data()
RETURNS json
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE
  v_uid uuid := auth.uid();
  v_result json;
BEGIN
  IF v_uid IS NULL THEN
    RAISE EXCEPTION 'not authenticated';
  END IF;

  SELECT json_build_object(
    'exported_at', now(),
    'user_id', v_uid,
    'profile', (SELECT row_to_json(p) FROM public.profiles p WHERE p.id = v_uid),
    'group_memberships', (SELECT COALESCE(json_agg(row_to_json(gm)), '[]'::json) FROM public.group_memberships gm WHERE gm.profile_id = v_uid),
    'org_memberships', (SELECT COALESCE(json_agg(row_to_json(om)), '[]'::json) FROM public.org_memberships om WHERE om.profile_id = v_uid),
    'inventory_items', (SELECT COALESCE(json_agg(row_to_json(i)), '[]'::json) FROM public.inventory_items i WHERE i.owner_profile_id = v_uid),
    'inquiries', (SELECT COALESCE(json_agg(row_to_json(inq)), '[]'::json) FROM public.inquiries inq WHERE inq.owner_profile_id = v_uid),
    'projects', (SELECT COALESCE(json_agg(row_to_json(prj)), '[]'::json) FROM public.projects prj WHERE prj.owner_profile_id = v_uid),
    'rentals', (SELECT COALESCE(json_agg(row_to_json(r)), '[]'::json) FROM public.rentals r WHERE r.owner_profile_id = v_uid),
    'inventory_group_shares', (SELECT COALESCE(json_agg(row_to_json(s)), '[]'::json) FROM public.inventory_group_shares s WHERE s.shared_by = v_uid),
    'project_members', (SELECT COALESCE(json_agg(row_to_json(pm)), '[]'::json) FROM public.project_members pm WHERE pm.profile_id = v_uid),
    'project_invitations', (SELECT COALESCE(json_agg(row_to_json(pi)), '[]'::json) FROM public.project_invitations pi WHERE pi.invited_profile_id = v_uid),
    'inquiry_invitations', (SELECT COALESCE(json_agg(row_to_json(ii)), '[]'::json) FROM public.inquiry_invitations ii WHERE ii.invited_profile_id = v_uid),
    'org_decision_votes', (SELECT COALESCE(json_agg(row_to_json(v)), '[]'::json) FROM public.org_decision_votes v WHERE v.voter_id = v_uid),
    'org_poll_votes', (SELECT COALESCE(json_agg(row_to_json(pv)), '[]'::json) FROM public.org_poll_votes pv WHERE pv.voter_id = v_uid)
  ) INTO v_result;

  RETURN v_result;
END;
$$;

REVOKE ALL ON FUNCTION public.export_my_data() FROM public;
GRANT EXECUTE ON FUNCTION public.export_my_data() TO authenticated;

COMMENT ON FUNCTION public.delete_my_account() IS 'DSGVO Art. 17: Self-Service-Loeschung des eigenen Accounts. Verweigert, wenn der User noch Founder einer aktiven Gruppe ist.';
COMMENT ON FUNCTION public.export_my_data() IS 'DSGVO Art. 15/20: Datenexport des eigenen Accounts als JSON.';
