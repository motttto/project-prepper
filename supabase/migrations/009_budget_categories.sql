-- Migration 009: Budget in Kategorien unterteilen
-- Honorar, Technik, Transport/Reisekosten

ALTER TABLE projects
  ADD COLUMN budget_honorar NUMERIC(12,2) DEFAULT NULL,
  ADD COLUMN budget_technik NUMERIC(12,2) DEFAULT NULL,
  ADD COLUMN budget_transport NUMERIC(12,2) DEFAULT NULL;

-- budget_planned bleibt als Gesamtbudget erhalten
