"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import type { Project, ScheduleEntry } from "@/types/database";
import { IconPlus, IconTrash } from "@/components/ui/icons";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import { DateInput } from "@/components/ui/date-input";

interface TabScheduleProps {
  projectId: string;
  project: Project;
}

function formatTime(start: string | null, end: string | null): string {
  if (!start) return "";
  const s = start.slice(0, 5);
  if (!end) return s;
  const e = end.slice(0, 5);
  return `${s} – ${e}`;
}

function calcDuration(start: string | null, end: string | null): string | null {
  if (!start || !end) return null;
  const [sh, sm] = start.split(":").map(Number);
  const [eh, em] = end.split(":").map(Number);
  let diffMin = (eh * 60 + em) - (sh * 60 + sm);
  if (diffMin < 0) diffMin += 24 * 60; // über Mitternacht
  const h = Math.floor(diffMin / 60);
  const m = diffMin % 60;
  if (h === 0) return `${m} Min.`;
  if (m === 0) return `${h} Std.`;
  return `${h} Std. ${m} Min.`;
}

function formatDateHeader(dateStr: string): string {
  const date = new Date(dateStr + "T00:00:00");
  return date.toLocaleDateString("de-DE", {
    weekday: "long",
    day: "2-digit",
    month: "long",
    year: "numeric",
  });
}

export function TabSchedule({ projectId, project }: TabScheduleProps) {
  const supabase = createClient();
  const [entries, setEntries] = useState<ScheduleEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [saving, setSaving] = useState(false);
  const [loadingTemplate, setLoadingTemplate] = useState(false);

  // Form state
  const [formTitle, setFormTitle] = useState("");
  const [formDate, setFormDate] = useState(project.show_date || "");
  const [formTimeStart, setFormTimeStart] = useState("");
  const [formTimeEnd, setFormTimeEnd] = useState("");
  const [formDescription, setFormDescription] = useState("");

  const loadEntries = useCallback(async () => {
    const { data } = await supabase
      .from("project_schedule")
      .select("*")
      .eq("project_id", projectId)
      .order("schedule_date")
      .order("sort_order");
    if (data) setEntries(data as ScheduleEntry[]);
    setLoading(false);
  }, [supabase, projectId]);

  useEffect(() => {
    loadEntries();
  }, [loadEntries]);

  // Realtime: Live-Synchronisation
  useRealtimeTable({
    table: "project_schedule",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadEntries,
  });

  // Group entries by date
  const grouped: Record<string, ScheduleEntry[]> = {};
  for (const entry of entries) {
    if (!grouped[entry.schedule_date]) {
      grouped[entry.schedule_date] = [];
    }
    grouped[entry.schedule_date].push(entry);
  }
  const sortedDates = Object.keys(grouped).sort();

  async function handleAdd(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);

    // Determine next sort_order for that date
    const existing = entries.filter((en) => en.schedule_date === formDate);
    const maxOrder = existing.length > 0
      ? Math.max(...existing.map((en) => en.sort_order))
      : -1;

    const { error } = await supabase.from("project_schedule").insert({
      project_id: projectId,
      title: formTitle,
      schedule_date: formDate,
      time_start: formTimeStart || null,
      time_end: formTimeEnd || null,
      description: formDescription || null,
      sort_order: maxOrder + 1,
    });

    if (!error) {
      setFormTitle("");
      setFormDate(project.show_date || "");
      setFormTimeStart("");
      setFormTimeEnd("");
      setFormDescription("");
      setShowForm(false);
      loadEntries();
    }
    setSaving(false);
  }

  async function handleDelete(id: string) {
    if (!confirm("Eintrag wirklich löschen?")) return;
    await supabase.from("project_schedule").delete().eq("id", id);
    loadEntries();
  }

  async function handleLoadTemplate() {
    if (!confirm("Standardvorlage laden? Dies erstellt die typischen Ablaufpunkte.")) return;
    setLoadingTemplate(true);

    const setupDate = project.setup_date || project.show_date || "";
    const showDate = project.show_date || "";
    const teardownDate = project.teardown_date || project.show_date || "";

    const templateEntries = [
      { title: "Anreise", schedule_date: setupDate, time_start: null, time_end: null, description: null, sort_order: 0 },
      { title: "Aufbau Licht", schedule_date: setupDate, time_start: null, time_end: null, description: null, sort_order: 1 },
      { title: "Aufbau Ton/Video", schedule_date: setupDate, time_start: null, time_end: null, description: null, sort_order: 2 },
      { title: "Soundcheck", schedule_date: showDate, time_start: null, time_end: null, description: null, sort_order: 3 },
      { title: "Probe", schedule_date: showDate, time_start: null, time_end: null, description: null, sort_order: 4 },
      { title: "Einlass", schedule_date: showDate, time_start: null, time_end: null, description: null, sort_order: 5 },
      { title: "Show", schedule_date: showDate, time_start: null, time_end: null, description: null, sort_order: 6 },
      { title: "Abbau", schedule_date: teardownDate, time_start: null, time_end: null, description: null, sort_order: 7 },
      { title: "Abreise", schedule_date: teardownDate, time_start: null, time_end: null, description: null, sort_order: 8 },
    ].filter((entry) => entry.schedule_date !== "");

    if (templateEntries.length === 0) {
      alert("Bitte zuerst Show-Datum im Projekt setzen.");
      setLoadingTemplate(false);
      return;
    }

    const rows = templateEntries.map((entry) => ({
      project_id: projectId,
      ...entry,
    }));

    await supabase.from("project_schedule").insert(rows);
    await loadEntries();
    setLoadingTemplate(false);
  }

  if (loading) {
    return (
      <div className="py-8 text-center" style={{ color: "var(--color-muted-foreground)" }}>
        Zeitplan wird geladen...
      </div>
    );
  }

  return (
    <div>
      {/* Header */}
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-lg font-semibold">Zeitplan / Ablauf</h2>
        <button
          onClick={() => setShowForm(!showForm)}
          className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-white rounded-lg transition-colors"
          style={{ background: "var(--color-primary)" }}
        >
          <IconPlus size={16} />
          Eintrag hinzufügen
        </button>
      </div>

      {/* Add form */}
      {showForm && (
        <div
          className="mb-4 p-5 rounded-lg"
          style={{
            border: "1px solid var(--color-border)",
            background: "var(--color-surface)",
          }}
        >
          <h3 className="font-medium mb-3">Neuer Eintrag</h3>
          <form onSubmit={handleAdd} className="space-y-3">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1">Titel *</label>
                <input
                  type="text"
                  value={formTitle}
                  onChange={(e) => setFormTitle(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{
                    border: "1px solid var(--color-border)",
                    background: "var(--color-background)",
                  }}
                  placeholder="z.B. Soundcheck, Einlass, Show..."
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Datum *</label>
                <DateInput
                  value={formDate}
                  onChange={setFormDate}
                  required
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1">Startzeit</label>
                <input
                  type="time"
                  value={formTimeStart}
                  onChange={(e) => setFormTimeStart(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{
                    border: "1px solid var(--color-border)",
                    background: "var(--color-background)",
                  }}
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Endzeit</label>
                <input
                  type="time"
                  value={formTimeEnd}
                  onChange={(e) => setFormTimeEnd(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{
                    border: "1px solid var(--color-border)",
                    background: "var(--color-background)",
                  }}
                />
              </div>
            </div>
            {/* Dauer-Vorschau */}
            {formTimeStart && formTimeEnd && (
              <div className="text-xs px-1" style={{ color: "var(--color-muted-foreground)" }}>
                Dauer: <span className="font-medium">{calcDuration(formTimeStart, formTimeEnd)}</span>
              </div>
            )}
            <div>
              <label className="block text-sm font-medium mb-1">Beschreibung</label>
              <input
                type="text"
                value={formDescription}
                onChange={(e) => setFormDescription(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{
                  border: "1px solid var(--color-border)",
                  background: "var(--color-background)",
                }}
                placeholder="Optionale Notizen..."
              />
            </div>
            <div className="flex gap-2">
              <button
                type="submit"
                disabled={saving}
                className="px-4 py-2 text-sm text-white rounded-lg disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}
              >
                {saving ? "Wird gespeichert..." : "Hinzufügen"}
              </button>
              <button
                type="button"
                onClick={() => setShowForm(false)}
                className="px-4 py-2 text-sm rounded-lg"
                style={{ border: "1px solid var(--color-border)" }}
              >
                Abbrechen
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Empty state */}
      {entries.length === 0 ? (
        <div
          className="text-center py-12 rounded-lg"
          style={{
            border: "2px dashed var(--color-border)",
            color: "var(--color-muted-foreground)",
          }}
        >
          <p className="mb-4">Noch kein Zeitplan erstellt</p>
          <button
            onClick={handleLoadTemplate}
            disabled={loadingTemplate}
            className="px-4 py-2 text-sm text-white rounded-lg disabled:opacity-50"
            style={{ background: "var(--color-primary)" }}
          >
            {loadingTemplate ? "Vorlage wird geladen..." : "Vorlage laden"}
          </button>
          <p className="text-xs mt-2" style={{ color: "var(--color-muted-foreground)" }}>
            Erstellt einen Standard-Ablauf mit Anreise, Aufbau, Show, Abbau etc.
          </p>
        </div>
      ) : (
        /* Timeline */
        <div className="space-y-6">
          {sortedDates.map((date) => (
            <div key={date}>
              {/* Date header */}
              <div className="mb-3">
                <h3 className="text-sm font-semibold uppercase tracking-wide" style={{ color: "var(--color-muted-foreground)" }}>
                  {formatDateHeader(date)}
                </h3>
              </div>

              {/* Timeline entries */}
              <div className="relative ml-4">
                {/* Vertical timeline line */}
                <div
                  className="absolute top-0 bottom-0 left-0"
                  style={{
                    width: "2px",
                    background: "var(--color-border)",
                  }}
                />

                <div className="space-y-1">
                  {grouped[date].map((entry) => {
                    const timeStr = formatTime(entry.time_start, entry.time_end);
                    const duration = calcDuration(entry.time_start, entry.time_end);

                    return (
                      <div
                        key={entry.id}
                        className="group relative flex items-start gap-3 pl-6 py-2 rounded-lg transition-colors"
                        style={{ marginLeft: "-1px" }}
                      >
                        {/* Timeline dot */}
                        <div
                          className="absolute left-0 top-[14px] -translate-x-1/2 w-2.5 h-2.5 rounded-full"
                          style={{
                            background: "var(--color-primary)",
                            border: "2px solid var(--color-border)",
                            marginLeft: "1px",
                          }}
                        />

                        {/* Time + Duration */}
                        <div className="shrink-0 w-[110px] pt-0.5">
                          <div
                            className="text-sm tabular-nums"
                            style={{ color: "var(--color-muted-foreground)" }}
                          >
                            {timeStr}
                          </div>
                          {duration && (
                            <div
                              className="text-xs mt-0.5 font-medium"
                              style={{ color: "var(--color-primary)" }}
                            >
                              {duration}
                            </div>
                          )}
                        </div>

                        {/* Content */}
                        <div className="flex-1 min-w-0">
                          <span className="text-sm font-bold">{entry.title}</span>
                          {entry.description && (
                            <p
                              className="text-sm mt-0.5 truncate"
                              style={{ color: "var(--color-muted-foreground)" }}
                            >
                              {entry.description}
                            </p>
                          )}
                        </div>

                        {/* Delete button (visible on hover) */}
                        <button
                          onClick={() => handleDelete(entry.id)}
                          className="opacity-0 group-hover:opacity-100 transition-opacity shrink-0 p-1 rounded"
                          style={{ color: "var(--color-destructive)" }}
                          title="Eintrag löschen"
                        >
                          <IconTrash size={14} />
                        </button>
                      </div>
                    );
                  })}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
