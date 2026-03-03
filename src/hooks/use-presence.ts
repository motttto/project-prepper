"use client";

import { useState, useEffect, useCallback, useRef } from "react";
import { createClient } from "@/lib/supabase";
import type { RealtimeChannel } from "@supabase/supabase-js";

export interface PresenceUser {
  userId: string;
  name: string;
  email: string;
  onlineAt: string;
  /** Welche Sektion der User gerade bearbeitet (null = idle) */
  editingSection?: string | null;
}

interface UsePresenceOptions {
  projectId: string;
  currentUser: { id: string; name: string; email: string } | null;
}

export function usePresence({
  projectId,
  currentUser,
}: UsePresenceOptions) {
  const [users, setUsers] = useState<PresenceUser[]>([]);
  const channelRef = useRef<RealtimeChannel | null>(null);

  useEffect(() => {
    if (!currentUser || !projectId) return;

    const supabase = createClient();
    const channel: RealtimeChannel = supabase.channel(
      `presence:project:${projectId}`,
      {
        config: { presence: { key: currentUser.id } },
      }
    );

    channelRef.current = channel;

    channel
      .on("presence", { event: "sync" }, () => {
        const state = channel.presenceState<{
          userId: string;
          name: string;
          email: string;
          onlineAt: string;
          editingSection?: string | null;
        }>();

        // Presence State flattenen (jeder Key → Array von Presences)
        const presentUsers: PresenceUser[] = [];
        const seen = new Set<string>();

        for (const presences of Object.values(state)) {
          for (const p of presences) {
            if (!seen.has(p.userId)) {
              seen.add(p.userId);
              presentUsers.push({
                userId: p.userId,
                name: p.name,
                email: p.email,
                onlineAt: p.onlineAt,
                editingSection: p.editingSection ?? null,
              });
            }
          }
        }

        setUsers(presentUsers);
      })
      .subscribe(async (status) => {
        if (status === "SUBSCRIBED") {
          await channel.track({
            userId: currentUser.id,
            name: currentUser.name,
            email: currentUser.email,
            onlineAt: new Date().toISOString(),
            editingSection: null,
          });
        }
      });

    return () => {
      channel.untrack();
      supabase.removeChannel(channel);
      channelRef.current = null;
    };
  }, [projectId, currentUser?.id, currentUser?.name, currentUser?.email]);

  /**
   * Update welche Sektion der aktuelle User gerade bearbeitet.
   * Wird von TabOverview aufgerufen wenn ein Feld fokussiert wird.
   */
  const updateEditingSection = useCallback(
    async (section: string | null) => {
      if (!channelRef.current || !currentUser) return;
      await channelRef.current.track({
        userId: currentUser.id,
        name: currentUser.name,
        email: currentUser.email,
        onlineAt: new Date().toISOString(),
        editingSection: section,
      });
    },
    [currentUser]
  );

  return { users, updateEditingSection };
}
