"use client";

import { useState, useEffect, useCallback, useMemo } from "react";
import { createClient } from "@/lib/supabase";
import type { TeamMember, ProjectContact, ProjectGuest, ProjectMember, OrgGuest } from "@/types/database";
import { IconPlus, IconTrash } from "@/components/ui/icons";
import { showToast } from "@/hooks/use-toast";
import { appConfirm } from "@/components/ui/confirm-dialog";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import { useCurrentUser } from "@/hooks/use-current-user";
// org_guests waren orgweit — Phase 6.5: durch group_guests ersetzen.
// Vorerst leere Liste, kein Org-Filter noetig.

interface TabTeamProps {
  projectId: string;
  onOpenMembersPanel?: () => void;
}

const defaultDepartments = [
  "Produktion",
  "Licht",
  "Ton",
  "Video",
  "Bühne",
  "Künstler",
  "Catering",
  "Transport",
  "Security",
];

interface InquiryRsvp {
  invitationId: string;
  profileId: string;
  name: string;
  email: string | null;
  avatarUrl: string | null;
  respondedAt: string | null;
}

export function TabTeam({ projectId, onOpenMembersPanel }: TabTeamProps) {
  const supabase = createClient();
  const currentUser = useCurrentUser();
  const currentUserId = currentUser?.id ?? null;
  const [projectMembers, setProjectMembers] = useState<(ProjectMember & { profiles?: { name: string; email: string } })[]>([]);
  const [members, setMembers] = useState<TeamMember[]>([]);
  const [contacts, setContacts] = useState<ProjectContact[]>([]);
  const [guests, setGuests] = useState<(ProjectGuest & { org_guests?: OrgGuest })[]>([]);
  const [orgGuests, setOrgGuests] = useState<OrgGuest[]>([]);
  const [inquiryRsvps, setInquiryRsvps] = useState<InquiryRsvp[]>([]);
  const [linkedInquiryId, setLinkedInquiryId] = useState<string | null>(null);
  const [inquiryCreator, setInquiryCreator] = useState<{ id: string; name: string; email: string | null; avatarUrl: string | null } | null>(null);
  const [loading, setLoading] = useState(true);

  // Guest form
  const [showGuestPicker, setShowGuestPicker] = useState(false);
  const [selectedOrgGuestId, setSelectedOrgGuestId] = useState("");
  const [guestPlusOnes, setGuestPlusOnes] = useState(0);
  const [savingGuest, setSavingGuest] = useState(false);

  // Team form
  const [showTeamForm, setShowTeamForm] = useState(false);
  const [teamName, setTeamName] = useState("");
  const [teamRole, setTeamRole] = useState("");
  const [teamDepartment, setTeamDepartment] = useState("");
  const [teamPhone, setTeamPhone] = useState("");
  const [teamEmail, setTeamEmail] = useState("");
  const [teamNotes, setTeamNotes] = useState("");
  const [savingTeam, setSavingTeam] = useState(false);

  // Contact form
  const [showContactForm, setShowContactForm] = useState(false);
  const [contactName, setContactName] = useState("");
  const [contactRole, setContactRole] = useState("");
  const [contactCompany, setContactCompany] = useState("");
  const [contactPhone, setContactPhone] = useState("");
  const [contactEmail, setContactEmail] = useState("");
  const [savingContact, setSavingContact] = useState(false);

  const loadData = useCallback(async () => {
    const [membersRes, teamRes, guestsRes, contactsRes, orgGuestsRes, inquiryRes] = await Promise.all([
      supabase.from("project_members").select("*, profiles(name, email)").eq("project_id", projectId),
      supabase.from("project_team").select("*").eq("project_id", projectId).order("department").order("created_at"),
      supabase.from("project_guests").select("*, org_guests(*)").eq("project_id", projectId).order("created_at"),
      supabase.from("project_contacts").select("*").eq("project_id", projectId).order("created_at"),
      // org_guests entfaellt im neuen Modell
      Promise.resolve({ data: [] as OrgGuest[] }),
      // Verknuepfte Anfrage zum Projekt finden (nur id + created_by, ohne FK-Join)
      supabase
        .from("inquiries")
        .select("id, created_by")
        .eq("project_id", projectId)
        .maybeSingle(),
    ]);
    if (membersRes.data) setProjectMembers(membersRes.data as any);
    if (teamRes.data) setMembers(teamRes.data as TeamMember[]);
    if (guestsRes.data) setGuests(guestsRes.data as any);
    if (contactsRes.data) setContacts(contactsRes.data as ProjectContact[]);
    if (orgGuestsRes.data) setOrgGuests(orgGuestsRes.data as OrgGuest[]);

    // Wenn Projekt aus Anfrage erstellt wurde: Zugesagte RSVPs laden + Ersteller-Profil
    const inquiryRow = inquiryRes.data as { id: string; created_by: string | null } | null;
    const inquiryId = inquiryRow?.id ?? null;
    setLinkedInquiryId(inquiryId);

    // Ersteller-Profil separat laden (eigener Query, damit Inquiry-Query nicht
    // von einem fragilen FK-Alias abhaengt)
    if (inquiryRow?.created_by) {
      const { data: creatorProfile } = await supabase
        .from("profiles")
        .select("id, name, email, avatar_url")
        .eq("id", inquiryRow.created_by)
        .maybeSingle();
      if (creatorProfile) {
        setInquiryCreator({
          id: creatorProfile.id,
          name: creatorProfile.name || "Unbekannt",
          email: creatorProfile.email || null,
          avatarUrl: creatorProfile.avatar_url || null,
        });
      } else {
        setInquiryCreator(null);
      }
    } else {
      setInquiryCreator(null);
    }
    if (inquiryId) {
      const { data: rsvps } = await supabase
        .from("inquiry_invitations")
        .select("id, invited_profile_id, status, responded_at, profiles!invited_profile_id(id, name, email, avatar_url)")
        .eq("inquiry_id", inquiryId)
        .eq("status", "accepted");
      if (rsvps) {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        setInquiryRsvps(rsvps.map((r: any) => ({
          invitationId: r.id,
          profileId: r.invited_profile_id,
          name: r.profiles?.name || "Unbekannt",
          email: r.profiles?.email || null,
          avatarUrl: r.profiles?.avatar_url || null,
          respondedAt: r.responded_at,
        })));
      }
    } else {
      setInquiryRsvps([]);
    }
    setLoading(false);
  }, [supabase, projectId]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // Realtime: Live-Synchronisation
  useRealtimeTable({
    table: "project_team",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadData,
  });
  useRealtimeTable({
    table: "project_contacts",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadData,
  });
  useRealtimeTable({
    table: "project_guests",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadData,
  });
  useRealtimeTable({
    table: "project_members",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadData,
  });
  useRealtimeTable({
    table: "inquiry_invitations",
    filter: linkedInquiryId
      ? { column: "inquiry_id", value: linkedInquiryId }
      : undefined,
    onDataChange: loadData,
    enabled: !!linkedInquiryId,
  });

  // Gruppen-Logik: nach department gruppieren
  const grouped = useMemo(() => {
    const map: Record<string, TeamMember[]> = {};
    for (const m of members) {
      const dept = m.department || "Ohne Abteilung";
      if (!map[dept]) map[dept] = [];
      map[dept].push(m);
    }
    // Sortierte Keys: benannte Abteilungen zuerst, "Ohne Abteilung" zuletzt
    const keys = Object.keys(map).sort((a, b) => {
      if (a === "Ohne Abteilung") return 1;
      if (b === "Ohne Abteilung") return -1;
      return a.localeCompare(b, "de");
    });
    return { map, keys };
  }, [members]);

  // Farben für Abteilungen
  const deptColors: Record<string, { bg: string; text: string }> = {
    Produktion: { bg: "#dbeafe", text: "#1d4ed8" },
    Licht: { bg: "#fef3c7", text: "#b45309" },
    Ton: { bg: "#e0e7ff", text: "#4338ca" },
    Video: { bg: "#fce7f3", text: "#be185d" },
    "Bühne": { bg: "#dcfce7", text: "#16a34a" },
    "Künstler": { bg: "#fef9c3", text: "#a16207" },
    Catering: { bg: "#ffe4e6", text: "#be123c" },
    Transport: { bg: "#f3e8ff", text: "#7c3aed" },
    Security: { bg: "#f1f5f9", text: "#475569" },
  };
  function getDeptColor(dept: string) {
    return deptColors[dept] || { bg: "var(--color-muted)", text: "var(--color-muted-foreground)" };
  }

  // Guest handlers
  async function handleAddGuest(e: React.FormEvent) {
    e.preventDefault();
    if (!selectedOrgGuestId) return;
    setSavingGuest(true);
    const { error } = await supabase.from("project_guests").insert({
      project_id: projectId,
      org_guest_id: selectedOrgGuestId,
      plus_ones: guestPlusOnes,
      approval_status: "proposed",
      proposed_by: currentUserId,
    });
    if (error) {
      showToast("Fehler: " + error.message, "error");
    } else {
      setSelectedOrgGuestId(""); setGuestPlusOnes(0);
      setShowGuestPicker(false);
      loadData();
      showToast("Gast hinzugefügt", "success");
    }
    setSavingGuest(false);
  }

  // Nur Gäste anzeigen die noch nicht zugewiesen sind
  const availableGuests = useMemo(() => {
    const assignedIds = new Set(guests.map(g => g.org_guest_id));
    return orgGuests.filter(g => !assignedIds.has(g.id));
  }, [orgGuests, guests]);

  async function handleDeleteGuest(id: string) {
    if (!(await appConfirm("Gast wirklich entfernen?", { variant: "danger", confirmLabel: "Entfernen" }))) return;
    const { error } = await supabase.from("project_guests").delete().eq("id", id);
    if (error) {
      showToast("Fehler: " + error.message, "error");
    } else {
      loadData();
      showToast("Gast entfernt", "success");
    }
  }

  async function handleGuestStatusChange(id: string, status: ProjectGuest["status"]) {
    await supabase.from("project_guests").update({ status }).eq("id", id);
    loadData();
  }

  const guestStatusLabels: Record<ProjectGuest["status"], string> = {
    invited: "Eingeladen",
    confirmed: "Zugesagt",
    declined: "Abgesagt",
    attended: "Anwesend",
  };
  const guestStatusColors: Record<ProjectGuest["status"], { bg: string; text: string }> = {
    invited: { bg: "var(--color-info-light)", text: "var(--color-info)" },
    confirmed: { bg: "var(--color-success-light)", text: "var(--color-success)" },
    declined: { bg: "var(--color-destructive-light)", text: "var(--color-destructive)" },
    attended: { bg: "#dbeafe", text: "#1d4ed8" },
  };

  const guestStats = useMemo(() => {
    const total = guests.reduce((sum, g) => sum + 1 + g.plus_ones, 0);
    const confirmed = guests.filter(g => g.status === "confirmed" || g.status === "attended")
      .reduce((sum, g) => sum + 1 + g.plus_ones, 0);
    return { total, confirmed };
  }, [guests]);

  async function handleAddTeamMember(e: React.FormEvent) {
    e.preventDefault();
    setSavingTeam(true);
    const { error } = await supabase.from("project_team").insert({
      project_id: projectId,
      name: teamName,
      role: teamRole,
      department: teamDepartment || null,
      phone: teamPhone || null,
      email: teamEmail || null,
      notes: teamNotes || null,
    });
    if (!error) {
      setTeamName(""); setTeamRole(""); setTeamDepartment(""); setTeamPhone(""); setTeamEmail(""); setTeamNotes("");
      setShowTeamForm(false);
      loadData();
    }
    setSavingTeam(false);
  }

  async function handleDeleteTeamMember(id: string) {
    if (!(await appConfirm("Teammitglied wirklich entfernen?", { variant: "danger", confirmLabel: "Entfernen" }))) return;
    await supabase.from("project_team").delete().eq("id", id);
    loadData();
  }

  async function handleAddContact(e: React.FormEvent) {
    e.preventDefault();
    setSavingContact(true);
    const { error } = await supabase.from("project_contacts").insert({
      project_id: projectId,
      name: contactName,
      role: contactRole,
      company: contactCompany || null,
      phone: contactPhone || null,
      email: contactEmail || null,
      approval_status: "proposed",
      proposed_by: currentUserId,
    });
    if (!error) {
      setContactName(""); setContactRole(""); setContactCompany(""); setContactPhone(""); setContactEmail("");
      setShowContactForm(false);
      loadData();
    }
    setSavingContact(false);
  }

  async function handleDeleteContact(id: string) {
    if (!(await appConfirm("Kontakt wirklich entfernen?", { variant: "danger", confirmLabel: "Entfernen" }))) return;
    await supabase.from("project_contacts").delete().eq("id", id);
    loadData();
  }

  // ── Vier-Augen: Bestaetigen / Ablehnen fuer Gaeste + Kontakte ──
  async function handleApproveGuest(id: string) {
    await supabase.from("project_guests").update({
      approval_status: "confirmed",
      approved_by: currentUserId,
      approved_at: new Date().toISOString(),
    }).eq("id", id);
    loadData();
  }
  async function handleRejectGuest(id: string) {
    if (!(await appConfirm("Gast ablehnen und entfernen?", { variant: "danger", confirmLabel: "Ablehnen" }))) return;
    await supabase.from("project_guests").delete().eq("id", id);
    loadData();
  }
  async function handleApproveContact(id: string) {
    await supabase.from("project_contacts").update({
      approval_status: "confirmed",
      approved_by: currentUserId,
      approved_at: new Date().toISOString(),
    }).eq("id", id);
    loadData();
  }
  async function handleRejectContact(id: string) {
    if (!(await appConfirm("Kontakt ablehnen und entfernen?", { variant: "danger", confirmLabel: "Ablehnen" }))) return;
    await supabase.from("project_contacts").delete().eq("id", id);
    loadData();
  }

  if (loading) {
    return <div className="py-8 text-center" style={{ color: "var(--color-muted-foreground)" }}>Team wird geladen...</div>;
  }

  const inputStyle = { border: "1px solid var(--color-border)", background: "var(--color-background)" };

  // Konsolidierte Team-Mitglieder Liste (project_members + RSVP zugesagt + Anfrage-Ersteller).
  // Pro profile_id eine Karte, mit Markern fuer alle zutreffenden Rollen.
  type ConsolidatedMember = {
    profileId: string;
    name: string;
    email: string | null;
    avatarUrl: string | null;
    isProjectMember: boolean;
    isOwner: boolean;
    isInquiryCreator: boolean;
    hasAcceptedRsvp: boolean;
  };
  const consolidatedMembers = (() => {
    const map = new Map<string, ConsolidatedMember>();
    const ensure = (id: string, base: Partial<ConsolidatedMember>) => {
      if (!map.has(id)) {
        map.set(id, {
          profileId: id,
          name: base.name || "Unbekannt",
          email: base.email ?? null,
          avatarUrl: base.avatarUrl ?? null,
          isProjectMember: false,
          isOwner: false,
          isInquiryCreator: false,
          hasAcceptedRsvp: false,
        });
      } else {
        const existing = map.get(id)!;
        // Falls Avatar/Email vorher null waren, jetzt fuellen
        if (!existing.avatarUrl && base.avatarUrl) existing.avatarUrl = base.avatarUrl;
        if (!existing.email && base.email) existing.email = base.email;
      }
      return map.get(id)!;
    };
    for (const pm of projectMembers) {
      const m = ensure(pm.profile_id, {
        name: pm.profiles?.name,
        email: pm.profiles?.email,
      });
      m.isProjectMember = true;
      if (pm.role === "owner") m.isOwner = true;
    }
    for (const r of inquiryRsvps) {
      const m = ensure(r.profileId, {
        name: r.name,
        email: r.email,
        avatarUrl: r.avatarUrl,
      });
      m.hasAcceptedRsvp = true;
    }
    if (inquiryCreator) {
      const m = ensure(inquiryCreator.id, {
        name: inquiryCreator.name,
        email: inquiryCreator.email,
        avatarUrl: inquiryCreator.avatarUrl,
      });
      m.isInquiryCreator = true;
    }
    return Array.from(map.values()).sort((a, b) => {
      // Owner zuerst, dann Mitglieder, dann RSVPs, dann reine Ersteller
      const score = (x: ConsolidatedMember) =>
        (x.isOwner ? 0 : x.isProjectMember ? 1 : x.hasAcceptedRsvp ? 2 : 3);
      const diff = score(a) - score(b);
      if (diff !== 0) return diff;
      return a.name.localeCompare(b.name, "de");
    });
  })();
  const teamTotalCount = consolidatedMembers.length + members.length;

  return (
    <div className="space-y-8">
      {/* ===== TEAM SECTION (unifiziert: Mitglieder + RSVP + Crew) ===== */}
      <div>
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-3">
            <h2 className="text-lg font-semibold">Team</h2>
            <span className="text-xs px-2 py-0.5 rounded-full"
              style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
              {teamTotalCount} {teamTotalCount === 1 ? "Person" : "Personen"}
            </span>
          </div>
          <div className="flex items-center gap-2">
            {onOpenMembersPanel && (
              <button
                onClick={onOpenMembersPanel}
                className="flex items-center gap-1.5 px-3 py-1.5 text-sm rounded-lg transition-colors"
                style={{ border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}
              >
                <IconPlus size={16} />
                Mitglied einladen
              </button>
            )}
            <button
              onClick={() => setShowTeamForm(!showTeamForm)}
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-white rounded-lg transition-colors"
              style={{ background: "var(--color-primary)" }}
            >
              <IconPlus size={16} />
              Crew hinzufügen
            </button>
          </div>
        </div>

        {/* Konsolidierte Mitglieder-Liste (Mitglied + Anfrage-Ersteller + RSVP zugesagt) */}
        {consolidatedMembers.length > 0 && (
          <div className="mb-4">
            <div className="flex flex-wrap gap-2">
              {consolidatedMembers.map((m) => {
                const markers: { label: string; tone: "primary" | "warning" | "success" }[] = [];
                if (m.isOwner) markers.push({ label: "Inhaber", tone: "warning" });
                else if (m.isProjectMember) markers.push({ label: "Mitglied", tone: "primary" });
                if (m.isInquiryCreator) markers.push({ label: "Anfrage erstellt", tone: "primary" });
                if (m.hasAcceptedRsvp) markers.push({ label: "Zugesagt", tone: "success" });
                const toneColor = (t: "primary" | "warning" | "success") =>
                  t === "warning"
                    ? { bg: "#fef3c7", color: "#b45309" }
                    : t === "success"
                      ? { bg: "var(--color-success-light)", color: "var(--color-success)" }
                      : { bg: "var(--color-primary-light)", color: "var(--color-primary)" };
                return (
                  <div key={m.profileId}
                    className="flex items-center gap-2.5 px-3 py-2 rounded-lg"
                    style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
                  >
                    {m.avatarUrl ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={m.avatarUrl} alt={m.name}
                        className="w-9 h-9 rounded-full object-cover shrink-0" />
                    ) : (
                      <div className="w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold shrink-0"
                        style={{ background: "var(--color-primary-light)", color: "var(--color-primary)" }}>
                        {m.name.charAt(0).toUpperCase()}
                      </div>
                    )}
                    <div className="min-w-0">
                      <div className="text-sm font-medium">{m.name}</div>
                      {m.email && (
                        <div className="text-xs truncate"
                          style={{ color: "var(--color-muted-foreground)" }}>
                          {m.email}
                        </div>
                      )}
                      {markers.length > 0 && (
                        <div className="flex flex-wrap gap-1 mt-1">
                          {markers.map((mk) => {
                            const c = toneColor(mk.tone);
                            return (
                              <span key={mk.label}
                                className="text-[10px] px-1.5 py-0.5 rounded font-medium"
                                style={{ background: c.bg, color: c.color }}>
                                {mk.label}
                              </span>
                            );
                          })}
                        </div>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        )}

        {/* Crew-Zwischenheader (project_team) — nur wenn drueber Mitglieder stehen */}
        {consolidatedMembers.length > 0 && members.length > 0 && (
          <div className="flex items-center gap-2 mb-2 mt-4">
            <span className="text-xs font-semibold uppercase tracking-wider"
              style={{ color: "var(--color-muted-foreground)" }}>
              Crew
            </span>
            <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
              {members.length}
            </span>
            <div className="flex-1 h-px" style={{ background: "var(--color-border)" }} />
          </div>
        )}

        {showTeamForm && (
          <div className="mb-4 p-5 rounded-lg" style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}>
            <h3 className="font-medium mb-3">Neues Teammitglied</h3>
            <form onSubmit={handleAddTeamMember} className="space-y-3">
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Name *</label>
                  <input type="text" value={teamName} onChange={(e) => setTeamName(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle}
                    placeholder="z.B. Max Mustermann" required />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Rolle *</label>
                  <input type="text" value={teamRole} onChange={(e) => setTeamRole(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle}
                    placeholder="z.B. Lichttechniker, FOH" required />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Abteilung</label>
                  <input
                    type="text"
                    list="dept-suggestions"
                    value={teamDepartment}
                    onChange={(e) => setTeamDepartment(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle}
                    placeholder="z.B. Licht, Ton, Produktion"
                  />
                  <datalist id="dept-suggestions">
                    {defaultDepartments.map((d) => <option key={d} value={d} />)}
                  </datalist>
                </div>
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Telefon</label>
                  <input type="tel" value={teamPhone} onChange={(e) => setTeamPhone(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle}
                    placeholder="+49..." />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">E-Mail</label>
                  <input type="email" value={teamEmail} onChange={(e) => setTeamEmail(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Notizen</label>
                  <input type="text" value={teamNotes} onChange={(e) => setTeamNotes(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle}
                    placeholder="z.B. Anreise mit eigenem Bus" />
                </div>
              </div>
              <div className="flex gap-2">
                <button type="submit" disabled={savingTeam}
                  className="px-4 py-2 text-sm text-white rounded-lg disabled:opacity-50"
                  style={{ background: "var(--color-primary)" }}>
                  {savingTeam ? "Wird gespeichert..." : "Hinzufügen"}
                </button>
                <button type="button" onClick={() => setShowTeamForm(false)}
                  className="px-4 py-2 text-sm rounded-lg"
                  style={{ border: "1px solid var(--color-border)" }}>
                  Abbrechen
                </button>
              </div>
            </form>
          </div>
        )}

        {members.length === 0 && teamTotalCount === 0 ? (
          <div className="text-center py-8 rounded-lg"
            style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}>
            Noch keine Teammitglieder eingetragen
          </div>
        ) : members.length === 0 ? null : (
          <div className="space-y-5">
            {grouped.keys.map((dept) => {
              const deptMembers = grouped.map[dept];
              const color = getDeptColor(dept);
              return (
                <div key={dept}>
                  {/* Department header */}
                  <div className="flex items-center gap-2 mb-2">
                    <span className="text-xs font-semibold px-2.5 py-1 rounded-full"
                      style={{ background: color.bg, color: color.text }}>
                      {dept}
                    </span>
                    <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                      {deptMembers.length} {deptMembers.length === 1 ? "Person" : "Personen"}
                    </span>
                    <div className="flex-1 h-px" style={{ background: "var(--color-border)" }} />
                  </div>
                  {/* Members in this department */}
                  <div className="space-y-1.5">
                    {deptMembers.map((m) => (
                      <div key={m.id} className="group flex items-center justify-between p-3 rounded-lg"
                        style={{ border: "1px solid var(--color-border)" }}>
                        <div className="flex items-center gap-3 min-w-0">
                          <div className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-semibold shrink-0"
                            style={{ background: color.bg, color: color.text }}>
                            {m.name.charAt(0).toUpperCase()}
                          </div>
                          <div className="min-w-0">
                            <div className="font-medium text-sm flex items-center gap-2">
                              {m.name}
                              <span className="text-xs px-1.5 py-0.5 rounded"
                                style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
                                {m.role}
                              </span>
                            </div>
                            <div className="text-xs flex items-center gap-3" style={{ color: "var(--color-muted-foreground)" }}>
                              {m.phone && <span>{m.phone}</span>}
                              {m.email && <span>{m.email}</span>}
                              {m.notes && <span>· {m.notes}</span>}
                            </div>
                          </div>
                        </div>
                        <button onClick={() => handleDeleteTeamMember(m.id)}
                          className="opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded shrink-0"
                          style={{ color: "var(--color-destructive)" }} title="Entfernen">
                          <IconTrash size={14} />
                        </button>
                      </div>
                    ))}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>

      {/* ===== GUESTS SECTION ===== */}
      <div>
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-3">
            <h2 className="text-lg font-semibold">Gästeliste</h2>
            <span className="text-xs px-2 py-0.5 rounded-full"
              style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
              {guestStats.confirmed}/{guestStats.total} Personen
            </span>
          </div>
          <button
            onClick={() => setShowGuestPicker(!showGuestPicker)}
            className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-white rounded-lg transition-colors"
            style={{ background: "var(--color-primary)" }}
          >
            <IconPlus size={16} />
            Gast zuweisen
          </button>
        </div>

        {showGuestPicker && (
          <div className="mb-4 p-5 rounded-lg" style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}>
            <h3 className="font-medium mb-3">Gast aus Verzeichnis zuweisen</h3>
            {availableGuests.length === 0 ? (
              <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
                Keine weiteren Gäste verfügbar. Neue Gäste können unter <strong>Team → Gäste</strong> angelegt werden.
              </p>
            ) : (
              <form onSubmit={handleAddGuest} className="space-y-3">
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <div className="sm:col-span-2">
                    <label className="block text-sm font-medium mb-1">Gast auswählen *</label>
                    <select value={selectedOrgGuestId} onChange={(e) => setSelectedOrgGuestId(e.target.value)}
                      className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} required>
                      <option value="">— Gast wählen —</option>
                      {availableGuests.map(g => (
                        <option key={g.id} value={g.id}>
                          {g.name}{g.company ? ` (${g.company})` : ""}{g.role ? ` — ${g.role}` : ""}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1">+Begleitung</label>
                    <input type="number" min={0} value={guestPlusOnes} onChange={(e) => setGuestPlusOnes(parseInt(e.target.value) || 0)}
                      className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} />
                  </div>
                </div>
                <div className="flex gap-2">
                  <button type="submit" disabled={savingGuest || !selectedOrgGuestId}
                    className="px-4 py-2 text-sm text-white rounded-lg disabled:opacity-50"
                    style={{ background: "var(--color-primary)" }}>
                    {savingGuest ? "Wird gespeichert..." : "Zuweisen"}
                  </button>
                  <button type="button" onClick={() => setShowGuestPicker(false)}
                    className="px-4 py-2 text-sm rounded-lg"
                    style={{ border: "1px solid var(--color-border)" }}>
                    Abbrechen
                  </button>
                </div>
              </form>
            )}
          </div>
        )}

        {guests.length === 0 ? (
          <div className="text-center py-6 rounded-lg"
            style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}>
            Noch keine Gäste zugewiesen. Gäste werden unter Team → Gäste verwaltet.
          </div>
        ) : (
          <div className="rounded-lg overflow-hidden" style={{ border: "1px solid var(--color-border)" }}>
            <div className="overflow-x-auto">
            <table className="w-full text-sm min-w-[550px]">
              <thead>
                <tr style={{ background: "var(--color-muted)" }}>
                  <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Name</th>
                  <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Firma / Rolle</th>
                  <th className="text-center px-3 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>+1</th>
                  <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Status</th>
                  <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Kontakt</th>
                  <th className="px-4 py-3 w-10" />
                </tr>
              </thead>
              <tbody>
                {guests.map((g) => {
                  const og = g.org_guests;
                  const displayName = og?.name || g.name || "?";
                  const displayCompany = og?.company || g.company;
                  const displayRole = og?.role || g.role;
                  const displayEmail = og?.email || g.email;
                  const displayPhone = og?.phone || g.phone;
                  const displayNotes = og?.notes || g.notes;
                  const sc = guestStatusColors[g.status];
                  const isProposed = g.approval_status === "proposed";
                  const isOwnProposal = isProposed && g.proposed_by === currentUserId;
                  return (
                    <tr key={g.id}
                      style={{
                        borderTop: "1px solid var(--color-border)",
                        background: isProposed
                          ? "color-mix(in srgb, var(--color-warning) 5%, transparent)"
                          : undefined,
                      }}
                      className="group">
                      <td className="px-4 py-3">
                        <div className="font-medium flex items-center gap-2">
                          {displayName}
                          {isProposed && (
                            <span className="text-[10px] px-1.5 py-0.5 rounded font-medium uppercase tracking-wider"
                              style={{ background: "var(--color-warning-light, #fef3c7)", color: "var(--color-warning, #d97706)" }}>
                              Vorgeschlagen
                            </span>
                          )}
                        </div>
                        {displayNotes && <div className="text-xs mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>{displayNotes}</div>}
                      </td>
                      <td className="px-4 py-3">
                        <div>{displayCompany || "–"}</div>
                        {displayRole && <span className="text-xs px-1.5 py-0.5 rounded" style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>{displayRole}</span>}
                      </td>
                      <td className="px-3 py-3 text-center">
                        {g.plus_ones > 0 ? (
                          <span className="text-xs font-semibold px-1.5 py-0.5 rounded" style={{ background: "var(--color-info-light)", color: "var(--color-info)" }}>+{g.plus_ones}</span>
                        ) : "–"}
                      </td>
                      <td className="px-4 py-3">
                        <select
                          value={g.status}
                          onChange={(e) => handleGuestStatusChange(g.id, e.target.value as ProjectGuest["status"])}
                          className="text-xs px-2 py-1 rounded-full font-medium border-0 cursor-pointer"
                          style={{ background: sc.bg, color: sc.text }}
                        >
                          {Object.entries(guestStatusLabels).map(([val, label]) => (
                            <option key={val} value={val}>{label}</option>
                          ))}
                        </select>
                      </td>
                      <td className="px-4 py-3 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                        {displayEmail && <div>{displayEmail}</div>}
                        {displayPhone && <div>{displayPhone}</div>}
                        {!displayEmail && !displayPhone && "–"}
                      </td>
                      <td className="px-4 py-3 text-right">
                        {isProposed && !isOwnProposal && (
                          <div className="flex gap-1 justify-end mb-1">
                            <button onClick={() => handleApproveGuest(g.id)}
                              className="text-xs px-2 py-1 rounded-lg font-medium text-white"
                              style={{ background: "var(--color-success)" }}>
                              Bestätigen
                            </button>
                            <button onClick={() => handleRejectGuest(g.id)}
                              className="text-xs px-2 py-1 rounded-lg font-medium"
                              style={{ border: "1px solid var(--color-destructive)", color: "var(--color-destructive)" }}>
                              Ablehnen
                            </button>
                          </div>
                        )}
                        {isOwnProposal && (
                          <div className="text-[10px] mb-1" style={{ color: "var(--color-muted-foreground)" }}>
                            Eigener Vorschlag — wartet auf Bestätigung
                          </div>
                        )}
                        <button onClick={() => handleDeleteGuest(g.id)}
                          className="opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded"
                          style={{ color: "var(--color-destructive)" }} title="Entfernen">
                          <IconTrash size={14} />
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
            </div>
          </div>
        )}
      </div>

      {/* ===== CONTACTS SECTION ===== */}
      <div>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold">Externe Kontakte</h2>
          <button
            onClick={() => setShowContactForm(!showContactForm)}
            className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-white rounded-lg transition-colors"
            style={{ background: "var(--color-primary)" }}
          >
            <IconPlus size={16} />
            Kontakt hinzufügen
          </button>
        </div>

        {showContactForm && (
          <div className="mb-4 p-5 rounded-lg" style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}>
            <h3 className="font-medium mb-3">Neuer Kontakt</h3>
            <form onSubmit={handleAddContact} className="space-y-3">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Name *</label>
                  <input type="text" value={contactName} onChange={(e) => setContactName(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle}
                    placeholder="z.B. Hans Müller" required />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Rolle *</label>
                  <input type="text" value={contactRole} onChange={(e) => setContactRole(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle}
                    placeholder="z.B. Caterer, Security, Veranstalter" required />
                </div>
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Firma</label>
                  <input type="text" value={contactCompany} onChange={(e) => setContactCompany(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Telefon</label>
                  <input type="tel" value={contactPhone} onChange={(e) => setContactPhone(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">E-Mail</label>
                  <input type="email" value={contactEmail} onChange={(e) => setContactEmail(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} />
                </div>
              </div>
              <div className="flex gap-2">
                <button type="submit" disabled={savingContact}
                  className="px-4 py-2 text-sm text-white rounded-lg disabled:opacity-50"
                  style={{ background: "var(--color-primary)" }}>
                  {savingContact ? "Wird gespeichert..." : "Hinzufügen"}
                </button>
                <button type="button" onClick={() => setShowContactForm(false)}
                  className="px-4 py-2 text-sm rounded-lg"
                  style={{ border: "1px solid var(--color-border)" }}>
                  Abbrechen
                </button>
              </div>
            </form>
          </div>
        )}

        {contacts.length === 0 ? (
          <div className="text-center py-8 rounded-lg"
            style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}>
            Noch keine externen Kontakte eingetragen
          </div>
        ) : (
          <div className="rounded-lg overflow-hidden" style={{ border: "1px solid var(--color-border)" }}>
            <div className="overflow-x-auto">
            <table className="w-full text-sm min-w-[500px]">
              <thead>
                <tr style={{ background: "var(--color-muted)" }}>
                  <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Name</th>
                  <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Rolle</th>
                  <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Firma</th>
                  <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Telefon</th>
                  <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>E-Mail</th>
                  <th className="px-4 py-3 w-10" />
                </tr>
              </thead>
              <tbody>
                {contacts.map((c) => {
                  const isProposed = c.approval_status === "proposed";
                  const isOwnProposal = isProposed && c.proposed_by === currentUserId;
                  return (
                    <tr key={c.id}
                      style={{
                        borderTop: "1px solid var(--color-border)",
                        background: isProposed
                          ? "color-mix(in srgb, var(--color-warning) 5%, transparent)"
                          : undefined,
                      }}
                      className="group">
                      <td className="px-4 py-3 font-medium">
                        <div className="flex items-center gap-2">
                          {c.name}
                          {isProposed && (
                            <span className="text-[10px] px-1.5 py-0.5 rounded font-medium uppercase tracking-wider"
                              style={{ background: "var(--color-warning-light, #fef3c7)", color: "var(--color-warning, #d97706)" }}>
                              Vorgeschlagen
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <span className="text-xs px-2 py-0.5 rounded-full"
                          style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
                          {c.role}
                        </span>
                      </td>
                      <td className="px-4 py-3" style={{ color: c.company ? undefined : "var(--color-muted-foreground)" }}>
                        {c.company || "–"}
                      </td>
                      <td className="px-4 py-3">{c.phone || "–"}</td>
                      <td className="px-4 py-3">{c.email || "–"}</td>
                      <td className="px-4 py-3 text-right">
                        {isProposed && !isOwnProposal && (
                          <div className="flex gap-1 justify-end mb-1">
                            <button onClick={() => handleApproveContact(c.id)}
                              className="text-xs px-2 py-1 rounded-lg font-medium text-white"
                              style={{ background: "var(--color-success)" }}>
                              Bestätigen
                            </button>
                            <button onClick={() => handleRejectContact(c.id)}
                              className="text-xs px-2 py-1 rounded-lg font-medium"
                              style={{ border: "1px solid var(--color-destructive)", color: "var(--color-destructive)" }}>
                              Ablehnen
                            </button>
                          </div>
                        )}
                        {isOwnProposal && (
                          <div className="text-[10px] mb-1" style={{ color: "var(--color-muted-foreground)" }}>
                            Eigener Vorschlag — wartet auf Bestätigung
                          </div>
                        )}
                        <button onClick={() => handleDeleteContact(c.id)}
                          className="opacity-0 group-hover:opacity-100 transition-opacity text-xs"
                          style={{ color: "var(--color-destructive)" }}>
                          Löschen
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
