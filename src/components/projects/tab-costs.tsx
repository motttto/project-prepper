"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import type { Project, CostItem } from "@/types/database";
import { useRealtimeTable } from "@/hooks/use-realtime-table";

interface TabCostsProps {
  projectId: string;
  project: Project;
}

const categoryLabels: Record<CostItem["category"], string> = {
  personnel: "Personal",
  material: "Material",
  inventory: "Inventar",
  external: "Extern",
  other: "Sonstiges",
};

const categoryColors: Record<CostItem["category"], { bg: string; text: string }> = {
  personnel: { bg: "#dbeafe", text: "#1d4ed8" },
  material: { bg: "#fef3c7", text: "#b45309" },
  inventory: { bg: "#e0e7ff", text: "#4338ca" },
  external: { bg: "#fce7f3", text: "#be185d" },
  other: { bg: "#f3f4f6", text: "#374151" },
};

const vatOptions = [
  { value: 19, label: "19 %" },
  { value: 7, label: "7 %" },
  { value: 0, label: "0 % (befreit)" },
];

function fmtEur(n: number) {
  return n.toLocaleString("de-DE", { minimumFractionDigits: 2 });
}

export function TabCosts({ projectId, project }: TabCostsProps) {
  const supabase = createClient();
  const [costItems, setCostItems] = useState<CostItem[]>([]);
  const [loading, setLoading] = useState(true);

  // Form state
  const [showForm, setShowForm] = useState(false);
  const [formCategory, setFormCategory] = useState<CostItem["category"]>("material");
  const [formDescription, setFormDescription] = useState("");
  const [formPlanned, setFormPlanned] = useState("");
  const [formActual, setFormActual] = useState("");
  const [formVat, setFormVat] = useState(19);
  const [saving, setSaving] = useState(false);

  const loadCosts = useCallback(async () => {
    const { data } = await supabase
      .from("cost_items")
      .select("*")
      .eq("project_id", projectId)
      .order("created_at");
    if (data) setCostItems(data as CostItem[]);
    setLoading(false);
  }, [supabase, projectId]);

  useEffect(() => {
    loadCosts();
  }, [loadCosts]);

  // Realtime: Live-Synchronisation
  useRealtimeTable({
    table: "cost_items",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadCosts,
  });

  // ── Netto / USt / Brutto Berechnungen ──────────────────────────────────
  const totalNetPlanned = costItems.reduce((sum, item) => sum + Number(item.amount_planned), 0);
  const totalNetActual = costItems.reduce((sum, item) => sum + Number(item.amount_actual || 0), 0);
  const totalVatPlanned = costItems.reduce(
    (sum, item) => sum + Number(item.amount_planned) * (Number(item.vat_rate) / 100),
    0
  );
  const totalVatActual = costItems.reduce(
    (sum, item) => sum + Number(item.amount_actual || 0) * (Number(item.vat_rate) / 100),
    0
  );
  const totalBruttoPlanned = totalNetPlanned + totalVatPlanned;
  const totalBruttoActual = totalNetActual + totalVatActual;

  const budgetPlanned = project.budget_planned ? Number(project.budget_planned) : null;
  const budgetDiff = budgetPlanned !== null ? budgetPlanned - totalBruttoPlanned : null;
  const budgetPercent = budgetPlanned ? Math.min((totalBruttoPlanned / budgetPlanned) * 100, 100) : 0;
  const overBudget = budgetDiff !== null && budgetDiff < 0;

  // Budget-Kategorien
  const costByBudgetCategory = {
    honorar: costItems.filter((c) => c.category === "personnel").reduce((s, c) => s + Number(c.amount_planned) * (1 + Number(c.vat_rate) / 100), 0),
    technik: costItems.filter((c) => c.category === "inventory" || c.category === "material").reduce((s, c) => s + Number(c.amount_planned) * (1 + Number(c.vat_rate) / 100), 0),
    transport: costItems.filter((c) => c.category === "external").reduce((s, c) => s + Number(c.amount_planned) * (1 + Number(c.vat_rate) / 100), 0),
  };
  const budgetCategories = [
    { label: "Honorar", budget: project.budget_honorar ? Number(project.budget_honorar) : null, spent: costByBudgetCategory.honorar, color: "#3b82f6" },
    { label: "Technik", budget: project.budget_technik ? Number(project.budget_technik) : null, spent: costByBudgetCategory.technik, color: "#8b5cf6" },
    { label: "Transport / Reise", budget: project.budget_transport ? Number(project.budget_transport) : null, spent: costByBudgetCategory.transport, color: "#f59e0b" },
  ];

  async function handleCreateCost(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    const { error } = await supabase.from("cost_items").insert({
      project_id: projectId,
      category: formCategory,
      description: formDescription,
      amount_planned: parseFloat(formPlanned),
      amount_actual: formActual ? parseFloat(formActual) : null,
      vat_rate: formVat,
    });
    if (!error) {
      setFormCategory("material");
      setFormDescription("");
      setFormPlanned("");
      setFormActual("");
      setFormVat(19);
      setShowForm(false);
      loadCosts();
    }
    setSaving(false);
  }

  async function handleDeleteCost(id: string) {
    if (!confirm("Kostenposition wirklich löschen?")) return;
    await supabase.from("cost_items").delete().eq("id", id);
    loadCosts();
  }

  if (loading) {
    return (
      <div className="py-8 text-center" style={{ color: "var(--color-muted-foreground)" }}>
        Kosten werden geladen...
      </div>
    );
  }

  return (
    <div>
      {/* Budget Comparison Header */}
      {budgetPlanned !== null && (
        <div
          className="mb-4 p-4 rounded-lg"
          style={{
            border: "1px solid var(--color-border)",
            background: "var(--color-surface)",
          }}
        >
          <div className="flex items-center justify-between mb-3 text-sm">
            <span>
              <span style={{ color: "var(--color-muted-foreground)" }}>Budget: </span>
              <span className="font-semibold">{fmtEur(budgetPlanned)} €</span>
            </span>
            <span>
              <span style={{ color: "var(--color-muted-foreground)" }}>Kosten (Brutto): </span>
              <span className="font-semibold">{fmtEur(totalBruttoPlanned)} €</span>
            </span>
            <span>
              <span style={{ color: "var(--color-muted-foreground)" }}>Differenz: </span>
              <span
                className="font-semibold"
                style={{ color: overBudget ? "var(--color-destructive)" : "var(--color-success)" }}
              >
                {budgetDiff !== null
                  ? `${budgetDiff >= 0 ? "+" : ""}${fmtEur(budgetDiff)} €`
                  : "–"}
              </span>
            </span>
          </div>
          {/* Progress Bar */}
          <div className="w-full h-3 rounded-full overflow-hidden" style={{ background: "var(--color-muted)" }}>
            <div
              className="h-full rounded-full transition-all duration-300"
              style={{
                width: `${budgetPercent}%`,
                background: overBudget ? "var(--color-destructive)" : "var(--color-success)",
              }}
            />
          </div>
          <div className="flex justify-between mt-1">
            <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>0 €</span>
            <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
              {fmtEur(budgetPlanned)} €
            </span>
          </div>
        </div>
      )}

      {/* Per-Category Budget Breakdown */}
      {budgetCategories.some((c) => c.budget !== null) && (
        <div className="grid grid-cols-3 gap-3 mb-4">
          {budgetCategories.map((cat) => {
            if (cat.budget === null) return null;
            const pct = cat.budget > 0 ? Math.min((cat.spent / cat.budget) * 100, 100) : 0;
            const over = cat.spent > cat.budget;
            return (
              <div
                key={cat.label}
                className="p-3 rounded-lg"
                style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
              >
                <div className="flex items-center justify-between mb-1">
                  <span className="text-xs font-semibold" style={{ color: cat.color }}>{cat.label}</span>
                  <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    {fmtEur(cat.spent)} / {fmtEur(cat.budget)} €
                  </span>
                </div>
                <div className="w-full h-2 rounded-full overflow-hidden" style={{ background: "var(--color-muted)" }}>
                  <div
                    className="h-full rounded-full transition-all duration-300"
                    style={{ width: `${pct}%`, background: over ? "var(--color-destructive)" : cat.color }}
                  />
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Header Row */}
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-lg font-semibold">Kostenpositionen</h2>
        <button
          onClick={() => setShowForm(!showForm)}
          className="px-3 py-1.5 text-sm text-white rounded-lg transition-colors"
          style={{ background: "var(--color-primary)" }}
        >
          + Kostenposition
        </button>
      </div>

      {/* Create Form */}
      {showForm && (
        <div
          className="mb-4 p-5 rounded-lg"
          style={{
            border: "1px solid var(--color-border)",
            background: "var(--color-surface)",
          }}
        >
          <h3 className="font-medium mb-3">Neue Kostenposition</h3>
          <form onSubmit={handleCreateCost} className="space-y-3">
            <div className="grid grid-cols-3 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1">Kategorie</label>
                <select
                  value={formCategory}
                  onChange={(e) => setFormCategory(e.target.value as CostItem["category"])}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  required
                >
                  <option value="personnel">Personal</option>
                  <option value="material">Material</option>
                  <option value="inventory">Inventar</option>
                  <option value="external">Extern</option>
                  <option value="other">Sonstiges</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Beschreibung</label>
                <input
                  type="text"
                  value={formDescription}
                  onChange={(e) => setFormDescription(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="z.B. Lichttechniker Tagessatz"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">USt-Satz</label>
                <select
                  value={formVat}
                  onChange={(e) => setFormVat(Number(e.target.value))}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                >
                  {vatOptions.map((v) => (
                    <option key={v.value} value={v.value}>{v.label}</option>
                  ))}
                </select>
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1">Betrag Netto Soll (€)</label>
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  value={formPlanned}
                  onChange={(e) => setFormPlanned(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="0.00"
                  required
                />
                {formPlanned && formVat > 0 && (
                  <div className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                    Brutto: {fmtEur(parseFloat(formPlanned) * (1 + formVat / 100))} €
                  </div>
                )}
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Betrag Netto Ist (€)</label>
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  value={formActual}
                  onChange={(e) => setFormActual(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="0.00 (optional)"
                />
                {formActual && formVat > 0 && (
                  <div className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                    Brutto: {fmtEur(parseFloat(formActual) * (1 + formVat / 100))} €
                  </div>
                )}
              </div>
            </div>
            <div className="flex gap-2">
              <button type="submit" disabled={saving}
                className="px-4 py-2 text-sm text-white rounded-lg disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}>
                {saving ? "Wird gespeichert..." : "Hinzufügen"}
              </button>
              <button type="button" onClick={() => setShowForm(false)}
                className="px-4 py-2 text-sm rounded-lg"
                style={{ border: "1px solid var(--color-border)" }}>
                Abbrechen
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Costs Table */}
      {costItems.length === 0 ? (
        <div
          className="text-center py-8 rounded-lg"
          style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}
        >
          Noch keine Kostenpositionen erfasst
        </div>
      ) : (
        <div className="rounded-lg overflow-hidden" style={{ border: "1px solid var(--color-border)" }}>
          <table className="w-full text-sm">
            <thead>
              <tr style={{ background: "var(--color-muted)" }}>
                <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Kategorie</th>
                <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Beschreibung</th>
                <th className="text-right px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Netto Soll</th>
                <th className="text-center px-3 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>USt</th>
                <th className="text-right px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Brutto Soll</th>
                <th className="text-right px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Ist (Netto)</th>
                <th className="px-4 py-3 w-10" />
              </tr>
            </thead>
            <tbody>
              {costItems.map((item) => {
                const colors = categoryColors[item.category];
                const vatAmt = Number(item.amount_planned) * (Number(item.vat_rate) / 100);
                const brutto = Number(item.amount_planned) + vatAmt;
                return (
                  <tr key={item.id} style={{ borderTop: "1px solid var(--color-border)" }}>
                    <td className="px-4 py-3">
                      <span
                        className="inline-block text-xs font-medium px-2 py-1 rounded-full"
                        style={{ background: colors.bg, color: colors.text }}
                      >
                        {categoryLabels[item.category]}
                      </span>
                    </td>
                    <td className="px-4 py-3">{item.description}</td>
                    <td className="px-4 py-3 text-right font-mono">{fmtEur(Number(item.amount_planned))}</td>
                    <td className="px-3 py-3 text-center">
                      <span className="text-xs px-1.5 py-0.5 rounded" style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
                        {Number(item.vat_rate)} %
                      </span>
                    </td>
                    <td className="px-4 py-3 text-right font-mono font-medium">{fmtEur(brutto)}</td>
                    <td className="px-4 py-3 text-right font-mono">
                      {item.amount_actual !== null ? fmtEur(Number(item.amount_actual)) : "–"}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <button
                        onClick={() => handleDeleteCost(item.id)}
                        className="text-xs"
                        style={{ color: "var(--color-destructive)" }}
                      >
                        Löschen
                      </button>
                    </td>
                  </tr>
                );
              })}
              {/* Totals */}
              <tr style={{ borderTop: "2px solid var(--color-border)", background: "var(--color-muted)" }}>
                <td className="px-4 py-2 font-semibold" colSpan={2}>Netto</td>
                <td className="px-4 py-2 text-right font-mono font-semibold">{fmtEur(totalNetPlanned)}</td>
                <td />
                <td />
                <td className="px-4 py-2 text-right font-mono font-semibold">{fmtEur(totalNetActual)}</td>
                <td />
              </tr>
              <tr style={{ background: "var(--color-muted)" }}>
                <td className="px-4 py-2 font-semibold" colSpan={2}>USt</td>
                <td className="px-4 py-2 text-right font-mono" style={{ color: "var(--color-muted-foreground)" }}>{fmtEur(totalVatPlanned)}</td>
                <td />
                <td />
                <td className="px-4 py-2 text-right font-mono" style={{ color: "var(--color-muted-foreground)" }}>{fmtEur(totalVatActual)}</td>
                <td />
              </tr>
              <tr style={{ background: "var(--color-muted)" }}>
                <td className="px-4 py-3 font-bold text-base" colSpan={2}>Brutto</td>
                <td />
                <td />
                <td className="px-4 py-3 text-right font-mono font-bold text-base">{fmtEur(totalBruttoPlanned)}</td>
                <td className="px-4 py-3 text-right font-mono font-bold text-base">{fmtEur(totalBruttoActual)}</td>
                <td />
              </tr>
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
