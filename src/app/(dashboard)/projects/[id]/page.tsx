"use client";

import { useState, useEffect, useCallback, useMemo } from "react";
import { useParams, useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import type { Project } from "@/types/database";
import { TabBar } from "@/components/ui/tabs";
import { TabOverview } from "@/components/projects/tab-overview";
import { TabSchedule } from "@/components/projects/tab-schedule";
import { TabEquipment } from "@/components/projects/tab-equipment";
import { TabTeam } from "@/components/projects/tab-team";
import { TabMaterials } from "@/components/projects/tab-materials";
import { TabCosts } from "@/components/projects/tab-costs";
import { TabChecklists } from "@/components/projects/tab-checklists";
import { TabTasks } from "@/components/projects/tab-tasks";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import { usePresence } from "@/hooks/use-presence";
import { useProjectRole } from "@/hooks/use-project-role";
import { PresenceAvatars } from "@/components/ui/presence-avatars";
import { ProjectMembersPanel } from "@/components/projects/project-members-panel";
import { ProjectPartnersPanel } from "@/components/projects/project-partners-panel";
import { useProjectOrgs } from "@/hooks/use-project-orgs";
import { useOrg } from "@/contexts/org-context";
import { IconUsers, IconHandshake } from "@/components/ui/icons";

const statusLabels: Record<Project["status"], string> = {
  draft: "Entwurf",
  planning: "Planung",
  active: "Aktiv",
  completed: "Abgeschlossen",
  cancelled: "Abgebrochen",
};

export default function ProjectDetailPage() {
  const params = useParams();
  const router = useRouter();
  const supabase = createClient();
  const projectId = params.id as string;

  const [project, setProject] = useState<Project | null>(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState("overview");
  const [showMembers, setShowMembers] = useState(false);
  const [showPartners, setShowPartners] = useState(false);

  const currentUser = useCurrentUser();
  const { orgId } = useOrg();
  const presenceUsers = usePresence({ projectId, currentUser });
  const { canViewCosts, isOwner, loading: roleLoading } = useProjectRole(projectId);
  const { acceptedOrgs } = useProjectOrgs(projectId);
  const partnerCount = acceptedOrgs.filter((o) => o.role === "partner").length;

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
    ];
    if (canViewCosts) {
      // Kosten-Tab vor Checklisten einfügen
      baseTabs.splice(5, 0, { key: "costs", label: "Kosten" });
    }
    return baseTabs;
  }, [canViewCosts]);

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

  // Realtime: Projekt-Daten live synchronisieren
  useRealtimeTable({
    table: "projects",
    filter: { column: "id", value: projectId },
    onDataChange: loadProject,
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
          <div className="flex items-center gap-3 mt-2 text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            <span className="px-2 py-0.5 rounded-full text-xs" style={{ background: "var(--color-muted)" }}>
              {statusLabels[project.status]}
            </span>
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
            onClick={() => setShowPartners(true)}
            className="flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm"
            style={{
              border: "1px solid var(--color-border)",
              color: "var(--color-text-muted)",
            }}
            title="Partner-Organisationen"
          >
            <IconHandshake size={16} />
            Partner
            {partnerCount > 0 && (
              <span
                className="text-xs px-1.5 py-0.5 rounded-full font-medium"
                style={{ background: "var(--color-info-light)", color: "var(--color-info)" }}
              >
                {partnerCount}
              </span>
            )}
          </button>
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

      {/* Partner-Panel */}
      <ProjectPartnersPanel
        projectId={projectId}
        isOwner={isOwner}
        show={showPartners}
        onClose={() => setShowPartners(false)}
      />

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
