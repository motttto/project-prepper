"use client";

import { useEffect, useMemo, useState } from "react";
import { loadItemsEarningsAggregate } from "@/lib/inventory-earnings";
import type { InventoryItem } from "@/types/database";

type Row = {
  id: string;
  inventory_number: string;
  name: string;
  category: string;
  purchase_price: number | null;
  total_payout: number;
  total_gross: number;
  project_count: number;
  roi: number | null;
};

export function InventoryEarningsOverview({
  items,
  onClose,
}: {
  items: InventoryItem[];
  onClose: () => void;
}) {
  const [rows, setRows] = useState<Row[]>([]);
  const [loading, setLoading] = useState(true);
  const [sortBy, setSortBy] = useState<"payout" | "roi" | "projects">("payout");

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    loadItemsEarningsAggregate(items.map((i) => i.id)).then((agg) => {
      if (cancelled) return;
      const result: Row[] = items.map((i) => {
        const a = agg.get(i.id) || { total_payout: 0, total_gross: 0, project_count: 0 };
        const purchasePrice = i.purchase_price !== null ? Number(i.purchase_price) : null;
        const roi =
          purchasePrice && purchasePrice > 0 ? (a.total_payout / purchasePrice) * 100 : null;
        return {
          id: i.id,
          inventory_number: i.inventory_number,
          name: i.name,
          category: i.category,
          purchase_price: purchasePrice,
          total_payout: a.total_payout,
          total_gross: a.total_gross,
          project_count: a.project_count,
          roi,
        };
      });
      setRows(result);
      setLoading(false);
    });
    return () => {
      cancelled = true;
    };
  }, [items]);

  const sorted = useMemo(() => {
    return [...rows].sort((a, b) => {
      if (sortBy === "payout") return b.total_payout - a.total_payout;
      if (sortBy === "projects") return b.project_count - a.project_count;
      // ROI — items ohne purchase_price ans Ende
      if (a.roi === null && b.roi === null) return 0;
      if (a.roi === null) return 1;
      if (b.roi === null) return -1;
      return b.roi - a.roi;
    });
  }, [rows, sortBy]);

  const totals = useMemo(
    () => ({
      payout: rows.reduce((s, r) => s + r.total_payout, 0),
      gross: rows.reduce((s, r) => s + r.total_gross, 0),
      projects: rows.reduce((s, r) => s + r.project_count, 0),
      withEarnings: rows.filter((r) => r.project_count > 0).length,
    }),
    [rows]
  );

  // Top per Kategorie
  const perCategory = useMemo(() => {
    const map = new Map<string, number>();
    for (const r of rows) {
      map.set(r.category, (map.get(r.category) || 0) + r.total_payout);
    }
    return Array.from(map.entries())
      .filter(([, v]) => v > 0)
      .sort((a, b) => b[1] - a[1]);
  }, [rows]);

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ background: "rgba(0,0,0,0.5)" }}
      onClick={onClose}
    >
      <div
        className="w-full max-w-5xl max-h-[90vh] overflow-auto rounded-xl p-6"
        style={{ background: "var(--color-surface)" }}
        onClick={(e) => e.stopPropagation()}
      >
        <h2 className="text-lg font-bold mb-1">Inventar-Auswertung</h2>
        <p className="text-sm mb-5" style={{ color: "var(--color-muted-foreground)" }}>
          Gewinnbeiträge pro Item — kombiniert verteilte Erträge und laufende Live-Projekte.
        </p>

        {loading ? (
          <div className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            Berechne... (kann bei vielen Items mehrere Sekunden dauern)
          </div>
        ) : (
          <>
            {/* KPIs */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-5">
              <div className="rounded-lg p-3" style={{ background: "var(--color-muted)" }}>
                <div className="text-[10px] uppercase font-semibold" style={{ color: "var(--color-muted-foreground)" }}>
                  Gesamt-Owner-Anteil
                </div>
                <div className="text-base font-semibold mt-0.5">
                  {totals.payout.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                </div>
              </div>
              <div className="rounded-lg p-3" style={{ background: "var(--color-muted)" }}>
                <div className="text-[10px] uppercase font-semibold" style={{ color: "var(--color-muted-foreground)" }}>
                  Gesamt erwirtschaftet
                </div>
                <div className="text-base font-semibold mt-0.5">
                  {totals.gross.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                </div>
              </div>
              <div className="rounded-lg p-3" style={{ background: "var(--color-muted)" }}>
                <div className="text-[10px] uppercase font-semibold" style={{ color: "var(--color-muted-foreground)" }}>
                  Aktive Items
                </div>
                <div className="text-base font-semibold mt-0.5">
                  {totals.withEarnings} / {rows.length}
                </div>
              </div>
              <div className="rounded-lg p-3" style={{ background: "var(--color-muted)" }}>
                <div className="text-[10px] uppercase font-semibold" style={{ color: "var(--color-muted-foreground)" }}>
                  Projekt-Einsätze
                </div>
                <div className="text-base font-semibold mt-0.5">{totals.projects}</div>
              </div>
            </div>

            {/* Pro Kategorie */}
            {perCategory.length > 0 && (
              <div className="mb-5">
                <h3 className="text-sm font-semibold mb-2">Nach Kategorie</h3>
                <div className="space-y-1.5">
                  {perCategory.map(([cat, amount]) => {
                    const pct = totals.payout > 0 ? (amount / totals.payout) * 100 : 0;
                    return (
                      <div key={cat} className="flex items-center gap-2 text-xs">
                        <span className="w-24 flex-shrink-0">{cat}</span>
                        <div className="flex-1 rounded-full h-2 overflow-hidden" style={{ background: "var(--color-muted)" }}>
                          <div
                            className="h-full rounded-full"
                            style={{ background: "var(--color-primary)", width: `${pct}%` }}
                          />
                        </div>
                        <span className="w-32 text-right font-mono">
                          {amount.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                        </span>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}

            {/* Tabelle mit Sort */}
            <div className="flex items-center justify-between mb-2">
              <h3 className="text-sm font-semibold">Pro Item</h3>
              <div className="flex gap-1 rounded-lg p-1" style={{ background: "var(--color-muted)" }}>
                {(["payout", "roi", "projects"] as const).map((s) => (
                  <button
                    key={s}
                    onClick={() => setSortBy(s)}
                    className="px-2.5 py-1 rounded-md text-xs font-medium"
                    style={{
                      background: sortBy === s ? "var(--color-primary)" : "transparent",
                      color: sortBy === s ? "var(--color-primary-foreground)" : "var(--color-muted-foreground)",
                    }}
                  >
                    {s === "payout" ? "Owner-Anteil" : s === "roi" ? "ROI" : "Einsätze"}
                  </button>
                ))}
              </div>
            </div>

            <div className="overflow-x-auto rounded-lg" style={{ border: "1px solid var(--color-border)" }}>
              <table className="w-full text-xs">
                <thead style={{ background: "var(--color-muted)" }}>
                  <tr>
                    <th className="text-left px-3 py-2 font-medium">Inv.-Nr.</th>
                    <th className="text-left px-3 py-2 font-medium">Item</th>
                    <th className="text-left px-3 py-2 font-medium">Kategorie</th>
                    <th className="text-right px-3 py-2 font-medium">Einsätze</th>
                    <th className="text-right px-3 py-2 font-medium">Brutto</th>
                    <th className="text-right px-3 py-2 font-medium">Owner-Anteil</th>
                    <th className="text-right px-3 py-2 font-medium">ROI</th>
                  </tr>
                </thead>
                <tbody>
                  {sorted
                    .filter((r) => r.project_count > 0)
                    .map((r) => (
                      <tr key={r.id} style={{ borderTop: "1px solid var(--color-border)" }}>
                        <td className="px-3 py-2 font-mono">{r.inventory_number}</td>
                        <td className="px-3 py-2">{r.name}</td>
                        <td className="px-3 py-2" style={{ color: "var(--color-muted-foreground)" }}>
                          {r.category}
                        </td>
                        <td className="px-3 py-2 text-right">{r.project_count}</td>
                        <td className="px-3 py-2 text-right font-mono">
                          {r.total_gross.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                        </td>
                        <td className="px-3 py-2 text-right font-mono font-semibold">
                          {r.total_payout.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                        </td>
                        <td className="px-3 py-2 text-right font-mono">
                          {r.roi !== null ? `${r.roi.toFixed(0)}%` : "—"}
                        </td>
                      </tr>
                    ))}
                </tbody>
              </table>
              {sorted.filter((r) => r.project_count > 0).length === 0 && (
                <div className="p-6 text-center text-sm" style={{ color: "var(--color-muted-foreground)" }}>
                  Noch keine Erträge erfasst — sobald Items in Cooperation-Agreements eingebracht werden, erscheinen sie hier.
                </div>
              )}
            </div>
          </>
        )}

        <div className="flex justify-end mt-5">
          <button
            onClick={onClose}
            className="px-4 py-2 rounded-lg text-sm font-medium"
            style={{ border: "1px solid var(--color-border)" }}
          >
            Schließen
          </button>
        </div>
      </div>
    </div>
  );
}
