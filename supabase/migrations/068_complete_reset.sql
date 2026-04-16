-- Migration 068: Kompletter Daten-Reset (Solo-Workspace + Groups Refactor Phase 0)
-- ==================================================================================
-- Vorher: Backup vorhanden in backups/2026-04-15T00-13-05-966Z/ (299 Zeilen JSON)
-- Plus Excel-Export der Dunkelstrom-Inventar.
--
-- Was passiert:
-- - Alle Auth-User AUSSER Superadmins (is_system = true) werden geloescht
-- - Cascading Deletes raeumen alle FKs auf:
--   * profiles (cascade von auth.users)
--   * org_memberships (cascade von profiles)
--   * inventory_items, projects, inquiries (cascade via owner-FK oder org_id)
--   * polls, decisions, calendar, agreements (cascade via org)
--
-- Was bleibt:
-- - profiles.is_system = true Eintraege (Superadmins)
-- - DB-Schema bleibt vollstaendig erhalten (nur Daten weg)
--
-- Folgemigrationen 069-072 fuehren das Schema-Refactor durch.

-- Reihenfolge: erst Tabellen leeren die FKs auf auth.users haben, dann auth.users selbst.
-- TRUNCATE CASCADE leert die meisten Daten via FK-Cascade.
TRUNCATE TABLE public.organizations CASCADE;
TRUNCATE TABLE public.roles CASCADE;

-- Project-bezogene Tabellen (haben FK created_by oder similar auf auth.users ohne CASCADE)
TRUNCATE TABLE public.projects CASCADE;
TRUNCATE TABLE public.inventory_items CASCADE;
TRUNCATE TABLE public.inquiries CASCADE;

-- Falls noch User-FKs existieren die das Loeschen blockieren wuerden,
-- droppen wir sie temporaer und fuegen sie spaeter wieder hinzu.
-- Aber erstmal versuchen.

-- Auth-User loeschen (ausser Superadmins)
DELETE FROM auth.users
WHERE id NOT IN (
  SELECT id FROM public.profiles WHERE is_system = true
);

-- Hinweis: Cascading ueber organizations CASCADE leert auch:
--   org_memberships, org_invitations, inventory_items (via org_id),
--   projects, inquiries, org_polls, org_decisions, calendar_groups,
--   cooperation_agreements, org_email_config, org_partnerships, etc.

-- Verifizierung
DO $$
DECLARE
  v_users integer;
  v_profiles integer;
  v_orgs integer;
  v_inventory integer;
BEGIN
  SELECT COUNT(*) INTO v_users FROM auth.users;
  SELECT COUNT(*) INTO v_profiles FROM public.profiles;
  SELECT COUNT(*) INTO v_orgs FROM public.organizations;
  SELECT COUNT(*) INTO v_inventory FROM public.inventory_items;

  RAISE NOTICE 'Reset abgeschlossen:';
  RAISE NOTICE '  auth.users: %', v_users;
  RAISE NOTICE '  profiles: %', v_profiles;
  RAISE NOTICE '  organizations: %', v_orgs;
  RAISE NOTICE '  inventory_items: %', v_inventory;
END $$;
