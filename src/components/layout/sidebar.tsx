"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
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
} from "@/components/ui/icons";
import { OrgSwitcher } from "@/components/layout/org-switcher";
import { useOrg } from "@/contexts/org-context";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useImpersonate } from "@/contexts/impersonate-context";

// Nav-Items: solo = immer sichtbar, group = nur wenn Group-Modus aktiv
const navItems: { href: string; label: string; icon: typeof IconDashboard; scope: "solo" | "group" | "always"; adminOnly?: boolean }[] = [
  // Solo-Bereich (immer sichtbar)
  { href: "/dashboard", label: "Dashboard", icon: IconDashboard, scope: "always" },
  { href: "/inventory", label: "Mein Inventar", icon: IconInventory, scope: "always" },
  { href: "/inquiries", label: "Meine Anfragen", icon: IconInbox, scope: "always" },
  { href: "/projects", label: "Meine Projekte", icon: IconProjects, scope: "always" },
  // Group-Bereich (nur in Gruppe sichtbar)
  { href: "/team", label: "Team", icon: IconUsers, scope: "group" },
  { href: "/costs", label: "Kosten", icon: IconCosts, scope: "group" },
  { href: "/calendar", label: "Kalender", icon: IconCalendar, scope: "group" },
  { href: "/polls", label: "Umfragen", icon: IconClipboard, scope: "group" },
  // Admin
  { href: "/admin", label: "Admin", icon: IconZap, scope: "always", adminOnly: true },
];

interface SidebarProps {
  isOpen?: boolean;
  onClose?: () => void;
}

export function Sidebar({ isOpen = false, onClose }: SidebarProps) {
  const pathname = usePathname();
  const supabase = createClient();
  // Notification-Counts pro Menüpunkt
  const [badgeCounts, setBadgeCounts] = useState<Record<string, number>>({});
  const { orgId } = useOrg();
  const currentUser = useCurrentUser();
  const { impersonating } = useImpersonate();
  const userId = currentUser?.id;

  useEffect(() => {
    if (!orgId || !userId) return;

    async function loadCounts() {
      const counts: Record<string, number> = {};

      // Team: Pending memberships + pending org invitations
      const [{ count: pendingMembers }, { count: pendingInvites }] = await Promise.all([
        supabase
          .from("org_memberships")
          .select("*", { count: "exact", head: true })
          .eq("org_id", orgId)
          .eq("is_active", false)
          .is("approved_at", null),
        supabase
          .from("org_invitations")
          .select("*", { count: "exact", head: true })
          .eq("org_id", orgId)
          .eq("status", "pending"),
      ]);
      counts["/team"] = (pendingMembers || 0) + (pendingInvites || 0);

      // Projekte: Pending project invitations für diesen User
      const { count: projectInvites } = await supabase
        .from("project_invitations")
        .select("*", { count: "exact", head: true })
        .eq("invited_profile_id", userId)
        .eq("status", "pending");
      counts["/projects"] = projectInvites || 0;

      // Anfragen: Pending inquiry invitations für diesen User
      const { count: inquiryInvites } = await supabase
        .from("inquiry_invitations")
        .select("*", { count: "exact", head: true })
        .eq("invited_profile_id", userId)
        .eq("status", "pending");
      counts["/inquiries"] = inquiryInvites || 0;

      setBadgeCounts(counts);
    }
    loadCounts();

    // Realtime: Counts aktualisieren bei Änderungen
    const channel = supabase
      .channel("sidebar-badges")
      .on("postgres_changes", { event: "*", schema: "public", table: "org_memberships" }, loadCounts)
      .on("postgres_changes", { event: "*", schema: "public", table: "org_invitations" }, loadCounts)
      .on("postgres_changes", { event: "*", schema: "public", table: "project_invitations" }, loadCounts)
      .on("postgres_changes", { event: "*", schema: "public", table: "inquiry_invitations" }, loadCounts)
      .on("postgres_changes", { event: "*", schema: "public", table: "task_notifications" }, loadCounts)
      .subscribe();

    return () => {
      supabase.removeChannel(channel);
    };
  }, [supabase, orgId, userId]);

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

      {/* Org-Switcher */}
      <div className="pt-3">
        <OrgSwitcher />
      </div>

      {/* Navigation */}
      <nav className="flex-1 px-3 py-4 space-y-1">
        {navItems.filter((item) => {
          // Admin-only Items: nur fuer Superadmin sichtbar
          if (item.adminOnly) {
            if (impersonating) return false;
            return currentUser?.isSuperadmin ?? false;
          }
          // Group-Items nur sichtbar wenn Group-Modus aktiv
          if (item.scope === "group" && !orgId) return false;
          // Solo-Items immer sichtbar (in beiden Modi)
          return true;
        }).map((item) => {
          const isActive =
            pathname === item.href || pathname.startsWith(item.href + "/");
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

    </aside>
  );
}
