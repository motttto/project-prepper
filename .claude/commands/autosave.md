# Auto-Save Feature bearbeiten

Auto-Save-Logik für Multi-User Echtzeit-Kollaboration.

## Anweisung

Aufgabe: $ARGUMENTS

### Kontext
- **Pattern:** Field-Level Debounce + Last-Write-Wins + Stale-Banner
- **Debounce:** Text 800ms, Zahlen 1200ms, Selects/Checkboxen sofort
- **Realtime:** Incoming Updates überschreiben nur Felder, die der User NICHT gerade editiert (Dirty-Tracking)
- **Konflikte:** Banner "Ein anderer Nutzer hat Änderungen gemacht" + "Neu laden" Button
- **Save-Indicator:** `idle` → `pending` → `saving` → `saved` → `error`

### Architektur

#### Hook: `useDebouncedSave`
```tsx
// src/hooks/use-debounced-save.ts
type SaveState = "idle" | "pending" | "saving" | "saved" | "error";

function useDebouncedSave<T>(
  saveFn: (value: T) => Promise<void>,
  delay?: number // default 800ms
): {
  debouncedSave: (value: T) => void;
  saveState: SaveState;
  flush: () => void;  // sofort speichern (z.B. bei Tab-Wechsel)
}
```

#### Hook: `useFieldTracking`
```tsx
// src/hooks/use-field-tracking.ts
function useFieldTracking<T extends Record<string, unknown>>(serverData: T) {
  // Tracks: dirtyFields (Set<keyof T>), localValues (Partial<T>)
  // mergeRemote(remoteData: T) → überschreibt nur clean fields
  // markClean(field: keyof T) → nach erfolgreichem Save
  // isDirty: boolean → irgendein Feld geändert?
}
```

#### Komponente: `SaveIndicator`
```tsx
// src/components/ui/save-indicator.tsx
<SaveIndicator state={saveState} />
// idle    → nichts
// pending → grauer Punkt "..."
// saving  → Spinner
// saved   → grüner Haken "Gespeichert" (verschwindet nach 2s)
// error   → roter Text "Fehler beim Speichern"
```

#### Komponente: `StaleDataBanner`
```tsx
// src/components/ui/stale-data-banner.tsx
<StaleDataBanner
  show={hasPendingRemoteUpdate}
  onReload={() => { loadData(); setHasPendingRemoteUpdate(false); }}
/>
```

### Migration (Voraussetzung)
```sql
-- updated_at Auto-Trigger für alle relevanten Tabellen
CREATE OR REPLACE FUNCTION public.handle_updated_at()
RETURNS trigger AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Auf projects (und weitere Tabellen bei Bedarf):
CREATE TRIGGER set_projects_updated_at
  BEFORE UPDATE ON public.projects
  FOR EACH ROW EXECUTE FUNCTION public.handle_updated_at();
```

### Relevante Dateien
- `src/hooks/use-debounced-save.ts` — Debounce-Save Hook (NEU)
- `src/hooks/use-field-tracking.ts` — Dirty-Field Tracking (NEU)
- `src/components/ui/save-indicator.tsx` — Save-Status Anzeige (NEU)
- `src/components/ui/stale-data-banner.tsx` — Konflikt-Banner (NEU)
- `src/hooks/use-realtime-table.ts` — Erweitern: isSavingRef Guard
- `src/hooks/use-presence.ts` — Erweitern: editingSection Payload
- `src/components/projects/tab-overview.tsx` — Erstes Ziel für Auto-Save

### Presence-Erweiterung
```tsx
// Erweitertes Presence-Payload:
await channel.track({
  userId, name, email, onlineAt,
  editingSection: "venue" | "details" | "client" | null  // NEU
});
// → Zeigt "Anna bearbeitet Venue" im UI
```

### Implementierungs-Reihenfolge
1. Migration: `updated_at` Trigger
2. `useDebouncedSave` Hook
3. `useFieldTracking` Hook
4. `SaveIndicator` + `StaleDataBanner` Komponenten
5. `TabOverview` umstellen (erstes Ziel)
6. `useRealtimeTable` erweitern (isSavingRef)
7. `usePresence` erweitern (editingSection)
8. Weitere Tabs nach Bedarf

### Debounce-Zeiten
| Input-Typ | Delay | Grund |
|-----------|-------|-------|
| Text (Name, Beschreibung) | 800ms | Schnell genug, kein DB-Spam |
| Zahlen (Budget, Kosten) | 1200ms | Mehrstellige Eingaben |
| Select/Dropdown | 0ms | Einzelne diskrete Auswahl |
| Checkbox/Toggle | 0ms | Binär, kein Debounce nötig |
| Datum | 0ms | Diskrete Kalender-Auswahl |
| Textarea (Notizen) | 1200ms | Längerer Content |
