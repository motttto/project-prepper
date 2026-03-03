"use client";

import { useState, useEffect } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { IconZap, IconClock, IconLogout } from "@/components/ui/icons";

export default function PendingPage() {
  const supabase = createClient();
  const router = useRouter();
  const [userName, setUserName] = useState("");
  const [voteCount, setVoteCount] = useState(0);
  const [activeCount, setActiveCount] = useState(0);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let channel: ReturnType<typeof supabase.channel> | null = null;

    async function load() {
      const {
        data: { user },
      } = await supabase.auth.getUser();
      if (!user) {
        router.push("/login");
        return;
      }

      // Eigenes Profil prüfen
      const { data: profile } = await supabase
        .from("profiles")
        .select("name, is_active")
        .eq("id", user.id)
        .single();

      if (profile?.is_active) {
        router.push("/dashboard");
        router.refresh();
        return;
      }
      setUserName(profile?.name || "");

      // Stimmen + aktive Mitglieder laden
      await loadCounts(user.id);
      setLoading(false);

      // Realtime: Profil-Updates + neue Votes
      channel = supabase
        .channel("pending-check")
        .on(
          "postgres_changes",
          {
            event: "UPDATE",
            schema: "public",
            table: "profiles",
            filter: `id=eq.${user.id}`,
          },
          (payload) => {
            if ((payload.new as { is_active: boolean }).is_active) {
              router.push("/dashboard");
              router.refresh();
            }
          }
        )
        .on(
          "postgres_changes",
          { event: "*", schema: "public", table: "team_votes" },
          () => {
            loadCounts(user.id);
          }
        )
        .subscribe();
    }

    async function loadCounts(userId: string) {
      const [votesRes, activeRes] = await Promise.all([
        supabase
          .from("team_votes")
          .select("*", { count: "exact", head: true })
          .eq("candidate_id", userId),
        supabase
          .from("profiles")
          .select("*", { count: "exact", head: true })
          .eq("is_active", true),
      ]);
      setVoteCount(votesRes.count || 0);
      setActiveCount(activeRes.count || 0);
    }

    load();

    return () => {
      if (channel) supabase.removeChannel(channel);
    };
  }, [supabase, router]);

  async function handleLogout() {
    await supabase.auth.signOut();
    router.push("/login");
    router.refresh();
  }

  const progress = activeCount > 0 ? (voteCount / activeCount) * 100 : 0;

  return (
    <div
      className="min-h-screen flex"
      style={{ background: "var(--color-background)" }}
    >
      {/* Left: Branding (wie Login-Seite) */}
      <div
        className="hidden lg:flex w-[45%] flex-col justify-between p-12"
        style={{ background: "var(--color-sidebar)" }}
      >
        <div>
          <div className="flex items-center gap-3 mb-12">
            <div
              className="w-10 h-10 rounded-lg flex items-center justify-center"
              style={{ background: "var(--color-sidebar-active)" }}
            >
              <IconZap size={22} className="text-white" />
            </div>
            <div>
              <div
                className="font-bold text-lg"
                style={{ color: "var(--color-sidebar-text)" }}
              >
                Project Prepper
              </div>
            </div>
          </div>
          <h2
            className="text-3xl font-bold leading-tight mb-4"
            style={{ color: "var(--color-sidebar-text)" }}
          >
            Willkommen im Team!
          </h2>
          <p
            className="text-sm leading-relaxed"
            style={{ color: "var(--color-sidebar-text-muted)" }}
          >
            Dein Beitritt wird vom Team geprüft. Sobald alle Mitglieder
            zugestimmt haben, wirst du automatisch weitergeleitet.
          </p>
        </div>
        <div
          className="text-xs"
          style={{ color: "var(--color-sidebar-text-muted)" }}
        >
          Project Prepper &middot; Projektmanagement
        </div>
      </div>

      {/* Right: Pending Status */}
      <div className="flex-1 flex items-center justify-center p-8">
        <div className="w-full max-w-sm space-y-6 text-center">
          {/* Mobile Logo */}
          <div className="lg:hidden flex items-center gap-3 justify-center mb-4">
            <div
              className="w-9 h-9 rounded-lg flex items-center justify-center"
              style={{ background: "var(--color-primary)" }}
            >
              <IconZap size={20} className="text-white" />
            </div>
            <span className="font-bold text-lg">Project Prepper</span>
          </div>

          {loading ? (
            <div
              className="py-12"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              Wird geladen...
            </div>
          ) : (
            <>
              {/* Clock Icon */}
              <div className="flex justify-center">
                <div
                  className="w-16 h-16 rounded-2xl flex items-center justify-center"
                  style={{ background: "var(--color-warning-light)" }}
                >
                  <IconClock
                    size={32}
                    style={{ color: "var(--color-warning)" }}
                  />
                </div>
              </div>

              <div>
                <h1 className="text-2xl font-bold">Warte auf Freigabe</h1>
                <p
                  className="text-sm mt-2"
                  style={{ color: "var(--color-muted-foreground)" }}
                >
                  Hallo {userName}! Dein Beitritt wird vom Team geprüft.
                </p>
              </div>

              {/* Fortschrittsanzeige */}
              <div
                className="p-5 rounded-xl space-y-3"
                style={{ background: "var(--color-surface)" }}
              >
                <div className="flex items-center justify-between text-sm">
                  <span style={{ color: "var(--color-muted-foreground)" }}>
                    Zustimmungen
                  </span>
                  <span className="font-bold">
                    {voteCount} von {activeCount}
                  </span>
                </div>

                {/* Fortschrittsbalken */}
                <div
                  className="w-full h-2.5 rounded-full overflow-hidden"
                  style={{ background: "var(--color-muted)" }}
                >
                  <div
                    className="h-full rounded-full transition-all duration-500"
                    style={{
                      width: `${Math.min(progress, 100)}%`,
                      background:
                        progress >= 100
                          ? "var(--color-success)"
                          : "var(--color-primary)",
                    }}
                  />
                </div>

                <p
                  className="text-xs"
                  style={{ color: "var(--color-muted-foreground)" }}
                >
                  {activeCount === 0
                    ? "Noch keine aktiven Mitglieder"
                    : voteCount === 0
                      ? "Noch keine Stimmen eingegangen"
                      : voteCount >= activeCount
                        ? "Freigabe wird verarbeitet..."
                        : `Noch ${activeCount - voteCount} Stimme${activeCount - voteCount !== 1 ? "n" : ""} nötig`}
                </p>
              </div>

              {/* Logout */}
              <button
                onClick={handleLogout}
                className="flex items-center gap-2 mx-auto px-4 py-2 rounded-lg text-sm transition-colors"
                style={{
                  color: "var(--color-muted-foreground)",
                  border: "1px solid var(--color-border)",
                }}
                onMouseEnter={(e) =>
                  (e.currentTarget.style.background = "var(--color-muted)")
                }
                onMouseLeave={(e) =>
                  (e.currentTarget.style.background = "transparent")
                }
              >
                <IconLogout size={16} />
                Abmelden
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
