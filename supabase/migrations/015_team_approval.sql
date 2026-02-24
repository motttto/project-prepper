-- Migration 015: Team-Freigabe & Abstimmungssystem
-- =================================================
-- Neue User starten als inaktiv. Alle aktiven Mitglieder müssen zustimmen.
-- Admin kann sofort freigeben. Erster User wird automatisch Admin + aktiv.

-- ── 1. Profiles erweitern ──────────────────────────────────────────────────

ALTER TABLE public.profiles
  ADD COLUMN IF NOT EXISTS is_active boolean NOT NULL DEFAULT false;

ALTER TABLE public.profiles
  ADD COLUMN IF NOT EXISTS approved_at timestamptz;

-- Bestehende User aktivieren (Rückwärtskompatibilität)
UPDATE public.profiles
SET is_active = true, approved_at = now()
WHERE is_active = false;

-- ── 2. Team-Votes Tabelle ──────────────────────────────────────────────────

CREATE TABLE public.team_votes (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  candidate_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE NOT NULL,
  voter_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE NOT NULL,
  created_at timestamptz DEFAULT now(),
  UNIQUE(candidate_id, voter_id)
);

CREATE INDEX idx_team_votes_candidate ON public.team_votes(candidate_id);
CREATE INDEX idx_team_votes_voter ON public.team_votes(voter_id);

-- ── 3. RLS für team_votes ──────────────────────────────────────────────────

ALTER TABLE public.team_votes ENABLE ROW LEVEL SECURITY;

-- Alle authentifizierten User können Votes sehen
CREATE POLICY "team_votes_select" ON public.team_votes
  FOR SELECT TO authenticated USING (true);

-- Nur aktive Mitglieder können abstimmen (voter_id muss auth.uid() sein)
CREATE POLICY "team_votes_insert" ON public.team_votes
  FOR INSERT TO authenticated
  WITH CHECK (
    voter_id = auth.uid()
    AND EXISTS (
      SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_active = true
    )
  );

-- Eigene Votes löschen (Stimme zurücknehmen)
CREATE POLICY "team_votes_delete" ON public.team_votes
  FOR DELETE TO authenticated
  USING (voter_id = auth.uid());

-- ── 4. Admin darf beliebige Profiles updaten ───────────────────────────────

CREATE POLICY "admin_update_profiles" ON public.profiles
  FOR UPDATE TO authenticated
  USING (public.is_admin())
  WITH CHECK (public.is_admin());

-- ── 5. Realtime aktivieren ─────────────────────────────────────────────────

ALTER PUBLICATION supabase_realtime ADD TABLE public.team_votes;

-- ── 6. handle_new_user() Trigger anpassen ──────────────────────────────────
-- Bootstrap: Erster User → Admin + aktiv. Weitere User → member + inaktiv.

CREATE OR REPLACE FUNCTION public.handle_new_user()
RETURNS trigger AS $$
DECLARE
  default_role_id uuid;
  admin_role_id uuid;
  active_count integer;
BEGIN
  -- Wie viele aktive Profile gibt es bereits?
  SELECT COUNT(*) INTO active_count
  FROM public.profiles
  WHERE is_active = true;

  IF active_count = 0 THEN
    -- Erster User: Admin-Rolle + sofort aktiv
    SELECT id INTO admin_role_id FROM public.roles WHERE name = 'admin';
    INSERT INTO public.profiles (id, email, name, role_id, is_active, approved_at)
    VALUES (
      new.id,
      new.email,
      coalesce(new.raw_user_meta_data->>'name', split_part(new.email, '@', 1)),
      admin_role_id,
      true,
      now()
    );
  ELSE
    -- Normaler User: member-Rolle + inaktiv (wartet auf Freigabe)
    SELECT id INTO default_role_id FROM public.roles WHERE name = 'member';
    INSERT INTO public.profiles (id, email, name, role_id, is_active)
    VALUES (
      new.id,
      new.email,
      coalesce(new.raw_user_meta_data->>'name', split_part(new.email, '@', 1)),
      default_role_id,
      false
    );
  END IF;

  RETURN new;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
