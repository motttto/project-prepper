"use client";

import { useState, useEffect, useCallback, useMemo, useRef } from "react";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import Link from "next/link";
import { createClient } from "@/lib/supabase";
import type { Project, Inquiry } from "@/types/database";
import { TabBar } from "@/components/ui/tabs";
import { TabOverview } from "@/components/projects/tab-overview";
import { TabSchedule } from "@/components/projects/tab-schedule";
import { TabEquipment } from "@/components/projects/tab-equipment";
import { TabTeam } from "@/components/projects/tab-team";
import { TabMaterials } from "@/components/projects/tab-materials";
import { TabCosts } from "@/components/projects/tab-costs";
import { TabChecklists } from "@/components/projects/tab-checklists";
import { TabTasks } from "@/components/projects/tab-tasks";
import { TabFiles } from "@/components/projects/tab-files";
import { TabProfit } from "@/components/projects/tab-profit";
import { TabPolls } from "@/components/projects/tab-polls";
import { TabAgreement } from "@/components/projects/tab-agreement";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import { usePresence } from "@/hooks/use-presence";
import { useProjectRole } from "@/hooks/use-project-role";
import { PresenceAvatars } from "@/components/ui/presence-avatars";
import { ProjectMembersPanel } from "@/components/projects/project-members-panel";
import { useWorkspace } from "@/contexts/org-context";
import { IconUsers, IconInbox } from "@/components/ui/icons";

const statusLabels: Record<Project["status"], string> = {
  draft: "Entwurf",
  planning: "Planung",
  active: "Aktiv",
  completed: "Abgeschlossen",
  cancelled: "Abgebrochen",
};

const statusColors: Record<Project["status"], { bg: string; color: string }> = {
  draft: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)" },
  planning: { bg: "var(--color-info-light)", color: "var(--color-info)" },
  active: { bg: "var(--color-success-light)", color: "var(--color-success)" },
  completed: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)" },
  cancelled: { bg: "var(--color-destructive-light)", color: "var(--color-destructive)" },
};

const statusOrder: Project["status"][] = ["draft", "planning", "active", "completed", "cancelled"];

export default function ProjectDetailPage() {
  const params = useParams();
  const router = useRouter();
  const searchParams = useSearchParams();
  const supabase = createClient();
  const projectId = params.id as string;

  const [project, setProject] = useState<Project | null>(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState("overview");
  const [showMembers, setShowMembers] = useState(false);
  const [showStatusMenu, setShowStatusMenu] = useState(false);
  const [pendingRemoteUpdate, setPendingRemoteUpdate] = useState(false);
  const [sourceInquiry, setSourceInquiry] = useState<Inquiry | null>(null);

  // Ref: true wenn TabOverview gerade sichtbar ist (für smarten Realtime-Callback)
  const overviewActiveRef = useRef(activeTab === "overview");
  overviewActiveRef.current = activeTab === "overview";

  const currentUser = useCurrentUser();
  const { groupId } = useWorkspace();
  const orgId = groupId; // backward-compat alias für Sub-Components
  const { users: presenceUsers } = usePresence({ projectId, currentUser });
  const { canViewCosts, isOwner, loading: roleLoading } = useProjectRole(projectId);

  // Tabs dynamisch berechnen
  const tabs = useMemo(() => {
    const baseTabs = [
      { key: "overview", label: "Übersicht" },
      { key: "schedule", label: "Zeitplan" },
      { key: "equipment", label: "Equipment" },
      { key: "team", label: "Team & Kontakte" },
      { key: "materials", label: "Material & Transport" },
      { key: "checklists", label: "Checklisten" },
      { key: "tasks", label: "Aufgaben" },
      { key: "polls", label: "Umfragen" },
      { key: "agreement", label: "Vereinbarung" },
      { key: "files", label: "Dateien" },
    ];
    if (canViewCosts) {
      // Kosten-Tab vor Checklisten einfügen
      baseTabs.splice(5, 0, { key: "costs", label: "Kosten" });
      // Gewinn-Tab am Ende (nur bei Kosten-Berechtigung)
      baseTabs.push({ key: "profit", label: "Gewinn" });
    }
    return baseTabs;
  }, [canViewCosts]);

  // Deep-Link: ?tab=tasks (z.B. aus Notification-Bell)
  useEffect(() => {
    const tabParam = searchParams.get("tab");
    if (tabParam && tabs.some((t) => t.key === tabParam)) {
      setActiveTab(tabParam);
    }
  }, [searchParams, tabs]);

  // Safeguard: wenn auf Kosten-Tab und kein Zugriff → reset
  useEffect(() => {
    if (activeTab === "costs" && !canViewCosts && !roleLoading) {
      setActiveTab("overview");
    }
  }, [activeTab, canViewCosts, roleLoading]);

  const loadProject = useCallback(async () => {
    const { data } = await supabase
      .from("projects")
      .select("*")
      .eq("id", projectId)
      .single();
    if (data) setProject(data as Project);
    setLoading(false);
  }, [supabase, projectId]);

  useEffect(() => {
    loadProject();
  }, [loadProject]);

  // Prüfe ob Projekt aus einer Anfrage erstellt wurde
  useEffect(() => {
    if (!projectId) return;
    supabase
      .from("inquiries")
      .select("id, title, client_name")
      .eq("project_id", projectId)
      .maybeSingle()
      .then(({ data }) => {
        if (data) setSourceInquiry(data as Inquiry);
      });
  }, [supabase, projectId]);

  // Realtime: Projekt-Daten live synchronisieren
  // Wenn Overview-Tab aktiv ist → loadProject (field-tracking merged dort intelligent)
  const handleRealtimeProjectChange = useCallback(() => {
    loadProject();
  }, [loadProject]);

  useRealtimeTable({
    table: "projects",
    filter: { column: "id", value: projectId },
    onDataChange: handleRealtimeProjectChange,
  });

  if (loading || roleLoading) {
    return (
      <div className="flex items-center justify-center h-64" style={{ color: "var(--color-muted-foreground)" }}>
        Projekt wird geladen...
      </div>
    );
  }

  if (!project) {
    return (
      <div className="text-center py-12">
        <p className="text-lg mb-4">Projekt nicht gefunden</p>
        <button
          onClick={() => router.push("/projects")}
          style={{ color: "var(--color-primary)" }}
          className="hover:underline"
        >
          Zurück zur Übersicht
        </button>
      </div>
    );
  }

  function handleProjectUpdate(updated: Project) {
    setProject(updated);
  }

  async function changeStatus(newStatus: Project["status"]) {
    setShowStatusMenu(false);
    const { data, error } = await supabase
      .from("projects")
      .update({ status: newStatus })
      .eq("id", projectId)
      .select()
      .single();
    if (!error && data) {
      setProject(data as Project);
    }
  }

  return (
    <div>
      {/* Header */}
      <div className="flex items-center gap-3 mb-2">
        <button
          onClick={() => router.push("/projects")}
          style={{ color: "var(--color-muted-foreground)" }}
        >
          &larr; Projekte
        </button>
      </div>

      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold">{project.name}</h1>
          <div className="flex items-center gap-3 mt-2 text-sm flex-wrap" style={{ color: "var(--color-muted-foreground)" }}>
            {sourceInquiry && (
              <Link
                href={`/inquiries/${sourceInquiry.id}`}
                className="flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium transition-opacity hover:opacity-80"
                style={{ background: "var(--color-info-light)", color: "var(--color-info)" }}
              >
                <IconInbox size={12} />
                Aus Anfrage erstellt
              </Link>
            )}
            <div className="relative">
              <button
                onClick={() => setShowStatusMenu(!showStatusMenu)}
                className="px-2.5 py-1 rounded-full text-xs font-medium cursor-pointer transition-opacity hover:opacity-80"
                style={{
                  background: statusColors[project.status].bg,
                  color: statusColors[project.status].color,
                }}
              >
                {statusLabels[project.status]} ▾
              </button>
              {showStatusMenu && (
                <>
                  <div
                    className="fixed inset-0 z-40"
                    onClick={() => setShowStatusMenu(false)}
                  />
                  <div
                    className="absolute top-full left-0 mt-1 z-50 py-1 rounded-lg min-w-[160px]"
                    style={{
                      background: "var(--color-surface)",
                      border: "1px solid var(--color-border)",
                      boxShadow: "var(--shadow-lg)",
                    }}
                  >
                    {statusOrder.map((s) => (
                      <button
                        key={s}
                        onClick={() => changeStatus(s)}
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
            {project.date_start && (
              <span>
                {new Date(project.date_start).toLocaleDateString("de-DE")}
                {project.date_end && ` – ${new Date(project.date_end).toLocaleDateString("de-DE")}`}
              </span>
            )}
          </div>
        </div>
        <div className="flex items-center gap-3">
          <PresenceAvatars users={presenceUsers} currentUserId={currentUser?.id} />
          <button
            onClick={() => setShowMembers(true)}
            className="flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm"
            style={{
              border: "1px solid var(--color-border)",
              color: "var(--color-text-muted)",
            }}
            title="Mitglieder verwalten"
          >
            <IconUsers size={16} />
            Mitglieder
          </button>
        </div>
      </div>

      {/* Tab Navigation */}
      <TabBar tabs={tabs} activeTab={activeTab} onTabChange={setActiveTab} />

      {/* Tab Content */}
      {activeTab === "overview" && (
        <TabOverview
          projectId={projectId}
          project={project}
          onProjectUpdate={handleProjectUpdate}
          canViewBudget={canViewCosts}
          hasPendingRemoteUpdate={pendingRemoteUpdate}
          onClearPendingRemoteUpdate={() => setPendingRemoteUpdate(false)}
        />
      )}
      {activeTab === "schedule" && (
        <TabSchedule projectId={projectId} project={project} />
      )}
      {activeTab === "equipment" && (
        <TabEquipment projectId={projectId} project={project} currentOrgId={orgId} />
      )}
      {activeTab === "team" && (
        <TabTeam projectId={projectId} />
      )}
      {activeTab === "materials" && (
        <TabMaterials projectId={projectId} project={project} onProjectUpdate={handleProjectUpdate} />
      )}
      {activeTab === "costs" && canViewCosts && (
        <TabCosts projectId={projectId} project={project} />
      )}
      {activeTab === "checklists" && (
        <TabChecklists projectId={projectId} />
      )}
      {activeTab === "tasks" && (
        <TabTasks projectId={projectId} />
      )}
      {activeTab === "polls" && (
        <TabPolls projectId={projectId} />
      )}
      {activeTab === "agreement" && (
        <TabAgreement projectId={projectId} />
      )}
      {activeTab === "files" && (
        <TabFiles projectId={projectId} />
      )}
      {activeTab === "profit" && project && (
        <TabProfit project={project} canEdit={isOwner} />
      )}

      {/* Partner-Panel: entfaellt im neuen Modell (Cross-Org -> Groups) */}

      {/* Mitglieder-Panel */}
      <ProjectMembersPanel
        projectId={projectId}
        isOwner={isOwner}
        show={showMembers}
        onClose={() => setShowMembers(false)}
      />
    </div>
  );
}
