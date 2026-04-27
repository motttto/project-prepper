"use client";

import { useEffect, useState } from "react";
import { loadItemEarnings, type EarningsRow } from "@/lib/inventory-earnings";

export function ItemEarningsSection({
  itemId,
  purchasePrice,
}: {
  itemId: string;
  purchasePrice: number | null;
}) {
  const [rows, setRows] = useState<EarningsRow[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    loadItemEarnings(itemId).then((r) => {
      if (!cancelled) {
        setRows(r);
        setLoading(false);
      }
    });
    return () => {
      cancelled = true;
    };
  }, [itemId]);

  const totalPayout = rows.reduce((s, r) => s + r.owner_payout, 0);
  const totalGross = rows.reduce((s, r) => s + r.gross_contribution, 0);
  const distributedPayout = rows
    .filter((r) => r.status === "distributed")
    .reduce((s, r) => s + r.owner_payout, 0);
  const livePayout = totalPayout - distributedPayout;
  const roi =
    purchasePrice && purchasePrice > 0
      ? (distributedPayout / purchasePrice) * 100
      : null;

  if (loading) {
    return (
      <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
        Lade Ertragshistorie...
      </div>
    );
  }

  if (rows.length === 0) {
    return (
      <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
        Dieses Item war noch in keinem Cooperation-Agreement eingebunden.
      </div>
    );
  }

  return (
    <div className="space-y-3">
      {/* Summary */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
        <div
          className="rounded-lg p-3"
          style={{ background: "var(--color-muted)" }}
        >
          <div className="text-[10px] uppercase font-semibold" style={{ color: "var(--color-muted-foreground)" }}>
            Gesamt erwirtschaftet
          </div>
          <div className="text-base font-semibold mt-0.5">
            {totalGross.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
          </div>
        </div>
        <div
          className="rounded-lg p-3"
          style={{ background: "var(--color-muted)" }}
        >
          <div className="text-[10px] uppercase font-semibold" style={{ color: "var(--color-muted-foreground)" }}>
            Owner-Anteil (verteilt)
          </div>
          <div className="text-base font-semibold mt-0.5">
            {distributedPayout.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
          </div>
        </div>
        <div
          className="rounded-lg p-3"
          style={{ background: "var(--color-muted)" }}
        >
          <div className="text-[10px] uppercase font-semibold" style={{ color: "var(--color-muted-foreground)" }}>
            Voraussichtlich offen
          </div>
          <div className="text-base font-semibold mt-0.5">
            {livePayout.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
          </div>
        </div>
        <div
          className="rounded-lg p-3"
          style={{ background: "var(--color-muted)" }}
        >
          <div className="text-[10px] uppercase font-semibold" style={{ color: "var(--color-muted-foreground)" }}>
            ROI {purchasePrice ? `(${purchasePrice.toLocaleString("de-DE", { style: "currency", currency: "EUR" })})` : ""}
          </div>
          <div className="text-base font-semibold mt-0.5">
            {roi !== null ? `${roi.toFixed(0)}%` : "—"}
          </div>
        </div>
      </div>

      {/* Tabelle */}
      <div className="overflow-x-auto rounded-lg" style={{ border: "1px solid var(--color-border)" }}>
        <table className="w-full text-xs">
          <thead style={{ background: "var(--color-muted)" }}>
            <tr>
              <th className="text-left px-3 py-2 font-medium">Projekt</th>
              <th className="text-left px-3 py-2 font-medium">Zeitraum</th>
              <th className="text-right px-3 py-2 font-medium">Tagessatz × Tage</th>
              <th className="text-right px-3 py-2 font-medium">Brutto</th>
              <th className="text-right px-3 py-2 font-medium">Owner-Anteil</th>
              <th className="text-left px-3 py-2 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={`${r.project_id}-${r.status}`} style={{ borderTop: "1px solid var(--color-border)" }}>
                <td className="px-3 py-2 font-medium">{r.project_name}</td>
                <td className="px-3 py-2" style={{ color: "var(--color-muted-foreground)" }}>
                  {r.date_start
                    ? `${new Date(r.date_start).toLocaleDateString("de-DE")} (${r.project_days}d)`
                    : "—"}
                </td>
                <td className="px-3 py-2 text-right font-mono" style={{ color: "var(--color-muted-foreground)" }}>
                  {r.daily_rate.toFixed(0)}€ × {r.quantity} × {r.project_days}d
                </td>
                <td className="px-3 py-2 text-right">
                  {r.gross_contribution.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                </td>
                <td className="px-3 py-2 text-right font-semibold">
                  {r.owner_payout.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                </td>
                <td className="px-3 py-2">
                  <span
                    className="inline-block px-2 py-0.5 rounded text-[10px] font-medium"
                    style={{
                      background: r.status === "distributed" ? "#dcfce7" : "#fef3c7",
                      color: r.status === "distributed" ? "#166534" : "#92400e",
                    }}
                  >
                    {r.status === "distributed" ? "Verteilt" : "Live"}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p className="text-[11px]" style={{ color: "var(--color-muted-foreground)" }}>
        Live-Werte sind aus aktuellem Revenue/Kosten/Formel berechnet. Verteilt = bei Projektabschluss
        in der DB fixiert.
      </p>
    </div>
  );
}
