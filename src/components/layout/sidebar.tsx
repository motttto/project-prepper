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
} from "@/components/ui/icons";
import { OrgSwitcher } from "@/components/layout/org-switcher";
import { useOrg } from "@/contexts/org-context";

const navItems = [
  { href: "/team", label: "Team", icon: IconUsers },
  { href: "/dashboard", label: "Dashboard", icon: IconDashboard },
  { href: "/projects", label: "Projekte", icon: IconProjects },
  { href: "/inventory", label: "Inventar", icon: IconInventory },
  { href: "/costs", label: "Kosten", icon: IconCosts },
];

interface SidebarProps {
  isOpen?: boolean;
  onClose?: () => void;
}

export function Sidebar({ isOpen = false, onClose }: SidebarProps) {
  const pathname = usePathname();
  const supabase = createClient();
  const [pendingCount, setPendingCount] = useState(0);
  const { orgId } = useOrg();

  useEffect(() => {
    if (!orgId) return;

    async function load() {
      // Nur echte Beitritte zählen (inaktiv + nie freigegeben, in aktueller Org)
      const { count } = await supabase
        .from("org_memberships")
        .select("*", { count: "exact", head: true })
        .eq("org_id", orgId)
        .eq("is_active", false)
        .is("approved_at", null);
      setPendingCount(count || 0);
    }
    load();

    // Realtime: Pending-Count aktualisieren
    const channel = supabase
      .channel("sidebar-pending")
      .on(
        "postgres_changes",
        { event: "*", schema: "public", table: "org_memberships" },
        async () => {
          const { count } = await supabase
            .from("org_memberships")
            .select("*", { count: "exact", head: true })
            .eq("org_id", orgId)
            .eq("is_active", false)
            .is("approved_at", null);
          setPendingCount(count || 0);
        }
      )
      .subscribe();

    return () => {
      supabase.removeChannel(channel);
    };
  }, [supabase, orgId]);

  // Sidebar auf Mobile schließen bei Navigation
  useEffect(() => {
    if (onClose) onClose();
  }, [pathname]); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <aside
      className={`
        fixed inset-y-0 left-0 z-50 w-[260px] min-h-screen flex flex-col
        transform transition-transform duration-200 ease-in-out
        lg:relative lg:translate-x-0 lg:z-auto
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
              Dunkelstrom
            </div>
            <div
              className="text-xs -mt-0.5"
              style={{ color: "var(--color-sidebar-text-muted)" }}
            >
              Projektplanner
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
        {navItems.map((item) => {
          const isActive =
            pathname === item.href || pathname.startsWith(item.href + "/");
          const Icon = item.icon;
          const showBadge = item.href === "/team" && pendingCount > 0;

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
                  {pendingCount}
                </span>
              )}
            </Link>
          );
        })}
      </nav>

    </aside>
  );
}
