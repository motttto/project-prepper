-- Migration 094: Self-RSVP fuer inquiry_invitations erlauben
-- Bisher konnte nur der Anfrage-Ersteller (created_by) oder Admin Einladungen
-- inserten. Mit Group-Ownership (086) sehen alle Gruppenmitglieder die Anfrage
-- und sollten sich auch selbst per RSVP-Banner eintragen koennen.

DROP POLICY IF EXISTS "inquiry_invitations_insert" ON public.inquiry_invitations;

CREATE POLICY "inquiry_invitations_insert"
  ON public.inquiry_invitations
  FOR INSERT TO authenticated
  WITH CHECK (
    invited_by = auth.uid()
    AND (
      -- Self-RSVP: User traegt sich selbst ein und darf die Anfrage sehen
      (
        invited_profile_id = auth.uid()
        AND EXISTS (
          SELECT 1 FROM public.inquiries i
          WHERE i.id = inquiry_id
          AND (
            i.owner_profile_id = auth.uid()
            OR i.created_by = auth.uid()
            OR (i.owner_group_id IS NOT NULL AND public.is_group_member(i.owner_group_id))
            OR (i.group_id IS NOT NULL AND public.is_group_member(i.group_id))
          )
        )
      )
      -- Bestehender Pfad: Ersteller laedt andere ein
      OR EXISTS (
        SELECT 1 FROM public.inquiries
        WHERE id = inquiry_id
        AND created_by = auth.uid()
      )
      -- Superadmin / Admin
      OR public.is_admin()
    )
  );
