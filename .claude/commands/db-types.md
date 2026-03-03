# TypeScript-Types nach Schema-Änderung aktualisieren

Aktualisiere `src/types/database.ts` nach einer Datenbank-Änderung.

## Anweisung

1. Lies alle Migrations in `supabase/migrations/` um das aktuelle DB-Schema zu verstehen
2. Lies die aktuelle `src/types/database.ts`
3. Vergleiche und ergänze fehlende:
   - Table-Interfaces (alle Spalten mit korrekten TypeScript-Typen)
   - Neue Tabellen als Export
   - Geänderte Spalten (neue Felder, Typ-Änderungen)
   - Enum-artige String-Unions (z.B. Status, Priority, Condition)
4. Zeige die Änderungen

## Type-Mapping
| PostgreSQL | TypeScript |
|-----------|------------|
| `uuid` | `string` |
| `text` | `string` |
| `integer` | `number` |
| `numeric` | `number` |
| `boolean` | `boolean` |
| `timestamptz` | `string` |
| `date` | `string` |
| `jsonb` | `Record<string, unknown>` |
| `nullable` | `type \| null` |
