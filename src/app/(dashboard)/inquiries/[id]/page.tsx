"use client";

import { useState, useEffect, useCallback, useRef } from "react";
import { useParams, useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import { useFieldTracking } from "@/hooks/use-field-tracking";
import { useDebouncedSave } from "@/hooks/use-debounced-save";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import { SaveIndicator } from "@/components/ui/save-indicator";
import { StaleDataBanner } from "@/components/ui/stale-data-banner";
import { DateInput } from "@/components/ui/date-input";
import { useCurrentUser } from "@/hooks/use-current-user";
import { InquiryTeamSection } from "@/components/inquiries/inquiry-team-section";
import type { Inquiry, InquiryStatus } from "@/types/database";
import {
  IconChevronLeft,
  IconTrash,
  IconInbox,
} from "@/components/ui/icons";

// ─────────────────────────────────────────────────────────────────────────
// Status-Konfiguration
// ─────────────────────────────────────────────────────────────────────────
const statusLabels: Record<InquiryStatus, string> = {
  new: "Neu",
  reviewing: "In Prüfung",
  offer_sent: "Angebot gesendet",
  accepted: "Zugesagt",
  rejected: "Abgesagt",
  archived: "Archiviert",
};

const statusColors: Record<InquiryStatus, { bg: string; color: string; dot: string }> = {
  new: { bg: "var(--color-info-light)", color: "var(--color-info)", dot: "#3b82f6" },
  reviewing: { bg: "var(--color-warning-light)", color: "var(--color-warning)", dot: "#f59e0b" },
  offer_sent: { bg: "var(--color-primary-light)", color: "var(--color-primary)", dot: "#8b5cf6" },
  accepted: { bg: "var(--color-success-light)", color: "var(--color-success)", dot: "#22c55e" },
  rejected: { bg: "var(--color-destructive-light)", color: "var(--color-destructive)", dot: "#ef4444" },
  archived: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)", dot: "#6b7280" },
};

const pipelineSteps: InquiryStatus[] = ["new", "reviewing", "offer_sent", "accepted", "rejected", "archived"];

export default function InquiryDetailPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const supabase = createClient();
  const { orgId } = useOrg();

  const [inquiry, setInquiry] = useState<Inquiry | null>(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [hasPendingRemoteUpdate, setHasPendingRemoteUpdate] = useState(false);
  const [creatingProject, setCreatingProject] = useState(false);

  // ─────────────────────────────────────────────────────────────────────────
  // Daten laden
  // ─────────────────────────────────────────────────────────────────────────
  const loadInquiry = useCallback(async () => {
    if (!id) return;
    const { data, error } = await supabase
      .from("inquiries")
      .select("*")
      .eq("id", id)
      .single();
    if (error || !data) {
      setNotFound(true);
      setLoading(false);
      return;
    }
    setInquiry(data as Inquiry);
    setLoading(false);
  }, [supabase, id]);

  useEffect(() => {
    loadInquiry();
  }, [loadInquiry]);

  // Realtime
  useRealtimeTable({
    table: "inquiries",
    orgFilter: orgId || undefined,
    onDataChange: () => {
      // Nur bei Remote-Update → Inquiry neu laden
      loadInquiry();
    },
  });

  if (loading) {
    return (
      <div className="space-y-4 animate-pulse">
        <div className="h-8 w-48 skeleton" />
        <div className="h-64 skeleton rounded-xl" />
        <div className="h-48 skeleton rounded-xl" />
      </div>
    );
  }

  if (notFound || !inquiry) {
    return (
      <div className="text-center py-20">
        <IconInbox size={48} className="mx-auto mb-4 opacity-30" />
        <h2 className="text-lg font-semibold mb-1">Anfrage nicht gefunden</h2>
        <p className="text-sm mb-4" style={{ color: "var(--color-muted-foreground)" }}>
          Die Anfrage existiert nicht oder wurde gelöscht.
        </p>
        <button
          onClick={() => router.push("/inquiries")}
          className="px-4 py-2 rounded-lg text-sm font-medium text-white"
          style={{ background: "var(--color-primary)" }}
        >
          Zurück zur Übersicht
        </button>
      </div>
    );
  }

  return (
    <InquiryDetailContent
      inquiry={inquiry}
      onInquiryUpdate={(updated) => setInquiry(updated)}
      hasPendingRemoteUpdate={hasPendingRemoteUpdate}
      onClearPendingRemoteUpdate={() => setHasPendingRemoteUpdate(false)}
      creatingProject={creatingProject}
      setCreatingProject={setCreatingProject}
    />
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Detail Content (mit Auto-Save)
// ─────────────────────────────────────────────────────────────────────────
interface InquiryDetailContentProps {
  inquiry: Inquiry;
  onInquiryUpdate: (i: Inquiry) => void;
  hasPendingRemoteUpdate: boolean;
  onClearPendingRemoteUpdate: () => void;
  creatingProject: boolean;
  setCreatingProject: (v: boolean) => void;
}

function InquiryDetailContent({
  inquiry,
  onInquiryUpdate,
  hasPendingRemoteUpdate,
  onClearPendingRemoteUpdate,
  creatingProject,
  setCreatingProject,
}: InquiryDetailContentProps) {
  const supabase = createClient();
  const router = useRouter();
  const { orgId } = useOrg();
  const currentUser = useCurrentUser();

  // Telegram-Chat-ID der Org laden
  const [telegramChatId, setTelegramChatId] = useState<number | null>(null);
  useEffect(() => {
    if (!orgId) return;
    supabase
      .from("organizations")
      .select("telegram_chat_id")
      .eq("id", orgId)
      .single()
      .then(({ data }) => setTelegramChatId(data?.telegram_chat_id ?? null));
  }, [supabase, orgId]);

  // ─────────────────────────────────────────────────────────────────────────
  // Field Tracking
  // ─────────────────────────────────────────────────────────────────────────
  const {
    localData,
    dirtyFields,
    isDirty,
    updateField: trackField,
    mergeRemote,
    markClean,
    markAllClean,
    resetToServer,
  } = useFieldTracking<Inquiry>(inquiry);

  // ─────────────────────────────────────────────────────────────────────────
  // Debounced Save
  // ─────────────────────────────────────────────────────────────────────────
  const isSavingRef = useRef(false);

  const saveFn = useCallback(
    async (payload: Partial<Inquiry>) => {
      isSavingRef.current = true;
      try {
        const { error } = await supabase
          .from("inquiries")
          .update(payload)
          .eq("id", inquiry.id);
        if (!error) {
          // Nur die gespeicherten Felder als clean markieren
          // (nicht markAllClean — sonst gehen zwischenzeitliche Eingaben verloren)
          markClean(Object.keys(payload) as (keyof Inquiry)[]);
        }
      } finally {
        isSavingRef.current = false;
      }
    },
    [supabase, inquiry.id, markClean]
  );

  const { debouncedSave, flush, saveState } = useDebouncedSave<Partial<Inquiry>>({
    saveFn,
    delay: 1500,
  });

  // ─────────────────────────────────────────────────────────────────────────
  // Remote-Update Merge
  // ─────────────────────────────────────────────────────────────────────────
  const prevInquiryRef = useRef(inquiry);
  useEffect(() => {
    if (prevInquiryRef.current !== inquiry && !isSavingRef.current) {
      if (isDirty) {
        mergeRemote(inquiry);
      } else {
        resetToServer(inquiry);
      }
    }
    prevInquiryRef.current = inquiry;
  }, [inquiry, isDirty, mergeRemote, resetToServer]);

  // ─────────────────────────────────────────────────────────────────────────
  // Field-Update Handler
  // ─────────────────────────────────────────────────────────────────────────
  function handleFieldChange(field: keyof Inquiry, value: string | number | null) {
    trackField(field, value as Inquiry[keyof Inquiry]);
    const currentLocal = { ...localData, [field]: value };
    const payload: Partial<Inquiry> = {};
    for (const f of dirtyFields) {
      payload[f as keyof Inquiry] = currentLocal[f as keyof Inquiry] as never;
    }
    payload[field] = value as never;
    debouncedSave(payload);
  }

  function handleImmediateChange(field: keyof Inquiry, value: string | number | null) {
    trackField(field, value as Inquiry[keyof Inquiry]);
    const payload: Partial<Inquiry> = { [field]: value } as Partial<Inquiry>;
    for (const f of dirtyFields) {
      payload[f as keyof Inquiry] = localData[f as keyof Inquiry] as never;
    }
    payload[field] = value as never;
    flush();
    saveFn(payload);
  }

  // Flush bei Unmount
  useEffect(() => {
    return () => { flush(); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Stale-Banner reload
  function handleReload() {
    resetToServer(inquiry);
    onClearPendingRemoteUpdate();
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Status Pipeline Change
  // ─────────────────────────────────────────────────────────────────────────
  async function handleStatusPipeline(newStatus: InquiryStatus) {
    handleImmediateChange("status", newStatus);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Projekt erstellen
  // ─────────────────────────────────────────────────────────────────────────
  async function handleCreateProject() {
    if (creatingProject) return;
    setCreatingProject(true);

    const { data: { user } } = await supabase.auth.getUser();

    const { data: project, error } = await supabase
      .from("projects")
      .insert({
        org_id: orgId,
        name: localData.title,
        description: localData.description,
        status: "planning",
        client_name: localData.client_name,
        client_contact_person: localData.client_contact_person,
        client_phone: localData.client_phone,
        client_email: localData.client_email,
        venue_name: localData.venue_name,
        venue_address: localData.venue_address,
        date_start: localData.event_date_start,
        date_end: localData.event_date_end,
        budget_planned: localData.estimated_budget,
        created_by: user?.id,
      })
      .select()
      .single();

    if (!error && project) {
      // Anfrage mit Projekt verknüpfen
      await supabase
        .from("inquiries")
        .update({ project_id: project.id, status: "accepted" })
        .eq("id", inquiry.id);

      router.push(`/projects/${project.id}`);
    }
    setCreatingProject(false);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Delete
  // ─────────────────────────────────────────────────────────────────────────
  async function handleDelete() {
    if (!confirm("Anfrage wirklich löschen?")) return;
    await supabase.from("inquiries").delete().eq("id", inquiry.id);
    router.push("/inquiries");
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Reusable sub-components
  // ─────────────────────────────────────────────────────────────────────────
  function SectionHeader({ title }: { title: string }) {
    return (
      <div className="flex items-center justify-between mb-3">
        <h3
          className="text-sm font-semibold uppercase tracking-wider"
          style={{ color: "var(--color-muted-foreground)" }}
        >
          {title}
        </h3>
        <SaveIndicator state={saveState} />
      </div>
    );
  }

  // Render-Helpers (keine Komponenten-Definitionen im Render-Body!)
  function renderFieldLabel(text: string) {
    return (
      <div className="text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>
        {text}
      </div>
    );
  }

  function renderAutoInput(
    label: string,
    field: keyof Inquiry,
    type: string = "text",
    placeholder?: string,
  ) {
    if (type === "date") {
      return (
        <div>
          {renderFieldLabel(label)}
          <DateInput
            value={(localData[field] as string) ?? ""}
            onChange={(val) => handleImmediateChange(field, val || null)}
          />
        </div>
      );
    }

    return (
      <div>
        {renderFieldLabel(label)}
        <input
          key={field}
          type={type}
          value={(localData[field] as string | number) ?? ""}
          onChange={(e) =>
            handleFieldChange(
              field,
              type === "number"
                ? e.target.value === "" ? null : parseFloat(e.target.value)
                : e.target.value || null
            )
          }
          placeholder={placeholder}
          className="w-full px-3 py-2 rounded-lg text-sm"
          style={{
            border: `1px solid ${dirtyFields.has(field as string) ? "var(--color-primary)" : "var(--color-border)"}`,
            background: "var(--color-background)",
          }}
        />
      </div>
    );
  }

  function renderAutoTextarea(
    label: string,
    field: keyof Inquiry,
    rows: number = 3,
    placeholder?: string,
  ) {
    return (
      <div>
        {renderFieldLabel(label)}
        <textarea
          key={field}
          rows={rows}
          value={(localData[field] as string) ?? ""}
          onChange={(e) => handleFieldChange(field, e.target.value || null)}
          placeholder={placeholder}
          className="w-full px-3 py-2 rounded-lg text-sm"
          style={{
            border: `1px solid ${dirtyFields.has(field as string) ? "var(--color-primary)" : "var(--color-border)"}`,
            background: "var(--color-background)",
          }}
        />
      </div>
    );
  }

  const cardStyle = {
    background: "var(--color-surface)",
    border: "1px solid var(--color-border)",
  };

  const currentStatus = localData.status as InquiryStatus;

  // ─────────────────────────────────────────────────────────────────────────
  // Render
  // ─────────────────────────────────────────────────────────────────────────
  return (
    <div className="animate-fadeIn">
      {/* Header */}
      <div className="flex items-center gap-3 mb-6">
        <button
          onClick={() => router.push("/inquiries")}
          className="p-2 rounded-lg transition-colors"
          style={{ color: "var(--color-muted-foreground)" }}
          onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-muted)"}
          onMouseLeave={(e) => e.currentTarget.style.background = "transparent"}
        >
          <IconChevronLeft size={20} />
        </button>
        <div className="flex-1 min-w-0">
          <h1 className="text-xl font-bold truncate">{localData.title || "Unbenannte Anfrage"}</h1>
          <div className="flex items-center gap-2 mt-0.5">
            <span
              className="text-xs px-2 py-0.5 rounded-full font-medium"
              style={{ background: statusColors[currentStatus].bg, color: statusColors[currentStatus].color }}
            >
              {statusLabels[currentStatus]}
            </span>
            <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
              {localData.client_name}
            </span>
          </div>
        </div>
        <button
          onClick={handleDelete}
          className="p-2 rounded-lg transition-colors"
          style={{ color: "var(--color-destructive)" }}
          onMouseEnter={(e) => e.currentTarget.style.background = "color-mix(in srgb, var(--color-destructive) 10%, transparent)"}
          onMouseLeave={(e) => e.currentTarget.style.background = "transparent"}
          title="Anfrage löschen"
        >
          <IconTrash size={18} />
        </button>
      </div>

      {/* Stale-Data Banner */}
      <StaleDataBanner show={hasPendingRemoteUpdate} onReload={handleReload} />

      {/* ================================================================== */}
      {/* Status Pipeline */}
      {/* ================================================================== */}
      <div className="p-4 rounded-xl mb-4" style={cardStyle}>
        <div className="flex items-center justify-between mb-3">
          <h3
            className="text-sm font-semibold uppercase tracking-wider"
            style={{ color: "var(--color-muted-foreground)" }}
          >
            Pipeline
          </h3>
          <SaveIndicator state={saveState} />
        </div>
        <div className="flex flex-wrap gap-2">
          {pipelineSteps.map((step) => {
            const isActive = currentStatus === step;
            return (
              <button
                key={step}
                onClick={() => handleStatusPipeline(step)}
                className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium transition-all"
                style={{
                  background: isActive ? statusColors[step].bg : "var(--color-muted)",
                  color: isActive ? statusColors[step].color : "var(--color-muted-foreground)",
                  border: isActive ? `1px solid ${statusColors[step].color}` : "1px solid transparent",
                }}
                onMouseEnter={(e) => {
                  if (!isActive) {
                    e.currentTarget.style.background = statusColors[step].bg;
                    e.currentTarget.style.color = statusColors[step].color;
                  }
                }}
                onMouseLeave={(e) => {
                  if (!isActive) {
                    e.currentTarget.style.background = "var(--color-muted)";
                    e.currentTarget.style.color = "var(--color-muted-foreground)";
                  }
                }}
              >
                <span
                  className="w-2 h-2 rounded-full flex-shrink-0"
                  style={{ background: statusColors[step].dot }}
                />
                {statusLabels[step]}
              </button>
            );
          })}
        </div>
      </div>

      {/* ================================================================== */}
      {/* Team-Verfügbarkeit */}
      {/* ================================================================== */}
      {currentUser && orgId && (
        <InquiryTeamSection
          inquiryId={inquiry.id}
          currentUserId={currentUser.id}
          orgId={orgId}
          isCreator={inquiry.created_by === currentUser.id}
          isAdmin={currentUser.roleName === "admin"}
          telegramChatId={telegramChatId}
          telegramMessageId={localData.telegram_message_id ?? null}
        />
      )}

      <div className="space-y-4">
        {/* ================================================================== */}
        {/* 1. Anfrage-Details */}
        {/* ================================================================== */}
        <div className="p-5 rounded-xl" style={cardStyle}>
          <SectionHeader title="Anfrage-Details" />
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {renderAutoInput("Titel", "title", "text", "Anfragetitel")}
            <div className="md:col-span-2">
              {renderAutoTextarea("Beschreibung", "description", 3, "Details zur Anfrage...")}
            </div>
          </div>
        </div>

        {/* ================================================================== */}
        {/* 2. Kunde */}
        {/* ================================================================== */}
        <div className="p-5 rounded-xl" style={cardStyle}>
          <SectionHeader title="Kunde" />
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {renderAutoInput("Kundenname", "client_name", "text", "Firma oder Person")}
            {renderAutoInput("Ansprechpartner", "client_contact_person", "text", "Max Mustermann")}
            {renderAutoInput("Telefon", "client_phone", "tel", "+49 ...")}
            {renderAutoInput("E-Mail", "client_email", "email", "kunde@firma.de")}
          </div>
        </div>

        {/* ================================================================== */}
        {/* 3. Event */}
        {/* ================================================================== */}
        <div className="p-5 rounded-xl" style={cardStyle}>
          <SectionHeader title="Event" />
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {renderAutoInput("Venue / Ort", "venue_name", "text", "Veranstaltungsort")}
            {renderAutoInput("Adresse", "venue_address", "text", "Straße, PLZ, Stadt")}
            {renderAutoInput("Datum Start", "event_date_start", "date")}
            {renderAutoInput("Datum Ende", "event_date_end", "date")}
          </div>
        </div>

        {/* ================================================================== */}
        {/* 4. Angebot */}
        {/* ================================================================== */}
        <div className="p-5 rounded-xl" style={cardStyle}>
          <SectionHeader title="Angebot" />
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {renderAutoInput("Geschätztes Budget (€)", "estimated_budget", "number", "0.00")}
            {renderAutoInput("Angebotssumme (€)", "offer_amount", "number", "0.00")}
            {renderAutoInput("Angebotsdatum", "offer_date", "date")}
            {renderAutoInput("Gültig bis", "offer_valid_until", "date")}
          </div>
        </div>

        {/* ================================================================== */}
        {/* 5. Bewertung */}
        {/* ================================================================== */}
        <div className="p-5 rounded-xl" style={cardStyle}>
          <SectionHeader title="Bewertung" />
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              {renderFieldLabel("Wahrscheinlichkeit (%)")}
              <div className="flex items-center gap-3">
                <input
                  type="range"
                  min={0}
                  max={100}
                  step={5}
                  value={Number(localData.probability ?? 0)}
                  onChange={(e) => handleFieldChange("probability", parseInt(e.target.value))}
                  className="flex-1"
                  style={{ accentColor: "var(--color-primary)" }}
                />
                <span
                  className="text-sm font-semibold tabular-nums w-12 text-right"
                  style={{
                    color: (localData.probability ?? 0) >= 70
                      ? "var(--color-success)"
                      : (localData.probability ?? 0) >= 30
                        ? "var(--color-warning)"
                        : "var(--color-destructive)",
                  }}
                >
                  {localData.probability ?? 0}%
                </span>
              </div>
            </div>
            {renderAutoInput("Nächstes Follow-Up", "next_follow_up", "date")}
          </div>
        </div>

        {/* ================================================================== */}
        {/* 6. Notizen */}
        {/* ================================================================== */}
        <div className="p-5 rounded-xl" style={cardStyle}>
          <SectionHeader title="Notizen" />
          {renderAutoTextarea("Interne Notizen", "notes", 4, "Notizen, Absprachen, TODOs...")}
        </div>

        {/* ================================================================== */}
        {/* 7. Aktionen */}
        {/* ================================================================== */}
        <div className="p-5 rounded-xl" style={cardStyle}>
          <SectionHeader title="Aktionen" />

          {/* Projekt erstellen */}
          {localData.project_id ? (
            <div
              className="flex items-center gap-3 p-3 rounded-lg mb-3"
              style={{ background: "var(--color-success-light)", border: "1px solid var(--color-success)" }}
            >
              <span className="text-sm font-medium" style={{ color: "var(--color-success)" }}>
                ✓ Projekt erstellt
              </span>
              <button
                onClick={() => router.push(`/projects/${localData.project_id}`)}
                className="ml-auto text-xs font-medium px-3 py-1.5 rounded-lg text-white transition-opacity hover:opacity-80"
                style={{ background: "var(--color-success)" }}
              >
                Zum Projekt →
              </button>
            </div>
          ) : (
            <button
              onClick={handleCreateProject}
              disabled={creatingProject}
              className="flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium text-white transition-colors disabled:opacity-50 mb-3"
              style={{ background: "var(--color-primary)" }}
              onMouseEnter={(e) => { if (!creatingProject) e.currentTarget.style.background = "var(--color-primary-hover)"; }}
              onMouseLeave={(e) => e.currentTarget.style.background = "var(--color-primary)"}
            >
              {creatingProject ? (
                <>
                  <span className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                  Projekt wird erstellt...
                </>
              ) : (
                "→ Projekt erstellen"
              )}
            </button>
          )}

          {/* Erstellt am */}
          <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            Erstellt am {new Date(inquiry.created_at).toLocaleDateString("de-DE", {
              day: "numeric",
              month: "long",
              year: "numeric",
              hour: "2-digit",
              minute: "2-digit",
            })}
          </p>
        </div>
      </div>
    </div>
  );
}
