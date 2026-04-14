-- Migration 064: Auto-Join-Trigger vollständig entfernen
-- =======================================================
-- Entscheidung (14.04.2026): Neue User sollen ausnahmslos erst ihre eigene
-- Org anlegen. Zusammenarbeit zwischen Orgs läuft über org_partnerships
-- (Migration 041), nicht über automatische Mitgliedschaften.
--
-- Der Trigger aus Migration 030 (Auto-Join bei passender org_invitations-Email)
-- wird komplett entfernt. Migration 063 hatte ihn nur entschärft — jetzt weg.
--
-- Tabelle org_invitations bleibt bestehen:
--   - Admin kann weiterhin sehen, wen er eingeladen hat
--   - /join-Seite funktioniert weiter (separate Entscheidung)
--   - Nur der automatische Profile-INSERT-Trigger ist weg

DROP TRIGGER IF EXISTS on_profile_created_check_invitations ON public.profiles;
DROP FUNCTION IF EXISTS public.handle_org_invitation_auto_join();
