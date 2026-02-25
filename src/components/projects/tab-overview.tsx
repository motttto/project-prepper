"use client";

import { useState } from "react";
import { createClient } from "@/lib/supabase";
import { IconEdit, IconSave } from "@/components/ui/icons";
import { DateInput } from "@/components/ui/date-input";
import type { Project } from "@/types/database";

interface TabOverviewProps {
  projectId: string;
  project: Project;
  onProjectUpdate: (p: Project) => void;
  canViewBudget?: boolean;
}

const statusLabels: Record<Project["status"], string> = {
  draft: "Entwurf",
  planning: "Planung",
  active: "Aktiv",
  completed: "Abgeschlossen",
  cancelled: "Abgebrochen",
};

export function TabOverview({ projectId, project, onProjectUpdate, canViewBudget = true }: TabOverviewProps) {
  const supabase = createClient();
  const [editingSection, setEditingSection] = useState<string | null>(null);
  const [form, setForm] = useState<Partial<Project>>({});

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  function startEditing(section: string) {
    setForm({ ...project });
    setEditingSection(section);
  }

  async function saveSection(section: string) {
    let payload: Record<string, unknown> = {};

    switch (section) {
      case "details":
        payload = {
          name: form.name,
          description: form.description,
          status: form.status,
          date_start: form.date_start || null,
          date_end: form.date_end || null,
        };
        break;
      case "venue":
        payload = {
          venue_name: form.venue_name || null,
          venue_address: form.venue_address || null,
          venue_contact_person: form.venue_contact_person || null,
          venue_phone: form.venue_phone || null,
          venue_notes: form.venue_notes || null,
        };
        break;
      case "client":
        payload = {
          client_name: form.client_name || null,
          client_contact_person: form.client_contact_person || null,
          client_phone: form.client_phone || null,
          client_email: form.client_email || null,
        };
        break;
      case "dates":
        payload = {
          show_date: form.show_date || null,
          arrival_time: form.arrival_time || null,
          departure_time: form.departure_time || null,
          setup_date: form.setup_date || null,
          teardown_date: form.teardown_date || null,
        };
        break;
      case "budget":
        payload = {
          budget_planned: form.budget_planned ?? null,
          budget_honorar: form.budget_honorar ?? null,
          budget_technik: form.budget_technik ?? null,
          budget_transport: form.budget_transport ?? null,
        };
        break;
      case "notes":
        payload = {
          transport_notes: form.transport_notes || null,
          internal_notes: form.internal_notes || null,
        };
        break;
    }

    const { data, error } = await supabase
      .from("projects")
      .update(payload)
      .eq("id", projectId)
      .select()
      .single();

    if (!error && data) {
      onProjectUpdate(data as Project);
    }

    setEditingSection(null);
  }

  function updateField(field: keyof Project, value: string | number | null) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  function formatDate(dateStr: string | null): string {
    if (!dateStr) return "\u2013";
    try {
      return new Date(dateStr).toLocaleDateString("de-DE", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
      });
    } catch {
      return dateStr;
    }
  }

  function formatCurrency(amount: number | null): string {
    if (amount === null || amount === undefined) return "\u2013";
    return new Intl.NumberFormat("de-DE", {
      style: "currency",
      currency: "EUR",
    }).format(amount);
  }

  // ---------------------------------------------------------------------------
  // Reusable sub-components
  // ---------------------------------------------------------------------------

  function SectionHeader({ title, section }: { title: string; section: string }) {
    const isEditing = editingSection === section;
    return (
      <div className="flex items-center justify-between mb-3">
        <h3
          className="text-sm font-semibold uppercase tracking-wider"
          style={{ color: "var(--color-muted-foreground)" }}
        >
          {title}
        </h3>
        {isEditing ? (
          <button
            onClick={() => saveSection(section)}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-white"
            style={{ background: "var(--color-primary, #2563eb)" }}
          >
            <IconSave size={14} />
            Speichern
          </button>
        ) : (
          <button
            onClick={() => startEditing(section)}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium"
            style={{
              border: "1px solid var(--color-border)",
              color: "var(--color-muted-foreground)",
            }}
          >
            <IconEdit size={14} />
            Bearbeiten
          </button>
        )}
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

  function FieldValue({ value }: { value: string | null | undefined }) {
    return <div className="text-sm">{value || "\u2013"}</div>;
  }

  function InputField({
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
            value={(form[field] as string) ?? ""}
            onChange={(val) => updateField(field, val || null)}
          />
        </div>
      );
    }
    return (
      <div>
        <FieldLabel>{label}</FieldLabel>
        <input
          type={type}
          value={(form[field] as string) ?? ""}
          onChange={(e) =>
            updateField(
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
            border: "1px solid var(--color-border)",
            background: "var(--color-background)",
          }}
        />
      </div>
    );
  }

  function TextareaField({
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
          value={(form[field] as string) ?? ""}
          onChange={(e) => updateField(field, e.target.value)}
          className="w-full px-3 py-2 rounded-lg text-sm"
          style={{
            border: "1px solid var(--color-border)",
            background: "var(--color-background)",
          }}
        />
      </div>
    );
  }

  // ---------------------------------------------------------------------------
  // Card wrapper style
  // ---------------------------------------------------------------------------

  const cardStyle = {
    background: "var(--color-surface)",
    border: "1px solid var(--color-border)",
  };

  // ---------------------------------------------------------------------------
  // Render
  // ---------------------------------------------------------------------------

  return (
    <div className="space-y-4">
      {/* ------------------------------------------------------------------ */}
      {/* 1. Projektdetails */}
      {/* ------------------------------------------------------------------ */}
      <div className="p-5 rounded-xl mb-4" style={cardStyle}>
        <SectionHeader title="Projektdetails" section="details" />

        {editingSection === "details" ? (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <InputField label="Projektname" field="name" />
            <div>
              <FieldLabel>Status</FieldLabel>
              <select
                value={form.status ?? "draft"}
                onChange={(e) => updateField("status", e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{
                  border: "1px solid var(--color-border)",
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
            <InputField label="Startdatum" field="date_start" type="date" />
            <InputField label="Enddatum" field="date_end" type="date" />
            <div className="md:col-span-2">
              <TextareaField label="Beschreibung" field="description" rows={4} />
            </div>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <FieldLabel>Projektname</FieldLabel>
              <FieldValue value={project.name} />
            </div>
            <div>
              <FieldLabel>Status</FieldLabel>
              <FieldValue value={statusLabels[project.status]} />
            </div>
            <div>
              <FieldLabel>Startdatum</FieldLabel>
              <FieldValue value={formatDate(project.date_start)} />
            </div>
            <div>
              <FieldLabel>Enddatum</FieldLabel>
              <FieldValue value={formatDate(project.date_end)} />
            </div>
            <div className="md:col-span-2">
              <FieldLabel>Beschreibung</FieldLabel>
              <FieldValue value={project.description} />
            </div>
          </div>
        )}
      </div>

      {/* ------------------------------------------------------------------ */}
      {/* 2. Spielstätte / Venue */}
      {/* ------------------------------------------------------------------ */}
      <div className="p-5 rounded-xl mb-4" style={cardStyle}>
        <SectionHeader title="Spielstätte / Venue" section="venue" />

        {editingSection === "venue" ? (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <InputField label="Name" field="venue_name" />
            <InputField label="Adresse" field="venue_address" />
            <InputField label="Ansprechpartner" field="venue_contact_person" />
            <InputField label="Telefon" field="venue_phone" />
            <div className="md:col-span-2">
              <TextareaField label="Notizen" field="venue_notes" />
            </div>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <FieldLabel>Name</FieldLabel>
              <FieldValue value={project.venue_name} />
            </div>
            <div>
              <FieldLabel>Adresse</FieldLabel>
              <FieldValue value={project.venue_address} />
            </div>
            <div>
              <FieldLabel>Ansprechpartner</FieldLabel>
              <FieldValue value={project.venue_contact_person} />
            </div>
            <div>
              <FieldLabel>Telefon</FieldLabel>
              <FieldValue value={project.venue_phone} />
            </div>
            <div className="md:col-span-2">
              <FieldLabel>Notizen</FieldLabel>
              <FieldValue value={project.venue_notes} />
            </div>
          </div>
        )}
      </div>

      {/* ------------------------------------------------------------------ */}
      {/* 3. Auftraggeber */}
      {/* ------------------------------------------------------------------ */}
      <div className="p-5 rounded-xl mb-4" style={cardStyle}>
        <SectionHeader title="Auftraggeber" section="client" />

        {editingSection === "client" ? (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <InputField label="Firmenname" field="client_name" />
            <InputField label="Ansprechpartner" field="client_contact_person" />
            <InputField label="Telefon" field="client_phone" />
            <InputField label="E-Mail" field="client_email" type="email" />
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <FieldLabel>Firmenname</FieldLabel>
              <FieldValue value={project.client_name} />
            </div>
            <div>
              <FieldLabel>Ansprechpartner</FieldLabel>
              <FieldValue value={project.client_contact_person} />
            </div>
            <div>
              <FieldLabel>Telefon</FieldLabel>
              <FieldValue value={project.client_phone} />
            </div>
            <div>
              <FieldLabel>E-Mail</FieldLabel>
              <FieldValue value={project.client_email} />
            </div>
          </div>
        )}
      </div>

      {/* ------------------------------------------------------------------ */}
      {/* 4. Termine */}
      {/* ------------------------------------------------------------------ */}
      <div className="p-5 rounded-xl mb-4" style={cardStyle}>
        <SectionHeader title="Termine" section="dates" />

        {editingSection === "dates" ? (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <InputField label="Show-Datum" field="show_date" type="date" />
            <InputField label="Ankunftszeit" field="arrival_time" type="time" />
            <InputField label="Abfahrtszeit" field="departure_time" type="time" />
            <InputField label="Aufbaudatum" field="setup_date" type="date" />
            <InputField label="Abbaudatum" field="teardown_date" type="date" />
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <FieldLabel>Show-Datum</FieldLabel>
              <FieldValue value={formatDate(project.show_date)} />
            </div>
            <div>
              <FieldLabel>Ankunftszeit</FieldLabel>
              <FieldValue value={project.arrival_time} />
            </div>
            <div>
              <FieldLabel>Abfahrtszeit</FieldLabel>
              <FieldValue value={project.departure_time} />
            </div>
            <div>
              <FieldLabel>Aufbaudatum</FieldLabel>
              <FieldValue value={formatDate(project.setup_date)} />
            </div>
            <div>
              <FieldLabel>Abbaudatum</FieldLabel>
              <FieldValue value={formatDate(project.teardown_date)} />
            </div>
          </div>
        )}
      </div>

      {/* ------------------------------------------------------------------ */}
      {/* 5. Budget */}
      {/* ------------------------------------------------------------------ */}
      {canViewBudget ? (
        <div className="p-5 rounded-xl mb-4" style={cardStyle}>
          <SectionHeader title="Budget" section="budget" />

          {editingSection === "budget" ? (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <InputField label="Gesamtbudget" field="budget_planned" type="number" />
              <div /> {/* spacer */}
              <InputField label="Honorar" field="budget_honorar" type="number" />
              <InputField label="Technik" field="budget_technik" type="number" />
              <InputField label="Transport- / Reisekosten" field="budget_transport" type="number" />
            </div>
          ) : (
            <>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                  <FieldLabel>Gesamtbudget</FieldLabel>
                  <div className="text-lg font-semibold">{formatCurrency(project.budget_planned)}</div>
                </div>
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div
                  className="p-3 rounded-lg"
                  style={{ background: "var(--color-muted)" }}
                >
                  <FieldLabel>Honorar</FieldLabel>
                  <div className="text-sm font-semibold">{formatCurrency(project.budget_honorar)}</div>
                </div>
                <div
                  className="p-3 rounded-lg"
                  style={{ background: "var(--color-muted)" }}
                >
                  <FieldLabel>Technik</FieldLabel>
                  <div className="text-sm font-semibold">{formatCurrency(project.budget_technik)}</div>
                </div>
                <div
                  className="p-3 rounded-lg"
                  style={{ background: "var(--color-muted)" }}
                >
                  <FieldLabel>Transport / Reise</FieldLabel>
                  <div className="text-sm font-semibold">{formatCurrency(project.budget_transport)}</div>
                </div>
              </div>
            </>
          )}
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
        <SectionHeader title="Notizen" section="notes" />

        {editingSection === "notes" ? (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <TextareaField label="Transportnotizen" field="transport_notes" rows={4} />
            <TextareaField label="Interne Notizen" field="internal_notes" rows={4} />
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <FieldLabel>Transportnotizen</FieldLabel>
              <FieldValue value={project.transport_notes} />
            </div>
            <div>
              <FieldLabel>Interne Notizen</FieldLabel>
              <FieldValue value={project.internal_notes} />
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
