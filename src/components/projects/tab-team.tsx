"use client";

import { useState, useEffect, useCallback, useMemo } from "react";
import { createClient } from "@/lib/supabase";
import type { TeamMember, ProjectContact } from "@/types/database";
import { IconPlus, IconTrash } from "@/components/ui/icons";
import { useRealtimeTable } from "@/hooks/use-realtime-table";

interface TabTeamProps {
  projectId: string;
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

export function TabTeam({ projectId }: TabTeamProps) {
  const supabase = createClient();
  const [members, setMembers] = useState<TeamMember[]>([]);
  const [contacts, setContacts] = useState<ProjectContact[]>([]);
  const [loading, setLoading] = useState(true);

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
    const [teamRes, contactsRes] = await Promise.all([
      supabase.from("project_team").select("*").eq("project_id", projectId).order("department").order("created_at"),
      supabase.from("project_contacts").select("*").eq("project_id", projectId).order("created_at"),
    ]);
    if (teamRes.data) setMembers(teamRes.data as TeamMember[]);
    if (contactsRes.data) setContacts(contactsRes.data as ProjectContact[]);
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
    if (!confirm("Teammitglied wirklich entfernen?")) return;
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
    });
    if (!error) {
      setContactName(""); setContactRole(""); setContactCompany(""); setContactPhone(""); setContactEmail("");
      setShowContactForm(false);
      loadData();
    }
    setSavingContact(false);
  }

  async function handleDeleteContact(id: string) {
    if (!confirm("Kontakt wirklich entfernen?")) return;
    await supabase.from("project_contacts").delete().eq("id", id);
    loadData();
  }

  if (loading) {
    return <div className="py-8 text-center" style={{ color: "var(--color-muted-foreground)" }}>Team wird geladen...</div>;
  }

  const inputStyle = { border: "1px solid var(--color-border)", background: "var(--color-background)" };

  return (
    <div className="space-y-8">
      {/* ===== TEAM SECTION ===== */}
      <div>
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-3">
            <h2 className="text-lg font-semibold">Team-Mitglieder</h2>
            <span className="text-xs px-2 py-0.5 rounded-full"
              style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
              {members.length} Personen
            </span>
          </div>
          <button
            onClick={() => setShowTeamForm(!showTeamForm)}
            className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-white rounded-lg transition-colors"
            style={{ background: "var(--color-primary)" }}
          >
            <IconPlus size={16} />
            Mitglied hinzufügen
          </button>
        </div>

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

        {members.length === 0 ? (
          <div className="text-center py-8 rounded-lg"
            style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}>
            Noch keine Teammitglieder eingetragen
          </div>
        ) : (
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
            <table className="w-full text-sm">
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
                {contacts.map((c) => (
                  <tr key={c.id} style={{ borderTop: "1px solid var(--color-border)" }} className="group">
                    <td className="px-4 py-3 font-medium">{c.name}</td>
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
                      <button onClick={() => handleDeleteContact(c.id)}
                        className="opacity-0 group-hover:opacity-100 transition-opacity text-xs"
                        style={{ color: "var(--color-destructive)" }}>
                        Löschen
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
