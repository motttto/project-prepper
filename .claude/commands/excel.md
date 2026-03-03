# Excel-Import / Export

Erstelle oder erweitere Excel-Import/Export-Funktionalität.

## Anweisung

Aufgabe: $ARGUMENTS

### Kontext
- **Library:** `xlsx` (SheetJS) — bereits installiert
- **Bestehender Import:** `src/components/inventory/excel-import.tsx`
  - Mehrstufig: Datei wählen → Spalten-Mapping → Vorschau → Import
  - Auto-Detection der Spalten anhand Headernamen (deutsch + englisch)
  - Duplikat-Erkennung via `inventory_number`

### Import-Pattern (bewährt)
```tsx
import * as XLSX from "xlsx";

// Datei lesen
const reader = new FileReader();
reader.onload = (e) => {
  const wb = XLSX.read(e.target?.result, { type: "array" });
  const ws = wb.Sheets[wb.SheetNames[0]];
  const data = XLSX.utils.sheet_to_json<Record<string, unknown>>(ws, { header: 1 });
  // data[0] = Headers, data[1+] = Rows
};
reader.readAsArrayBuffer(file);
```

### Export-Pattern
```tsx
import * as XLSX from "xlsx";

function exportToExcel(data: Record<string, unknown>[], filename: string) {
  const ws = XLSX.utils.json_to_sheet(data);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Daten");
  XLSX.writeFile(wb, `${filename}.xlsx`);
}
```

### Häufige Aufgaben
- Neuen Import bauen (Kosten, Team, Schedule) → Excel-Import als Vorlage nehmen
- Export-Button für Tabelle → `XLSX.writeFile` mit deutschen Spaltenheadern
- Spalten-Mapping erweitern → `autoDetectMapping()` Funktion
- Validierung → Pflichtfelder prüfen, Typen konvertieren
