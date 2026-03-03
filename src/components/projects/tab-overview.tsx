"use client";

import { useCallback, useEffect, useRef } from "react";
import { createClient } from "@/lib/supabase";
import { DateInput } from "@/components/ui/date-input";
import { SaveIndicator } from "@/components/ui/save-indicator";
import { StaleDataBanner } from "@/components/ui/stale-data-banner";
import { useFieldTracking } from "@/hooks/use-field-tracking";
import { useDebouncedSave } from "@/hooks/use-debounced-save";
import type { Project } from "@/types/database";

interface TabOverviewProps {
  projectId: string;
  project: Project;
  onProjectUpdate: (p: Project) => void;
  canViewBudget?: boolean;
  /** Wird auf true gesetzt wenn ein Remote-Update reinkommt während editiert wird */
  hasPendingRemoteUpdate?: boolean;
  onClearPendingRemoteUpdate?: () => void;
}

const statusLabels: Record<Project["status"], string> = {
  draft: "Entwurf",
  planning: "Planung",
  active: "Aktiv",
  completed: "Abgeschlossen",
  cancelled: "Abgebrochen",
};

export function TabOverview({
  projectId,
  project,
  onProjectUpdate,
  canViewBudget = true,
  hasPendingRemoteUpdate = false,
  onClearPendingRemoteUpdate,
}: TabOverviewProps) {
  const supabase = createClient();

  // ─────────────────────────────────────────────────────────────────────────
  // Field Tracking: verfolgt lokale Änderungen vs. Server-Daten
  // ─────────────────────────────────────────────────────────────────────────
  const {
    localData,
    dirtyFields,
    isDirty,
    updateField: trackField,
    mergeRemote,
    markAllClean,
    resetToServer,
  } = useFieldTracking<Project>(project);

  // ─────────────────────────────────────────────────────────────────────────
  // Debounced Save: speichert automatisch nach 800ms Inaktivität
  // ─────────────────────────────────────────────────────────────────────────
  const isSavingRef = useRef(false);

  const saveFn = useCallback(
    async (payload: Partial<Project>) => {
      isSavingRef.current = true;
      try {
        const { data, error } = await supabase
          .from("projects")
          .update(payload)
          .eq("id", projectId)
          .select()
          .single();

        if (!error && data) {
          onProjectUpdate(data as Project);
          markAllClean();
        }
      } finally {
        isSavingRef.current = false;
      }
    },
    [supabase, projectId, onProjectUpdate, markAllClean]
  );

  const { debouncedSave, flush, saveState } = useDebouncedSave<Partial<Project>>({
    saveFn,
    delay: 800,
  });

  // ─────────────────────────────────────────────────────────────────────────
  // Remote-Update Merge: wenn neuer project-Prop reinkommt
  // ─────────────────────────────────────────────────────────────────────────
  const prevProjectRef = useRef(project);
  useEffect(() => {
    // Nur mergen wenn sich der project-Prop wirklich geändert hat (nicht durch eigenen Save)
    if (prevProjectRef.current !== project && !isSavingRef.current) {
      if (isDirty) {
        // User editiert gerade → nur clean fields überschreiben
        mergeRemote(project);
      } else {
        // Kein lokaler Edit → komplett übernehmen
        resetToServer(project);
      }
    }
    prevProjectRef.current = project;
  }, [project, isDirty, mergeRemote, resetToServer]);

  // ─────────────────────────────────────────────────────────────────────────
  // Field-Update Handler: tracked + debounced save
  // ─────────────────────────────────────────────────────────────────────────
  function handleFieldChange(field: keyof Project, value: string | number | null) {
    trackField(field, value as Project[keyof Project]);

    // Payload mit allen aktuell dirty fields + das neue Feld
    const currentLocal = { ...localData, [field]: value };
    const payload: Partial<Project> = {};
    for (const f of dirtyFields) {
      payload[f as keyof Project] = currentLocal[f as keyof Project] as never;
    }
    payload[field] = value as never;

    debouncedSave(payload);
  }

  // Sofort-Save für Selects, Checkboxen, Dates
  function handleImmediateChange(field: keyof Project, value: string | number | null) {
    trackField(field, value as Project[keyof Project]);
    const payload: Partial<Project> = { [field]: value } as Partial<Project>;
    // Alle dirty fields mitsenden
    for (const f of dirtyFields) {
      payload[f as keyof Project] = localData[f as keyof Project] as never;
    }
    payload[field] = value as never;
    // Timer abbrechen und sofort speichern
    flush();
    saveFn(payload);
  }

  // Stale-Banner reload
  function handleReload() {
    resetToServer(project);
    onClearPendingRemoteUpdate?.();
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Flush bei Unmount (z.B. Tab-Wechsel)
  // ─────────────────────────────────────────────────────────────────────────
  useEffect(() => {
    return () => {
      flush();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // ─────────────────────────────────────────────────────────────────────────
  // Format Helpers
  // ─────────────────────────────────────────────────────────────────────────
  function formatCurrency(amount: number | null): string {
    if (amount === null || amount === undefined) return "\u2013";
    return new Intl.NumberFormat("de-DE", {
      style: "currency",
      currency: "EUR",
    }).format(amount);
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

  function FieldLabel({ children }: { children: React.ReactNode }) {
    return (
      <div
        className="text-xs font-medium mb-1"
        style={{ color: "var(--color-muted-foreground)" }}
      >
        {children}
      </div>
    );
  }

  function AutoInput({
    label,
    field,
    type = "text",
  }: {
    label: string;
    field: keyof Project;
    type?: string;
  }) {
    if (type === "date") {
      return (
        <div>
          <FieldLabel>{label}</FieldLabel>
          <DateInput
            value={(localData[field] as string) ?? ""}
            onChange={(val) => handleImmediateChange(field, val || null)}
          />
        </div>
      );
    }

    if (type === "time") {
      return (
        <div>
          <FieldLabel>{label}</FieldLabel>
          <input
            type="time"
            value={(localData[field] as string) ?? ""}
            onChange={(e) => handleImmediateChange(field, e.target.value || null)}
            className="w-full px-3 py-2 rounded-lg text-sm"
            style={{
              border: `1px solid ${dirtyFields.has(field as string) ? "var(--color-primary)" : "var(--color-border)"}`,
              background: "var(--color-background)",
            }}
          />
        </div>
      );
    }

    return (
      <div>
        <FieldLabel>{label}</FieldLabel>
        <input
          type={type}
          value={(localData[field] as string | number) ?? ""}
          onChange={(e) =>
            handleFieldChange(
              field,
              type === "number"
                ? e.target.value === ""
                  ? null
                  : parseFloat(e.target.value)
                : e.target.value
            )
          }
          className="w-full px-3 py-2 rounded-lg text-sm"
          style={{
            border: `1px solid ${dirtyFields.has(field as string) ? "var(--color-primary)" : "var(--color-border)"}`,
            background: "var(--color-background)",
          }}
        />
      </div>
    );
  }

  function AutoTextarea({
    label,
    field,
    rows = 3,
  }: {
    label: string;
    field: keyof Project;
    rows?: number;
  }) {
    return (
      <div>
        <FieldLabel>{label}</FieldLabel>
        <textarea
          rows={rows}
          value={(localData[field] as string) ?? ""}
          onChange={(e) => handleFieldChange(field, e.target.value)}
          className="w-full px-3 py-2 rounded-lg text-sm"
          style={{
            border: `1px solid ${dirtyFields.has(field as string) ? "var(--color-primary)" : "var(--color-border)"}`,
            background: "var(--color-background)",
          }}
        />
      </div>
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Card wrapper style
  // ─────────────────────────────────────────────────────────────────────────
  const cardStyle = {
    background: "var(--color-surface)",
    border: "1px solid var(--color-border)",
  };

  // ─────────────────────────────────────────────────────────────────────────
  // Render
  // ─────────────────────────────────────────────────────────────────────────
  return (
    <div className="space-y-4">
      {/* Stale-Data Banner */}
      <StaleDataBanner show={hasPendingRemoteUpdate} onReload={handleReload} />

      {/* ------------------------------------------------------------------ */}
      {/* 1. Projektdetails */}
      {/* ------------------------------------------------------------------ */}
      <div className="p-5 rounded-xl mb-4" style={cardStyle}>
        <SectionHeader title="Projektdetails" />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <AutoInput label="Projektname" field="name" />
          <div>
            <FieldLabel>Status</FieldLabel>
            <select
              value={localData.status ?? "draft"}
              onChange={(e) => handleImmediateChange("status", e.target.value)}
              className="w-full px-3 py-2 rounded-lg text-sm"
              style={{
                border: `1px solid ${dirtyFields.has("status") ? "var(--color-primary)" : "var(--color-border)"}`,
                background: "var(--color-background)",
              }}
            >
              {Object.entries(statusLabels).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </div>
          <AutoInput label="Startdatum" field="date_start" type="date" />
          <AutoInput label="Enddatum" field="date_end" type="date" />
          <div className="md:col-span-2">
            <AutoTextarea label="Beschreibung" field="description" rows={4} />
          </div>
        </div>
      </div>

      {/* ------------------------------------------------------------------ */}
      {/* 2. Spielstätte / Venue */}
      {/* ------------------------------------------------------------------ */}
      <div className="p-5 rounded-xl mb-4" style={cardStyle}>
        <SectionHeader title="Spielstätte / Venue" />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <AutoInput label="Name" field="venue_name" />
          <AutoInput label="Adresse" field="venue_address" />
          <AutoInput label="Ansprechpartner" field="venue_contact_person" />
          <AutoInput label="Telefon" field="venue_phone" />
          <div className="md:col-span-2">
            <AutoTextarea label="Notizen" field="venue_notes" />
          </div>
        </div>
      </div>

      {/* ------------------------------------------------------------------ */}
      {/* 3. Auftraggeber */}
      {/* ------------------------------------------------------------------ */}
      <div className="p-5 rounded-xl mb-4" style={cardStyle}>
        <SectionHeader title="Auftraggeber" />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <AutoInput label="Firmenname" field="client_name" />
          <AutoInput label="Ansprechpartner" field="client_contact_person" />
          <AutoInput label="Telefon" field="client_phone" />
          <AutoInput label="E-Mail" field="client_email" type="email" />
        </div>
      </div>

      {/* ------------------------------------------------------------------ */}
      {/* 4. Termine */}
      {/* ------------------------------------------------------------------ */}
      <div className="p-5 rounded-xl mb-4" style={cardStyle}>
        <SectionHeader title="Termine" />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <AutoInput label="Show-Datum" field="show_date" type="date" />
          <AutoInput label="Ankunftszeit" field="arrival_time" type="time" />
          <AutoInput label="Abfahrtszeit" field="departure_time" type="time" />
          <AutoInput label="Aufbaudatum" field="setup_date" type="date" />
          <AutoInput label="Abbaudatum" field="teardown_date" type="date" />
        </div>
      </div>

      {/* ------------------------------------------------------------------ */}
      {/* 5. Budget */}
      {/* ------------------------------------------------------------------ */}
      {canViewBudget ? (
        <div className="p-5 rounded-xl mb-4" style={cardStyle}>
          <SectionHeader title="Budget" />
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <AutoInput label="Gesamtbudget" field="budget_planned" type="number" />
            <div className="flex items-end pb-2">
              <span className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
                Aktuell: {formatCurrency(project.budget_planned)}
              </span>
            </div>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <AutoInput label="Honorar" field="budget_honorar" type="number" />
            <AutoInput label="Technik" field="budget_technik" type="number" />
            <AutoInput label="Transport / Reise" field="budget_transport" type="number" />
          </div>
        </div>
      ) : (
        <div className="p-5 rounded-xl mb-4" style={cardStyle}>
          <h3 className="text-sm font-semibold mb-2">Budget</h3>
          <p className="text-sm" style={{ color: "var(--color-text-muted)" }}>
            Budget-Informationen sind nur für Projektmitglieder sichtbar.
          </p>
        </div>
      )}

      {/* ------------------------------------------------------------------ */}
      {/* 6. Notizen */}
      {/* ------------------------------------------------------------------ */}
      <div className="p-5 rounded-xl mb-4" style={cardStyle}>
        <SectionHeader title="Notizen" />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <AutoTextarea label="Transportnotizen" field="transport_notes" rows={4} />
          <AutoTextarea label="Interne Notizen" field="internal_notes" rows={4} />
        </div>
      </div>
    </div>
  );
}
