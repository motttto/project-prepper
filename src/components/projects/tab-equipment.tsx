"use client";

// Equipment-Tab V1 (Phase 6.5)
// ============================
// Solo-Projekt: Owner bucht aus eigenem Inventar
// Group-Projekt: Buchungen aus eigenem + via inventory_group_shares
//                freigegebenem Inventar (item-Owner sieht/genehmigt)

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type { Project, InventoryItem, Booking } from "@/types/database";
import {
  IconPlus,
  IconPackage,
  IconTrash,
  IconX,
  IconCheck,
} from "@/components/ui/icons";
import { showToast } from "@/hooks/use-toast";

type BookingWithItem = Booking & {
  inventory_items: InventoryItem & { owner_profile_id: string | null };
};

interface TabEquipmentProps {
  projectId: string;
  project: Project;
  currentOrgId?: string | null;
}

const statusLabels: Record<string, string> = {
  reserved: "Reserviert",
  checked_out: "Ausgecheckt",
  returned: "Zurueckgegeben",
};

const statusColors: Record<string, { bg: string; color: string }> = {
  reserved: { bg: "var(--color-info-light)", color: "var(--color-info)" },
  checked_out: { bg: "var(--color-warning-light)", color: "var(--color-warning)" },
  returned: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)" },
};

export function TabEquipment({ projectId, project }: TabEquipmentProps) {
  const supabase = createClient();
  const currentUser = useCurrentUser();
  const [bookings, setBookings] = useState<BookingWithItem[]>([]);
  const [availableItems, setAvailableItems] = useState<(InventoryItem & { ownerName?: string; daily_rate?: number })[]>([]);
  const [loading, setLoading] = useState(true);
  const [showPicker, setShowPicker] = useState(false);

  const loadAll = useCallback(async () => {
    if (!currentUser) return;

    // 1. Buchungen fuer dieses Projekt
    const { data: bookingsData } = await supabase
      .from("bookings")
      .select("*, inventory_items(*)")
      .eq("project_id", projectId)
      .order("date_from", { ascending: false });

    if (bookingsData) setBookings(bookingsData as BookingWithItem[]);

    // 2. Verfuegbare Items: eigenes Inventar
    const { data: ownItems } = await supabase
      .from("inventory_items")
      .select("*")
      .eq("owner_profile_id", currentUser.id);

    let allItems: (InventoryItem & { ownerName?: string; daily_rate?: number })[] = (ownItems || []).map(
      (i) => ({ ...(i as InventoryItem), ownerName: "Du", daily_rate: i.cost_per_day })
    );

    // 3. Falls Projekt zu einer Gruppe gehoert: zusaetzlich Items, die fuer diese Gruppe freigegeben sind
    if (project.group_id) {
      const { data: shares } = await supabase
        .from("inventory_group_shares")
        .select("inventory_item_id, daily_rate, inventory_items(*, owner:profiles!owner_profile_id(name))")
        .eq("group_id", project.group_id)
        .is("revoked_at", null);

      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const sharedItems = (shares || []).map((s: any) => ({
        ...(s.inventory_items as InventoryItem),
        ownerName: s.inventory_items?.owner?.name || "?",
        daily_rate: Number(s.daily_rate),
      }));

      // Duplikate vermeiden (eigene Items koennten dabei sein)
      const ownIds = new Set(allItems.map((i) => i.id));
      sharedItems.forEach((i) => {
        if (!ownIds.has(i.id)) allItems.push(i);
      });
    }

    setAvailableItems(allItems);
    setLoading(false);
  }, [supabase, currentUser, projectId, project.group_id]);

  useEffect(() => {
    loadAll();
  }, [loadAll]);

  useRealtimeTable({ table: "bookings", filter: { column: "project_id", value: projectId }, onDataChange: loadAll });

  async function handleAddBooking(itemId: string, quantity: number, dateFrom: string, dateTo: string) {
    if (!currentUser) return;
    const { error } = await supabase.from("bookings").insert({
      project_id: projectId,
      inventory_item_id: itemId,
      quantity,
      date_from: dateFrom,
      date_to: dateTo,
      status: "reserved",
      requested_by: currentUser.id,
    });
    if (error) {
      showToast("Fehler: " + error.message, "error");
    } else {
      showToast("Equipment gebucht", "success");
      setShowPicker(false);
      loadAll();
    }
  }

  async function handleDeleteBooking(id: string) {
    if (!confirm("Buchung wirklich loeschen?")) return;
    await supabase.from("bookings").delete().eq("id", id);
    loadAll();
  }

  async function handleStatusChange(id: string, status: string) {
    await supabase.from("bookings").update({ status }).eq("id", id);
    loadAll();
  }

  if (loading) {
    return <div className="py-8 text-center text-sm" style={{ color: "var(--color-muted-foreground)" }}>Lade...</div>;
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-lg font-semibold" style={{ color: "var(--color-foreground)" }}>
            Equipment-Buchungen
          </h2>
          <p className="text-xs mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>
            {project.group_id
              ? "Buchungen aus eigenem Inventar + freigegebenen Items der Gruppe"
              : "Buchungen aus deinem Inventar"}
          </p>
        </div>
        <button
          onClick={() => setShowPicker(true)}
          className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium text-white"
          style={{ background: "var(--color-primary)" }}
        >
          <IconPlus size={14} /> Buchung anlegen
        </button>
      </div>

      {bookings.length === 0 ? (
        <div
          className="rounded-xl p-12 text-center"
          style={{ border: "2px dashed var(--color-border)" }}
        >
          <IconPackage size={32} className="mx-auto mb-2" style={{ color: "var(--color-muted-foreground)", opacity: 0.5 }} />
          <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            Noch keine Buchungen. Klick &quot;Buchung anlegen&quot; um Equipment zu reservieren.
          </p>
        </div>
      ) : (
        <div className="space-y-2">
          {bookings.map((b) => (
            <div
              key={b.id}
              className="flex items-center justify-between p-3 rounded-lg"
              style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
            >
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>
                    {b.inventory_items?.name || "Item geloescht"}
                  </span>
                  <span
                    className="text-xs px-2 py-0.5 rounded-full font-medium"
                    style={{
                      background: statusColors[b.status]?.bg,
                      color: statusColors[b.status]?.color,
                    }}
                  >
                    {statusLabels[b.status] || b.status}
                  </span>
                  <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    {b.quantity}x · {b.date_from} bis {b.date_to}
                  </span>
                </div>
              </div>
              <div className="flex items-center gap-1">
                {b.status === "reserved" && (
                  <button
                    onClick={() => handleStatusChange(b.id, "checked_out")}
                    className="text-xs px-2 py-1 rounded"
                    style={{ color: "var(--color-warning)", border: "1px solid var(--color-warning)" }}
                  >
                    Auschecken
                  </button>
                )}
                {b.status === "checked_out" && (
                  <button
                    onClick={() => handleStatusChange(b.id, "returned")}
                    className="text-xs px-2 py-1 rounded"
                    style={{ color: "var(--color-success)", border: "1px solid var(--color-success)" }}
                  >
                    Zurueckgegeben
                  </button>
                )}
                <button
                  onClick={() => handleDeleteBooking(b.id)}
                  className="p-1.5 rounded"
                  style={{ color: "var(--color-destructive)" }}
                  title="Loeschen"
                >
                  <IconTrash size={14} />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {showPicker && (
        <ItemPickerModal
          items={availableItems}
          defaultDateFrom={project.date_start || ""}
          defaultDateTo={project.date_end || ""}
          onClose={() => setShowPicker(false)}
          onAdd={handleAddBooking}
        />
      )}
    </div>
  );
}

// ── Item-Picker Modal ──────────────────────────────────────────────────────

function ItemPickerModal({
  items,
  defaultDateFrom,
  defaultDateTo,
  onClose,
  onAdd,
}: {
  items: (InventoryItem & { ownerName?: string; daily_rate?: number })[];
  defaultDateFrom: string;
  defaultDateTo: string;
  onClose: () => void;
  onAdd: (itemId: string, quantity: number, from: string, to: string) => void;
}) {
  const [selectedId, setSelectedId] = useState(items[0]?.id || "");
  const [quantity, setQuantity] = useState(1);
  const [dateFrom, setDateFrom] = useState(defaultDateFrom);
  const [dateTo, setDateTo] = useState(defaultDateTo);
  const [search, setSearch] = useState("");

  const filtered = items.filter((i) =>
    !search.trim() || i.name.toLowerCase().includes(search.toLowerCase())
  );

  const selectedItem = items.find((i) => i.id === selectedId);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: "rgba(0,0,0,0.5)" }}>
      <div
        className="w-full max-w-lg rounded-xl shadow-2xl max-h-[90vh] overflow-y-auto"
        style={{ background: "var(--color-surface)" }}
      >
        <div
          className="flex items-center justify-between p-4 border-b sticky top-0 z-10"
          style={{ background: "var(--color-surface)", borderColor: "var(--color-border)" }}
        >
          <h3 className="text-base font-semibold" style={{ color: "var(--color-foreground)" }}>
            Equipment buchen
          </h3>
          <button onClick={onClose} className="p-1 rounded hover:opacity-70">
            <IconX size={18} />
          </button>
        </div>

        <div className="p-4 space-y-3">
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Suche..."
            className="w-full px-3 py-2 rounded-lg text-sm"
            style={{
              background: "var(--color-muted)",
              border: "1px solid var(--color-border)",
              color: "var(--color-foreground)",
            }}
          />

          {filtered.length === 0 ? (
            <div className="text-sm py-6 text-center" style={{ color: "var(--color-muted-foreground)" }}>
              Keine Items verfuegbar. Lege im Inventar Items an oder lass dir welche von deiner Gruppe freigeben.
            </div>
          ) : (
            <div className="max-h-64 overflow-y-auto space-y-1">
              {filtered.map((item) => (
                <label
                  key={item.id}
                  className="flex items-center gap-3 p-2 rounded-lg cursor-pointer"
                  style={{
                    background: selectedId === item.id ? "var(--color-primary-light)" : "var(--color-muted)",
                  }}
                >
                  <input
                    type="radio"
                    name="item"
                    value={item.id}
                    checked={selectedId === item.id}
                    onChange={(e) => setSelectedId(e.target.value)}
                  />
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>
                      {item.name}
                    </div>
                    <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                      Owner: {item.ownerName} · {item.quantity} verfuegbar
                      {item.daily_rate ? ` · ${Number(item.daily_rate).toFixed(2)}€/Tag` : ""}
                    </div>
                  </div>
                </label>
              ))}
            </div>
          )}

          {selectedItem && (
            <>
              <div className="grid grid-cols-3 gap-3">
                <div>
                  <label className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Menge</label>
                  <input
                    type="number"
                    min={1}
                    max={selectedItem.quantity}
                    value={quantity}
                    onChange={(e) => setQuantity(parseInt(e.target.value) || 1)}
                    className="w-full px-3 py-2 rounded-lg text-sm"
                    style={{
                      background: "var(--color-muted)",
                      border: "1px solid var(--color-border)",
                      color: "var(--color-foreground)",
                    }}
                  />
                </div>
                <div>
                  <label className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Von</label>
                  <input
                    type="date"
                    value={dateFrom}
                    onChange={(e) => setDateFrom(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm"
                    style={{
                      background: "var(--color-muted)",
                      border: "1px solid var(--color-border)",
                      color: "var(--color-foreground)",
                    }}
                  />
                </div>
                <div>
                  <label className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Bis</label>
                  <input
                    type="date"
                    value={dateTo}
                    onChange={(e) => setDateTo(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm"
                    style={{
                      background: "var(--color-muted)",
                      border: "1px solid var(--color-border)",
                      color: "var(--color-foreground)",
                    }}
                  />
                </div>
              </div>
              <button
                onClick={() => onAdd(selectedId, quantity, dateFrom, dateTo)}
                disabled={!dateFrom || !dateTo || quantity < 1}
                className="w-full flex items-center justify-center gap-1 px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}
              >
                <IconCheck size={14} /> Buchung anlegen
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
