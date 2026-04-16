-- Migration 074: Alte Project-Trigger entfernen
-- ===============================================
-- handle_new_project_org wirft NULL-Constraint Fehler weil project_orgs.org_id NOT NULL ist.
-- project_orgs entfaellt im neuen Modell (Cross-Org war Org-spezifisch, jetzt sind es Groups).
-- Auch der Owner-Trigger (project_members) bleibt erhalten — Owner soll Project-Member werden.

DROP TRIGGER IF EXISTS on_project_created_add_org ON public.projects;
DROP FUNCTION IF EXISTS public.handle_new_project_org() CASCADE;

-- project_orgs Tabelle ist jetzt obsolet
DROP TABLE IF EXISTS public.project_orgs CASCADE;
