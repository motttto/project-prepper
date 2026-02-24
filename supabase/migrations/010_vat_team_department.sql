-- Migration 010: Umsatzsteuer auf Kostenpositionen + Teamstruktur (Abteilung)

-- USt-Satz pro Kostenposition (Standard 19%, ermäßigt 7%, befreit 0%)
ALTER TABLE cost_items
  ADD COLUMN vat_rate NUMERIC(5,2) NOT NULL DEFAULT 19;

-- Abteilung/Bereich für Team-Gruppierung
ALTER TABLE project_team
  ADD COLUMN department TEXT DEFAULT NULL;
