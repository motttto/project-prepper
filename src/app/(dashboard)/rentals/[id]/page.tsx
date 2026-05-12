"use client";

import { useState, useEffect, useCallback, useMemo, use } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { createClient } from "@/lib/supabase";
import { useCurrentUser, hasPermission } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import { DateInput } from "@/components/ui/date-input";
import { showToast } from "@/hooks/use-toast";
import { appConfirm } from "@/components/ui/confirm-dialog";
import { EquipmentPicker, type PickedItem } from "@/components/rentals/equipment-picker";
import type {
  Rental,
  RentalItem,
  RentalStatus,
  InventoryAvailability,
  RentalItemApprovalStatus,
} from "@/types/database";
import { rentalItemApprovalLabels } from "@/types/database";
import {
  IconChevronLeft,
  IconTrash,
  IconPackage,
  IconCheck,
  IconX,
} from "@/components/ui/icons";

const approvalColors: Record<RentalItemApprovalStatus, { bg: string; color: string }> = {
  auto: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)" },
  pending: { bg: "var(--color-warning-light)", color: "var(--color-warning)" },
  approved: { bg: "var(--color-success-light)", color: "var(--color-success)" },
  rejected: { bg: "var(--color-destructive-light)", color: "var(--color-destructive)" },
};

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

type RentalItemWithAvailability = RentalItem & { availability?: InventoryAvailability };

export default function RentalDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const supabase = createClient();
  const router = useRouter();
  const currentUser = useCurrentUser();
  const canEdit = hasPermission(currentUser, "rentals_edit");

  const [rental, setRental] = useState<Rental | null>(null);
  const [items, setItems] = useState<RentalItemWithAvailability[]>([]);
  const [itemOwnerLabels, setItemOwnerLabels] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(true);
  const [editMode, setEditMode] = useState(false);
  const [saving, setSaving] = useState(false);

  // Edit-Form State
  const [editBorrowerName, setEditBorrowerName] = useState("");
  const [editBorrowerEmail, setEditBorrowerEmail] = useState("");
  const [editBorrowerPhone, setEditBorrowerPhone] = useState("");
  const [editBorrowerAddress, setEditBorrowerAddress] = useState("");
  const [editDateFrom, setEditDateFrom] = useState("");
  const [editDateTo, setEditDateTo] = useState("");
  const [editDeposit, setEditDeposit] = useState("");
  const [editFee, setEditFee] = useState("");
  const [editNotes, setEditNotes] = useState("");
  const [editItems, setEditItems] = useState<PickedItem[]>([]);

  const load = useCallback(async () => {
    const { data: rentalData, error } = await supabase
      .from("rentals")
      .select("*")
      .eq("id", id)
      .single();
    if (error || !rentalData) {
      setLoading(false);
      return;
    }
    setRental(rentalData as Rental);

    const { data: itemsData } = await supabase
      .from("rental_items")
      .select("*, inventory_items(id, name, inventory_number, image_url, quantity, owner_profile_id, owner_group_id, loan_approval_mode)")
      .eq("rental_id", id)
      .order("created_at");

    if (itemsData) {
      // Verfuegbarkeit pro Item (nur reservierte, andere als sich selbst)
      const enriched = await Promise.all(
        itemsData.map(async (it) => {
          const { data: av } = await supabase.rpc("check_inventory_availability", {
            p_item_id: it.inventory_item_id,
            p_date_from: rentalData.date_from,
            p_date_to: rentalData.date_to,
            p_exclude_rental_id: id,
            p_exclude_booking_id: null,
          });
          return { ...it, availability: av?.[0] as InventoryAvailability | undefined };
        })
      );
      const itemsWithMeta = enriched as RentalItemWithAvailability[];
      setItems(itemsWithMeta);

      // Owner-Labels aufloesen (Profil-Name oder Group-Name)
      const profileIds = Array.from(
        new Set(
          itemsWithMeta
            .map((it) => (it.inventory_items as { owner_profile_id?: string | null } | null)?.owner_profile_id)
            .filter((x): x is string => !!x)
        )
      );
      const groupIds = Array.from(
        new Set(
          itemsWithMeta
            .map((it) => (it.inventory_items as { owner_group_id?: string | null } | null)?.owner_group_id)
            .filter((x): x is string => !!x)
        )
      );
      const [profilesRes, groupsRes] = await Promise.all([
        profileIds.length
          ? supabase.from("profiles").select("id, name").in("id", profileIds)
          : Promise.resolve({ data: [] as { id: string; name: string }[] }),
        groupIds.length
          ? supabase.from("groups").select("id, name").in("id", groupIds)
          : Promise.resolve({ data: [] as { id: string; name: string }[] }),
      ]);
      const pMap = new Map((profilesRes.data ?? []).map((p) => [p.id, p.name]));
      const gMap = new Map((groupsRes.data ?? []).map((g) => [g.id, g.name]));
      const labels: Record<string, string> = {};
      for (const it of itemsWithMeta) {
        const inv = it.inventory_items as { owner_profile_id?: string | null; owner_group_id?: string | null } | null;
        if (inv?.owner_profile_id) {
          const name = pMap.get(inv.owner_profile_id) ?? "—";
          labels[it.inventory_item_id] =
            inv.owner_profile_id === currentUser?.id ? `${name} (du)` : name;
        } else if (inv?.owner_group_id) {
          labels[it.inventory_item_id] = `🛡️ ${gMap.get(inv.owner_group_id) ?? "Gruppe"}`;
        }
      }
      setItemOwnerLabels(labels);
    }
    setLoading(false);
  }, [supabase, id, currentUser?.id]);

  useEffect(() => {
    load();
  }, [load]);

  useRealtimeTable({ table: "rentals", onDataChange: load });
  useRealtimeTable({ table: "rental_items", onDataChange: load });

  function enterEditMode() {
    if (!rental) return;
    setEditBorrowerName(rental.borrower_name);
    setEditBorrowerEmail(rental.borrower_email ?? "");
    setEditBorrowerPhone(rental.borrower_phone ?? "");
    setEditBorrowerAddress(rental.borrower_address ?? "");
    setEditDateFrom(rental.date_from);
    setEditDateTo(rental.date_to);
    setEditDeposit(String(rental.deposit_amount ?? ""));
    setEditFee(rental.rental_fee != null ? String(rental.rental_fee) : "");
    setEditNotes(rental.notes ?? "");
    setEditItems(
      items.map((it) => ({
        inventory_item_id: it.inventory_item_id,
        quantity: it.quantity,
        itemName: it.inventory_items?.name ?? "Gerät",
        itemQuantity: 0,
        ownerLabel: itemOwnerLabels[it.inventory_item_id],
        availability: it.availability,
      }))
    );
    setEditMode(true);
  }

  async function handleSave(e: React.FormEvent) {
    e.preventDefault();
    if (!rental) return;
    if (editItems.length === 0) {
      showToast("Mindestens ein Gerät auswählen", "error");
      return;
    }
    const conflicts = editItems.filter((p) => p.availability && p.quantity > p.availability.available);
    if (conflicts.length > 0) {
      showToast(`Konflikt: ${conflicts.map((c) => c.itemName).join(", ")} im Zeitraum nicht verfügbar`, "error");
      return;
    }
    setSaving(true);

    const { error: updErr } = await supabase
      .from("rentals")
      .update({
        borrower_name: editBorrowerName,
        borrower_email: editBorrowerEmail || null,
        borrower_phone: editBorrowerPhone || null,
        borrower_address: editBorrowerAddress || null,
        date_from: editDateFrom,
        date_to: editDateTo,
        deposit_amount: editDeposit ? parseFloat(editDeposit) : 0,
        rental_fee: editFee ? parseFloat(editFee) : null,
        notes: editNotes || null,
      })
      .eq("id", rental.id);

    if (updErr) {
      showToast("Fehler: " + updErr.message, "error");
      setSaving(false);
      return;
    }

    // Diff statt delete-all/insert-all, damit approval_status erhalten bleibt.
    const existing = new Map(items.map((it) => [it.inventory_item_id, it]));
    const desired = new Map(editItems.map((p) => [p.inventory_item_id, p]));

    const toDelete = items.filter((it) => !desired.has(it.inventory_item_id));
    const toInsert = editItems.filter((p) => !existing.has(p.inventory_item_id));
    const toUpdate = editItems.filter((p) => {
      const ex = existing.get(p.inventory_item_id);
      return ex && ex.quantity !== p.quantity;
    });

    if (toDelete.length > 0) {
      const { error } = await supabase.from("rental_items").delete().in("id", toDelete.map((it) => it.id));
      if (error) {
        showToast("Fehler bei Equipment: " + error.message, "error");
        setSaving(false);
        return;
      }
    }
    if (toInsert.length > 0) {
      const { error } = await supabase.from("rental_items").insert(
        toInsert.map((p) => ({
          rental_id: rental.id,
          inventory_item_id: p.inventory_item_id,
          quantity: p.quantity,
        }))
      );
      if (error) {
        showToast("Fehler bei Equipment: " + error.message, "error");
        setSaving(false);
        return;
      }
    }
    for (const p of toUpdate) {
      const ex = existing.get(p.inventory_item_id)!;
      const { error } = await supabase
        .from("rental_items")
        .update({ quantity: p.quantity })
        .eq("id", ex.id);
      if (error) {
        showToast("Fehler bei Equipment: " + error.message, "error");
        setSaving(false);
        return;
      }
    }

    setEditMode(false);
    setSaving(false);
    showToast("Gespeichert", "success");
    load();
  }

  async function handleStatusChange(newStatus: RentalStatus) {
    if (!rental) return;
    const { error } = await supabase.from("rentals").update({ status: newStatus }).eq("id", rental.id);
    if (error) showToast("Fehler: " + error.message, "error");
    else load();
  }

  async function handleApprove(rentalItemId: string) {
    const { error } = await supabase.rpc("approve_rental_item", { p_rental_item_id: rentalItemId });
    if (error) showToast("Fehler: " + error.message, "error");
    else {
      showToast("Freigegeben", "success");
      load();
    }
  }

  async function handleReject(rentalItemId: string) {
    const reason = window.prompt("Grund für die Ablehnung (optional):") ?? null;
    const { error } = await supabase.rpc("reject_rental_item", {
      p_rental_item_id: rentalItemId,
      p_reason: reason,
    });
    if (error) showToast("Fehler: " + error.message, "error");
    else {
      showToast("Abgelehnt", "success");
      load();
    }
  }

  async function handleDelete() {
    if (!rental) return;
    if (!(await appConfirm("Verleih wirklich löschen?", { variant: "danger", confirmLabel: "Löschen" }))) return;
    const { error } = await supabase.from("rentals").delete().eq("id", rental.id);
    if (error) showToast("Fehler: " + error.message, "error");
    else {
      showToast("Verleih gelöscht", "success");
      router.push("/rentals");
    }
  }

  const days = useMemo(() => {
    if (!rental) return 0;
    const ms = new Date(rental.date_to).getTime() - new Date(rental.date_from).getTime();
    return Math.max(1, Math.round(ms / 86400000) + 1);
  }, [rental]);

  if (loading) {
    return (
      <div className="space-y-4">
        <div className="h-8 w-48 skeleton" />
        <div className="h-32 skeleton rounded-xl" />
        <div className="h-48 skeleton rounded-xl" />
      </div>
    );
  }

  if (!rental) {
    return (
      <div className="text-center py-16">
        <p style={{ color: "var(--color-muted-foreground)" }}>Verleih nicht gefunden.</p>
        <Link href="/rentals" className="text-sm mt-3 inline-block" style={{ color: "var(--color-primary)" }}>
          ← Zurück zur Liste
        </Link>
      </div>
    );
  }

  return (
    <div className="animate-fadeIn space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between gap-4">
        <div className="flex items-center gap-3 min-w-0">
          <Link
            href="/rentals"
            className="p-2 rounded-lg transition-colors"
            style={{ color: "var(--color-muted-foreground)" }}
            onMouseEnter={(e) => (e.currentTarget.style.background = "var(--color-muted)")}
            onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}
            title="Zurück"
          >
            <IconChevronLeft size={20} />
          </Link>
          <div className="min-w-0">
            <h1 className="text-2xl font-bold truncate">{rental.borrower_name}</h1>
            <div className="flex items-center gap-2 mt-1 text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              <span
                className="text-xs px-2 py-0.5 rounded-full font-medium"
                style={{ background: statusColors[rental.status].bg, color: statusColors[rental.status].color }}
              >
                {statusLabels[rental.status]}
              </span>
              <span>·</span>
              <span>{days} {days === 1 ? "Tag" : "Tage"}</span>
            </div>
          </div>
        </div>
        {canEdit && !editMode && (
          <div className="flex items-center gap-2">
            <select
              value={rental.status}
              onChange={(e) => handleStatusChange(e.target.value as RentalStatus)}
              className="px-3 py-2 rounded-lg text-sm"
              style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
            >
              {statusOrder.map((s) => (
                <option key={s} value={s}>{statusLabels[s]}</option>
              ))}
            </select>
            <button
              onClick={enterEditMode}
              className="px-4 py-2 rounded-lg text-sm font-medium"
              style={{ border: "1px solid var(--color-border)" }}
            >
              Bearbeiten
            </button>
            <button
              onClick={handleDelete}
              className="p-2 rounded-lg"
              style={{ color: "var(--color-destructive)", border: "1px solid var(--color-border)" }}
              title="Löschen"
            >
              <IconTrash size={16} />
            </button>
          </div>
        )}
      </div>

      {/* Edit-Modus */}
      {editMode ? (
        <form
          onSubmit={handleSave}
          className="p-6 rounded-xl space-y-4"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
        >
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium mb-1.5">Name *</label>
              <input
                type="text"
                value={editBorrowerName}
                onChange={(e) => setEditBorrowerName(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                required
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1.5">E-Mail</label>
              <input
                type="email"
                value={editBorrowerEmail}
                onChange={(e) => setEditBorrowerEmail(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1.5">Telefon</label>
              <input
                type="tel"
                value={editBorrowerPhone}
                onChange={(e) => setEditBorrowerPhone(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1.5">Adresse</label>
              <input
                type="text"
                value={editBorrowerAddress}
                onChange={(e) => setEditBorrowerAddress(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
              />
            </div>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
              <label className="block text-sm font-medium mb-1.5">Von *</label>
              <DateInput value={editDateFrom} onChange={setEditDateFrom} required />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1.5">Bis *</label>
              <DateInput value={editDateTo} onChange={setEditDateTo} required />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1.5">Kaution (€)</label>
              <input
                type="number"
                value={editDeposit}
                onChange={(e) => setEditDeposit(e.target.value)}
                min={0}
                step={0.01}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1.5">Leihgebühr (€)</label>
              <input
                type="number"
                value={editFee}
                onChange={(e) => setEditFee(e.target.value)}
                min={0}
                step={0.01}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
              />
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium mb-1.5">Equipment *</label>
            <EquipmentPicker
              dateFrom={editDateFrom}
              dateTo={editDateTo}
              picked={editItems}
              onChange={setEditItems}
              excludeRentalId={rental.id}
            />
          </div>
          <div>
            <label className="block text-sm font-medium mb-1.5">Notizen</label>
            <textarea
              value={editNotes}
              onChange={(e) => setEditNotes(e.target.value)}
              rows={2}
              className="w-full px-3 py-2 rounded-lg text-sm"
              style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
            />
          </div>
          <div className="flex gap-2 pt-2">
            <button
              type="submit"
              disabled={saving}
              className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
              style={{ background: "var(--color-primary)" }}
            >
              <IconCheck size={16} />
              {saving ? "Wird gespeichert..." : "Speichern"}
            </button>
            <button
              type="button"
              onClick={() => setEditMode(false)}
              className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm"
              style={{ border: "1px solid var(--color-border)" }}
            >
              <IconX size={16} />
              Abbrechen
            </button>
          </div>
        </form>
      ) : (
        <>
          {/* Person + Zeitraum + Geld */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div
              className="p-5 rounded-xl"
              style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}
            >
              <h3 className="text-sm font-semibold mb-3" style={{ color: "var(--color-muted-foreground)" }}>Leiher</h3>
              <div className="space-y-2 text-sm">
                <div className="font-medium">{rental.borrower_name}</div>
                {rental.borrower_email && (
                  <div>
                    <a href={`mailto:${rental.borrower_email}`} style={{ color: "var(--color-primary)" }}>
                      {rental.borrower_email}
                    </a>
                  </div>
                )}
                {rental.borrower_phone && (
                  <div>
                    <a href={`tel:${rental.borrower_phone}`} style={{ color: "var(--color-primary)" }}>
                      {rental.borrower_phone}
                    </a>
                  </div>
                )}
                {rental.borrower_address && (
                  <div style={{ color: "var(--color-muted-foreground)" }}>{rental.borrower_address}</div>
                )}
              </div>
            </div>
            <div
              className="p-5 rounded-xl"
              style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}
            >
              <h3 className="text-sm font-semibold mb-3" style={{ color: "var(--color-muted-foreground)" }}>Verleih</h3>
              <div className="space-y-2 text-sm">
                <div>
                  <span style={{ color: "var(--color-muted-foreground)" }}>Zeitraum: </span>
                  <span className="font-medium">
                    {new Date(rental.date_from).toLocaleDateString("de-DE")} – {new Date(rental.date_to).toLocaleDateString("de-DE")}
                  </span>
                  <span className="ml-2 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    ({days} {days === 1 ? "Tag" : "Tage"})
                  </span>
                </div>
                <div>
                  <span style={{ color: "var(--color-muted-foreground)" }}>Kaution: </span>
                  <span className="font-medium tabular-nums">{Number(rental.deposit_amount).toLocaleString("de-DE")} €</span>
                </div>
                {rental.rental_fee != null && (
                  <div>
                    <span style={{ color: "var(--color-muted-foreground)" }}>Leihgebühr: </span>
                    <span className="font-medium tabular-nums">{Number(rental.rental_fee).toLocaleString("de-DE")} €</span>
                  </div>
                )}
              </div>
            </div>
          </div>

          {/* Equipment */}
          <div
            className="p-5 rounded-xl"
            style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}
          >
            <h3 className="text-sm font-semibold mb-3 flex items-center gap-2" style={{ color: "var(--color-muted-foreground)" }}>
              <IconPackage size={14} />
              Equipment ({items.length})
            </h3>
            {items.length === 0 ? (
              <div className="text-sm text-center py-6" style={{ color: "var(--color-muted-foreground)" }}>
                Keine Geräte zugewiesen.
              </div>
            ) : (
              <div className="space-y-1.5">
                {items.map((it) => {
                  const av = it.availability;
                  const conflict = av && it.quantity > av.available;
                  const ownerId = it.inventory_items?.owner_profile_id ?? null;
                  const isMyItem = currentUser?.id != null && ownerId === currentUser.id;
                  const status = it.approval_status;
                  const showApprovalActions = isMyItem && status === "pending";
                  return (
                    <div
                      key={it.id}
                      className="flex flex-wrap items-center gap-3 px-3 py-2 rounded-lg"
                      style={{
                        border: `1px solid ${conflict ? "var(--color-destructive)" : "var(--color-border-light)"}`,
                        background: conflict ? "var(--color-destructive-light)" : "var(--color-background)",
                      }}
                    >
                      <div className="flex-1 min-w-0">
                        <div className="text-sm truncate">{it.inventory_items?.name ?? "Gerät"}</div>
                        {itemOwnerLabels[it.inventory_item_id] && (
                          <div className="text-[11px] truncate" style={{ color: "var(--color-muted-foreground)" }}>
                            Eigentümer: {itemOwnerLabels[it.inventory_item_id]}
                          </div>
                        )}
                      </div>
                      <span className="text-xs tabular-nums" style={{ color: "var(--color-muted-foreground)" }}>
                        {it.inventory_items?.inventory_number}
                      </span>
                      <span className="text-sm font-semibold tabular-nums">{it.quantity}×</span>
                      {av && status !== "rejected" && (
                        <span className="text-xs tabular-nums" style={{ color: "var(--color-muted-foreground)" }}>
                          {av.available + it.quantity} von {av.total} sonst frei
                        </span>
                      )}
                      {/* Approval-Status-Badge — nur anzeigen wenn nicht 'auto' */}
                      {status !== "auto" && (
                        <span
                          className="text-xs font-medium px-1.5 py-0.5 rounded"
                          style={{ background: approvalColors[status].bg, color: approvalColors[status].color }}
                          title={status === "rejected" && it.rejection_reason ? `Grund: ${it.rejection_reason}` : undefined}
                        >
                          {rentalItemApprovalLabels[status]}
                        </span>
                      )}
                      {conflict && status !== "rejected" && (
                        <span
                          className="text-xs font-medium px-1.5 py-0.5 rounded"
                          style={{ background: "var(--color-destructive)", color: "#fff" }}
                        >
                          Überbucht
                        </span>
                      )}
                      {showApprovalActions && (
                        <div className="flex items-center gap-1 ml-auto">
                          <button
                            onClick={() => handleApprove(it.id)}
                            className="px-2 py-1 rounded text-xs font-medium text-white"
                            style={{ background: "var(--color-success)" }}
                            title="Ausleihe freigeben"
                          >
                            Freigeben
                          </button>
                          <button
                            onClick={() => handleReject(it.id)}
                            className="px-2 py-1 rounded text-xs font-medium"
                            style={{ border: "1px solid var(--color-destructive)", color: "var(--color-destructive)" }}
                            title="Ausleihe ablehnen"
                          >
                            Ablehnen
                          </button>
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </div>

          {/* Notizen */}
          {rental.notes && (
            <div
              className="p-5 rounded-xl"
              style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}
            >
              <h3 className="text-sm font-semibold mb-2" style={{ color: "var(--color-muted-foreground)" }}>Notizen</h3>
              <p className="text-sm whitespace-pre-wrap">{rental.notes}</p>
            </div>
          )}
        </>
      )}
    </div>
  );
}
