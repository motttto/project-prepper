"use client";

import { useState, useEffect, useCallback } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { useCurrentUser, isOrgAdmin } from "@/hooks/use-current-user";
import { useOrg } from "@/contexts/org-context";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type { ActivityLogEntry, ActivityAction } from "@/types/database";

const PAGE_SIZE = 50;

const actionLabels: Record<string, string> = {
  "member.registered": "hat sich registriert",
  "member.approved": "wurde freigeschaltet",
  "member.role_changed": "Rolle geändert",
  "member.deactivated": "wurde deaktiviert",
  "member.reactivated": "wurde reaktiviert",
  "project.created": "Projekt erstellt",
  "project.updated": "Projekt aktualisiert",
  "project.status_changed": "Projektstatus geändert",
  "inventory.created": "Inventar hinzugefügt",
  "inventory.updated": "Inventar aktualisiert",
  "inventory.deleted": "Inventar gelöscht",
  "decision.created": "Beschluss erstellt",
  "decision.voted": "hat abgestimmt",
  "decision.resolved": "Beschluss abgeschlossen",
  "guest.added": "Gast hinzugefügt",
  "guest.removed": "Gast entfernt",
  "invitation.sent": "Einladung gesendet",
  "invitation.accepted": "Einladung angenommen",
  "booking.created": "Buchung erstellt",
  "booking.deleted": "Buchung gelöscht",
};

const actionIcons: Record<string, string> = {
  "member.registered": "👤",
  "member.approved": "✅",
  "member.role_changed": "🔄",
  "member.deactivated": "⛔",
  "member.reactivated": "♻️",
  "project.created": "📁",
  "project.updated": "✏️",
  "project.status_changed": "📊",
  "inventory.created": "📦",
  "inventory.updated": "📦",
  "inventory.deleted": "🗑️",
  "decision.created": "📋",
  "decision.voted": "🗳️",
  "decision.resolved": "⚖️",
  "guest.added": "🎟️",
  "guest.removed": "🎟️",
  "invitation.sent": "📨",
  "invitation.accepted": "📬",
  "booking.created": "📅",
  "booking.deleted": "📅",
};

// Gruppen für den Filter
const actionGroups: { label: string; actions: string[] }[] = [
  { label: "Mitglieder", actions: ["member.registered", "member.approved", "member.role_changed", "member.deactivated", "member.reactivated"] },
  { label: "Projekte", actions: ["project.created", "project.updated", "project.status_changed"] },
  { label: "Inventar", actions: ["inventory.created", "inventory.updated", "inventory.deleted"] },
  { label: "Beschlüsse", actions: ["decision.created", "decision.voted", "decision.resolved"] },
  { label: "Gäste", actions: ["guest.added", "guest.removed"] },
  { label: "Einladungen", actions: ["invitation.sent", "invitation.accepted"] },
  { label: "Buchungen", actions: ["booking.created", "booking.deleted"] },
];

function formatTime(dateStr: string) {
  const d = new Date(dateStr);
  const now = new Date();
  const diffMs = now.getTime() - d.getTime();
  const diffMin = Math.floor(diffMs / 60000);
  const diffH = Math.floor(diffMs / 3600000);
  const diffD = Math.floor(diffMs / 86400000);

  if (diffMin < 1) return "Gerade eben";
  if (diffMin < 60) return `vor ${diffMin} Min.`;
  if (diffH < 24) return `vor ${diffH} Std.`;
  if (diffD < 7) return `vor ${diffD} Tag${diffD > 1 ? "en" : ""}`;
  return d.toLocaleDateString("de-DE", { day: "2-digit", month: "2-digit", year: "numeric" })
    + " " + d.toLocaleTimeString("de-DE", { hour: "2-digit", minute: "2-digit" });
}

export default function ActivityLogPage() {
  const supabase = createClient();
  const router = useRouter();
  const currentUser = useCurrentUser();
  const { orgId } = useOrg();

  const [entries, setEntries] = useState<ActivityLogEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [hasMore, setHasMore] = useState(false);
  const [filterAction, setFilterAction] = useState<string>("all");

  const isAdmin = isOrgAdmin(currentUser);

  const loadEntries = useCallback(async (append = false) => {
    if (!orgId) return;
    setLoading(true);

    let query = supabase
      .from("org_activity_log")
      .select("*, actor:profiles!actor_id(name, avatar_url)")
      .eq("org_id", orgId)
      .order("created_at", { ascending: false })
      .limit(PAGE_SIZE + 1);

    if (filterAction !== "all") {
      // Prüfe ob es eine Gruppe ist
      const group = actionGroups.find(g => g.label === filterAction);
      if (group) {
        query = query.in("action", group.actions);
      } else {
        query = query.eq("action", filterAction);
      }
    }

    if (append && entries.length > 0) {
      query = query.lt("created_at", entries[entries.length - 1].created_at);
    }

    const { data } = await query;
    if (data) {
      const hasNext = data.length > PAGE_SIZE;
      const sliced = data.slice(0, PAGE_SIZE) as ActivityLogEntry[];
      setEntries(prev => append ? [...prev, ...sliced] : sliced);
      setHasMore(hasNext);
    }
    setLoading(false);
  }, [supabase, orgId, filterAction, entries]);

  useEffect(() => {
    if (orgId && isAdmin !== undefined) {
      if (isAdmin) loadEntries();
    }
  }, [orgId, isAdmin, filterAction]);

  useRealtimeTable({
    table: "org_activity_log",
    orgFilter: orgId || undefined,
    onDataChange: () => loadEntries(),
    enabled: !!orgId && !!isAdmin,
  });

  // Redirect nicht-Admins
  useEffect(() => {
    if (currentUser && !isAdmin) {
      router.push("/dashboard");
    }
  }, [currentUser, isAdmin, router]);

  if (!isAdmin) {
    return <div className="py-8 text-center" style={{ color: "var(--color-muted-foreground)" }}>Kein Zugriff.</div>;
  }

  return (
    <div className="max-w-4xl mx-auto space-y-6">
      {/* Header */}
      <div>
        <div className="flex items-center gap-3 mb-1">
          <span className="text-2xl">📋</span>
          <h1 className="text-2xl font-bold">Aktivitätsprotokoll</h1>
        </div>
        <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          Alle Aktionen in der Organisation. Nur für Admins sichtbar.
        </p>
      </div>

      {/* Filter */}
      <div className="flex flex-wrap gap-2">
        <button
          onClick={() => setFilterAction("all")}
          className="px-3 py-1.5 text-sm rounded-lg font-medium transition-colors"
          style={{
            background: filterAction === "all" ? "var(--color-primary)" : "var(--color-muted)",
            color: filterAction === "all" ? "var(--color-primary-foreground)" : "var(--color-muted-foreground)",
          }}
        >
          Alle
        </button>
        {actionGroups.map(g => (
          <button
            key={g.label}
            onClick={() => setFilterAction(g.label)}
            className="px-3 py-1.5 text-sm rounded-lg font-medium transition-colors"
            style={{
              background: filterAction === g.label ? "var(--color-primary)" : "var(--color-muted)",
              color: filterAction === g.label ? "var(--color-primary-foreground)" : "var(--color-muted-foreground)",
            }}
          >
            {g.label}
          </button>
        ))}
      </div>

      {/* Entries */}
      {loading && entries.length === 0 ? (
        <div className="py-12 text-center" style={{ color: "var(--color-muted-foreground)" }}>
          Wird geladen...
        </div>
      ) : entries.length === 0 ? (
        <div className="py-12 text-center rounded-xl"
          style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}>
          <p className="text-lg mb-1">Noch keine Aktivitäten</p>
          <p className="text-sm">Aktionen werden automatisch protokolliert.</p>
        </div>
      ) : (
        <div className="space-y-1">
          {entries.map((entry, idx) => {
            const prevEntry = idx > 0 ? entries[idx - 1] : null;
            const entryDate = new Date(entry.created_at).toLocaleDateString("de-DE", {
              weekday: "long", day: "2-digit", month: "long", year: "numeric"
            });
            const prevDate = prevEntry
              ? new Date(prevEntry.created_at).toLocaleDateString("de-DE", {
                  weekday: "long", day: "2-digit", month: "long", year: "numeric"
                })
              : null;
            const showDateHeader = entryDate !== prevDate;

            const actor = entry.actor as { name: string; avatar_url: string | null } | null;
            const icon = actionIcons[entry.action] || "📌";
            const label = actionLabels[entry.action] || entry.action;
            const meta = entry.metadata as Record<string, string>;

            return (
              <div key={entry.id}>
                {showDateHeader && (
                  <div className="pt-4 pb-2 first:pt-0">
                    <p className="text-xs font-semibold uppercase tracking-wide"
                      style={{ color: "var(--color-muted-foreground)" }}>
                      {entryDate}
                    </p>
                  </div>
                )}
                <div className="flex items-start gap-3 py-2.5 px-3 rounded-lg transition-colors hover:opacity-90"
                  style={{ background: "var(--color-surface)" }}>
                  {/* Icon */}
                  <span className="text-base mt-0.5 shrink-0">{icon}</span>

                  {/* Content */}
                  <div className="flex-1 min-w-0">
                    <p className="text-sm">
                      <span className="font-medium">{actor?.name || "System"}</span>
                      {" "}
                      <span style={{ color: "var(--color-muted-foreground)" }}>{label}</span>
                      {entry.entity_label && (
                        <>
                          {": "}
                          <span className="font-medium">{entry.entity_label}</span>
                        </>
                      )}
                    </p>
                    {/* Metadata */}
                    {meta && Object.keys(meta).length > 0 && (
                      <div className="flex flex-wrap gap-2 mt-1">
                        {meta.old_role && meta.new_role && (
                          <span className="text-xs px-2 py-0.5 rounded"
                            style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
                            {meta.old_role} → {meta.new_role}
                          </span>
                        )}
                        {meta.status && (
                          <span className="text-xs px-2 py-0.5 rounded"
                            style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
                            Status: {meta.status}
                          </span>
                        )}
                        {meta.vote && (
                          <span className="text-xs px-2 py-0.5 rounded"
                            style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
                            Stimme: {meta.vote === "approve" ? "✅ Ja" : "❌ Nein"}
                          </span>
                        )}
                      </div>
                    )}
                  </div>

                  {/* Timestamp */}
                  <span className="text-xs shrink-0 mt-0.5"
                    style={{ color: "var(--color-muted-foreground)" }}>
                    {formatTime(entry.created_at)}
                  </span>
                </div>
              </div>
            );
          })}

          {/* Load more */}
          {hasMore && (
            <div className="pt-4 text-center">
              <button
                onClick={() => loadEntries(true)}
                disabled={loading}
                className="px-4 py-2 text-sm rounded-lg font-medium"
                style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}
              >
                {loading ? "Laden..." : "Mehr anzeigen"}
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
