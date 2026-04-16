"use client";

// WORKSPACE-CONTEXT (frueher OrgContext)
// =====================================
// Nach Refactor (Phase 1): Org-Konzept entfaellt. Stattdessen:
// - Solo-Modus: groupId === null, User arbeitet auf eigenen Daten
// - Group-Modus: groupId gesetzt, User in einer Gruppe aktiv
//
// Der Context behaelt den Namen `useOrg` als Alias damit bestehender Code
// schrittweise migriert werden kann. Neue Code soll `useWorkspace` nutzen.

import {
  createContext,
  useContext,
  useState,
  useEffect,
  useCallback,
  type ReactNode,
} from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";

// ── Types ──

export interface GroupSummary {
  id: string;
  name: string;
  slug: string;
  isActive: boolean;
  isFounder: boolean;
}

export interface WorkspaceContextValue {
  /** null = Solo-Modus, sonst aktive Gruppe */
  groupId: string | null;
  groupName: string;
  groupSlug: string;
  /** Alle Gruppen des Users (auch inaktive, z.B. waehrend Voting) */
  groups: GroupSummary[];
  activeGroup: GroupSummary | null;
  /** Wechselt Workspace. null = Solo-Modus */
  switchWorkspace: (groupId: string | null) => void;
  isSolo: boolean;
  loading: boolean;
  reload: () => Promise<void>;
  // ── Backward-Compat (alter Code nutzt orgId/orgs/etc.) ──
  /** @deprecated Nutze groupId */
  orgId: string | null;
  /** @deprecated Nutze groupName */
  orgName: string;
  /** @deprecated Nutze groupSlug */
  orgSlug: string;
  /** @deprecated Nutze groups */
  orgs: { id: string; name: string; slug: string; roleName: string; isActive: boolean }[];
  /** @deprecated Nutze activeGroup */
  activeOrg: { id: string; name: string; slug: string; roleName: string; isActive: boolean } | null;
  /** @deprecated Nutze switchWorkspace */
  switchOrg: (id: string) => void;
}

const WorkspaceContext = createContext<WorkspaceContextValue>({
  groupId: null,
  groupName: "",
  groupSlug: "",
  groups: [],
  activeGroup: null,
  switchWorkspace: () => {},
  isSolo: true,
  loading: true,
  reload: async () => {},
  orgId: null,
  orgName: "",
  orgSlug: "",
  orgs: [],
  activeOrg: null,
  switchOrg: () => {},
});

// ── Cookie ──
// Cookie-Wert: "solo" oder die group_id (UUID)
const COOKIE_NAME = "pp_workspace";

function getWorkspaceFromCookie(): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${COOKIE_NAME}=([^;]*)`));
  if (!match) return null;
  const v = decodeURIComponent(match[1]);
  return v === "solo" ? null : v;
}

function setWorkspaceCookie(groupId: string | null) {
  const v = groupId ?? "solo";
  document.cookie = `${COOKIE_NAME}=${encodeURIComponent(v)};path=/;max-age=${60 * 60 * 24 * 365};SameSite=Lax`;
}

// ── Provider ──

export function OrgProvider({ children }: { children: ReactNode }) {
  const [groups, setGroups] = useState<GroupSummary[]>([]);
  const [activeGroup, setActiveGroup] = useState<GroupSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const router = useRouter();

  const loadGroups = useCallback(async () => {
    const supabase = createClient();
    const { data: { user } } = await supabase.auth.getUser();
    if (!user) {
      setLoading(false);
      return;
    }

    // Group-Memberships laden (nur aktive sichtbar im Switcher)
    const { data: memberships } = await supabase
      .from("group_memberships")
      .select("group_id, is_active, is_founder, groups(id, name, slug)")
      .eq("profile_id", user.id);

    const list: GroupSummary[] = (memberships || []).map((m: any) => ({
      id: m.groups?.id || m.group_id,
      name: m.groups?.name || "?",
      slug: m.groups?.slug || "",
      isActive: m.is_active,
      isFounder: m.is_founder,
    }));

    setGroups(list);

    // Aktive Group aus Cookie wiederherstellen
    const cookieGroupId = getWorkspaceFromCookie();
    if (cookieGroupId) {
      const found = list.find((g) => g.id === cookieGroupId && g.isActive);
      if (found) {
        setActiveGroup(found);
      } else {
        // Cookie verweist auf Group die User nicht (mehr) hat -> Solo
        setActiveGroup(null);
        setWorkspaceCookie(null);
      }
    } else {
      // Default: Solo-Modus
      setActiveGroup(null);
    }

    setLoading(false);
  }, []);

  useEffect(() => {
    loadGroups();
  }, [loadGroups]);

  // Realtime: bei Group-Changes neu laden
  useEffect(() => {
    const supabase = createClient();
    const channel = supabase
      .channel("workspace-context")
      .on(
        "postgres_changes",
        { event: "*", schema: "public", table: "group_memberships" },
        () => loadGroups()
      )
      .subscribe();

    return () => {
      supabase.removeChannel(channel);
    };
  }, [loadGroups]);

  const switchWorkspace = useCallback(
    (groupId: string | null) => {
      if (groupId === null) {
        setActiveGroup(null);
        setWorkspaceCookie(null);
      } else {
        const g = groups.find((x) => x.id === groupId);
        if (!g || !g.isActive) return;
        setActiveGroup(g);
        setWorkspaceCookie(groupId);
      }
      router.refresh();
    },
    [groups, router]
  );

  const value: WorkspaceContextValue = {
    groupId: activeGroup?.id ?? null,
    groupName: activeGroup?.name ?? "",
    groupSlug: activeGroup?.slug ?? "",
    groups,
    activeGroup,
    switchWorkspace,
    isSolo: activeGroup === null,
    loading,
    reload: loadGroups,
    // Backward-Compat
    orgId: activeGroup?.id ?? null,
    orgName: activeGroup?.name ?? "",
    orgSlug: activeGroup?.slug ?? "",
    orgs: groups.map((g) => ({
      id: g.id,
      name: g.name,
      slug: g.slug,
      roleName: g.isFounder ? "founder" : "member",
      isActive: g.isActive,
    })),
    activeOrg: activeGroup
      ? {
          id: activeGroup.id,
          name: activeGroup.name,
          slug: activeGroup.slug,
          roleName: activeGroup.isFounder ? "founder" : "member",
          isActive: activeGroup.isActive,
        }
      : null,
    switchOrg: (id: string) => switchWorkspace(id),
  };

  return (
    <WorkspaceContext.Provider value={value}>
      {children}
    </WorkspaceContext.Provider>
  );
}

// ── Hooks ──

export function useWorkspace(): WorkspaceContextValue {
  return useContext(WorkspaceContext);
}

/** @deprecated Nutze useWorkspace() */
export function useOrg(): WorkspaceContextValue {
  return useContext(WorkspaceContext);
}
