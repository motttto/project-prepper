"use client";

import { useState, useEffect, useCallback, useMemo } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { useWorkspace } from "@/contexts/org-context";
import { useCurrentUser, hasPermission } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import { DateInput } from "@/components/ui/date-input";
import { showToast } from "@/hooks/use-toast";
import { appConfirm } from "@/components/ui/confirm-dialog";
import { EquipmentPicker, type PickedItem } from "@/components/rentals/equipment-picker";
import type { Rental, RentalStatus, RentalItemApprovalStatus } from "@/types/database";
import { rentalItemApprovalLabels } from "@/types/database";
import {
  IconPlus,
  IconSearch,
  IconX,
  IconChevronRight,
  IconTrash,
  IconPackage,
} from "@/components/ui/icons";

const statusLabels: Record<RentalStatus, string> = {
  reserved: "Reserviert",
  active: "Ausgegeben",
  returned: "Zurück",
  cancelled: "Storniert",
};

const statusColors: Record<RentalStatus, { bg: string; color: string; dot: string }> = {
  reserved: { bg: "var(--color-info-light)", color: "var(--color-info)", dot: "#3b82f6" },
  active: { bg: "var(--color-warning-light)", color: "var(--color-warning)", dot: "#f59e0b" },
  returned: { bg: "var(--color-success-light)", color: "var(--color-success)", dot: "#22c55e" },
  cancelled: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)", dot: "#6b7280" },
};

const statusOrder: RentalStatus[] = ["reserved", "active", "returned", "cancelled"];

type StatusFilter = "all" | "open" | RentalStatus;

const filterLabels: Record<StatusFilter, string> = {
  all: "Alle",
  open: "Offen",
  reserved: "Reserviert",
  active: "Ausgegeben",
  returned: "Zurück",
  cancelled: "Storniert",
};

type RentalWithCounts = Rental & { itemCount: number };

type MyItemRental = {
  rental_item_id: string;
  rental_id: string;
  item_name: string;
  inventory_number: string;
  quantity: number;
  approval_status: RentalItemApprovalStatus;
  rejection_reason: string | null;
  proposed_rate: number | null;
  agreed_rate: number | null;
  rental: {
    borrower_name: string;
    date_from: string;
    date_to: string;
    status: RentalStatus;
    owner_group_id: string | null;
    rental_fee: number | null;
    groups: { name: string } | null;
  };
};

const approvalBadgeColors: Record<RentalItemApprovalStatus, { bg: string; color: string }> = {
  auto: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)" },
  pending: { bg: "var(--color-warning-light)", color: "var(--color-warning)" },
  approved: { bg: "var(--color-success-light)", color: "var(--color-success)" },
  rejected: { bg: "var(--color-destructive-light)", color: "var(--color-destructive)" },
};

function MyItemApprovalControls({
  proposed,
  onApprove,
  onReject,
}: {
  proposed: number;
  onApprove: (rate: number | null) => void;
  onReject: () => void;
}) {
  const [rate, setRate] = useState<string>(String(proposed));
  return (
    <div className="flex items-center gap-1 flex-wrap">
      <input
        type="number"
        min={0}
        step={0.01}
        value={rate}
        onChange={(e) => setRate(e.target.value)}
        className="w-20 px-2 py-1.5 rounded text-xs"
        style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
        title="Vereinbarter Tagessatz (€/Tag)"
      />
      <button
        onClick={() => onApprove(rate === "" ? null : Number(rate))}
        className="px-3 py-1.5 rounded text-xs font-medium text-white"
        style={{ background: "var(--color-success)" }}
        title={`Freigeben mit ${rate || proposed} €/Tag`}
      >
        Akzeptieren
      </button>
      <button
        onClick={() => onApprove(0)}
        className="px-3 py-1.5 rounded text-xs font-medium"
        style={{ border: "1px solid var(--color-border)" }}
        title="Auf Einnahmen verzichten (0 €/Tag)"
      >
        Verzicht
      </button>
      <button
        onClick={onReject}
        className="px-3 py-1.5 rounded text-xs font-medium"
        style={{ border: "1px solid var(--color-destructive)", color: "var(--color-destructive)" }}
      >
        Ablehnen
      </button>
    </div>
  );
}

export default function RentalsPage() {
  const supabase = createClient();
  const router = useRouter();
  const currentUser = useCurrentUser();
  const { groupId } = useWorkspace();
  const ownerId = currentUser?.id ?? null;

  const canView = hasPermission(currentUser, "rentals_view");
  const canCreate = hasPermission(currentUser, "rentals_create");
  const canEdit = hasPermission(currentUser, "rentals_edit");

  const [rentals, setRentals] = useState<RentalWithCounts[]>([]);
  const [myItemRentals, setMyItemRentals] = useState<MyItemRental[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<StatusFilter>("open");
  const [showCreate, setShowCreate] = useState(false);
  const [saving, setSaving] = useState(false);
  const [statusMenuId, setStatusMenuId] = useState<string | null>(null);

  // Form State
  const [formBorrowerName, setFormBorrowerName] = useState("");
  const [formBorrowerEmail, setFormBorrowerEmail] = useState("");
  const [formBorrowerPhone, setFormBorrowerPhone] = useState("");
  const [formBorrowerAddress, setFormBorrowerAddress] = useState("");
  const [formDateFrom, setFormDateFrom] = useState("");
  const [formDateTo, setFormDateTo] = useState("");
  const [formDeposit, setFormDeposit] = useState("");
  const [formFee, setFormFee] = useState("");
  const [formNotes, setFormNotes] = useState("");
  const [formItems, setFormItems] = useState<PickedItem[]>([]);

  const loadRentals = useCallback(async () => {
    if (!ownerId) {
      setRentals([]);
      setLoading(false);
      return;
    }
    // Strikte Workspace-Isolation: zusaetzlich zur owner_*-Spalte das jeweils
    // andere Owner-Feld auf NULL pinnen (XOR ist DB-seitig garantiert, aber
    // doppelt haelt besser falls je Legacy-Daten auftauchen).
    const query = groupId
      ? supabase
          .from("rentals")
          .select("*, rental_items(id)")
          .eq("owner_group_id", groupId)
          .is("owner_profile_id", null)
          .order("created_at", { ascending: false })
      : supabase
          .from("rentals")
          .select("*, rental_items(id)")
          .eq("owner_profile_id", ownerId)
          .is("owner_group_id", null)
          .order("created_at", { ascending: false });
    const { data } = await query;
    const mapped: RentalWithCounts[] = (data ?? []).map((r) => {
      const row = r as Rental & { rental_items: { id: string }[] };
      return { ...row, itemCount: row.rental_items?.length ?? 0 };
    });
    setRentals(mapped);
    setLoading(false);
  }, [supabase, ownerId, groupId]);

  // State leeren, sobald Workspace wechselt — verhindert dass Group-Rentals
  // beim Switch zu Solo (oder umgekehrt) kurzzeitig haengen bleiben.
  useEffect(() => {
    setRentals([]);
    setLoading(true);
  }, [groupId]);

  // Verleihe in denen MEIN Equipment verwendet wird (durch andere User).
  // Nur Solo-Owner-Items relevant — bei Group-Owned-Items gibt es keinen Einzel-Approver.
  const loadMyItemRentals = useCallback(async () => {
    if (!ownerId) {
      setMyItemRentals([]);
      return;
    }
    // Items die mir gehoeren — Verleih-Filter (nicht meine eigenen Solo-Verleihe)
    // passiert clientseitig, weil .neq mit NULL in PostgREST nicht matched.
    const { data } = await supabase
      .from("rental_items")
      .select(`
        id,
        rental_id,
        quantity,
        approval_status,
        rejection_reason,
        proposed_rate,
        agreed_rate,
        inventory_items!inner(name, inventory_number, owner_profile_id),
        rentals!inner(
          borrower_name, date_from, date_to, status, owner_profile_id, owner_group_id, rental_fee,
          groups:owner_group_id(name)
        )
      `)
      .eq("inventory_items.owner_profile_id", ownerId)
      .order("created_at", { ascending: false });

    if (data) {
      const mapped: MyItemRental[] = (data as any[])
        // Eigene Solo-Verleihe ausfiltern (wenn rental.owner_profile_id == me)
        .filter((row) => row.rentals?.owner_profile_id !== ownerId)
        .map((row) => ({
          rental_item_id: row.id,
          rental_id: row.rental_id,
          item_name: row.inventory_items?.name ?? "Gerät",
          inventory_number: row.inventory_items?.inventory_number ?? "",
          quantity: row.quantity,
          approval_status: row.approval_status,
          rejection_reason: row.rejection_reason,
          proposed_rate: row.proposed_rate,
          agreed_rate: row.agreed_rate,
          rental: {
            borrower_name: row.rentals?.borrower_name ?? "",
            date_from: row.rentals?.date_from,
            date_to: row.rentals?.date_to,
            status: row.rentals?.status,
            owner_group_id: row.rentals?.owner_group_id,
            rental_fee: row.rentals?.rental_fee,
            groups: row.rentals?.groups ?? null,
          },
        }));
      setMyItemRentals(mapped);
    }
  }, [supabase, ownerId]);

  useEffect(() => {
    loadRentals();
    loadMyItemRentals();
  }, [loadRentals, loadMyItemRentals]);

  useRealtimeTable({ table: "rentals", onDataChange: () => { loadRentals(); loadMyItemRentals(); } });
  useRealtimeTable({ table: "rental_items", onDataChange: () => { loadRentals(); loadMyItemRentals(); } });

  async function handleApproveItem(rentalItemId: string, agreedRate?: number | null) {
    const { error } = await supabase.rpc("approve_rental_item", {
      p_rental_item_id: rentalItemId,
      p_agreed_rate: agreedRate ?? null,
    });
    if (error) showToast("Fehler: " + error.message, "error");
    else {
      showToast("Freigegeben", "success");
      loadMyItemRentals();
    }
  }

  async function handleRejectItem(rentalItemId: string) {
    const reason = window.prompt("Grund für die Ablehnung (optional):") ?? null;
    const { error } = await supabase.rpc("reject_rental_item", {
      p_rental_item_id: rentalItemId,
      p_reason: reason,
    });
    if (error) showToast("Fehler: " + error.message, "error");
    else {
      showToast("Abgelehnt", "success");
      loadMyItemRentals();
    }
  }

  const filtered = useMemo(() => {
    let result = rentals;
    if (statusFilter === "open") {
      result = result.filter((r) => !["returned", "cancelled"].includes(r.status));
    } else if (statusFilter !== "all") {
      result = result.filter((r) => r.status === statusFilter);
    }
    if (search.trim()) {
      const q = search.toLowerCase();
      result = result.filter(
        (r) =>
          r.borrower_name.toLowerCase().includes(q) ||
          r.borrower_email?.toLowerCase().includes(q) ||
          r.notes?.toLowerCase().includes(q)
      );
    }
    return result;
  }, [rentals, statusFilter, search]);

  const statusCounts = useMemo(() => {
    const counts: Record<string, number> = { all: rentals.length, open: 0 };
    for (const r of rentals) {
      counts[r.status] = (counts[r.status] || 0) + 1;
      if (!["returned", "cancelled"].includes(r.status)) counts.open++;
    }
    return counts;
  }, [rentals]);

  const totalDeposit = useMemo(
    () =>
      rentals
        .filter((r) => !["returned", "cancelled"].includes(r.status))
        .reduce((sum, r) => sum + Number(r.deposit_amount || 0), 0),
    [rentals]
  );

  function resetForm() {
    setFormBorrowerName("");
    setFormBorrowerEmail("");
    setFormBorrowerPhone("");
    setFormBorrowerAddress("");
    setFormDateFrom("");
    setFormDateTo("");
    setFormDeposit("");
    setFormFee("");
    setFormNotes("");
    setFormItems([]);
  }

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    if (!ownerId) return;
    if (formItems.length === 0) {
      showToast("Mindestens ein Gerät auswählen", "error");
      return;
    }
    const conflicts = formItems.filter((p) => p.availability && p.quantity > p.availability.available);
    if (conflicts.length > 0) {
      showToast(`Konflikt: ${conflicts.map((c) => c.itemName).join(", ")} im Zeitraum nicht verfügbar`, "error");
      return;
    }
    setSaving(true);

    const { data: { user } } = await supabase.auth.getUser();

    const { data: created, error } = await supabase
      .from("rentals")
      .insert({
        owner_profile_id: groupId ? null : ownerId,
        owner_group_id: groupId ?? null,
        borrower_name: formBorrowerName,
        borrower_email: formBorrowerEmail || null,
        borrower_phone: formBorrowerPhone || null,
        borrower_address: formBorrowerAddress || null,
        date_from: formDateFrom,
        date_to: formDateTo,
        deposit_amount: formDeposit ? parseFloat(formDeposit) : 0,
        rental_fee: formFee ? parseFloat(formFee) : null,
        notes: formNotes || null,
        created_by: user?.id,
      })
      .select("id")
      .single();

    if (error || !created) {
      showToast("Fehler beim Anlegen: " + (error?.message ?? ""), "error");
      setSaving(false);
      return;
    }

    const { error: itemsErr } = await supabase.from("rental_items").insert(
      formItems.map((p) => ({
        rental_id: created.id,
        inventory_item_id: p.inventory_item_id,
        quantity: p.quantity,
      }))
    );

    if (itemsErr) {
      showToast("Fehler bei Equipment: " + itemsErr.message, "error");
      setSaving(false);
      return;
    }

    resetForm();
    setShowCreate(false);
    loadRentals();
    showToast("Verleih angelegt", "success");
    setSaving(false);
    router.push(`/rentals/${created.id}`);
  }

  async function handleDelete(e: React.MouseEvent, id: string) {
    e.stopPropagation();
    if (!(await appConfirm("Verleih wirklich löschen?", { variant: "danger", confirmLabel: "Löschen" }))) return;
    const { error } = await supabase.from("rentals").delete().eq("id", id);
    if (error) showToast("Fehler: " + error.message, "error");
    else {
      loadRentals();
      showToast("Verleih gelöscht", "success");
    }
  }

  async function handleStatusChange(e: React.MouseEvent, id: string, newStatus: RentalStatus) {
    e.stopPropagation();
    setStatusMenuId(null);
    const { error } = await supabase.from("rentals").update({ status: newStatus }).eq("id", id);
    if (error) showToast("Status konnte nicht geändert werden", "error");
    else loadRentals();
  }

  if (!canView) {
    return (
      <div className="text-center py-16" style={{ color: "var(--color-muted-foreground)" }}>
        <IconPackage size={32} className="mx-auto mb-3 opacity-40" />
        <p>Du hast keine Berechtigung, den Verleih zu sehen.</p>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="space-y-4">
        <div className="h-8 w-48 skeleton" />
        <div className="grid grid-cols-4 gap-3">
          {[1, 2, 3, 4].map((i) => <div key={i} className="h-16 skeleton rounded-lg" />)}
        </div>
        {[1, 2, 3, 4, 5].map((i) => <div key={i} className="h-16 skeleton rounded-lg" />)}
      </div>
    );
  }

  return (
    <div className="animate-fadeIn">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold">Verleih</h1>
          <p className="text-sm mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>
            {rentals.length} Verleihe &middot; {statusCounts.open || 0} offen
          </p>
        </div>
        {canCreate && (
          <button
            onClick={() => setShowCreate(!showCreate)}
            className="flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium text-white transition-colors"
            style={{ background: "var(--color-primary)" }}
            onMouseEnter={(e) => (e.currentTarget.style.background = "var(--color-primary-hover)")}
            onMouseLeave={(e) => (e.currentTarget.style.background = "var(--color-primary)")}
          >
            <IconPlus size={16} />
            Neuer Verleih
          </button>
        )}
      </div>

      {/* KPI */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div className="p-3 rounded-lg" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}>
          <div className="text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>Reserviert</div>
          <div className="text-xl font-bold" style={{ color: statusColors.reserved.color }}>{statusCounts.reserved || 0}</div>
        </div>
        <div className="p-3 rounded-lg" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}>
          <div className="text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>Ausgegeben</div>
          <div className="text-xl font-bold" style={{ color: statusColors.active.color }}>{statusCounts.active || 0}</div>
        </div>
        <div className="p-3 rounded-lg" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}>
          <div className="text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>Zurück</div>
          <div className="text-xl font-bold" style={{ color: statusColors.returned.color }}>{statusCounts.returned || 0}</div>
        </div>
        <div className="p-3 rounded-lg" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}>
          <div className="text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>Kaution offen</div>
          <div className="text-xl font-bold">{totalDeposit.toLocaleString("de-DE")} €</div>
        </div>
      </div>

      {/* Create-Form */}
      {showCreate && canCreate && (
        <div
          className="mb-6 p-6 rounded-xl animate-slideDown"
          style={{ background: "var(--color-surface)", boxShadow: "var(--shadow-md)", border: "1px solid var(--color-border)" }}
        >
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold">Neuer Verleih</h2>
            <button onClick={() => setShowCreate(false)} style={{ color: "var(--color-muted-foreground)" }}>
              <IconX size={20} />
            </button>
          </div>
          <form onSubmit={handleCreate} className="space-y-4">
            {/* Person */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1.5">Name *</label>
                <input
                  type="text"
                  value={formBorrowerName}
                  onChange={(e) => setFormBorrowerName(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="Max Mustermann"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">E-Mail</label>
                <input
                  type="email"
                  value={formBorrowerEmail}
                  onChange={(e) => setFormBorrowerEmail(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="max@example.com"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Telefon</label>
                <input
                  type="tel"
                  value={formBorrowerPhone}
                  onChange={(e) => setFormBorrowerPhone(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="+49 ..."
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Adresse</label>
                <input
                  type="text"
                  value={formBorrowerAddress}
                  onChange={(e) => setFormBorrowerAddress(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="Straße, PLZ Ort"
                />
              </div>
            </div>

            {/* Zeitraum + Geld */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1.5">Von *</label>
                <DateInput value={formDateFrom} onChange={setFormDateFrom} required />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Bis *</label>
                <DateInput value={formDateTo} onChange={setFormDateTo} required />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Kaution (€)</label>
                <input
                  type="number"
                  value={formDeposit}
                  onChange={(e) => setFormDeposit(e.target.value)}
                  min={0}
                  step={0.01}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="0.00"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Leihgebühr (€)</label>
                <input
                  type="number"
                  value={formFee}
                  onChange={(e) => setFormFee(e.target.value)}
                  min={0}
                  step={0.01}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="0.00"
                />
              </div>
            </div>

            {/* Equipment */}
            <div>
              <label className="block text-sm font-medium mb-1.5">Equipment *</label>
              <EquipmentPicker
                dateFrom={formDateFrom}
                dateTo={formDateTo}
                picked={formItems}
                onChange={setFormItems}
              />
              {!formDateFrom || !formDateTo ? (
                <p className="text-[11px] mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                  Tipp: Zeitraum festlegen, um Verfügbarkeit zu prüfen.
                </p>
              ) : null}
            </div>

            {/* Notes */}
            <div>
              <label className="block text-sm font-medium mb-1.5">Notizen</label>
              <textarea
                value={formNotes}
                onChange={(e) => setFormNotes(e.target.value)}
                rows={2}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                placeholder="z.B. Ausweiskopie hinterlegt"
              />
            </div>

            <div className="flex gap-2 pt-2">
              <button
                type="submit"
                disabled={saving}
                className="px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}
              >
                {saving ? "Wird gespeichert..." : "Verleih anlegen"}
              </button>
              <button
                type="button"
                onClick={() => setShowCreate(false)}
                className="px-4 py-2 rounded-lg text-sm"
                style={{ border: "1px solid var(--color-border)" }}
              >
                Abbrechen
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Search + Filter */}
      <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 mb-5">
        <div className="relative flex-1 max-w-sm">
          <IconSearch
            size={16}
            className="absolute left-3 top-1/2 -translate-y-1/2"
            style={{ color: "var(--color-muted-foreground)" } as React.CSSProperties}
          />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Verleih suchen..."
            className="w-full pl-9 pr-3 py-2 rounded-lg text-sm"
            style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
          />
          {search && (
            <button
              onClick={() => setSearch("")}
              className="absolute right-2 top-1/2 -translate-y-1/2"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              <IconX size={14} />
            </button>
          )}
        </div>
        <div className="flex gap-1 rounded-lg p-1 overflow-x-auto" style={{ background: "var(--color-muted)" }}>
          {(["all", "open", "reserved", "active", "returned", "cancelled"] as StatusFilter[]).map((status) => (
            <button
              key={status}
              onClick={() => setStatusFilter(status)}
              className="px-3 py-1.5 rounded-md text-xs font-medium whitespace-nowrap transition-all"
              style={{
                background: statusFilter === status ? "var(--color-surface)" : "transparent",
                color: statusFilter === status ? "var(--color-foreground)" : "var(--color-muted-foreground)",
                boxShadow: statusFilter === status ? "var(--shadow-sm)" : "none",
              }}
            >
              {filterLabels[status]}
              <span className="ml-1 opacity-60">{statusCounts[status] || 0}</span>
            </button>
          ))}
        </div>
      </div>

      {/* Liste */}
      {filtered.length === 0 ? (
        <div
          className="text-center py-16 rounded-xl"
          style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}
        >
          <IconPackage size={32} className="mx-auto mb-3 opacity-40" />
          <p className="text-lg mb-1">{search ? "Keine Treffer" : "Keine Verleihe"}</p>
          <p className="text-sm">
            {search ? `Keine Verleihe für "${search}" gefunden` : "Lege deinen ersten Verleih an."}
          </p>
        </div>
      ) : (
        <div className="space-y-2">
          {filtered.map((rental) => (
            <div
              key={rental.id}
              onClick={() => router.push(`/rentals/${rental.id}`)}
              className="flex items-center gap-3 p-4 rounded-xl cursor-pointer transition-all group"
              style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}
              onMouseEnter={(e) => {
                e.currentTarget.style.borderColor = "var(--color-border)";
                e.currentTarget.style.boxShadow = "var(--shadow-sm)";
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.borderColor = "var(--color-border-light)";
                e.currentTarget.style.boxShadow = "none";
              }}
            >
              {/* Status Dot */}
              <div className="relative">
                {canEdit ? (
                  <button
                    onClick={(e) => {
                      e.stopPropagation();
                      setStatusMenuId(statusMenuId === rental.id ? null : rental.id);
                    }}
                    className="w-3 h-3 rounded-full flex-shrink-0 cursor-pointer transition-all"
                    style={{ background: statusColors[rental.status].dot }}
                    title={`Status: ${statusLabels[rental.status]}`}
                  />
                ) : (
                  <span
                    className="w-3 h-3 rounded-full flex-shrink-0 inline-block"
                    style={{ background: statusColors[rental.status].dot }}
                  />
                )}
                {canEdit && statusMenuId === rental.id && (
                  <>
                    <div className="fixed inset-0 z-40" onClick={(e) => { e.stopPropagation(); setStatusMenuId(null); }} />
                    <div
                      className="absolute top-full left-0 mt-2 z-50 py-1 rounded-lg min-w-[160px]"
                      style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)", boxShadow: "var(--shadow-lg)" }}
                    >
                      {statusOrder.map((s) => (
                        <button
                          key={s}
                          onClick={(e) => handleStatusChange(e, rental.id, s)}
                          className="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-left transition-colors"
                          style={{
                            color: s === rental.status ? statusColors[s].color : "var(--color-foreground)",
                            background: s === rental.status ? statusColors[s].bg : "transparent",
                          }}
                          onMouseEnter={(e) => { if (s !== rental.status) e.currentTarget.style.background = "var(--color-muted)"; }}
                          onMouseLeave={(e) => { if (s !== rental.status) e.currentTarget.style.background = "transparent"; }}
                        >
                          <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ background: statusColors[s].dot }} />
                          {statusLabels[s]}
                        </button>
                      ))}
                    </div>
                  </>
                )}
              </div>

              {/* Info */}
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                  <span className="font-medium text-sm truncate">{rental.borrower_name}</span>
                  <span
                    className="text-xs px-2 py-0.5 rounded-full font-medium flex-shrink-0"
                    style={{ background: statusColors[rental.status].bg, color: statusColors[rental.status].color }}
                  >
                    {statusLabels[rental.status]}
                  </span>
                </div>
                <div className="flex items-center gap-3 mt-0.5 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                  <span>
                    {new Date(rental.date_from).toLocaleDateString("de-DE", { day: "numeric", month: "short" })}
                    {" – "}
                    {new Date(rental.date_to).toLocaleDateString("de-DE", { day: "numeric", month: "short", year: "numeric" })}
                  </span>
                  <span>{rental.itemCount} {rental.itemCount === 1 ? "Gerät" : "Geräte"}</span>
                  {rental.borrower_email && <span className="truncate">{rental.borrower_email}</span>}
                </div>
              </div>

              {/* Leihgebühr */}
              {rental.rental_fee != null && Number(rental.rental_fee) > 0 && (
                <div className="text-sm font-semibold tabular-nums text-right" style={{ minWidth: 80 }}>
                  {Number(rental.rental_fee).toLocaleString("de-DE")} €
                  <div className="text-[10px] font-normal" style={{ color: "var(--color-muted-foreground)" }}>Leihgebühr</div>
                </div>
              )}

              {/* Kaution */}
              {Number(rental.deposit_amount) > 0 && (
                <div className="text-sm font-semibold tabular-nums text-right" style={{ minWidth: 80 }}>
                  {Number(rental.deposit_amount).toLocaleString("de-DE")} €
                  <div className="text-[10px] font-normal" style={{ color: "var(--color-muted-foreground)" }}>Kaution</div>
                </div>
              )}

              {canEdit && (
                <button
                  onClick={(e) => handleDelete(e, rental.id)}
                  className="opacity-0 group-hover:opacity-100 p-1.5 rounded transition-opacity flex-shrink-0"
                  style={{ color: "var(--color-destructive)" }}
                  title="Löschen"
                >
                  <IconTrash size={14} />
                </button>
              )}
              <IconChevronRight size={16} className="opacity-30 group-hover:opacity-60 transition-opacity flex-shrink-0" />
            </div>
          ))}
        </div>
      )}

      {/* Mein Equipment unterwegs — Verleihe mit Items, die mir gehören */}
      {myItemRentals.length > 0 && (
        <div className="mt-10">
          <h2 className="text-lg font-bold mb-3 flex items-center gap-2">
            <IconPackage size={18} />
            Mein Equipment unterwegs
            <span
              className="text-xs px-2 py-0.5 rounded-full font-medium"
              style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}
            >
              {myItemRentals.length}
            </span>
          </h2>
          <p className="text-sm mb-4" style={{ color: "var(--color-muted-foreground)" }}>
            Geräte aus deinem Inventar, die über eine Gruppe verliehen werden.
          </p>
          <div className="space-y-2">
            {myItemRentals.map((row) => {
              const status = row.approval_status;
              const showActions = status === "pending";
              const days = Math.max(
                1,
                Math.round((new Date(row.rental.date_to).getTime() - new Date(row.rental.date_from).getTime()) / 86400000) + 1
              );
              return (
                <div
                  key={row.rental_item_id}
                  className="flex flex-wrap items-center gap-3 p-3 rounded-xl"
                  style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}
                >
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="font-medium text-sm">{row.item_name}</span>
                      <span className="text-xs tabular-nums" style={{ color: "var(--color-muted-foreground)" }}>
                        {row.inventory_number} · {row.quantity}×
                      </span>
                      <span
                        className="text-xs font-medium px-1.5 py-0.5 rounded"
                        style={{ background: approvalBadgeColors[status].bg, color: approvalBadgeColors[status].color }}
                        title={status === "rejected" && row.rejection_reason ? `Grund: ${row.rejection_reason}` : undefined}
                      >
                        {rentalItemApprovalLabels[status]}
                      </span>
                    </div>
                    <div className="flex items-center gap-3 mt-0.5 text-xs flex-wrap" style={{ color: "var(--color-muted-foreground)" }}>
                      <span>
                        an <strong style={{ color: "var(--color-foreground)" }}>{row.rental.borrower_name}</strong>
                      </span>
                      <span>
                        {new Date(row.rental.date_from).toLocaleDateString("de-DE", { day: "numeric", month: "short" })}
                        {" – "}
                        {new Date(row.rental.date_to).toLocaleDateString("de-DE", { day: "numeric", month: "short", year: "numeric" })}
                        {" "}({days}t)
                      </span>
                      {row.rental.rental_fee != null && (
                        <span>Leihgebühr: {Number(row.rental.rental_fee).toLocaleString("de-DE")} €</span>
                      )}
                    </div>
                  </div>
                  {showActions && (
                    <MyItemApprovalControls
                      proposed={Number(row.proposed_rate ?? 0)}
                      onApprove={(rate) => handleApproveItem(row.rental_item_id, rate)}
                      onReject={() => handleRejectItem(row.rental_item_id)}
                    />
                  )}
                  <button
                    onClick={() => router.push(`/rentals/${row.rental_id}`)}
                    className="text-xs px-2 py-1 rounded"
                    style={{ border: "1px solid var(--color-border)", color: "var(--color-muted-foreground)" }}
                  >
                    Verleih ansehen
                  </button>
                </div>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}
