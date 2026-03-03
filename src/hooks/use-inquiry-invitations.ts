"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import type { InquiryInvitation } from "@/types/database";
import { useRealtimeTable } from "@/hooks/use-realtime-table";

interface UseInquiryInvitationsOptions {
  /** Per-Inquiry Modus: alle Einladungen für eine Anfrage */
  inquiryId?: string;
  /** Per-User Modus: offene Einladungen für den aktuellen User */
  userId?: string;
}

export function useInquiryInvitations({
  inquiryId,
  userId,
}: UseInquiryInvitationsOptions) {
  const [invitations, setInvitations] = useState<InquiryInvitation[]>([]);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    const supabase = createClient();

    if (inquiryId) {
      // Alle Einladungen für diese Anfrage (alle Status)
      const { data } = await supabase
        .from("inquiry_invitations")
        .select(
          "*, profiles!invited_profile_id(name, email, avatar_url), inviters:profiles!invited_by(name)"
        )
        .eq("inquiry_id", inquiryId)
        .order("created_at", { ascending: true });
      if (data) setInvitations(data as InquiryInvitation[]);
    } else if (userId) {
      // Offene Einladungen für diesen User (für die Glocke)
      const { data } = await supabase
        .from("inquiry_invitations")
        .select("*, inquiries(title), profiles!invited_by(name)")
        .eq("invited_profile_id", userId)
        .eq("status", "pending")
        .order("created_at", { ascending: false });
      if (data) setInvitations(data as InquiryInvitation[]);
    }

    setLoading(false);
  }, [inquiryId, userId]);

  useEffect(() => {
    load();
  }, [load]);

  // Realtime: Live-Updates
  useRealtimeTable({
    table: "inquiry_invitations",
    filter: inquiryId
      ? { column: "inquiry_id", value: inquiryId }
      : undefined,
    onDataChange: load,
    enabled: !!(inquiryId || userId),
  });

  /** Mitglied einladen (upsert für erneutes Einladen nach Absage) */
  async function invite(invitedProfileId: string, invitedBy: string) {
    if (!inquiryId) return;
    const supabase = createClient();
    await supabase.from("inquiry_invitations").upsert(
      {
        inquiry_id: inquiryId,
        invited_by: invitedBy,
        invited_profile_id: invitedProfileId,
        status: "pending",
        responded_at: null,
      },
      { onConflict: "inquiry_id,invited_profile_id" }
    );
    load();
  }

  /** Einladung annehmen (Zusage) */
  async function accept(invitationId: string) {
    const supabase = createClient();
    await supabase
      .from("inquiry_invitations")
      .update({ status: "accepted", responded_at: new Date().toISOString() })
      .eq("id", invitationId);
    load();
  }

  /** Einladung ablehnen (Absage) */
  async function decline(invitationId: string) {
    const supabase = createClient();
    await supabase
      .from("inquiry_invitations")
      .update({ status: "declined", responded_at: new Date().toISOString() })
      .eq("id", invitationId);
    load();
  }

  /** Einladung zurückziehen (nur Einladender) */
  async function revoke(invitationId: string) {
    const supabase = createClient();
    await supabase
      .from("inquiry_invitations")
      .delete()
      .eq("id", invitationId);
    load();
  }

  return {
    invitations,
    loading,
    invite,
    accept,
    decline,
    revoke,
    pendingCount: invitations.filter((i) => i.status === "pending").length,
  };
}
