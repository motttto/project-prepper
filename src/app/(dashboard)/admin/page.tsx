"use client";

import { useState, useEffect, useCallback } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useOrg } from "@/contexts/org-context";
import { useImpersonate } from "@/contexts/impersonate-context";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type { UserPermissions, PermissionKey, ActivityLogEntry, ActivityAction } from "@/types/database";
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
  "member.approved": "wurde freigeschaltet",
  "member.role_changed": "Rolle geaendert",
  "member.deactivated": "wurde deaktiviert",
  "member.reactivated": "wurde reaktiviert",
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

  const [activeTab, setActiveTab] = useState<"roles" | "log" | "system">("roles");

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

  const isAdmin = currentUser?.roleName === "admin" || currentUser?.isSystem;

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
    if (!orgId) return;
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
  const activeMembers = members.filter((m) => m.is_active);

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
  const tabs: { key: "roles" | "log" | "system"; label: string }[] = [
    { key: "roles", label: "Rollen & Berechtigungen" },
    { key: "log", label: "Protokoll" },
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
          members={activeMembers}
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
  if (members.length === 0) {
    return (
      <div
        className="py-12 text-center rounded-xl"
        style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}
      >
        <p className="text-lg mb-1">Keine aktiven Mitglieder</p>
      </div>
    );
  }

  return (
    <div
      className="rounded-xl overflow-hidden"
      style={{
        background: "var(--color-surface)",
        border: "1px solid var(--color-border-light)",
      }}
    >
      {members.map((member, idx) => {
        const profile = member.profiles;
        const orgRoleName = getRoleName(member);
        const badgeStyle = roleBadgeStyles[orgRoleName] || roleBadgeStyles.member;
        const isSelf = currentUser?.id === member.profile_id;
        const isSystemUser = profile?.is_system;
        const isLastAdmin =
          orgRoleName === "admin" &&
          members.filter((m) => getRoleName(m) === "admin").length <= 1;
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
                <RoleBadgeGroup badges={[
                  { role: "system", type: "org" },
                  { role: orgRoleName, type: "org" },
                ]} />
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
      })}
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
