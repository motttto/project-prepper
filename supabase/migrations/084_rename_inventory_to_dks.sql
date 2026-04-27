-- Migration 084: Bestehende Inventarnummern auf '-DKS' umbenennen
-- =================================================================
-- Migration 083 hatte den Suffix vom Owner-Profil (motto -> 'MOT') angehaengt.
-- Konzeptionell sind die 66 Items aber Dunkelstrom-Equipment, das gerade
-- auf reale Besitzer neu verteilt wird. Bis dahin tragen alle den
-- Gruppen-Suffix '-DKS'.
--
-- Owner bleibt motto (technische Anforderung: jedes Item hat genau einen
-- owner_profile_id). Group-Share zu Dunkelstrom existiert bereits.

UPDATE public.inventory_items
SET inventory_number = REGEXP_REPLACE(inventory_number, '-MOT$', '-DKS')
WHERE inventory_number ~ '-MOT$';
