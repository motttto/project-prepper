"use client";

import { useState, useEffect, useCallback, useMemo } from "react";
import { createClient } from "@/lib/supabase";
import type { ProjectMember, ProjectInvitation, ProjectGuest, OrgGuest, User } from "@/types/database";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import { useOrg } from "@/contexts/org-context";
import { showToast } from "@/hooks/use-toast";
import { IconX, IconUserPlus, IconTrash, IconSend, IconShield, IconPlus } from "@/components/ui/icons";
import { appConfirm } from "@/components/ui/confirm-dialog";

interface ProjectMembersPanelProps {
  projectId: string;
  /** Nicht mehr Owner-Privileg: jedes Projektmitglied darf verwalten (Migration 095). */
  canManage: boolean;
  show: boolean;
  onClose: () => void;
}

export function ProjectMembersPanel({
  projectId,
  canManage,
  show,
  onClose,
}: ProjectMembersPanelProps) {
  const supabase = createClient();
  const { orgId } = useOrg();
  const [members, setMembers] = useState<ProjectMember[]>([]);
  const [invitations, setInvitations] = useState<ProjectInvitation[]>([]);
  const [allProfiles, setAllProfiles] = useState<User[]>([]);
  const [loading, setLoading] = useState(true);

  // Form state
  const [mode, setMode] = useState<"add" | "invite" | null>(null);
  const [selectedProfileId, setSelectedProfileId] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [hasActiveAgreement, setHasActiveAgreement] = useState(false);

  // Guest state
  const [projectGuests, setProjectGuests] = useState<(ProjectGuest & { org_guests?: OrgGuest })[]>([]);
  const [orgGuests, setOrgGuests] = useState<OrgGuest[]>([]);
  const [selectedGuestId, setSelectedGuestId] = useState("");
  const [guestPlusOnes, setGuestPlusOnes] = useState(0);
  const [savingGuest, setSavingGuest] = useState(false);
  const [showGuestForm, setShowGuestForm] = useState(false);

  const loadData = useCallback(async () => {
    const [membersRes, invitationsRes, profilesRes, guestsRes, orgGuestsRes] = await Promise.all([
      supabase
        .from("project_members")
        .select("*, profiles(name, email)")
        .eq("project_id", projectId)
        .order("created_at"),
      supabase
        .from("project_invitations")
        .select("*, profiles!invited_profile_id(name, email)")
        .eq("project_id", projectId)
        .eq("status", "pending")
        .order("created_at", { ascending: false }),
      supabase
        .from("profiles")
        .select("id, email, name, role_id")
        .order("name"),
      supabase
        .from("project_guests")
        .select("*, org_guests(*)")
        .eq("project_id", projectId)
        .order("created_at"),
      orgId
        ? supabase.from("org_guests").select("*").eq("org_id", orgId).order("name")
        : Promise.resolve({ data: [] }),
    ]);

    if (membersRes.data) setMembers(membersRes.data as ProjectMember[]);
    if (invitationsRes.data) setInvitations(invitationsRes.data as ProjectInvitation[]);
    if (profilesRes.data) setAllProfiles(profilesRes.data as User[]);
    if (guestsRes.data) setProjectGuests(guestsRes.data as any);
    if (orgGuestsRes.data) setOrgGuests(orgGuestsRes.data as OrgGuest[]);

    // Aktive Kooperationsvereinbarung?
    const { data: ag } = await supabase
      .from("cooperation_agreements")
      .select("status")
      .eq("project_id", projectId)
      .maybeSingle();
    setHasActiveAgreement(ag?.status === "active");

    setLoading(false);
  }, [supabase, projectId, orgId]);

  useEffect(() => {
    if (show) loadData();
  }, [show, loadData]);

  // Realtime
  useRealtimeTable({
    table: "project_members",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadData,
    enabled: show,
  });
  useRealtimeTable({
    table: "project_invitations",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadData,
    enabled: show,
  });
  useRealtimeTable({
    table: "project_guests",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadData,
    enabled: show,
  });

  // Verfügbare Gäste (noch nicht diesem Projekt zugewiesen)
  const availableOrgGuests = useMemo(() => {
    const assignedIds = new Set(projectGuests.map(g => g.org_guest_id));
    return orgGuests.filter(g => !assignedIds.has(g.id));
  }, [orgGuests, projectGuests]);

  async function handleAddGuest() {
    if (!selectedGuestId) return;
    setSavingGuest(true);
    const { error: err } = await supabase.from("project_guests").insert({
      project_id: projectId,
      org_guest_id: selectedGuestId,
      plus_ones: guestPlusOnes,
    });
    if (err) {
      showToast("Fehler: " + err.message, "error");
    } else {
      showToast("Gast hinzugefügt", "success");
      setSelectedGuestId("");
      setGuestPlusOnes(0);
      setShowGuestForm(false);
      loadData();
    }
    setSavingGuest(false);
  }

  async function handleRemoveGuest(id: string) {
    if (!(await appConfirm("Gast wirklich entfernen?", { variant: "danger", confirmLabel: "Entfernen" }))) return;
    await supabase.from("project_guests").delete().eq("id", id);
    showToast("Gast entfernt", "success");
    loadData();
  }

  // Verfügbare Profiles (noch nicht Mitglied oder eingeladen)
  const memberIds = new Set(members.map((m) => m.profile_id));
  const invitedIds = new Set(invitations.map((i) => i.invited_profile_id));
  const availableProfiles = allProfiles.filter(
    (p) => !memberIds.has(p.id) && !invitedIds.has(p.id)
  );

  async function handleAddMember() {
    if (!selectedProfileId) return;
    setSaving(true);
    setError(null);

    const { error: err } = await supabase.from("project_members").insert({
      project_id: projectId,
      profile_id: selectedProfileId,
      role: "editor",
    });

    if (err) {
      setError("Fehler beim Hinzufügen: " + err.message);
    } else {
      setSelectedProfileId("");
      setMode(null);
      loadData();
    }
    setSaving(false);
  }

  async function handleInvite() {
    if (!selectedProfileId) return;
    setSaving(true);
    setError(null);

    const {
      data: { user },
    } = await supabase.auth.getUser();
    if (!user) return;

    const { data: invData, error: err } = await supabase.from("project_invitations").insert({
      project_id: projectId,
      invited_by: user.id,
      invited_profile_id: selectedProfileId,
      role: "editor",
    }).select("id").single();

    if (err) {
      setError("Fehler beim Einladen: " + err.message);
    } else {
      // Einladungs-Email senden (fire & forget)
      if (invData?.id) {
        supabase.functions.invoke("send-project-invite", {
          body: { invitation_id: invData.id },
        }).then(({ data }) => {
          if (data?.method === "email") {
            showToast("Einladung + Email gesendet", "success");
          }
        }).catch((e) => console.error("Email send error:", e));
      }
      setSelectedProfileId("");
      setMode(null);
      loadData();
    }
    setSaving(false);
  }

  async function handleRemoveMember(memberId: string) {
    if (!(await appConfirm("Mitglied wirklich entfernen?", { variant: "danger", confirmLabel: "Entfernen" }))) return;
    await supabase.from("project_members").delete().eq("id", memberId);
    loadData();
  }

  async function handleCancelInvitation(invId: string) {
    await supabase.from("project_invitations").delete().eq("id", invId);
    loadData();
  }

  async function handleResendInvitation(inv: ProjectInvitation) {
    // Send-Count erhöhen + Timestamp aktualisieren
    await supabase
      .from("project_invitations")
      .update({
        send_count: (inv.send_count || 1) + 1,
        last_sent_at: new Date().toISOString(),
      })
      .eq("id", inv.id);

    // Email erneut senden
    supabase.functions.invoke("send-project-invite", {
      body: { invitation_id: inv.id },
    }).then(({ data }) => {
      if (data?.method === "email") {
        showToast("Einladung erneut per Email gesendet", "success");
      } else {
        showToast("Einladung erneut markiert (kein SMTP konfiguriert)", "info");
      }
    }).catch((e) => console.error("Resend error:", e));

    loadData();
  }

  if (!show) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center"
      style={{ background: "rgba(0,0,0,0.4)" }}
    >
      <div
        className="rounded-xl shadow-2xl w-full max-w-lg max-h-[80vh] overflow-y-auto"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between p-4"
          style={{ borderBottom: "1px solid var(--color-border)" }}
        >
          <h2 className="text-lg font-semibold">Mitglieder verwalten</h2>
          <button onClick={onClose} className="p-1 rounded hover:opacity-70">
            <IconX size={20} />
          </button>
        </div>

        <div className="p-4 space-y-4">
          {loading ? (
            <p style={{ color: "var(--color-text-muted)" }}>Laden...</p>
          ) : (
            <>
              {/* Mitglieder-Liste */}
              <div>
                <h3 className="text-sm font-semibold mb-2" style={{ color: "var(--color-text-muted)" }}>
                  Mitglieder ({members.length})
                </h3>
                <div className="space-y-2">
                  {members.map((m) => {
                    const isProjectOwner = m.role === "owner";
                    return (
                      <div
                        key={m.id}
                        className="flex items-center justify-between p-2 rounded-lg"
                        style={{ background: "var(--color-muted)" }}
                      >
                        <div className="flex items-center gap-3">
                          <div
                            className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-semibold"
                            style={{ background: "var(--color-primary-light)", color: "var(--color-primary)" }}
                          >
                            {m.profiles?.name?.[0]?.toUpperCase() || "?"}
                          </div>
                          <div>
                            <p className="text-sm font-medium">{m.profiles?.name || "Unbekannt"}</p>
                            <p className="text-xs" style={{ color: "var(--color-text-muted)" }}>
                              {m.profiles?.email}
                            </p>
                          </div>
                        </div>
                        <div className="flex items-center gap-2">
                          {isProjectOwner && (
                            <span
                              className="px-2 py-0.5 rounded-full text-xs font-medium"
                              style={{ background: "#fef3c7", color: "#b45309" }}
                              title="Hat das Projekt erstellt"
                            >
                              <IconShield size={10} className="inline mr-1" />
                              Inhaber
                            </span>
                          )}
                          {canManage && (
                            <button
                              onClick={() => handleRemoveMember(m.id)}
                              className="p-1 rounded hover:opacity-70"
                              style={{ color: "var(--color-destructive)" }}
                              title="Entfernen"
                            >
                              <IconTrash size={14} />
                            </button>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>

              {/* Offene Einladungen */}
              {invitations.length > 0 && (
                <div>
                  <h3 className="text-sm font-semibold mb-2" style={{ color: "var(--color-text-muted)" }}>
                    Offene Einladungen ({invitations.length})
                  </h3>
                  <div className="space-y-2">
                    {invitations.map((inv) => (
                      <div
                        key={inv.id}
                        className="flex items-center justify-between p-2 rounded-lg"
                        style={{ background: "var(--color-muted)" }}
                      >
                        <div>
                          <p className="text-sm">{inv.profiles?.name || inv.profiles?.email}</p>
                          <p className="text-xs" style={{ color: "var(--color-warning)" }}>
                            Ausstehend
                            {(inv.send_count || 1) > 1 && (
                              <span style={{ color: "var(--color-muted-foreground)" }}>
                                {" "}({inv.send_count}x gesendet)
                              </span>
                            )}
                          </p>
                        </div>
                        {canManage && (
                          <div className="flex items-center gap-1">
                            <button
                              onClick={() => handleResendInvitation(inv)}
                              className="text-xs px-2 py-1 rounded hover:opacity-70"
                              style={{ color: "var(--color-primary)" }}
                              title="Einladung erneut senden"
                            >
                              Erneut senden
                            </button>
                            <button
                              onClick={() => handleCancelInvitation(inv.id)}
                              className="text-xs px-2 py-1 rounded hover:opacity-70"
                              style={{ color: "var(--color-destructive)" }}
                            >
                              Zurückziehen
                            </button>
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Gäste */}
              <div>
                <div className="flex items-center justify-between mb-2">
                  <h3 className="text-sm font-semibold" style={{ color: "var(--color-text-muted)" }}>
                    Gäste ({projectGuests.length})
                  </h3>
                  {canManage && (
                    <button
                      onClick={() => setShowGuestForm(!showGuestForm)}
                      className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg font-medium"
                      style={{ background: "var(--color-primary)", color: "white" }}
                    >
                      <IconPlus size={12} /> Gast zuweisen
                    </button>
                  )}
                </div>

                {showGuestForm && (
                  <div className="p-3 rounded-lg space-y-3 mb-2"
                    style={{ border: "1px solid var(--color-border)" }}>
                    {availableOrgGuests.length === 0 ? (
                      <p className="text-sm" style={{ color: "var(--color-text-muted)" }}>
                        Keine weiteren Gäste verfügbar. Neue Gäste unter <strong>Team → Gäste</strong> anlegen.
                      </p>
                    ) : (
                      <>
                        <div>
                          <label className="block text-xs mb-1" style={{ color: "var(--color-text-muted)" }}>Gast</label>
                          <select value={selectedGuestId} onChange={(e) => setSelectedGuestId(e.target.value)}
                            className="w-full p-2 rounded-lg text-sm"
                            style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}>
                            <option value="">— Gast wählen —</option>
                            {availableOrgGuests.map(g => (
                              <option key={g.id} value={g.id}>
                                {g.name}{g.company ? ` (${g.company})` : ""}{g.role ? ` — ${g.role}` : ""}
                              </option>
                            ))}
                          </select>
                        </div>
                        <div>
                          <label className="block text-xs mb-1" style={{ color: "var(--color-text-muted)" }}>+Begleitung</label>
                          <input type="number" min={0} value={guestPlusOnes}
                            onChange={(e) => setGuestPlusOnes(parseInt(e.target.value) || 0)}
                            className="w-full p-2 rounded-lg text-sm"
                            style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }} />
                        </div>
                      </>
                    )}
                    <div className="flex gap-2">
                      <button onClick={handleAddGuest} disabled={!selectedGuestId || savingGuest}
                        className="px-3 py-1.5 rounded-lg text-sm font-medium disabled:opacity-50"
                        style={{ background: "var(--color-primary)", color: "white" }}>
                        {savingGuest ? "Wird gespeichert..." : "Zuweisen"}
                      </button>
                      <button onClick={() => { setShowGuestForm(false); setSelectedGuestId(""); }}
                        className="px-3 py-1.5 rounded-lg text-sm"
                        style={{ border: "1px solid var(--color-border)" }}>
                        Abbrechen
                      </button>
                    </div>
                  </div>
                )}

                {projectGuests.length > 0 && (
                  <div className="space-y-2">
                    {projectGuests.map((pg) => {
                      const og = pg.org_guests;
                      const name = og?.name || pg.name || "?";
                      const detail = og?.company || og?.role;
                      return (
                        <div key={pg.id} className="flex items-center justify-between p-2 rounded-lg"
                          style={{ background: "var(--color-muted)" }}>
                          <div className="flex items-center gap-3">
                            <div className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-semibold shrink-0"
                              style={{ background: "#e0e7ff", color: "#4338ca" }}>
                              {name[0]?.toUpperCase() || "?"}
                            </div>
                            <div>
                              <p className="text-sm font-medium">
                                {name}
                                {pg.plus_ones > 0 && (
                                  <span className="ml-1.5 text-xs px-1.5 py-0.5 rounded"
                                    style={{ background: "var(--color-info-light)", color: "var(--color-info)" }}>
                                    +{pg.plus_ones}
                                  </span>
                                )}
                              </p>
                              {detail && (
                                <p className="text-xs" style={{ color: "var(--color-text-muted)" }}>{detail}</p>
                              )}
                            </div>
                          </div>
                          {canManage && (
                            <button onClick={() => handleRemoveGuest(pg.id)}
                              className="p-1 rounded hover:opacity-70"
                              style={{ color: "var(--color-destructive)" }} title="Entfernen">
                              <IconTrash size={14} />
                            </button>
                          )}
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>

              {/* Aktionen (nur für Owner) */}
              {canManage && !mode && (
                <div>
                  {hasActiveAgreement && (
                    <div
                      className="p-3 rounded-lg mb-3 text-xs"
                      style={{ background: "var(--color-warning-light)", color: "var(--color-warning)" }}
                    >
                      ⚠️ Kooperationsvereinbarung ist aktiv. Neue Mitglieder erfordern eine Amendment-Abstimmung aller bestehenden Beteiligten.
                    </div>
                  )}
                  <div className="flex gap-2">
                    <button
                      onClick={() => setMode("add")}
                      className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium"
                      style={{ background: "var(--color-primary)", color: "white" }}
                    >
                      <IconUserPlus size={16} /> Direkt hinzufügen
                    </button>
                    <button
                      onClick={() => setMode("invite")}
                      className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium"
                      style={{
                        background: "var(--color-surface)",
                        border: "1px solid var(--color-border)",
                      }}
                    >
                      <IconSend size={14} /> Einladen
                    </button>
                  </div>
                </div>
              )}

              {/* Add/Invite Form */}
              {canManage && mode && (
                <div
                  className="p-3 rounded-lg space-y-3"
                  style={{ border: "1px solid var(--color-border)" }}
                >
                  <h3 className="text-sm font-semibold">
                    {mode === "add" ? "Mitglied direkt hinzufügen" : "Einladung senden"}
                  </h3>

                  {availableProfiles.length === 0 ? (
                    <p className="text-sm" style={{ color: "var(--color-text-muted)" }}>
                      Alle Team-Mitglieder sind bereits hinzugefügt oder eingeladen.
                    </p>
                  ) : (
                    <>
                      <div>
                        <label className="block text-xs mb-1" style={{ color: "var(--color-text-muted)" }}>
                          Person
                        </label>
                        <select
                          value={selectedProfileId}
                          onChange={(e) => setSelectedProfileId(e.target.value)}
                          className="w-full p-2 rounded-lg text-sm"
                          style={{
                            background: "var(--color-surface)",
                            border: "1px solid var(--color-border)",
                          }}
                        >
                          <option value="">Bitte wählen...</option>
                          {availableProfiles.map((p) => (
                            <option key={p.id} value={p.id}>
                              {p.name} ({p.email})
                            </option>
                          ))}
                        </select>
                      </div>

                    </>
                  )}

                  {error && (
                    <p className="text-xs" style={{ color: "var(--color-destructive)" }}>
                      {error}
                    </p>
                  )}

                  <div className="flex gap-2">
                    <button
                      onClick={mode === "add" ? handleAddMember : handleInvite}
                      disabled={!selectedProfileId || saving}
                      className="px-3 py-1.5 rounded-lg text-sm font-medium disabled:opacity-50"
                      style={{ background: "var(--color-primary)", color: "white" }}
                    >
                      {saving
                        ? "Wird gespeichert..."
                        : mode === "add"
                          ? "Hinzufügen"
                          : "Einladung senden"}
                    </button>
                    <button
                      onClick={() => {
                        setMode(null);
                        setSelectedProfileId("");
                        setError(null);
                      }}
                      className="px-3 py-1.5 rounded-lg text-sm"
                      style={{ border: "1px solid var(--color-border)" }}
                    >
                      Abbrechen
                    </button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  );
}
