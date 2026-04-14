-- Migration 067: Kollaborationsbasis-Zustimmung pro User
-- =======================================================
-- Die Plattform funktioniert nur, wenn alle User die Grundregeln akzeptieren:
--   - Inventar bleibt Eigentum des Einzeluser
--   - Gewinne werden kollektiv und transparent verteilt
--   - Kooperationsvereinbarungen sind verbindlich
--   - Exit-Regeln gelten wie vereinbart
--
-- Ohne Akzeptanz:
--   - Kein Zugriff auf Inventar-Sharing
--   - Keine Teilnahme an Kooperationsvereinbarungen
--   - Redirect im Middleware zu /onboarding

ALTER TABLE public.profiles
  ADD COLUMN IF NOT EXISTS collaboration_accepted_version text,
  ADD COLUMN IF NOT EXISTS collaboration_accepted_at timestamptz;

-- Historie: jede Akzeptanz wird geloggt (Version, Zeitpunkt, IP optional)
CREATE TABLE IF NOT EXISTS public.collaboration_acceptances (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  profile_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  version text NOT NULL,
  accepted_at timestamptz DEFAULT now(),
  user_agent text,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_collab_accept_profile ON public.collaboration_acceptances(profile_id);

ALTER TABLE public.collaboration_acceptances ENABLE ROW LEVEL SECURITY;

CREATE POLICY "collab_accept_select_self" ON public.collaboration_acceptances
  FOR SELECT TO authenticated USING (profile_id = auth.uid());

CREATE POLICY "collab_accept_insert_self" ON public.collaboration_acceptances
  FOR INSERT TO authenticated WITH CHECK (profile_id = auth.uid());
