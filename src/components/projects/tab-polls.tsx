"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { useWorkspace } from "@/contexts/org-context";
import { useCurrentUser, hasPermission, isOrgAdmin } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type { OrgPoll } from "@/types/database";
import { PollCard } from "@/components/polls/poll-card";
import { PollCreateModal } from "@/components/polls/poll-create-modal";
import { IconPlus, IconClipboard } from "@/components/ui/icons";

interface TabPollsProps {
  projectId: string;
}

export function TabPolls({ projectId }: TabPollsProps) {
  const supabase = createClient();
  const { groupId } = useWorkspace();
  const currentUser = useCurrentUser();
  const [polls, setPolls] = useState<OrgPoll[]>([]);
  const [members, setMembers] = useState<{ id: string; name: string; avatar_url: string | null }[]>([]);
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);

  const canCreate = hasPermission(currentUser, "polls_create");
  const isAdmin = isOrgAdmin(currentUser);

  const loadPolls = useCallback(async () => {
    const { data } = await supabase
      .from("org_polls")
      .select("*")
      .eq("project_id", projectId)
      .order("created_at", { ascending: false });
    if (data) setPolls(data as OrgPoll[]);
    setLoading(false);
  }, [supabase, projectId]);

  const loadMembers = useCallback(async () => {
    if (!groupId) {
      // Solo: nur ich selbst
      if (currentUser) setMembers([{ id: currentUser.id, name: currentUser.name, avatar_url: currentUser.avatarUrl }]);
      return;
    }
    const { data } = await supabase
      .from("group_memberships")
      .select("profile_id, profiles(name, avatar_url)")
      .eq("group_id", groupId)
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
  }, [supabase, groupId, currentUser]);

  useEffect(() => {
    loadPolls();
    loadMembers();
  }, [loadPolls, loadMembers]);

  useRealtimeTable({
    table: "org_polls",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadPolls,
  });

  // groupId optional: Polls sind Projekt-gebunden, können auch ohne Group existieren

  return (
    <div>
      {/* Header */}
      <div className="flex items-center justify-between mb-5">
        <h2 className="text-lg font-semibold" style={{ color: "var(--color-foreground)" }}>
          Umfragen
        </h2>
        {canCreate && (
          <button
            onClick={() => setShowCreate(true)}
            className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium text-white hover:opacity-90"
            style={{ background: "var(--color-primary)" }}
          >
            <IconPlus size={14} />
            Neue Umfrage
          </button>
        )}
      </div>

      {/* Polls */}
      {loading ? (
        <div className="py-12 text-center text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          Laden...
        </div>
      ) : polls.length === 0 ? (
        <div className="py-12 text-center">
          <IconClipboard size={40} className="mx-auto mb-3" style={{ color: "var(--color-muted-foreground)", opacity: 0.4 }} />
          <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            Noch keine Umfragen für dieses Projekt
          </p>
          {canCreate && (
            <button
              onClick={() => setShowCreate(true)}
              className="mt-2 text-sm font-medium hover:opacity-80"
              style={{ color: "var(--color-primary)" }}
            >
              Erste Umfrage erstellen
            </button>
          )}
        </div>
      ) : (
        <div className="space-y-4">
          {polls.map((poll) => (
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
          orgId={groupId || ""}
          projectId={projectId}
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
