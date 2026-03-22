# Inventar-Feature bearbeiten

Änderungen am Inventar-System (Equipment, Buchungen, Bilder, Kategorien, Einzelstücke).

## Anweisung

Aufgabe: $ARGUMENTS

### Kontext
- **Tabellen:** `inventory_items` (Equipment) + `bookings` (Reservierungen) + `inventory_categories` (dynamische Kategorien) + `inventory_units` (Einzelstücke)
- **Felder:** name, category, quantity, condition, cost_per_day, location, owner, inventory_number (auto), purchased_by, purchased_at, device_name, serial_number, purchase_price, dimensions, power_watts, accessories (text[]), custom_field, manufacturer_url, manual_url
- **Conditions:** `new`, `good`, `fair`, `poor`, `broken`, `retired`
- **Booking-Status:** `reserved`, `checked_out`, `returned`
- **Bilder:** Supabase Storage Bucket `inventory-images`, WebP, max 800px/<200KB
- **Inventarnummer:** Automatisch generiert (Format: Kategorie-Prefix + laufende Nr.)

### Dynamische Kategorien (Migration 029)
- **Tabelle:** `inventory_categories` — name, icon (Emoji), prefix, sort_order, org_id
- **Auto-Seed:** Beim ersten Laden werden Default-Kategorien erstellt
- **Kategorie-Manager:** Modal im Inventar-Header (hinzufügen/bearbeiten/löschen)
- **Verwendung:** Filter-Chips, Neuanlage-Formular (Datalist), Inventarnummer-Generierung

### Einzelstücke (Migration 028)
- **Tabelle:** `inventory_units` — item_id, unit_number, condition, notes
- **Tracking:** Bei Menge > 1 können einzelne Stücke (#1, #2, #3...) mit eigenem Zustand + Notizen angelegt werden
- **UI:** Aufklappbare Sektion im Detail-Modal

### Hersteller-/Manual-URLs (Migration 028)
- `manufacturer_url` — Link zum Hersteller-Produkt
- `manual_url` — Link zum Handbuch/Manual
- UI: Input-Felder mit klickbarem Öffnen-Button (IconExternalLink)

### Relevante Dateien
- `src/app/(dashboard)/inventory/page.tsx` — Inventarliste + Filter + Excel-Import + Kategorie-Manager
- `src/components/inventory/excel-import.tsx` — Mehrstufiger Excel-Import
- `src/components/inventory/inventory-detail-modal.tsx` — Detail-Modal (Foto + URLs + Einzelstücke + Zubehör)
- `src/components/inventory/inventory-image-upload.tsx` — Foto-Upload mit Komprimierung
- `src/components/projects/tab-equipment.tsx` — Equipment-Buchungen im Projekt

### Buchungs-Logik
- Equipment wird pro Projekt für Zeitraum gebucht (`date_from`, `date_to`)
- Verfügbarkeit = `quantity` minus aktive Buchungen im Zeitraum
- Konflikte prüfen: Überlappende Zeiträume mit gleicher `inventory_item_id`

### Bild-Upload
```tsx
import imageCompression from "browser-image-compression";
const compressed = await imageCompression(file, {
  maxSizeMB: 0.2, maxWidthOrHeight: 800, fileType: "image/webp",
});
await supabase.storage.from("inventory-images").upload(path, compressed);
```

### Migrations
- `025_inventory_details.sql` — Gerätedetails (device_name, serial_number, etc.)
- `028_inventory_urls_and_units.sql` — URLs + Einzelstücke-Tabelle
- `029_inventory_categories.sql` — Dynamische Kategorien

### Häufige Aufgaben
- Neues Feld → Migration + Type + UI (Detail-Modal + Liste + Excel-Export)
- Kategorie hinzufügen → Kategorie-Manager Modal oder DB direkt
- Filter/Sortierung erweitern → inventory/page.tsx
- Buchungs-Kalender → Verfügbarkeits-Check über Zeiträume
- Barcode/QR-Scanner → Inventarnummer scannen
- Bulk-Operationen → Mehrere Items bearbeiten/löschen
