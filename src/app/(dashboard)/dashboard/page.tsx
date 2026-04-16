"use client";

import { useEffect, useState, useCallback } from "react";
import Link from "next/link";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useWorkspace } from "@/contexts/org-context";
import {
  IconProjects,
  IconPackage,
  IconInbox,
  IconUsers,
  IconShield,
  IconChevronRight,
  IconPlus,
} from "@/components/ui/icons";
import { HowItWorksBanner } from "@/components/dashboard/how-it-works-banner";

export default function DashboardPage() {
  const supabase = createClient();
  const currentUser = useCurrentUser();
  const { isSolo, groupName, groups } = useWorkspace();
  const [counts, setCounts] = useState({ inventory: 0, inquiries: 0, projects: 0 });
  const [pendingInvites, setPendingInvites] = useState(0);

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
        .select("id", { count: "exact", head: true })
        .eq("invited_profile_id", currentUser.id)
        .eq("status", "pending"),
    ]);
    setCounts({
      inventory: invRes.count ?? 0,
      inquiries: inqRes.count ?? 0,
      projects: projRes.count ?? 0,
    });
    setPendingInvites(pendRes.count ?? 0);
  }, [supabase, currentUser]);

  useEffect(() => {
    loadCounts();
  }, [loadCounts]);

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

      {/* Pending Group-Invitations Banner */}
      {pendingInvites > 0 && (
        <div
          className="rounded-xl p-4 mb-6 flex items-center gap-3"
          style={{
            background: "var(--color-info-light)",
            border: "1px solid var(--color-info)",
          }}
        >
          <IconUsers size={20} style={{ color: "var(--color-info)" }} />
          <div className="flex-1">
            <div className="text-sm font-medium" style={{ color: "var(--color-info)" }}>
              Du hast {pendingInvites} offene Gruppen-Einladung{pendingInvites !== 1 ? "en" : ""}
            </div>
            <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
              Pruefe deine Einladungen und entscheide ob du beitreten moechtest.
            </div>
          </div>
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
              <div
                key={g.id}
                className="flex items-center justify-between p-3 rounded-lg"
                style={{ background: "var(--color-muted)" }}
              >
                <div>
                  <div className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>
                    {g.name} {g.isFounder && <span className="text-xs" style={{ color: "var(--color-primary)" }}>(Founder)</span>}
                  </div>
                  <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    {g.isActive ? "Aktiv" : "Wartet auf Bestaetigung"}
                  </div>
                </div>
              </div>
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
