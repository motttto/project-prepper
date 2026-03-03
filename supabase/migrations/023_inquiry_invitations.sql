-- Migration 023: Inquiry Team Invitations
-- Einladungen für Team-Verfügbarkeit bei Anfragen

-- ─────────────────────────────────────────────────────────────────────────
-- Tabelle
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.inquiry_invitations (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  inquiry_id uuid NOT NULL REFERENCES public.inquiries(id) ON DELETE CASCADE,
  invited_by uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  invited_profile_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  status text NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending', 'accepted', 'declined')),
  created_at timestamptz DEFAULT now(),
  responded_at timestamptz,
  UNIQUE(inquiry_id, invited_profile_id)
);

-- Indizes
CREATE INDEX IF NOT EXISTS idx_inquiry_invitations_invited
  ON public.inquiry_invitations(invited_profile_id, status);
CREATE INDEX IF NOT EXISTS idx_inquiry_invitations_inquiry
  ON public.inquiry_invitations(inquiry_id);

-- ─────────────────────────────────────────────────────────────────────────
-- RLS
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE public.inquiry_invitations ENABLE ROW LEVEL SECURITY;

-- SELECT: offen für alle authentifizierten (wie inquiries selbst)
CREATE POLICY "inquiry_invitations_select"
  ON public.inquiry_invitations
  FOR SELECT TO authenticated
  USING (true);

-- INSERT: invited_by muss auth.uid() sein + Ersteller der Anfrage oder Admin
CREATE POLICY "inquiry_invitations_insert"
  ON public.inquiry_invitations
  FOR INSERT TO authenticated
  WITH CHECK (
    invited_by = auth.uid()
    AND (
      EXISTS (
        SELECT 1 FROM public.inquiries
        WHERE id = inquiry_id
        AND created_by = auth.uid()
      )
      OR public.is_admin()
    )
  );

-- UPDATE: nur der Eingeladene (accept/decline)
CREATE POLICY "inquiry_invitations_update"
  ON public.inquiry_invitations
  FOR UPDATE TO authenticated
  USING (invited_profile_id = auth.uid());

-- DELETE: Einladender kann zurückziehen, Admin auch
CREATE POLICY "inquiry_invitations_delete"
  ON public.inquiry_invitations
  FOR DELETE TO authenticated
  USING (
    invited_by = auth.uid()
    OR public.is_admin()
  );

-- ─────────────────────────────────────────────────────────────────────────
-- Realtime
-- ─────────────────────────────────────────────────────────────────────────
ALTER PUBLICATION supabase_realtime ADD TABLE public.inquiry_invitations;
