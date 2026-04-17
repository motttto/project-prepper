-- Migration 077: delete_user_completely RPC fuer Superadmin
-- ============================================================
-- Loescht einen User vollstaendig (auth.users) und alles via CASCADE:
--   - profiles, group_memberships, group_invitations,
--   - inventory_items (owner_profile_id), projects (owner_profile_id),
--   - inquiries, etc.
--
-- Nur Superadmin (is_system = true) darf das aufrufen.

CREATE OR REPLACE FUNCTION public.delete_user_completely(p_user_id uuid)
RETURNS json AS $$
DECLARE
  v_caller_is_superadmin boolean;
  v_target_email text;
  v_target_is_superadmin boolean;
BEGIN
  -- Aufrufer muss Superadmin sein
  SELECT is_system INTO v_caller_is_superadmin
  FROM public.profiles
  WHERE id = auth.uid();

  IF NOT COALESCE(v_caller_is_superadmin, false) THEN
    RAISE EXCEPTION 'Nur Superadmins koennen User loeschen';
  END IF;

  -- Verhindere Selbst-Loeschung
  IF p_user_id = auth.uid() THEN
    RAISE EXCEPTION 'Du kannst dich nicht selbst loeschen';
  END IF;

  -- Target-Profile laden
  SELECT email, is_system INTO v_target_email, v_target_is_superadmin
  FROM public.profiles
  WHERE id = p_user_id;

  IF NOT FOUND THEN
    RAISE EXCEPTION 'User nicht gefunden';
  END IF;

  -- Verhindere Loeschung anderer Superadmins (Schutz)
  IF v_target_is_superadmin THEN
    RAISE EXCEPTION 'Andere Superadmins koennen nicht ueber diese Funktion geloescht werden';
  END IF;

  -- Hard-Delete via auth.users (cascaded via FK)
  DELETE FROM auth.users WHERE id = p_user_id;

  RETURN json_build_object(
    'success', true,
    'deleted_email', v_target_email
  );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Permission
REVOKE ALL ON FUNCTION public.delete_user_completely(uuid) FROM public;
GRANT EXECUTE ON FUNCTION public.delete_user_completely(uuid) TO authenticated;
