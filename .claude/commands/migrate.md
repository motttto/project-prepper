# Neue Supabase-Migration erstellen

Erstelle eine neue SQL-Migration für das Projekt.

## Anweisung

1. Lies `supabase/migrations/` und ermittle die nächste Nummer (Format: `0XX_name.sql`)
2. Frage den User, was die Migration tun soll (falls nicht als Argument angegeben: $ARGUMENTS)
3. Erstelle die SQL-Datei mit:
   - Header-Kommentar mit Beschreibung
   - `CREATE TABLE` / `ALTER TABLE` Statements
   - RLS Policies (Pattern: `is_project_member()` oder `is_admin()` für eingeschränkte Tabellen)
   - Realtime aktivieren: `ALTER PUBLICATION supabase_realtime ADD TABLE new_table;`
   - Trigger für `updated_at` falls nötig
4. Zeige die fertige Migration und frage ob sie passt
5. Erinnere den User daran, `types/database.ts` zu aktualisieren (oder schlage `/db-types` vor)

## Konventionen
- Tabellennamen: snake_case, Plural
- Foreign Keys: `table_id` Format
- Immer `id UUID DEFAULT gen_random_uuid() PRIMARY KEY`
- Immer `created_at TIMESTAMPTZ DEFAULT now()`
- `updated_at` mit Trigger wenn sinnvoll
- RLS immer aktivieren: `ALTER TABLE x ENABLE ROW LEVEL SECURITY;`
