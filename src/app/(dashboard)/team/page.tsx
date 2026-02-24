"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type { TeamVote, InventoryItem } from "@/types/database";
import {
  IconUsers,
  IconUserPlus,
  IconClock,
  IconCheck,
  IconX,
  IconShield,
  IconInventory,
} from "@/components/ui/icons";

type ProfileWithRole = {
  id: string;
  name: string;
  email: string;
  role_id: string;
  is_active: boolean;
  approved_at: string | null;
  avatar_url: string | null;
  created_at: string;
  roles?: { name: string } | { name: string }[] | null;
};

type RoleOption = {
  id: string;
  name: string;
};

export default function TeamPage() {
  const supabase = createClient();
  const currentUser = useCurrentUser();

  const [profiles, setProfiles] = useState<ProfileWithRole[]>([]);
  const [votes, setVotes] = useState<TeamVote[]>([]);
  const [roles, setRoles] = useState<RoleOption[]>([]);
  const [inventoryItems, setInventoryItems] = useState<InventoryItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState<string | null>(null);
  const [showInactive, setShowInactive] = useState(false);

  const isAdmin = currentUser?.roleName === "admin";

  // Daten laden
  const loadData = useCallback(async () => {
    const [profilesRes, votesRes, rolesRes, inventoryRes] = await Promise.all([
      supabase.from("profiles").select("*, roles(name)").order("name"),
      supabase.from("team_votes").select("*"),
      supabase.from("roles").select("id, name"),
      supabase
        .from("inventory_items")
        .select("id, name, inventory_number, category, purchased_by")
        .not("purchased_by", "is", null),
    ]);

    if (profilesRes.data) setProfiles(profilesRes.data as ProfileWithRole[]);
    if (votesRes.data) setVotes(votesRes.data as TeamVote[]);
    if (rolesRes.data) setRoles(rolesRes.data as RoleOption[]);
    if (inventoryRes.data) setInventoryItems(inventoryRes.data as InventoryItem[]);
    setLoading(false);
  }, [supabase]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // Realtime
  useRealtimeTable({ table: "profiles", onDataChange: loadData });
  useRealtimeTable({ table: "team_votes", onDataChange: loadData });
  useRealtimeTable({ table: "inventory_items", onDataChange: loadData });

  // Berechnungen
  const activeProfiles = profiles.filter((p) => p.is_active);
  const pendingProfiles = profiles.filter((p) => !p.is_active && !p.approved_at);
  const inactiveProfiles = profiles.filter((p) => !p.is_active && p.approved_at);

  function getRoleName(profile: ProfileWithRole): string {
    const r = profile.roles as { name: string } | { name: string }[] | null;
    if (!r) return "member";
    if (Array.isArray(r)) return r[0]?.name || "member";
    return r.name || "member";
  }

  function getPatenItems(profileName: string): InventoryItem[] {
    return inventoryItems.filter((i) => i.purchased_by === profileName);
  }

  function getVotesForCandidate(candidateId: string): number {
    return votes.filter((v) => v.candidate_id === candidateId).length;
  }

  function hasVoted(candidateId: string): boolean {
    if (!currentUser) return false;
    return votes.some(
      (v) => v.candidate_id === candidateId && v.voter_id === currentUser.id
    );
  }

  // Abstimmen
  async function handleVote(candidateId: string) {
    if (!currentUser) return;
    setProcessing(candidateId);

    const alreadyVoted = hasVoted(candidateId);

    if (alreadyVoted) {
      // Stimme zurücknehmen
      await supabase
        .from("team_votes")
        .delete()
        .eq("candidate_id", candidateId)
        .eq("voter_id", currentUser.id);
    } else {
      // Abstimmen
      await supabase.from("team_votes").insert({
        candidate_id: candidateId,
        voter_id: currentUser.id,
      });

      // Auto-Approve prüfen
      await checkAndAutoApprove(candidateId);
    }

    await loadData();
    setProcessing(null);
  }

  async function checkAndAutoApprove(candidateId: string) {
    const [votesRes, activeRes] = await Promise.all([
      supabase
        .from("team_votes")
        .select("*", { count: "exact", head: true })
        .eq("candidate_id", candidateId),
      supabase
        .from("profiles")
        .select("*", { count: "exact", head: true })
        .eq("is_active", true),
    ]);

    const voteCount = votesRes.count || 0;
    const activeCount = activeRes.count || 0;

    if (voteCount >= activeCount) {
      await supabase
        .from("profiles")
        .update({ is_active: true, approved_at: new Date().toISOString() })
        .eq("id", candidateId);

      // Votes aufräumen
      await supabase
        .from("team_votes")
        .delete()
        .eq("candidate_id", candidateId);
    }
  }

  // Admin: Sofort freigeben
  async function handleAdminApprove(candidateId: string) {
    setProcessing(candidateId);

    await supabase
      .from("profiles")
      .update({ is_active: true, approved_at: new Date().toISOString() })
      .eq("id", candidateId);

    await supabase
      .from("team_votes")
      .delete()
      .eq("candidate_id", candidateId);

    await loadData();
    setProcessing(null);
  }

  // Admin: Rolle ändern
  async function handleRoleChange(profileId: string, newRoleId: string) {
    await supabase
      .from("profiles")
      .update({ role_id: newRoleId })
      .eq("id", profileId);
    await loadData();
  }

  // Admin: Deaktivieren / Reaktivieren
  async function handleToggleActive(profileId: string, activate: boolean) {
    setProcessing(profileId);
    await supabase
      .from("profiles")
      .update({
        is_active: activate,
        approved_at: activate ? new Date().toISOString() : undefined,
      })
      .eq("id", profileId);
    await loadData();
    setProcessing(null);
  }

  const roleLabels: Record<string, string> = {
    admin: "Admin",
    manager: "Manager",
    member: "Mitglied",
  };

  const roleBadgeStyles: Record<string, { bg: string; color: string }> = {
    admin: {
      bg: "var(--color-destructive-light)",
      color: "var(--color-destructive)",
    },
    manager: {
      bg: "var(--color-warning-light)",
      color: "var(--color-warning)",
    },
    member: {
      bg: "var(--color-muted)",
      color: "var(--color-muted-foreground)",
    },
  };

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
      <div className="mb-6">
        <h1 className="text-2xl font-bold">Team</h1>
        <p
          className="text-sm mt-1"
          style={{ color: "var(--color-muted-foreground)" }}
        >
          Mitglieder verwalten und Beitritte freigeben
        </p>
      </div>

      {/* KPI Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
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
          <div className="text-3xl font-bold">{activeProfiles.length}</div>
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
          <div className="text-3xl font-bold">{pendingProfiles.length}</div>
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
          <div className="text-3xl font-bold">{inactiveProfiles.length}</div>
        </div>
      </div>

      {/* Ausstehende Freigaben */}
      {pendingProfiles.length > 0 && (
        <div className="mb-8">
          <h2 className="text-lg font-bold mb-4">Ausstehende Freigaben</h2>
          <div className="space-y-3">
            {pendingProfiles.map((profile) => {
              const voteCount = getVotesForCandidate(profile.id);
              const totalNeeded = activeProfiles.length;
              const progress =
                totalNeeded > 0 ? (voteCount / totalNeeded) * 100 : 0;
              const voted = hasVoted(profile.id);
              const isProcessingThis = processing === profile.id;

              return (
                <div
                  key={profile.id}
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
                          background: profile.avatar_url ? "transparent" : "var(--color-primary-light)",
                          color: "var(--color-primary)",
                        }}
                      >
                        {profile.avatar_url ? (
                          <img
                            src={profile.avatar_url}
                            alt={profile.name}
                            className="w-full h-full object-cover"
                          />
                        ) : (
                          profile.name.charAt(0).toUpperCase()
                        )}
                      </div>
                      <div className="min-w-0">
                        <p className="font-medium truncate">{profile.name}</p>
                        <p
                          className="text-xs truncate"
                          style={{ color: "var(--color-muted-foreground)" }}
                        >
                          {profile.email}
                        </p>
                        <p
                          className="text-xs mt-0.5"
                          style={{ color: "var(--color-muted-foreground)" }}
                        >
                          Registriert:{" "}
                          {new Date(profile.created_at).toLocaleDateString(
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
                        onClick={() => handleVote(profile.id)}
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
                          onClick={() => handleAdminApprove(profile.id)}
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
          Aktive Mitglieder ({activeProfiles.length})
        </h2>
        <div
          className="rounded-xl overflow-hidden"
          style={{
            background: "var(--color-surface)",
            border: "1px solid var(--color-border-light)",
          }}
        >
          {activeProfiles.map((profile, idx) => {
            const roleName = getRoleName(profile);
            const badgeStyle = roleBadgeStyles[roleName] || roleBadgeStyles.member;
            const isSelf = currentUser?.id === profile.id;
            const isLastAdmin =
              roleName === "admin" &&
              activeProfiles.filter((p) => getRoleName(p) === "admin")
                .length <= 1;
            const patenCount = getPatenItems(profile.name).length;

            return (
              <div
                key={profile.id}
                className="flex items-center gap-4 px-5 py-3.5"
                style={{
                  borderBottom:
                    idx < activeProfiles.length - 1
                      ? "1px solid var(--color-border-light)"
                      : "none",
                }}
              >
                {/* Avatar */}
                <div
                  className="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 font-bold text-xs overflow-hidden"
                  style={{
                    background: profile.avatar_url ? "transparent" : "var(--color-primary-light)",
                    color: "var(--color-primary)",
                  }}
                >
                  {profile.avatar_url ? (
                    <img
                      src={profile.avatar_url}
                      alt={profile.name}
                      className="w-full h-full object-cover"
                    />
                  ) : (
                    profile.name.charAt(0).toUpperCase()
                  )}
                </div>

                {/* Name + Email */}
                <div className="flex-1 min-w-0">
                  <p className="font-medium text-sm truncate">
                    {profile.name}
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
                    {profile.email}
                  </p>
                </div>

                {/* Patenschaften Badge */}
                {patenCount > 0 && (
                  <span
                    className="flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium flex-shrink-0"
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

                {/* Rolle */}
                {isAdmin && !isSelf ? (
                  <select
                    value={profile.role_id}
                    onChange={(e) =>
                      handleRoleChange(profile.id, e.target.value)
                    }
                    className="px-2 py-1 rounded text-xs font-medium flex-shrink-0"
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
                ) : (
                  <span
                    className="px-2.5 py-1 rounded text-xs font-medium flex items-center gap-1 flex-shrink-0"
                    style={{
                      background: badgeStyle.bg,
                      color: badgeStyle.color,
                    }}
                  >
                    {roleName === "admin" && <IconShield size={11} />}
                    {roleLabels[roleName] || roleName}
                  </span>
                )}

                {/* Mitglied seit */}
                <span
                  className="text-xs flex-shrink-0 hidden sm:block"
                  style={{ color: "var(--color-muted-foreground)" }}
                >
                  Seit{" "}
                  {new Date(
                    profile.approved_at || profile.created_at
                  ).toLocaleDateString("de-DE", {
                    month: "short",
                    year: "numeric",
                  })}
                </span>

                {/* Admin: Aktiv-Toggle */}
                {isAdmin && !isSelf && !isLastAdmin && (
                  <button
                    onClick={() => handleToggleActive(profile.id, false)}
                    disabled={processing === profile.id}
                    className="relative w-9 h-5 rounded-full flex-shrink-0 transition-colors disabled:opacity-50"
                    style={{ background: "var(--color-success)" }}
                    title="Aktiv — klicken zum Deaktivieren"
                  >
                    <span
                      className="absolute top-0.5 right-0.5 w-4 h-4 rounded-full bg-white transition-all"
                      style={{ boxShadow: "0 1px 2px rgba(0,0,0,0.2)" }}
                    />
                  </button>
                )}
              </div>
            );
          })}
        </div>
      </div>

      {/* Inaktive / Ehemalige */}
      {inactiveProfiles.length > 0 && (
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
              ▸
            </span>
            Inaktive / Ehemalige ({inactiveProfiles.length})
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
              {inactiveProfiles.map((profile, idx) => {
                const patenCount = getPatenItems(profile.name).length;
                return (
                  <div
                    key={profile.id}
                    className="flex items-center gap-4 px-5 py-3.5"
                    style={{
                      borderBottom:
                        idx < inactiveProfiles.length - 1
                          ? "1px solid var(--color-border-light)"
                          : "none",
                    }}
                  >
                    {/* Avatar */}
                    <div
                      className="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 font-bold text-xs overflow-hidden"
                      style={{
                        background: profile.avatar_url ? "transparent" : "var(--color-muted)",
                        color: "var(--color-muted-foreground)",
                      }}
                    >
                      {profile.avatar_url ? (
                        <img
                          src={profile.avatar_url}
                          alt={profile.name}
                          className="w-full h-full object-cover"
                          style={{ opacity: 0.6 }}
                        />
                      ) : (
                        profile.name.charAt(0).toUpperCase()
                      )}
                    </div>

                    {/* Name + Email */}
                    <div className="flex-1 min-w-0">
                      <p className="font-medium text-sm truncate">
                        {profile.name}
                      </p>
                      <p
                        className="text-xs truncate"
                        style={{ color: "var(--color-muted-foreground)" }}
                      >
                        {profile.email}
                      </p>
                    </div>

                    {/* Patenschaften Badge */}
                    {patenCount > 0 && (
                      <span
                        className="flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium flex-shrink-0"
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

                    {/* Admin: Reaktivieren-Toggle */}
                    {isAdmin && (
                      <button
                        onClick={() => handleToggleActive(profile.id, true)}
                        disabled={processing === profile.id}
                        className="relative w-9 h-5 rounded-full flex-shrink-0 transition-colors disabled:opacity-50"
                        style={{ background: "var(--color-muted-foreground)" }}
                        title="Inaktiv — klicken zum Reaktivieren"
                      >
                        <span
                          className="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white transition-all"
                          style={{ boxShadow: "0 1px 2px rgba(0,0,0,0.2)" }}
                        />
                      </button>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
