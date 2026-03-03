# Inventar-Feature bearbeiten

Änderungen am Inventar-System (Equipment, Buchungen, Bilder).

## Anweisung

Aufgabe: $ARGUMENTS

### Kontext
- **Tabellen:** `inventory_items` (Equipment) + `bookings` (Reservierungen)
- **Felder:** name, category, quantity, condition, cost_per_day, location, owner, inventory_number (auto), purchased_by, purchased_at
- **Conditions:** `new`, `good`, `fair`, `poor`, `broken`, `retired`
- **Booking-Status:** `reserved`, `checked_out`, `returned`
- **Bilder:** Supabase Storage Bucket `inventory-images`, WebP, max 800px/<200KB
- **Inventarnummer:** Automatisch generiert via DB-Trigger (Format: Kategorie-Prefix + laufende Nr.)

### Relevante Dateien
- `src/app/(dashboard)/inventory/page.tsx` — Inventarliste + Filter + Excel-Import
- `src/components/inventory/excel-import.tsx` — Mehrstufiger Excel-Import
- `src/components/inventory/inventory-detail-modal.tsx` — Detail-Modal (Foto + Bearbeiten)
- `src/components/inventory/inventory-image-upload.tsx` — Foto-Upload mit Komprimierung
- `src/components/projects/tab-equipment.tsx` — Equipment-Buchungen im Projekt

### Buchungs-Logik
- Equipment wird pro Projekt für Zeitraum gebucht (`date_from`, `date_to`)
- Verfügbarkeit = `quantity` minus aktive Buchungen im Zeitraum
- Konflikte prüfen: Überlappende Zeiträume mit gleicher `inventory_item_id`

### Bild-Upload
```tsx
// Komprimierung via browser-image-compression
import imageCompression from "browser-image-compression";
const compressed = await imageCompression(file, {
  maxSizeMB: 0.2,
  maxWidthOrHeight: 800,
  fileType: "image/webp",
});
// Upload zu Supabase Storage
await supabase.storage.from("inventory-images").upload(path, compressed);
```

### Häufige Aufgaben
- Neues Feld auf inventory_items → Migration + Type + UI (Detail-Modal + Liste)
- Filter/Sortierung erweitern → inventory/page.tsx
- Buchungs-Kalender → Verfügbarkeits-Check über Zeiträume
- Barcode/QR-Scanner → Inventarnummer scannen
- Bulk-Operationen → Mehrere Items bearbeiten/löschen
