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

    // Projekt-Owner Check (user-first model)
    const { data: project } = await supabase
      .from("projects")
      .select("owner_profile_id")
      .eq("id", projectId)
      .maybeSingle();

    if (project?.owner_profile_id === user.id) {
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
