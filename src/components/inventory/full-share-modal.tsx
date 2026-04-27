"use client";

import { useEffect, useState, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useWorkspace } from "@/contexts/org-context";
import { showToast } from "@/hooks/use-toast";

type FullShare = {
  id: string;
  source_owner_profile_id: string | null;
  source_owner_group_id: string | null;
  target_profile_id: string | null;
  target_group_id: string | null;
  daily_rate_default: number;
  requires_approval_default: boolean;
  conditions: string | null;
  shared_at: string;
  target_profile?: { name: string; email: string } | null;
  target_group?: { name: string } | null;
};

type Group = { id: string; name: string };
type Profile = { id: string; name: string; email: string };

export function FullShareModal({ onClose }: { onClose: () => void }) {
  const supabase = createClient();
  const currentUser = useCurrentUser();
  const { groupId } = useWorkspace();
  const [shares, setShares] = useState<FullShare[]>([]);
  const [loading, setLoading] = useState(true);

  // Suche fuer neue Freigabe
  const [targetType, setTargetType] = useState<"profile" | "group">("group");
  const [search, setSearch] = useState("");
  const [groups, setGroups] = useState<Group[]>([]);
  const [profiles, setProfiles] = useState<Profile[]>([]);
  const [requiresApproval, setRequiresApproval] = useState(true);
  const [dailyRate, setDailyRate] = useState(0);

  const sourceFilter = groupId
    ? { source_owner_group_id: groupId }
    : { source_owner_profile_id: currentUser?.id };

  const load = useCallback(async () => {
    if (!currentUser) return;
    let q = supabase
      .from("inventory_full_shares")
      .select("*, target_profile:profiles!target_profile_id(name, email), target_group:groups!target_group_id(name)")
      .is("revoked_at", null);
    if (groupId) q = q.eq("source_owner_group_id", groupId);
    else q = q.eq("source_owner_profile_id", currentUser.id);
    const { data } = await q;
    setShares((data as FullShare[]) || []);

    // Verfuegbare Targets laden
    const [{ data: gs }, { data: ps }] = await Promise.all([
      supabase.from("groups").select("id, name").is("archived_at", null),
      supabase.from("profiles").select("id, name, email").eq("is_active", true).limit(100),
    ]);
    setGroups((gs as Group[]) || []);
    setProfiles((ps as Profile[]) || []);
    setLoading(false);
  }, [supabase, currentUser, groupId]);

  useEffect(() => {
    load();
  }, [load]);

  async function addShare(targetId: string) {
    if (!currentUser) return;
    const payload: Record<string, unknown> = {
      source_owner_profile_id: groupId ? null : currentUser.id,
      source_owner_group_id: groupId,
      target_profile_id: targetType === "profile" ? targetId : null,
      target_group_id: targetType === "group" ? targetId : null,
      daily_rate_default: dailyRate,
      requires_approval_default: requiresApproval,
      shared_by: currentUser.id,
    };
    const { error } = await supabase.from("inventory_full_shares").insert(payload);
    if (error) {
      showToast("Fehler: " + error.message, "error");
      return;
    }
    showToast("Inventar freigegeben", "success");
    setSearch("");
    load();
  }

  async function revokeShare(id: string) {
    const { error } = await supabase
      .from("inventory_full_shares")
      .update({ revoked_at: new Date().toISOString() })
      .eq("id", id);
    if (error) {
      showToast("Fehler: " + error.message, "error");
      return;
    }
    showToast("Freigabe widerrufen", "success");
    load();
  }

  // Filter
  const sourceLabel = groupId ? "der Gruppe" : "dein";
  const filteredGroups = search
    ? groups.filter((g) => g.name.toLowerCase().includes(search.toLowerCase()) && g.id !== groupId)
    : groups.filter((g) => g.id !== groupId).slice(0, 5);
  const filteredProfiles = search
    ? profiles.filter(
        (p) =>
          (p.name.toLowerCase().includes(search.toLowerCase()) ||
            p.email.toLowerCase().includes(search.toLowerCase())) &&
          p.id !== currentUser?.id
      )
    : profiles.filter((p) => p.id !== currentUser?.id).slice(0, 5);

  // Schon freigegebene IDs ausschliessen
  const alreadyShared = new Set(
    shares.flatMap((s) => [s.target_profile_id, s.target_group_id].filter(Boolean) as string[])
  );

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ background: "rgba(0,0,0,0.5)" }}
      onClick={onClose}
    >
      <div
        className="w-full max-w-2xl max-h-[90vh] overflow-auto rounded-xl p-6"
        style={{ background: "var(--color-surface)" }}
        onClick={(e) => e.stopPropagation()}
      >
        <h2 className="text-lg font-bold mb-1">Gesamtinventar freigeben</h2>
        <p className="text-sm mb-5" style={{ color: "var(--color-muted-foreground)" }}>
          Gibt {sourceLabel} komplettes Inventar (alle aktuellen + zukünftigen Items) für eine andere Person oder Gruppe frei.
        </p>

        {/* Bestehende Freigaben */}
        {!loading && shares.length > 0 && (
          <div className="mb-6">
            <h3 className="text-sm font-semibold mb-2">Aktive Freigaben</h3>
            <div className="space-y-2">
              {shares.map((s) => {
                const targetName = s.target_group?.name || s.target_profile?.name || "?";
                const targetKind = s.target_group_id ? "Gruppe" : "User";
                return (
                  <div
                    key={s.id}
                    className="flex items-center justify-between p-3 rounded-lg"
                    style={{ background: "var(--color-muted)" }}
                  >
                    <div>
                      <div className="text-sm font-medium">{targetName}</div>
                      <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                        {targetKind} · seit {new Date(s.shared_at).toLocaleDateString("de-DE")}
                        {s.requires_approval_default && " · benötigt Zustimmung"}
                      </div>
                    </div>
                    <button
                      onClick={() => revokeShare(s.id)}
                      className="px-3 py-1.5 rounded-md text-xs font-medium"
                      style={{ color: "var(--color-destructive)", border: "1px solid var(--color-destructive)" }}
                    >
                      Widerrufen
                    </button>
                  </div>
                );
              })}
            </div>
          </div>
        )}

        {/* Neue Freigabe */}
        <div>
          <h3 className="text-sm font-semibold mb-2">Neue Freigabe</h3>

          <div className="flex gap-1 rounded-lg p-1 mb-3" style={{ background: "var(--color-muted)" }}>
            {(["group", "profile"] as const).map((t) => (
              <button
                key={t}
                onClick={() => setTargetType(t)}
                className="flex-1 px-3 py-1.5 rounded-md text-sm font-medium"
                style={{
                  background: targetType === t ? "var(--color-primary)" : "transparent",
                  color: targetType === t ? "var(--color-primary-foreground)" : "var(--color-muted-foreground)",
                }}
              >
                {t === "group" ? "An Gruppe" : "An Person"}
              </button>
            ))}
          </div>

          <div className="grid grid-cols-2 gap-3 mb-3">
            <label className="flex items-center gap-2 text-sm cursor-pointer">
              <input
                type="checkbox"
                checked={requiresApproval}
                onChange={(e) => setRequiresApproval(e.target.checked)}
              />
              <span>Anfrage erforderlich</span>
            </label>
            <div>
              <input
                type="number"
                min={0}
                value={dailyRate}
                onChange={(e) => setDailyRate(parseFloat(e.target.value) || 0)}
                placeholder="Standard-Tagessatz €"
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
              />
            </div>
          </div>

          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={targetType === "group" ? "Gruppe suchen..." : "User suchen (Name oder Email)..."}
            className="w-full px-3 py-2 rounded-lg text-sm mb-2"
            style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
          />

          <div className="space-y-1 max-h-60 overflow-auto">
            {targetType === "group"
              ? filteredGroups.map((g) => (
                  <button
                    key={g.id}
                    onClick={() => addShare(g.id)}
                    disabled={alreadyShared.has(g.id)}
                    className="w-full text-left p-2 rounded-md text-sm disabled:opacity-40"
                    style={{ border: "1px solid var(--color-border)" }}
                    onMouseEnter={(e) => !alreadyShared.has(g.id) && (e.currentTarget.style.background = "var(--color-muted)")}
                    onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}
                  >
                    {g.name} {alreadyShared.has(g.id) && "(bereits freigegeben)"}
                  </button>
                ))
              : filteredProfiles.map((p) => (
                  <button
                    key={p.id}
                    onClick={() => addShare(p.id)}
                    disabled={alreadyShared.has(p.id)}
                    className="w-full text-left p-2 rounded-md text-sm disabled:opacity-40"
                    style={{ border: "1px solid var(--color-border)" }}
                    onMouseEnter={(e) => !alreadyShared.has(p.id) && (e.currentTarget.style.background = "var(--color-muted)")}
                    onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}
                  >
                    <span className="font-medium">{p.name}</span>{" "}
                    <span style={{ color: "var(--color-muted-foreground)" }}>· {p.email}</span>
                    {alreadyShared.has(p.id) && " (bereits freigegeben)"}
                  </button>
                ))}
          </div>
        </div>

        <div className="flex justify-end mt-5">
          <button
            onClick={onClose}
            className="px-4 py-2 rounded-lg text-sm font-medium"
            style={{ border: "1px solid var(--color-border)" }}
          >
            Schließen
          </button>
        </div>
      </div>
    </div>
  );
}
