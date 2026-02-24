"use client";

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

export interface OrgSummary {
  id: string;
  name: string;
  slug: string;
  roleName: string;
  isActive: boolean;
}

export interface OrgContextValue {
  orgId: string | null;
  orgName: string;
  orgSlug: string;
  orgs: OrgSummary[];
  activeOrg: OrgSummary | null;
  switchOrg: (orgId: string) => void;
  loading: boolean;
  reload: () => Promise<void>;
}

const OrgContext = createContext<OrgContextValue>({
  orgId: null,
  orgName: "",
  orgSlug: "",
  orgs: [],
  activeOrg: null,
  switchOrg: () => {},
  loading: true,
  reload: async () => {},
});

// ── Cookie Helpers ──

const COOKIE_NAME = "pp_org_id";

function getOrgIdFromCookie(): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${COOKIE_NAME}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : null;
}

function setOrgIdCookie(orgId: string) {
  document.cookie = `${COOKIE_NAME}=${encodeURIComponent(orgId)};path=/;max-age=${60 * 60 * 24 * 365};SameSite=Lax`;
}

// ── Provider ──

export function OrgProvider({ children }: { children: ReactNode }) {
  const [orgs, setOrgs] = useState<OrgSummary[]>([]);
  const [activeOrg, setActiveOrg] = useState<OrgSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const router = useRouter();

  const loadOrgs = useCallback(async () => {
    const supabase = createClient();
    const {
      data: { user },
    } = await supabase.auth.getUser();
    if (!user) {
      setLoading(false);
      return;
    }

    // Lade alle Org-Mitgliedschaften des Users
    const { data: memberships } = await supabase
      .from("org_memberships")
      .select("org_id, is_active, organizations(id, name, slug), roles(name)")
      .eq("profile_id", user.id);

    if (!memberships || memberships.length === 0) {
      // User hat keine Orgs → redirect zu /org/new
      setOrgs([]);
      setActiveOrg(null);
      setLoading(false);
      return;
    }

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const orgList: OrgSummary[] = memberships.map((m: any) => ({
      id: m.organizations?.id || m.org_id,
      name: m.organizations?.name || "?",
      slug: m.organizations?.slug || "",
      roleName: m.roles?.name || "member",
      isActive: m.is_active,
    }));

    setOrgs(orgList);

    // Aktive Org bestimmen
    const cookieOrgId = getOrgIdFromCookie();
    let selected = orgList.find((o) => o.id === cookieOrgId && o.isActive);

    // Fallback: erste aktive Org
    if (!selected) {
      selected = orgList.find((o) => o.isActive);
    }

    // Wenn keine aktive Org, nimm die erste (auch inaktive, für Pending-Seite)
    if (!selected && orgList.length > 0) {
      selected = orgList[0];
    }

    if (selected) {
      setActiveOrg(selected);
      setOrgIdCookie(selected.id);
    }

    setLoading(false);
  }, []);

  useEffect(() => {
    loadOrgs();
  }, [loadOrgs]);

  // Realtime: Auf Änderungen in org_memberships hören
  useEffect(() => {
    const supabase = createClient();
    const channel = supabase
      .channel("org-context-memberships")
      .on(
        "postgres_changes",
        { event: "*", schema: "public", table: "org_memberships" },
        () => {
          loadOrgs();
        }
      )
      .subscribe();

    return () => {
      supabase.removeChannel(channel);
    };
  }, [loadOrgs]);

  const switchOrg = useCallback(
    (orgId: string) => {
      const org = orgs.find((o) => o.id === orgId);
      if (!org) return;
      setActiveOrg(org);
      setOrgIdCookie(orgId);
      router.refresh();
    },
    [orgs, router]
  );

  return (
    <OrgContext.Provider
      value={{
        orgId: activeOrg?.id ?? null,
        orgName: activeOrg?.name ?? "",
        orgSlug: activeOrg?.slug ?? "",
        orgs,
        activeOrg,
        switchOrg,
        loading,
        reload: loadOrgs,
      }}
    >
      {children}
    </OrgContext.Provider>
  );
}

// ── Hook ──

export function useOrg(): OrgContextValue {
  return useContext(OrgContext);
}
