-- Fix: Alle Org-Mitglieder duerfen Events loeschen (nicht nur Ersteller/Admin)
-- Konsistent mit der UPDATE-Policy die bereits is_org_member nutzt
DROP POLICY IF EXISTS "calendar_events_delete" ON calendar_events;
CREATE POLICY "calendar_events_delete" ON calendar_events
  FOR DELETE TO authenticated
  USING (is_org_member(org_id));
