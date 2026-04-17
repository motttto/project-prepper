-- Migration 080: Email-Group-Invitations beim Signup verlinken
-- ============================================================
-- Wenn ein User sich registriert und es bestehen offene Group-
-- Invitations fuer seine Email-Adresse, dann auf seinen
-- profile_id verlinken (so dass /dashboard die Einladungen
-- anzeigen kann).
--
-- Wird per AFTER INSERT Trigger auf public.profiles ausgeloest.

CREATE OR REPLACE FUNCTION public.link_group_invitations_to_new_profile()
RETURNS TRIGGER AS $$
BEGIN
  UPDATE public.group_invitations
  SET invited_profile_id = NEW.id
  WHERE invited_profile_id IS NULL
    AND lower(invited_email) = lower(NEW.email)
    AND status = 'pending';
  RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

DROP TRIGGER IF EXISTS link_group_invitations_trg ON public.profiles;
CREATE TRIGGER link_group_invitations_trg
  AFTER INSERT ON public.profiles
  FOR EACH ROW EXECUTE FUNCTION public.link_group_invitations_to_new_profile();

-- Backfill: bestehende profile_id-NULL Invitations matchen
UPDATE public.group_invitations gi
SET invited_profile_id = p.id
FROM public.profiles p
WHERE gi.invited_profile_id IS NULL
  AND gi.status = 'pending'
  AND lower(gi.invited_email) = lower(p.email);
