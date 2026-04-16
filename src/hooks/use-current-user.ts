"use client";

// Aktueller User + Workspace-Kontext (Solo oder Group)
// =====================================================
// Nach Refactor: keine Org-Rolle mehr. Im Solo-Modus hat User volle Permissions
// auf eigenen Daten. In einer Gruppe gibt's noch keine differenzierten Rollen
// (alle Mitglieder gleichberechtigt — Beitritt war ja einstimmig).

import { useState, useEffect } from "react";
import { createClient } from "@/lib/supabase";
import { useWorkspace } from "@/contexts/org-context";
import type { UserPermissions, PermissionModule } from "@/types/database";
import { defaultPermissionsByRole, modulePermissionMap } from "@/types/database";

export interface CurrentUser {
  id: string;
  email: string;
  name: string;
  avatarUrl: string | null;
  /** Aktive Group-ID. null = Solo-Modus */
  groupId: string | null;
  /** "founder" | "member" — nur wenn in Group, sonst "" */
  groupRole: string;
  isSuperadmin: boolean;
  /** @deprecated Nutze isSuperadmin */
  isSystem: boolean;
  /** Im Solo-Modus immer true. In Gruppe: aktive Mitgliedschaft */
  isActive: boolean;
  permissions: UserPermissions;
  // ── Backward-Compat ──
  /** @deprecated Nutze groupId */
  orgId: string | null;
  /** @deprecated Im neuen Modell entfaellt — alle Member sind gleichberechtigt */
  roleName: string;
}

// Solo-User hat alle Permissions auf eigenen Daten
const SOLO_PERMISSIONS: UserPermissions = {
  projects_view: true,
  projects_edit: true,
  inventory_view: true,
  inventory_edit: true,
  excel_export: true,
  excel_import: true,
  costs_view: true,
  costs_edit: true,
  team_view: true,
  team_manage: true,
  inquiries_view: true,
  inquiries_edit: true,
  inquiries_create: true,
  polls_view: true,
  polls_create: true,
};

/** Permission-Check (Solo: immer erlaubt; Group: immer erlaubt — vorerst keine Differenzierung) */
export function hasPermission(user: CurrentUser | null, key: string): boolean {
  if (!user) return false;
  // Im neuen Modell: alle authentifizierten User haben alle Permissions
  // Differenzierung kommt zurueck wenn Group-Rollen-Konzept implementiert wird
  return true;
}

/** Im neuen Modell entfaellt das Konzept Org-Admin. Alle Group-Member sind gleich. */
export function isOrgAdmin(user: CurrentUser | null): boolean {
  if (!user) return false;
  // Founder einer Gruppe oder Superadmin
  return user.groupRole === "founder" || user.isSuperadmin;
}

export function useCurrentUser(): CurrentUser | null {
  const [user, setUser] = useState<CurrentUser | null>(null);
  const { groupId } = useWorkspace();

  useEffect(() => {
    const supabase = createClient();

    async function fetchUser() {
      const {
        data: { user: authUser },
      } = await supabase.auth.getUser();
      if (!authUser) return;

      // Profil laden
      const { data: profile } = await supabase
        .from("profiles")
        .select("name, email, is_system, avatar_url")
        .eq("id", authUser.id)
        .single();

      const isSystem = !!(profile as { is_system?: boolean } | null)?.is_system;

      // Group-Membership wenn Group aktiv
      let groupRole = "";
      let isActive = true;
      if (groupId) {
        const { data: membership } = await supabase
          .from("group_memberships")
          .select("is_active, is_founder")
          .eq("profile_id", authUser.id)
          .eq("group_id", groupId)
          .maybeSingle();
        if (membership) {
          groupRole = membership.is_founder ? "founder" : "member";
          isActive = membership.is_active;
        }
      }

      setUser({
        id: authUser.id,
        email: profile?.email || authUser.email || "",
        name:
          profile?.name ||
          authUser.user_metadata?.name ||
          authUser.email?.split("@")[0] ||
          "User",
        avatarUrl: profile?.avatar_url || null,
        groupId,
        groupRole,
        isSuperadmin: isSystem,
        isSystem,
        isActive: isSystem || isActive,
        permissions: SOLO_PERMISSIONS,
        // Backward-Compat
        orgId: groupId,
        roleName: groupRole,
      });
    }

    fetchUser();
  }, [groupId]);

  return user;
}
