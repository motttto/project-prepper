-- Migration 057: Umfragen (Polls) — Terminumfragen + Allgemeine Umfragen
-- Org-weit und optional projekt-spezifisch

-- Umfragen
CREATE TABLE IF NOT EXISTS public.org_polls (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  org_id uuid NOT NULL REFERENCES public.organizations(id) ON DELETE CASCADE,
  project_id uuid REFERENCES public.projects(id) ON DELETE CASCADE,
  title text NOT NULL,
  description text,
  poll_type text NOT NULL DEFAULT 'choice'
    CHECK (poll_type IN ('date', 'choice')),
  allows_multiple boolean DEFAULT true,
  is_anonymous boolean DEFAULT false,
  status text NOT NULL DEFAULT 'active'
    CHECK (status IN ('active', 'closed', 'archived')),
  deadline timestamptz,
  created_by uuid NOT NULL REFERENCES public.profiles(id),
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now()
);

-- Antwortoptionen
CREATE TABLE IF NOT EXISTS public.org_poll_options (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  poll_id uuid NOT NULL REFERENCES public.org_polls(id) ON DELETE CASCADE,
  label text NOT NULL,
  date_value date,
  time_value time,
  sort_order integer DEFAULT 0,
  created_at timestamptz DEFAULT now()
);

-- Stimmen
CREATE TABLE IF NOT EXISTS public.org_poll_votes (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  poll_id uuid NOT NULL REFERENCES public.org_polls(id) ON DELETE CASCADE,
  option_id uuid NOT NULL REFERENCES public.org_poll_options(id) ON DELETE CASCADE,
  voter_id uuid NOT NULL REFERENCES public.profiles(id),
  vote text NOT NULL DEFAULT 'yes'
    CHECK (vote IN ('yes', 'no', 'maybe')),
  created_at timestamptz DEFAULT now(),
  UNIQUE(poll_id, option_id, voter_id)
);

-- Indizes
CREATE INDEX IF NOT EXISTS idx_org_polls_org_id ON public.org_polls(org_id);
CREATE INDEX IF NOT EXISTS idx_org_polls_project_id ON public.org_polls(project_id);
CREATE INDEX IF NOT EXISTS idx_org_polls_status ON public.org_polls(status);
CREATE INDEX IF NOT EXISTS idx_org_poll_options_poll_id ON public.org_poll_options(poll_id);
CREATE INDEX IF NOT EXISTS idx_org_poll_votes_poll_id ON public.org_poll_votes(poll_id);
CREATE INDEX IF NOT EXISTS idx_org_poll_votes_voter_id ON public.org_poll_votes(voter_id);

-- RLS
ALTER TABLE public.org_polls ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.org_poll_options ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.org_poll_votes ENABLE ROW LEVEL SECURITY;

-- Policies: Alle authentifizierten Org-Mitglieder können sehen
CREATE POLICY "org_polls_select" ON public.org_polls
  FOR SELECT TO authenticated USING (true);

CREATE POLICY "org_polls_insert" ON public.org_polls
  FOR INSERT TO authenticated WITH CHECK (created_by = auth.uid());

CREATE POLICY "org_polls_update" ON public.org_polls
  FOR UPDATE TO authenticated USING (
    created_by = auth.uid()
    OR EXISTS (
      SELECT 1 FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      WHERE om.profile_id = auth.uid()
        AND om.org_id = org_polls.org_id
        AND r.name = 'admin'
        AND om.is_active = true
    )
  );

CREATE POLICY "org_polls_delete" ON public.org_polls
  FOR DELETE TO authenticated USING (
    created_by = auth.uid()
    OR EXISTS (
      SELECT 1 FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      WHERE om.profile_id = auth.uid()
        AND om.org_id = org_polls.org_id
        AND r.name = 'admin'
        AND om.is_active = true
    )
  );

CREATE POLICY "org_poll_options_select" ON public.org_poll_options
  FOR SELECT TO authenticated USING (true);

CREATE POLICY "org_poll_options_insert" ON public.org_poll_options
  FOR INSERT TO authenticated WITH CHECK (true);

CREATE POLICY "org_poll_options_update" ON public.org_poll_options
  FOR UPDATE TO authenticated USING (true);

CREATE POLICY "org_poll_options_delete" ON public.org_poll_options
  FOR DELETE TO authenticated USING (true);

CREATE POLICY "org_poll_votes_select" ON public.org_poll_votes
  FOR SELECT TO authenticated USING (true);

CREATE POLICY "org_poll_votes_insert" ON public.org_poll_votes
  FOR INSERT TO authenticated WITH CHECK (voter_id = auth.uid());

CREATE POLICY "org_poll_votes_update" ON public.org_poll_votes
  FOR UPDATE TO authenticated USING (voter_id = auth.uid());

CREATE POLICY "org_poll_votes_delete" ON public.org_poll_votes
  FOR DELETE TO authenticated USING (voter_id = auth.uid());

-- updated_at Trigger
CREATE OR REPLACE FUNCTION update_org_poll_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_org_poll_updated_at ON public.org_polls;
CREATE TRIGGER trg_org_poll_updated_at
  BEFORE UPDATE ON public.org_polls
  FOR EACH ROW
  EXECUTE FUNCTION update_org_poll_updated_at();

-- Realtime aktivieren
ALTER PUBLICATION supabase_realtime ADD TABLE public.org_polls;
ALTER PUBLICATION supabase_realtime ADD TABLE public.org_poll_options;
ALTER PUBLICATION supabase_realtime ADD TABLE public.org_poll_votes;
