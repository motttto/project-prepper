"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import type { ProjectOrg } from "@/types/database";
import { useRealtimeTable } from "@/hooks/use-realtime-table";

interface UseProjectOrgsResult {
  projectOrgs: ProjectOrg[];
  acceptedOrgs: ProjectOrg[];
  pendingOrgs: ProjectOrg[];
  loading: boolean;
  inviteOrg: (orgId: string) => Promise<{ error: string | null }>;
  acceptPartnership: (projectOrgId: string) => Promise<void>;
  declinePartnership: (projectOrgId: string) => Promise<void>;
  removeOrg: (projectOrgId: string) => Promise<void>;
}

export function useProjectOrgs(projectId: string): UseProjectOrgsResult {
  const supabase = createClient();
  const [projectOrgs, setProjectOrgs] = useState<ProjectOrg[]>([]);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    const { data } = await supabase
      .from("project_orgs")
      .select("*, organizations(name, slug, logo_url)")
      .eq("project_id", projectId)
      .order("created_at");

    if (data) setProjectOrgs(data as ProjectOrg[]);
    setLoading(false);
  }, [supabase, projectId]);

  useEffect(() => {
    load();
  }, [load]);

  // Realtime
  useRealtimeTable({
    table: "project_orgs",
    filter: { column: "project_id", value: projectId },
    onDataChange: load,
  });

  const acceptedOrgs = projectOrgs.filter((po) => po.status === "accepted");
  const pendingOrgs = projectOrgs.filter((po) => po.status === "pending");

  async function inviteOrg(orgId: string): Promise<{ error: string | null }> {
    const {
      data: { user },
    } = await supabase.auth.getUser();
    if (!user) return { error: "Nicht angemeldet" };

    const { error } = await supabase.from("project_orgs").insert({
      project_id: projectId,
      org_id: orgId,
      role: "partner",
      status: "pending",
      invited_by: user.id,
    });

    if (error) return { error: error.message };
    load();
    return { error: null };
  }

  async function acceptPartnership(projectOrgId: string) {
    await supabase
      .from("project_orgs")
      .update({ status: "accepted", responded_at: new Date().toISOString() })
      .eq("id", projectOrgId);
    load();
  }

  async function declinePartnership(projectOrgId: string) {
    await supabase
      .from("project_orgs")
      .update({ status: "declined", responded_at: new Date().toISOString() })
      .eq("id", projectOrgId);
    load();
  }

  async function removeOrg(projectOrgId: string) {
    await supabase
      .from("project_orgs")
      .update({ status: "removed", responded_at: new Date().toISOString() })
      .eq("id", projectOrgId);
    load();
  }

  return {
    projectOrgs,
    acceptedOrgs,
    pendingOrgs,
    loading,
    inviteOrg,
    acceptPartnership,
    declinePartnership,
    removeOrg,
  };
}
