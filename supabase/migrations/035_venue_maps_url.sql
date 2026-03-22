-- Migration 035: Maps-Link für Spielstätte
ALTER TABLE projects
  ADD COLUMN IF NOT EXISTS venue_maps_url text;
