"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useWorkspace } from "@/contexts/org-context";
import { IconPlus, IconTrash, IconSearch, IconX } from "@/components/ui/icons";
import type { InventoryItem, InventoryAvailability } from "@/types/database";

export type PickedItem = {
  inventory_item_id: string;
  quantity: number;
  // gecacht
  itemName: string;
  itemQuantity: number;
  availability?: InventoryAvailability;
};

interface Props {
  dateFrom: string;
  dateTo: string;
  picked: PickedItem[];
  onChange: (next: PickedItem[]) => void;
  /** ID einer bestehenden Rental, damit sie sich nicht selbst als Konflikt zaehlt */
  excludeRentalId?: string | null;
}

export function EquipmentPicker({ dateFrom, dateTo, picked, onChange, excludeRentalId }: Props) {
  const supabase = createClient();
  const currentUser = useCurrentUser();
  const { groupId } = useWorkspace();
  const ownerId = currentUser?.id ?? null;

  const [items, setItems] = useState<InventoryItem[]>([]);
  const [search, setSearch] = useState("");
  const [open, setOpen] = useState(false);

  // Verfuegbarkeit nachladen wenn Zeitraum oder Auswahl wechselt
  const refreshAvailability = useCallback(async () => {
    if (!dateFrom || !dateTo || picked.length === 0) return;
    const updated = await Promise.all(
      picked.map(async (p) => {
        const { data, error } = await supabase.rpc("check_inventory_availability", {
          p_item_id: p.inventory_item_id,
          p_date_from: dateFrom,
          p_date_to: dateTo,
          p_exclude_rental_id: excludeRentalId ?? null,
          p_exclude_booking_id: null,
        });
        if (error || !data || !data[0]) return p;
        return { ...p, availability: data[0] as InventoryAvailability };
      })
    );
    onChange(updated);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dateFrom, dateTo, excludeRentalId]);

  useEffect(() => {
    refreshAvailability();
  }, [refreshAvailability]);

  // Inventar laden (im Workspace-Scope)
  useEffect(() => {
    async function load() {
      if (!ownerId) return;
      const query = groupId
        ? supabase.from("inventory_items").select("*").eq("owner_group_id", groupId)
        : supabase.from("inventory_items").select("*").eq("owner_profile_id", ownerId);
      const { data } = await query.order("name");
      if (data) setItems(data as InventoryItem[]);
    }
    load();
  }, [supabase, ownerId, groupId]);

  const pickedIds = useMemo(() => new Set(picked.map((p) => p.inventory_item_id)), [picked]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return items
      .filter((i) => !pickedIds.has(i.id))
      .filter((i) => !q || i.name.toLowerCase().includes(q) || i.inventory_number.toLowerCase().includes(q));
  }, [items, pickedIds, search]);

  async function handleAdd(item: InventoryItem) {
    let availability: InventoryAvailability | undefined;
    if (dateFrom && dateTo) {
      const { data } = await supabase.rpc("check_inventory_availability", {
        p_item_id: item.id,
        p_date_from: dateFrom,
        p_date_to: dateTo,
        p_exclude_rental_id: excludeRentalId ?? null,
        p_exclude_booking_id: null,
      });
      if (data && data[0]) availability = data[0] as InventoryAvailability;
    }
    onChange([
      ...picked,
      {
        inventory_item_id: item.id,
        quantity: 1,
        itemName: item.name,
        itemQuantity: item.quantity,
        availability,
      },
    ]);
    setOpen(false);
    setSearch("");
  }

  function updateQuantity(idx: number, quantity: number) {
    const next = [...picked];
    next[idx] = { ...next[idx], quantity: Math.max(1, quantity) };
    onChange(next);
  }

  function remove(idx: number) {
    const next = [...picked];
    next.splice(idx, 1);
    onChange(next);
  }

  const datesSet = Boolean(dateFrom && dateTo);

  return (
    <div className="space-y-2">
      {/* Auswahl-Liste */}
      {picked.length > 0 && (
        <div className="space-y-1.5">
          {picked.map((p, idx) => {
            const av = p.availability;
            const conflict = datesSet && av && p.quantity > av.available;
            const allOk = datesSet && av && p.quantity <= av.available;
            return (
              <div
                key={p.inventory_item_id}
                className="flex items-center gap-2 px-3 py-2 rounded-lg"
                style={{
                  border: `1px solid ${conflict ? "var(--color-destructive)" : "var(--color-border-light)"}`,
                  background: conflict ? "var(--color-destructive-light)" : "var(--color-background)",
                }}
              >
                <span className="flex-1 text-sm truncate">{p.itemName}</span>
                <input
                  type="number"
                  min={1}
                  value={p.quantity}
                  onChange={(e) => updateQuantity(idx, parseInt(e.target.value) || 1)}
                  className="w-16 px-2 py-1 rounded text-sm text-center"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
                />
                <span className="text-xs tabular-nums" style={{ color: "var(--color-muted-foreground)", minWidth: 90 }}>
                  {datesSet
                    ? av
                      ? `${av.available} von ${av.total} frei`
                      : "wird geprüft..."
                    : `Bestand: ${p.itemQuantity}`}
                </span>
                {conflict && (
                  <span
                    className="text-xs font-medium px-1.5 py-0.5 rounded"
                    style={{ background: "var(--color-destructive)", color: "#fff" }}
                  >
                    Konflikt
                  </span>
                )}
                {allOk && (
                  <span
                    className="text-xs font-medium px-1.5 py-0.5 rounded"
                    style={{ background: "var(--color-success-light)", color: "var(--color-success)" }}
                  >
                    OK
                  </span>
                )}
                <button
                  type="button"
                  onClick={() => remove(idx)}
                  className="p-1 rounded"
                  style={{ color: "var(--color-destructive)" }}
                  title="Entfernen"
                >
                  <IconTrash size={14} />
                </button>
              </div>
            );
          })}
        </div>
      )}

      {/* Hinzufuegen-Button + Dropdown */}
      {!open ? (
        <button
          type="button"
          onClick={() => setOpen(true)}
          className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm w-full"
          style={{ border: "1px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}
        >
          <IconPlus size={14} />
          Equipment hinzufügen
        </button>
      ) : (
        <div
          className="p-2 rounded-lg"
          style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
        >
          <div className="relative mb-2">
            <IconSearch
              size={14}
              className="absolute left-2.5 top-1/2 -translate-y-1/2"
              style={{ color: "var(--color-muted-foreground)" } as React.CSSProperties}
            />
            <input
              autoFocus
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Suchen..."
              className="w-full pl-8 pr-8 py-1.5 rounded text-sm"
              style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
            />
            <button
              type="button"
              onClick={() => {
                setOpen(false);
                setSearch("");
              }}
              className="absolute right-2 top-1/2 -translate-y-1/2"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              <IconX size={14} />
            </button>
          </div>
          <div className="max-h-56 overflow-y-auto space-y-1">
            {filtered.length === 0 ? (
              <div className="text-xs text-center py-3" style={{ color: "var(--color-muted-foreground)" }}>
                {items.length === 0 ? "Kein Inventar vorhanden" : "Keine Treffer"}
              </div>
            ) : (
              filtered.slice(0, 40).map((item) => (
                <button
                  key={item.id}
                  type="button"
                  onClick={() => handleAdd(item)}
                  className="w-full flex items-center justify-between px-2.5 py-1.5 rounded text-sm text-left transition-colors"
                  onMouseEnter={(e) => (e.currentTarget.style.background = "var(--color-muted)")}
                  onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}
                >
                  <span className="flex-1 truncate">{item.name}</span>
                  <span className="text-xs ml-2" style={{ color: "var(--color-muted-foreground)" }}>
                    {item.inventory_number} · {item.quantity} Stk
                  </span>
                </button>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
}
