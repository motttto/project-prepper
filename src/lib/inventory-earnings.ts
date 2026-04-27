// Item-Erträge berechnen + lesen
// ===============================
// Kombiniert fixierte Earnings (inventory_item_earnings, gefüllt bei
// profit_distribution_status='distributed') mit Live-Berechnung für
// noch nicht ausgeschüttete Projekte.
//
// Owner-Payout pro Item:
//   gross   = daily_rate × quantity × projectDays
//   share   = gross / SUM(gross) aller Items im Agreement
//   payout  = netProfit × (inventory_weight / weightSum) × share

import { createClient } from "@/lib/supabase";
import type { ProfitFormula } from "@/types/database";

export type EarningsRow = {
  // Identifikation
  item_id: string;
  project_id: string;
  project_name: string;
  agreement_id: string | null;
  contributor_id: string | null;
  contributor_name?: string | null;
  // Beitrags-Details
  daily_rate: number;
  quantity: number;
  project_days: number;
  gross_contribution: number;
  share_of_inventory: number;
  // Gewinn-Anteil zum Owner
  owner_payout: number;
  // Kontext
  revenue: number;
  net_profit: number;
  status: "live" | "distributed";
  distribution_status: string | null;
  date_start: string | null;
  date_end: string | null;
  distributed_at: string | null;
};

function diffDays(a: string | null, b: string | null): number {
  if (!a || !b) return 1;
  const start = new Date(a).getTime();
  const end = new Date(b).getTime();
  return Math.max(1, Math.floor((end - start) / 86400000) + 1);
}

function preDeductionsTotal(formula: ProfitFormula | null): number {
  if (!formula?.pre_deductions) return 0;
  return formula.pre_deductions.reduce((s, d) => s + Number(d.amount || 0), 0);
}

/** Earnings für ein einzelnes Item: distributed + live kombiniert */
export async function loadItemEarnings(itemId: string): Promise<EarningsRow[]> {
  const supabase = createClient();

  // 1. Fixierte Erträge (snapshot)
  const { data: fixed } = await supabase
    .from("inventory_item_earnings")
    .select(
      `
      item_id, project_id, agreement_id, contributor_id,
      daily_rate, quantity, project_days, gross_contribution,
      share_of_inventory, owner_payout, revenue_at_distribution,
      net_profit_at_distribution, distributed_at,
      project:projects(id, name, date_start, date_end, profit_distribution_status),
      contributor:profiles!contributor_id(name)
    `
    )
    .eq("item_id", itemId);

  const fixedRows: EarningsRow[] = (fixed || []).map((r: any) => ({
    item_id: r.item_id,
    project_id: r.project_id,
    project_name: r.project?.name || "?",
    agreement_id: r.agreement_id,
    contributor_id: r.contributor_id,
    contributor_name: r.contributor?.name ?? null,
    daily_rate: Number(r.daily_rate),
    quantity: r.quantity,
    project_days: r.project_days,
    gross_contribution: Number(r.gross_contribution),
    share_of_inventory: Number(r.share_of_inventory),
    owner_payout: Number(r.owner_payout),
    revenue: Number(r.revenue_at_distribution),
    net_profit: Number(r.net_profit_at_distribution),
    status: "distributed",
    distribution_status: r.project?.profit_distribution_status || null,
    date_start: r.project?.date_start || null,
    date_end: r.project?.date_end || null,
    distributed_at: r.distributed_at,
  }));

  // 2. Live-Beiträge: Items in Agreements ohne distribution-Snapshot
  const fixedProjectIds = new Set(fixedRows.map((r) => r.project_id));

  const { data: contribs } = await supabase
    .from("agreement_inventory_contributions")
    .select(
      `
      id, agreement_id, contributor_id, daily_rate, quantity,
      contributor:profiles!contributor_id(name),
      agreement:cooperation_agreements(
        id, status, profit_formula,
        project:projects(id, name, date_start, date_end, revenue_actual, profit_distribution_status)
      )
    `
    )
    .eq("inventory_item_id", itemId);

  // Kosten pro Projekt vorab laden
  const projectIds = (contribs || [])
    .map((c: any) => c.agreement?.project?.id)
    .filter(Boolean) as string[];
  const liveProjectIds = projectIds.filter((id) => !fixedProjectIds.has(id));

  const costsMap = new Map<string, number>();
  if (liveProjectIds.length > 0) {
    const { data: costs } = await supabase
      .from("cost_items")
      .select("project_id, amount_actual")
      .in("project_id", liveProjectIds);
    for (const c of costs || []) {
      costsMap.set(c.project_id, (costsMap.get(c.project_id) || 0) + Number(c.amount_actual || 0));
    }
  }

  // Pro Live-Projekt: alle Contributions des Agreements laden, um totalInventory zu kennen
  const contribsByAgreement = new Map<string, any[]>();
  if (liveProjectIds.length > 0) {
    const agreementIds = Array.from(
      new Set(
        (contribs || [])
          // deno-lint-ignore no-explicit-any
          .filter((c: any) => liveProjectIds.includes((c.agreement as any)?.project?.id))
          // deno-lint-ignore no-explicit-any
          .map((c: any) => c.agreement_id)
      )
    );
    if (agreementIds.length > 0) {
      const { data: allContribs } = await supabase
        .from("agreement_inventory_contributions")
        .select("agreement_id, daily_rate, quantity")
        .in("agreement_id", agreementIds);
      for (const ac of allContribs || []) {
        const arr = contribsByAgreement.get(ac.agreement_id) || [];
        arr.push(ac);
        contribsByAgreement.set(ac.agreement_id, arr);
      }
    }
  }

  const liveRows: EarningsRow[] = [];
  for (const c of (contribs || []) as any[]) {
    const agreement: any = c.agreement;
    const proj: any = agreement?.project;
    if (!proj) continue;
    if (fixedProjectIds.has(proj.id)) continue; // schon fixiert

    const formula: ProfitFormula | null = agreement?.profit_formula ?? null;
    const days = diffDays(proj.date_start, proj.date_end);
    const revenue = Number(proj.revenue_actual || 0);
    const costs = costsMap.get(proj.id) || 0;
    const preDed = preDeductionsTotal(formula);
    const netProfit = Math.max(0, revenue - costs - preDed);

    const dailyRate = Number(c.daily_rate);
    const qty = Number(c.quantity);
    const gross = dailyRate * qty * days;

    const allInAgreement = contribsByAgreement.get(c.agreement_id) || [];
    const totalInventory = allInAgreement.reduce(
      (s, x) => s + Number(x.daily_rate) * Number(x.quantity) * days,
      0
    );
    const share = totalInventory > 0 ? gross / totalInventory : 0;

    const w = formula?.weights || {};
    const weightSum =
      Number(w.hours || 0) +
      Number(w.inventory || 0) +
      Number(w.capital || 0) +
      Number(w.fixed || 0);
    const invWeight = Number(w.inventory || 0);

    let payout = 0;
    if (weightSum > 0 && invWeight > 0 && netProfit > 0) {
      payout = netProfit * (invWeight / weightSum) * share;
    }

    liveRows.push({
      item_id: itemId,
      project_id: proj.id,
      project_name: proj.name,
      agreement_id: c.agreement_id,
      contributor_id: c.contributor_id,
      contributor_name: (c.contributor as any)?.name ?? null,
      daily_rate: dailyRate,
      quantity: qty,
      project_days: days,
      gross_contribution: gross,
      share_of_inventory: share,
      owner_payout: Math.round(payout * 100) / 100,
      revenue,
      net_profit: netProfit,
      status: "live",
      distribution_status: proj.profit_distribution_status || null,
      date_start: proj.date_start,
      date_end: proj.date_end,
      distributed_at: null,
    });
  }

  return [...liveRows, ...fixedRows].sort((a, b) =>
    (b.date_start || "").localeCompare(a.date_start || "")
  );
}

export type ItemEarningsAggregate = {
  item_id: string;
  inventory_number: string;
  name: string;
  category: string;
  purchase_price: number | null;
  total_payout: number;
  total_gross: number;
  project_count: number;
  roi_percent: number | null; // bezogen auf Owner-Payout (was zurueckkam)
};

/**
 * Aggregat für die Auswertungs-UI.
 * source: items[] mit owner_profile_id/owner_group_id Filter VORHER vom Caller anwenden.
 */
export async function loadItemsEarningsAggregate(
  itemIds: string[]
): Promise<Map<string, { total_payout: number; total_gross: number; project_count: number }>> {
  const result = new Map<
    string,
    { total_payout: number; total_gross: number; project_count: number }
  >();
  if (itemIds.length === 0) return result;

  // Schritt für Schritt: viele Items haben oft kein Earning, daher
  // einfach pro Item loadItemEarnings — bei kleinem Inventar (< 200) ok.
  // Performance-Optimierung später via Materialized View denkbar.
  for (const id of itemIds) {
    const rows = await loadItemEarnings(id);
    const agg = rows.reduce(
      (acc, r) => {
        acc.total_payout += r.owner_payout;
        acc.total_gross += r.gross_contribution;
        return acc;
      },
      { total_payout: 0, total_gross: 0, project_count: rows.length }
    );
    if (agg.project_count > 0) {
      result.set(id, agg);
    }
  }
  return result;
}
