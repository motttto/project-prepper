"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { createClient } from "@/lib/supabase";
import {
  IconDashboard,
  IconProjects,
  IconInventory,
  IconCosts,
  IconUsers,
  IconZap,
  IconX,
  IconInbox,
  IconCalendar,
  IconClipboard,
  IconPackage,
} from "@/components/ui/icons";
import { useOrg, useWorkspace } from "@/contexts/org-context";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useImpersonate } from "@/contexts/impersonate-context";
import { FeedbackModal } from "@/components/feedback/feedback-modal";

// Nav-Items werden jetzt dynamisch (kontextsensitiv) generiert in buildNavItems().
type NavItem = { href: string; label: string; icon: typeof IconDashboard; adminOnly?: boolean };

function buildNavItems(activeGroupId: string | null): NavItem[] {
  if (activeGroupId) {
    // Gruppen-Kontext (Workspace-Badge zeigt Gruppenname → Prefix unnoetig)
    return [
      { href: "/dashboard", label: "Dashboard", icon: IconDashboard },
      { href: `/groups/${activeGroupId}`, label: "Übersicht", icon: IconUsers },
      { href: "/inventory", label: "Inventar", icon: IconInventory },
      { href: "/inquiries", label: "Anfragen", icon: IconInbox },
      { href: "/rentals", label: "Verleih", icon: IconPackage },
      { href: "/projects", label: "Projekte", icon: IconProjects },
      { href: "/calendar", label: "Kalender", icon: IconCalendar },
      { href: "/polls", label: "Umfragen", icon: IconClipboard },
      { href: "/groups", label: "Alle Gruppen", icon: IconUsers },
      { href: "/admin", label: "Admin", icon: IconZap, adminOnly: true },
    ];
  }
  // Solo-Kontext
  return [
    { href: "/dashboard", label: "Dashboard", icon: IconDashboard },
    { href: "/inventory", label: "Mein Inventar", icon: IconInventory },
    { href: "/inquiries", label: "Meine Anfragen", icon: IconInbox },
    { href: "/rentals", label: "Mein Verleih", icon: IconPackage },
    { href: "/projects", label: "Meine Projekte", icon: IconProjects },
    { href: "/groups", label: "Meine Gruppen", icon: IconUsers },
    { href: "/admin", label: "Admin", icon: IconZap, adminOnly: true },
  ];
}

interface SidebarProps {
  isOpen?: boolean;
  onClose?: () => void;
}

export function Sidebar({ isOpen = false, onClose }: SidebarProps) {
  const pathname = usePathname();
  const router = useRouter();
  const supabase = createClient();
  // Notification-Counts pro Menüpunkt
  const [badgeCounts, setBadgeCounts] = useState<Record<string, number>>({});
  const [showFeedback, setShowFeedback] = useState(false);
  const { orgId } = useOrg();
  const { groups, groupId: contextGroupId, switchWorkspace } = useWorkspace();
  const currentUser = useCurrentUser();

  // Aktive Gruppe: URL hat Vorrang (/groups/[id]/…), sonst Workspace-Cookie
  const groupRouteMatch = pathname.match(/^\/groups\/([0-9a-f-]{36})/i);
  const activeGroupId = groupRouteMatch?.[1] || contextGroupId || null;
  const activeGroup = activeGroupId
    ? groups.find((g) => g.id === activeGroupId)
    : null;
  const { impersonating } = useImpersonate();
  const userId = currentUser?.id;

  useEffect(() => {
    if (!userId) return;

    async function loadCounts() {
      const counts: Record<string, number> = {};

      // Pending project & inquiry invitations für diesen User (User-First)
      // + Pending rental approvals (Owner muss zustimmen, gemaess /modi nur Solo)
      const [projRes, inqRes, rentRes] = await Promise.all([
        supabase
          .from("project_invitations")
          .select("id")
          .eq("invited_profile_id", userId)
          .eq("status", "pending"),
        supabase
          .from("inquiry_invitations")
          .select("id")
          .eq("invited_profile_id", userId)
          .eq("status", "pending"),
        activeGroupId
          ? Promise.resolve({ data: [] as { id: string }[] })
          : supabase
              .from("rental_items")
              .select("id, inventory_items!inner(owner_profile_id)")
              .eq("approval_status", "pending")
              .eq("inventory_items.owner_profile_id", userId),
      ]);
      counts["/projects"] = projRes.data?.length || 0;
      counts["/inquiries"] = inqRes.data?.length || 0;
      counts["/rentals"] = rentRes.data?.length || 0;

      setBadgeCounts(counts);
    }
    loadCounts();

    // Realtime: Counts aktualisieren bei Änderungen (nur user-relevante Tabellen)
    const channel = supabase
      .channel("sidebar-badges")
      .on("postgres_changes", { event: "*", schema: "public", table: "project_invitations" }, loadCounts)
      .on("postgres_changes", { event: "*", schema: "public", table: "inquiry_invitations" }, loadCounts)
      .on("postgres_changes", { event: "*", schema: "public", table: "task_notifications" }, loadCounts)
      .on("postgres_changes", { event: "*", schema: "public", table: "rental_items" }, loadCounts)
      .subscribe();

    return () => {
      supabase.removeChannel(channel);
    };
  }, [supabase, userId, activeGroupId]);

  // Sidebar auf Mobile schließen bei Navigation
  useEffect(() => {
    if (onClose) onClose();
  }, [pathname]); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <aside
      className={`
        fixed inset-y-0 left-0 z-50 w-[260px] h-screen flex flex-col
        transform transition-transform duration-200 ease-in-out
        lg:sticky lg:top-0 lg:translate-x-0 lg:z-auto
        ${isOpen ? "translate-x-0" : "-translate-x-full"}
      `}
      style={{ background: "var(--color-sidebar)" }}
    >
      {/* Logo + Close Button (mobile) */}
      <div
        className="flex items-center justify-between px-6 py-5 border-b"
        style={{ borderColor: "rgba(255,255,255,0.08)" }}
      >
        <Link
          href="/dashboard"
          className="flex items-center gap-3"
        >
          <div
            className="w-9 h-9 rounded-lg flex items-center justify-center"
            style={{ background: "var(--color-sidebar-active)" }}
          >
            <IconZap size={20} className="text-white" />
          </div>
          <div>
            <div
              className="font-bold text-[15px]"
              style={{ color: "var(--color-sidebar-text)" }}
            >
              Project Prepper
            </div>
          </div>
        </Link>
        <button
          onClick={onClose}
          className="lg:hidden p-1.5 rounded-md transition-colors"
          style={{ color: "var(--color-sidebar-text-muted)" }}
        >
          <IconX size={20} />
        </button>
      </div>

      {/* Workspace-Info — klickbar im Group-Modus → zurück zu Solo */}
      <div className="px-3 pt-3 pb-2">
        {activeGroup ? (
          <button
            type="button"
            onClick={() => {
              switchWorkspace(null);
              // Wenn wir auf /groups/[id] sind, wuerde der URL-Kontext die Gruppe
              // sofort wieder aktivieren — also raus navigieren.
              if (pathname.startsWith("/groups/")) {
                router.push("/dashboard");
              }
            }}
            className="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-xs cursor-pointer"
            style={{
              background: "var(--color-sidebar-active)",
              color: "#fff",
            }}
            title={`Gruppe: ${activeGroup.name} · Klick → zurück zum Solo-Workspace`}
          >
            <span className="truncate font-medium flex-1 text-left">{activeGroup.name}</span>
            <IconX size={12} />
          </button>
        ) : (
          <div
            className="flex items-center gap-2 px-3 py-2 rounded-lg text-xs"
            style={{
              background: "rgba(255,255,255,0.05)",
              color: "var(--color-sidebar-text-muted)",
            }}
            title={`Solo-Workspace · ${currentUser?.name || ""}`}
          >
            <span className="truncate font-medium">{currentUser?.name || "Workspace"}</span>
          </div>
        )}
      </div>

      {/* Navigation */}
      <nav className="flex-1 px-3 py-4 space-y-1">
        {buildNavItems(activeGroupId).filter((item) => {
          if (item.adminOnly) {
            if (impersonating) return false;
            return currentUser?.isSuperadmin ?? false;
          }
          return true;
        }).map((item) => {
          // /groups exakt matchen, sonst markiert es sich auf /groups/[id] als aktiv
          const isActive =
            item.href === "/groups"
              ? pathname === "/groups"
              : pathname === item.href || pathname.startsWith(item.href + "/");
          const Icon = item.icon;
          const badgeCount = badgeCounts[item.href] || 0;
          const showBadge = badgeCount > 0;

          return (
            <Link
              key={item.href}
              href={item.href}
              className="flex items-center gap-3 px-3 py-3 rounded-lg transition-all duration-150 group"
              style={{
                background: isActive
                  ? "var(--color-sidebar-active)"
                  : "transparent",
                color: isActive ? "#ffffff" : "var(--color-sidebar-text-muted)",
              }}
              onMouseEnter={(e) => {
                if (!isActive) {
                  e.currentTarget.style.background =
                    "var(--color-sidebar-hover)";
                  e.currentTarget.style.color = "var(--color-sidebar-text)";
                }
              }}
              onMouseLeave={(e) => {
                if (!isActive) {
                  e.currentTarget.style.background = "transparent";
                  e.currentTarget.style.color =
                    "var(--color-sidebar-text-muted)";
                }
              }}
            >
              <Icon size={20} />
              <span className="text-[14px] font-medium">{item.label}</span>
              {showBadge && (
                <span
                  className="ml-auto w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold text-white"
                  style={{ background: "var(--color-destructive)" }}
                >
                  {badgeCount}
                </span>
              )}
            </Link>
          );
        })}
      </nav>

      {/* Feedback */}
      <div className="px-3 py-3 border-t" style={{ borderColor: "var(--color-border)" }}>
        <button
          onClick={() => setShowFeedback(true)}
          className="w-full text-left text-xs px-3 py-2 rounded-lg transition-colors"
          style={{ color: "var(--color-muted-foreground)" }}
          onMouseEnter={(e) => (e.currentTarget.style.background = "var(--color-muted)")}
          onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}
        >
          Feedback geben
        </button>
        {/* Legal-Footer */}
        <div className="flex items-center gap-3 px-3 mt-1 text-[11px]" style={{ color: "var(--color-muted-foreground)" }}>
          <Link href="/impressum" className="hover:underline">Impressum</Link>
          <Link href="/datenschutz" className="hover:underline">Datenschutz</Link>
          <Link href="/agb" className="hover:underline">AGB</Link>
        </div>
      </div>

      {showFeedback && <FeedbackModal onClose={() => setShowFeedback(false)} />}
    </aside>
  );
}
