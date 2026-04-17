"use client";

// Profit-Tab V1 (Phase 6.5)
// =========================
// - Liest Revenue (project.revenue_actual) + Kosten (cost_items)
// - Wenn aktive Cooperation Agreement existiert: Anteile nach Formel berechnen
// - Sonst: Hinweis, dass eine Vereinbarung noetig ist

import { useState, useEffect, useCallback } from "react";
import Link from "next/link";
import { createClient } from "@/lib/supabase";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type {
  Project,
  CostItem,
  CooperationAgreement,
  AgreementRole,
  AgreementInventoryContribution,
} from "@/types/database";
import { calculateProfitShares, calculateNetProfit, formatFormulaLabel } from "@/lib/agreement-calc";
import {
  IconCosts,
  IconShield,
  IconSave,
} from "@/components/ui/icons";
import { showToast } from "@/hooks/use-toast";

interface TabProfitProps {
  project: Project;
  canEdit: boolean;
}

export function TabProfit({ project, canEdit }: TabProfitProps) {
  const supabase = createClient();
  const [revenue, setRevenue] = useState<string>(project.revenue_actual?.toString() || "");
  const [costs, setCosts] = useState<CostItem[]>([]);
  const [agreement, setAgreement] = useState<CooperationAgreement | null>(null);
  const [roles, setRoles] = useState<AgreementRole[]>([]);
  const [contributions, setContributions] = useState<AgreementInventoryContribution[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const loadAll = useCallback(async () => {
    const [costsRes, agreeRes] = await Promise.all([
      supabase.from("cost_items").select("*").eq("project_id", project.id),
      supabase.from("cooperation_agreements").select("*").eq("project_id", project.id).maybeSingle(),
    ]);
    if (costsRes.data) setCosts(costsRes.data as CostItem[]);
    if (agreeRes.data) {
      const ag = agreeRes.data as CooperationAgreement;
      setAgreement(ag);
      const [rolesRes, contribRes] = await Promise.all([
        supabase.from("agreement_roles").select("*, profiles(name, avatar_url)").eq("agreement_id", ag.id),
        supabase.from("agreement_inventory_contributions").select("*").eq("agreement_id", ag.id),
      ]);
      if (rolesRes.data) setRoles(rolesRes.data as AgreementRole[]);
      if (contribRes.data) setContributions(contribRes.data as AgreementInventoryContribution[]);
    } else {
      setAgreement(null);
      setRoles([]);
      setContributions([]);
    }
    setLoading(false);
  }, [supabase, project.id]);

  useEffect(() => {
    loadAll();
  }, [loadAll]);

  useRealtimeTable({ table: "cost_items", filter: { column: "project_id", value: project.id }, onDataChange: loadAll });
  useRealtimeTable({ table: "cooperation_agreements", filter: { column: "project_id", value: project.id }, onDataChange: loadAll });

  async function saveRevenue() {
    if (!canEdit) return;
    setSaving(true);
    const num = revenue === "" ? null : Number(revenue);
    await supabase.from("projects").update({ revenue_actual: num }).eq("id", project.id);
    setSaving(false);
    showToast("Revenue gespeichert", "success");
  }

  if (loading) {
    return <div className="py-8 text-center text-sm" style={{ color: "var(--color-muted-foreground)" }}>Lade...</div>;
  }

  // Berechnung
  const revenueNum = Number(revenue || 0);
  const totalCosts = costs.reduce(
    (sum, c) => sum + (c.exclude_from_profit ? 0 : Number(c.amount_actual ?? c.amount_planned ?? 0)),
    0
  );
  const grossProfit = revenueNum - totalCosts;

  // Projekt-Tage fuer Inventar-Berechnung
  let projectDays = 1;
  if (project.date_start && project.date_end) {
    const diff = (new Date(project.date_end).getTime() - new Date(project.date_start).getTime()) / 86400000;
    projectDays = Math.max(1, Math.round(diff) + 1);
  }

  const netProfit = agreement ? calculateNetProfit(revenueNum, totalCosts, agreement.profit_formula) : grossProfit;
  const shares = agreement
    ? calculateProfitShares(netProfit, roles, contributions, agreement.profit_formula, projectDays)
    : [];

  return (
    <div className="space-y-5">
      {/* Revenue */}
      <div className="rounded-xl p-5" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}>
        <h3 className="text-sm font-semibold mb-3" style={{ color: "var(--color-foreground)" }}>
          Einnahmen
        </h3>
        <div className="flex items-center gap-2">
          <input
            type="number"
            value={revenue}
            onChange={(e) => setRevenue(e.target.value)}
            disabled={!canEdit}
            placeholder="0.00"
            className="flex-1 px-3 py-2 rounded-lg text-sm"
            style={{
              background: "var(--color-muted)",
              border: "1px solid var(--color-border)",
              color: "var(--color-foreground)",
            }}
          />
          <span className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>€</span>
          {canEdit && (
            <button
              onClick={saveRevenue}
              disabled={saving}
              className="flex items-center gap-1 px-3 py-2 rounded-lg text-xs font-medium text-white"
              style={{ background: "var(--color-primary)" }}
            >
              <IconSave size={12} /> Speichern
            </button>
          )}
        </div>
      </div>

      {/* Profit Summary */}
      <div className="grid grid-cols-3 gap-3">
        <div className="rounded-lg p-4" style={{ background: "var(--color-muted)" }}>
          <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Einnahmen</div>
          <div className="text-lg font-bold" style={{ color: "var(--color-foreground)" }}>
            {revenueNum.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
          </div>
        </div>
        <div className="rounded-lg p-4" style={{ background: "var(--color-muted)" }}>
          <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Kosten</div>
          <div className="text-lg font-bold" style={{ color: "var(--color-foreground)" }}>
            {totalCosts.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
          </div>
        </div>
        <div
          className="rounded-lg p-4"
          style={{
            background: netProfit >= 0 ? "var(--color-success-light)" : "var(--color-destructive-light)",
          }}
        >
          <div className="text-xs" style={{ color: netProfit >= 0 ? "var(--color-success)" : "var(--color-destructive)" }}>
            Netto-Gewinn {agreement && agreement.profit_formula.pre_deductions?.length ? "(nach Vorab-Abzuegen)" : ""}
          </div>
          <div
            className="text-lg font-bold"
            style={{ color: netProfit >= 0 ? "var(--color-success)" : "var(--color-destructive)" }}
          >
            {netProfit.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
          </div>
        </div>
      </div>

      {/* Agreement-Status */}
      {!agreement && (
        <div
          className="rounded-xl p-6 flex items-start gap-3"
          style={{ background: "var(--color-warning-light)", border: "1px solid var(--color-warning)" }}
        >
          <IconShield size={20} style={{ color: "var(--color-warning)" }} />
          <div className="flex-1">
            <div className="text-sm font-semibold" style={{ color: "var(--color-warning)" }}>
              Keine Kooperationsvereinbarung
            </div>
            <p className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
              Schliesse zuerst eine Vereinbarung ab — sie definiert wer welchen Anteil am Gewinn bekommt.
            </p>
          </div>
        </div>
      )}

      {agreement && agreement.status !== "active" && (
        <div
          className="rounded-xl p-4"
          style={{ background: "var(--color-warning-light)", border: "1px solid var(--color-warning)" }}
        >
          <div className="text-sm font-medium" style={{ color: "var(--color-warning)" }}>
            Vereinbarung noch nicht aktiv (Status: {agreement.status})
          </div>
          <div className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
            Anteile werden erst verbindlich nachdem alle Beteiligten unterschrieben haben.
          </div>
        </div>
      )}

      {/* Anteile */}
      {agreement && shares.length > 0 && (
        <div
          className="rounded-xl p-5"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
        >
          <div className="flex items-center justify-between mb-3">
            <h3 className="text-sm font-semibold" style={{ color: "var(--color-foreground)" }}>
              Verteilung
            </h3>
            <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
              Formel: {formatFormulaLabel(agreement.profit_formula)}
            </span>
          </div>
          <div className="space-y-2">
            {shares.map((s) => (
              <div
                key={s.profile_id}
                className="flex items-center justify-between p-3 rounded-lg"
                style={{ background: "var(--color-muted)" }}
              >
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>
                    {s.name}
                  </div>
                  <div className="text-xs flex flex-wrap gap-2" style={{ color: "var(--color-muted-foreground)" }}>
                    {s.hours_contribution > 0 && <span>Stunden: {s.hours_contribution.toFixed(2)}€</span>}
                    {s.inventory_contribution > 0 && <span>Inventar: {s.inventory_contribution.toFixed(2)}€</span>}
                    {s.capital_contribution > 0 && <span>Kapital: {s.capital_contribution.toFixed(2)}€</span>}
                    {s.fixed_amount > 0 && <span>Festbetrag: {s.fixed_amount.toFixed(2)}€</span>}
                  </div>
                </div>
                <div className="text-right">
                  <div
                    className="text-lg font-bold"
                    style={{ color: s.calculated_amount >= 0 ? "var(--color-success)" : "var(--color-destructive)" }}
                  >
                    {s.calculated_amount.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                  </div>
                  <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    {s.percentage.toFixed(1)}%
                  </div>
                </div>
              </div>
            ))}
          </div>

          {/* Vorab-Abzuege */}
          {agreement.profit_formula.pre_deductions && agreement.profit_formula.pre_deductions.length > 0 && (
            <div className="mt-4 pt-3 border-t text-xs" style={{ borderColor: "var(--color-border)", color: "var(--color-muted-foreground)" }}>
              <div className="font-semibold mb-1">Vorab-Abzuege:</div>
              {agreement.profit_formula.pre_deductions.map((d, i) => (
                <div key={i}>· {d.label}: {d.amount.toFixed(2)}€</div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Empty State */}
      {!agreement && (
        <div className="text-center py-8">
          <IconCosts size={32} className="mx-auto mb-2" style={{ color: "var(--color-muted-foreground)", opacity: 0.5 }} />
          <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            Wechsel zum &quot;Vereinbarung&quot; Tab um eine Kooperationsvereinbarung zu erstellen.
          </p>
        </div>
      )}
    </div>
  );
}
