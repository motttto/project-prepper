"use client";

// Group-Kostenübersicht
// =====================
// Solo: Hinweis "Kosten findest du im Projekt"
// Group: Aggregierte Sicht über alle Projekte der Gruppe + Cost-Items

import { useState, useEffect, useCallback, useMemo } from "react";
import Link from "next/link";
import { createClient } from "@/lib/supabase";
import { useWorkspace } from "@/contexts/org-context";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type { CostItem, Project } from "@/types/database";
import { IconCosts, IconChevronRight, IconFilter } from "@/components/ui/icons";

const categoryLabels: Record<string, string> = {
  personnel: "Personal",
  material: "Material",
  inventory: "Inventar",
  external: "Extern",
  other: "Sonstiges",
};

type CostWithProject = CostItem & { project_name?: string };

export default function CostsPage() {
  const supabase = createClient();
  const { isSolo, groupId, groupName } = useWorkspace();
  const currentUser = useCurrentUser();

  const [projects, setProjects] = useState<Project[]>([]);
  const [costs, setCosts] = useState<CostWithProject[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterCategory, setFilterCategory] = useState<string>("all");

  const loadData = useCallback(async () => {
    if (!currentUser) return;
    if (isSolo) {
      // Solo: nur eigene Projekte + Kosten
      const { data: projData } = await supabase
        .from("projects")
        .select("id, name")
        .eq("owner_profile_id", currentUser.id);
      const ids = (projData || []).map((p) => p.id);
      if (ids.length === 0) {
        setProjects([]);
        setCosts([]);
      } else {
        setProjects(projData as Project[]);
        const { data: costData } = await supabase
          .from("cost_items")
          .select("*, projects(name)")
          .in("project_id", ids)
          .order("created_at", { ascending: false });
        setCosts(
          (costData || []).map((c) => ({
            ...(c as CostItem),
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            project_name: (c as any).projects?.name,
          }))
        );
      }
    } else if (groupId) {
      // Group: alle Projekte der Gruppe
      const { data: projData } = await supabase
        .from("projects")
        .select("id, name")
        .eq("group_id", groupId);
      const ids = (projData || []).map((p) => p.id);
      setProjects(projData as Project[]);
      if (ids.length > 0) {
        const { data: costData } = await supabase
          .from("cost_items")
          .select("*, projects(name)")
          .in("project_id", ids)
          .order("created_at", { ascending: false });
        setCosts(
          (costData || []).map((c) => ({
            ...(c as CostItem),
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            project_name: (c as any).projects?.name,
          }))
        );
      } else {
        setCosts([]);
      }
    }
    setLoading(false);
  }, [supabase, currentUser, isSolo, groupId]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  useRealtimeTable({ table: "cost_items", onDataChange: loadData });

  const filtered = useMemo(() => {
    if (filterCategory === "all") return costs;
    return costs.filter((c) => c.category === filterCategory);
  }, [costs, filterCategory]);

  const summary = useMemo(() => {
    let planned = 0;
    let actual = 0;
    for (const c of filtered) {
      planned += Number(c.amount_planned || 0);
      actual += Number(c.amount_actual || 0);
    }
    return { planned, actual };
  }, [filtered]);

  if (isSolo) {
    return (
      <div className="max-w-2xl mx-auto px-4 py-12 text-center">
        <IconCosts size={40} className="mx-auto mb-3" style={{ color: "var(--color-muted-foreground)", opacity: 0.5 }} />
        <h1 className="text-xl font-semibold mb-2" style={{ color: "var(--color-foreground)" }}>
          Solo-Kostenübersicht
        </h1>
        <p className="text-sm mb-4" style={{ color: "var(--color-muted-foreground)" }}>
          Im Solo-Modus siehst du Kosten direkt im jeweiligen Projekt.
        </p>
        <Link
          href="/projects"
          className="inline-flex items-center gap-1 text-sm font-medium"
          style={{ color: "var(--color-primary)" }}
        >
          Zu meinen Projekten <IconChevronRight size={14} />
        </Link>
      </div>
    );
  }

  if (loading) {
    return <div className="py-8 text-center text-sm" style={{ color: "var(--color-muted-foreground)" }}>Lade...</div>;
  }

  return (
    <div className="max-w-5xl">
      <div className="mb-6">
        <h1 className="text-2xl font-bold" style={{ color: "var(--color-foreground)" }}>Kosten</h1>
        <p className="text-sm mt-1" style={{ color: "var(--color-muted-foreground)" }}>
          Aggregiert über alle Projekte von &quot;{groupName}&quot;
        </p>
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-3 gap-4 mb-6">
        <div className="rounded-xl p-4" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}>
          <div className="text-xs mb-1" style={{ color: "var(--color-muted-foreground)" }}>Projekte</div>
          <div className="text-2xl font-bold" style={{ color: "var(--color-foreground)" }}>{projects.length}</div>
        </div>
        <div className="rounded-xl p-4" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}>
          <div className="text-xs mb-1" style={{ color: "var(--color-muted-foreground)" }}>Plan-Kosten</div>
          <div className="text-2xl font-bold" style={{ color: "var(--color-foreground)" }}>
            {summary.planned.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
          </div>
        </div>
        <div className="rounded-xl p-4" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}>
          <div className="text-xs mb-1" style={{ color: "var(--color-muted-foreground)" }}>Ist-Kosten</div>
          <div className="text-2xl font-bold" style={{ color: summary.actual > summary.planned ? "var(--color-destructive)" : "var(--color-foreground)" }}>
            {summary.actual.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
          </div>
        </div>
      </div>

      {/* Filter */}
      <div className="flex items-center gap-2 mb-4 flex-wrap">
        <IconFilter size={14} style={{ color: "var(--color-muted-foreground)" }} />
        {(["all", ...Object.keys(categoryLabels)] as string[]).map((cat) => (
          <button
            key={cat}
            onClick={() => setFilterCategory(cat)}
            className="text-xs px-3 py-1 rounded-full font-medium"
            style={{
              background: filterCategory === cat ? "var(--color-primary)" : "var(--color-muted)",
              color: filterCategory === cat ? "white" : "var(--color-muted-foreground)",
            }}
          >
            {cat === "all" ? "Alle" : categoryLabels[cat]}
          </button>
        ))}
      </div>

      {/* Liste */}
      {filtered.length === 0 ? (
        <div className="rounded-xl p-12 text-center" style={{ border: "2px dashed var(--color-border)" }}>
          <IconCosts size={32} className="mx-auto mb-2" style={{ color: "var(--color-muted-foreground)", opacity: 0.5 }} />
          <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            Keine Kostenpositionen.
          </p>
        </div>
      ) : (
        <div className="rounded-xl overflow-hidden" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}>
          <table className="w-full">
            <thead>
              <tr style={{ background: "var(--color-muted)" }}>
                <th className="text-left px-4 py-2 text-xs font-medium" style={{ color: "var(--color-muted-foreground)" }}>
                  Projekt
                </th>
                <th className="text-left px-4 py-2 text-xs font-medium" style={{ color: "var(--color-muted-foreground)" }}>
                  Kategorie
                </th>
                <th className="text-left px-4 py-2 text-xs font-medium" style={{ color: "var(--color-muted-foreground)" }}>
                  Beschreibung
                </th>
                <th className="text-right px-4 py-2 text-xs font-medium" style={{ color: "var(--color-muted-foreground)" }}>
                  Plan
                </th>
                <th className="text-right px-4 py-2 text-xs font-medium" style={{ color: "var(--color-muted-foreground)" }}>
                  Ist
                </th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((c) => (
                <tr key={c.id} style={{ borderTop: "1px solid var(--color-border-light)" }}>
                  <td className="px-4 py-2 text-sm">
                    <Link
                      href={`/projects/${c.project_id}?tab=costs`}
                      className="hover:underline"
                      style={{ color: "var(--color-primary)" }}
                    >
                      {c.project_name || "?"}
                    </Link>
                  </td>
                  <td className="px-4 py-2 text-xs">
                    <span className="px-2 py-0.5 rounded-full" style={{ background: "var(--color-muted)" }}>
                      {categoryLabels[c.category] || c.category}
                    </span>
                  </td>
                  <td className="px-4 py-2 text-sm" style={{ color: "var(--color-foreground)" }}>
                    {c.description || "–"}
                  </td>
                  <td className="px-4 py-2 text-sm text-right" style={{ color: "var(--color-muted-foreground)" }}>
                    {Number(c.amount_planned || 0).toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                  </td>
                  <td className="px-4 py-2 text-sm text-right font-medium" style={{ color: "var(--color-foreground)" }}>
                    {c.amount_actual != null
                      ? Number(c.amount_actual).toLocaleString("de-DE", { style: "currency", currency: "EUR" })
                      : "–"}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
