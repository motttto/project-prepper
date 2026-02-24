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
  IconLogout,
  IconUser,
  IconZap,
} from "@/components/ui/icons";

const navItems = [
  { href: "/dashboard", label: "Dashboard", icon: IconDashboard },
  { href: "/projects", label: "Projekte", icon: IconProjects },
  { href: "/inventory", label: "Inventar", icon: IconInventory },
  { href: "/costs", label: "Kosten", icon: IconCosts },
];

export function Sidebar() {
  const pathname = usePathname();
  const router = useRouter();
  const supabase = createClient();
  const [userName, setUserName] = useState<string>("");

  useEffect(() => {
    async function getUser() {
      const {
        data: { user },
      } = await supabase.auth.getUser();
      if (user) {
        setUserName(
          user.user_metadata?.name || user.email?.split("@")[0] || "User"
        );
      }
    }
    getUser();
  }, [supabase.auth]);

  async function handleLogout() {
    await supabase.auth.signOut();
    router.push("/login");
    router.refresh();
  }

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
            </Link>
          );
        })}
      </nav>

      {/* User */}
      <div
        className="px-3 py-4 border-t"
        style={{ borderColor: "rgba(255,255,255,0.08)" }}
      >
        <div className="flex items-center gap-3 px-3 py-2">
          <div
            className="w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium"
            style={{
              background: "var(--color-sidebar-hover)",
              color: "var(--color-sidebar-text)",
            }}
          >
            {userName ? (
              userName.charAt(0).toUpperCase()
            ) : (
              <IconUser size={16} />
            )}
          </div>
          <div className="flex-1 min-w-0">
            <div
              className="text-sm font-medium truncate"
              style={{ color: "var(--color-sidebar-text)" }}
            >
              {userName || "Laden..."}
            </div>
          </div>
          <button
            onClick={handleLogout}
            className="p-1.5 rounded-md transition-colors"
            style={{ color: "var(--color-sidebar-text-muted)" }}
            onMouseEnter={(e) => {
              e.currentTarget.style.background = "var(--color-sidebar-hover)";
              e.currentTarget.style.color = "var(--color-sidebar-text)";
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.background = "transparent";
              e.currentTarget.style.color = "var(--color-sidebar-text-muted)";
            }}
            title="Abmelden"
          >
            <IconLogout size={18} />
          </button>
        </div>
      </div>
    </aside>
  );
}
