"use client";

import { useCallback, useEffect, useState } from "react";
import { createClient } from "@/lib/supabase";
import { IconTrash, IconShield, IconUsers } from "@/components/ui/icons";
import { showToast } from "@/hooks/use-toast";
import { appConfirm } from "@/components/ui/confirm-dialog";

type GroupMembership = {
  group_id: string;
  is_active: boolean;
  is_founder: boolean;
  groups: { id: string; name: string } | null;
};

type UserRow = {
  id: string;
  email: string;
  name: string | null;
  avatar_url: string | null;
  is_system: boolean;
  created_at: string;
  group_memberships: GroupMembership[];
};

type Props = {
  currentUserId: string;
};

export function UsersOverviewTab({ currentUserId }: Props) {
  const supabase = createClient();
  const [users, setUsers] = useState<UserRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [deleting, setDeleting] = useState<string | null>(null);
  const [search, setSearch] = useState("");

  const loadUsers = useCallback(async () => {
    setLoading(true);
    const { data, error } = await supabase
      .from("profiles")
      .select(
        `id, email, name, avatar_url, is_system, created_at,
         group_memberships:group_memberships!group_memberships_profile_id_fkey(
           group_id, is_active, is_founder,
           groups:groups!group_memberships_group_id_fkey(id, name)
         )`
      )
      .order("created_at", { ascending: false });

    if (error) {
      showToast(`Fehler beim Laden: ${error.message}`, "error");
      setLoading(false);
      return;
    }
    setUsers((data || []) as unknown as UserRow[]);
    setLoading(false);
  }, [supabase]);

  useEffect(() => {
    loadUsers();
    const channel = supabase
      .channel("admin-users-overview")
      .on("postgres_changes", { event: "*", schema: "public", table: "profiles" }, () => loadUsers())
      .on("postgres_changes", { event: "*", schema: "public", table: "group_memberships" }, () => loadUsers())
      .subscribe();
    return () => {
      supabase.removeChannel(channel);
    };
  }, [loadUsers, supabase]);

  const handleDelete = async (user: UserRow) => {
    const confirmed = await appConfirm(
      `Willst du ${user.name || user.email} wirklich vollstaendig loeschen? Alle Daten (Inventar, Anfragen, Projekte, Gruppen-Mitgliedschaften) werden via CASCADE entfernt. Diese Aktion ist nicht rueckgaengig zu machen.`,
      {
        title: "User loeschen?",
        confirmLabel: "Endgueltig loeschen",
        variant: "danger",
      }
    );
    if (!confirmed) return;

    setDeleting(user.id);
    const { data, error } = await supabase.rpc("delete_user_completely", { p_user_id: user.id });

    if (error) {
      showToast(`Fehler: ${error.message}`, "error");
      setDeleting(null);
      return;
    }

    const result = data as { success?: boolean; deleted_email?: string } | null;
    showToast(`User ${result?.deleted_email || user.email} geloescht`, "success");
    setDeleting(null);
    loadUsers();
  };

  const filtered = users.filter((u) => {
    if (!search.trim()) return true;
    const q = search.toLowerCase();
    return (
      (u.email || "").toLowerCase().includes(q) ||
      (u.name || "").toLowerCase().includes(q) ||
      u.group_memberships.some((m) => (m.groups?.name || "").toLowerCase().includes(q))
    );
  });

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64" style={{ color: "var(--color-muted-foreground)" }}>
        Wird geladen...
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div className="flex items-center gap-2 text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          <IconUsers size={16} />
          {users.length} {users.length === 1 ? "User" : "User"} im System
        </div>
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Suche nach Name, Email oder Gruppe..."
          className="px-3 py-2 rounded-lg text-sm"
          style={{
            background: "var(--color-surface)",
            border: "1px solid var(--color-border)",
            color: "var(--color-foreground)",
            minWidth: 280,
          }}
        />
      </div>

      <div
        className="rounded-lg overflow-hidden"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
      >
        <table className="w-full text-sm">
          <thead>
            <tr style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
              <th className="text-left px-4 py-3 font-medium">User</th>
              <th className="text-left px-4 py-3 font-medium">Gruppen</th>
              <th className="text-left px-4 py-3 font-medium">Registriert</th>
              <th className="text-right px-4 py-3 font-medium">Aktionen</th>
            </tr>
          </thead>
          <tbody>
            {filtered.length === 0 && (
              <tr>
                <td colSpan={4} className="px-4 py-8 text-center" style={{ color: "var(--color-muted-foreground)" }}>
                  Keine User gefunden
                </td>
              </tr>
            )}
            {filtered.map((u) => {
              const isSelf = u.id === currentUserId;
              const canDelete = !u.is_system && !isSelf;
              return (
                <tr key={u.id} style={{ borderTop: "1px solid var(--color-border)" }}>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      {u.avatar_url ? (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img src={u.avatar_url} alt="" className="w-8 h-8 rounded-full object-cover" />
                      ) : (
                        <div
                          className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-medium"
                          style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}
                        >
                          {(u.name || u.email || "?").charAt(0).toUpperCase()}
                        </div>
                      )}
                      <div className="min-w-0">
                        <div className="flex items-center gap-2">
                          <span className="font-medium truncate">{u.name || "(ohne Name)"}</span>
                          {u.is_system && (
                            <span
                              className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold"
                              style={{ background: "var(--color-warning, #f59e0b)", color: "#fff" }}
                              title="Superadmin"
                            >
                              <IconShield size={10} />
                              SUPERADMIN
                            </span>
                          )}
                          {isSelf && (
                            <span
                              className="px-1.5 py-0.5 rounded text-[10px] font-semibold"
                              style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}
                            >
                              DU
                            </span>
                          )}
                        </div>
                        <div className="text-xs truncate" style={{ color: "var(--color-muted-foreground)" }}>
                          {u.email}
                        </div>
                      </div>
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    {u.group_memberships.length === 0 ? (
                      <span className="text-xs italic" style={{ color: "var(--color-muted-foreground)" }}>
                        Solo
                      </span>
                    ) : (
                      <div className="flex flex-wrap gap-1.5">
                        {u.group_memberships.map((m) => (
                          <span
                            key={m.group_id}
                            className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs"
                            style={{
                              background: m.is_active ? "var(--color-muted)" : "transparent",
                              border: m.is_active ? "none" : "1px dashed var(--color-border)",
                              color: m.is_active ? "var(--color-foreground)" : "var(--color-muted-foreground)",
                            }}
                            title={m.is_active ? "Aktives Mitglied" : "Pending"}
                          >
                            {m.groups?.name || "?"}
                            {m.is_founder && (
                              <span style={{ color: "var(--color-primary)" }} title="Gruender">★</span>
                            )}
                          </span>
                        ))}
                      </div>
                    )}
                  </td>
                  <td className="px-4 py-3 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    {new Date(u.created_at).toLocaleDateString("de-DE")}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <button
                      onClick={() => handleDelete(u)}
                      disabled={!canDelete || deleting === u.id}
                      className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md text-xs font-medium transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                      style={{
                        color: "var(--color-destructive, #dc2626)",
                        border: "1px solid var(--color-border)",
                      }}
                      onMouseEnter={(e) => {
                        if (canDelete && deleting !== u.id) {
                          e.currentTarget.style.background = "var(--color-destructive, #dc2626)";
                          e.currentTarget.style.color = "#fff";
                        }
                      }}
                      onMouseLeave={(e) => {
                        e.currentTarget.style.background = "transparent";
                        e.currentTarget.style.color = "var(--color-destructive, #dc2626)";
                      }}
                      title={
                        isSelf
                          ? "Du kannst dich nicht selbst loeschen"
                          : u.is_system
                          ? "Superadmins koennen nicht ueber dieses Panel geloescht werden"
                          : "User loeschen"
                      }
                    >
                      <IconTrash size={12} />
                      {deleting === u.id ? "Loesche..." : "Loeschen"}
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
        ★ Gruender · Loeschen entfernt User aus auth.users + alle abhaengigen Daten via CASCADE
      </p>
    </div>
  );
}
