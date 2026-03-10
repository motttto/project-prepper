-- Auf- und Abbau: Einzeldaten zu Zeiträumen erweitern
-- setup_date und teardown_date bleiben als Startdatum bestehen,
-- neue _end Felder ergänzen den Zeitraum.

ALTER TABLE projects
  ADD COLUMN IF NOT EXISTS setup_date_end date,
  ADD COLUMN IF NOT EXISTS teardown_date_end date;
