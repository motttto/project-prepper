# Testdaten-Migration erstellen

Erstelle eine Seed-Migration mit realistischen Testdaten.

## Anweisung

Erstelle Testdaten für: $ARGUMENTS

1. Lies `supabase/migrations/` für die nächste Nummer und das aktuelle Schema
2. Schau dir bestehende Seeds an (`002_seed_inventory.sql`, `003_seed_projects.sql`) als Referenz
3. Erstelle die SQL-Datei mit:
   - Header-Kommentar
   - Realistische deutsche Testdaten (Event-/Veranstaltungsbranche)
   - `INSERT INTO ... VALUES` Statements
   - Referenzen auf existierende Daten (z.B. project_ids aus Seed 003)
4. Zeige die fertige Migration

## Konventionen
- Realistische Namen und Werte (keine "Test 1", "Test 2")
- Deutsche Texte für UI-sichtbare Felder
- UUIDs: `gen_random_uuid()` oder fest definierte für Referenzen
- Mengen und Preise im realistischen Bereich
