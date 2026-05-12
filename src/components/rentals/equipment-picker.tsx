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
  /** Label des Eigentuemers (Profil-Name oder Group-Name + (du) falls eigen) */
  ownerLabel?: string;
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
  const [ownerLabels, setOwnerLabels] = useState<Record<string, string>>({});
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

  // Inventar laden (im Workspace-Scope) + Owner-Labels aufloesen
  // Siehe /modi-Skill: Gruppen-Picker enthaelt Gruppen-Items UNION shares.
  useEffect(() => {
    async function load() {
      if (!ownerId) return;

      let list: InventoryItem[] = [];

      if (groupId) {
        // 1) Items die der Gruppe direkt gehoeren
        const { data: groupItems } = await supabase
          .from("inventory_items")
          .select("*")
          .eq("owner_group_id", groupId);

        // 2) Items die Solo-User der Gruppe via inventory_group_shares freigegeben haben
        const { data: shares } = await supabase
          .from("inventory_group_shares")
          .select("inventory_item_id")
          .eq("group_id", groupId)
          .is("revoked_at", null);

        const sharedIds = (shares ?? []).map((s) => s.inventory_item_id);
        let sharedItems: InventoryItem[] = [];
        if (sharedIds.length > 0) {
          const { data } = await supabase
            .from("inventory_items")
            .select("*")
            .in("id", sharedIds);
          sharedItems = (data ?? []) as InventoryItem[];
        }

        // Dedupliziert (Items koennen theoretisch ueber beide Quellen kommen)
        const merged = new Map<string, InventoryItem>();
        for (const it of (groupItems ?? []) as InventoryItem[]) merged.set(it.id, it);
        for (const it of sharedItems) merged.set(it.id, it);
        list = Array.from(merged.values()).sort((a, b) => a.name.localeCompare(b.name));
      } else {
        const { data } = await supabase
          .from("inventory_items")
          .select("*")
          .eq("owner_profile_id", ownerId)
          .order("name");
        list = (data ?? []) as InventoryItem[];
      }

      setItems(list);

      // Owner-Labels in einem Rutsch nachladen
      const profileIds = Array.from(
        new Set(list.map((i) => i.owner_profile_id).filter((x): x is string => !!x))
      );
      const groupIds = Array.from(
        new Set(list.map((i) => i.owner_group_id).filter((x): x is string => !!x))
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
      for (const it of list) {
        if (it.owner_profile_id) {
          const name = pMap.get(it.owner_profile_id) ?? "—";
          labels[it.id] = it.owner_profile_id === ownerId ? `${name} (du)` : name;
        } else if (it.owner_group_id) {
          labels[it.id] = gMap.get(it.owner_group_id) ?? "Gruppe";
        }
      }
      setOwnerLabels(labels);
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
        ownerLabel: ownerLabels[item.id],
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
                <div className="flex-1 min-w-0">
                  <div className="text-sm truncate">{p.itemName}</div>
                  {p.ownerLabel && (
                    <div className="text-[11px] truncate" style={{ color: "var(--color-muted-foreground)" }}>
                      Eigentümer: {p.ownerLabel}
                    </div>
                  )}
                </div>
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
                  className="w-full flex items-start justify-between gap-2 px-2.5 py-1.5 rounded text-sm text-left transition-colors"
                  onMouseEnter={(e) => (e.currentTarget.style.background = "var(--color-muted)")}
                  onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}
                >
                  <div className="flex-1 min-w-0">
                    <div className="truncate">{item.name}</div>
                    {ownerLabels[item.id] && (
                      <div className="text-[11px] truncate" style={{ color: "var(--color-muted-foreground)" }}>
                        {ownerLabels[item.id]}
                      </div>
                    )}
                  </div>
                  <span className="text-xs whitespace-nowrap" style={{ color: "var(--color-muted-foreground)" }}>
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
