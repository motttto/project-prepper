"use client";

import { useEffect, useState, useCallback } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useWorkspace } from "@/contexts/org-context";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import {
  IconProjects,
  IconPackage,
  IconInbox,
  IconUsers,
  IconShield,
  IconChevronRight,
  IconPlus,
  IconCheck,
  IconX,
} from "@/components/ui/icons";
import { HowItWorksBanner } from "@/components/dashboard/how-it-works-banner";
import { showToast } from "@/hooks/use-toast";

interface PendingInvitation {
  id: string;
  group_id: string;
  invited_message: string | null;
  created_at: string;
  status: string;
  group?: { id: string; name: string; description: string | null };
  inviter?: { name: string };
}

export default function DashboardPage() {
  const supabase = createClient();
  const router = useRouter();
  const currentUser = useCurrentUser();
  const { isSolo, groupName, groups, reload, switchWorkspace } = useWorkspace();
  const [counts, setCounts] = useState({ inventory: 0, inquiries: 0, projects: 0 });
  const [pendingInvites, setPendingInvites] = useState<PendingInvitation[]>([]);

  const loadCounts = useCallback(async () => {
    if (!currentUser) return;
    const [invRes, inqRes, projRes, pendRes] = await Promise.all([
      supabase
        .from("inventory_items")
        .select("id", { count: "exact", head: true })
        .eq("owner_profile_id", currentUser.id),
      supabase
        .from("inquiries")
        .select("id", { count: "exact", head: true })
        .eq("owner_profile_id", currentUser.id),
      supabase
        .from("projects")
        .select("id", { count: "exact", head: true })
        .eq("owner_profile_id", currentUser.id),
      supabase
        .from("group_invitations")
        .select("id, group_id, invited_message, created_at, status, group:groups(id, name, description), inviter:profiles!invited_by(name)")
        .eq("invited_profile_id", currentUser.id)
        .in("status", ["pending"]),
    ]);
    setCounts({
      inventory: invRes.count ?? 0,
      inquiries: inqRes.count ?? 0,
      projects: projRes.count ?? 0,
    });
    if (pendRes.data) setPendingInvites(pendRes.data as unknown as PendingInvitation[]);
  }, [supabase, currentUser]);

  useEffect(() => {
    loadCounts();
  }, [loadCounts]);

  useRealtimeTable({ table: "group_invitations", onDataChange: loadCounts });
  useRealtimeTable({ table: "group_memberships", onDataChange: loadCounts });

  async function handleAcceptInvite(inv: PendingInvitation) {
    if (!currentUser) return;
    // Status auf accepted_by_user setzen (Trigger startet Voting oder aktiviert direkt)
    const { error } = await supabase
      .from("group_invitations")
      .update({ status: "accepted_by_user", accepted_by_user_at: new Date().toISOString() })
      .eq("id", inv.id);
    if (error) {
      showToast("Fehler: " + error.message, "error");
    } else {
      showToast(`Einladung zu "${inv.group?.name}" akzeptiert`, "success");
      await reload();
    }
  }

  async function handleDeclineInvite(inv: PendingInvitation) {
    const { error } = await supabase
      .from("group_invitations")
      .update({ status: "declined_by_user", resolved_at: new Date().toISOString() })
      .eq("id", inv.id);
    if (error) {
      showToast("Fehler: " + error.message, "error");
    } else {
      showToast(`Einladung zu "${inv.group?.name}" abgelehnt`, "info");
    }
  }

  if (!currentUser) {
    return <div className="py-12 text-center text-sm" style={{ color: "var(--color-muted-foreground)" }}>Lade...</div>;
  }

  return (
    <div className="animate-fadeIn max-w-4xl">
      {/* Header */}
      <div className="mb-6">
        <h1 className="text-2xl font-bold" style={{ color: "var(--color-foreground)" }}>
          Hallo {currentUser.name}
        </h1>
        <p className="text-sm mt-1" style={{ color: "var(--color-muted-foreground)" }}>
          {isSolo ? "Solo-Workspace" : `Gruppe: ${groupName}`}
        </p>
      </div>

      <HowItWorksBanner />

      {/* Pending Group-Invitations */}
      {pendingInvites.length > 0 && (
        <div className="space-y-3 mb-6">
          {pendingInvites.map((inv) => (
            <div
              key={inv.id}
              className="rounded-xl p-4"
              style={{
                background: "var(--color-info-light)",
                border: "1px solid var(--color-info)",
              }}
            >
              <div className="flex items-start gap-3 mb-3">
                <div
                  className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                  style={{ background: "rgba(255,255,255,0.5)" }}
                >
                  <IconUsers size={18} style={{ color: "var(--color-info)" }} />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-semibold" style={{ color: "var(--color-foreground)" }}>
                    Einladung zu Gruppe &quot;{inv.group?.name}&quot;
                  </div>
                  <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    von {inv.inviter?.name} ·{" "}
                    {new Date(inv.created_at).toLocaleDateString("de-DE")}
                  </div>
                  {inv.invited_message && (
                    <p
                      className="text-xs italic mt-2"
                      style={{ color: "var(--color-muted-foreground)" }}
                    >
                      &quot;{inv.invited_message}&quot;
                    </p>
                  )}
                </div>
              </div>
              <div
                className="text-xs mb-3 p-2 rounded"
                style={{ background: "rgba(255,255,255,0.4)", color: "var(--color-muted-foreground)" }}
              >
                💡 Hinweis: Wenn du akzeptierst, muessen alle bestehenden Mitglieder
                einstimmig zustimmen, bevor du Mitglied wirst.
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => handleAcceptInvite(inv)}
                  className="flex items-center gap-1 px-4 py-2 rounded-lg text-sm font-medium text-white"
                  style={{ background: "var(--color-success)" }}
                >
                  <IconCheck size={14} /> Akzeptieren
                </button>
                <button
                  onClick={() => handleDeclineInvite(inv)}
                  className="flex items-center gap-1 px-4 py-2 rounded-lg text-sm font-medium"
                  style={{
                    border: "1px solid var(--color-destructive)",
                    color: "var(--color-destructive)",
                  }}
                >
                  <IconX size={14} /> Ablehnen
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Solo-KPIs */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
        <Link
          href="/inventory"
          className="rounded-xl p-5 transition-all hover:shadow-md"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
        >
          <div className="flex items-start justify-between mb-2">
            <div
              className="w-10 h-10 rounded-lg flex items-center justify-center"
              style={{ background: "var(--color-warning-light)" }}
            >
              <IconPackage size={20} style={{ color: "var(--color-warning)" }} />
            </div>
            <IconChevronRight size={16} style={{ color: "var(--color-muted-foreground)" }} />
          </div>
          <div className="text-2xl font-bold mt-2" style={{ color: "var(--color-foreground)" }}>
            {counts.inventory}
          </div>
          <div className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            Inventar-Artikel
          </div>
        </Link>

        <Link
          href="/inquiries"
          className="rounded-xl p-5 transition-all hover:shadow-md"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
        >
          <div className="flex items-start justify-between mb-2">
            <div
              className="w-10 h-10 rounded-lg flex items-center justify-center"
              style={{ background: "var(--color-info-light)" }}
            >
              <IconInbox size={20} style={{ color: "var(--color-info)" }} />
            </div>
            <IconChevronRight size={16} style={{ color: "var(--color-muted-foreground)" }} />
          </div>
          <div className="text-2xl font-bold mt-2" style={{ color: "var(--color-foreground)" }}>
            {counts.inquiries}
          </div>
          <div className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            Anfragen
          </div>
        </Link>

        <Link
          href="/projects"
          className="rounded-xl p-5 transition-all hover:shadow-md"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
        >
          <div className="flex items-start justify-between mb-2">
            <div
              className="w-10 h-10 rounded-lg flex items-center justify-center"
              style={{ background: "var(--color-primary-light)" }}
            >
              <IconProjects size={20} style={{ color: "var(--color-primary)" }} />
            </div>
            <IconChevronRight size={16} style={{ color: "var(--color-muted-foreground)" }} />
          </div>
          <div className="text-2xl font-bold mt-2" style={{ color: "var(--color-foreground)" }}>
            {counts.projects}
          </div>
          <div className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            Projekte
          </div>
        </Link>
      </div>

      {/* Group-Bereich */}
      <div
        className="rounded-xl p-6"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
      >
        <div className="flex items-center gap-3 mb-4">
          <div
            className="w-10 h-10 rounded-lg flex items-center justify-center"
            style={{ background: "var(--color-success-light)" }}
          >
            <IconShield size={20} style={{ color: "var(--color-success)" }} />
          </div>
          <div>
            <h2 className="text-lg font-semibold" style={{ color: "var(--color-foreground)" }}>
              Gruppen
            </h2>
            <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              Kollektive fuer gemeinsame Projekte mit transparentem Gewinn
            </p>
          </div>
        </div>

        {groups.length === 0 ? (
          <p className="text-sm mb-4" style={{ color: "var(--color-muted-foreground)" }}>
            Du bist noch in keiner Gruppe. Gruende eine eigene oder warte auf eine Einladung.
          </p>
        ) : (
          <div className="space-y-2 mb-4">
            {groups.map((g) => (
              <Link
                key={g.id}
                href={`/groups/${g.id}`}
                className="flex items-center justify-between p-3 rounded-lg transition-colors hover:opacity-80"
                style={{ background: "var(--color-muted)" }}
              >
                <div>
                  <div className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>
                    {g.name}{" "}
                    {g.isFounder && (
                      <span className="text-xs" style={{ color: "var(--color-primary)" }}>
                        (Founder)
                      </span>
                    )}
                  </div>
                  <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    {g.isActive ? "Aktiv" : "Wartet auf Bestaetigung"}
                  </div>
                </div>
                <IconChevronRight size={16} style={{ color: "var(--color-muted-foreground)" }} />
              </Link>
            ))}
          </div>
        )}

        <Link
          href="/groups/new"
          className="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium text-white"
          style={{ background: "var(--color-success)" }}
        >
          <IconPlus size={14} /> Gruppe gruenden
        </Link>
      </div>
    </div>
  );
}
