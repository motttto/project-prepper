"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import type { EffectiveProjectRole } from "@/types/database";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import { useOrg } from "@/contexts/org-context";

interface UseProjectRoleResult {
  role: EffectiveProjectRole;
  loading: boolean;
  isMember: boolean;
  canViewCosts: boolean;
  canEditProject: boolean;
  isOwner: boolean;
}

export function useProjectRole(projectId: string): UseProjectRoleResult {
  const [role, setRole] = useState<EffectiveProjectRole>("none");
  const [loading, setLoading] = useState(true);
  const { orgId } = useOrg();

  const determine = useCallback(async () => {
    const supabase = createClient();
    const {
      data: { user },
    } = await supabase.auth.getUser();
    if (!user) {
      setLoading(false);
      return;
    }

    // System-User Check (globaler Admin)
    const { data: profile } = await supabase
      .from("profiles")
      .select("is_system")
      .eq("id", user.id)
      .single();

    if (profile?.is_system) {
      setRole("admin");
      setLoading(false);
      return;
    }

    // Org-Level Admin Check via org_memberships
    if (orgId) {
      const { data: orgMembership } = await supabase
        .from("org_memberships")
        .select("roles(name)")
        .eq("profile_id", user.id)
        .eq("org_id", orgId)
        .single();

      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const rolesRaw = orgMembership?.roles as any;
      const orgRole: { name: string } | null = Array.isArray(rolesRaw)
        ? rolesRaw[0] ?? null
        : rolesRaw ?? null;
      if (orgRole?.name === "admin") {
        // Prüfe ob die Org direkt das Projekt besitzt ODER ein akzeptierter Partner ist
        const { data: projectOrg } = await supabase
          .from("project_orgs")
          .select("id")
          .eq("project_id", projectId)
          .eq("org_id", orgId)
          .eq("status", "accepted")
          .maybeSingle();

        if (projectOrg) {
          setRole("admin");
          setLoading(false);
          return;
        }
      }
    }

    // Projekt-Mitgliedschaft prüfen
    const { data: membership } = await supabase
      .from("project_members")
      .select("role")
      .eq("project_id", projectId)
      .eq("profile_id", user.id)
      .single();

    setRole((membership?.role as EffectiveProjectRole) ?? "none");
    setLoading(false);
  }, [projectId, orgId]);

  useEffect(() => {
    determine();
  }, [determine]);

  // Realtime: Mitgliedschaft live aktualisieren
  useRealtimeTable({
    table: "project_members",
    filter: { column: "project_id", value: projectId },
    onDataChange: determine,
  });

  const isMember = role !== "none";
  const canViewCosts = isMember;
  const canEditProject =
    role === "owner" || role === "editor" || role === "admin";
  const isOwner = role === "owner" || role === "admin";

  return { role, loading, isMember, canViewCosts, canEditProject, isOwner };
}
