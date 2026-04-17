// Restore-Script: Dunkelstrom Projekte / Inventar / Anfragen aus Backup
// in das aktuelle User-First Schema einspielen.
//
// Owner_profile_id wird auf motttto (Superadmin) gesetzt,
// group_id (bei Projekten) auf die Dunkelstrom-Gruppe.
//
// Run: node backups/restore-dunkelstrom.mjs

import fs from "node:fs";
import path from "node:path";
import { execSync } from "node:child_process";

const BACKUP_DIR = path.join(import.meta.dirname, "2026-04-15T00-13-05-966Z");
const PROJECT_REF = "wiywvuurxzkctvpwkncj";
const OWNER_ID = "4a94b14d-0460-4a99-a4b2-bd3bec643275"; // motttto
const GROUP_ID = "3cde30d0-e9b2-446f-97fd-46bbcccbacc8"; // Dunkelstrom

const TOKEN = execSync('security find-generic-password -s "supabase-deploy-token" -w', {
  encoding: "utf8",
}).trim();

// JSONB-Spalten (Array oder Object → als ein jsonb-Wert serialisieren)
const JSONB_COLS = new Set(["ownership_shares", "metadata", "permissions"]);

function sqlEscape(v, col = null) {
  if (v === null || v === undefined) return "NULL";
  if (typeof v === "boolean") return v ? "true" : "false";
  if (typeof v === "number") return String(v);
  if (Array.isArray(v)) {
    // jsonb-Spalten oder Array-of-Objects → als jsonb
    if (col && JSONB_COLS.has(col)) {
      return `'${JSON.stringify(v).replace(/'/g, "''")}'::jsonb`;
    }
    if (v.length > 0 && typeof v[0] === "object") {
      return `'${JSON.stringify(v).replace(/'/g, "''")}'::jsonb`;
    }
    // text[]
    return `ARRAY[${v.map((x) => sqlEscape(x)).join(",")}]::text[]`;
  }
  if (typeof v === "object") {
    return `'${JSON.stringify(v).replace(/'/g, "''")}'::jsonb`;
  }
  return `'${String(v).replace(/'/g, "''")}'`;
}

function buildInsert(table, rows, colsOverride = null) {
  if (!rows.length) return null;
  const cols = colsOverride || Object.keys(rows[0]);
  const values = rows
    .map((r) => `(${cols.map((c) => sqlEscape(r[c], c)).join(", ")})`)
    .join(",\n");
  return `INSERT INTO public.${table} (${cols.join(", ")}) VALUES\n${values}\nON CONFLICT (id) DO NOTHING;`;
}

async function exec(sql) {
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
  const txt = await res.text();
  if (!res.ok) throw new Error(`HTTP ${res.status}: ${txt}`);
  return txt;
}

function load(file) {
  return JSON.parse(fs.readFileSync(path.join(BACKUP_DIR, file), "utf8"));
}

// ─── Inventar ───
async function restoreInventory() {
  const items = load("inventory_items.json").map((i) => ({
    ...i,
    owner_profile_id: OWNER_ID,
    org_id: null, // nicht mehr relevant
  }));
  const cols = [
    "id",
    "name",
    "description",
    "category",
    "quantity",
    "condition",
    "cost_per_day",
    "location",
    "image_url",
    "owner",
    "purchased_at",
    "purchased_by",
    "inventory_number",
    "device_name",
    "serial_number",
    "purchase_price",
    "dimensions",
    "power_watts",
    "accessories",
    "custom_field",
    "manufacturer_url",
    "manual_url",
    "ownership_type",
    "owner_profile_id",
    "ownership_shares",
    "funding_source",
    "receipt_url",
    "depreciation_method",
    "depreciation_years",
    "residual_value",
    "current_value",
    "is_shareable",
    "sharing_notes",
    "created_at",
  ];
  const sql = buildInsert("inventory_items", items, cols);
  await exec(sql);
  console.log(`  ✓ ${items.length} Inventar-Artikel`);

  // Einzelstücke
  const units = load("inventory_units.json");
  if (units.length) {
    const uCols = Object.keys(units[0]);
    await exec(buildInsert("inventory_units", units, uCols));
    console.log(`  ✓ ${units.length} Einzelstücke`);
  }

  // Kategorien — auf owner_profile_id setzen, alte org_id raus
  const cats = load("inventory_categories.json").map((c) => ({
    id: c.id,
    name: c.name,
    icon: c.icon,
    prefix: c.prefix,
    sort_order: c.sort_order,
    owner_profile_id: OWNER_ID,
    created_at: c.created_at,
  }));
  if (cats.length) {
    await exec(
      buildInsert("inventory_categories", cats, [
        "id",
        "name",
        "icon",
        "prefix",
        "sort_order",
        "owner_profile_id",
        "created_at",
      ])
    );
    console.log(`  ✓ ${cats.length} Kategorien`);
  }
}

// ─── Projekte ───
async function restoreProjects() {
  const projects = load("projects.json").map((p) => ({
    ...p,
    owner_profile_id: OWNER_ID,
    group_id: GROUP_ID,
    created_by: OWNER_ID, // alte User existieren nicht mehr
    org_id: null,
  }));
  const cols = [
    "id",
    "name",
    "description",
    "status",
    "date_start",
    "date_end",
    "created_by",
    "revenue",
    "venue_name",
    "venue_address",
    "venue_contact_person",
    "venue_phone",
    "venue_notes",
    "client_name",
    "client_contact_person",
    "client_phone",
    "client_email",
    "show_date",
    "arrival_time",
    "departure_time",
    "setup_date",
    "teardown_date",
    "budget_planned",
    "transport_notes",
    "internal_notes",
    "budget_honorar",
    "budget_technik",
    "budget_transport",
    "setup_date_end",
    "teardown_date_end",
    "venue_maps_url",
    "revenue_actual",
    "profit_distribution_status",
    "owner_profile_id",
    "group_id",
    "created_at",
    "updated_at",
  ];
  const sql = buildInsert("projects", projects, cols);
  await exec(sql);
  console.log(`  ✓ ${projects.length} Projekte`);

  // Sub-Tabellen pro Projekt (relativ klein, simpel rüberkopieren)
  const subTables = [
    "project_schedule",
    "project_team_members",
    "project_contacts",
    "project_consumables",
    "project_checklists",
    "project_checklist_items",
    "project_tasks",
    "cost_items",
  ];
  for (const t of subTables) {
    const file = path.join(BACKUP_DIR, `${t}.json`);
    if (!fs.existsSync(file)) continue;
    const rows = JSON.parse(fs.readFileSync(file, "utf8"));
    if (!rows.length) continue;
    // Neutralize foreign-key references zu User-Spalten falls vorhanden
    const cleaned = rows.map((r) => {
      const c = { ...r };
      for (const k of Object.keys(c)) {
        if (k === "created_by" || k === "assigned_to" || k === "uploaded_by") c[k] = OWNER_ID;
      }
      return c;
    });
    await exec(buildInsert(t, cleaned, Object.keys(cleaned[0])));
    console.log(`  ✓ ${rows.length}× ${t}`);
  }
}

// ─── Anfragen ───
async function restoreInquiries() {
  const inquiries = load("inquiries.json").map((i) => ({
    ...i,
    owner_profile_id: OWNER_ID,
    created_by: OWNER_ID,
    org_id: null,
  }));
  if (!inquiries.length) {
    console.log("  ⊘ keine Anfragen im Backup");
    return;
  }
  const cols = [
    "id",
    "status",
    "client_name",
    "client_contact_person",
    "client_phone",
    "client_email",
    "title",
    "description",
    "venue_name",
    "venue_address",
    "event_date_start",
    "event_date_end",
    "estimated_budget",
    "offer_amount",
    "offer_date",
    "offer_valid_until",
    "probability",
    "next_follow_up",
    "notes",
    "project_id",
    "created_by",
    "owner_profile_id",
    "telegram_message_id",
    "created_at",
    "updated_at",
  ];
  const sql = buildInsert("inquiries", inquiries, cols);
  await exec(sql);
  console.log(`  ✓ ${inquiries.length} Anfragen`);
}

(async () => {
  console.log("→ Restore Inventar...");
  await restoreInventory();
  console.log("→ Restore Projekte...");
  await restoreProjects();
  console.log("→ Restore Anfragen...");
  await restoreInquiries();
  console.log("\n✓ Fertig.");
})().catch((e) => {
  console.error("FEHLER:", e.message);
  process.exit(1);
});
