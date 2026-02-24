"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import type { EffectiveProjectRole } from "@/types/database";
import { useRealtimeTable } from "@/hooks/use-realtime-table";

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

  const determine = useCallback(async () => {
    const supabase = createClient();
    const {
      data: { user },
    } = await supabase.auth.getUser();
    if (!user) {
      setLoading(false);
      return;
    }

    // Org-Level Admin Check
    const { data: profile } = await supabase
      .from("profiles")
      .select("role_id, roles(name)")
      .eq("id", user.id)
      .single();

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const rolesRaw = profile?.roles as any;
    const roles: { name: string } | null = Array.isArray(rolesRaw)
      ? rolesRaw[0] ?? null
      : rolesRaw ?? null;
    if (roles?.name === "admin") {
      setRole("admin");
      setLoading(false);
      return;
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
  }, [projectId]);

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
