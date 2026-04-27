-- Migration 088: App-Feedback + Notification-Praeferenzen
-- ========================================================
-- 1. app_feedback: User-Feedback (Bug/Idee/Sonstiges) -> Superadmin
-- 2. profiles.notification_prefs: User-spezifische Email-Notification-Toggles

-- ── 1. App-Feedback ─────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS public.app_feedback (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  profile_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  feedback_type text NOT NULL CHECK (feedback_type IN ('bug', 'idea', 'other')),
  message text NOT NULL,
  app_route text,
  status text NOT NULL DEFAULT 'new' CHECK (status IN ('new', 'in_review', 'resolved', 'wontfix')),
  response text,
  resolved_by uuid REFERENCES public.profiles(id),
  resolved_at timestamptz,
  created_at timestamptz DEFAULT now()
);

ALTER TABLE public.app_feedback ENABLE ROW LEVEL SECURITY;

-- User sieht eigenes Feedback, Superadmin sieht alles
CREATE POLICY "app_feedback_select" ON public.app_feedback
  FOR SELECT TO authenticated USING (
    profile_id = auth.uid()
    OR EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

-- Authenticated kann eigenes Feedback einreichen
CREATE POLICY "app_feedback_insert" ON public.app_feedback
  FOR INSERT TO authenticated WITH CHECK (profile_id = auth.uid());

-- Superadmin kann antworten / Status aendern
CREATE POLICY "app_feedback_update" ON public.app_feedback
  FOR UPDATE TO authenticated USING (
    EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

-- ── 2. Notification-Praeferenzen ────────────────────────────────────────────

ALTER TABLE public.profiles
  ADD COLUMN IF NOT EXISTS notification_prefs jsonb DEFAULT
    '{"voting": true, "polls": true, "loans": true, "feedback": true}'::jsonb;

COMMENT ON COLUMN public.profiles.notification_prefs IS
  'Toggle pro Notification-Typ: voting (Group-Beitritts-Voting), polls (neue Umfrage), loans (Verleih-Anfrage), feedback (Superadmin only)';
