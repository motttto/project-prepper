"use client";

import { useState, useEffect } from "react";
import { createClient } from "@/lib/supabase";
import type { RealtimeChannel } from "@supabase/supabase-js";

export interface PresenceUser {
  userId: string;
  name: string;
  email: string;
  onlineAt: string;
}

interface UsePresenceOptions {
  projectId: string;
  currentUser: { id: string; name: string; email: string } | null;
}

export function usePresence({
  projectId,
  currentUser,
}: UsePresenceOptions): PresenceUser[] {
  const [users, setUsers] = useState<PresenceUser[]>([]);

  useEffect(() => {
    if (!currentUser || !projectId) return;

    const supabase = createClient();
    const channel: RealtimeChannel = supabase.channel(
      `presence:project:${projectId}`,
      {
        config: { presence: { key: currentUser.id } },
      }
    );

    channel
      .on("presence", { event: "sync" }, () => {
        const state = channel.presenceState<{
          userId: string;
          name: string;
          email: string;
          onlineAt: string;
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
          });
        }
      });

    return () => {
      channel.untrack();
      supabase.removeChannel(channel);
    };
  }, [projectId, currentUser?.id, currentUser?.name, currentUser?.email]);

  return users;
}
