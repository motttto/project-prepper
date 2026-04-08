"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import type { UserPermissions, PermissionModule } from "@/types/database";
import { defaultPermissionsByRole, modulePermissionMap } from "@/types/database";

export interface CurrentUser {
  id: string;
  email: string;
  name: string;
  avatarUrl: string | null;
  roleName: string; // 'admin' | 'manager' | 'member' (in aktueller Org)
  isActive: boolean; // aktiv in aktueller Org
  isSuperadmin: boolean; // App-weiter Superadmin (alle Orgs, alle Rechte)
  /** @deprecated Nutze isSuperadmin */
  isSystem: boolean;
  orgId: string | null;
  permissions: UserPermissions; // aufgelöste Berechtigungen
}

// Prüft ob User Org-Admin oder Superadmin ist
export function isOrgAdmin(user: CurrentUser | null): boolean {
  if (!user) return false;
  return user.roleName === "admin" || user.isSuperadmin;
}

// Prüft eine feingranulare Permission (z.B. "costs_view") oder ein grobes Modul (z.B. "costs")
export function hasPermission(user: CurrentUser | null, key: string): boolean {
  if (!user) return false;
  if (user.isSuperadmin || user.roleName === "admin") return true;
  // Grobes Modul → auf _view Key mappen
  const permKey = (modulePermissionMap as Record<string, string>)[key] || key;
  return user.permissions[permKey] ?? false;
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

      // Rolle + Status + Permissions aus org_memberships (wenn orgId vorhanden)
      let roleName = "member";
      let isActive = false;
      let userPermissions: UserPermissions | null = null;

      if (orgId) {
        const { data: membership } = await supabase
          .from("org_memberships")
          .select("is_active, permissions, roles(name)")
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
          userPermissions = membership.permissions as UserPermissions | null;
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

      // Permissions auflösen: individuell > Rollen-Default > alles aus
      const resolvedPermissions: UserPermissions = userPermissions
        || defaultPermissionsByRole[roleName]
        || { projects: true, inventory: true, costs: false, team: false, inquiries: false };

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
        isActive: isSystem || isActive, // Superadmin immer aktiv
        isSuperadmin: isSystem,
        isSystem, // Backward-Compat
        orgId,
        permissions: resolvedPermissions,
      });
    }

    fetchUser();
  }, [orgId]);

  return user;
}
