"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import { useCurrentUser } from "@/hooks/use-current-user";
import type { InventoryItem, ProfitFormula, ExitRules, ProjectMember } from "@/types/database";
import { IconX, IconCheck, IconChevronRight, IconChevronLeft, IconPlus, IconTrash } from "@/components/ui/icons";
import { showToast } from "@/hooks/use-toast";

interface AgreementWizardProps {
  projectId: string;
  onClose: () => void;
  onCreated: () => void;
}

type Step = 1 | 2 | 3 | 4;

interface RoleDraft {
  profile_id: string;
  name: string;
  role_title: string;
  responsibilities: string;
  hourly_rate: number;
  hours_estimate: number;
  capital_contribution: number;
  fixed_amount: number;
}

interface ContributionDraft {
  id: string; // temp
  inventory_item_id: string;
  contributor_id: string;
  daily_rate: number;
  quantity: number;
  notes: string;
}

export function AgreementWizard({ projectId, onClose, onCreated }: AgreementWizardProps) {
  const supabase = createClient();
  const { orgId } = useOrg();
  const currentUser = useCurrentUser();

  const [step, setStep] = useState<Step>(1);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  // Schritt 1: Beteiligte (projekt_members geladen)
  const [members, setMembers] = useState<Array<{ profile_id: string; name: string; role: string }>>([]);

  // Schritt 2: Inventar
  const [inventory, setInventory] = useState<InventoryItem[]>([]);
  const [contributions, setContributions] = useState<ContributionDraft[]>([]);

  // Schritt 3: Rollen
  const [roles, setRoles] = useState<RoleDraft[]>([]);

  // Schritt 4: Formel + Exit + Freitext
  const [formula, setFormula] = useState<ProfitFormula>({
    method: "mixed",
    weights: { hours: 0.5, inventory: 0.3, capital: 0.1, fixed: 0.1 },
  });
  const [exitRules, setExitRules] = useState<ExitRules>({
    forfeit_if_exit_before_event: false,
    inventory_return_window_days: 7,
  });
  const [additionalTerms, setAdditionalTerms] = useState("");

  const loadData = useCallback(async () => {
    if (!orgId) return;
    const [membersRes, invRes] = await Promise.all([
      supabase
        .from("project_members")
        .select("profile_id, role, profiles(name)")
        .eq("project_id", projectId),
      supabase
        .from("inventory_items")
        .select("id, name, inventory_number, cost_per_day")
        .eq("org_id", orgId),
    ]);

    if (membersRes.data) {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const m = membersRes.data.map((x: any) => ({
        profile_id: x.profile_id,
        name: x.profiles?.name || "Unbekannt",
        role: x.role,
      }));
      setMembers(m);
      // Initiale Rollen-Entwürfe
      setRoles(m.map((x: { profile_id: string; name: string; role: string }) => ({
        profile_id: x.profile_id,
        name: x.name,
        role_title: x.role === "owner" ? "Projektleitung" : "Mitwirkende:r",
        responsibilities: "",
        hourly_rate: 50,
        hours_estimate: 0,
        capital_contribution: 0,
        fixed_amount: 0,
      })));
    }
    if (invRes.data) setInventory(invRes.data as InventoryItem[]);
  }, [supabase, orgId, projectId]);

  useEffect(() => { loadData(); }, [loadData]);

  const addContribution = () => {
    if (inventory.length === 0 || members.length === 0) return;
    setContributions([
      ...contributions,
      {
        id: `tmp-${Date.now()}`,
        inventory_item_id: inventory[0].id,
        contributor_id: members[0].profile_id,
        daily_rate: inventory[0].cost_per_day || 0,
        quantity: 1,
        notes: "",
      },
    ]);
  };

  const removeContribution = (id: string) => {
    setContributions(contributions.filter((c) => c.id !== id));
  };

  const updateContribution = <K extends keyof ContributionDraft>(id: string, key: K, value: ContributionDraft[K]) => {
    setContributions(contributions.map((c) => (c.id === id ? { ...c, [key]: value } : c)));
  };

  const updateRole = <K extends keyof RoleDraft>(profileId: string, key: K, value: RoleDraft[K]) => {
    setRoles(roles.map((r) => (r.profile_id === profileId ? { ...r, [key]: value } : r)));
  };

  const updateWeight = (key: "hours" | "inventory" | "capital" | "fixed", value: number) => {
    setFormula({
      ...formula,
      weights: { ...(formula.weights || {}), [key]: value / 100 },
    });
  };

  const weightSum = (formula.weights?.hours || 0) + (formula.weights?.inventory || 0) + (formula.weights?.capital || 0) + (formula.weights?.fixed || 0);
  const weightSumPct = Math.round(weightSum * 100);

  const canProceed = () => {
    if (step === 1) return members.length > 0;
    if (step === 2) return true; // Inventar ist optional
    if (step === 3) return roles.every((r) => r.role_title.trim().length > 0);
    if (step === 4) return Math.abs(weightSumPct - 100) < 1;
    return true;
  };

  const handleSave = async () => {
    if (!orgId || !currentUser) return;
    setSaving(true);
    setError("");

    try {
      // 1. Agreement anlegen
      const { data: agreement, error: agreeError } = await supabase
        .from("cooperation_agreements")
        .insert({
          project_id: projectId,
          org_id: orgId,
          status: "signing",
          profit_formula: formula,
          exit_rules: exitRules,
          additional_terms: additionalTerms.trim() || null,
          created_by: currentUser.id,
        })
        .select("id")
        .single();

      if (agreeError || !agreement) throw new Error(agreeError?.message || "Konnte Vereinbarung nicht anlegen");

      // 2. Rollen
      if (roles.length > 0) {
        const rolesInsert = roles.map((r) => ({
          agreement_id: agreement.id,
          profile_id: r.profile_id,
          role_title: r.role_title,
          responsibilities: r.responsibilities || null,
          hourly_rate: r.hourly_rate,
          hours_estimate: r.hours_estimate,
          capital_contribution: r.capital_contribution,
          fixed_amount: r.fixed_amount,
        }));
        const { error: rolesError } = await supabase.from("agreement_roles").insert(rolesInsert);
        if (rolesError) throw new Error("Rollen: " + rolesError.message);
      }

      // 3. Inventar-Beiträge
      if (contributions.length > 0) {
        const contribInsert = contributions.map((c) => ({
          agreement_id: agreement.id,
          inventory_item_id: c.inventory_item_id,
          contributor_id: c.contributor_id,
          daily_rate: c.daily_rate,
          quantity: c.quantity,
          notes: c.notes || null,
        }));
        const { error: contribError } = await supabase.from("agreement_inventory_contributions").insert(contribInsert);
        if (contribError) throw new Error("Inventar: " + contribError.message);
      }

      // 4. Signatur-Platzhalter für jeden Beteiligten anlegen
      const sigInsert = members.map((m) => ({
        agreement_id: agreement.id,
        profile_id: m.profile_id,
      }));
      await supabase.from("agreement_signatures").insert(sigInsert);

      showToast("Vereinbarung erstellt. Alle Beteiligten müssen jetzt signieren.", "success");
      onCreated();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  const inputStyle = {
    background: "var(--color-muted)",
    color: "var(--color-foreground)",
    border: "1px solid var(--color-border)",
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: "rgba(0,0,0,0.5)" }}>
      <div
        className="w-full max-w-2xl rounded-xl shadow-2xl max-h-[90vh] overflow-y-auto"
        style={{ background: "var(--color-surface)" }}
      >
        {/* Header */}
        <div className="flex items-center justify-between p-5 border-b sticky top-0 z-10" style={{ background: "var(--color-surface)", borderColor: "var(--color-border)" }}>
          <div>
            <h2 className="text-lg font-semibold" style={{ color: "var(--color-foreground)" }}>
              Kooperationsvereinbarung — Schritt {step} von 4
            </h2>
            <p className="text-xs mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>
              {step === 1 && "Beteiligte prüfen"}
              {step === 2 && "Inventar-Beiträge"}
              {step === 3 && "Rollen & Arbeitsanteile"}
              {step === 4 && "Gewinnverteilung & Regeln"}
            </p>
          </div>
          <button onClick={onClose} className="p-1 rounded-md hover:opacity-70">
            <IconX size={20} />
          </button>
        </div>

        {/* Progress */}
        <div className="px-5 pt-3">
          <div className="flex gap-1">
            {[1, 2, 3, 4].map((n) => (
              <div
                key={n}
                className="flex-1 h-1 rounded-full"
                style={{ background: n <= step ? "var(--color-primary)" : "var(--color-muted)" }}
              />
            ))}
          </div>
        </div>

        {/* Body */}
        <div className="p-5 space-y-5">
          {/* SCHRITT 1: Beteiligte */}
          {step === 1 && (
            <div className="space-y-3">
              <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
                Diese Projekt-Mitglieder müssen die Vereinbarung unterschreiben:
              </p>
              {members.length === 0 ? (
                <div className="p-4 rounded-lg text-sm" style={{ background: "var(--color-warning-light)", color: "var(--color-warning)" }}>
                  Es gibt noch keine Projekt-Mitglieder. Bitte zuerst über Mitglieder-Panel Mitglieder hinzufügen.
                </div>
              ) : (
                <div className="space-y-2">
                  {members.map((m) => (
                    <div
                      key={m.profile_id}
                      className="flex items-center justify-between p-3 rounded-lg"
                      style={{ background: "var(--color-muted)" }}
                    >
                      <div>
                        <div className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>{m.name}</div>
                        <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>{m.role}</div>
                      </div>
                      <IconCheck size={16} style={{ color: "var(--color-success)" }} />
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* SCHRITT 2: Inventar-Beiträge */}
          {step === 2 && (
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
                  Wer bringt welches Equipment zu welchem Tagessatz ein?
                </p>
                <button
                  onClick={addContribution}
                  className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg font-medium"
                  style={{ background: "var(--color-primary)", color: "#fff" }}
                >
                  <IconPlus size={12} /> Hinzufügen
                </button>
              </div>
              {contributions.length === 0 ? (
                <p className="text-xs py-4 text-center" style={{ color: "var(--color-muted-foreground)" }}>
                  Kein Inventar-Beitrag (optional)
                </p>
              ) : (
                <div className="space-y-2">
                  {contributions.map((c) => (
                    <div key={c.id} className="grid grid-cols-[2fr_2fr_80px_80px_auto] gap-2 items-start p-2 rounded-lg" style={{ background: "var(--color-muted)" }}>
                      <select value={c.inventory_item_id} onChange={(e) => updateContribution(c.id, "inventory_item_id", e.target.value)} className="px-2 py-1.5 rounded text-xs" style={inputStyle}>
                        {inventory.map((i) => (<option key={i.id} value={i.id}>{i.name}</option>))}
                      </select>
                      <select value={c.contributor_id} onChange={(e) => updateContribution(c.id, "contributor_id", e.target.value)} className="px-2 py-1.5 rounded text-xs" style={inputStyle}>
                        {members.map((m) => (<option key={m.profile_id} value={m.profile_id}>{m.name}</option>))}
                      </select>
                      <input type="number" step="0.01" value={c.daily_rate} onChange={(e) => updateContribution(c.id, "daily_rate", parseFloat(e.target.value) || 0)} placeholder="€/Tag" className="px-2 py-1.5 rounded text-xs" style={inputStyle} />
                      <input type="number" value={c.quantity} onChange={(e) => updateContribution(c.id, "quantity", parseInt(e.target.value) || 1)} placeholder="Anzahl" className="px-2 py-1.5 rounded text-xs" style={inputStyle} />
                      <button onClick={() => removeContribution(c.id)} className="p-1.5 rounded" style={{ color: "var(--color-destructive)" }}>
                        <IconTrash size={14} />
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* SCHRITT 3: Rollen */}
          {step === 3 && (
            <div className="space-y-3">
              <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
                Rollen, Verantwortlichkeiten und Arbeitsanteile pro Person:
              </p>
              <div className="space-y-3">
                {roles.map((r) => (
                  <div key={r.profile_id} className="p-3 rounded-lg space-y-2" style={{ background: "var(--color-muted)" }}>
                    <div className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>{r.name}</div>
                    <div className="grid grid-cols-2 gap-2">
                      <div>
                        <label className="text-[10px]" style={{ color: "var(--color-muted-foreground)" }}>Rolle</label>
                        <input type="text" value={r.role_title} onChange={(e) => updateRole(r.profile_id, "role_title", e.target.value)} className="w-full px-2 py-1.5 rounded text-xs" style={inputStyle} />
                      </div>
                      <div>
                        <label className="text-[10px]" style={{ color: "var(--color-muted-foreground)" }}>Verantwortlichkeit</label>
                        <input type="text" value={r.responsibilities} onChange={(e) => updateRole(r.profile_id, "responsibilities", e.target.value)} className="w-full px-2 py-1.5 rounded text-xs" style={inputStyle} />
                      </div>
                    </div>
                    <div className="grid grid-cols-4 gap-2">
                      <div>
                        <label className="text-[10px]" style={{ color: "var(--color-muted-foreground)" }}>€/Std</label>
                        <input type="number" step="0.01" value={r.hourly_rate} onChange={(e) => updateRole(r.profile_id, "hourly_rate", parseFloat(e.target.value) || 0)} className="w-full px-2 py-1.5 rounded text-xs" style={inputStyle} />
                      </div>
                      <div>
                        <label className="text-[10px]" style={{ color: "var(--color-muted-foreground)" }}>Stunden</label>
                        <input type="number" step="0.5" value={r.hours_estimate} onChange={(e) => updateRole(r.profile_id, "hours_estimate", parseFloat(e.target.value) || 0)} className="w-full px-2 py-1.5 rounded text-xs" style={inputStyle} />
                      </div>
                      <div>
                        <label className="text-[10px]" style={{ color: "var(--color-muted-foreground)" }}>Kapital €</label>
                        <input type="number" step="0.01" value={r.capital_contribution} onChange={(e) => updateRole(r.profile_id, "capital_contribution", parseFloat(e.target.value) || 0)} className="w-full px-2 py-1.5 rounded text-xs" style={inputStyle} />
                      </div>
                      <div>
                        <label className="text-[10px]" style={{ color: "var(--color-muted-foreground)" }}>Festbetrag €</label>
                        <input type="number" step="0.01" value={r.fixed_amount} onChange={(e) => updateRole(r.profile_id, "fixed_amount", parseFloat(e.target.value) || 0)} className="w-full px-2 py-1.5 rounded text-xs" style={inputStyle} />
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* SCHRITT 4: Gewinnverteilung + Exit + Freitext */}
          {step === 4 && (
            <div className="space-y-5">
              <div>
                <h3 className="text-sm font-semibold mb-2" style={{ color: "var(--color-foreground)" }}>Gewinn-Formel (Gewichtung)</h3>
                <p className="text-xs mb-3" style={{ color: "var(--color-muted-foreground)" }}>
                  Summe muss 100% ergeben. Aktuell: <strong style={{ color: weightSumPct === 100 ? "var(--color-success)" : "var(--color-destructive)" }}>{weightSumPct}%</strong>
                </p>
                <div className="grid grid-cols-4 gap-3">
                  {(["hours", "inventory", "capital", "fixed"] as const).map((k) => (
                    <div key={k}>
                      <label className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                        {k === "hours" ? "Stunden" : k === "inventory" ? "Inventar" : k === "capital" ? "Kapital" : "Festbetrag"}
                      </label>
                      <div className="relative">
                        <input type="number" min="0" max="100" value={Math.round((formula.weights?.[k] || 0) * 100)} onChange={(e) => updateWeight(k, parseFloat(e.target.value) || 0)} className="w-full px-2 py-1.5 rounded text-xs pr-6" style={inputStyle} />
                        <span className="absolute right-2 top-1/2 -translate-y-1/2 text-xs" style={{ color: "var(--color-muted-foreground)" }}>%</span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              <div>
                <h3 className="text-sm font-semibold mb-2" style={{ color: "var(--color-foreground)" }}>Exit-Regeln</h3>
                <label className="flex items-center gap-2 text-sm">
                  <input type="checkbox" checked={exitRules.forfeit_if_exit_before_event} onChange={(e) => setExitRules({ ...exitRules, forfeit_if_exit_before_event: e.target.checked })} />
                  <span style={{ color: "var(--color-foreground)" }}>Bei Austritt vor dem Event: Verzicht auf Gewinnanteil</span>
                </label>
                <div className="mt-2">
                  <label className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Rückgabefrist für Inventar (Tage)</label>
                  <input type="number" value={exitRules.inventory_return_window_days} onChange={(e) => setExitRules({ ...exitRules, inventory_return_window_days: parseInt(e.target.value) || 7 })} className="w-24 px-2 py-1.5 rounded text-xs" style={inputStyle} />
                </div>
              </div>

              <div>
                <h3 className="text-sm font-semibold mb-2" style={{ color: "var(--color-foreground)" }}>Zusatzklauseln (Freitext)</h3>
                <textarea value={additionalTerms} onChange={(e) => setAdditionalTerms(e.target.value)} rows={3} className="w-full px-3 py-2 rounded-lg text-sm resize-none" style={inputStyle} placeholder="Besondere Vereinbarungen, Haftung, Vertraulichkeit..." />
              </div>
            </div>
          )}

          {error && (
            <div className="p-3 rounded-lg text-sm" style={{ background: "var(--color-destructive-light)", color: "var(--color-destructive)" }}>
              {error}
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between p-5 border-t sticky bottom-0" style={{ background: "var(--color-surface)", borderColor: "var(--color-border)" }}>
          <button
            onClick={() => setStep((step - 1) as Step)}
            disabled={step === 1}
            className="flex items-center gap-1 px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-40"
            style={{ border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}
          >
            <IconChevronLeft size={14} /> Zurück
          </button>
          {step < 4 ? (
            <button
              onClick={() => setStep((step + 1) as Step)}
              disabled={!canProceed()}
              className="flex items-center gap-1 px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
              style={{ background: "var(--color-primary)" }}
            >
              Weiter <IconChevronRight size={14} />
            </button>
          ) : (
            <button
              onClick={handleSave}
              disabled={!canProceed() || saving}
              className="px-5 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
              style={{ background: "var(--color-success)" }}
            >
              {saving ? "Erstelle..." : "Vereinbarung zur Unterschrift versenden"}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
