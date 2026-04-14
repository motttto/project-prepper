-- Migration 063: Org-Einladungen benötigen Admin-Freigabe
-- ========================================================
-- Vorher (030): Auto-Join-Trigger setzte is_active=TRUE + approved_at=now(),
--   sobald sich ein User mit einer passenden org_invitations-Email registriert.
--   → Offene/vergessene Einladungen konnten Fremdzugriff auf die Org ermöglichen.
--
-- Jetzt: Einladung erstellt zwar die Mitgliedschaft, aber inaktiv.
--   Der User landet auf /pending und wartet auf Admin-Freigabe im Team-Tab.
--   Das ist konsistent zum Team-Approval-Flow aus Migration 015.

CREATE OR REPLACE FUNCTION public.handle_org_invitation_auto_join()
RETURNS trigger AS $$
DECLARE
  inv RECORD;
BEGIN
  -- Alle offenen Einladungen für diese E-Mail suchen
  FOR inv IN
    SELECT * FROM public.org_invitations
    WHERE email = NEW.email AND status = 'pending'
  LOOP
    -- Mitgliedschaft vormerken, aber INAKTIV (wartet auf Admin-Freigabe)
    -- ON CONFLICT: bei bereits existierender Membership NICHT herunterstufen
    INSERT INTO public.org_memberships (org_id, profile_id, role_id, is_active, approved_at)
    VALUES (inv.org_id, NEW.id, inv.role_id, false, NULL)
    ON CONFLICT (org_id, profile_id) DO NOTHING;

    -- Einladung als akzeptiert markieren (User hat sich ja registriert)
    -- Freigabe erfolgt separat durch Admin
    UPDATE public.org_invitations
    SET status = 'accepted', accepted_at = now()
    WHERE id = inv.id;
  END LOOP;

  RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Optional: Bestehende offene Einladungen prüfen und aufräumen
-- (kommentiert — nur ausführen, wenn gewünscht)
--
-- SELECT email, org_id, status, created_at
-- FROM public.org_invitations
-- WHERE status = 'pending'
-- ORDER BY created_at DESC;
