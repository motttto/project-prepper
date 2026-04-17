"use client";

import Link from "next/link";
import { useEffect, useState, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import { IconShield, IconUsers, IconPlus, IconChevronRight } from "@/components/ui/icons";

type GroupRow = {
  id: string;
  name: string;
  description: string | null;
  founded_by: string;
  is_founder: boolean;
  is_active: boolean;
  member_count: number;
};

export default function MyGroupsPage() {
  const supabase = createClient();
  const currentUser = useCurrentUser();
  const [groups, setGroups] = useState<GroupRow[]>([]);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    if (!currentUser) return;
    const { data } = await supabase
      .from("group_memberships")
      .select("is_active, is_founder, group:groups(id, name, description, founded_by)")
      .eq("profile_id", currentUser.id);

    if (!data) {
      setLoading(false);
      return;
    }

    const rows: GroupRow[] = await Promise.all(
      data
        .filter((m) => m.group)
        .map(async (m) => {
          const g = m.group as unknown as { id: string; name: string; description: string | null; founded_by: string };
          const { count } = await supabase
            .from("group_memberships")
            .select("*", { count: "exact", head: true })
            .eq("group_id", g.id)
            .eq("is_active", true);
          return {
            id: g.id,
            name: g.name,
            description: g.description,
            founded_by: g.founded_by,
            is_founder: !!m.is_founder,
            is_active: !!m.is_active,
            member_count: count ?? 0,
          };
        })
    );

    rows.sort((a, b) => {
      if (a.is_founder !== b.is_founder) return a.is_founder ? -1 : 1;
      return a.name.localeCompare(b.name);
    });
    setGroups(rows);
    setLoading(false);
  }, [supabase, currentUser]);

  useEffect(() => {
    load();
  }, [load]);

  useRealtimeTable({ table: "group_memberships", onDataChange: load });

  if (!currentUser) return null;

  return (
    <div className="max-w-3xl animate-fadeIn">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold">Meine Gruppen</h1>
          <p className="text-sm mt-1" style={{ color: "var(--color-muted-foreground)" }}>
            Kollektive, in denen du Mitglied bist
          </p>
        </div>
        <Link
          href="/groups/new"
          className="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium text-white"
          style={{ background: "var(--color-primary)" }}
        >
          <IconPlus size={14} /> Neue Gruppe
        </Link>
      </div>

      {loading ? (
        <div className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          Lade...
        </div>
      ) : groups.length === 0 ? (
        <div
          className="rounded-xl p-8 text-center"
          style={{ background: "var(--color-surface)", border: "1px dashed var(--color-border)" }}
        >
          <IconUsers size={32} className="mx-auto mb-2" style={{ color: "var(--color-muted-foreground)" }} />
          <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            Du bist noch in keiner Gruppe. Gründe eine eigene oder warte auf eine Einladung.
          </p>
        </div>
      ) : (
        <div className="space-y-2">
          {groups.map((g) => (
            <Link
              key={g.id}
              href={`/groups/${g.id}`}
              className="block rounded-xl p-4 transition-all hover:shadow-md"
              style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
            >
              <div className="flex items-start gap-3">
                <div
                  className="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                  style={{ background: "var(--color-primary-light)" }}
                >
                  <IconShield size={20} style={{ color: "var(--color-primary)" }} />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <h2 className="text-base font-semibold truncate" style={{ color: "var(--color-foreground)" }}>
                      {g.name}
                    </h2>
                    {g.is_founder && (
                      <span
                        className="px-1.5 py-0.5 rounded text-[10px] font-semibold"
                        style={{ background: "var(--color-primary)", color: "#fff" }}
                      >
                        GRÜNDER
                      </span>
                    )}
                    {!g.is_active && (
                      <span
                        className="px-1.5 py-0.5 rounded text-[10px] font-semibold"
                        style={{ background: "var(--color-warning)", color: "#fff" }}
                      >
                        PENDING
                      </span>
                    )}
                  </div>
                  {g.description && (
                    <p className="text-xs mt-1 line-clamp-2" style={{ color: "var(--color-muted-foreground)" }}>
                      {g.description}
                    </p>
                  )}
                  <p className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                    {g.member_count} {g.member_count === 1 ? "Mitglied" : "Mitglieder"}
                  </p>
                </div>
                <IconChevronRight size={16} style={{ color: "var(--color-muted-foreground)", marginTop: 10 }} />
              </div>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
