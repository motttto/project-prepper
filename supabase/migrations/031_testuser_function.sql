-- 031: Testuser-Funktion (umgeht FK auf auth.users)
-- Erstellt einen Dummy-User in auth.users + profiles + org_memberships
CREATE OR REPLACE FUNCTION public.create_test_user(
  p_org_id uuid,
  p_name text,
  p_email text,
  p_role_id uuid
)
RETURNS uuid AS $$
DECLARE
  v_user_id uuid := gen_random_uuid();
BEGIN
  -- Dummy auth.users Eintrag (minimal)
  INSERT INTO auth.users (
    id, instance_id, aud, role,
    email, encrypted_password,
    email_confirmed_at, created_at, updated_at,
    raw_user_meta_data, is_sso_user
  ) VALUES (
    v_user_id,
    '00000000-0000-0000-0000-000000000000',
    'authenticated',
    'authenticated',
    p_email,
    crypt('testuser_no_login_' || v_user_id::text, gen_salt('bf')),
    now(), now(), now(),
    jsonb_build_object('name', p_name),
    false
  );

  -- Profil wird durch handle_new_user() Trigger erstellt,
  -- aber wir überschreiben is_active und approved_at
  UPDATE public.profiles
  SET is_active = true, approved_at = now(), name = p_name
  WHERE id = v_user_id;

  -- Org-Membership erstellen
  INSERT INTO public.org_memberships (org_id, profile_id, role_id, is_active, approved_at)
  VALUES (p_org_id, v_user_id, p_role_id, true, now())
  ON CONFLICT (org_id, profile_id) DO UPDATE
  SET role_id = p_role_id, is_active = true, approved_at = now();

  RETURN v_user_id;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
