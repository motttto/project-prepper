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
} from "@/components/ui/icons";
import { InvitationBell } from "@/components/layout/invitation-bell";

const navItems = [
  { href: "/team", label: "Team", icon: IconUsers },
  { href: "/dashboard", label: "Dashboard", icon: IconDashboard },
  { href: "/projects", label: "Projekte", icon: IconProjects },
  { href: "/inventory", label: "Inventar", icon: IconInventory },
  { href: "/costs", label: "Kosten", icon: IconCosts },
];

export function Sidebar() {
  const pathname = usePathname();
  const supabase = createClient();
  const [userId, setUserId] = useState<string | undefined>();
  const [pendingCount, setPendingCount] = useState(0);

  useEffect(() => {
    async function getUser() {
      const {
        data: { user },
      } = await supabase.auth.getUser();
      if (user) {
        setUserId(user.id);

        // Pending-Mitglieder zählen
        const { count } = await supabase
          .from("profiles")
          .select("*", { count: "exact", head: true })
          .eq("is_active", false);
        setPendingCount(count || 0);
      }
    }
    getUser();

    // Realtime: Pending-Count aktualisieren
    const channel = supabase
      .channel("sidebar-pending")
      .on(
        "postgres_changes",
        { event: "*", schema: "public", table: "profiles" },
        async () => {
          const { count } = await supabase
            .from("profiles")
            .select("*", { count: "exact", head: true })
            .eq("is_active", false);
          setPendingCount(count || 0);
        }
      )
      .subscribe();

    return () => {
      supabase.removeChannel(channel);
    };
  }, [supabase]);

  return (
    <aside
      className="w-[260px] min-h-screen flex flex-col"
      style={{ background: "var(--color-sidebar)" }}
    >
      {/* Logo */}
      <Link
        href="/dashboard"
        className="flex items-center gap-3 px-6 py-5 border-b"
        style={{ borderColor: "rgba(255,255,255,0.08)" }}
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
            className="text-[11px] -mt-0.5"
            style={{ color: "var(--color-sidebar-text-muted)" }}
          >
            Projektplanner
          </div>
        </div>
      </Link>

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
              className="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all duration-150 group"
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
                  className="ml-auto w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold text-white"
                  style={{ background: "var(--color-destructive)" }}
                >
                  {pendingCount}
                </span>
              )}
            </Link>
          );
        })}
      </nav>

      {/* Einladungen */}
      <div className="px-3 py-4 flex justify-center">
        <InvitationBell userId={userId} />
      </div>
    </aside>
  );
}
