"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import type { ProjectInvitation } from "@/types/database";
import { useRealtimeTable } from "@/hooks/use-realtime-table";

export function useInvitations(userId: string | undefined) {
  const [invitations, setInvitations] = useState<ProjectInvitation[]>([]);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    if (!userId) return;
    const supabase = createClient();
    const { data } = await supabase
      .from("project_invitations")
      .select("*, projects(name), profiles!invited_by(name)")
      .eq("invited_profile_id", userId)
      .eq("status", "pending")
      .order("created_at", { ascending: false });
    if (data) setInvitations(data as ProjectInvitation[]);
    setLoading(false);
  }, [userId]);

  useEffect(() => {
    load();
  }, [load]);

  // Realtime: Live-Updates für Einladungen
  useRealtimeTable({
    table: "project_invitations",
    onDataChange: load,
    enabled: !!userId,
  });

  async function accept(invitationId: string) {
    const supabase = createClient();
    const inv = invitations.find((i) => i.id === invitationId);
    if (!inv) return;

    // Einladung akzeptieren
    await supabase
      .from("project_invitations")
      .update({ status: "accepted", responded_at: new Date().toISOString() })
      .eq("id", invitationId);

    // Als Mitglied hinzufügen
    await supabase.from("project_members").insert({
      project_id: inv.project_id,
      profile_id: inv.invited_profile_id,
      role: inv.role,
    });

    load();
  }

  async function decline(invitationId: string) {
    const supabase = createClient();
    await supabase
      .from("project_invitations")
      .update({ status: "declined", responded_at: new Date().toISOString() })
      .eq("id", invitationId);
    load();
  }

  return {
    invitations,
    loading,
    accept,
    decline,
    pendingCount: invitations.length,
  };
}
