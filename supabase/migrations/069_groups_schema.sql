-- Migration 069: Groups Schema (Phase 1 — Refactor zu User-First + Groups Overlay)
-- =================================================================================
-- Neues Konzept:
-- - Jeder User ist sein eigener "Solo-Workspace" (Inventar, Anfragen, Projekte gehoeren ihm)
-- - "Groups" sind optionale Kollektive aus mehreren Usern
-- - Beitritt zu Group erfordert EINSTIMMIGES Voting aller bestehenden Mitglieder
-- - Group-Features: Polls, Kalender, Beschluesse, Cooperation Agreements
--
-- Diese Migration legt die NEUEN Tabellen an. Alte Tabellen (organizations, etc.)
-- bleiben vorerst bestehen — werden in Phase 2-3 entfernt sobald Frontend migriert.

-- ── Groups (das neue Kollektiv) ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.groups (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name text NOT NULL,
  slug text UNIQUE NOT NULL,
  description text,
  logo_url text,
  founded_by uuid NOT NULL REFERENCES public.profiles(id),
  founded_at timestamptz DEFAULT now(),
  archived_at timestamptz,
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_groups_founded_by ON public.groups(founded_by);
CREATE INDEX IF NOT EXISTS idx_groups_slug ON public.groups(slug);

-- ── Group Memberships ───────────────────────────────────────────────────────
-- Jeder User in einer Group.
-- is_active = false bedeutet: warten auf Voting-Bestaetigung
CREATE TABLE IF NOT EXISTS public.group_memberships (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  group_id uuid NOT NULL REFERENCES public.groups(id) ON DELETE CASCADE,
  profile_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  is_active boolean NOT NULL DEFAULT false,
  is_founder boolean NOT NULL DEFAULT false,
  joined_at timestamptz,
  left_at timestamptz,
  created_at timestamptz DEFAULT now(),
  UNIQUE(group_id, profile_id)
);

CREATE INDEX IF NOT EXISTS idx_group_memberships_group ON public.group_memberships(group_id);
CREATE INDEX IF NOT EXISTS idx_group_memberships_profile ON public.group_memberships(profile_id);

-- ── Group Invitations ───────────────────────────────────────────────────────
-- Einladung zu einer Gruppe. Erst nach Akzeptanz des Eingeladenen + EINSTIMMIGEM
-- Voting der bestehenden Mitglieder wird Membership aktiv.
CREATE TABLE IF NOT EXISTS public.group_invitations (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  group_id uuid NOT NULL REFERENCES public.groups(id) ON DELETE CASCADE,
  invited_by uuid NOT NULL REFERENCES public.profiles(id),
  invited_email text,                                 -- fuer noch-nicht-registrierte
  invited_profile_id uuid REFERENCES public.profiles(id), -- gesetzt wenn User existiert
  invited_message text,                                -- optionale Nachricht
  -- Status-Flow: pending -> accepted_by_user (User hat akzeptiert)
  --            -> voting_in_progress -> approved (alle haben zugestimmt)
  --            -> declined_by_user / rejected_by_member / cancelled / expired
  status text NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending', 'accepted_by_user', 'voting_in_progress',
                      'approved', 'declined_by_user', 'rejected_by_member',
                      'cancelled', 'expired')),
  accepted_by_user_at timestamptz,
  voting_started_at timestamptz,
  resolved_at timestamptz,
  expires_at timestamptz DEFAULT (now() + interval '30 days'),
  created_at timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_group_invitations_group ON public.group_invitations(group_id);
CREATE INDEX IF NOT EXISTS idx_group_invitations_email ON public.group_invitations(invited_email);
CREATE INDEX IF NOT EXISTS idx_group_invitations_profile ON public.group_invitations(invited_profile_id);
CREATE INDEX IF NOT EXISTS idx_group_invitations_status ON public.group_invitations(status);

-- ── Group Invitation Votes ──────────────────────────────────────────────────
-- Jedes bestehende Mitglied muss einer Einladung zustimmen.
-- Bei einstimmigem "approve" -> Trigger aktiviert Membership.
CREATE TABLE IF NOT EXISTS public.group_invitation_votes (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  invitation_id uuid NOT NULL REFERENCES public.group_invitations(id) ON DELETE CASCADE,
  voter_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  vote text NOT NULL CHECK (vote IN ('approve', 'reject', 'abstain')),
  comment text,
  voted_at timestamptz DEFAULT now(),
  UNIQUE(invitation_id, voter_id)
);

CREATE INDEX IF NOT EXISTS idx_group_inv_votes_invitation ON public.group_invitation_votes(invitation_id);
CREATE INDEX IF NOT EXISTS idx_group_inv_votes_voter ON public.group_invitation_votes(voter_id);

-- ── RLS ─────────────────────────────────────────────────────────────────────
ALTER TABLE public.groups ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.group_memberships ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.group_invitations ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.group_invitation_votes ENABLE ROW LEVEL SECURITY;

-- Helper: ist User Mitglied der Gruppe (aktiv)?
CREATE OR REPLACE FUNCTION public.is_group_member(p_group_id uuid)
RETURNS boolean AS $$
BEGIN
  RETURN EXISTS (
    SELECT 1 FROM public.group_memberships
    WHERE group_id = p_group_id
      AND profile_id = auth.uid()
      AND is_active = true
  ) OR EXISTS (
    SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true
  );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

-- Helper: ist User Founder der Gruppe?
CREATE OR REPLACE FUNCTION public.is_group_founder(p_group_id uuid)
RETURNS boolean AS $$
BEGIN
  RETURN EXISTS (
    SELECT 1 FROM public.group_memberships
    WHERE group_id = p_group_id
      AND profile_id = auth.uid()
      AND is_founder = true
      AND is_active = true
  );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

-- ── Policies: groups ────────────────────────────────────────────────────────
-- Gruppen sehen: jeder authentifizierte User darf alle Gruppen sehen (fuer Suche/Einladungen)
CREATE POLICY "groups_select_all" ON public.groups
  FOR SELECT TO authenticated USING (true);

-- Gruppe gruenden: jeder darf
CREATE POLICY "groups_insert_self" ON public.groups
  FOR INSERT TO authenticated WITH CHECK (founded_by = auth.uid());

-- Gruppe bearbeiten: nur Founder oder Mitglied
CREATE POLICY "groups_update_member" ON public.groups
  FOR UPDATE TO authenticated USING (
    is_group_member(id)
  );

-- ── Policies: group_memberships ─────────────────────────────────────────────
CREATE POLICY "memberships_select_all" ON public.group_memberships
  FOR SELECT TO authenticated USING (true);

-- Insert: nur eigene (also wenn man sich selbst eintraegt) oder via Trigger
CREATE POLICY "memberships_insert_self" ON public.group_memberships
  FOR INSERT TO authenticated WITH CHECK (profile_id = auth.uid());

-- Update: nur eigene (z.B. left_at setzen)
CREATE POLICY "memberships_update_self" ON public.group_memberships
  FOR UPDATE TO authenticated USING (profile_id = auth.uid());

-- Delete: nur eigene (sich selbst aus Gruppe entfernen)
CREATE POLICY "memberships_delete_self" ON public.group_memberships
  FOR DELETE TO authenticated USING (profile_id = auth.uid());

-- ── Policies: group_invitations ─────────────────────────────────────────────
-- Lesen: alle Mitglieder der Gruppe + Eingeladener
CREATE POLICY "invitations_select" ON public.group_invitations
  FOR SELECT TO authenticated USING (
    is_group_member(group_id)
    OR invited_profile_id = auth.uid()
    OR invited_by = auth.uid()
  );

-- Erstellen: nur aktive Mitglieder der Gruppe
CREATE POLICY "invitations_insert_member" ON public.group_invitations
  FOR INSERT TO authenticated WITH CHECK (
    is_group_member(group_id) AND invited_by = auth.uid()
  );

-- Update: Eingeladener (akzeptieren/ablehnen) oder Einladender (cancel)
CREATE POLICY "invitations_update" ON public.group_invitations
  FOR UPDATE TO authenticated USING (
    invited_profile_id = auth.uid() OR invited_by = auth.uid()
  );

-- Delete: nur Einladender
CREATE POLICY "invitations_delete" ON public.group_invitations
  FOR DELETE TO authenticated USING (invited_by = auth.uid());

-- ── Policies: group_invitation_votes ────────────────────────────────────────
CREATE POLICY "inv_votes_select" ON public.group_invitation_votes
  FOR SELECT TO authenticated USING (
    EXISTS (
      SELECT 1 FROM public.group_invitations gi
      WHERE gi.id = invitation_id AND is_group_member(gi.group_id)
    )
  );

CREATE POLICY "inv_votes_insert_self" ON public.group_invitation_votes
  FOR INSERT TO authenticated WITH CHECK (
    voter_id = auth.uid()
    AND EXISTS (
      SELECT 1 FROM public.group_invitations gi
      WHERE gi.id = invitation_id AND is_group_member(gi.group_id)
    )
  );

CREATE POLICY "inv_votes_update_self" ON public.group_invitation_votes
  FOR UPDATE TO authenticated USING (voter_id = auth.uid());

-- ── updated_at Trigger fuer groups ──────────────────────────────────────────
CREATE OR REPLACE FUNCTION public.touch_groups_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_groups_updated_at ON public.groups;
CREATE TRIGGER trg_groups_updated_at
  BEFORE UPDATE ON public.groups
  FOR EACH ROW EXECUTE FUNCTION public.touch_groups_updated_at();

-- ── Realtime ────────────────────────────────────────────────────────────────
ALTER PUBLICATION supabase_realtime ADD TABLE public.groups;
ALTER PUBLICATION supabase_realtime ADD TABLE public.group_memberships;
ALTER PUBLICATION supabase_realtime ADD TABLE public.group_invitations;
ALTER PUBLICATION supabase_realtime ADD TABLE public.group_invitation_votes;
