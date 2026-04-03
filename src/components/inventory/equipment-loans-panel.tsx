"use client";

import { useState, useCallback, useEffect } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type { EquipmentLoan, InventoryItem, LoanStatus } from "@/types/database";
import { IconX, IconPlus, IconCheck, IconArrowLeft } from "@/components/ui/icons";

const statusLabels: Record<LoanStatus, string> = {
  requested: "Angefragt",
  active: "Ausgeliehen",
  returned: "Zurückgegeben",
  overdue: "Überfällig",
  lost: "Verloren",
};

const statusColors: Record<LoanStatus, { bg: string; color: string }> = {
  requested: { bg: "var(--color-info-light)", color: "var(--color-info)" },
  active: { bg: "var(--color-warning-light)", color: "var(--color-warning)" },
  returned: { bg: "var(--color-success-light)", color: "var(--color-success)" },
  overdue: { bg: "var(--color-destructive-light)", color: "var(--color-destructive)" },
  lost: { bg: "var(--color-destructive-light)", color: "var(--color-destructive)" },
};

const conditionLabels: Record<string, string> = {
  new: "Neu",
  good: "Gut",
  fair: "Befriedigend",
  poor: "Schlecht",
  broken: "Defekt",
};

interface EquipmentLoansPanelProps {
  show: boolean;
  onClose: () => void;
  filterItemId?: string; // Optional: nur Leihgaben für ein bestimmtes Item
}

export function EquipmentLoansPanel({ show, onClose, filterItemId }: EquipmentLoansPanelProps) {
  const supabase = createClient();
  const { orgId } = useOrg();
  const currentUser = useCurrentUser();

  const [loans, setLoans] = useState<EquipmentLoan[]>([]);
  const [items, setItems] = useState<InventoryItem[]>([]);
  const [members, setMembers] = useState<{ id: string; name: string }[]>([]);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState<"active" | "history">("active");
  const [showCreate, setShowCreate] = useState(false);
  const [returnLoanId, setReturnLoanId] = useState<string | null>(null);

  // Create-Form State
  const [formItemId, setFormItemId] = useState(filterItemId || "");
  const [formBorrowerId, setFormBorrowerId] = useState("");
  const [formQuantity, setFormQuantity] = useState(1);
  const [formDueDate, setFormDueDate] = useState("");
  const [formPurpose, setFormPurpose] = useState("");
  const [formCondition, setFormCondition] = useState<string>("good");

  // Return-Form State
  const [returnCondition, setReturnCondition] = useState<string>("good");
  const [returnNotes, setReturnNotes] = useState("");

  const loadLoans = useCallback(async () => {
    if (!orgId) return;
    let query = supabase
      .from("equipment_loans")
      .select("*, borrower:borrower_id(name, avatar_url), approver:approved_by(name), inventory_items:inventory_item_id(name, inventory_number, image_url)")
      .eq("org_id", orgId)
      .order("borrowed_at", { ascending: false });

    if (filterItemId) {
      query = query.eq("inventory_item_id", filterItemId);
    }

    const { data } = await query;
    if (data) setLoans(data as EquipmentLoan[]);
    setLoading(false);
  }, [supabase, orgId, filterItemId]);

  const loadItems = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("inventory_items")
      .select("id, name, inventory_number, quantity")
      .eq("org_id", orgId)
      .order("name");
    if (data) setItems(data as InventoryItem[]);
  }, [supabase, orgId]);

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
  }, [supabase, orgId]);

  useEffect(() => {
    if (show) {
      loadLoans();
      loadItems();
      loadMembers();
      // Überfällige markieren
      if (orgId) {
        supabase.rpc("check_overdue_loans", { p_org_id: orgId });
      }
    }
  }, [show, loadLoans, loadItems, loadMembers, orgId, supabase]);

  useRealtimeTable({ table: "equipment_loans", onDataChange: loadLoans });

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    if (!orgId || !formItemId || !formBorrowerId) return;
    await supabase.from("equipment_loans").insert({
      org_id: orgId,
      inventory_item_id: formItemId,
      borrower_id: formBorrowerId,
      quantity: formQuantity,
      due_date: formDueDate || null,
      purpose: formPurpose || null,
      condition_at_checkout: formCondition,
      status: "active",
      approved_by: currentUser?.id || null,
    });
    setShowCreate(false);
    resetForm();
    await loadLoans();
  }

  async function handleReturn(loanId: string) {
    await supabase
      .from("equipment_loans")
      .update({
        status: "returned",
        returned_at: new Date().toISOString(),
        condition_at_return: returnCondition,
        return_notes: returnNotes || null,
      })
      .eq("id", loanId);
    setReturnLoanId(null);
    setReturnCondition("good");
    setReturnNotes("");
    await loadLoans();
  }

  async function markLost(loanId: string) {
    if (!confirm("Wirklich als verloren markieren?")) return;
    await supabase
      .from("equipment_loans")
      .update({ status: "lost" })
      .eq("id", loanId);
    await loadLoans();
  }

  function resetForm() {
    setFormItemId(filterItemId || "");
    setFormBorrowerId("");
    setFormQuantity(1);
    setFormDueDate("");
    setFormPurpose("");
    setFormCondition("good");
  }

  const activeLoans = loans.filter((l) => l.status === "active" || l.status === "overdue" || l.status === "requested");
  const historyLoans = loans.filter((l) => l.status === "returned" || l.status === "lost");

  const inputStyle = {
    border: "1px solid var(--color-border)",
    background: "var(--color-background)",
  };

  if (!show) return null;

  return (
    <>
      {/* Backdrop */}
      <div className="fixed inset-0 z-40 bg-black/30" onClick={onClose} />

      {/* Panel */}
      <div
        className="fixed top-0 right-0 h-full w-full max-w-lg z-50 flex flex-col overflow-hidden"
        style={{
          background: "var(--color-surface)",
          borderLeft: "1px solid var(--color-border)",
          boxShadow: "var(--shadow-lg)",
        }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between px-5 py-4 shrink-0"
          style={{ borderBottom: "1px solid var(--color-border)" }}
        >
          <h2 className="text-lg font-bold">
            {filterItemId ? "Leihgaben für Artikel" : "Leihgaben-Verwaltung"}
          </h2>
          <button onClick={onClose} className="p-1.5 rounded-lg" style={{ color: "var(--color-muted-foreground)" }}>
            <IconX size={18} />
          </button>
        </div>

        {/* Tabs */}
        <div className="flex px-5 pt-3 gap-1 shrink-0">
          <button
            onClick={() => setTab("active")}
            className="px-3 py-1.5 rounded-lg text-sm font-medium"
            style={{
              background: tab === "active" ? "var(--color-primary)" : "transparent",
              color: tab === "active" ? "white" : "var(--color-muted-foreground)",
            }}
          >
            Aktiv ({activeLoans.length})
          </button>
          <button
            onClick={() => setTab("history")}
            className="px-3 py-1.5 rounded-lg text-sm font-medium"
            style={{
              background: tab === "history" ? "var(--color-primary)" : "transparent",
              color: tab === "history" ? "white" : "var(--color-muted-foreground)",
            }}
          >
            Verlauf ({historyLoans.length})
          </button>
          <button
            onClick={() => setShowCreate(true)}
            className="ml-auto flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium text-white"
            style={{ background: "var(--color-primary)" }}
          >
            <IconPlus size={14} />
            Ausleihe
          </button>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto px-5 py-4 space-y-3">
          {loading ? (
            <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>Lade...</p>
          ) : (
            <>
              {/* Erstellen-Formular */}
              {showCreate && (
                <form
                  onSubmit={handleCreate}
                  className="rounded-lg p-4 space-y-3 mb-4"
                  style={{ background: "var(--color-muted)", border: "1px solid var(--color-border)" }}
                >
                  <h3 className="text-sm font-semibold">Neue Ausleihe</h3>

                  {!filterItemId && (
                    <select
                      value={formItemId}
                      onChange={(e) => setFormItemId(e.target.value)}
                      className="w-full px-3 py-2 rounded-lg text-sm"
                      style={inputStyle}
                      required
                    >
                      <option value="">Artikel wählen...</option>
                      {items.map((item) => (
                        <option key={item.id} value={item.id}>
                          {item.inventory_number} — {item.name}
                        </option>
                      ))}
                    </select>
                  )}

                  <select
                    value={formBorrowerId}
                    onChange={(e) => setFormBorrowerId(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm"
                    style={inputStyle}
                    required
                  >
                    <option value="">Ausgeliehen an...</option>
                    {members.map((m) => (
                      <option key={m.id} value={m.id}>{m.name}</option>
                    ))}
                  </select>

                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Anzahl</label>
                      <input
                        type="number"
                        value={formQuantity}
                        onChange={(e) => setFormQuantity(Number(e.target.value))}
                        min={1}
                        className="w-full px-3 py-2 rounded-lg text-sm"
                        style={inputStyle}
                      />
                    </div>
                    <div>
                      <label className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Rückgabe bis</label>
                      <input
                        type="date"
                        value={formDueDate}
                        onChange={(e) => setFormDueDate(e.target.value)}
                        className="w-full px-3 py-2 rounded-lg text-sm"
                        style={inputStyle}
                      />
                    </div>
                  </div>

                  <div>
                    <label className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Zustand bei Ausgabe</label>
                    <select
                      value={formCondition}
                      onChange={(e) => setFormCondition(e.target.value)}
                      className="w-full px-3 py-2 rounded-lg text-sm"
                      style={inputStyle}
                    >
                      {Object.entries(conditionLabels).map(([k, v]) => (
                        <option key={k} value={k}>{v}</option>
                      ))}
                    </select>
                  </div>

                  <input
                    type="text"
                    value={formPurpose}
                    onChange={(e) => setFormPurpose(e.target.value)}
                    placeholder="Zweck (optional)"
                    className="w-full px-3 py-2 rounded-lg text-sm"
                    style={inputStyle}
                  />

                  <div className="flex gap-2">
                    <button
                      type="submit"
                      className="flex-1 px-3 py-2 rounded-lg text-sm font-medium text-white"
                      style={{ background: "var(--color-primary)" }}
                    >
                      Ausleihen
                    </button>
                    <button
                      type="button"
                      onClick={() => { setShowCreate(false); resetForm(); }}
                      className="px-3 py-2 rounded-lg text-sm"
                      style={{ border: "1px solid var(--color-border)" }}
                    >
                      Abbrechen
                    </button>
                  </div>
                </form>
              )}

              {/* Loan-Liste */}
              {(tab === "active" ? activeLoans : historyLoans).length === 0 ? (
                <p className="text-sm py-8 text-center" style={{ color: "var(--color-muted-foreground)" }}>
                  {tab === "active" ? "Keine aktiven Leihgaben." : "Noch kein Verlauf vorhanden."}
                </p>
              ) : (
                (tab === "active" ? activeLoans : historyLoans).map((loan) => (
                  <div
                    key={loan.id}
                    className="rounded-lg p-3"
                    style={{
                      background: "var(--color-muted)",
                      border: loan.status === "overdue"
                        ? "1px solid var(--color-destructive)"
                        : "1px solid var(--color-border-light)",
                    }}
                  >
                    <div className="flex items-start justify-between gap-2">
                      <div className="flex-1 min-w-0">
                        {/* Item-Name */}
                        <div className="text-sm font-medium truncate">
                          {loan.inventory_items?.inventory_number} — {loan.inventory_items?.name}
                        </div>
                        {/* Borrower */}
                        <div className="text-xs mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>
                          Ausgeliehen an: <span className="font-medium" style={{ color: "var(--color-foreground)" }}>{loan.borrower?.name}</span>
                          {loan.quantity > 1 && ` (${loan.quantity}×)`}
                        </div>
                        {/* Dates */}
                        <div className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                          Seit: {new Date(loan.borrowed_at).toLocaleDateString("de-DE")}
                          {loan.due_date && (
                            <span
                              style={{
                                color: loan.status === "overdue" ? "var(--color-destructive)" : undefined,
                                fontWeight: loan.status === "overdue" ? 600 : undefined,
                              }}
                            >
                              {" "}· Rückgabe: {new Date(loan.due_date).toLocaleDateString("de-DE")}
                            </span>
                          )}
                          {loan.returned_at && (
                            <span> · Zurück: {new Date(loan.returned_at).toLocaleDateString("de-DE")}</span>
                          )}
                        </div>
                        {/* Purpose */}
                        {loan.purpose && (
                          <div className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                            Zweck: {loan.purpose}
                          </div>
                        )}
                        {/* Zustand bei Rückgabe */}
                        {loan.condition_at_return && (
                          <div className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                            Zustand bei Rückgabe: {conditionLabels[loan.condition_at_return] || loan.condition_at_return}
                            {loan.return_notes && ` — ${loan.return_notes}`}
                          </div>
                        )}
                      </div>

                      <div className="flex flex-col items-end gap-1.5 shrink-0">
                        {/* Status Badge */}
                        <span
                          className="px-2 py-0.5 rounded-full text-xs font-medium"
                          style={{
                            background: statusColors[loan.status].bg,
                            color: statusColors[loan.status].color,
                          }}
                        >
                          {statusLabels[loan.status]}
                        </span>

                        {/* Actions */}
                        {(loan.status === "active" || loan.status === "overdue") && (
                          <div className="flex gap-1">
                            <button
                              onClick={() => setReturnLoanId(loan.id)}
                              className="p-1 rounded text-xs"
                              style={{ color: "var(--color-success)" }}
                              title="Rückgabe"
                            >
                              <IconArrowLeft size={14} />
                            </button>
                            <button
                              onClick={() => markLost(loan.id)}
                              className="p-1 rounded text-xs"
                              style={{ color: "var(--color-destructive)" }}
                              title="Als verloren markieren"
                            >
                              <IconX size={14} />
                            </button>
                          </div>
                        )}
                      </div>
                    </div>

                    {/* Return-Form inline */}
                    {returnLoanId === loan.id && (
                      <div
                        className="mt-3 pt-3 space-y-2"
                        style={{ borderTop: "1px solid var(--color-border)" }}
                      >
                        <h4 className="text-xs font-semibold">Rückgabe bestätigen</h4>
                        <div className="grid grid-cols-2 gap-2">
                          <div>
                            <label className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Zustand</label>
                            <select
                              value={returnCondition}
                              onChange={(e) => setReturnCondition(e.target.value)}
                              className="w-full px-2 py-1.5 rounded text-xs"
                              style={inputStyle}
                            >
                              {Object.entries(conditionLabels).map(([k, v]) => (
                                <option key={k} value={k}>{v}</option>
                              ))}
                            </select>
                          </div>
                          <div>
                            <label className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Notizen</label>
                            <input
                              type="text"
                              value={returnNotes}
                              onChange={(e) => setReturnNotes(e.target.value)}
                              placeholder="Optional"
                              className="w-full px-2 py-1.5 rounded text-xs"
                              style={inputStyle}
                            />
                          </div>
                        </div>
                        <div className="flex gap-2">
                          <button
                            onClick={() => handleReturn(loan.id)}
                            className="flex items-center gap-1 px-3 py-1.5 rounded text-xs font-medium text-white"
                            style={{ background: "var(--color-success)" }}
                          >
                            <IconCheck size={12} />
                            Zurückgeben
                          </button>
                          <button
                            onClick={() => setReturnLoanId(null)}
                            className="px-3 py-1.5 rounded text-xs"
                            style={{ border: "1px solid var(--color-border)" }}
                          >
                            Abbrechen
                          </button>
                        </div>
                      </div>
                    )}
                  </div>
                ))
              )}
            </>
          )}
        </div>
      </div>
    </>
  );
}
