-- Migration 104: created_by/approved_by/... FKs auf ON DELETE SET NULL umstellen
--
-- Bisher hatten viele Audit-FKs (created_by, approved_by, invited_by, etc.)
-- keine ON DELETE-Klausel → Default = NO ACTION → User-Delete schlaegt fehl
-- wenn irgendwo ein FK auf ihn zeigt. Fuer DSGVO Art. 17 (Loeschpflicht)
-- muss das gehen.
--
-- Fix: alle Audit-FKs auf SET NULL umstellen (Audit-Eintrag bleibt erhalten,
-- nur der Bezug zum geloeschten User wird auf NULL gesetzt).
--
-- CASCADE-FKs (z.B. votes wo ohne User der Vote sinnlos ist) bleiben unveraendert.

ALTER TABLE public.agreement_amendments
  DROP CONSTRAINT agreement_amendments_created_by_fkey,
  ADD CONSTRAINT agreement_amendments_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.bookings
  DROP CONSTRAINT bookings_approved_by_fkey,
  ADD CONSTRAINT bookings_approved_by_fkey
    FOREIGN KEY (approved_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.bookings
  DROP CONSTRAINT bookings_requested_by_fkey,
  ADD CONSTRAINT bookings_requested_by_fkey
    FOREIGN KEY (requested_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.cooperation_agreements
  DROP CONSTRAINT cooperation_agreements_created_by_fkey,
  ADD CONSTRAINT cooperation_agreements_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.email_templates
  DROP CONSTRAINT email_templates_updated_by_fkey,
  ADD CONSTRAINT email_templates_updated_by_fkey
    FOREIGN KEY (updated_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.equipment_loans
  DROP CONSTRAINT equipment_loans_approved_by_fkey,
  ADD CONSTRAINT equipment_loans_approved_by_fkey
    FOREIGN KEY (approved_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.equipment_loans
  DROP CONSTRAINT equipment_loans_borrower_id_fkey,
  ADD CONSTRAINT equipment_loans_borrower_id_fkey
    FOREIGN KEY (borrower_id) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.equipment_requests
  DROP CONSTRAINT equipment_requests_requested_by_fkey,
  ADD CONSTRAINT equipment_requests_requested_by_fkey
    FOREIGN KEY (requested_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.equipment_requests
  DROP CONSTRAINT equipment_requests_responded_by_fkey,
  ADD CONSTRAINT equipment_requests_responded_by_fkey
    FOREIGN KEY (responded_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.exit_settlements
  DROP CONSTRAINT exit_settlements_created_by_fkey,
  ADD CONSTRAINT exit_settlements_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.group_invitations
  DROP CONSTRAINT group_invitations_invited_by_fkey,
  ADD CONSTRAINT group_invitations_invited_by_fkey
    FOREIGN KEY (invited_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.inventory_full_shares
  DROP CONSTRAINT inventory_full_shares_shared_by_fkey,
  ADD CONSTRAINT inventory_full_shares_shared_by_fkey
    FOREIGN KEY (shared_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.inventory_group_shares
  DROP CONSTRAINT inventory_group_shares_shared_by_fkey,
  ADD CONSTRAINT inventory_group_shares_shared_by_fkey
    FOREIGN KEY (shared_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.inventory_project_grants
  DROP CONSTRAINT inventory_project_grants_granted_by_fkey,
  ADD CONSTRAINT inventory_project_grants_granted_by_fkey
    FOREIGN KEY (granted_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.org_decisions
  DROP CONSTRAINT org_decisions_created_by_fkey,
  ADD CONSTRAINT org_decisions_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.org_partnerships
  DROP CONSTRAINT org_partnerships_invited_by_fkey,
  ADD CONSTRAINT org_partnerships_invited_by_fkey
    FOREIGN KEY (invited_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.org_polls
  DROP CONSTRAINT org_polls_created_by_fkey,
  ADD CONSTRAINT org_polls_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.partnership_invitations
  DROP CONSTRAINT partnership_invitations_responded_by_fkey,
  ADD CONSTRAINT partnership_invitations_responded_by_fkey
    FOREIGN KEY (responded_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.project_tasks
  DROP CONSTRAINT project_tasks_created_by_fkey,
  ADD CONSTRAINT project_tasks_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES public.profiles(id) ON DELETE SET NULL;

ALTER TABLE public.projects
  DROP CONSTRAINT projects_created_by_fkey,
  ADD CONSTRAINT projects_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES public.profiles(id) ON DELETE SET NULL;
