-- 030: Org-Einladungen (Link-basiert) + Testuser-Support

-- Einladungs-Tabelle
CREATE TABLE IF NOT EXISTS org_invitations (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  org_id uuid NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
  email text NOT NULL,
  invited_by uuid NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
  role_id uuid NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
  status text NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending', 'accepted', 'expired', 'cancelled')),
  created_at timestamptz DEFAULT now(),
  accepted_at timestamptz,
  UNIQUE (org_id, email)
);

-- RLS
ALTER TABLE org_invitations ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Authenticated users can select org_invitations"
  ON org_invitations FOR SELECT TO authenticated USING (true);

CREATE POLICY "Authenticated users can insert org_invitations"
  ON org_invitations FOR INSERT TO authenticated WITH CHECK (true);

CREATE POLICY "Authenticated users can update org_invitations"
  ON org_invitations FOR UPDATE TO authenticated USING (true) WITH CHECK (true);

CREATE POLICY "Authenticated users can delete org_invitations"
  ON org_invitations FOR DELETE TO authenticated USING (true);

-- Realtime
ALTER PUBLICATION supabase_realtime ADD TABLE org_invitations;

-- Auto-Join Trigger: Wenn ein neuer User sich registriert und eine Einladung hat,
-- automatisch org_membership erstellen und Einladung akzeptieren.
-- Läuft AFTER INSERT auf profiles (nach handle_new_user).
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
    -- org_membership erstellen (aktiv + sofort freigeschaltet)
    INSERT INTO public.org_memberships (org_id, profile_id, role_id, is_active, approved_at)
    VALUES (inv.org_id, NEW.id, inv.role_id, true, now())
    ON CONFLICT (org_id, profile_id) DO UPDATE
    SET role_id = inv.role_id, is_active = true, approved_at = now();

    -- Einladung als akzeptiert markieren
    UPDATE public.org_invitations
    SET status = 'accepted', accepted_at = now()
    WHERE id = inv.id;
  END LOOP;

  RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE TRIGGER on_profile_created_check_invitations
  AFTER INSERT ON public.profiles
  FOR EACH ROW
  EXECUTE FUNCTION public.handle_org_invitation_auto_join();
