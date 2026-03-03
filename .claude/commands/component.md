# Neue React-Komponente erstellen

Erstelle eine neue Komponente nach Projekt-Conventions.

## Anweisung

Erstelle eine Komponente basierend auf: $ARGUMENTS

1. Bestimme den richtigen Ordner:
   - Seiten → `src/app/(dashboard)/...`
   - Layout-Teile → `src/components/layout/`
   - Projekt-Tabs → `src/components/projects/`
   - Inventar → `src/components/inventory/`
   - Allgemein → `src/components/ui/`
2. Erstelle die Komponente mit:
   - `"use client";` Direktive
   - Styling via CSS-Variables: `style={{ color: "var(--color-primary)" }}`
   - Tailwind für Layout (flex, grid, padding, margin, rounded)
   - Deutsche UI-Texte
   - Props-Interface mit TypeScript
3. Falls die Komponente Daten braucht:
   - `createClient()` aus `@/lib/supabase`
   - `useState` + `useCallback` + `useEffect` Pattern
   - Realtime via `useRealtimeTable` Hook wenn sinnvoll
4. Icons aus `@/components/ui/icons` importieren

## Conventions
- Kein Redux/Zustand — nur useState + useCallback
- Kein CSS-in-JS — nur CSS-Variables + Tailwind
- Formulare: useState pro Feld, e.preventDefault() Handler
- Org-Scoping: `useOrg()` für orgId, `.eq("org_id", orgId)` in Queries
