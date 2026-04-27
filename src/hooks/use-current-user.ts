"use client";

// Aktueller User + Workspace-Kontext (Solo oder Group)
// =====================================================
// Nach Refactor: keine Org-Rolle mehr. Im Solo-Modus hat User volle Permissions
// auf eigenen Daten. In einer Gruppe gibt's noch keine differenzierten Rollen
// (alle Mitglieder gleichberechtigt — Beitritt war ja einstimmig).

import { useState, useEffect } from "react";
import { createClient } from "@/lib/supabase";
import { useWorkspace } from "@/contexts/org-context";
import { useImpersonate } from "@/contexts/impersonate-context";
import type { UserPermissions, PermissionModule } from "@/types/database";
import { defaultPermissionsByRole, modulePermissionMap } from "@/types/database";

export interface CurrentUser {
  id: string;
  email: string;
  name: string;
  avatarUrl: string | null;
  /** Suffix für Inventarnummern (z.B. 'MOT'). null = noch nicht gesetzt */
  inventorySuffix: string | null;
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
  // Differenzierung kommt zurück wenn Group-Rollen-Konzept implementiert wird
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
  const { impersonating } = useImpersonate();
  const impersonateProfileId = impersonating?.profileId ?? null;

  useEffect(() => {
    const supabase = createClient();

    async function fetchUser() {
      const {
        data: { user: authUser },
      } = await supabase.auth.getUser();
      if (!authUser) return;

      // Im Impersonation-Modus: Daten des imitierten Users laden
      // (echter Auth-User ist immer noch der Superadmin in der Session,
      // aber currentUser.id ist die ID des imitierten Users — Pages, die
      // nach owner_profile_id filtern, sehen dann dessen Daten)
      const targetProfileId = impersonateProfileId || authUser.id;

      const { data: profile } = await supabase
        .from("profiles")
        .select("id, name, email, is_system, avatar_url, inventory_suffix")
        .eq("id", targetProfileId)
        .single();

      const isSystem = !!(profile as { is_system?: boolean } | null)?.is_system;
      const inventorySuffix =
        (profile as { inventory_suffix?: string | null } | null)?.inventory_suffix ?? null;

      // Group-Role bezogen auf den anzuzeigenden User
      let groupRole = "";
      let isActive = true;
      if (groupId) {
        const { data: membership } = await supabase
          .from("group_memberships")
          .select("is_active, is_founder")
          .eq("profile_id", targetProfileId)
          .eq("group_id", groupId)
          .maybeSingle();
        if (membership) {
          groupRole = membership.is_founder ? "founder" : "member";
          isActive = membership.is_active;
        }
      }

      setUser({
        id: targetProfileId,
        email: profile?.email || authUser.email || "",
        name:
          profile?.name ||
          authUser.user_metadata?.name ||
          authUser.email?.split("@")[0] ||
          "User",
        avatarUrl: profile?.avatar_url || null,
        inventorySuffix,
        groupId,
        groupRole,
        // isSuperadmin spiegelt den ECHTEN Auth-User wider — sonst koennte ein
        // imitierter Nicht-Admin Admin-UI sehen. Wir laden dafuer sein eigenes Profil.
        isSuperadmin: !impersonateProfileId && isSystem,
        isSystem: !impersonateProfileId && isSystem,
        isActive: (!impersonateProfileId && isSystem) || isActive,
        permissions: SOLO_PERMISSIONS,
        // Backward-Compat
        orgId: groupId,
        roleName: groupRole,
      });
    }

    fetchUser();
  }, [groupId, impersonateProfileId]);

  return user;
}
