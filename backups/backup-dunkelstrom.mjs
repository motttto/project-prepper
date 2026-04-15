// Backup-Script: Exportiert alle Dunkelstrom-Daten als JSON
// Usage: TOKEN=... ORG_ID=... node backup-dunkelstrom.mjs

import { writeFileSync, mkdirSync } from "node:fs";
import { join } from "node:path";

const TOKEN = process.env.TOKEN;
const ORG_ID = process.env.ORG_ID || "a0000000-0000-0000-0000-000000000001";
const PROJECT_REF = "wiywvuurxzkctvpwkncj";

if (!TOKEN) {
  console.error("TOKEN environment variable fehlt");
  process.exit(1);
}

const ts = new Date().toISOString().replace(/[:.]/g, "-");
const BACKUP_DIR = join(new URL(".", import.meta.url).pathname, ts);
mkdirSync(BACKUP_DIR, { recursive: true });

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
  if (!res.ok) {
    const t = await res.text();
    throw new Error(`HTTP ${res.status}: ${t.substring(0, 300)}`);
  }
  return await res.json();
}

async function dump(table, whereSQL = "") {
  try {
    const sql = `SELECT * FROM ${table} ${whereSQL}`;
    const rows = await query(sql);
    const arr = Array.isArray(rows) ? rows : [];
    writeFileSync(join(BACKUP_DIR, `${table}.json`), JSON.stringify(arr, null, 2));
    console.log(`  ${table.padEnd(40)} ${arr.length} rows`);
    return arr.length;
  } catch (err) {
    console.log(`  ${table.padEnd(40)} ERROR: ${err.message.substring(0, 80)}`);
    return 0;
  }
}

const projectFilter = `WHERE project_id IN (SELECT id FROM projects WHERE org_id = '${ORG_ID}')`;

console.log(`Backup nach: ${BACKUP_DIR}`);
console.log(`Org: ${ORG_ID}\n`);

let total = 0;
total += await dump("organizations", `WHERE id = '${ORG_ID}'`);
total += await dump("org_memberships", `WHERE org_id = '${ORG_ID}'`);
total += await dump("profiles", "");
total += await dump("roles", `WHERE org_id = '${ORG_ID}'`);

total += await dump("inventory_items", `WHERE org_id = '${ORG_ID}'`);
total += await dump("inventory_categories", `WHERE org_id = '${ORG_ID}'`);
total += await dump("inventory_units", `WHERE org_id = '${ORG_ID}'`);

total += await dump("projects", `WHERE org_id = '${ORG_ID}'`);
total += await dump("project_members", projectFilter);
total += await dump("project_invitations", projectFilter);
total += await dump("project_tasks", projectFilter);
total += await dump("project_schedule", projectFilter);
total += await dump("project_team_members", projectFilter);
total += await dump("project_contacts", projectFilter);
total += await dump("project_consumables", projectFilter);
total += await dump("project_checklists", projectFilter);
total += await dump(
  "project_checklist_items",
  `WHERE checklist_id IN (SELECT id FROM project_checklists ${projectFilter})`
);
total += await dump("project_files", `WHERE org_id = '${ORG_ID}'`);

total += await dump("cost_items", projectFilter);
total += await dump("bookings", projectFilter);
total += await dump("project_profit_shares", projectFilter);

total += await dump("org_decisions", `WHERE org_id = '${ORG_ID}'`);
total += await dump(
  "org_decision_votes",
  `WHERE decision_id IN (SELECT id FROM org_decisions WHERE org_id = '${ORG_ID}')`
);

total += await dump("inquiries", `WHERE org_id = '${ORG_ID}'`);
total += await dump(
  "inquiry_invitations",
  `WHERE inquiry_id IN (SELECT id FROM inquiries WHERE org_id = '${ORG_ID}')`
);

total += await dump("org_polls", `WHERE org_id = '${ORG_ID}'`);
total += await dump(
  "org_poll_options",
  `WHERE poll_id IN (SELECT id FROM org_polls WHERE org_id = '${ORG_ID}')`
);
total += await dump(
  "org_poll_votes",
  `WHERE poll_id IN (SELECT id FROM org_polls WHERE org_id = '${ORG_ID}')`
);

total += await dump("cooperation_agreements", `WHERE org_id = '${ORG_ID}'`);
total += await dump(
  "agreement_inventory_contributions",
  `WHERE agreement_id IN (SELECT id FROM cooperation_agreements WHERE org_id = '${ORG_ID}')`
);
total += await dump(
  "agreement_roles",
  `WHERE agreement_id IN (SELECT id FROM cooperation_agreements WHERE org_id = '${ORG_ID}')`
);
total += await dump(
  "agreement_signatures",
  `WHERE agreement_id IN (SELECT id FROM cooperation_agreements WHERE org_id = '${ORG_ID}')`
);

total += await dump("org_activity_log", `WHERE org_id = '${ORG_ID}'`);
total += await dump("calendar_groups", `WHERE org_id = '${ORG_ID}'`);
total += await dump(
  "calendar_events",
  `WHERE group_id IN (SELECT id FROM calendar_groups WHERE org_id = '${ORG_ID}')`
);

total += await dump("org_email_config", `WHERE org_id = '${ORG_ID}'`);

console.log(`\nFertig: ${total} Zeilen in ${BACKUP_DIR}`);
