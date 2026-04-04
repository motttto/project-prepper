"use client";

import { useState, useEffect, useCallback, useMemo } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import { DateInput } from "@/components/ui/date-input";
import { showToast } from "@/hooks/use-toast";
import type { Inquiry, InquiryStatus } from "@/types/database";
import { appConfirm } from "@/components/ui/confirm-dialog";
import {
  IconPlus,
  IconSearch,
  IconX,
  IconChevronRight,
  IconTrash,
  IconInbox,
  IconUserCheck,
} from "@/components/ui/icons";

// Status Labels + Farben
const statusLabels: Record<InquiryStatus, string> = {
  new: "Neu",
  reviewing: "In Prüfung",
  offer_sent: "Angebot",
  accepted: "Zugesagt",
  rejected: "Abgesagt",
  archived: "Archiv",
};

const statusColors: Record<InquiryStatus, { bg: string; color: string; dot: string }> = {
  new: { bg: "var(--color-info-light)", color: "var(--color-info)", dot: "#3b82f6" },
  reviewing: { bg: "var(--color-warning-light)", color: "var(--color-warning)", dot: "#f59e0b" },
  offer_sent: { bg: "var(--color-primary-light)", color: "var(--color-primary)", dot: "#8b5cf6" },
  accepted: { bg: "var(--color-success-light)", color: "var(--color-success)", dot: "#22c55e" },
  rejected: { bg: "var(--color-destructive-light)", color: "var(--color-destructive)", dot: "#ef4444" },
  archived: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)", dot: "#6b7280" },
};

const statusOrder: InquiryStatus[] = ["new", "reviewing", "offer_sent", "accepted", "rejected", "archived"];

type StatusFilter = "all" | "open" | InquiryStatus;

const filterLabels: Record<StatusFilter, string> = {
  all: "Alle",
  open: "Offen",
  new: "Neu",
  reviewing: "In Prüfung",
  offer_sent: "Angebot",
  accepted: "Zugesagt",
  rejected: "Abgesagt",
  archived: "Archiv",
};

export default function InquiriesPage() {
  const supabase = createClient();
  const router = useRouter();
  const { orgId } = useOrg();

  const [inquiries, setInquiries] = useState<Inquiry[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<StatusFilter>("open");
  const [showCreate, setShowCreate] = useState(false);
  const [saving, setSaving] = useState(false);
  const [statusMenuId, setStatusMenuId] = useState<string | null>(null);

  // Team-Zusagen pro Anfrage: { inquiryId: { accepted: N, total: N } }
  const [teamCounts, setTeamCounts] = useState<Record<string, { accepted: number; declined: number; pending: number; total: number }>>({});

  // Create Form State
  const [formTitle, setFormTitle] = useState("");
  const [formClientName, setFormClientName] = useState("");
  const [formClientContact, setFormClientContact] = useState("");
  const [formClientPhone, setFormClientPhone] = useState("");
  const [formClientEmail, setFormClientEmail] = useState("");
  const [formDateStart, setFormDateStart] = useState("");
  const [formDateEnd, setFormDateEnd] = useState("");
  const [formVenue, setFormVenue] = useState("");
  const [formBudget, setFormBudget] = useState("");
  const [formProbability, setFormProbability] = useState("");
  const [formNotes, setFormNotes] = useState("");

  const loadInquiries = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("inquiries")
      .select("*")
      .eq("org_id", orgId)
      .order("created_at", { ascending: false });
    if (data) setInquiries(data as Inquiry[]);
    setLoading(false);
  }, [supabase, orgId]);

  // Team-Zusagen-Zähler für alle Anfragen laden
  const loadTeamCounts = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("inquiry_invitations")
      .select("inquiry_id, status")
      .in("inquiry_id", inquiries.map((i) => i.id));
    if (data) {
      const counts: Record<string, { accepted: number; declined: number; pending: number; total: number }> = {};
      for (const inv of data) {
        if (!counts[inv.inquiry_id]) {
          counts[inv.inquiry_id] = { accepted: 0, declined: 0, pending: 0, total: 0 };
        }
        counts[inv.inquiry_id].total++;
        if (inv.status === "accepted") counts[inv.inquiry_id].accepted++;
        else if (inv.status === "declined") counts[inv.inquiry_id].declined++;
        else if (inv.status === "pending") counts[inv.inquiry_id].pending++;
      }
      setTeamCounts(counts);
    }
  }, [supabase, orgId, inquiries]);

  useEffect(() => {
    loadInquiries();
  }, [loadInquiries]);

  useEffect(() => {
    if (inquiries.length > 0) loadTeamCounts();
  }, [inquiries, loadTeamCounts]);

  useRealtimeTable({
    table: "inquiries",
    orgFilter: orgId || undefined,
    onDataChange: loadInquiries,
  });

  // Realtime: Team-Zusagen live aktualisieren
  useRealtimeTable({
    table: "inquiry_invitations",
    onDataChange: loadTeamCounts,
    enabled: inquiries.length > 0,
  });

  // Filterung
  const filtered = useMemo(() => {
    let result = inquiries;
    if (statusFilter === "open") {
      result = result.filter((i) => !["accepted", "archived"].includes(i.status));
    } else if (statusFilter !== "all") {
      result = result.filter((i) => i.status === statusFilter);
    }
    if (search.trim()) {
      const q = search.toLowerCase();
      result = result.filter(
        (i) =>
          i.title.toLowerCase().includes(q) ||
          i.client_name.toLowerCase().includes(q) ||
          i.description?.toLowerCase().includes(q) ||
          i.client_contact_person?.toLowerCase().includes(q)
      );
    }
    return result;
  }, [inquiries, statusFilter, search]);

  // Status-Zähler
  const statusCounts = useMemo(() => {
    const counts: Record<string, number> = { all: inquiries.length, open: 0 };
    for (const i of inquiries) {
      counts[i.status] = (counts[i.status] || 0) + 1;
      if (!["accepted", "archived"].includes(i.status)) counts.open++;
    }
    return counts;
  }, [inquiries]);

  // KPI Werte
  const totalOfferAmount = useMemo(
    () => inquiries
      .filter((i) => i.offer_amount && !["rejected", "archived"].includes(i.status))
      .reduce((sum, i) => sum + Number(i.offer_amount), 0),
    [inquiries]
  );

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    const { data: { user } } = await supabase.auth.getUser();
    const { error } = await supabase.from("inquiries").insert({
      org_id: orgId,
      title: formTitle,
      client_name: formClientName,
      client_contact_person: formClientContact || null,
      client_phone: formClientPhone || null,
      client_email: formClientEmail || null,
      event_date_start: formDateStart || null,
      event_date_end: formDateEnd || null,
      venue_name: formVenue || null,
      estimated_budget: formBudget ? parseFloat(formBudget) : null,
      probability: formProbability ? parseInt(formProbability) : null,
      notes: formNotes || null,
      created_by: user?.id,
    });
    if (error) {
      showToast("Fehler beim Erstellen: " + error.message, "error");
    } else {
      setFormTitle(""); setFormClientName(""); setFormClientContact("");
      setFormClientPhone(""); setFormClientEmail(""); setFormDateStart("");
      setFormDateEnd(""); setFormVenue(""); setFormBudget("");
      setFormProbability(""); setFormNotes("");
      setShowCreate(false);
      loadInquiries();
      showToast("Anfrage erstellt", "success");
    }
    setSaving(false);
  }

  async function handleDelete(e: React.MouseEvent, id: string) {
    e.stopPropagation();
    if (!(await appConfirm("Anfrage wirklich löschen?", { variant: "danger", confirmLabel: "Löschen" }))) return;
    const { error } = await supabase.from("inquiries").delete().eq("id", id);
    if (error) {
      showToast("Fehler beim Löschen: " + error.message, "error");
    } else {
      loadInquiries();
      showToast("Anfrage gelöscht", "success");
    }
  }

  async function handleStatusChange(e: React.MouseEvent, id: string, newStatus: InquiryStatus) {
    e.stopPropagation();
    setStatusMenuId(null);
    const { error } = await supabase.from("inquiries").update({ status: newStatus }).eq("id", id);
    if (error) {
      showToast("Status konnte nicht geändert werden", "error");
    } else {
      loadInquiries();
    }
  }

  const today = new Date().toISOString().split("T")[0];

  if (loading) {
    return (
      <div className="space-y-4">
        <div className="h-8 w-48 skeleton" />
        <div className="grid grid-cols-4 gap-3">{[1, 2, 3, 4].map((i) => <div key={i} className="h-16 skeleton rounded-lg" />)}</div>
        {[1, 2, 3, 4, 5].map((i) => <div key={i} className="h-16 skeleton rounded-lg" />)}
      </div>
    );
  }

  return (
    <div className="animate-fadeIn">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold">Anfragen</h1>
          <p className="text-sm mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>
            {inquiries.length} Anfragen &middot; {statusCounts.open || 0} offen
          </p>
        </div>
        <button
          onClick={() => setShowCreate(!showCreate)}
          className="flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium text-white transition-colors"
          style={{ background: "var(--color-primary)" }}
          onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-primary-hover)"}
          onMouseLeave={(e) => e.currentTarget.style.background = "var(--color-primary)"}
        >
          <IconPlus size={16} />
          Neue Anfrage
        </button>
      </div>

      {/* KPI Mini-Stats */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div className="p-3 rounded-lg" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}>
          <div className="text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>Neu</div>
          <div className="text-xl font-bold" style={{ color: statusColors.new.color }}>{statusCounts.new || 0}</div>
        </div>
        <div className="p-3 rounded-lg" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}>
          <div className="text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>In Prüfung</div>
          <div className="text-xl font-bold" style={{ color: statusColors.reviewing.color }}>{statusCounts.reviewing || 0}</div>
        </div>
        <div className="p-3 rounded-lg" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}>
          <div className="text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>Angebot gesendet</div>
          <div className="text-xl font-bold" style={{ color: statusColors.offer_sent.color }}>{statusCounts.offer_sent || 0}</div>
        </div>
        <div className="p-3 rounded-lg" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}>
          <div className="text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>Angebotswert</div>
          <div className="text-xl font-bold">{totalOfferAmount.toLocaleString("de-DE")} €</div>
        </div>
      </div>

      {/* Create Form */}
      {showCreate && (
        <div
          className="mb-6 p-6 rounded-xl animate-slideDown"
          style={{ background: "var(--color-surface)", boxShadow: "var(--shadow-md)", border: "1px solid var(--color-border)" }}
        >
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold">Neue Anfrage</h2>
            <button onClick={() => setShowCreate(false)} style={{ color: "var(--color-muted-foreground)" }}><IconX size={20} /></button>
          </div>
          <form onSubmit={handleCreate} className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1.5">Titel *</label>
                <input type="text" value={formTitle} onChange={(e) => setFormTitle(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="z.B. Firmenfeier Müller GmbH" required />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Kundenname *</label>
                <input type="text" value={formClientName} onChange={(e) => setFormClientName(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="Firma oder Person" required />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Ansprechpartner</label>
                <input type="text" value={formClientContact} onChange={(e) => setFormClientContact(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="Max Mustermann" />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Telefon</label>
                <input type="tel" value={formClientPhone} onChange={(e) => setFormClientPhone(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="+49 ..." />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">E-Mail</label>
                <input type="email" value={formClientEmail} onChange={(e) => setFormClientEmail(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="kunde@firma.de" />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Venue / Ort</label>
                <input type="text" value={formVenue} onChange={(e) => setFormVenue(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="Veranstaltungsort" />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Eventdatum Start</label>
                <DateInput value={formDateStart} onChange={(v) => setFormDateStart(v)} />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Eventdatum Ende</label>
                <DateInput value={formDateEnd} onChange={(v) => setFormDateEnd(v)} />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Geschätztes Budget (€)</label>
                <input type="number" value={formBudget} onChange={(e) => setFormBudget(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm" min={0} step={0.01}
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="0.00" />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Wahrscheinlichkeit (%)</label>
                <input type="number" value={formProbability} onChange={(e) => setFormProbability(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm" min={0} max={100}
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="50" />
              </div>
            </div>
            <div>
              <label className="block text-sm font-medium mb-1.5">Notizen</label>
              <textarea value={formNotes} onChange={(e) => setFormNotes(e.target.value)} rows={2}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                placeholder="Weitere Details zur Anfrage..." />
            </div>
            <div className="flex gap-2 pt-2">
              <button type="submit" disabled={saving}
                className="px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}>
                {saving ? "Wird gespeichert..." : "Anfrage anlegen"}
              </button>
              <button type="button" onClick={() => setShowCreate(false)}
                className="px-4 py-2 rounded-lg text-sm" style={{ border: "1px solid var(--color-border)" }}>
                Abbrechen
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Search + Status Filter */}
      <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 mb-5">
        <div className="relative flex-1 max-w-sm">
          <IconSearch size={16} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: "var(--color-muted-foreground)" } as React.CSSProperties} />
          <input
            type="text" value={search} onChange={(e) => setSearch(e.target.value)}
            placeholder="Anfrage suchen..."
            className="w-full pl-9 pr-3 py-2 rounded-lg text-sm"
            style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }} />
          {search && (
            <button onClick={() => setSearch("")} className="absolute right-2 top-1/2 -translate-y-1/2" style={{ color: "var(--color-muted-foreground)" }}>
              <IconX size={14} />
            </button>
          )}
        </div>
        <div className="flex gap-1 rounded-lg p-1 overflow-x-auto" style={{ background: "var(--color-muted)" }}>
          {(["all", "open", "new", "reviewing", "offer_sent", "accepted", "rejected"] as StatusFilter[]).map((status) => (
            <button
              key={status}
              onClick={() => setStatusFilter(status)}
              className="px-3 py-1.5 rounded-md text-xs font-medium whitespace-nowrap transition-all"
              style={{
                background: statusFilter === status ? "var(--color-surface)" : "transparent",
                color: statusFilter === status ? "var(--color-foreground)" : "var(--color-muted-foreground)",
                boxShadow: statusFilter === status ? "var(--shadow-sm)" : "none",
              }}
            >
              {filterLabels[status]}
              <span className="ml-1 opacity-60">{statusCounts[status] || 0}</span>
            </button>
          ))}
        </div>
      </div>

      {/* Liste */}
      {filtered.length === 0 ? (
        <div className="text-center py-16 rounded-xl" style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}>
          <IconInbox size={32} className="mx-auto mb-3 opacity-40" />
          <p className="text-lg mb-1">{search ? "Keine Treffer" : "Keine Anfragen"}</p>
          <p className="text-sm">{search ? `Keine Anfrage für "${search}" gefunden` : "Erstelle deine erste Anfrage."}</p>
        </div>
      ) : (
        <div className="space-y-2">
          {filtered.map((inquiry) => {
            const probColor = inquiry.probability !== null
              ? inquiry.probability >= 70 ? "var(--color-success)"
                : inquiry.probability >= 30 ? "var(--color-warning)"
                : "var(--color-destructive)"
              : null;
            const followUpOverdue = inquiry.next_follow_up && inquiry.next_follow_up < today;

            return (
              <div
                key={inquiry.id}
                onClick={() => router.push(`/inquiries/${inquiry.id}`)}
                className="flex items-center gap-3 p-4 rounded-xl cursor-pointer transition-all group"
                style={{
                  background: "var(--color-surface)",
                  border: "1px solid var(--color-border-light)",
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.borderColor = "var(--color-border)";
                  e.currentTarget.style.boxShadow = "var(--shadow-sm)";
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.borderColor = "var(--color-border-light)";
                  e.currentTarget.style.boxShadow = "none";
                }}
              >
                {/* Status Dot + Status-Wechsel */}
                <div className="relative">
                  <button
                    onClick={(e) => {
                      e.stopPropagation();
                      setStatusMenuId(statusMenuId === inquiry.id ? null : inquiry.id);
                    }}
                    className="w-3 h-3 rounded-full flex-shrink-0 cursor-pointer transition-all"
                    style={{
                      background: statusColors[inquiry.status].dot,
                      boxShadow: `0 0 0 0px ${statusColors[inquiry.status].dot}`,
                    }}
                    onMouseEnter={(e) => e.currentTarget.style.boxShadow = `0 0 0 3px color-mix(in srgb, ${statusColors[inquiry.status].dot} 30%, transparent)`}
                    onMouseLeave={(e) => e.currentTarget.style.boxShadow = `0 0 0 0px ${statusColors[inquiry.status].dot}`}
                    title={`Status: ${statusLabels[inquiry.status]}`}
                  />
                  {statusMenuId === inquiry.id && (
                    <>
                      <div className="fixed inset-0 z-40" onClick={(e) => { e.stopPropagation(); setStatusMenuId(null); }} />
                      <div
                        className="absolute top-full left-0 mt-2 z-50 py-1 rounded-lg min-w-[160px]"
                        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)", boxShadow: "var(--shadow-lg)" }}
                      >
                        {statusOrder.map((s) => (
                          <button
                            key={s}
                            onClick={(e) => handleStatusChange(e, inquiry.id, s)}
                            className="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-left transition-colors"
                            style={{
                              color: s === inquiry.status ? statusColors[s].color : "var(--color-foreground)",
                              background: s === inquiry.status ? statusColors[s].bg : "transparent",
                            }}
                            onMouseEnter={(e) => { if (s !== inquiry.status) e.currentTarget.style.background = "var(--color-muted)"; }}
                            onMouseLeave={(e) => { if (s !== inquiry.status) e.currentTarget.style.background = "transparent"; }}
                          >
                            <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ background: statusColors[s].dot }} />
                            {statusLabels[s]}
                          </button>
                        ))}
                      </div>
                    </>
                  )}
                </div>

                {/* Info */}
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="font-medium text-sm truncate">{inquiry.title}</span>
                    <span
                      className="text-xs px-2 py-0.5 rounded-full font-medium flex-shrink-0"
                      style={{ background: statusColors[inquiry.status].bg, color: statusColors[inquiry.status].color }}
                    >
                      {statusLabels[inquiry.status]}
                    </span>
                  </div>
                  <div className="flex items-center gap-3 mt-0.5 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    <span>{inquiry.client_name}</span>
                    {inquiry.event_date_start && (
                      <span>
                        {new Date(inquiry.event_date_start).toLocaleDateString("de-DE", { day: "numeric", month: "short", year: "numeric" })}
                      </span>
                    )}
                    {followUpOverdue && (
                      <span style={{ color: "var(--color-destructive)" }}>
                        Follow-Up fällig!
                      </span>
                    )}
                  </div>
                </div>

                {/* Team-Zusagen */}
                {teamCounts[inquiry.id] && teamCounts[inquiry.id].total > 0 && (
                  <span
                    className="flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-medium flex-shrink-0"
                    style={{
                      background: teamCounts[inquiry.id].accepted === teamCounts[inquiry.id].total
                        ? "var(--color-success-light)"
                        : teamCounts[inquiry.id].pending > 0
                        ? "var(--color-warning-light)"
                        : "var(--color-muted)",
                      color: teamCounts[inquiry.id].accepted === teamCounts[inquiry.id].total
                        ? "var(--color-success)"
                        : teamCounts[inquiry.id].pending > 0
                        ? "var(--color-warning)"
                        : "var(--color-muted-foreground)",
                    }}
                    title={`${teamCounts[inquiry.id].accepted} zugesagt, ${teamCounts[inquiry.id].pending} offen, ${teamCounts[inquiry.id].declined} abgesagt`}
                  >
                    <IconUserCheck size={12} />
                    {teamCounts[inquiry.id].accepted}/{teamCounts[inquiry.id].total}
                  </span>
                )}

                {/* Angebotssumme */}
                {inquiry.offer_amount && (
                  <div className="text-sm font-semibold tabular-nums text-right" style={{ minWidth: 80 }}>
                    {Number(inquiry.offer_amount).toLocaleString("de-DE")} €
                  </div>
                )}

                {/* Wahrscheinlichkeit */}
                {probColor && (
                  <span
                    className="text-xs px-2 py-0.5 rounded-full font-semibold flex-shrink-0 tabular-nums"
                    style={{ color: probColor, background: `color-mix(in srgb, ${probColor} 15%, transparent)` }}
                  >
                    {inquiry.probability}%
                  </span>
                )}

                {/* Delete + Chevron */}
                <button
                  onClick={(e) => handleDelete(e, inquiry.id)}
                  className="opacity-0 group-hover:opacity-100 p-1.5 rounded transition-opacity flex-shrink-0"
                  style={{ color: "var(--color-destructive)" }}
                  title="Löschen"
                >
                  <IconTrash size={14} />
                </button>
                <IconChevronRight
                  size={16}
                  className="opacity-30 group-hover:opacity-60 transition-opacity flex-shrink-0"
                />
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
