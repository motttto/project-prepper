"use client";

import { useState, useEffect, useCallback } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { useCurrentUser, isOrgAdmin } from "@/hooks/use-current-user";
import { useOrg } from "@/contexts/org-context";
import { useImpersonate } from "@/contexts/impersonate-context";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type { UserPermissions, PermissionKey, ActivityLogEntry, ActivityAction, OrgEmailConfig } from "@/types/database";
import { permissionGroups, defaultPermissionsByRole, allPermissionKeys } from "@/types/database";
import {
  IconUsers,
  IconUserPlus,
  IconPlus,
  IconTrash,
  IconShield,
  IconEye,
  IconInventory,
  IconX,
  IconCheck,
  IconZap,
  IconMail,
} from "@/components/ui/icons";
import {
  RoleBadge,
  RoleBadgeGroup,
  orgRoleLabels,
  orgBadgeStyles,
} from "@/components/ui/role-badge";
import { showToast } from "@/hooks/use-toast";
import { logActivity } from "@/lib/activity-log";
import { appConfirm, appAlert } from "@/components/ui/confirm-dialog";
import { UsersOverviewTab } from "@/components/admin/users-overview-tab";

// ── Types ──

type OrgMember = {
  id: string;
  org_id: string;
  profile_id: string;
  role_id: string;
  is_active: boolean;
  approved_at: string | null;
  permissions: UserPermissions | null;
  created_at: string;
  profiles: {
    id: string;
    name: string;
    email: string;
    avatar_url: string | null;
    is_system: boolean;
    created_at: string;
  };
  roles: {
    id: string;
    name: string;
  };
};

type RoleOption = {
  id: string;
  name: string;
};

// ── Activity Log Constants ──

const PAGE_SIZE = 50;

const actionLabels: Record<string, string> = {
  "member.registered": "hat sich registriert",
  "member.approved": "hat freigeschaltet",
  "member.role_changed": "hat Rolle geaendert von",
  "member.deactivated": "hat deaktiviert",
  "member.reactivated": "hat reaktiviert",
  "project.created": "Projekt erstellt",
  "project.updated": "Projekt aktualisiert",
  "project.status_changed": "Projektstatus geaendert",
  "inventory.created": "Inventar hinzugefuegt",
  "inventory.updated": "Inventar aktualisiert",
  "inventory.deleted": "Inventar geloescht",
  "decision.created": "Beschluss erstellt",
  "decision.voted": "hat abgestimmt",
  "decision.resolved": "Beschluss abgeschlossen",
  "guest.added": "Gast hinzugefuegt",
  "guest.removed": "Gast entfernt",
  "invitation.sent": "Einladung gesendet",
  "invitation.accepted": "Einladung angenommen",
  "booking.created": "Buchung erstellt",
  "booking.deleted": "Buchung geloescht",
};

const actionIcons: Record<string, string> = {
  "member.registered": "\u{1F464}",
  "member.approved": "\u2705",
  "member.role_changed": "\u{1F504}",
  "member.deactivated": "\u26D4",
  "member.reactivated": "\u267B\uFE0F",
  "project.created": "\u{1F4C1}",
  "project.updated": "\u270F\uFE0F",
  "project.status_changed": "\u{1F4CA}",
  "inventory.created": "\u{1F4E6}",
  "inventory.updated": "\u{1F4E6}",
  "inventory.deleted": "\u{1F5D1}\uFE0F",
  "decision.created": "\u{1F4CB}",
  "decision.voted": "\u{1F5F3}\uFE0F",
  "decision.resolved": "\u2696\uFE0F",
  "guest.added": "\u{1F39F}\uFE0F",
  "guest.removed": "\u{1F39F}\uFE0F",
  "invitation.sent": "\u{1F4E8}",
  "invitation.accepted": "\u{1F4EC}",
  "booking.created": "\u{1F4C5}",
  "booking.deleted": "\u{1F4C5}",
};

const actionGroups: { label: string; actions: string[] }[] = [
  { label: "Mitglieder", actions: ["member.registered", "member.approved", "member.role_changed", "member.deactivated", "member.reactivated"] },
  { label: "Projekte", actions: ["project.created", "project.updated", "project.status_changed"] },
  { label: "Inventar", actions: ["inventory.created", "inventory.updated", "inventory.deleted"] },
  { label: "Beschluesse", actions: ["decision.created", "decision.voted", "decision.resolved"] },
  { label: "Gaeste", actions: ["guest.added", "guest.removed"] },
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

// ── Main Component ──

export default function AdminPage() {
  const supabase = createClient();
  const router = useRouter();
  const currentUser = useCurrentUser();
  const { orgId } = useOrg();
  const { startImpersonating } = useImpersonate();

  const [activeTab, setActiveTab] = useState<"roles" | "log" | "system" | "services" | "email" | "users">("roles");

  // ── Rollen & Berechtigungen State ──
  const [members, setMembers] = useState<OrgMember[]>([]);
  const [roles, setRoles] = useState<RoleOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState<string | null>(null);
  const [expandedPermissions, setExpandedPermissions] = useState<string | null>(null);
  const [showInviteModal, setShowInviteModal] = useState(false);

  // ── Activity Log State ──
  const [logEntries, setLogEntries] = useState<ActivityLogEntry[]>([]);
  const [logLoading, setLogLoading] = useState(true);
  const [logHasMore, setLogHasMore] = useState(false);
  const [filterAction, setFilterAction] = useState<string>("all");

  const isAdmin = isOrgAdmin(currentUser);

  // ── Redirect non-admins ──
  useEffect(() => {
    if (currentUser && !isAdmin) {
      router.push("/dashboard");
    }
  }, [currentUser, isAdmin, router]);

  // ── Load Members & Roles ──
  const loadMembers = useCallback(async () => {
    if (!orgId) return;
    const [membersRes, rolesRes] = await Promise.all([
      supabase
        .from("org_memberships")
        .select("*, permissions, profiles(id, name, email, avatar_url, is_system, created_at), roles(id, name)")
        .eq("org_id", orgId),
      supabase.from("roles").select("id, name").eq("org_id", orgId),
    ]);

    if (membersRes.data) {
      const sorted = (membersRes.data as OrgMember[]).sort((a, b) =>
        (a.profiles?.name || "").localeCompare(b.profiles?.name || "")
      );
      setMembers(sorted);
    }
    if (rolesRes.data) setRoles(rolesRes.data as RoleOption[]);
    setLoading(false);
  }, [supabase, orgId]);

  useEffect(() => {
    loadMembers();
  }, [loadMembers]);

  // ── Load Activity Log ──
  const loadLogEntries = useCallback(async (append = false) => {
    if (!orgId) return;
    setLogLoading(true);

    let query = supabase
      .from("org_activity_log")
      .select("*, actor:profiles!actor_id(name, avatar_url)")
      .eq("org_id", orgId)
      .order("created_at", { ascending: false })
      .limit(PAGE_SIZE + 1);

    if (filterAction !== "all") {
      const group = actionGroups.find(g => g.label === filterAction);
      if (group) {
        query = query.in("action", group.actions);
      } else {
        query = query.eq("action", filterAction);
      }
    }

    if (append && logEntries.length > 0) {
      query = query.lt("created_at", logEntries[logEntries.length - 1].created_at);
    }

    const { data } = await query;
    if (data) {
      const hasNext = data.length > PAGE_SIZE;
      const sliced = data.slice(0, PAGE_SIZE) as ActivityLogEntry[];
      setLogEntries(prev => append ? [...prev, ...sliced] : sliced);
      setLogHasMore(hasNext);
    }
    setLogLoading(false);
  }, [supabase, orgId, filterAction, logEntries]);

  useEffect(() => {
    if (orgId && isAdmin && activeTab === "log") {
      loadLogEntries();
    }
  }, [orgId, isAdmin, filterAction, activeTab]);

  // ── Realtime ──
  useRealtimeTable({ table: "org_memberships", onDataChange: loadMembers, orgFilter: orgId || undefined });
  useRealtimeTable({
    table: "org_activity_log",
    onDataChange: () => { if (activeTab === "log") loadLogEntries(); },
    orgFilter: orgId || undefined,
    enabled: !!orgId && !!isAdmin,
  });

  // ── Helper Functions ──

  function getRoleName(member: OrgMember): string {
    return member.roles?.name || "member";
  }

  function getMemberPermissions(member: OrgMember): UserPermissions {
    const roleName = getRoleName(member);
    return member.permissions || defaultPermissionsByRole[roleName] || defaultPermissionsByRole.member;
  }

  // ── Role Change ──
  async function handleRoleChange(profileId: string, newRoleId: string) {
    if (!orgId) return;
    const member = members.find(m => m.profile_id === profileId);
    const oldRole = member ? getRoleName(member) : "unknown";
    const newRole = roles.find(r => r.id === newRoleId)?.name || "unknown";

    await supabase
      .from("org_memberships")
      .update({ role_id: newRoleId })
      .eq("profile_id", profileId)
      .eq("org_id", orgId);

    if (orgId) {
      logActivity({
        orgId,
        action: "member.role_changed",
        entityType: "member",
        entityId: profileId,
        entityLabel: member?.profiles?.name || "",
        metadata: { old_role: oldRole, new_role: newRole },
      });
    }

    showToast("Rolle geaendert", "success");
    await loadMembers();
  }

  // ── Toggle Permission ──
  async function handleTogglePermission(member: OrgMember, key: PermissionKey) {
    if (!orgId) return;
    const roleName = getRoleName(member);
    const defaults = defaultPermissionsByRole[roleName] || defaultPermissionsByRole.member;
    const current: UserPermissions = member.permissions || { ...defaults };
    const updated = { ...current, [key]: !current[key] };
    await supabase
      .from("org_memberships")
      .update({ permissions: updated })
      .eq("profile_id", member.profile_id)
      .eq("org_id", orgId);
    setMembers((prev) =>
      prev.map((m) =>
        m.profile_id === member.profile_id ? { ...m, permissions: updated } : m
      )
    );
  }

  // ── Toggle Active ──
  async function handleToggleActive(profileId: string, activate: boolean) {
    if (!orgId || processing) return;
    setProcessing(profileId);
    await supabase
      .from("org_memberships")
      .update({
        is_active: activate,
        approved_at: activate ? new Date().toISOString() : undefined,
      })
      .eq("profile_id", profileId)
      .eq("org_id", orgId);

    const member = members.find(m => m.profile_id === profileId);
    if (orgId) {
      logActivity({
        orgId,
        action: activate ? "member.reactivated" : "member.deactivated",
        entityType: "member",
        entityId: profileId,
        entityLabel: member?.profiles?.name || "",
      });
    }

    showToast(activate ? "Mitglied reaktiviert" : "Mitglied deaktiviert", "success");
    await loadMembers();
    setProcessing(null);
  }

  // ── Create Test User ──
  async function handleCreateTestUser() {
    if (!orgId || !currentUser) return;
    setProcessing("testuser");

    const testNames = ["Max Mustermann", "Erika Musterfrau", "Test User", "Anna Schmidt", "Lukas Weber", "Sophie Mueller", "Jonas Fischer", "Lena Bauer"];
    const randomName = testNames[Math.floor(Math.random() * testNames.length)];
    const randomSuffix = Math.floor(Math.random() * 1000);
    const testEmail = `test.${randomSuffix}@example.com`;

    const memberRole = roles.find((r) => r.name === "member");
    if (!memberRole) { setProcessing(null); return; }

    await supabase.rpc("create_test_user", {
      p_org_id: orgId,
      p_name: `${randomName} (Test)`,
      p_email: testEmail,
      p_role_id: memberRole.id,
    });

    showToast("Testuser erstellt", "success");
    await loadMembers();
    setProcessing(null);
  }

  // ── Remove Member ──
  async function handleRemoveMember(profileId: string, profileName: string) {
    if (!orgId) return;
    const isTestUser = profileName?.includes("(Test)");
    const confirmMsg = isTestUser
      ? `Testuser "${profileName}" komplett loeschen?`
      : `"${profileName}" aus der Organisation entfernen?`;
    if (!(await appConfirm(confirmMsg, { variant: "danger", confirmLabel: "Entfernen" }))) return;

    setProcessing(profileId);
    await supabase.rpc("remove_org_member", {
      p_org_id: orgId,
      p_profile_id: profileId,
      p_delete_user: isTestUser,
    });
    showToast(isTestUser ? "Testuser geloescht" : "Mitglied entfernt", "success");
    await loadMembers();
    setProcessing(null);
  }

  // ── Computed ──
  const roleLabels = orgRoleLabels;
  const roleBadgeStyles = orgBadgeStyles;

  if (!isAdmin) {
    return (
      <div className="py-8 text-center" style={{ color: "var(--color-muted-foreground)" }}>
        Kein Zugriff.
      </div>
    );
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64" style={{ color: "var(--color-muted-foreground)" }}>
        Wird geladen...
      </div>
    );
  }

  // ── Tab Config ──
  const tabs: { key: "roles" | "log" | "system" | "services" | "email" | "users"; label: string }[] = [
    { key: "roles", label: "Rollen & Berechtigungen" },
    { key: "log", label: "Protokoll" },
    { key: "services", label: "Services" },
    { key: "email", label: "E-Mail" },
    ...(currentUser?.isSuperadmin ? [{ key: "users" as const, label: "User" }] : []),
    { key: "system", label: "System" },
  ];

  return (
    <div className="max-w-5xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Admin</h1>
          <p className="text-sm mt-1" style={{ color: "var(--color-muted-foreground)" }}>
            Rollen, Berechtigungen und Aktivitaetsprotokoll
          </p>
        </div>
        {activeTab === "roles" && (
          <div className="flex items-center gap-2">
            <button
              onClick={handleCreateTestUser}
              disabled={processing === "testuser"}
              className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors disabled:opacity-50"
              style={{ border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}
              onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-muted)"}
              onMouseLeave={(e) => e.currentTarget.style.background = "transparent"}
            >
              <IconPlus size={16} />
              Testuser
            </button>
            <button
              onClick={() => setShowInviteModal(true)}
              className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium text-white transition-colors"
              style={{ background: "var(--color-primary)" }}
              onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-primary-hover)"}
              onMouseLeave={(e) => e.currentTarget.style.background = "var(--color-primary)"}
            >
              <IconUserPlus size={16} />
              Einladen
            </button>
          </div>
        )}
      </div>

      {/* Tab Bar */}
      <div className="flex gap-1 rounded-lg p-1" style={{ background: "var(--color-muted)" }}>
        {tabs.map((tab) => (
          <button
            key={tab.key}
            onClick={() => setActiveTab(tab.key)}
            className="flex-1 px-4 py-2 rounded-md text-sm font-medium transition-colors"
            style={{
              background: activeTab === tab.key ? "var(--color-primary)" : "transparent",
              color: activeTab === tab.key ? "var(--color-primary-foreground)" : "var(--color-muted-foreground)",
            }}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Tab Content */}
      {activeTab === "roles" ? (
        <RolesTab
          members={members}
          roles={roles}
          currentUser={currentUser}
          processing={processing}
          expandedPermissions={expandedPermissions}
          setExpandedPermissions={setExpandedPermissions}
          getRoleName={getRoleName}
          getMemberPermissions={getMemberPermissions}
          handleRoleChange={handleRoleChange}
          handleTogglePermission={handleTogglePermission}
          handleToggleActive={handleToggleActive}
          handleRemoveMember={handleRemoveMember}
          startImpersonating={startImpersonating}
          roleLabels={roleLabels}
          roleBadgeStyles={roleBadgeStyles}
        />
      ) : activeTab === "log" ? (
        <LogTab
          entries={logEntries}
          loading={logLoading}
          hasMore={logHasMore}
          filterAction={filterAction}
          setFilterAction={setFilterAction}
          loadEntries={loadLogEntries}
        />
      ) : activeTab === "services" ? (
        <ServicesTab orgId={orgId || ""} />
      ) : activeTab === "email" ? (
        <EmailConfigTab ownerId={currentUser?.id || ""} userEmail={currentUser?.email || ""} />
      ) : activeTab === "users" ? (
        <UsersOverviewTab currentUserId={currentUser?.id || ""} />
      ) : (
        <SystemTab />
      )}

      {/* Invite Modal */}
      {showInviteModal && (
        <InviteModal
          orgId={orgId || ""}
          roles={roles}
          currentUserId={currentUser?.id || ""}
          onClose={() => setShowInviteModal(false)}
          onInvited={loadMembers}
        />
      )}
    </div>
  );
}

// ══════════════════════════════════════════════
// ── Rollen & Berechtigungen Tab ──
// ══════════════════════════════════════════════

function RolesTab({
  members,
  roles,
  currentUser,
  processing,
  expandedPermissions,
  setExpandedPermissions,
  getRoleName,
  getMemberPermissions,
  handleRoleChange,
  handleTogglePermission,
  handleToggleActive,
  handleRemoveMember,
  startImpersonating,
  roleLabels,
  roleBadgeStyles,
}: {
  members: OrgMember[];
  roles: RoleOption[];
  currentUser: ReturnType<typeof useCurrentUser>;
  processing: string | null;
  expandedPermissions: string | null;
  setExpandedPermissions: (id: string | null) => void;
  getRoleName: (m: OrgMember) => string;
  getMemberPermissions: (m: OrgMember) => UserPermissions;
  handleRoleChange: (profileId: string, newRoleId: string) => void;
  handleTogglePermission: (member: OrgMember, key: PermissionKey) => void;
  handleToggleActive: (profileId: string, activate: boolean) => void;
  handleRemoveMember: (profileId: string, profileName: string) => void;
  startImpersonating: (user: { profileId: string; name: string; roleName: string; permissions: UserPermissions }) => void;
  roleLabels: Record<string, string>;
  roleBadgeStyles: Record<string, { bg: string; color: string }>;
}) {
  const activeMembers = members.filter((m) => m.is_active);
  const inactiveMembers = members.filter((m) => !m.is_active);

  if (members.length === 0) {
    return (
      <div
        className="py-12 text-center rounded-xl"
        style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}
      >
        <p className="text-lg mb-1">Keine Mitglieder</p>
      </div>
    );
  }

  const renderMemberRow = (member: OrgMember, idx: number, listLength: number) => {
        const profile = member.profiles;
        const orgRoleName = getRoleName(member);
        const badgeStyle = roleBadgeStyles[orgRoleName] || roleBadgeStyles.member;
        const isSelf = currentUser?.id === member.profile_id;
        const isSystemUser = profile?.is_system;
        const isLastAdmin =
          orgRoleName === "admin" &&
          activeMembers.filter((m) => getRoleName(m) === "admin").length <= 1;
        const isExpanded = expandedPermissions === member.profile_id;
        const memberPerms = getMemberPermissions(member);
        const canEditPerms = !isSelf && orgRoleName !== "admin" && !isSystemUser;

        return (
          <div key={member.id}>
            <div
              className="flex items-center gap-4 px-5 py-3.5"
              style={{
                borderBottom:
                  (idx < members.length - 1 && !isExpanded)
                    ? "1px solid var(--color-border-light)"
                    : "none",
                cursor: canEditPerms ? "pointer" : undefined,
              }}
              onClick={() => canEditPerms && setExpandedPermissions(isExpanded ? null : member.profile_id)}
            >
              {/* Avatar */}
              <div
                className="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 font-bold text-xs overflow-hidden"
                style={{
                  background: profile?.avatar_url ? "transparent" : "var(--color-primary-light)",
                  color: "var(--color-primary)",
                }}
              >
                {profile?.avatar_url ? (
                  <img
                    src={profile.avatar_url}
                    alt={profile.name}
                    className="w-full h-full object-cover"
                  />
                ) : (
                  (profile?.name || "?").charAt(0).toUpperCase()
                )}
              </div>

              {/* Name + Email */}
              <div className="flex-1 min-w-0">
                <p className="font-medium text-sm truncate">
                  {profile?.name}
                  {isSelf && (
                    <span
                      className="text-xs ml-1.5"
                      style={{ color: "var(--color-muted-foreground)" }}
                    >
                      (Du)
                    </span>
                  )}
                </p>
                <p
                  className="text-xs truncate"
                  style={{ color: "var(--color-muted-foreground)" }}
                >
                  {profile?.email}
                </p>
              </div>

              {/* Rolle */}
              {!isSelf && !isSystemUser ? (
                <div className="flex items-center gap-1.5 flex-shrink-0">
                  <select
                    value={member.role_id}
                    onChange={(e) => {
                      e.stopPropagation();
                      handleRoleChange(member.profile_id, e.target.value);
                    }}
                    onClick={(e) => e.stopPropagation()}
                    className="px-2 py-1 rounded text-xs font-medium"
                    style={{
                      background: badgeStyle.bg,
                      color: badgeStyle.color,
                      border: "1px solid transparent",
                    }}
                  >
                    {roles.map((r) => (
                      <option key={r.id} value={r.id}>
                        {roleLabels[r.name] || r.name}
                      </option>
                    ))}
                  </select>
                </div>
              ) : isSystemUser ? (
                <RoleBadge role="superadmin" type="org" />
              ) : (
                <RoleBadge role={orgRoleName} type="org" />
              )}

              {/* Mitglied seit */}
              <span
                className="text-xs flex-shrink-0 hidden sm:block"
                style={{ color: "var(--color-muted-foreground)" }}
              >
                Seit{" "}
                {new Date(
                  member.approved_at || member.created_at
                ).toLocaleDateString("de-DE", {
                  month: "short",
                  year: "numeric",
                })}
              </span>

              {/* Impersonate */}
              {!isSelf && !isSystemUser && (
                <button
                  onClick={(e) => {
                    e.stopPropagation();
                    startImpersonating({
                      profileId: member.profile_id,
                      name: profile?.name || "",
                      roleName: orgRoleName,
                      permissions: getMemberPermissions(member),
                    });
                  }}
                  className="p-1.5 rounded transition-colors flex-shrink-0"
                  style={{ color: "var(--color-info)" }}
                  title={`App als ${profile?.name} anzeigen`}
                >
                  <IconEye size={15} />
                </button>
              )}

              {/* Active Toggle */}
              {!isSelf && !isLastAdmin && !isSystemUser && (
                <button
                  onClick={(e) => { e.stopPropagation(); handleToggleActive(member.profile_id, false); }}
                  disabled={processing === member.profile_id}
                  className="relative w-9 h-5 rounded-full flex-shrink-0 transition-colors disabled:opacity-50"
                  style={{ background: "var(--color-success)" }}
                  title="Aktiv -- klicken zum Deaktivieren"
                >
                  <span
                    className="absolute top-0.5 right-0.5 w-4 h-4 rounded-full bg-white transition-all"
                    style={{ boxShadow: "0 1px 2px rgba(0,0,0,0.2)" }}
                  />
                </button>
              )}

              {/* Remove */}
              {!isSelf && !isLastAdmin && !isSystemUser && (
                <button
                  onClick={(e) => { e.stopPropagation(); handleRemoveMember(member.profile_id, profile?.name || ""); }}
                  disabled={processing === member.profile_id}
                  className="p-1.5 rounded transition-colors flex-shrink-0 disabled:opacity-50"
                  style={{ color: "var(--color-destructive)" }}
                  title={profile?.name?.includes("(Test)") ? "Testuser komplett loeschen" : "Aus Organisation entfernen"}
                >
                  <IconTrash size={14} />
                </button>
              )}
            </div>

            {/* Berechtigungen (aufklappbar) */}
            {isExpanded && canEditPerms && (
              <div
                className="px-5 py-3"
                style={{
                  background: "var(--color-muted)",
                  borderBottom: idx < members.length - 1 ? "1px solid var(--color-border-light)" : "none",
                }}
                onClick={(e) => e.stopPropagation()}
              >
                <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-x-6 gap-y-3">
                  {permissionGroups.map((group) => (
                    <div key={group.label}>
                      <p className="text-xs font-semibold mb-1.5" style={{ color: "var(--color-muted-foreground)" }}>
                        {group.label}
                      </p>
                      {group.permissions.map(({ key, label }) => (
                        <label
                          key={key}
                          className="flex items-center gap-1.5 text-xs cursor-pointer select-none py-0.5"
                        >
                          <input
                            type="checkbox"
                            checked={memberPerms[key] ?? false}
                            onChange={() => handleTogglePermission(member, key)}
                            className="rounded"
                            style={{ accentColor: "var(--color-primary)" }}
                          />
                          {label}
                        </label>
                      ))}
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        );
  };

  return (
    <div className="space-y-6">
      {/* Aktive Mitglieder */}
      <div
        className="rounded-xl overflow-hidden"
        style={{
          background: "var(--color-surface)",
          border: "1px solid var(--color-border-light)",
        }}
      >
        {activeMembers.map((member, idx) => renderMemberRow(member, idx, activeMembers.length))}
      </div>

      {/* Ehemalige Mitglieder */}
      {inactiveMembers.length > 0 && (
        <div>
          <h3
            className="text-sm font-semibold mb-2 px-1"
            style={{ color: "var(--color-muted-foreground)" }}
          >
            Ehemalige Mitglieder ({inactiveMembers.length})
          </h3>
          <div
            className="rounded-xl overflow-hidden"
            style={{
              background: "var(--color-surface)",
              border: "1px solid var(--color-border-light)",
              opacity: 0.7,
            }}
          >
            {inactiveMembers.map((member, idx) => renderMemberRow(member, idx, inactiveMembers.length))}
          </div>
        </div>
      )}
    </div>
  );
}

// ══════════════════════════════════════════════
// ── Protokoll Tab ──
// ══════════════════════════════════════════════

function LogTab({
  entries,
  loading,
  hasMore,
  filterAction,
  setFilterAction,
  loadEntries,
}: {
  entries: ActivityLogEntry[];
  loading: boolean;
  hasMore: boolean;
  filterAction: string;
  setFilterAction: (v: string) => void;
  loadEntries: (append?: boolean) => void;
}) {
  return (
    <div className="space-y-4">
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
          <p className="text-lg mb-1">Noch keine Aktivitaeten</p>
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
            const icon = actionIcons[entry.action] || "\u{1F4CC}";
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
                            {meta.old_role} &rarr; {meta.new_role}
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
                            Stimme: {meta.vote === "approve" ? "Ja" : "Nein"}
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

// ══════════════════════════════════════════════
// ── Einladungs-Modal ──
// ══════════════════════════════════════════════

function InviteModal({
  orgId,
  roles,
  currentUserId,
  onClose,
  onInvited,
}: {
  orgId: string;
  roles: RoleOption[];
  currentUserId: string;
  onClose: () => void;
  onInvited: () => void;
}) {
  const supabase = createClient();
  const [email, setEmail] = useState("");
  const [selectedRoleId, setSelectedRoleId] = useState(roles.find((r) => r.name === "member")?.id || "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState(false);
  const [copiedLink, setCopiedLink] = useState(false);

  const inputStyle = {
    border: "1px solid var(--color-border)",
    background: "var(--color-background)",
  };

  async function handleInvite(e: React.FormEvent) {
    e.preventDefault();
    if (!email.trim()) return;
    setSaving(true);
    setError("");

    const { data: insertData, error: insertError } = await supabase
      .from("org_invitations")
      .insert({
        org_id: orgId,
        email: email.trim().toLowerCase(),
        invited_by: currentUserId,
        role_id: selectedRoleId,
      })
      .select("id")
      .single();

    if (insertError) {
      if (insertError.code === "23505") {
        setError("Diese E-Mail wurde bereits eingeladen.");
      } else {
        setError(insertError.message);
      }
    } else {
      if (insertData?.id) {
        supabase.functions.invoke("send-invite-email", {
          body: { invitation_id: insertData.id },
        }).catch((err) => console.error("Email send error:", err));
      }
      setSuccess(true);
      onInvited();
      if (orgId) logActivity({ orgId, action: "invitation.sent", entityType: "invitation", entityLabel: email.trim() });
    }
    setSaving(false);
  }

  function handleCopyLink() {
    const url = `${window.location.origin}/login`;
    navigator.clipboard.writeText(url);
    setCopiedLink(true);
    setTimeout(() => setCopiedLink(false), 2000);
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ background: "rgba(0,0,0,0.5)" }}
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div
        className="w-full max-w-md rounded-xl overflow-hidden"
        style={{ background: "var(--color-surface)", boxShadow: "var(--shadow-lg)" }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between px-6 py-4"
          style={{ borderBottom: "1px solid var(--color-border-light)" }}
        >
          <h2 className="text-lg font-bold">Mitglied einladen</h2>
          <button onClick={onClose} className="p-1.5 rounded-lg" style={{ color: "var(--color-muted-foreground)" }}>
            <IconX size={20} />
          </button>
        </div>

        {/* Body */}
        <div className="px-6 py-5">
          {success ? (
            <div className="text-center space-y-4">
              <div
                className="w-14 h-14 rounded-full flex items-center justify-center mx-auto"
                style={{ background: "var(--color-success-light)" }}
              >
                <IconCheck size={28} style={{ color: "var(--color-success)" }} />
              </div>
              <div>
                <p className="font-semibold">Einladung gesendet!</p>
                <p className="text-sm mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                  Eine Einladungs-Email wurde an <strong>{email}</strong> gesendet.
                </p>
              </div>
              <button
                onClick={handleCopyLink}
                className="w-full px-4 py-2.5 rounded-lg text-sm font-medium transition-colors"
                style={{
                  background: copiedLink ? "var(--color-success-light)" : "var(--color-primary)",
                  color: copiedLink ? "var(--color-success)" : "#fff",
                }}
              >
                {copiedLink ? "Link kopiert!" : "Registrierungs-Link kopieren"}
              </button>
              <button
                onClick={() => { setSuccess(false); setEmail(""); }}
                className="text-sm font-medium"
                style={{ color: "var(--color-primary)" }}
              >
                Weitere Person einladen
              </button>
            </div>
          ) : (
            <form onSubmit={handleInvite} className="space-y-4">
              <div>
                <label className="text-sm font-medium mb-1 block">E-Mail-Adresse</label>
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="name@example.com"
                  className="w-full rounded-lg px-3 py-2.5 text-sm"
                  style={inputStyle}
                  required
                  autoFocus
                />
              </div>
              <div>
                <label className="text-sm font-medium mb-1 block">Rolle</label>
                <select
                  value={selectedRoleId}
                  onChange={(e) => setSelectedRoleId(e.target.value)}
                  className="w-full rounded-lg px-3 py-2.5 text-sm"
                  style={inputStyle}
                >
                  {roles.map((r) => (
                    <option key={r.id} value={r.id}>
                      {orgRoleLabels[r.name] || r.name}
                    </option>
                  ))}
                </select>
              </div>
              {error && (
                <p className="text-sm" style={{ color: "var(--color-destructive)" }}>{error}</p>
              )}
              <button
                type="submit"
                disabled={saving}
                className="w-full px-4 py-2.5 rounded-lg text-sm font-medium transition-colors disabled:opacity-50"
                style={{ background: "var(--color-primary)", color: "#fff" }}
              >
                {saving ? "Wird gesendet..." : "Einladung senden"}
              </button>
            </form>
          )}
        </div>
      </div>
    </div>
  );
}

// ══════════════════════════════════════════════
// ── System Tab ──
// ══════════════════════════════════════════════

// ── Services & Debug Tab ──

type ServiceStatus = "checking" | "online" | "degraded" | "offline" | "unknown";

type ServiceCheck = {
  name: string;
  url: string;
  status: ServiceStatus;
  latency: number | null;
  detail: string;
  lastCheck: Date | null;
};

function ServicesTab({ orgId }: { orgId: string }) {
  const supabase = createClient();
  const [services, setServices] = useState<ServiceCheck[]>([
    { name: "Supabase Database", url: "", status: "checking", latency: null, detail: "", lastCheck: null },
    { name: "Supabase Auth", url: "", status: "checking", latency: null, detail: "", lastCheck: null },
    { name: "Supabase Realtime", url: "", status: "checking", latency: null, detail: "", lastCheck: null },
    { name: "Vercel App", url: "", status: "checking", latency: null, detail: "", lastCheck: null },
    { name: "CalDAV Proxy", url: "caldav-proxy.post-cd8.workers.dev", status: "checking", latency: null, detail: "", lastCheck: null },
    { name: "CalDAV Server", url: "", status: "checking", latency: null, detail: "", lastCheck: null },
    { name: "iCal Feed", url: "", status: "checking", latency: null, detail: "", lastCheck: null },
  ]);
  const [dbStats, setDbStats] = useState<{ table: string; count: number }[]>([]);
  const [calendarDebug, setCalendarDebug] = useState<{ groups: number; events: number; tokens: number; ctags: { name: string; ctag: number }[] }>({ groups: 0, events: 0, tokens: 0, ctags: [] });
  const [checking, setChecking] = useState(false);
  const [pushing, setPushing] = useState(false);
  const [pushResult, setPushResult] = useState<any>(null);
  const [debugResult, setDebugResult] = useState<any>(null);
  const [loadingDebug, setLoadingDebug] = useState(false);

  const updateService = useCallback((name: string, update: Partial<ServiceCheck>) => {
    setServices(prev => prev.map(s => s.name === name ? { ...s, ...update } : s));
  }, []);

  const runChecks = useCallback(async () => {
    setChecking(true);
    setServices(prev => prev.map(s => ({ ...s, status: "checking" as ServiceStatus, latency: null, detail: "" })));

    // 1. Supabase Database
    const dbStart = performance.now();
    try {
      const { count, error } = await supabase.from("organizations").select("*", { count: "exact", head: true });
      const lat = Math.round(performance.now() - dbStart);
      if (error) {
        updateService("Supabase Database", { status: "offline", latency: lat, detail: error.message, lastCheck: new Date() });
      } else {
        updateService("Supabase Database", { status: "online", latency: lat, detail: `${count ?? 0} Organisationen`, lastCheck: new Date() });
      }
    } catch (e: any) {
      updateService("Supabase Database", { status: "offline", latency: Math.round(performance.now() - dbStart), detail: e.message, lastCheck: new Date() });
    }

    // 2. Supabase Auth
    const authStart = performance.now();
    try {
      const { data, error } = await supabase.auth.getUser();
      const lat = Math.round(performance.now() - authStart);
      if (error) {
        updateService("Supabase Auth", { status: "degraded", latency: lat, detail: error.message, lastCheck: new Date() });
      } else {
        updateService("Supabase Auth", { status: "online", latency: lat, detail: `User: ${data.user?.email || "anonym"}`, lastCheck: new Date() });
      }
    } catch (e: any) {
      updateService("Supabase Auth", { status: "offline", latency: Math.round(performance.now() - authStart), detail: e.message, lastCheck: new Date() });
    }

    // 3. Supabase Realtime
    const rtStart = performance.now();
    try {
      const channel = supabase.channel("health-check");
      const sub = await new Promise<string>((resolve) => {
        const timeout = setTimeout(() => resolve("timeout"), 5000);
        channel.subscribe((status) => {
          clearTimeout(timeout);
          resolve(status);
        });
      });
      supabase.removeChannel(channel);
      const lat = Math.round(performance.now() - rtStart);
      if (sub === "SUBSCRIBED") {
        updateService("Supabase Realtime", { status: "online", latency: lat, detail: "WebSocket verbunden", lastCheck: new Date() });
      } else if (sub === "timeout") {
        updateService("Supabase Realtime", { status: "degraded", latency: lat, detail: "Timeout (>5s)", lastCheck: new Date() });
      } else {
        updateService("Supabase Realtime", { status: "degraded", latency: lat, detail: `Status: ${sub}`, lastCheck: new Date() });
      }
    } catch (e: any) {
      updateService("Supabase Realtime", { status: "offline", latency: Math.round(performance.now() - rtStart), detail: e.message, lastCheck: new Date() });
    }

    // 4. Vercel App
    const appStart = performance.now();
    try {
      const res = await fetch("/api/calendar/feed?health=1", { method: "HEAD" });
      const lat = Math.round(performance.now() - appStart);
      updateService("Vercel App", { status: res.ok || res.status === 400 ? "online" : "degraded", latency: lat, detail: `HTTP ${res.status}`, lastCheck: new Date() });
    } catch (e: any) {
      updateService("Vercel App", { status: "offline", latency: Math.round(performance.now() - appStart), detail: e.message, lastCheck: new Date() });
    }

    // 5. CalDAV Proxy (Cloudflare Worker)
    const cfStart = performance.now();
    try {
      const res = await fetch("https://caldav-proxy.post-cd8.workers.dev/", { method: "OPTIONS" });
      const lat = Math.round(performance.now() - cfStart);
      updateService("CalDAV Proxy", { status: res.ok || res.status === 405 || res.status === 200 ? "online" : "degraded", latency: lat, detail: `HTTP ${res.status}`, lastCheck: new Date() });
    } catch (e: any) {
      updateService("CalDAV Proxy", { status: "offline", latency: Math.round(performance.now() - cfStart), detail: e.message, lastCheck: new Date() });
    }

    // 6. CalDAV Server — check via token
    const caldavStart = performance.now();
    try {
      const { data: token } = await supabase
        .from("calendar_feed_tokens")
        .select("token")
        .eq("read_write", true)
        .limit(1)
        .maybeSingle();

      if (token?.token) {
        const res = await fetch(`/api/caldav/${token.token}/`, {
          method: "OPTIONS",
        });
        const lat = Math.round(performance.now() - caldavStart);
        const allow = res.headers.get("allow") || "";
        updateService("CalDAV Server", { status: res.ok ? "online" : "degraded", latency: lat, detail: allow ? `Methoden: ${allow}` : `HTTP ${res.status}`, lastCheck: new Date() });
      } else {
        updateService("CalDAV Server", { status: "unknown", latency: null, detail: "Kein CalDAV-Token vorhanden", lastCheck: new Date() });
      }
    } catch (e: any) {
      updateService("CalDAV Server", { status: "offline", latency: Math.round(performance.now() - caldavStart), detail: e.message, lastCheck: new Date() });
    }

    // 7. iCal Feed
    const feedStart = performance.now();
    try {
      const { data: token } = await supabase
        .from("calendar_feed_tokens")
        .select("token")
        .eq("read_write", false)
        .limit(1)
        .maybeSingle();

      if (token?.token) {
        const res = await fetch(`/api/calendar/feed?token=${token.token}`, { method: "HEAD" });
        const lat = Math.round(performance.now() - feedStart);
        updateService("iCal Feed", { status: res.ok ? "online" : "degraded", latency: lat, detail: `HTTP ${res.status}`, lastCheck: new Date() });
      } else {
        updateService("iCal Feed", { status: "unknown", latency: null, detail: "Kein Feed-Token", lastCheck: new Date() });
      }
    } catch (e: any) {
      updateService("iCal Feed", { status: "offline", latency: Math.round(performance.now() - feedStart), detail: e.message, lastCheck: new Date() });
    }

    // DB Stats
    if (orgId) {
      const tables = ["calendar_events", "calendar_groups", "calendar_feed_tokens", "projects", "inventory_items", "bookings", "cost_items", "org_memberships"];
      const stats: { table: string; count: number }[] = [];
      for (const table of tables) {
        const { count } = await supabase.from(table).select("*", { count: "exact", head: true });
        stats.push({ table, count: count ?? 0 });
      }
      setDbStats(stats);

      // Calendar debug info
      const [groupsRes, eventsRes, tokensRes] = await Promise.all([
        supabase.from("calendar_groups").select("name, ctag").eq("org_id", orgId),
        supabase.from("calendar_events").select("*", { count: "exact", head: true }).eq("org_id", orgId),
        supabase.from("calendar_feed_tokens").select("*", { count: "exact", head: true }),
      ]);
      setCalendarDebug({
        groups: groupsRes.data?.length ?? 0,
        events: eventsRes.count ?? 0,
        tokens: tokensRes.count ?? 0,
        ctags: (groupsRes.data || []).map(g => ({ name: g.name, ctag: g.ctag })),
      });
    }

    setChecking(false);
  }, [supabase, orgId, updateService]);

  useEffect(() => {
    runChecks();
  }, [runChecks]);

  const statusColors: Record<ServiceStatus, { bg: string; text: string; dot: string }> = {
    checking: { bg: "var(--color-muted)", text: "var(--color-muted-foreground)", dot: "var(--color-muted-foreground)" },
    online: { bg: "var(--color-success-light, #dcfce7)", text: "var(--color-success, #16a34a)", dot: "#22c55e" },
    degraded: { bg: "var(--color-warning-light, #fef9c3)", text: "var(--color-warning, #ca8a04)", dot: "#eab308" },
    offline: { bg: "var(--color-destructive-light, #fef2f2)", text: "var(--color-destructive, #dc2626)", dot: "#ef4444" },
    unknown: { bg: "var(--color-muted)", text: "var(--color-muted-foreground)", dot: "var(--color-muted-foreground)" },
  };

  const statusLabels: Record<ServiceStatus, string> = {
    checking: "Pruefe...",
    online: "Online",
    degraded: "Eingeschraenkt",
    offline: "Offline",
    unknown: "Unbekannt",
  };

  const sectionStyle: React.CSSProperties = {
    background: "var(--color-surface)",
    border: "1px solid var(--color-border)",
  };

  const allOnline = services.every(s => s.status === "online");
  const anyOffline = services.some(s => s.status === "offline");

  return (
    <div className="space-y-6">
      {/* Overall Status */}
      <div className="rounded-xl p-6" style={sectionStyle}>
        <div className="flex items-center justify-between mb-5">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl flex items-center justify-center text-lg"
              style={{ background: allOnline ? "#dcfce7" : anyOffline ? "#fef2f2" : "#fef9c3" }}>
              {checking ? "\u23F3" : allOnline ? "\u2705" : anyOffline ? "\u{1F6A8}" : "\u26A0\uFE0F"}
            </div>
            <div>
              <h2 className="text-lg font-bold">
                {checking ? "Services werden geprueft..." : allOnline ? "Alle Services online" : anyOffline ? "Service-Stoerung erkannt" : "Einschraenkungen erkannt"}
              </h2>
              <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                {services.filter(s => s.status === "online").length}/{services.length} Services erreichbar
              </p>
            </div>
          </div>
          <button
            onClick={runChecks}
            disabled={checking}
            className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors disabled:opacity-50"
            style={{ border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}
          >
            {checking ? "\u23F3" : "\u{1F504}"} Neu pruefen
          </button>
        </div>

        {/* Service List */}
        <div className="space-y-2">
          {services.map((s) => {
            const colors = statusColors[s.status];
            return (
              <div key={s.name} className="flex items-center gap-3 px-4 py-3 rounded-lg" style={{ background: "var(--color-muted)" }}>
                {/* Status Dot */}
                <div className="w-2.5 h-2.5 rounded-full flex-shrink-0" style={{
                  background: colors.dot,
                  animation: s.status === "checking" ? "pulse 1.5s infinite" : undefined,
                }} />
                {/* Name */}
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-semibold">{s.name}</p>
                  {s.detail && (
                    <p className="text-[11px] truncate" style={{ color: "var(--color-muted-foreground)" }}>{s.detail}</p>
                  )}
                </div>
                {/* Latency */}
                {s.latency !== null && (
                  <span className="text-[11px] font-mono flex-shrink-0 px-2 py-0.5 rounded" style={{
                    background: s.latency < 200 ? "#dcfce7" : s.latency < 500 ? "#fef9c3" : "#fef2f2",
                    color: s.latency < 200 ? "#16a34a" : s.latency < 500 ? "#ca8a04" : "#dc2626",
                  }}>
                    {s.latency}ms
                  </span>
                )}
                {/* Status Badge */}
                <span className="text-[11px] font-medium flex-shrink-0 px-2 py-0.5 rounded" style={{
                  background: colors.bg,
                  color: colors.text,
                }}>
                  {statusLabels[s.status]}
                </span>
              </div>
            );
          })}
        </div>
      </div>

      {/* Calendar Debug */}
      <div className="rounded-xl p-6" style={sectionStyle}>
        <h3 className="text-base font-bold mb-4">Kalender Debug</h3>
        <div className="grid grid-cols-3 gap-3 mb-4">
          {[
            { label: "Gruppen", value: calendarDebug.groups },
            { label: "Events", value: calendarDebug.events },
            { label: "Tokens", value: calendarDebug.tokens },
          ].map((item) => (
            <div key={item.label} className="text-center p-3 rounded-lg" style={{ background: "var(--color-muted)" }}>
              <p className="text-2xl font-bold" style={{ color: "var(--color-primary)" }}>{item.value}</p>
              <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>{item.label}</p>
            </div>
          ))}
        </div>

        {/* CTags */}
        {calendarDebug.ctags.length > 0 && (
          <div>
            <p className="text-xs font-bold mb-2" style={{ color: "var(--color-muted-foreground)" }}>CTags (Sync-Zaehler)</p>
            <div className="space-y-1">
              {calendarDebug.ctags.map((g) => (
                <div key={g.name} className="flex items-center justify-between px-3 py-2 rounded-lg text-xs" style={{ background: "var(--color-muted)" }}>
                  <span className="font-medium">{g.name}</span>
                  <span className="font-mono px-2 py-0.5 rounded" style={{ background: "var(--color-primary-light)", color: "var(--color-primary)" }}>
                    CTag: {g.ctag}
                  </span>
                </div>
              ))}
            </div>
            <p className="text-[11px] mt-2" style={{ color: "var(--color-muted-foreground)" }}>
              CTag erhoet sich bei jeder Aenderung. Apple Calendar vergleicht den CTag und synct nur bei Aenderung.
            </p>
          </div>
        )}

        {/* Push CalDAV + Debug */}
        <div className="mt-5 pt-4" style={{ borderTop: "1px solid var(--color-border-light)" }}>
          <div className="flex items-center gap-3 mb-3">
            <button
              onClick={async () => {
                setPushing(true);
                setPushResult(null);
                try {
                  const res = await fetch(`/api/calendar/debug?org_id=${orgId}&action=bump_ctags`, { method: "POST" });
                  const data = await res.json();
                  setPushResult(data);
                  // CTags neu laden
                  runChecks();
                } catch (e: any) {
                  setPushResult({ error: e.message });
                }
                setPushing(false);
              }}
              disabled={pushing}
              className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium text-white transition-colors disabled:opacity-50"
              style={{ background: "var(--color-primary)" }}
            >
              {pushing ? "\u23F3 Pushe..." : "\u{1F504} Force CTag Bump"}
            </button>
            <button
              onClick={async () => {
                setLoadingDebug(true);
                setDebugResult(null);
                try {
                  const res = await fetch(`/api/calendar/debug?org_id=${orgId}`);
                  const data = await res.json();
                  setDebugResult(data);
                } catch (e: any) {
                  setDebugResult({ error: e.message });
                }
                setLoadingDebug(false);
              }}
              disabled={loadingDebug}
              className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors disabled:opacity-50"
              style={{ border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}
            >
              {loadingDebug ? "\u23F3 Lade..." : "\u{1F50D} CalDAV Debug"}
            </button>
          </div>

          <p className="text-[11px] mb-3" style={{ color: "var(--color-muted-foreground)" }}>
            <strong>Force CTag Bump</strong> erhoet alle CTags und zwingt Apple Calendar zum Re-Sync (Cmd+R in Apple Calendar).
            <br />
            <strong>CalDAV Debug</strong> zeigt die letzten Events, iCal-Output und testet den CalDAV GET-Endpoint.
          </p>

          {/* Push Result */}
          {pushResult && (
            <div className="mb-3 p-3 rounded-lg text-xs" style={{
              background: pushResult.error ? "var(--color-destructive-light)" : "var(--color-success-light, #dcfce7)",
              color: pushResult.error ? "var(--color-destructive)" : "var(--color-success, #16a34a)",
            }}>
              {pushResult.error ? (
                <p>Fehler: {pushResult.error}</p>
              ) : (
                <div>
                  <p className="font-bold mb-1">CTags aktualisiert:</p>
                  {pushResult.results?.map((r: any) => (
                    <p key={r.name}>{r.success ? "\u2705" : "\u274C"} {r.name}: {r.oldCtag} → {r.newCtag}</p>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* Debug Result */}
          {debugResult && (
            <div className="space-y-3">
              {/* Letzte Events */}
              {debugResult.recentEvents?.length > 0 && (
                <div>
                  <p className="text-xs font-bold mb-1">Letzte Events:</p>
                  <div className="space-y-1">
                    {debugResult.recentEvents.map((e: any) => (
                      <div key={e.id} className="px-3 py-2 rounded-lg text-[11px] font-mono" style={{ background: "var(--color-muted)" }}>
                        <span className="font-bold">{e.summary}</span>
                        <span className="mx-2" style={{ color: "var(--color-muted-foreground)" }}>|</span>
                        <span>{e.all_day ? "Ganztaegig" : ""} {e.start_at?.split("T")[0]}</span>
                        <span className="mx-2" style={{ color: "var(--color-muted-foreground)" }}>|</span>
                        <span style={{ color: "var(--color-muted-foreground)" }}>UID: {e.caldav_uid?.substring(0, 12)}...</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* iCal Sample */}
              {debugResult.icalSample && (
                <div>
                  <p className="text-xs font-bold mb-1">iCal-Output (neuestes Event):</p>
                  <pre className="p-3 rounded-lg text-[11px] font-mono overflow-x-auto whitespace-pre-wrap" style={{ background: "var(--color-muted)", color: "var(--color-foreground)" }}>
                    {debugResult.icalSample}
                  </pre>
                </div>
              )}

              {/* CalDAV GET Test */}
              {debugResult.caldavGetTest && (
                <div>
                  <p className="text-xs font-bold mb-1">CalDAV GET Test:</p>
                  <div className="p-3 rounded-lg text-[11px] font-mono" style={{
                    background: debugResult.caldavGetTest.status === 200 ? "var(--color-success-light, #dcfce7)" : "var(--color-destructive-light)",
                    color: debugResult.caldavGetTest.status === 200 ? "var(--color-success, #16a34a)" : "var(--color-destructive)",
                  }}>
                    <p>HTTP {debugResult.caldavGetTest.status}</p>
                    <p className="truncate" style={{ color: "var(--color-muted-foreground)" }}>{debugResult.caldavGetTest.url}</p>
                    {debugResult.caldavGetTest.body && (
                      <pre className="mt-1 whitespace-pre-wrap text-[10px]" style={{ color: "var(--color-foreground)" }}>
                        {debugResult.caldavGetTest.body}
                      </pre>
                    )}
                  </div>
                </div>
              )}
            </div>
          )}
        </div>
      </div>

      {/* DB Stats */}
      {dbStats.length > 0 && (
        <div className="rounded-xl p-6" style={sectionStyle}>
          <h3 className="text-base font-bold mb-4">Datenbank-Statistiken</h3>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
            {dbStats.map((s) => (
              <div key={s.table} className="p-3 rounded-lg" style={{ background: "var(--color-muted)" }}>
                <p className="text-lg font-bold" style={{ color: "var(--color-primary)" }}>{s.count}</p>
                <p className="text-[11px] truncate" style={{ color: "var(--color-muted-foreground)" }}>
                  {s.table.replace(/_/g, " ").replace("calendar ", "cal. ")}
                </p>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Env & Config */}
      <div className="rounded-xl p-6" style={sectionStyle}>
        <h3 className="text-base font-bold mb-4">Konfiguration</h3>
        <div className="space-y-1">
          {[
            { key: "SUPABASE_URL", value: process.env.NEXT_PUBLIC_SUPABASE_URL || "–", masked: false },
            { key: "SUPABASE_ANON_KEY", value: process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY ? "***" + process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY.slice(-8) : "–", masked: true },
            { key: "Org-ID", value: orgId || "–", masked: false },
            { key: "Umgebung", value: typeof window !== "undefined" && window.location.hostname === "localhost" ? "Development" : "Production", masked: false },
            { key: "Host", value: typeof window !== "undefined" ? window.location.host : "–", masked: false },
          ].map((item) => (
            <div key={item.key} className="flex items-center gap-3 px-3 py-2 rounded-lg text-xs" style={{ background: "var(--color-muted)" }}>
              <span className="font-bold w-36 flex-shrink-0">{item.key}</span>
              <code className="font-mono text-[11px] truncate" style={{ color: "var(--color-muted-foreground)" }}>{item.value}</code>
            </div>
          ))}
        </div>
      </div>

      <style>{`
        @keyframes pulse {
          0%, 100% { opacity: 1; }
          50% { opacity: 0.3; }
        }
      `}</style>
    </div>
  );
}

function SecurityToggleSection() {
  const supabase = createClient();
  const currentUser = useCurrentUser();
  const [mfaEnabled, setMfaEnabled] = useState<boolean | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let active = true;
    (async () => {
      const { data } = await supabase
        .from("app_settings")
        .select("mfa_enabled")
        .eq("id", true)
        .maybeSingle();
      if (active) setMfaEnabled(data?.mfa_enabled ?? false);
    })();
    return () => {
      active = false;
    };
  }, [supabase]);

  if (!currentUser?.isSuperadmin) return null;
  if (mfaEnabled === null) return null;

  const toggle = async () => {
    const next = !mfaEnabled;
    if (next) {
      const ok = await appConfirm(
        "2FA fuer alle nicht-Superadmin User aktivieren? Sie werden beim naechsten Login zur Einrichtung gezwungen.",
        { title: "2FA global aktivieren?", confirmLabel: "Aktivieren" }
      );
      if (!ok) return;
    }
    setSaving(true);
    const { error } = await supabase
      .from("app_settings")
      .update({ mfa_enabled: next })
      .eq("id", true);
    setSaving(false);
    if (error) {
      showToast(`Fehler: ${error.message}`, "error");
      return;
    }
    setMfaEnabled(next);
    showToast(next ? "2FA aktiviert" : "2FA deaktiviert", "success");
  };

  const sectionStyle: React.CSSProperties = {
    background: "var(--color-surface)",
    border: "1px solid var(--color-border)",
  };

  return (
    <div className="rounded-xl p-6" style={sectionStyle}>
      <div className="flex items-start justify-between gap-4">
        <div className="flex-1">
          <div className="flex items-center gap-2 mb-1">
            <IconShield size={18} />
            <h3 className="text-base font-bold">2FA / Zwei-Faktor-Authentifizierung</h3>
          </div>
          <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            {mfaEnabled
              ? "Aktiv: Alle nicht-Superadmin User muessen TOTP einrichten und beim Login bestaetigen."
              : "Deaktiviert (Entwicklungsmodus): Login funktioniert ohne zweiten Faktor. Superadmins sind generell ausgenommen."}
          </p>
        </div>
        <button
          onClick={toggle}
          disabled={saving}
          className="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none disabled:opacity-50"
          style={{ background: mfaEnabled ? "var(--color-primary)" : "var(--color-muted)" }}
          aria-pressed={mfaEnabled}
          title={mfaEnabled ? "2FA deaktivieren" : "2FA aktivieren"}
        >
          <span
            className="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
            style={{ transform: mfaEnabled ? "translateX(20px)" : "translateX(0)" }}
          />
        </button>
      </div>
    </div>
  );
}

function SystemTab() {
  const techStack = [
    { name: "Next.js", version: "16.1", desc: "App Router + Turbopack" },
    { name: "React", version: "19", desc: "UI Components" },
    { name: "TypeScript", version: "5.9", desc: "Type Safety" },
    { name: "Supabase", version: "", desc: "Auth, PostgreSQL, Realtime, Storage" },
    { name: "Tailwind CSS", version: "4.2", desc: "Styling" },
    { name: "Vercel", version: "", desc: "Hosting & Deployment" },
    { name: "Cloudflare Worker", version: "", desc: "CalDAV-Proxy" },
  ];

  const deploySteps = [
    {
      title: "App (Vercel)",
      icon: "\u25B2",
      desc: "Automatisch bei Push auf main",
      steps: [
        "Code committen: git add . && git commit -m \"...\"",
        "Pushen: git push origin main",
        "Vercel baut und deployed automatisch",
        "Status: vercel.com/dashboard",
      ],
    },
    {
      title: "CalDAV-Proxy (Cloudflare Worker)",
      icon: "\u2601\uFE0F",
      desc: "Manuell deployen wenn Worker-Code geaendert wird",
      steps: [
        "cd cloudflare-caldav-proxy",
        "npx wrangler deploy",
        "Worker URL: caldav-proxy.post-cd8.workers.dev",
        "Wird benoetigt weil Vercel PROPFIND/REPORT blockt",
      ],
    },
    {
      title: "Datenbank (Supabase)",
      icon: "\u{1F5C4}\uFE0F",
      desc: "Migrationen manuell ausfuehren",
      steps: [
        "SQL-Datei in supabase/migrations/ erstellen",
        "Im Supabase Dashboard → SQL Editor ausfuehren",
        "Oder: supabase db push (wenn CLI eingerichtet)",
        "Aktuell: 53 Migrationen",
      ],
    },
  ];

  const features = [
    { name: "Projekte", desc: "Verwaltung mit Status-Workflow, 8 Tabs, Budget, Dateien" },
    { name: "Kalender", desc: "Monats-/Wochenansicht, CalDAV Zwei-Wege-Sync, iCal-Feed" },
    { name: "Inventar", desc: "Equipment mit Kategorien, Einzelstuecke, Fotos, Excel-Import" },
    { name: "Anfragen", desc: "Pipeline von Erstanfrage bis Angebot, Team-Verfuegbarkeit" },
    { name: "Kosten", desc: "Pro Projekt + globale Uebersicht, USt-Saetze, Budget vs. Ist" },
    { name: "Team", desc: "Multi-Tenant, Rollen, Permissions, Einladungen, Impersonation" },
  ];

  const sectionStyle: React.CSSProperties = {
    background: "var(--color-surface)",
    border: "1px solid var(--color-border)",
  };

  return (
    <div className="space-y-6">
      {/* Security: 2FA Toggle (Superadmin only) */}
      <SecurityToggleSection />

      {/* App Info */}
      <div className="rounded-xl p-6" style={sectionStyle}>
        <div className="flex items-center gap-3 mb-4">
          <div className="w-10 h-10 rounded-xl flex items-center justify-center text-lg font-bold text-white"
            style={{ background: "var(--color-primary)" }}>
            <IconZap size={22} />
          </div>
          <div>
            <h2 className="text-lg font-bold">Project Prepper</h2>
            <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
              Projektmanagement fuer Veranstaltungstechnik
            </p>
          </div>
        </div>

        {/* Feature-Liste */}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-4">
          {features.map((f) => (
            <div key={f.name} className="flex items-start gap-2.5 p-2.5 rounded-lg" style={{ background: "var(--color-muted)" }}>
              <span className="text-xs font-bold mt-0.5" style={{ color: "var(--color-primary)" }}>•</span>
              <div>
                <p className="text-sm font-semibold">{f.name}</p>
                <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>{f.desc}</p>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Tech Stack */}
      <div className="rounded-xl p-6" style={sectionStyle}>
        <h3 className="text-base font-bold mb-4">Tech Stack</h3>
        <div className="space-y-2">
          {techStack.map((t) => (
            <div key={t.name} className="flex items-center gap-3 px-3 py-2 rounded-lg" style={{ background: "var(--color-muted)" }}>
              <span className="text-sm font-bold w-40">{t.name} {t.version && <span className="font-normal text-xs" style={{ color: "var(--color-primary)" }}>{t.version}</span>}</span>
              <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>{t.desc}</span>
            </div>
          ))}
        </div>
      </div>

      {/* Deployment */}
      <div className="rounded-xl p-6" style={sectionStyle}>
        <h3 className="text-base font-bold mb-4">Deployment</h3>
        <div className="space-y-4">
          {deploySteps.map((d) => (
            <div key={d.title} className="rounded-lg overflow-hidden" style={{ border: "1px solid var(--color-border-light)" }}>
              <div className="flex items-center gap-3 px-4 py-3" style={{ background: "var(--color-muted)" }}>
                <span className="text-lg">{d.icon}</span>
                <div>
                  <p className="text-sm font-bold">{d.title}</p>
                  <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>{d.desc}</p>
                </div>
              </div>
              <div className="px-4 py-3">
                <ol className="space-y-1.5">
                  {d.steps.map((step, i) => (
                    <li key={i} className="flex items-start gap-2 text-xs">
                      <span className="font-bold flex-shrink-0 w-4 text-right" style={{ color: "var(--color-primary)" }}>{i + 1}.</span>
                      <code className="font-mono text-[11px] break-all" style={{ color: "var(--color-foreground)" }}>{step}</code>
                    </li>
                  ))}
                </ol>
              </div>
            </div>
          ))}
        </div>

        {/* Zusammenfassung */}
        <div className="mt-4 p-3 rounded-lg text-xs" style={{ background: "var(--color-primary-light)", border: "1px solid var(--color-primary)", color: "var(--color-primary)" }}>
          <strong>Kurzfassung:</strong> git push origin main deployed die App automatisch auf Vercel.
          CalDAV-Proxy nur bei Aenderungen am Worker manuell deployen (npx wrangler deploy).
          Datenbank-Migrationen im Supabase SQL Editor ausfuehren.
        </div>
      </div>

      {/* Architektur */}
      <div className="rounded-xl p-6" style={sectionStyle}>
        <h3 className="text-base font-bold mb-4">Architektur</h3>
        <pre className="text-[11px] font-mono p-4 rounded-lg overflow-x-auto leading-relaxed" style={{ background: "var(--color-muted)", color: "var(--color-foreground)" }}>
{`src/
├── app/                 # Next.js App Router (Pages & API Routes)
│   ├── (auth)/          # Login, Pending
│   ├── (dashboard)/     # Geschuetzte Seiten (Sidebar-Layout)
│   ├── api/caldav/      # CalDAV-Server (Zwei-Wege-Sync)
│   └── api/calendar/    # iCal-Feed (Nur-Lesen)
├── components/          # React Components
├── contexts/            # Org-Context, Impersonation
├── hooks/               # Auth, Realtime, Presence
├── lib/                 # Supabase Client, CalDAV-Server
│   └── caldav/          # Auth, Handlers, iCal, XML
└── middleware.ts        # Auth Guard

supabase/migrations/     # 53 SQL-Migrationen
cloudflare-caldav-proxy/ # PROPFIND/REPORT → POST Proxy`}
        </pre>
      </div>

      {/* CalDAV Info */}
      <div className="rounded-xl p-6" style={sectionStyle}>
        <h3 className="text-base font-bold mb-4">CalDAV-Server</h3>
        <div className="space-y-3 text-sm">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
            {[
              { label: "Protokoll", value: "PROPFIND, REPORT, GET, PUT, DELETE" },
              { label: "Auth", value: "Token in URL + HTTP Basic Auth" },
              { label: "Sync", value: "ETag (Event) + CTag (Collection)" },
              { label: "Proxy", value: "Cloudflare Worker (Vercel blockt non-standard Methoden)" },
              { label: "Clients", value: "Apple Calendar, Thunderbird, DAVx5, Outlook (Plugin)" },
              { label: "Feed", value: "iCal-Abo (Nur-Lesen) fuer Google Calendar, Outlook Web" },
            ].map((item) => (
              <div key={item.label} className="p-2.5 rounded-lg" style={{ background: "var(--color-muted)" }}>
                <p className="text-xs font-bold" style={{ color: "var(--color-primary)" }}>{item.label}</p>
                <p className="text-xs mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>{item.value}</p>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

// ── E-Mail Konfiguration Tab (Thunderbird-Style) ──

const securityOptions = [
  { value: "none", label: "Keine" },
  { value: "ssl", label: "SSL/TLS" },
  { value: "starttls", label: "STARTTLS" },
];

const authOptions = [
  { value: "auto", label: "Automatisch erkennen" },
  { value: "plain", label: "Passwort, normal (PLAIN)" },
  { value: "login", label: "Passwort (LOGIN)" },
  { value: "cram-md5", label: "CRAM-MD5" },
];

function EmailConfigTab({ ownerId, userEmail }: { ownerId: string; userEmail: string }) {
  const supabase = createClient();
  // SMTP (Postausgang)
  const [smtpHost, setSmtpHost] = useState("");
  const [smtpPort, setSmtpPort] = useState(587);
  const [smtpUser, setSmtpUser] = useState("");
  const [smtpPass, setSmtpPass] = useState("");
  const [smtpSecurity, setSmtpSecurity] = useState("starttls");
  const [smtpAuth, setSmtpAuth] = useState("auto");
  const [senderEmail, setSenderEmail] = useState("");
  const [senderName, setSenderName] = useState("");
  const [bccEmail, setBccEmail] = useState("");
  const [smtpEnabled, setSmtpEnabled] = useState(false);
  // IMAP (Posteingang)
  const [imapHost, setImapHost] = useState("");
  const [imapPort, setImapPort] = useState(993);
  const [imapUser, setImapUser] = useState("");
  const [imapPass, setImapPass] = useState("");
  const [imapSecurity, setImapSecurity] = useState("ssl");
  const [imapAuth, setImapAuth] = useState("auto");
  const [imapEnabled, setImapEnabled] = useState(false);
  // UI
  const [saving, setSaving] = useState(false);
  const [success, setSuccess] = useState("");
  const [error, setError] = useState("");
  const [testing, setTesting] = useState(false);
  const [showSmtpPass, setShowSmtpPass] = useState(false);
  const [showImapPass, setShowImapPass] = useState(false);
  const [configId, setConfigId] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    if (!ownerId) return;
    supabase
      .from("org_email_config")
      .select("*")
      .eq("owner_profile_id", ownerId)
      .maybeSingle()
      .then(({ data }) => {
        if (data) {
          const c = data as OrgEmailConfig;
          setConfigId(c.id);
          setSmtpHost(c.smtp_host); setSmtpPort(c.smtp_port);
          setSmtpUser(c.smtp_user); setSmtpPass(c.smtp_pass);
          setSmtpSecurity(c.smtp_security || "starttls");
          setSmtpAuth(c.smtp_auth || "auto");
          setSenderEmail(c.sender_email); setSenderName(c.sender_name);
          setBccEmail(c.bcc_email || "");
          setSmtpEnabled(c.is_enabled);
          setImapHost(c.imap_host || ""); setImapPort(c.imap_port || 993);
          setImapUser(c.imap_user || ""); setImapPass(c.imap_pass || "");
          setImapSecurity(c.imap_security || "ssl");
          setImapAuth(c.imap_auth || "auto");
          setImapEnabled(c.imap_enabled || false);
        }
        setLoaded(true);
      });
  }, [ownerId, supabase]);

  async function handleSave() {
    setSaving(true); setError(""); setSuccess("");
    const payload = {
      owner_profile_id: ownerId,
      smtp_host: smtpHost.trim(), smtp_port: smtpPort,
      smtp_user: smtpUser.trim(), smtp_pass: smtpPass,
      smtp_security: smtpSecurity, smtp_auth: smtpAuth,
      sender_email: senderEmail.trim(), sender_name: senderName.trim(),
      bcc_email: bccEmail.trim(), is_enabled: smtpEnabled,
      imap_host: imapHost.trim(), imap_port: imapPort,
      imap_user: imapUser.trim(), imap_pass: imapPass,
      imap_security: imapSecurity, imap_auth: imapAuth,
      imap_enabled: imapEnabled,
    };
    if (configId) {
      const { error: err } = await supabase.from("org_email_config").update(payload).eq("id", configId);
      if (err) setError(err.message);
      else { setSuccess("Gespeichert"); setTimeout(() => setSuccess(""), 3000); }
    } else {
      const { data, error: err } = await supabase.from("org_email_config").insert(payload).select("id").single();
      if (err) setError(err.message);
      else { setConfigId(data?.id || null); setSuccess("Gespeichert"); setTimeout(() => setSuccess(""), 3000); }
    }
    setSaving(false);
  }

  async function handleTest() {
    setTesting(true); setError(""); setSuccess("");
    await handleSave();
    try {
      const { data, error: fnError } = await supabase.functions.invoke("test-smtp", {
        body: { owner_id: ownerId, test_email: userEmail },
      });
      if (fnError) setError(`Test fehlgeschlagen: ${fnError.message}`);
      else if (data?.error) setError(data.error);
      else { setSuccess(data?.message || "Test-Email gesendet!"); setTimeout(() => setSuccess(""), 5000); }
    } catch (err) {
      setError(`Fehler: ${(err as Error).message}`);
    }
    setTesting(false);
  }

  const inputStyle = { background: "var(--color-muted)", color: "var(--color-foreground)", border: "1px solid var(--color-border)" };
  const selectStyle = { ...inputStyle, appearance: "auto" as const };
  const labelStyle = { color: "var(--color-muted-foreground)" };
  const sectionHeaderStyle = {
    background: "var(--color-primary)",
    color: "var(--color-primary-foreground, #fff)",
    padding: "6px 12px",
    borderRadius: "6px",
    fontSize: "11px",
    fontWeight: 700 as const,
    letterSpacing: "0.05em",
    textTransform: "uppercase" as const,
    display: "inline-block",
  };

  if (!loaded) {
    return <div className="py-12 text-center text-sm" style={{ color: "var(--color-muted-foreground)" }}>Laden...</div>;
  }

  return (
    <div className="max-w-2xl space-y-6">
      {/* Aktivierung + Absender */}
      <div className="rounded-xl p-6" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}>
        <div className="flex items-center gap-2 mb-4">
          <IconMail size={20} style={{ color: "var(--color-primary)" }} />
          <h2 className="text-lg font-semibold" style={{ color: "var(--color-foreground)" }}>E-Mail Einstellungen</h2>
        </div>

        <label className="flex items-center gap-3 cursor-pointer mb-5">
          <div
            className="relative w-10 h-6 rounded-full transition-colors cursor-pointer"
            style={{ background: smtpEnabled ? "var(--color-primary)" : "var(--color-muted)" }}
            onClick={() => setSmtpEnabled(!smtpEnabled)}
          >
            <div className="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform" style={{ left: smtpEnabled ? 18 : 2 }} />
          </div>
          <span className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>E-Mail-Versand aktiviert</span>
        </label>

        <div className="grid grid-cols-2 gap-3 mb-3">
          <div>
            <label className="block text-xs font-medium mb-1" style={labelStyle}>Absender-Name</label>
            <input type="text" value={senderName} onChange={(e) => setSenderName(e.target.value)} placeholder="Project Prepper" className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={labelStyle}>Absender-Email</label>
            <input type="email" value={senderEmail} onChange={(e) => setSenderEmail(e.target.value)} placeholder="noreply@example.com" className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} />
          </div>
        </div>
        <div>
          <label className="block text-xs font-medium mb-1" style={labelStyle}>BCC (Blindkopie an)</label>
          <input type="email" value={bccEmail} onChange={(e) => setBccEmail(e.target.value)} placeholder="archiv@example.com" className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} />
          <p className="text-xs mt-1" style={labelStyle}>Jede ausgehende Email wird als Blindkopie an diese Adresse gesendet.</p>
        </div>
      </div>

      {/* Posteingangs-Server (IMAP) */}
      <div className="rounded-xl p-6" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}>
        <div className="flex items-center gap-3 mb-5">
          <span style={sectionHeaderStyle}>Posteingangs-Server</span>
          <label className="flex items-center gap-2 cursor-pointer ml-auto">
            <div
              className="relative w-9 h-5 rounded-full transition-colors cursor-pointer"
              style={{ background: imapEnabled ? "var(--color-primary)" : "var(--color-muted)" }}
              onClick={() => setImapEnabled(!imapEnabled)}
            >
              <div className="absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform" style={{ left: imapEnabled ? 16 : 2 }} />
            </div>
            <span className="text-xs" style={labelStyle}>Aktiviert</span>
          </label>
        </div>

        <div className="space-y-3">
          {/* Protokoll */}
          <div className="grid grid-cols-[140px_1fr] gap-3 items-center">
            <label className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>Protokoll:</label>
            <select disabled className="px-3 py-2 rounded-lg text-sm" style={selectStyle}>
              <option>IMAP</option>
            </select>
          </div>

          {/* Hostname */}
          <div className="grid grid-cols-[140px_1fr] gap-3 items-center">
            <label className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>Hostname:</label>
            <input type="text" value={imapHost} onChange={(e) => setImapHost(e.target.value)} placeholder="imap.example.com" className="px-3 py-2 rounded-lg text-sm" style={inputStyle} />
          </div>

          {/* Port */}
          <div className="grid grid-cols-[140px_1fr] gap-3 items-center">
            <label className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>Port:</label>
            <input type="number" value={imapPort} onChange={(e) => setImapPort(parseInt(e.target.value) || 993)} className="w-24 px-3 py-2 rounded-lg text-sm" style={inputStyle} />
          </div>

          {/* Verbindungssicherheit */}
          <div className="grid grid-cols-[140px_1fr] gap-3 items-center">
            <label className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>Sicherheit:</label>
            <select value={imapSecurity} onChange={(e) => setImapSecurity(e.target.value)} className="px-3 py-2 rounded-lg text-sm" style={selectStyle}>
              {securityOptions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          </div>

          {/* Authentifizierung */}
          <div className="grid grid-cols-[140px_1fr] gap-3 items-center">
            <label className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>Authentifizierung:</label>
            <select value={imapAuth} onChange={(e) => setImapAuth(e.target.value)} className="px-3 py-2 rounded-lg text-sm" style={selectStyle}>
              {authOptions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          </div>

          {/* Benutzername */}
          <div className="grid grid-cols-[140px_1fr] gap-3 items-center">
            <label className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>Benutzername:</label>
            <input type="text" value={imapUser} onChange={(e) => setImapUser(e.target.value)} placeholder="user@example.com" className="px-3 py-2 rounded-lg text-sm" style={inputStyle} />
          </div>

          {/* Passwort */}
          <div className="grid grid-cols-[140px_1fr] gap-3 items-center">
            <label className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>Passwort:</label>
            <div className="relative">
              <input type={showImapPass ? "text" : "password"} value={imapPass} onChange={(e) => setImapPass(e.target.value)} placeholder="••••••••" className="w-full px-3 py-2 pr-10 rounded-lg text-sm" style={inputStyle} />
              <button type="button" onClick={() => setShowImapPass(!showImapPass)} className="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded hover:opacity-70" style={{ color: "var(--color-muted-foreground)" }}>
                <IconEye size={16} />
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Postausgangs-Server (SMTP) */}
      <div className="rounded-xl p-6" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}>
        <div className="mb-5">
          <span style={sectionHeaderStyle}>Postausgangs-Server</span>
        </div>

        <div className="space-y-3">
          {/* Hostname */}
          <div className="grid grid-cols-[140px_1fr] gap-3 items-center">
            <label className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>Hostname:</label>
            <input type="text" value={smtpHost} onChange={(e) => setSmtpHost(e.target.value)} placeholder="smtp.example.com" className="px-3 py-2 rounded-lg text-sm" style={inputStyle} />
          </div>

          {/* Port */}
          <div className="grid grid-cols-[140px_1fr] gap-3 items-center">
            <label className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>Port:</label>
            <input type="number" value={smtpPort} onChange={(e) => setSmtpPort(parseInt(e.target.value) || 587)} className="w-24 px-3 py-2 rounded-lg text-sm" style={inputStyle} />
          </div>

          {/* Verbindungssicherheit */}
          <div className="grid grid-cols-[140px_1fr] gap-3 items-center">
            <label className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>Sicherheit:</label>
            <select value={smtpSecurity} onChange={(e) => setSmtpSecurity(e.target.value)} className="px-3 py-2 rounded-lg text-sm" style={selectStyle}>
              {securityOptions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          </div>

          {/* Authentifizierung */}
          <div className="grid grid-cols-[140px_1fr] gap-3 items-center">
            <label className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>Authentifizierung:</label>
            <select value={smtpAuth} onChange={(e) => setSmtpAuth(e.target.value)} className="px-3 py-2 rounded-lg text-sm" style={selectStyle}>
              {authOptions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          </div>

          {/* Benutzername */}
          <div className="grid grid-cols-[140px_1fr] gap-3 items-center">
            <label className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>Benutzername:</label>
            <input type="text" value={smtpUser} onChange={(e) => setSmtpUser(e.target.value)} placeholder="user@example.com" className="px-3 py-2 rounded-lg text-sm" style={inputStyle} />
          </div>

          {/* Passwort */}
          <div className="grid grid-cols-[140px_1fr] gap-3 items-center">
            <label className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>Passwort:</label>
            <div className="relative">
              <input type={showSmtpPass ? "text" : "password"} value={smtpPass} onChange={(e) => setSmtpPass(e.target.value)} placeholder="••••••••" className="w-full px-3 py-2 pr-10 rounded-lg text-sm" style={inputStyle} />
              <button type="button" onClick={() => setShowSmtpPass(!showSmtpPass)} className="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded hover:opacity-70" style={{ color: "var(--color-muted-foreground)" }}>
                <IconEye size={16} />
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Messages + Buttons */}
      {error && (
        <div className="text-sm px-4 py-3 rounded-xl" style={{ background: "var(--color-destructive-light)", color: "var(--color-destructive)" }}>
          {error}
        </div>
      )}
      {success && (
        <div className="flex items-center gap-2 text-sm px-4 py-3 rounded-xl" style={{ background: "var(--color-success-light)", color: "var(--color-success)" }}>
          <IconCheck size={14} /> {success}
        </div>
      )}

      <div className="flex gap-3">
        <button onClick={handleSave} disabled={saving} className="px-5 py-2.5 rounded-lg text-sm font-medium text-white disabled:opacity-50" style={{ background: "var(--color-primary)" }}>
          {saving ? "Speichern..." : "Speichern"}
        </button>
        <button onClick={handleTest} disabled={testing || !smtpHost || !smtpUser} className="px-5 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50" style={{ border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}>
          {testing ? "Teste..." : "Verbindung testen"}
        </button>
      </div>
    </div>
  );
}
