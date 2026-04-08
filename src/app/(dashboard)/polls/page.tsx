"use client";

import { useState, useEffect, useCallback, useMemo } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import { useCurrentUser, hasPermission } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type { OrgPoll } from "@/types/database";
import { PollCard } from "@/components/polls/poll-card";
import { PollCreateModal } from "@/components/polls/poll-create-modal";
import { IconPlus, IconClipboard } from "@/components/ui/icons";

type FilterType = "all" | "active" | "closed" | "mine";

export default function PollsPage() {
  const supabase = createClient();
  const { orgId } = useOrg();
  const currentUser = useCurrentUser();
  const [polls, setPolls] = useState<OrgPoll[]>([]);
  const [members, setMembers] = useState<{ id: string; name: string; avatar_url: string | null }[]>([]);
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);
  const [filter, setFilter] = useState<FilterType>("active");

  const canCreate = hasPermission(currentUser, "polls_create");
  const isAdmin = currentUser?.roleName === "admin";

  const loadPolls = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("org_polls")
      .select("*")
      .eq("org_id", orgId)
      .is("project_id", null) // Nur org-weite Umfragen
      .order("created_at", { ascending: false });
    if (data) setPolls(data as OrgPoll[]);
    setLoading(false);
  }, [supabase, orgId]);

  const loadMembers = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("org_memberships")
      .select("profile_id, profiles(name, avatar_url)")
      .eq("org_id", orgId)
      .eq("is_active", true);
    if (data) {
      setMembers(
        data.map((m: Record<string, unknown>) => ({
          id: m.profile_id as string,
          name: (m.profiles as Record<string, unknown>)?.name as string || "Unbekannt",
          avatar_url: (m.profiles as Record<string, unknown>)?.avatar_url as string | null,
        }))
      );
    }
  }, [supabase, orgId]);

  useEffect(() => {
    loadPolls();
    loadMembers();
  }, [loadPolls, loadMembers]);

  useRealtimeTable({
    table: "org_polls",
    orgFilter: orgId || undefined,
    onDataChange: loadPolls,
  });

  const filteredPolls = useMemo(() => {
    switch (filter) {
      case "active":
        return polls.filter((p) => p.status === "active");
      case "closed":
        return polls.filter((p) => p.status !== "active");
      case "mine":
        return polls.filter((p) => p.created_by === currentUser?.id);
      default:
        return polls;
    }
  }, [polls, filter, currentUser?.id]);

  const filterButtons: { key: FilterType; label: string }[] = [
    { key: "active", label: "Aktiv" },
    { key: "all", label: "Alle" },
    { key: "closed", label: "Geschlossen" },
    { key: "mine", label: "Meine" },
  ];

  if (!orgId) return null;

  return (
    <div className="max-w-4xl mx-auto px-4 py-8">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: "var(--color-foreground)" }}>
            Umfragen
          </h1>
          <p className="text-sm mt-1" style={{ color: "var(--color-muted-foreground)" }}>
            Terminumfragen und Abstimmungen
          </p>
        </div>
        {canCreate && (
          <button
            onClick={() => setShowCreate(true)}
            className="flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium text-white hover:opacity-90 transition-opacity"
            style={{ background: "var(--color-primary)" }}
          >
            <IconPlus size={16} />
            Neue Umfrage
          </button>
        )}
      </div>

      {/* Filter */}
      <div className="flex gap-1 mb-6 p-1 rounded-lg" style={{ background: "var(--color-muted)" }}>
        {filterButtons.map((btn) => (
          <button
            key={btn.key}
            onClick={() => setFilter(btn.key)}
            className="flex-1 px-3 py-2 rounded-md text-sm font-medium transition-all"
            style={{
              background: filter === btn.key ? "var(--color-surface)" : "transparent",
              color: filter === btn.key ? "var(--color-foreground)" : "var(--color-muted-foreground)",
              boxShadow: filter === btn.key ? "0 1px 2px rgba(0,0,0,0.05)" : "none",
            }}
          >
            {btn.label}
          </button>
        ))}
      </div>

      {/* Polls List */}
      {loading ? (
        <div className="py-16 text-center text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          Laden...
        </div>
      ) : filteredPolls.length === 0 ? (
        <div className="py-16 text-center">
          <IconClipboard size={48} className="mx-auto mb-4" style={{ color: "var(--color-muted-foreground)", opacity: 0.4 }} />
          <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            {filter === "active"
              ? "Keine aktiven Umfragen"
              : filter === "mine"
              ? "Du hast noch keine Umfragen erstellt"
              : "Keine Umfragen gefunden"}
          </p>
          {canCreate && filter === "active" && (
            <button
              onClick={() => setShowCreate(true)}
              className="mt-3 text-sm font-medium hover:opacity-80"
              style={{ color: "var(--color-primary)" }}
            >
              Erste Umfrage erstellen
            </button>
          )}
        </div>
      ) : (
        <div className="space-y-4">
          {filteredPolls.map((poll) => (
            <PollCard
              key={poll.id}
              poll={poll}
              currentUserId={currentUser?.id || ""}
              isAdmin={isAdmin}
              members={members}
            />
          ))}
        </div>
      )}

      {/* Create Modal */}
      {showCreate && (
        <PollCreateModal
          orgId={orgId}
          onClose={() => setShowCreate(false)}
          onCreated={() => {
            setShowCreate(false);
            loadPolls();
          }}
        />
      )}
    </div>
  );
}
