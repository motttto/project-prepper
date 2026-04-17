-- Migration 078: delete_user_completely v2
-- ============================================================
-- Cleanup-Reihenfolge fuer Hard-Delete eines Users:
-- 1) Owned Content explizit loeschen (FKs sind oft NO ACTION)
-- 2) Referenzen auf den User auf NULL setzen (wo nullable)
-- 3) Endlich auth.users loeschen (cascadet profiles + Reste)

CREATE OR REPLACE FUNCTION public.delete_user_completely(p_user_id uuid)
RETURNS json AS $$
DECLARE
  v_caller_is_superadmin boolean;
  v_target_email text;
  v_target_is_superadmin boolean;
BEGIN
  -- Auth-Checks
  SELECT is_system INTO v_caller_is_superadmin
  FROM public.profiles WHERE id = auth.uid();

  IF NOT COALESCE(v_caller_is_superadmin, false) THEN
    RAISE EXCEPTION 'Nur Superadmins koennen User loeschen';
  END IF;

  IF p_user_id = auth.uid() THEN
    RAISE EXCEPTION 'Du kannst dich nicht selbst loeschen';
  END IF;

  SELECT email, is_system INTO v_target_email, v_target_is_superadmin
  FROM public.profiles WHERE id = p_user_id;

  IF NOT FOUND THEN
    RAISE EXCEPTION 'User nicht gefunden';
  END IF;

  IF v_target_is_superadmin THEN
    RAISE EXCEPTION 'Andere Superadmins koennen nicht ueber diese Funktion geloescht werden';
  END IF;

  -- ─── 1) Owned Content loeschen (CASCADE-Eltern zuerst) ───

  -- Cooperation Agreements & abhaengige (NOT NULL FKs)
  DELETE FROM public.cooperation_agreements WHERE created_by = p_user_id;
  DELETE FROM public.agreement_inventory_contributions WHERE contributor_id = p_user_id;
  DELETE FROM public.agreement_roles WHERE profile_id = p_user_id;
  DELETE FROM public.agreement_signatures WHERE profile_id = p_user_id;

  -- Inventar (haengen Bookings, Categories, Units, Shares dran via CASCADE)
  DELETE FROM public.inventory_items WHERE owner_profile_id = p_user_id;
  DELETE FROM public.inventory_group_shares WHERE shared_by = p_user_id;
  DELETE FROM public.inventory_project_grants WHERE granted_by = p_user_id;

  -- Anfragen
  DELETE FROM public.inquiries WHERE owner_profile_id = p_user_id;
  DELETE FROM public.inquiry_invitations WHERE invited_by = p_user_id OR invited_profile_id = p_user_id;

  -- Projekte (CASCADE: schedule, members, tasks, costs, etc.)
  DELETE FROM public.projects WHERE owner_profile_id = p_user_id;
  DELETE FROM public.project_invitations WHERE invited_by = p_user_id OR invited_profile_id = p_user_id;
  DELETE FROM public.project_profit_shares WHERE profile_id = p_user_id;

  -- Gruppen, die er gegruendet hat (CASCADE: memberships, invitations, polls, decisions, calendar_groups)
  DELETE FROM public.groups WHERE founded_by = p_user_id;
  DELETE FROM public.group_invitations WHERE invited_by = p_user_id;

  -- Org-Welt (legacy, falls noch Daten da)
  DELETE FROM public.org_decisions WHERE created_by = p_user_id;
  DELETE FROM public.org_polls WHERE created_by = p_user_id;
  DELETE FROM public.org_invitations WHERE invited_by = p_user_id;
  DELETE FROM public.org_decision_votes WHERE voter_id = p_user_id;
  DELETE FROM public.org_poll_votes WHERE voter_id = p_user_id;
  DELETE FROM public.partnership_invitations WHERE invited_by = p_user_id;

  -- Sonstige NOT NULL Referenzen
  DELETE FROM public.equipment_loans WHERE borrower_id = p_user_id;
  DELETE FROM public.equipment_requests WHERE requested_by = p_user_id;
  DELETE FROM public.exit_settlements WHERE profile_id = p_user_id OR created_by = p_user_id;
  DELETE FROM public.team_votes WHERE voter_id = p_user_id OR candidate_id = p_user_id;
  DELETE FROM public.task_notifications WHERE profile_id = p_user_id;
  DELETE FROM public.collaboration_acceptances WHERE profile_id = p_user_id;
  DELETE FROM public.calendar_feed_tokens WHERE profile_id = p_user_id;

  -- Calendar (created_by ist nullable - aber Items sind oft persoenlich)
  DELETE FROM public.calendar_events WHERE created_by = p_user_id;
  DELETE FROM public.calendar_groups WHERE created_by = p_user_id;

  -- ─── 2) Nullable Referenzen auf NULL setzen ───
  UPDATE public.organizations SET created_by = NULL WHERE created_by = p_user_id;
  UPDATE public.bookings SET approved_by = NULL WHERE approved_by = p_user_id;
  UPDATE public.bookings SET requested_by = NULL WHERE requested_by = p_user_id;
  UPDATE public.project_tasks SET created_by = NULL WHERE created_by = p_user_id;
  UPDATE public.projects SET created_by = NULL WHERE created_by = p_user_id;
  UPDATE public.org_partnerships SET invited_by = NULL WHERE invited_by = p_user_id;
  UPDATE public.org_partnerships SET accepted_by = NULL WHERE accepted_by = p_user_id;
  UPDATE public.equipment_requests SET responded_by = NULL WHERE responded_by = p_user_id;
  UPDATE public.partnership_invitations SET responded_by = NULL WHERE responded_by = p_user_id;
  UPDATE public.equipment_loans SET approved_by = NULL WHERE approved_by = p_user_id;
  UPDATE public.org_activity_log SET actor_id = NULL WHERE actor_id = p_user_id;
  UPDATE public.telegram_link_tokens SET consumed_by = NULL WHERE consumed_by = p_user_id;
  UPDATE public.agreement_amendments SET created_by = NULL WHERE created_by = p_user_id;
  UPDATE public.inquiries SET created_by = NULL WHERE created_by = p_user_id;
  UPDATE public.group_invitations SET invited_profile_id = NULL WHERE invited_profile_id = p_user_id;

  -- ─── 3) auth.users loeschen → cascadet profiles + auth.* ───
  DELETE FROM auth.users WHERE id = p_user_id;

  RETURN json_build_object(
    'success', true,
    'deleted_email', v_target_email
  );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

REVOKE ALL ON FUNCTION public.delete_user_completely(uuid) FROM public;
GRANT EXECUTE ON FUNCTION public.delete_user_completely(uuid) TO authenticated;
