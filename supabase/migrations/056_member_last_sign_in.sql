-- Migration 056: RPC um last_sign_in_at für Org-Mitglieder zu holen
-- Supabase auth.users ist client-seitig nicht direkt zugänglich,
-- daher SECURITY DEFINER Funktion.

CREATE OR REPLACE FUNCTION get_member_last_sign_in(p_org_id uuid)
RETURNS TABLE(user_id uuid, last_sign_in_at timestamptz)
LANGUAGE sql SECURITY DEFINER AS $$
  SELECT au.id, au.last_sign_in_at
  FROM auth.users au
  JOIN org_memberships om ON om.profile_id = au.id
  WHERE om.org_id = p_org_id AND om.is_active = true;
$$;
