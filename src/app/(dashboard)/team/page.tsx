"use client";

import { useState, useEffect, useCallback } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import { useInvitations } from "@/hooks/use-invitations";
import { useOrg } from "@/contexts/org-context";
import type { TeamVote, InventoryItem, OrgInvitation, OrgGuest, UserPermissions } from "@/types/database";
import {
  IconUsers,
  IconUserPlus,
  IconClock,
  IconCheck,
  IconX,
  IconShield,
  IconInventory,
  IconMail,
  IconPlus,
  IconTrash,
} from "@/components/ui/icons";
import {
  RoleBadge,
  RoleBadgeGroup,
  orgRoleLabels,
} from "@/components/ui/role-badge";
import { DecisionPanel } from "@/components/decisions/decision-panel";
import { ExitSettlementWizard } from "@/components/team/exit-settlement-wizard";
import { showToast } from "@/hooks/use-toast";
import { logActivity } from "@/lib/activity-log";
import { appConfirm, appAlert } from "@/components/ui/confirm-dialog";

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

export default function TeamPage() {
  const supabase = createClient();
  const router = useRouter();
  const currentUser = useCurrentUser();
  const { orgId } = useOrg();
  const { invitations, accept: acceptInvitation, decline: declineInvitation, pendingCount: invitationCount } = useInvitations(currentUser?.id);

  const [members, setMembers] = useState<OrgMember[]>([]);
  const [votes, setVotes] = useState<TeamVote[]>([]);
  const [roles, setRoles] = useState<RoleOption[]>([]);
  const [inventoryItems, setInventoryItems] = useState<InventoryItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState<string | null>(null);
  const [showInactive, setShowInactive] = useState(false);
  const [invProcessing, setInvProcessing] = useState<string | null>(null);
  // Impersonation jetzt in /admin
  const [showInviteModal, setShowInviteModal] = useState(false);
  const [orgInvitations, setOrgInvitations] = useState<OrgInvitation[]>([]);
  // expandedPermissions entfernt — jetzt in /admin
  const [exitMember, setExitMember] = useState<{ id: string; name: string } | null>(null);

  // Gäste
  const [orgGuests, setOrgGuests] = useState<OrgGuest[]>([]);
  const [showGuestForm, setShowGuestForm] = useState(false);
  const [guestName, setGuestName] = useState("");
  const [guestCompany, setGuestCompany] = useState("");
  const [guestRole, setGuestRole] = useState("");
  const [guestEmail, setGuestEmail] = useState("");
  const [guestPhone, setGuestPhone] = useState("");
  const [guestNotes, setGuestNotes] = useState("");
  const [savingGuest, setSavingGuest] = useState(false);
  const [editingGuest, setEditingGuest] = useState<string | null>(null);

  const isAdmin = currentUser?.roleName === "admin" || currentUser?.isSystem;

  // Daten laden — org_memberships statt profiles
  const loadData = useCallback(async () => {
    if (!orgId) return;
    const [membersRes, votesRes, rolesRes, inventoryRes] = await Promise.all([
      supabase
        .from("org_memberships")
        .select("*, permissions, profiles(id, name, email, avatar_url, is_system, created_at), roles(id, name)")
        .eq("org_id", orgId),
      supabase.from("team_votes").select("*").eq("org_id", orgId),
      supabase.from("roles").select("id, name").eq("org_id", orgId),
      supabase
        .from("inventory_items")
        .select("id, name, inventory_number, category, purchased_by")
        .eq("org_id", orgId)
        .not("purchased_by", "is", null),
    ]);

    if (membersRes.data) {
      const sorted = (membersRes.data as OrgMember[]).sort((a, b) =>
        (a.profiles?.name || "").localeCompare(b.profiles?.name || "")
      );
      setMembers(sorted);
    }
    if (votesRes.data) setVotes(votesRes.data as TeamVote[]);
    if (rolesRes.data) setRoles(rolesRes.data as RoleOption[]);
    if (inventoryRes.data) setInventoryItems(inventoryRes.data as InventoryItem[]);
    setLoading(false);
  }, [supabase, orgId]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // Org-Einladungen laden
  const loadOrgInvitations = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("org_invitations")
      .select("*, profiles:invited_by(name), roles(name)")
      .eq("org_id", orgId)
      .order("created_at", { ascending: false });
    if (data) setOrgInvitations(data as OrgInvitation[]);
  }, [supabase, orgId]);

  useEffect(() => {
    loadOrgInvitations();
  }, [loadOrgInvitations]);

  // Org-Gäste laden
  const loadGuests = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("org_guests")
      .select("*")
      .eq("org_id", orgId)
      .order("name");
    if (data) setOrgGuests(data as OrgGuest[]);
  }, [supabase, orgId]);

  useEffect(() => {
    loadGuests();
  }, [loadGuests]);

  // Realtime — org-scoped
  useRealtimeTable({ table: "org_memberships", onDataChange: loadData, orgFilter: orgId || undefined });
  useRealtimeTable({ table: "team_votes", onDataChange: loadData, orgFilter: orgId || undefined });
  useRealtimeTable({ table: "inventory_items", onDataChange: loadData, orgFilter: orgId || undefined });
  useRealtimeTable({ table: "org_invitations", onDataChange: loadOrgInvitations, orgFilter: orgId || undefined });
  useRealtimeTable({ table: "org_guests", onDataChange: loadGuests, orgFilter: orgId || undefined });

  // Guest handlers
  async function handleAddGuest(e: React.FormEvent) {
    e.preventDefault();
    if (!orgId || !guestName.trim()) return;
    setSavingGuest(true);
    const { error } = await supabase.from("org_guests").insert({
      org_id: orgId,
      name: guestName.trim(),
      company: guestCompany || null,
      role: guestRole || null,
      email: guestEmail || null,
      phone: guestPhone || null,
      notes: guestNotes || null,
    });
    if (!error) {
      showToast("Gast hinzugefügt", "success");
      if (orgId) logActivity({ orgId, action: "guest.added", entityType: "guest", entityLabel: guestName.trim() });
      setGuestName(""); setGuestCompany(""); setGuestRole(""); setGuestEmail(""); setGuestPhone(""); setGuestNotes("");
      setShowGuestForm(false);
    } else {
      showToast("Fehler: " + error.message, "error");
    }
    setSavingGuest(false);
  }

  async function handleUpdateGuest(id: string, updates: Partial<OrgGuest>) {
    const { error } = await supabase.from("org_guests").update(updates).eq("id", id);
    if (error) showToast("Fehler: " + error.message, "error");
    else {
      showToast("Gast aktualisiert", "success");
      setEditingGuest(null);
    }
  }

  async function handleDeleteGuest(id: string) {
    if (!(await appConfirm("Gast wirklich löschen?", { variant: "danger", confirmLabel: "Löschen" }))) return;
    const { error } = await supabase.from("org_guests").delete().eq("id", id);
    if (error) showToast("Fehler: " + error.message, "error");
    else showToast("Gast entfernt", "success");
  }

  // Berechnungen
  const activeMembers = members.filter((m) => m.is_active);
  const pendingMembers = members.filter((m) => !m.is_active && !m.approved_at);
  const inactiveMembers = members.filter((m) => !m.is_active && m.approved_at);

  function getRoleName(member: OrgMember): string {
    return member.roles?.name || "member";
  }

  function getPatenItems(profileName: string): InventoryItem[] {
    return inventoryItems.filter((i) => i.purchased_by === profileName);
  }

  function getVotesForCandidate(profileId: string): number {
    return votes.filter((v) => v.candidate_id === profileId).length;
  }

  function hasVoted(profileId: string): boolean {
    if (!currentUser) return false;
    return votes.some(
      (v) => v.candidate_id === profileId && v.voter_id === currentUser.id
    );
  }

  // Abstimmen — mit org_id
  async function handleVote(profileId: string) {
    if (!currentUser || !orgId) return;
    setProcessing(profileId);

    const alreadyVoted = hasVoted(profileId);

    if (alreadyVoted) {
      await supabase
        .from("team_votes")
        .delete()
        .eq("candidate_id", profileId)
        .eq("voter_id", currentUser.id);
    } else {
      await supabase.from("team_votes").insert({
        candidate_id: profileId,
        voter_id: currentUser.id,
        org_id: orgId,
      });

      await checkAndAutoApprove(profileId);
    }

    await loadData();
    setProcessing(null);
  }

  // Auto-Approve — org_memberships statt profiles
  async function checkAndAutoApprove(profileId: string) {
    if (!orgId) return;
    const [votesRes, activeRes] = await Promise.all([
      supabase
        .from("team_votes")
        .select("*", { count: "exact", head: true })
        .eq("candidate_id", profileId)
        .eq("org_id", orgId),
      supabase
        .from("org_memberships")
        .select("*", { count: "exact", head: true })
        .eq("org_id", orgId)
        .eq("is_active", true),
    ]);

    const voteCount = votesRes.count || 0;
    const activeCount = activeRes.count || 0;

    if (voteCount >= activeCount) {
      await supabase
        .from("org_memberships")
        .update({ is_active: true, approved_at: new Date().toISOString() })
        .eq("profile_id", profileId)
        .eq("org_id", orgId);

      await supabase
        .from("team_votes")
        .delete()
        .eq("candidate_id", profileId)
        .eq("org_id", orgId);
    }
  }

  // Admin: Sofort freigeben — org_memberships
  async function handleAdminApprove(profileId: string) {
    if (!orgId) return;
    setProcessing(profileId);

    await supabase
      .from("org_memberships")
      .update({ is_active: true, approved_at: new Date().toISOString() })
      .eq("profile_id", profileId)
      .eq("org_id", orgId);

    await supabase
      .from("team_votes")
      .delete()
      .eq("candidate_id", profileId)
      .eq("org_id", orgId);

    await loadData();
    setProcessing(null);
  }

  // Admin: Deaktivieren / Reaktivieren — org_memberships
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
    await loadData();
    setProcessing(null);
  }

  // Org-Einladung löschen
  async function handleDeleteOrgInvitation(invId: string) {
    await supabase.from("org_invitations").delete().eq("id", invId);
    await loadOrgInvitations();
  }

  // Mitglied entfernen (bei Testusern komplett löschen)
  async function handleRemoveMember(profileId: string, profileName: string) {
    if (!orgId) return;
    const isTestUser = profileName?.includes("(Test)");
    const confirmMsg = isTestUser
      ? `Testuser "${profileName}" komplett löschen?`
      : `"${profileName}" aus der Organisation entfernen?`;
    if (!(await appConfirm(confirmMsg, { variant: "danger", confirmLabel: "Entfernen" }))) return;

    setProcessing(profileId);
    await supabase.rpc("remove_org_member", {
      p_org_id: orgId,
      p_profile_id: profileId,
      p_delete_user: isTestUser,
    });
    await loadData();
    setProcessing(null);
  }

  const pendingOrgInvitations = orgInvitations.filter((i) => i.status === "pending");

  const roleLabels = orgRoleLabels;

  if (loading) {
    return (
      <div
        className="flex items-center justify-center h-64"
        style={{ color: "var(--color-muted-foreground)" }}
      >
        Team wird geladen...
      </div>
    );
  }

  return (
    <div>
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold">Team</h1>
          <p
            className="text-sm mt-1"
            style={{ color: "var(--color-muted-foreground)" }}
          >
            Mitglieder verwalten und Beitritte freigeben
          </p>
        </div>
        {isAdmin && (
          <button
            onClick={() => setShowInviteModal(true)}
            className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium text-white transition-colors"
            style={{ background: "var(--color-primary)" }}
            onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-primary-hover)"}
            onMouseLeave={(e) => e.currentTarget.style.background = "var(--color-primary)"}
          >
            <IconUserPlus size={16} />
            Mitglied einladen
          </button>
        )}
      </div>

      {/* KPI Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
        <div
          className="p-5 rounded-xl"
          style={{ background: "var(--color-surface)" }}
        >
          <div className="flex items-center justify-between mb-3">
            <span
              className="text-sm font-medium"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              Aktive Mitglieder
            </span>
            <div
              className="w-9 h-9 rounded-lg flex items-center justify-center"
              style={{ background: "var(--color-success-light)" }}
            >
              <IconUsers size={18} style={{ color: "var(--color-success)" }} />
            </div>
          </div>
          <div className="text-3xl font-bold">{activeMembers.length}</div>
        </div>

        <div
          className="p-5 rounded-xl"
          style={{ background: "var(--color-surface)" }}
        >
          <div className="flex items-center justify-between mb-3">
            <span
              className="text-sm font-medium"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              Ausstehende Freigaben
            </span>
            <div
              className="w-9 h-9 rounded-lg flex items-center justify-center"
              style={{ background: "var(--color-warning-light)" }}
            >
              <IconUserPlus
                size={18}
                style={{ color: "var(--color-warning)" }}
              />
            </div>
          </div>
          <div className="text-3xl font-bold">{pendingMembers.length}</div>
        </div>

        <div
          className="p-5 rounded-xl"
          style={{ background: "var(--color-surface)" }}
        >
          <div className="flex items-center justify-between mb-3">
            <span
              className="text-sm font-medium"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              Inaktive / Ehemalige
            </span>
            <div
              className="w-9 h-9 rounded-lg flex items-center justify-center"
              style={{ background: "var(--color-muted)" }}
            >
              <IconClock
                size={18}
                style={{ color: "var(--color-muted-foreground)" }}
              />
            </div>
          </div>
          <div className="text-3xl font-bold">{inactiveMembers.length}</div>
        </div>

        <div
          className="p-5 rounded-xl"
          style={{ background: "var(--color-surface)" }}
        >
          <div className="flex items-center justify-between mb-3">
            <span
              className="text-sm font-medium"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              Einladungen
            </span>
            <div
              className="w-9 h-9 rounded-lg flex items-center justify-center"
              style={{ background: "var(--color-info-light)" }}
            >
              <IconMail size={18} style={{ color: "var(--color-info)" }} />
            </div>
          </div>
          <div className="text-3xl font-bold">{invitationCount + pendingOrgInvitations.length}</div>
        </div>
      </div>

      {/* Projekt-Einladungen */}
      {invitationCount > 0 && (
        <div className="mb-8">
          <h2 className="text-lg font-bold mb-4 flex items-center gap-2">
            <IconMail size={20} style={{ color: "var(--color-info)" }} />
            Projekt-Einladungen ({invitationCount})
          </h2>
          <div
            className="rounded-xl overflow-hidden"
            style={{
              background: "var(--color-surface)",
              border: "1px solid var(--color-border-light)",
            }}
          >
            {invitations.map((inv, idx) => {
              const inviterName = (inv.profiles as any)?.name || "Unbekannt";
              const projectName = (inv.projects as any)?.name || "Unbekanntes Projekt";
              const roleLabel = inv.role === "editor" ? "Bearbeiter" : "Betrachter";
              const isProcessingInv = invProcessing === inv.id;

              return (
                <div
                  key={inv.id}
                  className="flex items-center gap-4 px-5 py-3.5"
                  style={{
                    borderBottom:
                      idx < invitations.length - 1
                        ? "1px solid var(--color-border-light)"
                        : "none",
                  }}
                >
                  {/* Projekt-Icon */}
                  <div
                    className="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 font-bold text-xs"
                    style={{
                      background: "var(--color-info-light)",
                      color: "var(--color-info)",
                    }}
                  >
                    {projectName.charAt(0).toUpperCase()}
                  </div>

                  {/* Info */}
                  <div className="flex-1 min-w-0">
                    <p className="font-medium text-sm truncate">{projectName}</p>
                    <p
                      className="text-xs truncate"
                      style={{ color: "var(--color-muted-foreground)" }}
                    >
                      Eingeladen von {inviterName} als {roleLabel}
                    </p>
                  </div>

                  {/* Actions */}
                  <div className="flex items-center gap-2 flex-shrink-0">
                    <button
                      onClick={async () => {
                        setInvProcessing(inv.id);
                        await acceptInvitation(inv.id);
                        setInvProcessing(null);
                        router.push(`/projects/${inv.project_id}`);
                      }}
                      disabled={isProcessingInv}
                      className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors disabled:opacity-50"
                      style={{
                        background: "var(--color-primary)",
                        color: "white",
                      }}
                    >
                      <IconCheck size={14} />
                      Annehmen
                    </button>
                    <button
                      onClick={async () => {
                        setInvProcessing(inv.id);
                        await declineInvitation(inv.id);
                        setInvProcessing(null);
                      }}
                      disabled={isProcessingInv}
                      className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors disabled:opacity-50"
                      style={{
                        background: "var(--color-muted)",
                        color: "var(--color-muted-foreground)",
                      }}
                    >
                      <IconX size={14} />
                      Ablehnen
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* Eingeladene (pending Org-Invitations) */}
      {pendingOrgInvitations.length > 0 && (
        <div className="mb-8">
          <h2 className="text-lg font-bold mb-4 flex items-center gap-2">
            <IconMail size={20} style={{ color: "var(--color-primary)" }} />
            Eingeladene ({pendingOrgInvitations.length})
          </h2>
          <div
            className="rounded-xl overflow-hidden"
            style={{
              background: "var(--color-surface)",
              border: "1px solid var(--color-border-light)",
            }}
          >
            {pendingOrgInvitations.map((inv, idx) => {
              const inviterName = (inv.profiles as any)?.name || "Unbekannt";
              const roleName = (inv.roles as any)?.name || "member";
              const roleLabel = roleLabels[roleName] || roleName;

              return (
                <div
                  key={inv.id}
                  className="flex items-center gap-4 px-5 py-3.5"
                  style={{
                    borderBottom:
                      idx < pendingOrgInvitations.length - 1
                        ? "1px solid var(--color-border-light)"
                        : "none",
                  }}
                >
                  <div
                    className="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 font-bold text-xs"
                    style={{ background: "var(--color-primary-light)", color: "var(--color-primary)" }}
                  >
                    {inv.email.charAt(0).toUpperCase()}
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="font-medium text-sm truncate">{inv.email}</p>
                    <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                      Eingeladen von {inviterName} als {roleLabel} &middot;{" "}
                      {new Date(inv.created_at).toLocaleDateString("de-DE", { day: "numeric", month: "short" })}
                    </p>
                  </div>
                  <span
                    className="px-2.5 py-1 rounded text-xs font-medium flex-shrink-0"
                    style={{ background: "var(--color-warning-light)", color: "var(--color-warning)" }}
                  >
                    Ausstehend
                  </span>
                  {isAdmin && (
                    <button
                      onClick={async () => {
                        setProcessing(inv.id);
                        await supabase.functions.invoke("send-invite-email", {
                          body: { invitation_id: inv.id },
                        }).catch((err: unknown) => console.error("Email resend error:", err));
                        setProcessing(null);
                        await appAlert("Einladungs-Email erneut gesendet!");
                      }}
                      disabled={processing === inv.id}
                      className="px-2.5 py-1 rounded text-xs font-medium flex-shrink-0 transition-colors disabled:opacity-50"
                      style={{ color: "var(--color-primary)", border: "1px solid var(--color-primary)" }}
                      title="Email erneut senden"
                    >
                      {processing === inv.id ? "..." : "Erneut senden"}
                    </button>
                  )}
                  {isAdmin && (
                    <button
                      onClick={() => handleDeleteOrgInvitation(inv.id)}
                      className="p-1.5 rounded transition-colors flex-shrink-0"
                      style={{ color: "var(--color-destructive)" }}
                      title="Einladung zurückziehen"
                    >
                      <IconTrash size={14} />
                    </button>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* Ausstehende Freigaben */}
      {pendingMembers.length > 0 && (
        <div className="mb-8">
          <h2 className="text-lg font-bold mb-4">Ausstehende Freigaben</h2>
          <div className="space-y-3">
            {pendingMembers.map((member) => {
              const profile = member.profiles;
              const voteCount = getVotesForCandidate(member.profile_id);
              const totalNeeded = activeMembers.length;
              const progress =
                totalNeeded > 0 ? (voteCount / totalNeeded) * 100 : 0;
              const voted = hasVoted(member.profile_id);
              const isProcessingThis = processing === member.profile_id;

              return (
                <div
                  key={member.id}
                  className="p-5 rounded-xl"
                  style={{
                    background: "var(--color-surface)",
                    border: "1px solid var(--color-border-light)",
                  }}
                >
                  <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-3 min-w-0">
                      {/* Avatar */}
                      <div
                        className="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 font-bold text-sm overflow-hidden"
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
                      <div className="min-w-0">
                        <p className="font-medium truncate">{profile?.name}</p>
                        <p
                          className="text-xs truncate"
                          style={{ color: "var(--color-muted-foreground)" }}
                        >
                          {profile?.email}
                        </p>
                        <p
                          className="text-xs mt-0.5"
                          style={{ color: "var(--color-muted-foreground)" }}
                        >
                          Registriert:{" "}
                          {new Date(profile?.created_at || member.created_at).toLocaleDateString(
                            "de-DE",
                            {
                              day: "numeric",
                              month: "short",
                              year: "numeric",
                            }
                          )}
                        </p>
                      </div>
                    </div>

                    {/* Buttons */}
                    <div className="flex items-center gap-2 flex-shrink-0">
                      <button
                        onClick={() => handleVote(member.profile_id)}
                        disabled={isProcessingThis}
                        className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors disabled:opacity-50"
                        style={{
                          background: voted
                            ? "var(--color-success-light)"
                            : "var(--color-primary)",
                          color: voted
                            ? "var(--color-success)"
                            : "white",
                          border: voted
                            ? "1px solid var(--color-success)"
                            : "none",
                        }}
                      >
                        <IconCheck size={14} />
                        {voted ? "Zugestimmt" : "Zustimmen"}
                      </button>

                      {isAdmin && (
                        <button
                          onClick={() => handleAdminApprove(member.profile_id)}
                          disabled={isProcessingThis}
                          className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors disabled:opacity-50"
                          style={{
                            background: "var(--color-warning-light)",
                            color: "var(--color-warning)",
                            border: "1px solid var(--color-warning)",
                          }}
                        >
                          <IconShield size={14} />
                          Sofort freigeben
                        </button>
                      )}
                    </div>
                  </div>

                  {/* Fortschrittsbalken */}
                  <div className="mt-3">
                    <div className="flex items-center justify-between text-xs mb-1">
                      <span
                        style={{ color: "var(--color-muted-foreground)" }}
                      >
                        Zustimmungen
                      </span>
                      <span className="font-medium">
                        {voteCount}/{totalNeeded}
                      </span>
                    </div>
                    <div
                      className="w-full h-1.5 rounded-full overflow-hidden"
                      style={{ background: "var(--color-muted)" }}
                    >
                      <div
                        className="h-full rounded-full transition-all duration-300"
                        style={{
                          width: `${Math.min(progress, 100)}%`,
                          background: "var(--color-primary)",
                        }}
                      />
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* Aktive Mitglieder */}
      <div className="mb-8">
        <h2 className="text-lg font-bold mb-4">
          Aktive Mitglieder ({activeMembers.length})
        </h2>
        <div
          className="rounded-xl overflow-hidden"
          style={{
            background: "var(--color-surface)",
            border: "1px solid var(--color-border-light)",
          }}
        >
          {activeMembers.map((member, idx) => {
            const profile = member.profiles;
            const orgRoleName = getRoleName(member);
            const isSelf = currentUser?.id === member.profile_id;
            const isSystemUser = profile?.is_system;
            const patenCount = getPatenItems(profile?.name || "").length;

            return (
              <div
                key={member.id}
                className="flex items-center gap-4 px-5 py-3.5"
                style={{
                  borderBottom:
                    idx < activeMembers.length - 1
                      ? "1px solid var(--color-border-light)"
                      : "none",
                }}
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

                {/* Patenschaften Badge */}
                {patenCount > 0 && (
                  <span
                    className="flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium flex-shrink-0"
                    style={{
                      background: "var(--color-info-light)",
                      color: "var(--color-info)",
                    }}
                    title={`Pate für ${patenCount} Inventar-Artikel`}
                  >
                    <IconInventory size={11} />
                    {patenCount}
                  </span>
                )}

                {/* Rolle Badge */}
                {isSystemUser ? (
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
              </div>
            );
          })}
        </div>
      </div>

      {/* ===== GÄSTE-VERZEICHNIS ===== */}
      <div>
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-3">
            <h2 className="text-lg font-semibold flex items-center gap-2">
              <IconUsers className="w-5 h-5" />
              Gäste
            </h2>
            <span className="text-xs px-2 py-0.5 rounded-full"
              style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
              {orgGuests.length}
            </span>
          </div>
          <button
            onClick={() => setShowGuestForm(!showGuestForm)}
            className="flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-lg font-medium transition-colors"
            style={{ background: "var(--color-primary)", color: "var(--color-primary-foreground)" }}
          >
            <IconPlus className="w-4 h-4" />
            Neuer Gast
          </button>
        </div>

        {showGuestForm && (
          <div className="rounded-xl p-4 mb-4" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}>
            <form onSubmit={handleAddGuest} className="space-y-3">
              <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                <div>
                  <label className="text-xs font-medium mb-1 block" style={{ color: "var(--color-muted-foreground)" }}>Name *</label>
                  <input type="text" value={guestName} onChange={(e) => setGuestName(e.target.value)}
                    className="w-full rounded-lg px-3 py-2 text-sm" style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
                    required placeholder="Max Mustermann" />
                </div>
                <div>
                  <label className="text-xs font-medium mb-1 block" style={{ color: "var(--color-muted-foreground)" }}>Firma</label>
                  <input type="text" value={guestCompany} onChange={(e) => setGuestCompany(e.target.value)}
                    className="w-full rounded-lg px-3 py-2 text-sm" style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
                    placeholder="Firma GmbH" />
                </div>
                <div>
                  <label className="text-xs font-medium mb-1 block" style={{ color: "var(--color-muted-foreground)" }}>Rolle</label>
                  <input type="text" value={guestRole} onChange={(e) => setGuestRole(e.target.value)}
                    className="w-full rounded-lg px-3 py-2 text-sm" style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
                    placeholder="z.B. Kunde, VIP, Sponsor" />
                </div>
                <div>
                  <label className="text-xs font-medium mb-1 block" style={{ color: "var(--color-muted-foreground)" }}>E-Mail</label>
                  <input type="email" value={guestEmail} onChange={(e) => setGuestEmail(e.target.value)}
                    className="w-full rounded-lg px-3 py-2 text-sm" style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
                    placeholder="max@firma.de" />
                </div>
                <div>
                  <label className="text-xs font-medium mb-1 block" style={{ color: "var(--color-muted-foreground)" }}>Telefon</label>
                  <input type="tel" value={guestPhone} onChange={(e) => setGuestPhone(e.target.value)}
                    className="w-full rounded-lg px-3 py-2 text-sm" style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
                    placeholder="+49 ..." />
                </div>
                <div>
                  <label className="text-xs font-medium mb-1 block" style={{ color: "var(--color-muted-foreground)" }}>Notizen</label>
                  <input type="text" value={guestNotes} onChange={(e) => setGuestNotes(e.target.value)}
                    className="w-full rounded-lg px-3 py-2 text-sm" style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
                    placeholder="Sonderwünsche, Allergien..." />
                </div>
              </div>
              <div className="flex gap-2">
                <button type="submit" disabled={savingGuest}
                  className="px-4 py-2 rounded-lg text-sm font-medium"
                  style={{ background: "var(--color-primary)", color: "var(--color-primary-foreground)" }}>
                  {savingGuest ? "Wird gespeichert..." : "Hinzufügen"}
                </button>
                <button type="button" onClick={() => setShowGuestForm(false)}
                  className="px-4 py-2 rounded-lg text-sm"
                  style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
                  Abbrechen
                </button>
              </div>
            </form>
          </div>
        )}

        {orgGuests.length === 0 ? (
          <p className="text-sm py-4" style={{ color: "var(--color-muted-foreground)" }}>
            Noch keine Gäste eingetragen. Gäste können hier zentral verwaltet und Projekten zugewiesen werden.
          </p>
        ) : (
          <div className="overflow-x-auto rounded-xl" style={{ border: "1px solid var(--color-border)" }}>
            <table className="w-full text-sm" style={{ minWidth: 600 }}>
              <thead>
                <tr style={{ background: "var(--color-muted)", borderBottom: "1px solid var(--color-border)" }}>
                  <th className="text-left px-4 py-2.5 font-medium">Name</th>
                  <th className="text-left px-4 py-2.5 font-medium hidden sm:table-cell">Firma / Rolle</th>
                  <th className="text-left px-4 py-2.5 font-medium hidden md:table-cell">Kontakt</th>
                  <th className="text-left px-4 py-2.5 font-medium hidden lg:table-cell">Notizen</th>
                  <th className="w-10"></th>
                </tr>
              </thead>
              <tbody>
                {orgGuests.map((g) => (
                  <tr key={g.id} className="group transition-colors"
                    style={{ borderBottom: "1px solid var(--color-border-light)" }}>
                    <td className="px-4 py-2.5 font-medium">
                      {g.name}
                      {g.company && <span className="sm:hidden text-xs ml-1" style={{ color: "var(--color-muted-foreground)" }}>· {g.company}</span>}
                    </td>
                    <td className="px-4 py-2.5 hidden sm:table-cell">
                      <div className="flex flex-col">
                        {g.company && <span className="text-xs">{g.company}</span>}
                        {g.role && <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>{g.role}</span>}
                      </div>
                    </td>
                    <td className="px-4 py-2.5 hidden md:table-cell">
                      <div className="flex flex-col gap-0.5 text-xs">
                        {g.email && <span>{g.email}</span>}
                        {g.phone && <span style={{ color: "var(--color-muted-foreground)" }}>{g.phone}</span>}
                      </div>
                    </td>
                    <td className="px-4 py-2.5 hidden lg:table-cell">
                      <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>{g.notes || "—"}</span>
                    </td>
                    <td className="px-2 py-2.5">
                      <button onClick={() => handleDeleteGuest(g.id)}
                        className="opacity-0 group-hover:opacity-100 p-1 rounded transition-opacity"
                        title="Löschen">
                        <IconTrash className="w-4 h-4" style={{ color: "var(--color-danger)" }} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Inaktive / Ehemalige */}
      {inactiveMembers.length > 0 && (
        <div>
          <button
            onClick={() => setShowInactive(!showInactive)}
            className="flex items-center gap-2 text-sm font-medium mb-4 transition-colors"
            style={{ color: "var(--color-muted-foreground)" }}
          >
            <span
              className="transition-transform"
              style={{
                display: "inline-block",
                transform: showInactive ? "rotate(90deg)" : "rotate(0deg)",
              }}
            >
              &#9656;
            </span>
            Inaktive / Ehemalige ({inactiveMembers.length})
          </button>

          {showInactive && (
            <div
              className="rounded-xl overflow-hidden"
              style={{
                background: "var(--color-surface)",
                border: "1px solid var(--color-border-light)",
                opacity: 0.75,
              }}
            >
              {inactiveMembers.map((member, idx) => {
                const profile = member.profiles;
                const patenCount = getPatenItems(profile?.name || "").length;
                return (
                  <div
                    key={member.id}
                    className="flex items-center gap-4 px-5 py-3.5"
                    style={{
                      borderBottom:
                        idx < inactiveMembers.length - 1
                          ? "1px solid var(--color-border-light)"
                          : "none",
                    }}
                  >
                    {/* Avatar */}
                    <div
                      className="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 font-bold text-xs overflow-hidden"
                      style={{
                        background: profile?.avatar_url ? "transparent" : "var(--color-muted)",
                        color: "var(--color-muted-foreground)",
                      }}
                    >
                      {profile?.avatar_url ? (
                        <img
                          src={profile.avatar_url}
                          alt={profile.name}
                          className="w-full h-full object-cover"
                          style={{ opacity: 0.6 }}
                        />
                      ) : (
                        (profile?.name || "?").charAt(0).toUpperCase()
                      )}
                    </div>

                    {/* Name + Email */}
                    <div className="flex-1 min-w-0">
                      <p className="font-medium text-sm truncate">
                        {profile?.name}
                      </p>
                      <p
                        className="text-xs truncate"
                        style={{ color: "var(--color-muted-foreground)" }}
                      >
                        {profile?.email}
                      </p>
                    </div>

                    {/* Patenschaften Badge */}
                    {patenCount > 0 && (
                      <span
                        className="flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium flex-shrink-0"
                        style={{
                          background: "var(--color-muted)",
                          color: "var(--color-muted-foreground)",
                        }}
                        title={`Pate für ${patenCount} Inventar-Artikel`}
                      >
                        <IconInventory size={11} />
                        {patenCount}
                      </span>
                    )}

                    <span
                      className="px-2.5 py-1 rounded text-xs font-medium flex-shrink-0"
                      style={{
                        background: "var(--color-muted)",
                        color: "var(--color-muted-foreground)",
                      }}
                    >
                      Inaktiv
                    </span>

                    {/* Admin: Reaktivieren + Entfernen */}
                    {isAdmin && (
                      <div className="flex items-center gap-2 flex-shrink-0">
                        <button
                          onClick={() => handleToggleActive(member.profile_id, true)}
                          disabled={processing === member.profile_id}
                          className="relative w-9 h-5 rounded-full transition-colors disabled:opacity-50"
                          style={{ background: "var(--color-muted-foreground)" }}
                          title="Inaktiv — klicken zum Reaktivieren"
                        >
                          <span
                            className="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white transition-all"
                            style={{ boxShadow: "0 1px 2px rgba(0,0,0,0.2)" }}
                          />
                        </button>
                        <button
                          onClick={() => setExitMember({ id: member.profile_id, name: profile?.name || "" })}
                          className="px-2 py-1 rounded text-xs font-medium transition-colors"
                          style={{ border: "1px solid var(--color-border)", color: "var(--color-warning)" }}
                          title="Austritt mit Auslöse-Berechnung"
                        >
                          Austritt
                        </button>
                        <button
                          onClick={() => handleRemoveMember(member.profile_id, profile?.name || "")}
                          disabled={processing === member.profile_id}
                          className="p-1.5 rounded transition-colors disabled:opacity-50"
                          style={{ color: "var(--color-destructive)" }}
                          title={profile?.name?.includes("(Test)") ? "Testuser komplett löschen" : "Aus Organisation entfernen"}
                        >
                          <IconTrash size={14} />
                        </button>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}

      {/* Beschlüsse */}
      <div
        className="rounded-xl p-6"
        style={{
          background: "var(--color-surface)",
          border: "1px solid var(--color-border-light)",
        }}
      >
        <DecisionPanel />
      </div>

      {/* Exit Settlement Wizard */}
      {exitMember && (
        <ExitSettlementWizard
          memberId={exitMember.id}
          memberName={exitMember.name}
          onClose={() => setExitMember(null)}
          onComplete={() => { setExitMember(null); loadData(); }}
        />
      )}

      {/* Invite Modal */}
      {showInviteModal && (
        <InviteModal
          orgId={orgId || ""}
          roles={roles}
          currentUserId={currentUser?.id || ""}
          onClose={() => setShowInviteModal(false)}
          onInvited={loadOrgInvitations}
        />
      )}
    </div>
  );
}

// === Einladungs-Modal ===
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
      // Einladungs-Email senden (fire & forget)
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
                <label className="block text-sm font-medium mb-1.5">E-Mail-Adresse</label>
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={inputStyle}
                  placeholder="person@example.com"
                  required
                  autoFocus
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Rolle</label>
                <select
                  value={selectedRoleId}
                  onChange={(e) => setSelectedRoleId(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
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
                <p className="text-sm font-medium" style={{ color: "var(--color-destructive)" }}>{error}</p>
              )}
              <button
                type="submit"
                disabled={saving || !email.trim()}
                className="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium text-white disabled:opacity-50 transition-colors"
                style={{ background: "var(--color-primary)" }}
              >
                <IconUserPlus size={16} />
                {saving ? "Wird eingeladen..." : "Einladen"}
              </button>
              <p className="text-xs text-center" style={{ color: "var(--color-muted-foreground)" }}>
                Die Person muss sich mit dieser E-Mail registrieren und wird dann automatisch freigeschaltet.
              </p>
            </form>
          )}
        </div>
      </div>
    </div>
  );
}
