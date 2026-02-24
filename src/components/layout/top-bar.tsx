"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { createClient } from "@/lib/supabase";
import { IconLogout, IconUser, IconMenu } from "@/components/ui/icons";

interface TopBarProps {
  onMenuToggle?: () => void;
}

export function TopBar({ onMenuToggle }: TopBarProps) {
  const router = useRouter();
  const supabase = createClient();
  const [userName, setUserName] = useState<string>("");
  const [avatarUrl, setAvatarUrl] = useState<string | null>(null);
  const [roleName, setRoleName] = useState<string>("");

  useEffect(() => {
    async function load() {
      const {
        data: { user },
      } = await supabase.auth.getUser();
      if (!user) return;

      const { data: profile } = await supabase
        .from("profiles")
        .select("name, avatar_url, roles(name)")
        .eq("id", user.id)
        .single();

      if (profile) {
        setUserName(profile.name || user.email?.split("@")[0] || "User");
        setAvatarUrl(profile.avatar_url || null);
        const r = (profile as any).roles;
        if (r) {
          const name = Array.isArray(r) ? r[0]?.name : r.name;
          setRoleName(name || "");
        }
      }
    }
    load();

    const channel = supabase
      .channel("topbar-profile")
      .on(
        "postgres_changes",
        { event: "*", schema: "public", table: "profiles" },
        async () => {
          const {
            data: { user: u },
          } = await supabase.auth.getUser();
          if (!u) return;
          const { data: p } = await supabase
            .from("profiles")
            .select("name, avatar_url")
            .eq("id", u.id)
            .single();
          if (p) {
            setUserName(p.name);
            setAvatarUrl(p.avatar_url || null);
          }
        }
      )
      .subscribe();

    return () => {
      supabase.removeChannel(channel);
    };
  }, [supabase]);

  async function handleLogout() {
    await supabase.auth.signOut();
    router.push("/login");
    router.refresh();
  }

  return (
    <div className="flex items-center justify-between lg:justify-end gap-3 mb-6">
      {/* Hamburger — nur auf Mobile */}
      <button
        onClick={onMenuToggle}
        className="lg:hidden p-2 rounded-lg transition-colors"
        style={{ color: "var(--color-foreground)" }}
        onMouseEnter={(e) =>
          (e.currentTarget.style.background = "var(--color-muted)")
        }
        onMouseLeave={(e) =>
          (e.currentTarget.style.background = "transparent")
        }
      >
        <IconMenu size={22} />
      </button>

      {/* Rechte Seite: Profil + Logout */}
      <div className="flex items-center gap-3">
        <Link
          href="/profile"
          className="flex items-center gap-2.5 px-3 py-1.5 rounded-lg transition-colors"
          style={{ color: "var(--color-foreground)" }}
          onMouseEnter={(e) =>
            (e.currentTarget.style.background = "var(--color-muted)")
          }
          onMouseLeave={(e) =>
            (e.currentTarget.style.background = "transparent")
          }
          title="Profil bearbeiten"
        >
          <div className="text-right hidden sm:block">
            <div className="text-sm font-medium leading-tight">{userName}</div>
            {roleName && (
              <div
                className="text-[10px] leading-tight"
                style={{ color: "var(--color-muted-foreground)" }}
              >
                {roleName === "admin"
                  ? "Admin"
                  : roleName === "manager"
                    ? "Manager"
                    : "Mitglied"}
              </div>
            )}
          </div>
          <div
            className="w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium flex-shrink-0 overflow-hidden"
            style={{
              background: avatarUrl
                ? "transparent"
                : "var(--color-primary-light)",
              color: "var(--color-primary)",
            }}
          >
            {avatarUrl ? (
              <img
                src={avatarUrl}
                alt={userName}
                className="w-full h-full object-cover"
              />
            ) : userName ? (
              userName.charAt(0).toUpperCase()
            ) : (
              <IconUser size={16} />
            )}
          </div>
        </Link>
        <button
          onClick={handleLogout}
          className="p-1.5 rounded-md transition-colors"
          style={{ color: "var(--color-muted-foreground)" }}
          onMouseEnter={(e) => {
            e.currentTarget.style.background = "var(--color-muted)";
            e.currentTarget.style.color = "var(--color-foreground)";
          }}
          onMouseLeave={(e) => {
            e.currentTarget.style.background = "transparent";
            e.currentTarget.style.color = "var(--color-muted-foreground)";
          }}
          title="Abmelden"
        >
          <IconLogout size={18} />
        </button>
      </div>
    </div>
  );
}
