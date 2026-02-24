"use client";

import { useState, useEffect, useCallback } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import type { Project, InventoryItem, CostItem, Booking } from "@/types/database";
import {
  IconProjects,
  IconCosts,
  IconPackage,
  IconActivity,
  IconChevronRight,
  IconTrendingUp,
} from "@/components/ui/icons";

const statusLabels: Record<Project["status"], string> = {
  draft: "Entwurf",
  planning: "Planung",
  active: "Aktiv",
  completed: "Abgeschlossen",
  cancelled: "Abgebrochen",
};

const statusDots: Record<Project["status"], string> = {
  draft: "bg-gray-400",
  planning: "bg-blue-500",
  active: "bg-emerald-500",
  completed: "bg-gray-300",
  cancelled: "bg-red-400",
};

export default function DashboardPage() {
  const supabase = createClient();
  const router = useRouter();
  const [projects, setProjects] = useState<Project[]>([]);
  const [inventory, setInventory] = useState<InventoryItem[]>([]);
  const [costs, setCosts] = useState<CostItem[]>([]);
  const [bookings, setBookings] = useState<Booking[]>([]);
  const [loading, setLoading] = useState(true);

  const loadData = useCallback(async () => {
    const [projectsRes, inventoryRes, costsRes, bookingsRes] =
      await Promise.all([
        supabase
          .from("projects")
          .select("*")
          .order("date_start", { ascending: false }),
        supabase.from("inventory_items").select("*"),
        supabase.from("cost_items").select("*"),
        supabase.from("bookings").select("*").neq("status", "returned"),
      ]);

    if (projectsRes.data) setProjects(projectsRes.data as Project[]);
    if (inventoryRes.data) setInventory(inventoryRes.data as InventoryItem[]);
    if (costsRes.data) setCosts(costsRes.data as CostItem[]);
    if (bookingsRes.data) setBookings(bookingsRes.data as Booking[]);
    setLoading(false);
  }, [supabase]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  if (loading) {
    return (
      <div className="space-y-6">
        <div className="h-8 w-48 skeleton" />
        <div className="grid grid-cols-4 gap-5">
          {[1, 2, 3, 4].map((i) => (
            <div key={i} className="h-32 skeleton rounded-xl" />
          ))}
        </div>
        <div className="h-64 skeleton rounded-xl" />
      </div>
    );
  }

  const activeProjects = projects.filter(
    (p) => p.status === "active" || p.status === "planning"
  );
  const totalPlanned = costs.reduce(
    (sum, c) => sum + Number(c.amount_planned),
    0
  );
  const totalActual = costs.reduce(
    (sum, c) => sum + (c.amount_actual !== null ? Number(c.amount_actual) : 0),
    0
  );
  const activeBookings = bookings.filter((b) => b.status !== "returned");
  const totalInventory = inventory.reduce((sum, i) => sum + i.quantity, 0);

  // Nächste anstehende Projekte (planning/active, sortiert nach Startdatum)
  const upcomingProjects = projects
    .filter((p) => p.status === "planning" || p.status === "active")
    .sort((a, b) => {
      if (!a.date_start) return 1;
      if (!b.date_start) return -1;
      return new Date(a.date_start).getTime() - new Date(b.date_start).getTime();
    })
    .slice(0, 5);

  // Letzte abgeschlossene Projekte
  const recentCompleted = projects
    .filter((p) => p.status === "completed")
    .slice(0, 5);

  return (
    <div className="animate-fadeIn">
      {/* Header */}
      <div className="mb-8">
        <h1 className="text-2xl font-bold">Dashboard</h1>
        <p className="text-sm mt-1" style={{ color: "var(--color-muted-foreground)" }}>
          Willkommen bei Dunkelstrom Projektplanner
        </p>
      </div>

      {/* KPI Cards */}
      <div className="grid grid-cols-4 gap-5 mb-8">
        {/* Aktive Projekte */}
        <div
          className="p-5 rounded-xl"
          style={{
            background: "var(--color-surface)",
            boxShadow: "var(--shadow-sm)",
            border: "1px solid var(--color-border-light)",
          }}
        >
          <div className="flex items-center justify-between mb-3">
            <span className="text-sm font-medium" style={{ color: "var(--color-muted-foreground)" }}>
              Aktive Projekte
            </span>
            <div
              className="w-9 h-9 rounded-lg flex items-center justify-center"
              style={{ background: "var(--color-primary-light)", color: "var(--color-primary)" }}
            >
              <IconProjects size={18} />
            </div>
          </div>
          <div className="text-3xl font-bold">{activeProjects.length}</div>
          <div className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
            von {projects.length} gesamt
          </div>
        </div>

        {/* Budget */}
        <div
          className="p-5 rounded-xl"
          style={{
            background: "var(--color-surface)",
            boxShadow: "var(--shadow-sm)",
            border: "1px solid var(--color-border-light)",
          }}
        >
          <div className="flex items-center justify-between mb-3">
            <span className="text-sm font-medium" style={{ color: "var(--color-muted-foreground)" }}>
              Budget (Soll)
            </span>
            <div
              className="w-9 h-9 rounded-lg flex items-center justify-center"
              style={{ background: "var(--color-success-light)", color: "var(--color-success)" }}
            >
              <IconCosts size={18} />
            </div>
          </div>
          <div className="text-3xl font-bold">
            {totalPlanned.toLocaleString("de-DE")} &euro;
          </div>
          <div className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
            Ist: {totalActual.toLocaleString("de-DE")} &euro;
          </div>
        </div>

        {/* Inventar */}
        <div
          className="p-5 rounded-xl"
          style={{
            background: "var(--color-surface)",
            boxShadow: "var(--shadow-sm)",
            border: "1px solid var(--color-border-light)",
          }}
        >
          <div className="flex items-center justify-between mb-3">
            <span className="text-sm font-medium" style={{ color: "var(--color-muted-foreground)" }}>
              Inventar
            </span>
            <div
              className="w-9 h-9 rounded-lg flex items-center justify-center"
              style={{ background: "var(--color-warning-light)", color: "var(--color-warning)" }}
            >
              <IconPackage size={18} />
            </div>
          </div>
          <div className="text-3xl font-bold">{inventory.length}</div>
          <div className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
            {totalInventory} Teile gesamt
          </div>
        </div>

        {/* Buchungen */}
        <div
          className="p-5 rounded-xl"
          style={{
            background: "var(--color-surface)",
            boxShadow: "var(--shadow-sm)",
            border: "1px solid var(--color-border-light)",
          }}
        >
          <div className="flex items-center justify-between mb-3">
            <span className="text-sm font-medium" style={{ color: "var(--color-muted-foreground)" }}>
              Aktive Buchungen
            </span>
            <div
              className="w-9 h-9 rounded-lg flex items-center justify-center"
              style={{ background: "var(--color-info-light)", color: "var(--color-info)" }}
            >
              <IconActivity size={18} />
            </div>
          </div>
          <div className="text-3xl font-bold">{activeBookings.length}</div>
          <div className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
            Equipment unterwegs
          </div>
        </div>
      </div>

      {/* Budget-Warnung */}
      {totalActual > totalPlanned && totalPlanned > 0 && (
        <div
          className="p-4 rounded-xl mb-8 flex items-center gap-3"
          style={{
            background: "var(--color-warning-light)",
            border: "1px solid var(--color-warning)",
          }}
        >
          <IconTrendingUp size={20} style={{ color: "var(--color-warning)" }} />
          <div>
            <div className="font-medium text-sm" style={{ color: "var(--color-warning)" }}>
              Budget-Warnung
            </div>
            <div className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              Ist-Kosten ({totalActual.toLocaleString("de-DE")} &euro;) übersteigen Soll (
              {totalPlanned.toLocaleString("de-DE")} &euro;) um{" "}
              {(totalActual - totalPlanned).toLocaleString("de-DE")} &euro;
            </div>
          </div>
        </div>
      )}

      {/* Two-Column Layout */}
      <div className="grid grid-cols-2 gap-6">
        {/* Anstehende Projekte */}
        <div
          className="rounded-xl overflow-hidden"
          style={{
            background: "var(--color-surface)",
            boxShadow: "var(--shadow-sm)",
            border: "1px solid var(--color-border-light)",
          }}
        >
          <div className="flex items-center justify-between px-5 py-4 border-b"
            style={{ borderColor: "var(--color-border-light)" }}
          >
            <h2 className="font-semibold">Anstehende Events</h2>
            <button
              onClick={() => router.push("/projects")}
              className="text-xs font-medium flex items-center gap-1 transition-colors"
              style={{ color: "var(--color-primary)" }}
            >
              Alle anzeigen <IconChevronRight size={14} />
            </button>
          </div>

          {upcomingProjects.length === 0 ? (
            <div className="px-5 py-8 text-center text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              Keine anstehenden Projekte
            </div>
          ) : (
            <div>
              {upcomingProjects.map((project) => (
                <div
                  key={project.id}
                  onClick={() => router.push(`/projects/${project.id}`)}
                  className="flex items-center gap-3 px-5 py-3 cursor-pointer transition-colors"
                  style={{ borderBottom: "1px solid var(--color-border-light)" }}
                  onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-surface-hover)"}
                  onMouseLeave={(e) => e.currentTarget.style.background = "transparent"}
                >
                  <div className={`w-2 h-2 rounded-full ${statusDots[project.status]}`} />
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-medium truncate">
                      {project.name}
                    </div>
                    {project.date_start && (
                      <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                        {new Date(project.date_start).toLocaleDateString("de-DE", {
                          day: "numeric",
                          month: "short",
                          year: "numeric",
                        })}
                        {project.date_end && project.date_end !== project.date_start &&
                          ` – ${new Date(project.date_end).toLocaleDateString("de-DE", {
                            day: "numeric",
                            month: "short",
                          })}`}
                      </div>
                    )}
                  </div>
                  <span
                    className="text-[11px] px-2 py-0.5 rounded-full font-medium"
                    style={{
                      background: project.status === "active" ? "var(--color-success-light)" : "var(--color-info-light)",
                      color: project.status === "active" ? "var(--color-success)" : "var(--color-info)",
                    }}
                  >
                    {statusLabels[project.status]}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Letzte Projekte */}
        <div
          className="rounded-xl overflow-hidden"
          style={{
            background: "var(--color-surface)",
            boxShadow: "var(--shadow-sm)",
            border: "1px solid var(--color-border-light)",
          }}
        >
          <div className="flex items-center justify-between px-5 py-4 border-b"
            style={{ borderColor: "var(--color-border-light)" }}
          >
            <h2 className="font-semibold">Zuletzt abgeschlossen</h2>
            <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
              {projects.filter((p) => p.status === "completed").length} Events
            </span>
          </div>

          {recentCompleted.length === 0 ? (
            <div className="px-5 py-8 text-center text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              Noch keine abgeschlossenen Projekte
            </div>
          ) : (
            <div>
              {recentCompleted.map((project) => (
                <div
                  key={project.id}
                  onClick={() => router.push(`/projects/${project.id}`)}
                  className="flex items-center gap-3 px-5 py-3 cursor-pointer transition-colors"
                  style={{ borderBottom: "1px solid var(--color-border-light)" }}
                  onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-surface-hover)"}
                  onMouseLeave={(e) => e.currentTarget.style.background = "transparent"}
                >
                  <div className={`w-2 h-2 rounded-full ${statusDots[project.status]}`} />
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-medium truncate">{project.name}</div>
                    {project.date_start && (
                      <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                        {new Date(project.date_start).toLocaleDateString("de-DE", {
                          day: "numeric",
                          month: "short",
                          year: "numeric",
                        })}
                      </div>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
