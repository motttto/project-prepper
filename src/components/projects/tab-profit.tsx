"use client";

import { useState, useCallback, useEffect } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type { Project, ProjectProfitShare, ShareType } from "@/types/database";
import { IconPlus, IconTrash, IconCheck, IconSave } from "@/components/ui/icons";

const shareTypeLabels: Record<ShareType, string> = {
  fixed: "Fixbetrag",
  percentage: "Prozent",
  hourly: "Stundensatz",
};

interface TabProfitProps {
  project: Project;
  canEdit: boolean;
}

export function TabProfit({ project, canEdit }: TabProfitProps) {
  const supabase = createClient();
  const { orgId } = useOrg();
  const currentUser = useCurrentUser();

  const [shares, setShares] = useState<ProjectProfitShare[]>([]);
  const [members, setMembers] = useState<{ id: string; name: string }[]>([]);
  const [revenue, setRevenue] = useState(project.revenue_actual ?? "");
  const [profit, setProfit] = useState<{ revenue: number; total_costs: number; profit: number } | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [addMemberId, setAddMemberId] = useState("");

  const loadShares = useCallback(async () => {
    const { data } = await supabase
      .from("project_profit_shares")
      .select("*, profiles:profile_id(name, avatar_url)")
      .eq("project_id", project.id)
      .order("created_at");
    if (data) setShares(data);
  }, [supabase, project.id]);

  const loadProfit = useCallback(async () => {
    const { data } = await supabase.rpc("calculate_project_profit", {
      p_project_id: project.id,
    });
    if (data && data.length > 0) {
      setProfit(data[0]);
    }
  }, [supabase, project.id]);

  const loadMembers = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("org_memberships")
      .select("profile_id, profiles(name)")
      .eq("org_id", orgId)
      .eq("is_active", true);
    if (data) {
      setMembers(data.map((m: any) => ({ id: m.profile_id, name: m.profiles?.name || "" })));
    }
    setLoading(false);
  }, [supabase, orgId]);

  useEffect(() => { loadShares(); loadProfit(); loadMembers(); }, [loadShares, loadProfit, loadMembers]);

  useRealtimeTable({ table: "project_profit_shares", onDataChange: loadShares });

  // Einnahmen speichern
  async function saveRevenue() {
    setSaving(true);
    await supabase
      .from("projects")
      .update({ revenue_actual: revenue !== "" ? Number(revenue) : null })
      .eq("id", project.id);
    await loadProfit();
    setSaving(false);
  }

  // Anteil hinzufügen
  async function addShare() {
    if (!addMemberId) return;
    await supabase.from("project_profit_shares").insert({
      project_id: project.id,
      profile_id: addMemberId,
      share_type: "percentage",
      share_value: shares.length === 0 ? 100 : 0,
    });
    setAddMemberId("");
    await loadShares();
  }

  // Gleichmäßig verteilen
  async function distributeEvenly() {
    if (shares.length === 0) return;
    const pct = Math.round(10000 / shares.length) / 100;
    for (const share of shares) {
      await supabase
        .from("project_profit_shares")
        .update({ share_type: "percentage", share_value: pct })
        .eq("id", share.id);
    }
    await loadShares();
    await recalculateAmounts();
  }

  // Beträge neuberechnen
  async function recalculateAmounts() {
    if (!profit) return;
    const profitAmount = profit.profit;
    for (const share of shares) {
      let amount = 0;
      if (share.share_type === "fixed") {
        amount = share.share_value;
      } else if (share.share_type === "percentage") {
        amount = profitAmount * (share.share_value / 100);
      } else if (share.share_type === "hourly" && share.hours_worked) {
        amount = share.share_value * share.hours_worked;
      }
      await supabase
        .from("project_profit_shares")
        .update({ calculated_amount: Math.round(amount * 100) / 100 })
        .eq("id", share.id);
    }
    await loadShares();
  }

  // Anteil updaten
  async function updateShare(shareId: string, field: string, value: any) {
    await supabase
      .from("project_profit_shares")
      .update({ [field]: value })
      .eq("id", shareId);
    await loadShares();
  }

  // Anteil löschen
  async function deleteShare(shareId: string) {
    await supabase.from("project_profit_shares").delete().eq("id", shareId);
    await loadShares();
  }

  // Als bezahlt markieren
  async function markPaid(shareId: string) {
    await supabase
      .from("project_profit_shares")
      .update({ is_paid: true, paid_at: new Date().toISOString() })
      .eq("id", shareId);
    await loadShares();
  }

  const inputStyle = {
    border: "1px solid var(--color-border)",
    background: "var(--color-background)",
  };

  const totalShared = shares.reduce((s, sh) => s + Number(sh.calculated_amount || 0), 0);
  const availableMembers = members.filter((m) => !shares.some((s) => s.profile_id === m.id));

  if (loading) {
    return <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>Lade...</p>;
  }

  return (
    <div className="space-y-6">
      {/* Finanzen-Übersicht */}
      <div className="grid grid-cols-3 gap-4">
        <div className="rounded-lg p-4" style={{ background: "var(--color-muted)" }}>
          <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Einnahmen</div>
          {canEdit ? (
            <div className="flex items-center gap-2 mt-1">
              <input
                type="number"
                value={revenue}
                onChange={(e) => setRevenue(e.target.value === "" ? "" : Number(e.target.value))}
                onBlur={saveRevenue}
                className="w-full px-2 py-1 rounded text-lg font-bold"
                style={inputStyle}
                placeholder="0,00"
                min={0}
                step={0.01}
              />
              <span className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>€</span>
            </div>
          ) : (
            <div className="text-lg font-bold mt-1">
              {revenue ? Number(revenue).toLocaleString("de-DE", { style: "currency", currency: "EUR" }) : "–"}
            </div>
          )}
        </div>
        <div className="rounded-lg p-4" style={{ background: "var(--color-muted)" }}>
          <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Kosten (Ist)</div>
          <div className="text-lg font-bold mt-1" style={{ color: "var(--color-error)" }}>
            {profit ? Number(profit.total_costs).toLocaleString("de-DE", { style: "currency", currency: "EUR" }) : "–"}
          </div>
        </div>
        <div
          className="rounded-lg p-4"
          style={{
            background: profit && profit.profit >= 0 ? "var(--color-success-light)" : "var(--color-error-light)",
          }}
        >
          <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Gewinn</div>
          <div
            className="text-lg font-bold mt-1"
            style={{ color: profit && profit.profit >= 0 ? "var(--color-success)" : "var(--color-error)" }}
          >
            {profit ? Number(profit.profit).toLocaleString("de-DE", { style: "currency", currency: "EUR" }) : "–"}
          </div>
        </div>
      </div>

      {/* Verteilung */}
      <div>
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-sm font-semibold">Gewinnverteilung</h3>
          <div className="flex gap-2">
            {shares.length > 0 && canEdit && (
              <>
                <button
                  onClick={distributeEvenly}
                  className="px-3 py-1.5 rounded-lg text-xs font-medium"
                  style={{ border: "1px solid var(--color-border)" }}
                >
                  Gleichmäßig
                </button>
                <button
                  onClick={recalculateAmounts}
                  className="px-3 py-1.5 rounded-lg text-xs font-medium text-white"
                  style={{ background: "var(--color-primary)" }}
                >
                  Neuberechnen
                </button>
              </>
            )}
          </div>
        </div>

        {/* Shares */}
        {shares.length === 0 ? (
          <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            Noch keine Gewinnverteilung angelegt. Füge Mitglieder hinzu.
          </p>
        ) : (
          <div className="space-y-2">
            {shares.map((share) => (
              <div
                key={share.id}
                className="flex items-center gap-3 px-3 py-2.5 rounded-lg"
                style={{ background: "var(--color-muted)", border: "1px solid var(--color-border-light)" }}
              >
                {/* Name */}
                <span className="text-sm font-medium min-w-[100px]">
                  {(share as any).profiles?.name || "–"}
                </span>

                {/* Typ */}
                {canEdit ? (
                  <select
                    value={share.share_type}
                    onChange={(e) => updateShare(share.id, "share_type", e.target.value)}
                    className="px-2 py-1 rounded text-xs"
                    style={inputStyle}
                  >
                    {Object.entries(shareTypeLabels).map(([k, v]) => (
                      <option key={k} value={k}>{v}</option>
                    ))}
                  </select>
                ) : (
                  <span className="text-xs px-2 py-1 rounded" style={{ background: "var(--color-surface)" }}>
                    {shareTypeLabels[share.share_type]}
                  </span>
                )}

                {/* Wert */}
                {canEdit ? (
                  <input
                    type="number"
                    value={share.share_value}
                    onChange={(e) => updateShare(share.id, "share_value", Number(e.target.value))}
                    className="w-20 px-2 py-1 rounded text-xs text-right"
                    style={inputStyle}
                    min={0}
                    step={share.share_type === "percentage" ? 1 : 0.01}
                  />
                ) : (
                  <span className="text-xs font-mono">{share.share_value}</span>
                )}
                <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                  {share.share_type === "percentage" ? "%" : "€"}
                  {share.share_type === "hourly" ? "/h" : ""}
                </span>

                {/* Stunden (nur bei hourly) */}
                {share.share_type === "hourly" && canEdit && (
                  <input
                    type="number"
                    value={share.hours_worked ?? ""}
                    onChange={(e) => updateShare(share.id, "hours_worked", e.target.value ? Number(e.target.value) : null)}
                    className="w-16 px-2 py-1 rounded text-xs"
                    style={inputStyle}
                    placeholder="Std."
                    min={0}
                    step={0.5}
                  />
                )}

                {/* Berechneter Betrag */}
                <span className="ml-auto text-sm font-bold" style={{ color: "var(--color-primary)" }}>
                  {Number(share.calculated_amount).toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                </span>

                {/* Bezahlt-Status */}
                {share.is_paid ? (
                  <span className="px-2 py-0.5 rounded-full text-xs" style={{ background: "var(--color-success-light)", color: "var(--color-success)" }}>
                    Bezahlt
                  </span>
                ) : canEdit ? (
                  <button
                    onClick={() => markPaid(share.id)}
                    className="p-1 rounded" style={{ color: "var(--color-success)" }}
                    title="Als bezahlt markieren"
                  >
                    <IconCheck size={14} />
                  </button>
                ) : (
                  <span className="px-2 py-0.5 rounded-full text-xs" style={{ background: "var(--color-warning-light)", color: "var(--color-warning)" }}>
                    Offen
                  </span>
                )}

                {/* Löschen */}
                {canEdit && (
                  <button
                    onClick={() => deleteShare(share.id)}
                    className="p-1 rounded" style={{ color: "var(--color-error)" }}
                  >
                    <IconTrash size={12} />
                  </button>
                )}
              </div>
            ))}

            {/* Summe */}
            <div
              className="flex justify-between items-center px-3 py-2 rounded-lg text-sm font-bold"
              style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
            >
              <span>Gesamt verteilt</span>
              <span style={{ color: totalShared <= (profit?.profit || 0) ? "var(--color-success)" : "var(--color-error)" }}>
                {totalShared.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                {profit && (
                  <span className="font-normal text-xs ml-2" style={{ color: "var(--color-muted-foreground)" }}>
                    von {Number(profit.profit).toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                  </span>
                )}
              </span>
            </div>
          </div>
        )}

        {/* Mitglied hinzufügen */}
        {canEdit && availableMembers.length > 0 && (
          <div className="flex gap-2 mt-3">
            <select
              value={addMemberId}
              onChange={(e) => setAddMemberId(e.target.value)}
              className="flex-1 px-3 py-2 rounded-lg text-sm"
              style={inputStyle}
            >
              <option value="">Mitglied hinzufügen...</option>
              {availableMembers.map((m) => (
                <option key={m.id} value={m.id}>{m.name}</option>
              ))}
            </select>
            <button
              onClick={addShare}
              disabled={!addMemberId}
              className="flex items-center gap-1 px-3 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
              style={{ background: "var(--color-primary)" }}
            >
              <IconPlus size={14} />
              Hinzufügen
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
