"use client";

import { useState, useEffect, useCallback } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useOrg } from "@/contexts/org-context";
import type { Project, InventoryItem, CostItem, Booking, ProjectTask, Inquiry } from "@/types/database";
import {
  IconProjects,
  IconCosts,
  IconPackage,
  IconActivity,
  IconChevronRight,
  IconTrendingUp,
  IconClipboardCheck,
  IconClock,
  IconInbox,
} from "@/components/ui/icons";
import { DashboardCard } from "@/components/dashboard/dashboard-card";

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
  const currentUser = useCurrentUser();
  const { orgId } = useOrg();
  const [projects, setProjects] = useState<Project[]>([]);
  const [inventory, setInventory] = useState<InventoryItem[]>([]);
  const [costs, setCosts] = useState<CostItem[]>([]);
  const [bookings, setBookings] = useState<Booking[]>([]);
  const [tasks, setTasks] = useState<ProjectTask[]>([]);
  const [inquiries, setInquiries] = useState<Inquiry[]>([]);
  const [myProjectIds, setMyProjectIds] = useState<Set<string>>(new Set());
  const [loading, setLoading] = useState(true);

  const isAdmin = currentUser?.roleName === "admin";

  const loadData = useCallback(async () => {
    if (!orgId) return;
    const [projectsRes, inventoryRes, costsRes, bookingsRes, membersRes, tasksRes, inquiriesRes] =
      await Promise.all([
        supabase
          .from("projects")
          .select("*")
          .eq("org_id", orgId)
          .order("date_start", { ascending: false }),
        supabase.from("inventory_items").select("*").eq("org_id", orgId),
        // RLS filtert automatisch: nur Kosten aus Projekten, an denen User Mitglied ist
        supabase.from("cost_items").select("*"),
        supabase.from("bookings").select("*").neq("status", "returned"),
        // Meine Projekt-Mitgliedschaften laden
        supabase.from("project_members").select("project_id"),
        // Alle offenen Aufgaben laden (todo + in_progress)
        supabase
          .from("project_tasks")
          .select("id, title, status, priority, due_date, project_id, assigned_to, assignment_status, projects(name)")
          .neq("status", "done")
          .order("due_date", { ascending: true, nullsFirst: false }),
        // Offene Anfragen laden
        supabase
          .from("inquiries")
          .select("id, status, offer_amount")
          .eq("org_id", orgId)
          .not("status", "in", '("accepted","rejected","archived")'),
      ]);

    if (projectsRes.data) setProjects(projectsRes.data as Project[]);
    if (inventoryRes.data) setInventory(inventoryRes.data as InventoryItem[]);
    if (costsRes.data) setCosts(costsRes.data as CostItem[]);
    if (bookingsRes.data) setBookings(bookingsRes.data as Booking[]);
    if (tasksRes.data) setTasks(tasksRes.data as unknown as ProjectTask[]);
    if (inquiriesRes.data) setInquiries(inquiriesRes.data as Inquiry[]);
    if (membersRes.data) {
      setMyProjectIds(new Set(membersRes.data.map((m: { project_id: string }) => m.project_id)));
    }
    setLoading(false);
  }, [supabase, orgId]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  if (loading) {
    return (
      <div className="space-y-6">
        <div className="h-8 w-48 skeleton" />
        <div className="grid grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-4 sm:gap-5">
          {[1, 2, 3, 4, 5, 6, 7].map((i) => (
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
  // cost_items sind durch RLS bereits auf "meine" Projekte gefiltert
  const totalPlanned = costs.reduce(
    (sum, c) => sum + Number(c.amount_planned),
    0
  );
  const totalActual = costs.reduce(
    (sum, c) => sum + (c.amount_actual !== null ? Number(c.amount_actual) : 0),
    0
  );
  const hasCostAccess = costs.length > 0 || isAdmin;
  const activeBookings = bookings.filter((b) => b.status !== "returned");
  const totalInventory = inventory.reduce((sum, i) => sum + i.quantity, 0);

  // Org-Projekt-IDs für Task-Filterung
  const orgProjectIds = new Set(projects.map((p) => p.id));
  const orgTasks = tasks.filter((t) => orgProjectIds.has(t.project_id));
  const openTaskCount = orgTasks.length;
  const inProgressCount = orgTasks.filter((t) => t.status === "in_progress").length;

  // Überfällige Aufgaben: due_date < heute & Status != done
  const today = new Date().toISOString().split("T")[0];
  const overdueTasks = orgTasks.filter((t) => t.due_date && t.due_date < today);
  const overdueCount = overdueTasks.length;

  // Anfragen-Statistiken
  const openInquiryCount = inquiries.length;
  const inquiriesWithOffer = inquiries.filter((i) => i.status === "offer_sent").length;

  // Nächste anstehende Projekte (planning/active, sortiert nach Startdatum)
  const upcomingProjects = projects
    .filter((p) => p.status === "planning" || p.status === "active")
    .sort((a, b) => {
      if (!a.date_start) return 1;
      if (!b.date_start) return -1;
      return new Date(a.date_start).getTime() - new Date(b.date_start).getTime();
    })
    .slice(0, 5);

  // Meine Aufgaben: mir zugewiesen, nicht erledigt, Pending zuerst
  const myTasks = orgTasks
    .filter((t) => t.assigned_to === currentUser?.id)
    .sort((a, b) => {
      // Pending acceptance zuerst
      if (a.assignment_status === "pending" && b.assignment_status !== "pending") return -1;
      if (a.assignment_status !== "pending" && b.assignment_status === "pending") return 1;
      // Dann überfällige
      const aOverdue = a.due_date && a.due_date < today;
      const bOverdue = b.due_date && b.due_date < today;
      if (aOverdue && !bOverdue) return -1;
      if (!aOverdue && bOverdue) return 1;
      // Dann nach Due Date
      if (a.due_date && b.due_date) return a.due_date.localeCompare(b.due_date);
      if (a.due_date) return -1;
      if (b.due_date) return 1;
      return 0;
    })
    .slice(0, 8);

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
          Willkommen bei Project Prepper
        </p>
      </div>

      {/* KPI Cards — 7 klickbare Kacheln */}
      <div className="grid grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-4 sm:gap-5 mb-8">
        {/* 1. Aktive Projekte */}
        <DashboardCard
          label="Aktive Projekte"
          value={activeProjects.length}
          subtext={`von ${projects.length} gesamt`}
          icon={<IconProjects size={18} />}
          iconBg="var(--color-primary-light)"
          iconColor="var(--color-primary)"
          href="/projects"
        />

        {/* 2. Budget (Soll) */}
        <DashboardCard
          label="Budget (Soll)"
          value={hasCostAccess ? `${totalPlanned.toLocaleString("de-DE")} €` : "—"}
          subtext={
            hasCostAccess
              ? `Ist: ${totalActual.toLocaleString("de-DE")} €${!isAdmin ? " · Meine Projekte" : ""}`
              : "Werde Projektmitglied"
          }
          icon={<IconCosts size={18} />}
          iconBg="var(--color-success-light)"
          iconColor="var(--color-success)"
          href={hasCostAccess ? "/costs" : undefined}
          disabled={!hasCostAccess}
        />

        {/* 3. Inventar */}
        <DashboardCard
          label="Inventar"
          value={inventory.length}
          subtext={`${totalInventory} Teile gesamt`}
          icon={<IconPackage size={18} />}
          iconBg="var(--color-warning-light)"
          iconColor="var(--color-warning)"
          href="/inventory"
        />

        {/* 4. Aktive Ausleihen */}
        <DashboardCard
          label="Aktive Ausleihen"
          value={activeBookings.length}
          subtext="Equipment ausgeliehen"
          icon={<IconActivity size={18} />}
          iconBg="var(--color-info-light)"
          iconColor="var(--color-info)"
          href="/inventory"
        />

        {/* 5. Offene Aufgaben */}
        <DashboardCard
          label="Offene Aufgaben"
          value={openTaskCount}
          subtext={`${inProgressCount} in Arbeit`}
          icon={<IconClipboardCheck size={18} />}
          iconBg="var(--color-primary-light)"
          iconColor="var(--color-primary)"
          href="/projects"
        />

        {/* 6. Überfällig */}
        <DashboardCard
          label="Überfällig"
          value={overdueCount}
          subtext="Aufgaben überfällig"
          icon={<IconClock size={18} />}
          iconBg="var(--color-destructive-light)"
          iconColor="var(--color-destructive)"
          href="/projects"
          urgent={overdueCount > 0}
        />

        {/* 7. Offene Anfragen */}
        <DashboardCard
          label="Offene Anfragen"
          value={openInquiryCount}
          subtext={`${inquiriesWithOffer} mit Angebot`}
          icon={<IconInbox size={18} />}
          iconBg="var(--color-primary-light)"
          iconColor="var(--color-primary)"
          href="/inquiries"
        />
      </div>

      {/* Budget-Warnung — nur bei Kosten-Zugriff */}
      {hasCostAccess && totalActual > totalPlanned && totalPlanned > 0 && (
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
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
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
              className="text-sm font-medium flex items-center gap-1 transition-colors"
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
                    className="text-xs px-2 py-0.5 rounded-full font-medium"
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

        {/* Meine Aufgaben */}
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
            <h2 className="font-semibold flex items-center gap-2">
              <IconClipboardCheck size={16} />
              Meine Aufgaben
            </h2>
            <span className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              {myTasks.length} offen
            </span>
          </div>

          {myTasks.length === 0 ? (
            <div className="px-5 py-8 text-center text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              Keine offenen Aufgaben an dich zugewiesen
            </div>
          ) : (
            <div>
              {myTasks.map((task) => {
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                const projectName = (task as any).projects?.name || "Projekt";
                const isOverdue = task.due_date && task.due_date < today;
                const isPending = task.assignment_status === "pending";

                return (
                  <div
                    key={task.id}
                    onClick={() => router.push(`/projects/${task.project_id}?tab=tasks`)}
                    className="flex items-center gap-3 px-5 py-3 cursor-pointer transition-colors"
                    style={{
                      borderBottom: "1px solid var(--color-border-light)",
                      background: isPending ? "var(--color-warning-light)" : "transparent",
                    }}
                    onMouseEnter={(e) => {
                      if (!isPending) e.currentTarget.style.background = "var(--color-surface-hover)";
                    }}
                    onMouseLeave={(e) => {
                      e.currentTarget.style.background = isPending ? "var(--color-warning-light)" : "transparent";
                    }}
                  >
                    <div
                      className="w-2 h-2 rounded-full flex-shrink-0"
                      style={{
                        background: isPending
                          ? "var(--color-warning)"
                          : task.status === "in_progress"
                            ? "var(--color-info)"
                            : "var(--color-muted-foreground)",
                      }}
                    />
                    <div className="flex-1 min-w-0">
                      <div className="text-sm font-medium truncate flex items-center gap-2">
                        {task.title}
                        {isPending && (
                          <span
                            className="text-[10px] px-1.5 py-0.5 rounded font-medium flex-shrink-0"
                            style={{ background: "var(--color-warning)", color: "white" }}
                          >
                            Neu
                          </span>
                        )}
                      </div>
                      <div className="text-xs flex items-center gap-2" style={{ color: "var(--color-muted-foreground)" }}>
                        <span>{projectName}</span>
                        {task.due_date && (
                          <span style={{
                            color: isOverdue ? "var(--color-destructive)" : "var(--color-muted-foreground)",
                            fontWeight: isOverdue ? 600 : 400,
                          }}>
                            📅 {new Date(task.due_date).toLocaleDateString("de-DE", { day: "numeric", month: "short" })}
                            {isOverdue && " (überfällig)"}
                          </span>
                        )}
                      </div>
                    </div>
                    <span
                      className="text-xs px-1.5 py-0.5 rounded-full font-medium flex-shrink-0"
                      style={{
                        background: task.priority === "high"
                          ? "var(--color-destructive-light)"
                          : task.priority === "medium"
                            ? "var(--color-warning-light)"
                            : "var(--color-success-light)",
                        color: task.priority === "high"
                          ? "var(--color-destructive)"
                          : task.priority === "medium"
                            ? "var(--color-warning)"
                            : "var(--color-success)",
                      }}
                    >
                      {task.priority === "high" ? "Hoch" : task.priority === "medium" ? "Mittel" : "Niedrig"}
                    </span>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
