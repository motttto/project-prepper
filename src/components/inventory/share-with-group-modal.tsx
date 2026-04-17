"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import type { InventoryItem } from "@/types/database";
import {
  IconX,
  IconShield,
  IconCheck,
  IconTrash,
} from "@/components/ui/icons";
import { showToast } from "@/hooks/use-toast";

interface Group {
  id: string;
  name: string;
}

interface ShareRow {
  id: string;
  group_id: string;
  daily_rate: number;
  conditions: string | null;
  group?: { name: string };
}

interface Props {
  item: InventoryItem;
  onClose: () => void;
}

export function ShareWithGroupModal({ item, onClose }: Props) {
  const supabase = createClient();
  const currentUser = useCurrentUser();

  const [groups, setGroups] = useState<Group[]>([]);
  const [shares, setShares] = useState<ShareRow[]>([]);
  const [loading, setLoading] = useState(true);

  // Form
  const [selectedGroupId, setSelectedGroupId] = useState("");
  const [dailyRate, setDailyRate] = useState<number>(item.cost_per_day || 0);
  const [conditions, setConditions] = useState("");
  const [saving, setSaving] = useState(false);

  const loadData = useCallback(async () => {
    if (!currentUser) return;
    const [grpRes, shareRes] = await Promise.all([
      supabase
        .from("group_memberships")
        .select("group_id, groups(id, name)")
        .eq("profile_id", currentUser.id)
        .eq("is_active", true),
      supabase
        .from("inventory_group_shares")
        .select("*, group:groups(name)")
        .eq("inventory_item_id", item.id)
        .is("revoked_at", null),
    ]);

    if (grpRes.data) {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const list: Group[] = grpRes.data.map((m: any) => ({
        id: m.groups?.id || m.group_id,
        name: m.groups?.name || "?",
      }));
      setGroups(list);
      if (list.length > 0 && !selectedGroupId) setSelectedGroupId(list[0].id);
    }
    if (shareRes.data) setShares(shareRes.data as ShareRow[]);
    setLoading(false);
  }, [supabase, currentUser, item.id, selectedGroupId]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  async function handleAddShare(e: React.FormEvent) {
    e.preventDefault();
    if (!selectedGroupId || !currentUser) return;
    setSaving(true);

    const { error } = await supabase.from("inventory_group_shares").insert({
      inventory_item_id: item.id,
      group_id: selectedGroupId,
      daily_rate: dailyRate,
      conditions: conditions.trim() || null,
      shared_by: currentUser.id,
    });

    if (error) {
      showToast("Fehler: " + error.message, "error");
    } else {
      showToast("Item für Gruppe freigegeben", "success");
      setConditions("");
      loadData();
    }
    setSaving(false);
  }

  async function handleRevoke(shareId: string) {
    const { error } = await supabase
      .from("inventory_group_shares")
      .delete()
      .eq("id", shareId);
    if (error) {
      showToast("Fehler: " + error.message, "error");
    } else {
      showToast("Freigabe widerrufen", "info");
      loadData();
    }
  }

  // Gruppen wo dieses Item noch nicht freigegeben ist
  const sharedGroupIds = new Set(shares.map((s) => s.group_id));
  const availableGroups = groups.filter((g) => !sharedGroupIds.has(g.id));

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ background: "rgba(0,0,0,0.5)" }}
    >
      <div
        className="w-full max-w-md rounded-xl shadow-2xl max-h-[90vh] overflow-y-auto"
        style={{ background: "var(--color-surface)" }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between p-5 border-b"
          style={{ borderColor: "var(--color-border)" }}
        >
          <div className="flex items-center gap-2">
            <IconShield size={18} style={{ color: "var(--color-success)" }} />
            <h2
              className="text-lg font-semibold"
              style={{ color: "var(--color-foreground)" }}
            >
              Für Gruppe freigeben
            </h2>
          </div>
          <button onClick={onClose} className="p-1 rounded hover:opacity-70">
            <IconX size={20} />
          </button>
        </div>

        <div className="p-5 space-y-4">
          <div className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            <strong>{item.name}</strong>
            {item.inventory_number && <span className="ml-2 text-xs">({item.inventory_number})</span>}
          </div>

          {loading ? (
            <div className="py-6 text-center text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              Lade...
            </div>
          ) : groups.length === 0 ? (
            <div
              className="p-4 rounded-lg text-sm text-center"
              style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}
            >
              Du bist in keiner Gruppe. Gründe eine Gruppe oder warte auf eine
              Einladung, um Equipment zu teilen.
            </div>
          ) : (
            <>
              {/* Bestehende Freigaben */}
              {shares.length > 0 && (
                <div>
                  <h3
                    className="text-xs font-semibold mb-2"
                    style={{ color: "var(--color-muted-foreground)" }}
                  >
                    AKTIVE FREIGABEN
                  </h3>
                  <div className="space-y-2">
                    {shares.map((s) => (
                      <div
                        key={s.id}
                        className="flex items-center justify-between p-3 rounded-lg"
                        style={{ background: "var(--color-success-light)" }}
                      >
                        <div>
                          <div className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>
                            {s.group?.name}
                          </div>
                          <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                            {Number(s.daily_rate).toFixed(2)} €/Tag
                            {s.conditions && <span> · {s.conditions}</span>}
                          </div>
                        </div>
                        <button
                          onClick={() => handleRevoke(s.id)}
                          className="p-1.5 rounded hover:opacity-70"
                          style={{ color: "var(--color-destructive)" }}
                          title="Freigabe widerrufen"
                        >
                          <IconTrash size={14} />
                        </button>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Neue Freigabe */}
              {availableGroups.length > 0 ? (
                <form onSubmit={handleAddShare} className="space-y-3 pt-2 border-t" style={{ borderColor: "var(--color-border)" }}>
                  <h3
                    className="text-xs font-semibold mt-3"
                    style={{ color: "var(--color-muted-foreground)" }}
                  >
                    NEUE FREIGABE
                  </h3>
                  <div>
                    <label
                      className="block text-xs mb-1"
                      style={{ color: "var(--color-muted-foreground)" }}
                    >
                      Gruppe
                    </label>
                    <select
                      value={selectedGroupId}
                      onChange={(e) => setSelectedGroupId(e.target.value)}
                      className="w-full px-3 py-2 rounded-lg text-sm"
                      style={{
                        background: "var(--color-muted)",
                        border: "1px solid var(--color-border)",
                        color: "var(--color-foreground)",
                      }}
                    >
                      {availableGroups.map((g) => (
                        <option key={g.id} value={g.id}>{g.name}</option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label
                      className="block text-xs mb-1"
                      style={{ color: "var(--color-muted-foreground)" }}
                    >
                      Tagessatz (€)
                    </label>
                    <input
                      type="number"
                      step="0.01"
                      value={dailyRate}
                      onChange={(e) => setDailyRate(parseFloat(e.target.value) || 0)}
                      className="w-full px-3 py-2 rounded-lg text-sm"
                      style={{
                        background: "var(--color-muted)",
                        border: "1px solid var(--color-border)",
                        color: "var(--color-foreground)",
                      }}
                    />
                  </div>
                  <div>
                    <label
                      className="block text-xs mb-1"
                      style={{ color: "var(--color-muted-foreground)" }}
                    >
                      Bedingungen (optional)
                    </label>
                    <textarea
                      value={conditions}
                      onChange={(e) => setConditions(e.target.value)}
                      rows={2}
                      placeholder="z.B. nur mit Bedienung, Haftung prüfen"
                      className="w-full px-3 py-2 rounded-lg text-sm resize-none"
                      style={{
                        background: "var(--color-muted)",
                        border: "1px solid var(--color-border)",
                        color: "var(--color-foreground)",
                      }}
                    />
                  </div>
                  <button
                    type="submit"
                    disabled={saving || !selectedGroupId}
                    className="flex items-center gap-1 px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
                    style={{ background: "var(--color-success)" }}
                  >
                    <IconCheck size={14} /> Freigeben
                  </button>
                </form>
              ) : shares.length > 0 ? (
                <div className="text-xs text-center pt-3" style={{ color: "var(--color-muted-foreground)" }}>
                  Item ist für alle deine Gruppen freigegeben.
                </div>
              ) : null}
            </>
          )}
        </div>
      </div>
    </div>
  );
}
