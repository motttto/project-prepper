"use client";

import { useState, useEffect } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";

export interface CurrentUser {
  id: string;
  email: string;
  name: string;
  avatarUrl: string | null;
  roleName: string; // 'admin' | 'manager' | 'member' (in aktueller Org)
  isActive: boolean; // aktiv in aktueller Org
  isSystem: boolean; // globales System-Flag
  orgId: string | null;
}

export function useCurrentUser(): CurrentUser | null {
  const [user, setUser] = useState<CurrentUser | null>(null);
  const { orgId } = useOrg();

  useEffect(() => {
    const supabase = createClient();

    async function fetchUser() {
      const {
        data: { user: authUser },
      } = await supabase.auth.getUser();
      if (!authUser) return;

      // Profil-Basisdaten (name, email, is_system, avatar)
      const { data: profile } = await supabase
        .from("profiles")
        .select("name, email, is_system, avatar_url")
        .eq("id", authUser.id)
        .single();

      // Rolle + Status aus org_memberships (wenn orgId vorhanden)
      let roleName = "member";
      let isActive = false;

      if (orgId) {
        const { data: membership } = await supabase
          .from("org_memberships")
          .select("is_active, roles(name)")
          .eq("profile_id", authUser.id)
          .eq("org_id", orgId)
          .single();

        if (membership) {
          // eslint-disable-next-line @typescript-eslint/no-explicit-any
          const rolesRaw = membership.roles as any;
          const role: { name: string } | null = Array.isArray(rolesRaw)
            ? rolesRaw[0] ?? null
            : rolesRaw ?? null;
          roleName = role?.name || "member";
          isActive = membership.is_active ?? false;
        }
      } else {
        // Fallback: Lese aus profiles.role_id (Backward-Compat, vor Org-Migration)
        const { data: profileWithRole } = await supabase
          .from("profiles")
          .select("is_active, roles(name)")
          .eq("id", authUser.id)
          .single();
        if (profileWithRole) {
          // eslint-disable-next-line @typescript-eslint/no-explicit-any
          const rolesRaw = profileWithRole.roles as any;
          const role: { name: string } | null = Array.isArray(rolesRaw)
            ? rolesRaw[0] ?? null
            : rolesRaw ?? null;
          roleName = role?.name || "member";
          isActive = profileWithRole.is_active ?? false;
        }
      }

      const isSystem = !!(profile as any)?.is_system;

      setUser({
        id: authUser.id,
        email: profile?.email || authUser.email || "",
        name:
          profile?.name ||
          authUser.user_metadata?.name ||
          authUser.email?.split("@")[0] ||
          "User",
        avatarUrl: profile?.avatar_url || null,
        roleName,
        isActive: isSystem || isActive, // System-User immer aktiv
        isSystem,
        orgId,
      });
    }

    fetchUser();
  }, [orgId]);

  return user;
}
