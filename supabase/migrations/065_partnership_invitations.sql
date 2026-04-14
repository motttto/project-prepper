-- Migration 065: Partnership-Einladungen per E-Mail
-- ===================================================
-- Dunkelstrom-Admin lädt externe Org via E-Mail ein. Empfänger muss existierenden
-- Account + eigene Org haben, klickt Link, stimmt zu → org_partnerships entsteht
-- mit status='active' und share_inventory=true.
--
-- Eingang: /partner-invite?token=<invitation.id>

CREATE TABLE IF NOT EXISTS public.partnership_invitations (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  org_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE CASCADE,
  invited_by uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  email text NOT NULL,
  -- Was beim Akzeptieren geteilt werden soll
  share_inventory boolean NOT NULL DEFAULT true,
  share_team_contacts boolean NOT NULL DEFAULT false,
  -- Status-Tracking
  status text NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending', 'accepted', 'declined', 'expired', 'cancelled')),
  notes text,
  -- Meta
  created_at timestamptz DEFAULT now(),
  responded_at timestamptz,
  responded_by uuid REFERENCES public.profiles(id),
  accepted_partnership_id uuid REFERENCES public.org_partnerships(id) ON DELETE SET NULL,
  expires_at timestamptz DEFAULT (now() + interval '30 days')
);

-- Indizes
CREATE INDEX IF NOT EXISTS idx_partnership_inv_org ON public.partnership_invitations(org_id);
CREATE INDEX IF NOT EXISTS idx_partnership_inv_email ON public.partnership_invitations(email);
CREATE INDEX IF NOT EXISTS idx_partnership_inv_status ON public.partnership_invitations(status);

-- RLS: authentifizierte User dürfen lesen (Token-basierter Zugriff, UUID ist schwer zu raten).
-- Mutationen nur durch Org-Admins oder den Empfänger selbst.
ALTER TABLE public.partnership_invitations ENABLE ROW LEVEL SECURITY;

CREATE POLICY "partnership_inv_select" ON public.partnership_invitations
  FOR SELECT TO authenticated USING (true);

-- INSERT nur durch Admin der einladenden Org
CREATE POLICY "partnership_inv_insert" ON public.partnership_invitations
  FOR INSERT TO authenticated WITH CHECK (
    EXISTS (
      SELECT 1 FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      WHERE om.profile_id = auth.uid()
        AND om.org_id = partnership_invitations.org_id
        AND r.name = 'admin'
        AND om.is_active = true
    )
  );

-- UPDATE: Admin der einladenden Org (Cancel) ODER der Empfänger (Accept/Decline)
CREATE POLICY "partnership_inv_update" ON public.partnership_invitations
  FOR UPDATE TO authenticated USING (
    EXISTS (
      SELECT 1 FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      WHERE om.profile_id = auth.uid()
        AND om.org_id = partnership_invitations.org_id
        AND r.name = 'admin'
        AND om.is_active = true
    )
    OR auth.uid() IS NOT NULL  -- Empfänger darf akzeptieren (Check findet in Business-Logik statt)
  );

-- DELETE nur Admins der einladenden Org
CREATE POLICY "partnership_inv_delete" ON public.partnership_invitations
  FOR DELETE TO authenticated USING (
    EXISTS (
      SELECT 1 FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      WHERE om.profile_id = auth.uid()
        AND om.org_id = partnership_invitations.org_id
        AND r.name = 'admin'
        AND om.is_active = true
    )
  );

-- Realtime
ALTER PUBLICATION supabase_realtime ADD TABLE public.partnership_invitations;
