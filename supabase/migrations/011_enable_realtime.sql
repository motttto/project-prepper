-- Migration 011: Supabase Realtime für alle projekt-relevanten Tabellen aktivieren
-- Ermöglicht postgres_changes Subscriptions für INSERT/UPDATE/DELETE Events.

ALTER PUBLICATION supabase_realtime ADD TABLE public.projects;
ALTER PUBLICATION supabase_realtime ADD TABLE public.bookings;
ALTER PUBLICATION supabase_realtime ADD TABLE public.cost_items;
ALTER PUBLICATION supabase_realtime ADD TABLE public.project_schedule;
ALTER PUBLICATION supabase_realtime ADD TABLE public.project_team;
ALTER PUBLICATION supabase_realtime ADD TABLE public.project_contacts;
ALTER PUBLICATION supabase_realtime ADD TABLE public.project_consumables;
ALTER PUBLICATION supabase_realtime ADD TABLE public.project_checklists;
ALTER PUBLICATION supabase_realtime ADD TABLE public.project_checklist_items;
ALTER PUBLICATION supabase_realtime ADD TABLE public.inventory_items;
