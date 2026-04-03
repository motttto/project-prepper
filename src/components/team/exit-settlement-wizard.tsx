"use client";

import { useState, useCallback, useEffect } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import { useCurrentUser } from "@/hooks/use-current-user";
import type { ExitCalculation, SettlementAction } from "@/types/database";
import { IconX } from "@/components/ui/icons";

const actionLabels: Record<SettlementAction, { label: string; desc: string }> = {
  buyout: { label: "Auskauf", desc: "Organisation übernimmt zum aktuellen Wert" },
  return: { label: "Mitnahme", desc: "Mitglied nimmt Equipment mit" },
  keep: { label: "Verbleib", desc: "Bleibt bei Organisation (kein Ausgleich)" },
  donate: { label: "Schenkung", desc: "Mitglied schenkt der Organisation" },
};

const conditionLabels: Record<string, string> = {
  new: "Neu", good: "Gut", fair: "Befriedigend", poor: "Schlecht", broken: "Defekt", retired: "Ausgemustert",
};

interface ExitSettlementWizardProps {
  memberId: string;
  memberName: string;
  onClose: () => void;
  onComplete: () => void;
}

export function ExitSettlementWizard({
  memberId,
  memberName,
  onClose,
  onComplete,
}: ExitSettlementWizardProps) {
  const supabase = createClient();
  const { orgId } = useOrg();
  const currentUser = useCurrentUser();

  const [step, setStep] = useState(1);
  const [loading, setLoading] = useState(true);
  const [calc, setCalc] = useState<ExitCalculation | null>(null);
  const [actions, setActions] = useState<Record<string, SettlementAction>>({});
  const [notes, setNotes] = useState("");
  const [saving, setSaving] = useState(false);

  // Berechnung laden
  const loadCalculation = useCallback(async () => {
    if (!orgId) return;
    setLoading(true);
    const { data, error } = await supabase.rpc("calculate_exit_settlement", {
      p_org_id: orgId,
      p_profile_id: memberId,
    });
    if (data && !error) {
      setCalc(data as ExitCalculation);
      // Defaults setzen
      const defaults: Record<string, SettlementAction> = {};
      (data as ExitCalculation).owned_items?.forEach((item) => {
        defaults[item.id] = "return"; // Eigene Items: mitnehmen
      });
      (data as ExitCalculation).funded_items?.forEach((item) => {
        defaults[item.id] = "buyout"; // Finanzierte Org-Items: auskaufen
      });
      setActions(defaults);
    }
    setLoading(false);
  }, [supabase, orgId, memberId]);

  useEffect(() => { loadCalculation(); }, [loadCalculation]);

  // Gesamtauszahlung berechnen
  function calculatePayout(): number {
    if (!calc) return 0;
    let total = 0;
    calc.owned_items?.forEach((item) => {
      const action = actions[item.id] || "return";
      if (action === "return") {
        // Mitglied nimmt mit → kein Geld
      } else if (action === "buyout") {
        total += item.current_value || 0; // Org zahlt
      }
      // keep/donate → kein Geld
    });
    calc.funded_items?.forEach((item) => {
      const action = actions[item.id] || "buyout";
      if (action === "buyout") {
        total += item.current_value || 0; // Org erstattet
      }
      // return/keep/donate → verschiedene Logik
    });
    return total;
  }

  // Settlement erstellen + Beschluss
  async function handleFinalize() {
    if (!orgId || !currentUser || !calc) return;
    setSaving(true);

    const payout = calculatePayout();

    // 1. Beschluss erstellen
    const { data: decision } = await supabase
      .from("org_decisions")
      .insert({
        org_id: orgId,
        title: `Austritt: ${memberName}`,
        description: `Auslöse-Berechnung: ${payout.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}. ${calc.owned_items.length + calc.funded_items.length} Items betroffen.`,
        decision_type: "exit_settlement",
        requires_unanimous: true,
        related_profile_id: memberId,
        created_by: currentUser.id,
      })
      .select("id")
      .single();

    // 2. Settlement erstellen
    const { data: settlement } = await supabase
      .from("exit_settlements")
      .insert({
        org_id: orgId,
        profile_id: memberId,
        status: "pending_approval",
        decision_id: decision?.id || null,
        total_owned_value: calc.total_owned_value,
        total_funded_value: calc.total_funded_value,
        total_payout: payout,
        settlement_method: "contribution_based",
        notes: notes || null,
        created_by: currentUser.id,
      })
      .select("id")
      .single();

    // 3. Items erstellen
    if (settlement?.id) {
      const allItems = [
        ...calc.owned_items.map((item) => ({
          settlement_id: settlement.id,
          inventory_item_id: item.id,
          ownership_type: "owner",
          current_value: item.current_value || 0,
          action: actions[item.id] || "return",
        })),
        ...calc.funded_items.map((item) => ({
          settlement_id: settlement.id,
          inventory_item_id: item.id,
          ownership_type: "funder",
          current_value: item.current_value || 0,
          action: actions[item.id] || "buyout",
        })),
      ];
      if (allItems.length > 0) {
        await supabase.from("exit_settlement_items").insert(allItems);
      }
    }

    setSaving(false);
    onComplete();
  }

  const inputStyle = {
    border: "1px solid var(--color-border)",
    background: "var(--color-background)",
  };

  const payout = calculatePayout();

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ background: "rgba(0,0,0,0.5)" }}
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div
        className="w-full max-w-2xl rounded-xl overflow-hidden flex flex-col"
        style={{ background: "var(--color-surface)", boxShadow: "var(--shadow-lg)", maxHeight: "85vh" }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between px-6 py-4"
          style={{ borderBottom: "1px solid var(--color-border-light)" }}
        >
          <div>
            <h2 className="text-lg font-bold">Austritt: {memberName}</h2>
            <div className="flex gap-2 mt-1">
              {[1, 2, 3].map((s) => (
                <div
                  key={s}
                  className="h-1.5 rounded-full flex-1"
                  style={{
                    background: s <= step ? "var(--color-primary)" : "var(--color-border)",
                  }}
                />
              ))}
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 rounded-lg" style={{ color: "var(--color-muted-foreground)" }}>
            <IconX size={20} />
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto px-6 py-5">
          {loading ? (
            <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>Berechne Auslöse...</p>
          ) : !calc ? (
            <p className="text-sm" style={{ color: "var(--color-error)" }}>Fehler bei der Berechnung.</p>
          ) : (
            <>
              {/* Schritt 1: Übersicht */}
              {step === 1 && (
                <div className="space-y-4">
                  <h3 className="text-sm font-semibold">Automatische Aufstellung</h3>

                  {/* Summen-Karten */}
                  <div className="grid grid-cols-3 gap-3">
                    <div className="rounded-lg p-3" style={{ background: "var(--color-muted)" }}>
                      <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Eigene Items</div>
                      <div className="text-lg font-bold">{calc.owned_items.length}</div>
                      <div className="text-xs font-medium" style={{ color: "var(--color-primary)" }}>
                        {Number(calc.total_owned_value).toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                      </div>
                    </div>
                    <div className="rounded-lg p-3" style={{ background: "var(--color-muted)" }}>
                      <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Eigenfinanziert (Org)</div>
                      <div className="text-lg font-bold">{calc.funded_items.length}</div>
                      <div className="text-xs font-medium" style={{ color: "var(--color-primary)" }}>
                        {Number(calc.total_funded_value).toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                      </div>
                    </div>
                    <div className="rounded-lg p-3" style={{ background: "var(--color-primary)", color: "#fff" }}>
                      <div className="text-xs opacity-80">Gesamt-Auslöse</div>
                      <div className="text-lg font-bold">
                        {Number(calc.total_settlement).toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                      </div>
                      <div className="text-xs opacity-80">max. Anspruch</div>
                    </div>
                  </div>

                  {/* Items-Liste */}
                  {calc.owned_items.length > 0 && (
                    <div>
                      <h4 className="text-xs font-semibold mb-2" style={{ color: "var(--color-muted-foreground)" }}>
                        Eigentum von {memberName}
                      </h4>
                      {calc.owned_items.map((item) => (
                        <div key={item.id} className="flex justify-between items-center py-1.5 text-xs"
                          style={{ borderBottom: "1px solid var(--color-border-light)" }}>
                          <span>{item.name} <span style={{ color: "var(--color-muted-foreground)" }}>({item.inventory_number})</span></span>
                          <div className="flex items-center gap-3">
                            <span className="px-1.5 py-0.5 rounded text-xs" style={{ background: "var(--color-muted)" }}>
                              {conditionLabels[item.condition] || item.condition}
                            </span>
                            <span className="font-medium">
                              {item.current_value != null
                                ? Number(item.current_value).toLocaleString("de-DE", { style: "currency", currency: "EUR" })
                                : "–"}
                            </span>
                          </div>
                        </div>
                      ))}
                    </div>
                  )}

                  {calc.funded_items.length > 0 && (
                    <div>
                      <h4 className="text-xs font-semibold mb-2" style={{ color: "var(--color-muted-foreground)" }}>
                        Eigenfinanziert für Organisation
                      </h4>
                      {calc.funded_items.map((item) => (
                        <div key={item.id} className="flex justify-between items-center py-1.5 text-xs"
                          style={{ borderBottom: "1px solid var(--color-border-light)" }}>
                          <span>{item.name} <span style={{ color: "var(--color-muted-foreground)" }}>({item.inventory_number})</span></span>
                          <span className="font-medium">
                            {item.current_value != null
                              ? Number(item.current_value).toLocaleString("de-DE", { style: "currency", currency: "EUR" })
                              : "–"}
                          </span>
                        </div>
                      ))}
                    </div>
                  )}

                  {calc.owned_items.length === 0 && calc.funded_items.length === 0 && (
                    <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
                      Keine zugeordneten Items gefunden. Auslöse: 0 €
                    </p>
                  )}
                </div>
              )}

              {/* Schritt 2: Aktionen pro Item */}
              {step === 2 && (
                <div className="space-y-4">
                  <h3 className="text-sm font-semibold">Aktion pro Item festlegen</h3>
                  <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    Für jedes Item: Was soll beim Austritt passieren?
                  </p>

                  {[...calc.owned_items, ...calc.funded_items].map((item) => {
                    const isOwned = calc.owned_items.some((o) => o.id === item.id);
                    return (
                      <div
                        key={item.id}
                        className="rounded-lg p-3"
                        style={{ background: "var(--color-muted)", border: "1px solid var(--color-border-light)" }}
                      >
                        <div className="flex justify-between items-center mb-2">
                          <div>
                            <span className="text-sm font-medium">{item.name}</span>
                            <span className="ml-2 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                              {item.inventory_number} · {isOwned ? "Eigentum" : "Eigenfinanziert"}
                            </span>
                          </div>
                          <span className="text-sm font-bold" style={{ color: "var(--color-primary)" }}>
                            {item.current_value != null
                              ? Number(item.current_value).toLocaleString("de-DE", { style: "currency", currency: "EUR" })
                              : "–"}
                          </span>
                        </div>
                        <div className="flex gap-2">
                          {(Object.entries(actionLabels) as [SettlementAction, { label: string }][]).map(([key, { label }]) => (
                            <button
                              key={key}
                              onClick={() => setActions((prev) => ({ ...prev, [item.id]: key }))}
                              className="px-3 py-1.5 rounded-lg text-xs font-medium transition-all"
                              style={{
                                background: actions[item.id] === key ? "var(--color-primary)" : "var(--color-surface)",
                                color: actions[item.id] === key ? "#fff" : "var(--color-muted-foreground)",
                                border: actions[item.id] === key ? "none" : "1px solid var(--color-border)",
                              }}
                            >
                              {label}
                            </button>
                          ))}
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}

              {/* Schritt 3: Zusammenfassung + Beschluss */}
              {step === 3 && (
                <div className="space-y-4">
                  <h3 className="text-sm font-semibold">Zusammenfassung &amp; Beschluss</h3>

                  {/* Auszahlungssumme */}
                  <div
                    className="rounded-lg p-4 text-center"
                    style={{ background: "var(--color-primary)", color: "#fff" }}
                  >
                    <div className="text-xs opacity-80 mb-1">Auszahlung an {memberName}</div>
                    <div className="text-2xl font-bold">
                      {payout.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                    </div>
                  </div>

                  {/* Aktionen-Übersicht */}
                  <div className="space-y-1">
                    {[...calc.owned_items, ...calc.funded_items].map((item) => {
                      const action = actions[item.id];
                      return (
                        <div key={item.id} className="flex justify-between text-xs py-1"
                          style={{ borderBottom: "1px solid var(--color-border-light)" }}>
                          <span>{item.name}</span>
                          <span className="font-medium">{actionLabels[action]?.label || "–"}</span>
                        </div>
                      );
                    })}
                  </div>

                  {/* Notizen */}
                  <div>
                    <label className="block text-xs font-medium mb-1.5" style={{ color: "var(--color-muted-foreground)" }}>
                      Notizen (optional)
                    </label>
                    <textarea
                      value={notes}
                      onChange={(e) => setNotes(e.target.value)}
                      className="w-full px-3 py-2 rounded-lg text-sm"
                      style={inputStyle}
                      rows={2}
                      placeholder="Zusätzliche Vereinbarungen..."
                    />
                  </div>

                  <div
                    className="rounded-lg p-3 text-xs"
                    style={{ background: "var(--color-warning-light)", color: "var(--color-warning)" }}
                  >
                    Mit &quot;Beschluss erstellen&quot; wird ein Abstimmungs-Beschluss erstellt, dem alle aktiven Mitglieder zustimmen müssen.
                  </div>
                </div>
              )}
            </>
          )}
        </div>

        {/* Footer */}
        <div
          className="flex items-center justify-between px-6 py-4"
          style={{ borderTop: "1px solid var(--color-border-light)" }}
        >
          <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            Schritt {step} von 3
          </div>
          <div className="flex gap-2">
            {step > 1 && (
              <button
                onClick={() => setStep(step - 1)}
                className="px-4 py-2 rounded-lg text-sm font-medium"
                style={{ border: "1px solid var(--color-border)" }}
              >
                Zurück
              </button>
            )}
            {step < 3 ? (
              <button
                onClick={() => setStep(step + 1)}
                disabled={loading}
                className="px-4 py-2 rounded-lg text-sm font-medium text-white"
                style={{ background: "var(--color-primary)" }}
              >
                Weiter
              </button>
            ) : (
              <button
                onClick={handleFinalize}
                disabled={saving}
                className="px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
                style={{ background: "var(--color-success)" }}
              >
                {saving ? "Wird erstellt..." : "Beschluss erstellen"}
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
