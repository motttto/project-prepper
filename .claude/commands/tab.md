# Neuen Projekt-Tab anlegen

Erstelle einen neuen Tab für die Projekt-Detailseite.

## Anweisung

Erstelle einen Tab für: $ARGUMENTS

1. Lies `src/app/(dashboard)/projects/[id]/page.tsx` um das Tab-System zu verstehen
2. Lies bestehende Tabs in `src/components/projects/` als Referenz (z.B. `tab-overview.tsx`, `tab-costs.tsx`)
3. Erstelle `src/components/projects/tab-{name}.tsx`:
   - `"use client";` Direktive
   - Props: mindestens `projectId: string`
   - Supabase-Queries mit `useCallback` + `useEffect`
   - Realtime-Subscription via `useRealtimeTable` wenn sinnvoll
   - CRUD-Operationen (Liste + Formular-Dialog)
   - Styling: CSS-Variables + Tailwind
   - Deutsche UI-Texte
4. Registriere den Tab in `projects/[id]/page.tsx`:
   - Import hinzufügen
   - Tab-Array erweitern mit `{ id, label, icon }`
   - Conditional Rendering im Tab-Content
5. Falls neue DB-Tabelle nötig → schlage `/migrate` vor

## Tab-Pattern
- Header mit Titel + "Hinzufügen"-Button
- Liste/Tabelle der Einträge
- Inline-Edit oder Modal für Bearbeitung
- Löschen mit Bestätigung
- Loading-State + Empty-State
