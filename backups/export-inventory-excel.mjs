// Exportiert Dunkelstrom-Inventar als Excel (wie im UI)
// Usage: TOKEN=... node export-inventory-excel.mjs

import { writeFileSync } from "node:fs";
import { join } from "node:path";
import * as XLSX from "xlsx";

const TOKEN = process.env.TOKEN;
const ORG_ID = process.env.ORG_ID || "a0000000-0000-0000-0000-000000000001";
const PROJECT_REF = "wiywvuurxzkctvpwkncj";

if (!TOKEN) {
  console.error("TOKEN environment variable fehlt");
  process.exit(1);
}

async function query(sql) {
  const res = await fetch(
    `https://api.supabase.com/v1/projects/${PROJECT_REF}/database/query`,
    {
      method: "POST",
      headers: {
        Authorization: `Bearer ${TOKEN}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ query: sql }),
    }
  );
  if (!res.ok) throw new Error(`HTTP ${res.status}: ${await res.text()}`);
  return await res.json();
}

const conditionLabels = {
  new: "Neu",
  good: "Gut",
  fair: "Okay",
  poor: "Schlecht",
  broken: "Defekt",
  retired: "Ausgemustert",
};

const items = await query(`
  SELECT * FROM inventory_items
  WHERE org_id = '${ORG_ID}'
  ORDER BY inventory_number NULLS LAST, name
`);

console.log(`${items.length} Items exportieren...`);

// Alle Felder — vollständiger Export (mehr als das UI exportiert)
const rows = items.map((i) => ({
  "Inv.-Nr.": i.inventory_number || "",
  Name: i.name,
  "Gerätebezeichnung": i.device_name || "",
  Beschreibung: i.description || "",
  Kategorie: i.category || "",
  Seriennummer: i.serial_number || "",
  Menge: i.quantity,
  Zustand: conditionLabels[i.condition] || i.condition,
  "Kaufpreis (€)": i.purchase_price != null ? Number(i.purchase_price) : "",
  "Preis/Tag (€)": Number(i.cost_per_day),
  "Aktueller Wert (€)": i.current_value != null ? Number(i.current_value) : "",
  "Restwert (€)": i.residual_value != null ? Number(i.residual_value) : "",
  "Abschreibungsmethode": i.depreciation_method || "",
  "Abschreibungsjahre": i.depreciation_years || "",
  "Abmaße": i.dimensions || "",
  "Leistung (W)": i.power_watts != null ? i.power_watts : "",
  "Zubehör": (i.accessories || []).join(", "),
  Lagerort: i.location || "",
  Eigentümer: i.owner || "",
  "Eigentums-Typ": i.ownership_type || "",
  "Finanzierung": i.funding_source || "",
  "Pate": i.purchased_by || "",
  "Kaufdatum": i.purchased_at || "",
  "Teilbar": i.is_shareable ? "Ja" : "Nein",
  "Sharing-Notizen": i.sharing_notes || "",
  "Freifeld": i.custom_field || "",
  "Hersteller-Link": i.manufacturer_url || "",
  "Manual-Link": i.manual_url || "",
  "Foto-URL": i.image_url || "",
  "Beleg-URL": i.receipt_url || "",
  "Erstellt": i.created_at || "",
}));

const ws = XLSX.utils.json_to_sheet(rows);

// Alle Spalten sichtbar mit Auto-Breite
const colWidths = Object.keys(rows[0] || {}).map((k) => ({
  wch: Math.min(Math.max(k.length + 2, 12), 40),
}));
ws["!cols"] = colWidths;

const wb = XLSX.utils.book_new();
XLSX.utils.book_append_sheet(wb, ws, "Inventar");

const dateStr = new Date().toISOString().slice(0, 10);
const fileName = `Dunkelstrom_Inventar_${dateStr}.xlsx`;
const filePath = join(new URL(".", import.meta.url).pathname, fileName);
XLSX.writeFile(wb, filePath);

console.log(`\nFertig: ${filePath}`);
console.log(`Spalten: ${Object.keys(rows[0] || {}).length}`);
console.log(`\nZum Herunterladen oeffne:\nopen "${filePath}"`);
