# Input verliert Fokus beim Tippen — Debug

Symptom: User tippt "450" und es kommt "4 [Pause] 5 [Pause] 0", muss zwischen den Ziffern neu ins Feld klicken. Cursor-Position und Fokus gehen verloren.

## Anweisung

Aufgabe: $ARGUMENTS

## Ursache 1 — Inline-Komponente im Render-Body (häufigster Fall)

Komponenten DEFINIERT innerhalb des Render-Bodys einer anderen Komponente bekommen bei jedem Render einen neuen Komponenten-Typ. React mountet sie deshalb komplett neu → DOM-Knoten weg → Fokus weg.

**Anti-Pattern:**
```tsx
function ParentPage() {
  // ❌ Definition INNERHALB des Renders
  function AutoInput({ label, field }: { ... }) {
    return <input value={localData[field]} onChange={...} />;
  }

  return <div><AutoInput label="Budget" field="budget" /></div>;
}
```

**Fix — Render-Funktion (gibt JSX zurück, kein neuer Komponenten-Typ):**
```tsx
function ParentPage() {
  function renderAutoInput(label: string, field: keyof Data) {
    return (
      <div>
        <input
          key={field}
          value={localData[field] ?? ""}
          onChange={(e) => handleChange(field, e.target.value)}
        />
      </div>
    );
  }

  return <div>{renderAutoInput("Budget", "budget")}</div>;
}
```

**Stabiler Fix** wäre, die Komponente AUSSERHALB der Parent-Komponente zu definieren und Props durchzureichen. Render-Funktionen sind aber pragmatischer wenn sie auf Parent-State (`localData`, `handleChange`) zugreifen.

Referenz: Commit `4b24c3c` ("Fix input bug: replace inline components with render functions") — gleicher Bug in `inquiries/[id]/page.tsx`.

## Ursache 2 — `key` ändert sich auf jedem Render

Wenn ein Wrapper um den Input einen `key` bekommt, der sich pro Render ändert (z.B. `key={Math.random()}`, `key={Date.now()}`, `key={someObject}`), wird remountet.

**Check:**
```bash
grep -nE "key=\{[^}]*(\.id|Math\.|Date\.|new |\?\.)" <file>
```

## Ursache 3 — Conditional Mount/Unmount durch volatile State

Wenn der Input nur unter Bedingung gerendert wird, und die Bedingung mid-typing flippt:
```tsx
{!loading && <input ... />}  // ❌ wenn loading kurz true wird
```

Häufig in Verbindung mit:
- Auto-Save-Hook der `loading` setzt
- Realtime-Subscription die loadAll() triggert mit `setLoading(true)` am Start

## Ursache 4 — Auto-Save mit Re-Fetch direkt nach Save

Pattern: User tippt → Auto-Save fired (debounced) → DB-Write → Realtime fired → loadAll() → setData(neueDaten) → Input rerendert mit neuem `value`-Prop. Cursor-Position kann springen, aber Fokus bleibt **eigentlich** erhalten.

**Wenn doch Fokus weg:** Prüfen ob:
- Input-`value` während Save kurz auf falschen Wert gesetzt wird (z.B. von Server-Response überschrieben während User noch tippt)
- `key` auf Input von einer ID abhängt die sich ändert

## Diagnose-Workflow

1. **Konsole offen — Warning checken:**
   - "Maximum update depth exceeded" → Re-Render-Loop
   - "Each child in a list should have a unique key" → key-Probleme
2. **React DevTools → Profiler:**
   - Welche Komponente rerendert pro Keystroke?
   - Wie viele Renders pro Tastendruck? Mehr als 2 = verdächtig.
3. **`grep` im betroffenen File:**
   ```bash
   # Inline-Komponenten finden
   grep -nE "^\s+function [A-Z][a-zA-Z]+\s*\(" <file>
   ```
4. **Vergleich:** funktioniert das Feld in einem anderen Browser/Inkognito? (Browser-Extensions ausschließen)

## Bekannte Stellen mit ähnlichem Risiko

| Stelle | Status | Hinweis |
|--------|--------|---------|
| `inquiries/[id]/page.tsx` | gefixt (4b24c3c) | Render-Funktionen statt AutoInput/AutoTextarea |
| `inquiries/page.tsx` Create-Form | inline JSX, kein AutoInput-Pattern | Falls Bug: andere Ursache prüfen (4 oben) |
| `groups/[id]/page.tsx` Settings | `GroupSettings` ist Top-Level-Funktion (richtig) | Realtime-Trigger durch Memberships beachten |
| TabOverview Auto-Save | `useDebouncedSave` + `useFieldTracking` | Etabliertes Muster, sollte ok sein |

## Häufige Fix-Snippets

**Komponente nach AUSSERHALB extrahieren (sauberster Fix):**
```tsx
// vorher: innen
function Page() {
  function MyInput(props) { ... }
  return <MyInput .../>;
}

// nachher: aussen, Props durchreichen
function MyInput({ value, onChange, ... }) { ... }
function Page() {
  return <MyInput value={x} onChange={setX} .../>;
}
```

**Stable `key` setzen** (nicht `Math.random`):
```tsx
<input key={field} ... />  // field ist konstant pro Position
```

**`useMemo` für Objekte/Arrays die als Props gehen:**
```tsx
const value = useMemo(() => ({ groupId, ... }), [groupId, ...]);
<Provider value={value}>...</Provider>
```
