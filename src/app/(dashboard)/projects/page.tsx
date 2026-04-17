"use client";

import { useState, useEffect, useCallback, useMemo } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { useOrg, useWorkspace } from "@/contexts/org-context";
import { useCurrentUser, hasPermission } from "@/hooks/use-current-user";
import type { Project } from "@/types/database";
import { IconPlus, IconSearch, IconX, IconChevronRight, IconTrash, IconHandshake, IconUser } from "@/components/ui/icons";
import { DateInput } from "@/components/ui/date-input";
import { appConfirm } from "@/components/ui/confirm-dialog";
import { showToast } from "@/hooks/use-toast";
import { logActivity } from "@/lib/activity-log";

const statusLabels: Record<Project["status"], string> = {
  draft: "Entwurf",
  planning: "Planung",
  active: "Aktiv",
  completed: "Abgeschlossen",
  cancelled: "Abgebrochen",
};

const statusDots: Record<Project["status"], string> = {
  draft: "#9ca3af",
  planning: "#3b82f6",
  active: "#10b981",
  completed: "#6b7280",
  cancelled: "#ef4444",
};

const statusColors: Record<Project["status"], { bg: string; color: string }> = {
  draft: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)" },
  planning: { bg: "var(--color-info-light)", color: "var(--color-info)" },
  active: { bg: "var(--color-success-light)", color: "var(--color-success)" },
  completed: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)" },
  cancelled: { bg: "var(--color-destructive-light)", color: "var(--color-destructive)" },
};

const statusOrder: Project["status"][] = ["draft", "planning", "active", "completed", "cancelled"];

type StatusFilter = "all" | Project["status"];
type ViewFilter = "mine" | "team" | "all";

export default function ProjectsPage() {
  const supabase = createClient();
  const router = useRouter();
  const currentUser = useCurrentUser();
  const ownerId = currentUser?.id ?? null;
  const orgId = ownerId; // alias backward-compat
  const { groupId } = useWorkspace();
  const canEdit = true;
  const [projects, setProjects] = useState<(Project & { isPartner?: boolean })[]>([]);
  const [myProjectIds, setMyProjectIds] = useState<Set<string>>(new Set());
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<StatusFilter>("all");
  const [viewFilter, setViewFilter] = useState<ViewFilter>("all");

  // Formular-State
  const [formName, setFormName] = useState("");
  const [formDescription, setFormDescription] = useState("");
  const [formDateStart, setFormDateStart] = useState("");
  const [formDateEnd, setFormDateEnd] = useState("");
  const [formStatus, setFormStatus] = useState<Project["status"]>("draft");
  const [saving, setSaving] = useState(false);
  const [statusMenuId, setStatusMenuId] = useState<string | null>(null);

  const loadProjects = useCallback(async () => {
    if (!ownerId || !currentUser) return;

    // Group-Modus: Projekte der Gruppe laden
    // Solo-Modus: eigene Projekte (owner_profile_id)
    const query = groupId
      ? supabase
          .from("projects")
          .select("*")
          .eq("group_id", groupId)
          .order("date_start", { ascending: false })
      : supabase
          .from("projects")
          .select("*")
          .eq("owner_profile_id", ownerId)
          .order("date_start", { ascending: false });

    const { data: ownData } = await query;
    const ownProjects = (ownData || []).map((p) => ({ ...p, isPartner: false }));

    // Mitgliedschaften des Users (project_members)
    const { data: memberships } = await supabase
      .from("project_members")
      .select("project_id")
      .eq("profile_id", currentUser.id);
    setMyProjectIds(new Set((memberships || []).map((m) => m.project_id)));

    setProjects(ownProjects as (Project & { isPartner?: boolean })[]);
    setLoading(false);
  }, [supabase, ownerId, currentUser, groupId]);

  useEffect(() => {
    loadProjects();
  }, [loadProjects]);

  // Filtered & Grouped
  const filtered = useMemo(() => {
    let result = projects;
    if (viewFilter === "mine") {
      result = result.filter((p) => myProjectIds.has(p.id));
    } else if (viewFilter === "team") {
      result = result.filter((p) => !myProjectIds.has(p.id));
    }
    if (statusFilter !== "all") {
      result = result.filter((p) => p.status === statusFilter);
    }
    if (search.trim()) {
      const q = search.toLowerCase();
      result = result.filter(
        (p) =>
          p.name.toLowerCase().includes(q) ||
          p.description?.toLowerCase().includes(q)
      );
    }
    return result;
  }, [projects, statusFilter, search, viewFilter, myProjectIds]);

  // Group by year
  const grouped = useMemo(() => {
    const groups: Record<string, Project[]> = {};
    filtered.forEach((p) => {
      const year = p.date_start
        ? new Date(p.date_start).getFullYear().toString()
        : "Ohne Datum";
      if (!groups[year]) groups[year] = [];
      groups[year].push(p);
    });
    // Sort years descending
    return Object.entries(groups).sort(([a], [b]) => {
      if (a === "Ohne Datum") return 1;
      if (b === "Ohne Datum") return -1;
      return Number(b) - Number(a);
    });
  }, [filtered]);

  // Status counts (respecting viewFilter)
  const statusCounts = useMemo(() => {
    let base = projects;
    if (viewFilter === "mine") base = base.filter((p) => myProjectIds.has(p.id));
    else if (viewFilter === "team") base = base.filter((p) => !myProjectIds.has(p.id));
    const counts: Record<string, number> = { all: base.length };
    base.forEach((p) => {
      counts[p.status] = (counts[p.status] || 0) + 1;
    });
    return counts;
  }, [projects, viewFilter, myProjectIds]);

  // View counts
  const viewCounts = useMemo(() => ({
    all: projects.length,
    mine: projects.filter((p) => myProjectIds.has(p.id)).length,
    team: projects.filter((p) => !myProjectIds.has(p.id)).length,
  }), [projects, myProjectIds]);

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    const {
      data: { user },
    } = await supabase.auth.getUser();

    const { error } = await supabase.from("projects").insert({
      name: formName,
      description: formDescription || null,
      date_start: formDateStart || null,
      date_end: formDateEnd || null,
      status: formStatus,
      created_by: user?.id,
      owner_profile_id: ownerId,
    });

    if (error) {
      showToast("Fehler beim Erstellen: " + error.message, "error");
    } else {
      setFormName("");
      setFormDescription("");
      setFormDateStart("");
      setFormDateEnd("");
      setFormStatus("draft");
      setShowCreate(false);
      loadProjects();
      showToast("Projekt erstellt", "success");
      // logActivity entfaellt während Refactor
    }
    setSaving(false);
  }

  async function handleStatusChange(e: React.MouseEvent, projectId: string, newStatus: Project["status"]) {
    e.stopPropagation();
    setStatusMenuId(null);
    const { error } = await supabase
      .from("projects")
      .update({ status: newStatus })
      .eq("id", projectId);
    if (error) {
      showToast("Status konnte nicht geändert werden", "error");
    } else {
      setProjects((prev) =>
        prev.map((p) => (p.id === projectId ? { ...p, status: newStatus } : p))
      );
      const proj = projects.find(p => p.id === projectId);
      // logActivity entfaellt während Refactor
    }
  }

  async function handleDelete(e: React.MouseEvent, id: string) {
    e.stopPropagation();
    if (!(await appConfirm("Projekt wirklich löschen?", { variant: "danger", confirmLabel: "Löschen" }))) return;
    const { error } = await supabase.from("projects").delete().eq("id", id);
    if (error) {
      showToast("Fehler beim Löschen: " + error.message, "error");
    } else {
      loadProjects();
      showToast("Projekt gelöscht", "success");
    }
  }

  if (loading) {
    return (
      <div className="space-y-4">
        <div className="h-8 w-48 skeleton" />
        <div className="h-10 skeleton rounded-lg" />
        {[1, 2, 3, 4, 5].map((i) => (
          <div key={i} className="h-16 skeleton rounded-xl" />
        ))}
      </div>
    );
  }

  return (
    <div className="animate-fadeIn">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold">Projekte</h1>
          <p className="text-sm mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>
            {viewFilter === "mine"
              ? `${viewCounts.mine} Projekte mit deiner Beteiligung`
              : viewFilter === "team"
                ? `${viewCounts.team} weitere Team-Projekte`
                : `${projects.length} Projekte insgesamt`}
          </p>
        </div>
        {canEdit && (
          <button
            onClick={() => setShowCreate(!showCreate)}
            className="flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium text-white transition-colors"
            style={{ background: "var(--color-primary)" }}
            onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-primary-hover)"}
            onMouseLeave={(e) => e.currentTarget.style.background = "var(--color-primary)"}
          >
            <IconPlus size={16} />
            Neues Projekt
          </button>
        )}
      </div>

      {/* Create Form */}
      {showCreate && (
        <div
          className="mb-6 p-6 rounded-xl animate-slideDown"
          style={{
            background: "var(--color-surface)",
            boxShadow: "var(--shadow-md)",
            border: "1px solid var(--color-border)",
          }}
        >
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold">Neues Projekt anlegen</h2>
            <button onClick={() => setShowCreate(false)} style={{ color: "var(--color-muted-foreground)" }}>
              <IconX size={20} />
            </button>
          </div>
          <form onSubmit={handleCreate} className="space-y-4">
            <div>
              <label className="block text-sm font-medium mb-1.5">Name</label>
              <input
                type="text"
                value={formName}
                onChange={(e) => setFormName(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                placeholder="Projektname"
                required
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1.5">Beschreibung</label>
              <textarea
                value={formDescription}
                onChange={(e) => setFormDescription(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                rows={2}
                placeholder="Kontakt, Equipment, Notizen..."
              />
            </div>
            <div className="grid grid-cols-3 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1.5">Start</label>
                <DateInput
                  value={formDateStart}
                  onChange={setFormDateStart}
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Ende</label>
                <DateInput
                  value={formDateEnd}
                  onChange={setFormDateEnd}
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Status</label>
                <select
                  value={formStatus}
                  onChange={(e) => setFormStatus(e.target.value as Project["status"])}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                >
                  {Object.entries(statusLabels).map(([key, label]) => (
                    <option key={key} value={key}>{label}</option>
                  ))}
                </select>
              </div>
            </div>
            <div className="flex gap-2 pt-2">
              <button
                type="submit"
                disabled={saving}
                className="px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}
              >
                {saving ? "Wird gespeichert..." : "Anlegen"}
              </button>
              <button
                type="button"
                onClick={() => setShowCreate(false)}
                className="px-4 py-2 rounded-lg text-sm"
                style={{ border: "1px solid var(--color-border)" }}
              >
                Abbrechen
              </button>
            </div>
          </form>
        </div>
      )}

      {/* View Tabs: Meine / Team / Alle */}
      <div className="flex gap-1 mb-4">
        {([
          { key: "all" as ViewFilter, label: "Alle Projekte" },
          { key: "mine" as ViewFilter, label: "Meine Projekte" },
          { key: "team" as ViewFilter, label: "Team-Projekte" },
        ]).map(({ key, label }) => (
          <button
            key={key}
            onClick={() => { setViewFilter(key); setStatusFilter("all"); }}
            className="px-4 py-2 rounded-lg text-sm font-medium transition-all"
            style={{
              background: viewFilter === key ? "var(--color-primary)" : "var(--color-muted)",
              color: viewFilter === key ? "#fff" : "var(--color-muted-foreground)",
            }}
          >
            {label}
            <span className="ml-1.5 opacity-70">{viewCounts[key]}</span>
          </button>
        ))}
      </div>

      {/* Search + Filter Bar */}
      <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 mb-5">
        {/* Search */}
        <div className="relative flex-1 max-w-sm">
          <IconSearch
            size={16}
            className="absolute left-3 top-1/2 -translate-y-1/2"
            style={{ color: "var(--color-muted-foreground)" } as React.CSSProperties}
          />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Projekte suchen..."
            className="w-full pl-9 pr-3 py-2 rounded-lg text-sm"
            style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
          />
          {search && (
            <button
              onClick={() => setSearch("")}
              className="absolute right-2 top-1/2 -translate-y-1/2"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              <IconX size={14} />
            </button>
          )}
        </div>

        {/* Status Filter Tabs */}
        <div className="flex gap-1 rounded-lg p-1 overflow-x-auto" style={{ background: "var(--color-muted)" }}>
          {(["all", "planning", "active", "completed"] as StatusFilter[]).map((status) => (
            <button
              key={status}
              onClick={() => setStatusFilter(status)}
              className="px-3 py-1.5 rounded-md text-sm font-medium transition-all"
              style={{
                background: statusFilter === status ? "var(--color-surface)" : "transparent",
                boxShadow: statusFilter === status ? "var(--shadow-sm)" : "none",
                color: statusFilter === status ? "var(--color-foreground)" : "var(--color-muted-foreground)",
              }}
            >
              {status === "all" ? "Alle" : statusLabels[status]}
              <span className="ml-1 opacity-60">
                {statusCounts[status] || 0}
              </span>
            </button>
          ))}
        </div>
      </div>

      {/* Project List */}
      {filtered.length === 0 ? (
        <div
          className="text-center py-16 rounded-xl"
          style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}
        >
          <p className="text-lg mb-1">
            {search ? "Keine Treffer" : "Noch keine Projekte"}
          </p>
          <p className="text-sm">
            {search
              ? `Keine Projekte für "${search}" gefunden`
              : "Erstelle dein erstes Projekt mit dem Button oben."}
          </p>
        </div>
      ) : (
        <div className="space-y-6">
          {grouped.map(([year, yearProjects]) => (
            <div key={year}>
              {/* Year Header */}
              <div className="flex items-center gap-3 mb-3">
                <h3 className="text-sm font-semibold" style={{ color: "var(--color-muted-foreground)" }}>
                  {year}
                </h3>
                <div className="flex-1 h-px" style={{ background: "var(--color-border-light)" }} />
                <span className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
                  {yearProjects.length} Projekte
                </span>
              </div>

              {/* Project Cards */}
              <div className="space-y-2">
                {yearProjects.map((project) => (
                  <div
                    key={project.id}
                    onClick={() => router.push(`/projects/${project.id}`)}
                    className="flex items-center gap-4 px-4 py-3 rounded-xl cursor-pointer transition-all group"
                    style={{
                      background: "var(--color-surface)",
                      border: "1px solid var(--color-border-light)",
                    }}
                    onMouseEnter={(e) => {
                      e.currentTarget.style.borderColor = "var(--color-primary-muted)";
                      e.currentTarget.style.boxShadow = "var(--shadow-sm)";
                    }}
                    onMouseLeave={(e) => {
                      e.currentTarget.style.borderColor = "var(--color-border-light)";
                      e.currentTarget.style.boxShadow = "none";
                    }}
                  >
                    {/* Status Dot */}
                    <div
                      className="w-2.5 h-2.5 rounded-full shrink-0"
                      style={{ background: statusDots[project.status] }}
                    />

                    {/* Info */}
                    <div className="flex-1 min-w-0">
                      <div className="font-medium text-sm flex items-center gap-2">
                        {project.name}
                        {(project as Project & { isPartner?: boolean }).isPartner && (
                          <span
                            className="inline-flex items-center gap-1 text-xs px-1.5 py-0.5 rounded-full font-medium"
                            style={{ background: "var(--color-info-light)", color: "var(--color-info)" }}
                          >
                            <IconHandshake size={10} />
                            Partner
                          </span>
                        )}
                      </div>
                      {project.description && (
                        <div
                          className="text-sm truncate mt-0.5"
                          style={{ color: "var(--color-muted-foreground)" }}
                        >
                          {project.description}
                        </div>
                      )}
                    </div>

                    {/* Date */}
                    {project.date_start && (
                      <div className="text-sm shrink-0" style={{ color: "var(--color-muted-foreground)" }}>
                        {new Date(project.date_start).toLocaleDateString("de-DE", {
                          day: "numeric",
                          month: "short",
                        })}
                        {project.date_end && project.date_end !== project.date_start &&
                          ` – ${new Date(project.date_end).toLocaleDateString("de-DE", {
                            day: "numeric",
                            month: "short",
                          })}`}
                      </div>
                    )}

                    {/* Status Switcher */}
                    <div className="relative shrink-0">
                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          setStatusMenuId(statusMenuId === project.id ? null : project.id);
                        }}
                        className="text-xs px-2.5 py-1 rounded-full font-medium transition-opacity hover:opacity-80"
                        style={{
                          background: statusColors[project.status].bg,
                          color: statusColors[project.status].color,
                        }}
                      >
                        {statusLabels[project.status]} ▾
                      </button>
                      {statusMenuId === project.id && (
                        <>
                          <div
                            className="fixed inset-0 z-40"
                            onClick={(e) => { e.stopPropagation(); setStatusMenuId(null); }}
                          />
                          <div
                            className="absolute top-full right-0 mt-1 z-50 py-1 rounded-lg min-w-[160px]"
                            style={{
                              background: "var(--color-surface)",
                              border: "1px solid var(--color-border)",
                              boxShadow: "var(--shadow-lg)",
                            }}
                          >
                            {statusOrder.map((s) => (
                              <button
                                key={s}
                                onClick={(e) => handleStatusChange(e, project.id, s)}
                                className="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-left transition-colors"
                                style={{
                                  color: s === project.status ? statusColors[s].color : "var(--color-foreground)",
                                  background: s === project.status ? statusColors[s].bg : "transparent",
                                }}
                                onMouseEnter={(e) => {
                                  if (s !== project.status) e.currentTarget.style.background = "var(--color-muted)";
                                }}
                                onMouseLeave={(e) => {
                                  if (s !== project.status) e.currentTarget.style.background = "transparent";
                                }}
                              >
                                <span
                                  className="w-2 h-2 rounded-full flex-shrink-0"
                                  style={{ background: statusColors[s].color }}
                                />
                                {statusLabels[s]}
                              </button>
                            ))}
                          </div>
                        </>
                      )}
                    </div>

                    {/* Delete + Arrow */}
                    <button
                      onClick={(e) => handleDelete(e, project.id)}
                      className="opacity-0 group-hover:opacity-100 p-1 rounded transition-opacity"
                      style={{ color: "var(--color-destructive)" }}
                      title="Löschen"
                    >
                      <IconTrash size={14} />
                    </button>
                    <IconChevronRight
                      size={16}
                      className="opacity-30 group-hover:opacity-60 transition-opacity shrink-0"
                    />
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
