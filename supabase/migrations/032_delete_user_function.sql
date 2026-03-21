-- 032: User entfernen (Membership löschen, bei Testusern komplett)
CREATE OR REPLACE FUNCTION public.remove_org_member(
  p_org_id uuid,
  p_profile_id uuid,
  p_delete_user boolean DEFAULT false
)
RETURNS void AS $$
BEGIN
  -- Org-Membership entfernen
  DELETE FROM public.org_memberships
  WHERE org_id = p_org_id AND profile_id = p_profile_id;

  -- Bei Testusern: komplett löschen (Profil + Auth-User)
  IF p_delete_user THEN
    DELETE FROM public.profiles WHERE id = p_profile_id;
    DELETE FROM auth.users WHERE id = p_profile_id;
  END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
