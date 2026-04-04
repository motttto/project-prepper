"use client";

import { useState, useEffect, useCallback } from "react";
import { IconCalendar, IconPlus, IconTrash, IconX } from "@/components/ui/icons";
import { showToast } from "@/hooks/use-toast";
import { appConfirm } from "@/components/ui/confirm-dialog";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import { useCurrentUser } from "@/hooks/use-current-user";

type CalendarGroup = {
  id: string;
  name: string;
  color: string;
  sort_order: number;
};

type CalendarEvent = {
  id: string;
  group_id: string | null;
  summary: string;
  description: string | null;
  location: string | null;
  all_day: boolean;
  start_at: string;
  end_at: string | null;
  created_by: string | null;
  groupName?: string;
};

type ViewMode = "month" | "week";

const WEEKDAYS = ["Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"];
const MONTH_NAMES = [
  "Januar", "Februar", "März", "April", "Mai", "Juni",
  "Juli", "August", "September", "Oktober", "November", "Dezember",
];

const CALENDAR_COLORS = [
  "#0066FF", "#22C55E", "#F59E0B", "#EF4444", "#8B5CF6", "#EC4899", "#06B6D4", "#F97316",
];

function getGroupColor(group: CalendarGroup | undefined, idx: number): string {
  return group?.color || CALENDAR_COLORS[idx % CALENDAR_COLORS.length];
}

function isSameDay(d1: Date, d2: Date): boolean {
  return d1.getFullYear() === d2.getFullYear() && d1.getMonth() === d2.getMonth() && d1.getDate() === d2.getDate();
}

function formatTime(dateStr: string): string {
  return new Date(dateStr).toLocaleTimeString("de-DE", { hour: "2-digit", minute: "2-digit" });
}

function getMonday(d: Date): Date {
  const result = new Date(d);
  const day = result.getDay();
  result.setDate(result.getDate() - day + (day === 0 ? -6 : 1));
  result.setHours(0, 0, 0, 0);
  return result;
}

function toDateInputValue(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

function toDateTimeInputValue(d: Date): string {
  return `${toDateInputValue(d)}T${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
}

export default function CalendarPage() {
  const supabase = createClient();
  const { orgId, orgName } = useOrg();
  const currentUser = useCurrentUser();

  const [groups, setGroups] = useState<CalendarGroup[]>([]);
  const [events, setEvents] = useState<CalendarEvent[]>([]);
  const [loading, setLoading] = useState(true);
  const [currentDate, setCurrentDate] = useState(new Date());
  const [viewMode, setViewMode] = useState<ViewMode>("month");
  const [hiddenGroups, setHiddenGroups] = useState<Set<string>>(new Set());

  // Modals
  const [selectedEvent, setSelectedEvent] = useState<CalendarEvent | null>(null);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [editEvent, setEditEvent] = useState<CalendarEvent | null>(null);
  const [createForDate, setCreateForDate] = useState<Date | null>(null);
  const [showGroupManager, setShowGroupManager] = useState(false);
  const [showSubscribeInfo, setShowSubscribeInfo] = useState(false);

  // Group color helper
  const getEventColor = useCallback((event: CalendarEvent) => {
    const idx = groups.findIndex((g) => g.id === event.group_id);
    return getGroupColor(groups[idx], idx);
  }, [groups]);

  // === Data Loading ===
  const fetchGroups = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("calendar_groups")
      .select("*")
      .eq("org_id", orgId)
      .order("sort_order");
    if (data) setGroups(data);
  }, [supabase, orgId]);

  const fetchEvents = useCallback(async () => {
    if (!orgId) return;
    setLoading(true);

    let start: Date;
    let end: Date;
    if (viewMode === "month") {
      start = new Date(currentDate.getFullYear(), currentDate.getMonth(), -7);
      end = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 14);
    } else {
      const monday = getMonday(currentDate);
      start = new Date(monday); start.setDate(start.getDate() - 1);
      end = new Date(monday); end.setDate(end.getDate() + 8);
    }

    const { data } = await supabase
      .from("calendar_events")
      .select("*")
      .eq("org_id", orgId)
      .gte("start_at", start.toISOString())
      .lte("start_at", end.toISOString())
      .order("start_at");

    if (data) setEvents(data);
    setLoading(false);
  }, [supabase, orgId, currentDate, viewMode]);

  useEffect(() => { fetchGroups(); }, [fetchGroups]);
  useEffect(() => { fetchEvents(); }, [fetchEvents]);

  // Realtime
  useEffect(() => {
    if (!orgId) return;
    const channel = supabase
      .channel("calendar-realtime")
      .on("postgres_changes", { event: "*", schema: "public", table: "calendar_events", filter: `org_id=eq.${orgId}` }, () => fetchEvents())
      .on("postgres_changes", { event: "*", schema: "public", table: "calendar_groups", filter: `org_id=eq.${orgId}` }, () => fetchGroups())
      .subscribe();
    return () => { supabase.removeChannel(channel); };
  }, [supabase, orgId, fetchEvents, fetchGroups]);

  // Navigation
  function goNext() {
    setCurrentDate(viewMode === "month"
      ? new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 1)
      : new Date(currentDate.getTime() + 7 * 86400000));
  }
  function goPrev() {
    setCurrentDate(viewMode === "month"
      ? new Date(currentDate.getFullYear(), currentDate.getMonth() - 1, 1)
      : new Date(currentDate.getTime() - 7 * 86400000));
  }
  function goToday() { setCurrentDate(new Date()); }

  function toggleGroup(groupId: string) {
    setHiddenGroups((prev) => {
      const next = new Set(prev);
      next.has(groupId) ? next.delete(groupId) : next.add(groupId);
      return next;
    });
  }

  // Events für einen Tag (gefiltert)
  function getEventsForDay(date: Date): CalendarEvent[] {
    return events.filter((e) => {
      if (e.group_id && hiddenGroups.has(e.group_id)) return false;
      const eStart = new Date(e.start_at);
      const eEnd = e.end_at ? new Date(e.end_at) : eStart;
      if (e.all_day) {
        const dayStart = new Date(date); dayStart.setHours(0, 0, 0, 0);
        const dayEnd = new Date(date); dayEnd.setHours(23, 59, 59, 999);
        return eStart <= dayEnd && eEnd >= dayStart;
      }
      return isSameDay(eStart, date);
    });
  }

  // === CRUD ===
  async function handleDelete(event: CalendarEvent) {
    if (!(await appConfirm(`"${event.summary}" wirklich löschen?`, { variant: "danger", confirmLabel: "Löschen" }))) return;
    const { error } = await supabase.from("calendar_events").delete().eq("id", event.id);
    if (error) { showToast("Fehler beim Löschen", "error"); }
    else {
      showToast("Termin gelöscht", "success");
      setSelectedEvent(null);
      setEditEvent(null);
    }
  }

  async function handleSave(data: {
    group_id: string | null;
    id?: string;
    summary: string;
    start_at: string;
    end_at: string | null;
    all_day: boolean;
    location?: string;
    description?: string;
  }) {
    const payload = {
      org_id: orgId,
      group_id: data.group_id || null,
      summary: data.summary,
      start_at: data.start_at,
      end_at: data.end_at,
      all_day: data.all_day,
      location: data.location || null,
      description: data.description || null,
    };

    let error;
    if (data.id) {
      ({ error } = await supabase.from("calendar_events").update(payload).eq("id", data.id));
    } else {
      ({ error } = await supabase.from("calendar_events").insert(payload));
    }

    if (error) {
      showToast(error.message || "Fehler beim Speichern", "error");
    } else {
      showToast(data.id ? "Termin aktualisiert" : "Termin erstellt", "success");
      setShowCreateModal(false);
      setEditEvent(null);
      setSelectedEvent(null);
    }
  }

  // Monats-Grid
  function getMonthDays(): Date[][] {
    const year = currentDate.getFullYear(), month = currentDate.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    let startOffset = firstDay.getDay() - 1;
    if (startOffset < 0) startOffset = 6;
    const weeks: Date[][] = [];
    let week: Date[] = [];
    for (let i = startOffset - 1; i >= 0; i--) week.push(new Date(year, month, -i));
    for (let day = 1; day <= lastDay.getDate(); day++) {
      week.push(new Date(year, month, day));
      if (week.length === 7) { weeks.push(week); week = []; }
    }
    if (week.length > 0) {
      let n = 1;
      while (week.length < 7) week.push(new Date(year, month + 1, n++));
      weeks.push(week);
    }
    return weeks;
  }

  function getWeekDays(): Date[] {
    const monday = getMonday(currentDate);
    return Array.from({ length: 7 }, (_, i) => {
      const d = new Date(monday);
      d.setDate(d.getDate() + i);
      return d;
    });
  }

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  const inputStyle = { border: "1px solid var(--color-border)", background: "var(--color-background)" };

  // "Ohne Gruppe" als filter
  const ungroupedEvents = events.filter((e) => !e.group_id);
  const hasUngrouped = ungroupedEvents.length > 0;

  return (
    <div>
      {/* Header — Titel + Aktionen */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
        <h1 className="text-2xl font-bold flex items-center gap-2.5">
          <IconCalendar size={24} />
          Team-Kalender
        </h1>
        <div className="flex items-center gap-2">
          <button
            onClick={() => { setCreateForDate(new Date()); setShowCreateModal(true); }}
            className="flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-semibold text-white transition-colors hover:opacity-90"
            style={{ background: "var(--color-primary)" }}
          >
            <IconPlus size={15} />
            Neuer Termin
          </button>
          <button
            onClick={() => setShowGroupManager(true)}
            className="px-3 py-2 rounded-lg text-sm font-medium transition-colors hover:opacity-80"
            style={{ border: "1px solid var(--color-border)", color: "var(--color-muted-foreground)" }}
          >
            Gruppen
          </button>
          <button
            onClick={() => setShowSubscribeInfo(true)}
            className="px-3 py-2 rounded-lg text-sm font-medium transition-colors hover:opacity-80"
            style={{ border: "1px solid var(--color-border)", color: "var(--color-muted-foreground)" }}
            title="Kalender auf anderen Geräten einbinden"
          >
            Einbinden
          </button>
        </div>
      </div>

      {/* Toolbar — Navigation + View Toggle + Gruppen-Filter */}
      <div className="flex flex-col gap-2 mb-4">
        {/* Zeile 1: Nav + View */}
        <div className="flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}>
          <div className="flex items-center gap-2">
            <button onClick={goPrev} className="w-8 h-8 rounded-lg flex items-center justify-center hover:opacity-80 transition-opacity text-base" style={{ background: "var(--color-muted)" }} aria-label="Zurück">&lsaquo;</button>
            <button onClick={goNext} className="w-8 h-8 rounded-lg flex items-center justify-center hover:opacity-80 transition-opacity text-base" style={{ background: "var(--color-muted)" }} aria-label="Vor">&rsaquo;</button>
            <button onClick={goToday} className="px-3 py-1.5 rounded-lg text-xs font-semibold hover:opacity-80 transition-opacity" style={{ background: "var(--color-muted)" }}>Heute</button>
          </div>

          <h2 className="text-base font-bold tracking-tight">
            {viewMode === "month"
              ? `${MONTH_NAMES[currentDate.getMonth()]} ${currentDate.getFullYear()}`
              : (() => { const d = getWeekDays(); return `${d[0].getDate()}. ${MONTH_NAMES[d[0].getMonth()].slice(0, 3)} – ${d[6].getDate()}. ${MONTH_NAMES[d[6].getMonth()].slice(0, 3)} ${d[6].getFullYear()}`; })()}
          </h2>

          <div className="flex rounded-lg overflow-hidden" style={{ border: "1px solid var(--color-border)" }}>
            <button
              onClick={() => setViewMode("month")}
              className="px-3.5 py-1.5 text-xs font-semibold transition-colors"
              style={{
                background: viewMode === "month" ? "var(--color-primary)" : "transparent",
                color: viewMode === "month" ? "white" : "var(--color-muted-foreground)",
              }}
            >Monat</button>
            <button
              onClick={() => setViewMode("week")}
              className="px-3.5 py-1.5 text-xs font-semibold transition-colors"
              style={{
                background: viewMode === "week" ? "var(--color-primary)" : "transparent",
                color: viewMode === "week" ? "white" : "var(--color-muted-foreground)",
              }}
            >Woche</button>
          </div>
        </div>

        {/* Zeile 2: Gruppen-Filter */}
        {groups.length > 0 && (
          <div className="flex flex-wrap items-center gap-2">
            {groups.map((group, idx) => {
              const color = getGroupColor(group, idx);
              const isHidden = hiddenGroups.has(group.id);
              return (
                <button
                  key={group.id}
                  onClick={() => toggleGroup(group.id)}
                  className="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all"
                  style={{
                    background: isHidden ? "var(--color-muted)" : `${color}18`,
                    color: isHidden ? "var(--color-muted-foreground)" : color,
                    border: `1.5px solid ${isHidden ? "transparent" : color}`,
                    opacity: isHidden ? 0.5 : 1,
                  }}
                >
                  <span
                    className="w-3 h-3 rounded-full flex-shrink-0"
                    style={{ background: isHidden ? "var(--color-muted-foreground)" : color }}
                  />
                  {group.name}
                </button>
              );
            })}
          </div>
        )}
      </div>

      {loading && <div className="text-center py-4 text-sm" style={{ color: "var(--color-muted-foreground)" }}>Kalender wird geladen...</div>}

      {/* === MONATSANSICHT === */}
      {viewMode === "month" && (
        <div className="rounded-xl overflow-hidden" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}>
          {/* Wochentag-Header */}
          <div className="grid grid-cols-7" style={{ borderBottom: "2px solid var(--color-border)" }}>
            {WEEKDAYS.map((day, i) => (
              <div key={day} className="text-center text-[11px] font-bold uppercase tracking-wider py-2.5"
                style={{ color: i >= 5 ? "var(--color-primary)" : "var(--color-muted-foreground)" }}>
                {day}
              </div>
            ))}
          </div>
          {getMonthDays().map((week, wIdx) => (
            <div key={wIdx} className="grid grid-cols-7" style={{ borderBottom: wIdx < getMonthDays().length - 1 ? "1px solid var(--color-border-light)" : "none" }}>
              {week.map((day, dIdx) => {
                const isCurrentMonth = day.getMonth() === currentDate.getMonth();
                const isToday = isSameDay(day, today);
                const isWeekend = dIdx >= 5;
                const dayEvents = getEventsForDay(day);
                return (
                  <div
                    key={dIdx}
                    className="p-1.5 min-h-[100px] relative group cursor-pointer transition-colors"
                    style={{
                      borderRight: dIdx < 6 ? "1px solid var(--color-border-light)" : "none",
                      opacity: isCurrentMonth ? 1 : 0.3,
                      background: isToday
                        ? "rgba(var(--color-primary-rgb, 0,102,255), 0.06)"
                        : isWeekend
                        ? "rgba(var(--color-primary-rgb, 0,102,255), 0.02)"
                        : "transparent",
                    }}
                    onDoubleClick={() => { setCreateForDate(day); setShowCreateModal(true); }}
                  >
                    {/* Tageszahl */}
                    <div className="flex justify-center mb-1">
                      <span className={`text-xs font-semibold w-7 h-7 flex items-center justify-center rounded-full ${isToday ? "shadow-sm" : ""}`}
                        style={{
                          background: isToday ? "var(--color-primary)" : "transparent",
                          color: isToday ? "white" : isWeekend ? "var(--color-primary)" : "var(--color-foreground)",
                        }}>
                        {day.getDate()}
                      </span>
                    </div>
                    {/* Schnell-Erstellen Button */}
                    <button
                      onClick={(e) => { e.stopPropagation(); setCreateForDate(day); setShowCreateModal(true); }}
                      className="absolute top-1 right-1 w-6 h-6 rounded-md flex items-center justify-center opacity-0 group-hover:opacity-60 hover:!opacity-100 transition-all"
                      style={{ color: "var(--color-primary)", background: "var(--color-primary-light)" }}
                      title="Termin hinzufügen"
                    >
                      <IconPlus size={12} />
                    </button>
                    {/* Events */}
                    <div className="space-y-0.5">
                      {dayEvents.slice(0, 3).map((event) => {
                        const color = getEventColor(event);
                        return (
                          <button
                            key={event.id + event.start_at}
                            onClick={() => setSelectedEvent(event)}
                            className="w-full text-left px-1.5 py-[3px] rounded text-[11px] leading-tight truncate font-medium transition-opacity hover:opacity-80 flex items-center gap-0"
                            style={{ background: `${color}18`, borderLeft: `3px solid ${color}`, color }}
                            title={event.summary}
                          >
                            {!event.all_day && <span className="font-normal opacity-70 mr-1 flex-shrink-0">{formatTime(event.start_at)}</span>}
                            <span className="truncate">{event.summary}</span>
                          </button>
                        );
                      })}
                      {dayEvents.length > 3 && (
                        <button
                          onClick={() => { /* TODO: Show all events */ }}
                          className="text-[11px] font-semibold px-1.5 py-0.5 rounded hover:opacity-80 transition-opacity w-full text-left"
                          style={{ color: "var(--color-primary)" }}
                        >
                          +{dayEvents.length - 3} weitere
                        </button>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          ))}
        </div>
      )}

      {/* === WOCHENANSICHT (Apple Calendar Style) === */}
      {viewMode === "week" && (
        <WeekTimeGrid
          days={getWeekDays()}
          today={today}
          getEventsForDay={getEventsForDay}
          getEventColor={getEventColor}
          onEventClick={setSelectedEvent}
          onCreateClick={(date) => { setCreateForDate(date); setShowCreateModal(true); }}
        />
      )}

      {/* === EVENT DETAIL MODAL === */}
      {selectedEvent && !editEvent && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: "rgba(0,0,0,0.5)" }} onClick={() => setSelectedEvent(null)}>
          <div className="w-full max-w-md rounded-xl overflow-hidden" style={{ background: "var(--color-surface)", boxShadow: "var(--shadow-lg)" }} onClick={(e) => e.stopPropagation()}>
            <div className="px-6 py-4" style={{ background: `${getEventColor(selectedEvent)}15`, borderBottom: "1px solid var(--color-border-light)" }}>
              <div className="flex items-start justify-between gap-3">
                <h2 className="text-lg font-bold" style={{ color: getEventColor(selectedEvent) }}>{selectedEvent.summary}</h2>
                {selectedEvent.group_id && (() => {
                  const g = groups.find((gr) => gr.id === selectedEvent.group_id);
                  return g ? (
                    <span className="text-[10px] px-2 py-0.5 rounded-full flex-shrink-0 font-medium mt-1"
                      style={{ background: `${g.color}25`, color: g.color }}>
                      {g.name}
                    </span>
                  ) : null;
                })()}
              </div>
            </div>
            <div className="px-6 py-4 space-y-3">
              <div className="flex items-start gap-3">
                <IconCalendar size={16} style={{ color: "var(--color-muted-foreground)", marginTop: 2 }} />
                <div>
                  <p className="text-sm font-medium">{new Date(selectedEvent.start_at).toLocaleDateString("de-DE", { weekday: "long", day: "numeric", month: "long", year: "numeric" })}</p>
                  <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    {selectedEvent.all_day ? "Ganztägig" : `${formatTime(selectedEvent.start_at)} – ${selectedEvent.end_at ? formatTime(selectedEvent.end_at) : ""}`}
                  </p>
                </div>
              </div>
              {selectedEvent.location && (
                <div className="flex items-start gap-3"><span className="text-sm mt-0.5">📍</span><p className="text-sm">{selectedEvent.location}</p></div>
              )}
              {selectedEvent.description && (
                <div className="flex items-start gap-3"><span className="text-sm mt-0.5">📝</span><p className="text-sm whitespace-pre-wrap" style={{ color: "var(--color-muted-foreground)" }}>{selectedEvent.description}</p></div>
              )}
            </div>
            <div className="px-6 py-3 flex items-center justify-between gap-2" style={{ borderTop: "1px solid var(--color-border-light)" }}>
              <button onClick={() => handleDelete(selectedEvent)} className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                style={{ color: "var(--color-destructive)", border: "1px solid var(--color-destructive)" }}>
                <IconTrash size={13} /> Löschen
              </button>
              <div className="flex items-center gap-2">
                <button onClick={() => setEditEvent(selectedEvent)} className="px-3 py-1.5 rounded-lg text-xs font-medium text-white transition-colors" style={{ background: "var(--color-primary)" }}>
                  Bearbeiten
                </button>
                <button onClick={() => setSelectedEvent(null)} className="px-3 py-1.5 rounded-lg text-xs font-medium" style={{ background: "var(--color-muted)" }}>
                  Schließen
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* === CREATE / EDIT MODAL === */}
      {(showCreateModal || editEvent) && (
        <EventFormModal
          groups={groups}
          event={editEvent || undefined}
          defaultDate={createForDate || new Date()}
          inputStyle={inputStyle}
          onSave={handleSave}
          onDelete={editEvent ? () => handleDelete(editEvent) : undefined}
          onClose={() => { setShowCreateModal(false); setEditEvent(null); setCreateForDate(null); }}
        />
      )}

      {/* === GROUP MANAGER MODAL === */}
      {showGroupManager && (
        <GroupManager
          supabase={supabase}
          orgId={orgId!}
          groups={groups}
          inputStyle={inputStyle}
          onClose={() => setShowGroupManager(false)}
        />
      )}

      {/* === SUBSCRIBE INFO MODAL === */}
      {showSubscribeInfo && orgId && (
        <SubscribeInfoModal
          orgId={orgId}
          orgName={orgName}
          profileId={currentUser?.id || ""}
          groups={groups}
          isAdmin={currentUser?.roleName === "admin"}
          onClose={() => setShowSubscribeInfo(false)}
        />
      )}
    </div>
  );
}

// === Week Time Grid (Apple Calendar Style) ===
const HOUR_HEIGHT = 64;
const START_HOUR = 6;
const END_HOUR = 23;
const TOTAL_HOURS = END_HOUR - START_HOUR;

function WeekTimeGrid({
  days,
  today,
  getEventsForDay,
  getEventColor,
  onEventClick,
  onCreateClick,
}: {
  days: Date[];
  today: Date;
  getEventsForDay: (date: Date) => CalendarEvent[];
  getEventColor: (event: CalendarEvent) => string;
  onEventClick: (event: CalendarEvent) => void;
  onCreateClick: (date: Date) => void;
}) {
  const allDayRows: { day: Date; events: CalendarEvent[] }[] = days.map((day) => ({
    day,
    events: getEventsForDay(day).filter((e) => e.all_day),
  }));
  const hasAllDay = allDayRows.some((r) => r.events.length > 0);
  const maxAllDay = Math.max(1, ...allDayRows.map((r) => r.events.length));

  const now = new Date();
  const nowMinutes = now.getHours() * 60 + now.getMinutes();
  const nowTop = ((nowMinutes - START_HOUR * 60) / (TOTAL_HOURS * 60)) * (TOTAL_HOURS * HOUR_HEIGHT);

  return (
    <div className="rounded-xl overflow-hidden" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}>
      {/* Column Headers */}
      <div className="flex" style={{ borderBottom: "2px solid var(--color-border)" }}>
        <div className="flex-shrink-0 w-[56px]" />
        {days.map((day, idx) => {
          const isToday = isSameDay(day, today);
          const isWeekend = idx >= 5;
          const dayShort = day.toLocaleDateString("de-DE", { weekday: "short" });
          return (
            <div
              key={idx}
              className="flex-1 text-center py-2.5 min-w-0"
              style={{
                borderLeft: "1px solid var(--color-border-light)",
                background: isToday ? "var(--color-primary-light)" : "transparent",
              }}
            >
              <p className="text-[10px] uppercase font-bold tracking-wider" style={{ color: isToday ? "var(--color-primary)" : isWeekend ? "var(--color-primary)" : "var(--color-muted-foreground)" }}>
                {dayShort}
              </p>
              <p
                className={`text-lg font-bold leading-tight w-9 h-9 mx-auto flex items-center justify-center rounded-full ${isToday ? "shadow-sm" : ""}`}
                style={{
                  background: isToday ? "var(--color-primary)" : "transparent",
                  color: isToday ? "white" : isWeekend ? "var(--color-primary)" : "var(--color-foreground)",
                }}
              >
                {day.getDate()}
              </p>
            </div>
          );
        })}
      </div>

      {/* All-day row */}
      {hasAllDay && (
        <div className="flex" style={{ borderBottom: "1px solid var(--color-border)" }}>
          <div className="flex-shrink-0 w-[56px] flex items-start justify-end pr-2 pt-1.5">
            <span className="text-[10px] font-medium" style={{ color: "var(--color-muted-foreground)" }}>Ganzt.</span>
          </div>
          {days.map((day, idx) => {
            const dayAllDay = allDayRows[idx].events;
            return (
              <div
                key={idx}
                className="flex-1 min-w-0 p-1"
                style={{ borderLeft: "1px solid var(--color-border-light)", minHeight: maxAllDay * 24 + 8 }}
              >
                {dayAllDay.map((event) => {
                  const color = getEventColor(event);
                  return (
                    <button
                      key={event.id}
                      onClick={() => onEventClick(event)}
                      className="w-full text-left text-[11px] font-semibold px-1.5 py-[3px] rounded truncate mb-0.5 transition-opacity hover:opacity-80"
                      style={{ background: `${color}25`, borderLeft: `3px solid ${color}`, color, lineHeight: "1.3" }}
                      title={event.summary}
                    >
                      {event.summary}
                    </button>
                  );
                })}
              </div>
            );
          })}
        </div>
      )}

      {/* Time grid */}
      <div className="flex overflow-y-auto" style={{ maxHeight: "calc(100vh - 280px)" }}>
        {/* Stundenleiste */}
        <div className="flex-shrink-0 w-[56px] relative" style={{ height: TOTAL_HOURS * HOUR_HEIGHT }}>
          {Array.from({ length: TOTAL_HOURS }, (_, i) => (
            <div key={i} className="absolute right-3 text-[11px] font-medium"
              style={{ top: i * HOUR_HEIGHT - 7, color: "var(--color-muted-foreground)" }}>
              {String(START_HOUR + i).padStart(2, "0")}
              <span className="text-[9px] opacity-60">:00</span>
            </div>
          ))}
        </div>

        {days.map((day, dayIdx) => {
          const isToday = isSameDay(day, today);
          const isWeekend = dayIdx >= 5;
          const timedEvents = getEventsForDay(day).filter((e) => !e.all_day);
          const positioned = layoutEvents(timedEvents);

          return (
            <div
              key={dayIdx}
              className="flex-1 min-w-0 relative"
              style={{
                height: TOTAL_HOURS * HOUR_HEIGHT,
                borderLeft: "1px solid var(--color-border-light)",
                background: isToday
                  ? "rgba(var(--color-primary-rgb, 0,102,255), 0.05)"
                  : isWeekend
                  ? "rgba(var(--color-primary-rgb, 0,102,255), 0.02)"
                  : "transparent",
              }}
              onDoubleClick={(e) => {
                const rect = e.currentTarget.getBoundingClientRect();
                const y = e.clientY - rect.top;
                const hour = Math.floor(y / HOUR_HEIGHT) + START_HOUR;
                const clickDate = new Date(day);
                clickDate.setHours(hour, 0, 0, 0);
                onCreateClick(clickDate);
              }}
            >
              {/* Volle Stunden */}
              {Array.from({ length: TOTAL_HOURS }, (_, i) => (
                <div key={i} className="absolute left-0 right-0" style={{ top: i * HOUR_HEIGHT, height: 1, background: "var(--color-border)" }} />
              ))}
              {/* Halbe Stunden */}
              {Array.from({ length: TOTAL_HOURS }, (_, i) => (
                <div key={`half-${i}`} className="absolute left-0 right-0" style={{ top: i * HOUR_HEIGHT + HOUR_HEIGHT / 2, height: 1, background: "var(--color-border-light)", opacity: 0.6 }} />
              ))}

              {/* Now-Linie */}
              {isToday && nowMinutes >= START_HOUR * 60 && nowMinutes <= END_HOUR * 60 && (
                <div className="absolute left-0 right-0 z-10 pointer-events-none" style={{ top: nowTop }}>
                  <div className="relative">
                    <div className="absolute -left-[6px] -top-[6px] w-[12px] h-[12px] rounded-full" style={{ background: "var(--color-destructive)" }} />
                    <div className="h-[2px]" style={{ background: "var(--color-destructive)", boxShadow: "0 0 4px rgba(239,68,68,0.4)" }} />
                  </div>
                </div>
              )}

              {/* Events */}
              {positioned.map(({ event, top, height, left, width }) => {
                const color = getEventColor(event);
                return (
                  <button
                    key={event.id}
                    onClick={(e) => { e.stopPropagation(); onEventClick(event); }}
                    className="absolute rounded-md px-2 py-1 overflow-hidden text-left transition-all hover:opacity-90 hover:shadow-sm z-[5]"
                    style={{
                      top,
                      height: Math.max(height, 20),
                      left: `${left}%`,
                      width: `${width}%`,
                      background: `${color}25`,
                      borderLeft: `3px solid ${color}`,
                      color,
                    }}
                    title={`${event.summary}\n${formatTime(event.start_at)} – ${event.end_at ? formatTime(event.end_at) : ""}`}
                  >
                    <p className="text-[11px] font-semibold truncate leading-tight">{event.summary}</p>
                    {height > 30 && (
                      <p className="text-[10px] opacity-75 truncate">{formatTime(event.start_at)} – {event.end_at ? formatTime(event.end_at) : ""}</p>
                    )}
                    {height > 48 && event.location && (
                      <p className="text-[10px] opacity-60 truncate mt-0.5">📍 {event.location}</p>
                    )}
                  </button>
                );
              })}
            </div>
          );
        })}
      </div>
    </div>
  );
}

// Layout overlapping events into columns (like Apple Calendar)
function layoutEvents(events: CalendarEvent[]): {
  event: CalendarEvent;
  top: number;
  height: number;
  left: number;
  width: number;
}[] {
  if (events.length === 0) return [];

  const items = events.map((event) => {
    const s = new Date(event.start_at);
    const e = event.end_at ? new Date(event.end_at) : new Date(s.getTime() + 60 * 60 * 1000);
    const startMin = s.getHours() * 60 + s.getMinutes();
    const endMin = e.getHours() * 60 + e.getMinutes();
    return { event, startMin, endMin: Math.max(endMin, startMin + 15) };
  }).sort((a, b) => a.startMin - b.startMin || a.endMin - b.endMin);

  const columns: (typeof items)[] = [];

  for (const item of items) {
    let placed = false;
    for (const col of columns) {
      const lastInCol = col[col.length - 1];
      if (lastInCol.endMin <= item.startMin) {
        col.push(item);
        placed = true;
        break;
      }
    }
    if (!placed) {
      columns.push([item]);
    }
  }

  const colMap = new Map<string, number>();
  columns.forEach((col, colIdx) => {
    col.forEach((item) => {
      colMap.set(item.event.id, colIdx);
    });
  });

  const totalCols = columns.length;
  const padding = 2;

  return items.map((item) => {
    const colIdx = colMap.get(item.event.id) || 0;
    const top = ((item.startMin - START_HOUR * 60) / 60) * HOUR_HEIGHT;
    const height = ((item.endMin - item.startMin) / 60) * HOUR_HEIGHT;
    const colWidth = (100 - padding) / totalCols;
    const left = colIdx * colWidth + padding / 2;
    const width = colWidth - padding / 2;

    return { event: item.event, top, height, left, width };
  });
}

// === Event Form Modal ===
function EventFormModal({
  groups,
  event,
  defaultDate,
  inputStyle,
  onSave,
  onDelete,
  onClose,
}: {
  groups: CalendarGroup[];
  event?: CalendarEvent;
  defaultDate: Date;
  inputStyle: React.CSSProperties;
  onSave: (data: any) => Promise<void>;
  onDelete?: () => void;
  onClose: () => void;
}) {
  const isEdit = !!event;

  const [groupId, setGroupId] = useState(event?.group_id || groups[0]?.id || "");
  const [summary, setSummary] = useState(event?.summary || "");
  const [allDay, setAllDay] = useState(event?.all_day ?? true);
  const [startDate, setStartDate] = useState(() => {
    if (event) return event.all_day ? event.start_at.split("T")[0] : toDateTimeInputValue(new Date(event.start_at));
    return defaultDate.getHours() > 0 ? toDateTimeInputValue(defaultDate) : toDateInputValue(defaultDate);
  });
  const [endDate, setEndDate] = useState(() => {
    if (event && event.end_at) return event.all_day ? event.end_at.split("T")[0] : toDateTimeInputValue(new Date(event.end_at));
    if (defaultDate.getHours() > 0) {
      const end = new Date(defaultDate);
      end.setHours(end.getHours() + 1);
      return toDateTimeInputValue(end);
    }
    return toDateInputValue(defaultDate);
  });
  const [location, setLocation] = useState(event?.location || "");
  const [description, setDescription] = useState(event?.description || "");
  const [saving, setSaving] = useState(false);

  // Auto-detect allDay based on defaultDate
  useEffect(() => {
    if (!event && defaultDate.getHours() > 0) {
      setAllDay(false);
    }
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (allDay) {
      setStartDate((v) => v.includes("T") ? v.split("T")[0] : v);
      setEndDate((v) => v.includes("T") ? v.split("T")[0] : v);
    } else {
      setStartDate((v) => v.includes("T") ? v : `${v}T09:00`);
      setEndDate((v) => v.includes("T") ? v : `${v}T10:00`);
    }
  }, [allDay]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!summary.trim()) return;
    setSaving(true);

    let formattedStart = startDate;
    let formattedEnd = endDate;

    if (allDay) {
      // Ganztägig: als Date ohne Zeit, aber als ISO
      formattedStart = new Date(startDate + "T00:00:00").toISOString();
      formattedEnd = new Date((endDate || startDate) + "T23:59:59").toISOString();
    } else {
      formattedStart = new Date(startDate).toISOString();
      formattedEnd = new Date(endDate).toISOString();
    }

    await onSave({
      id: event?.id,
      group_id: groupId || null,
      summary: summary.trim(),
      start_at: formattedStart,
      end_at: formattedEnd,
      all_day: allDay,
      location: location.trim() || undefined,
      description: description.trim() || undefined,
    });
    setSaving(false);
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: "rgba(0,0,0,0.5)" }} onClick={onClose}>
      <div className="w-full max-w-lg rounded-xl overflow-hidden" style={{ background: "var(--color-surface)", boxShadow: "var(--shadow-lg)" }} onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: "1px solid var(--color-border-light)" }}>
          <h2 className="text-lg font-bold">{isEdit ? "Termin bearbeiten" : "Neuer Termin"}</h2>
          <button onClick={onClose} className="p-1.5 rounded-lg" style={{ color: "var(--color-muted-foreground)" }}><IconX size={20} /></button>
        </div>

        <form onSubmit={handleSubmit} className="px-6 py-5 space-y-4">
          <div>
            <label className="block text-sm font-medium mb-1">Titel *</label>
            <input type="text" value={summary} onChange={(e) => setSummary(e.target.value)}
              className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle}
              placeholder="Termin-Titel" required autoFocus />
          </div>

          {groups.length > 0 && (
            <div>
              <label className="block text-sm font-medium mb-1">Gruppe</label>
              <select value={groupId} onChange={(e) => setGroupId(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle}>
                <option value="">Keine Gruppe</option>
                {groups.map((g) => (
                  <option key={g.id} value={g.id}>{g.name}</option>
                ))}
              </select>
            </div>
          )}

          <label className="flex items-center gap-2 text-sm cursor-pointer select-none">
            <input type="checkbox" checked={allDay} onChange={(e) => setAllDay(e.target.checked)}
              style={{ accentColor: "var(--color-primary)" }} />
            Ganztägig
          </label>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>Von</label>
              <input type={allDay ? "date" : "datetime-local"} value={startDate} onChange={(e) => setStartDate(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} required />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>Bis</label>
              <input type={allDay ? "date" : "datetime-local"} value={endDate} onChange={(e) => setEndDate(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} />
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium mb-1">Ort</label>
            <input type="text" value={location} onChange={(e) => setLocation(e.target.value)}
              className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} placeholder="Veranstaltungsort" />
          </div>

          <div>
            <label className="block text-sm font-medium mb-1">Beschreibung</label>
            <textarea value={description} onChange={(e) => setDescription(e.target.value)}
              className="w-full px-3 py-2 rounded-lg text-sm resize-none" style={inputStyle}
              rows={3} placeholder="Notizen zum Termin..." />
          </div>

          <div className="flex items-center justify-between pt-2">
            <div>
              {isEdit && onDelete && (
                <button type="button" onClick={onDelete}
                  className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium transition-colors"
                  style={{ color: "var(--color-destructive)", border: "1px solid var(--color-destructive)" }}>
                  <IconTrash size={13} /> Löschen
                </button>
              )}
            </div>
            <div className="flex items-center gap-2">
              <button type="button" onClick={onClose}
                className="px-4 py-2 rounded-lg text-sm font-medium" style={{ background: "var(--color-muted)" }}>
                Abbrechen
              </button>
              <button type="submit" disabled={saving || !summary.trim()}
                className="px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50 transition-colors"
                style={{ background: "var(--color-primary)" }}>
                {saving ? "Wird gespeichert..." : isEdit ? "Speichern" : "Erstellen"}
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  );
}

// === Group Manager Modal ===
function GroupManager({
  supabase,
  orgId,
  groups,
  inputStyle,
  onClose,
}: {
  supabase: any;
  orgId: string;
  groups: CalendarGroup[];
  inputStyle: React.CSSProperties;
  onClose: () => void;
}) {
  const [newName, setNewName] = useState("");
  const [newColor, setNewColor] = useState("#0066FF");
  const [saving, setSaving] = useState(false);

  async function addGroup() {
    if (!newName.trim()) return;
    setSaving(true);
    const { error } = await supabase.from("calendar_groups").insert({
      org_id: orgId,
      name: newName.trim(),
      color: newColor,
      sort_order: groups.length,
    });
    if (error) showToast("Fehler: " + error.message, "error");
    else {
      showToast("Gruppe erstellt", "success");
      setNewName("");
    }
    setSaving(false);
  }

  async function deleteGroup(id: string, name: string) {
    if (!(await appConfirm(`Gruppe "${name}" löschen? Events bleiben erhalten (ohne Gruppe).`, { variant: "danger", confirmLabel: "Löschen" }))) return;
    const { error } = await supabase.from("calendar_groups").delete().eq("id", id);
    if (error) showToast("Fehler: " + error.message, "error");
    else showToast("Gruppe gelöscht", "success");
  }

  async function updateGroup(id: string, updates: Partial<CalendarGroup>) {
    await supabase.from("calendar_groups").update(updates).eq("id", id);
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: "rgba(0,0,0,0.5)" }} onClick={onClose}>
      <div className="w-full max-w-md rounded-xl overflow-hidden" style={{ background: "var(--color-surface)", boxShadow: "var(--shadow-lg)" }} onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: "1px solid var(--color-border-light)" }}>
          <h2 className="text-lg font-bold">Kalender-Gruppen</h2>
          <button onClick={onClose} className="p-1.5 rounded-lg" style={{ color: "var(--color-muted-foreground)" }}><IconX size={20} /></button>
        </div>

        <div className="px-6 py-4 space-y-3">
          {/* Bestehende Gruppen */}
          {groups.length === 0 && (
            <p className="text-sm text-center py-4" style={{ color: "var(--color-muted-foreground)" }}>
              Noch keine Gruppen erstellt
            </p>
          )}
          {groups.map((group) => (
            <div key={group.id} className="flex items-center gap-3 p-2 rounded-lg" style={{ background: "var(--color-muted)" }}>
              <input
                type="color"
                value={group.color}
                onChange={(e) => updateGroup(group.id, { color: e.target.value })}
                className="w-8 h-8 rounded cursor-pointer border-0"
                title="Farbe ändern"
              />
              <span className="flex-1 text-sm font-medium">{group.name}</span>
              <button
                onClick={() => deleteGroup(group.id, group.name)}
                className="p-1.5 rounded transition-colors hover:opacity-70"
                style={{ color: "var(--color-destructive)" }}
              >
                <IconTrash size={14} />
              </button>
            </div>
          ))}

          {/* Neue Gruppe */}
          <div className="pt-2" style={{ borderTop: "1px solid var(--color-border-light)" }}>
            <p className="text-xs font-medium mb-2" style={{ color: "var(--color-muted-foreground)" }}>Neue Gruppe</p>
            <div className="flex items-center gap-2">
              <input
                type="color"
                value={newColor}
                onChange={(e) => setNewColor(e.target.value)}
                className="w-8 h-8 rounded cursor-pointer border-0"
              />
              <input
                type="text"
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                placeholder="Gruppenname..."
                className="flex-1 px-3 py-1.5 rounded-lg text-sm"
                style={inputStyle}
                onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); addGroup(); } }}
              />
              <button
                onClick={addGroup}
                disabled={saving || !newName.trim()}
                className="px-3 py-1.5 rounded-lg text-xs font-medium text-white disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}
              >
                {saving ? "..." : "Erstellen"}
              </button>
            </div>
          </div>
        </div>

        <div className="px-6 py-3 flex justify-end" style={{ borderTop: "1px solid var(--color-border-light)" }}>
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm font-medium" style={{ background: "var(--color-muted)" }}>
            Schließen
          </button>
        </div>
      </div>
    </div>
  );
}

// === Subscribe Info Modal ===
function SubscribeInfoModal({
  orgId,
  orgName,
  profileId,
  groups,
  isAdmin,
  onClose,
}: {
  orgId: string;
  orgName: string;
  profileId: string;
  groups: CalendarGroup[];
  isAdmin: boolean;
  onClose: () => void;
}) {
  const supabase = createClient();
  const [feedToken, setFeedToken] = useState<string | null>(null);
  const [caldavToken, setCaldavToken] = useState<string | null>(null);
  const [loadingToken, setLoadingToken] = useState(true);
  const [importing, setImporting] = useState(false);
  const [importResult, setImportResult] = useState<string | null>(null);
  const [tab, setTab] = useState<"feed" | "caldav">("caldav");

  const baseUrl = typeof window !== "undefined" ? window.location.origin : "";

  // Tokens laden oder erstellen
  useEffect(() => {
    async function loadOrCreateTokens() {
      setLoadingToken(true);

      // Feed-Token (read-only)
      const { data: existingFeed } = await supabase
        .from("calendar_feed_tokens")
        .select("token")
        .eq("org_id", orgId)
        .eq("read_write", false)
        .limit(1)
        .maybeSingle();

      if (existingFeed?.token) {
        setFeedToken(existingFeed.token);
      } else {
        const { data: newFeed } = await supabase
          .from("calendar_feed_tokens")
          .insert({ org_id: orgId, profile_id: profileId, read_write: false })
          .select("token")
          .single();
        if (newFeed) setFeedToken(newFeed.token);
      }

      // CalDAV-Token (read-write)
      const { data: existingCaldav } = await supabase
        .from("calendar_feed_tokens")
        .select("token")
        .eq("org_id", orgId)
        .eq("read_write", true)
        .limit(1)
        .maybeSingle();

      if (existingCaldav?.token) {
        setCaldavToken(existingCaldav.token);
      } else {
        const { data: newCaldav } = await supabase
          .from("calendar_feed_tokens")
          .insert({ org_id: orgId, profile_id: profileId, read_write: true })
          .select("token")
          .single();
        if (newCaldav) setCaldavToken(newCaldav.token);
      }

      setLoadingToken(false);
    }
    loadOrCreateTokens();
  }, [supabase, orgId]);

  const feedUrl = feedToken ? `${baseUrl}/api/calendar/feed?token=${feedToken}` : "";
  const webcalUrl = feedUrl.replace(/^https?:/, "webcal:");
  // CalDAV läuft über Cloudflare Worker Proxy (Vercel blockt PROPFIND/REPORT)
  const caldavUrl = caldavToken ? `https://caldav-proxy.post-cd8.workers.dev/api/caldav/${caldavToken}/` : "";

  async function handleImport() {
    setImporting(true);
    setImportResult(null);
    try {
      const res = await fetch(`/api/calendar/import?org_id=${orgId}`, { method: "POST" });
      const data = await res.json();
      if (res.ok) {
        setImportResult(`Import erfolgreich: ${data.groupsCreated} Gruppen und ${data.eventsCreated} Termine importiert.`);
        showToast("Import abgeschlossen", "success");
      } else {
        setImportResult(`Fehler: ${data.error}`);
        showToast("Import fehlgeschlagen", "error");
      }
    } catch {
      setImportResult("Verbindungsfehler");
      showToast("Import fehlgeschlagen", "error");
    }
    setImporting(false);
  }

  function copyToClipboard(text: string) {
    navigator.clipboard.writeText(text);
    showToast("URL kopiert", "success");
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: "rgba(0,0,0,0.5)" }} onClick={onClose}>
      <div className="w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-xl" style={{ background: "var(--color-surface)", boxShadow: "var(--shadow-lg)" }} onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between px-6 py-4 sticky top-0" style={{ borderBottom: "1px solid var(--color-border-light)", background: "var(--color-surface)" }}>
          <h2 className="text-lg font-bold">Kalender einbinden</h2>
          <button onClick={onClose} className="p-1.5 rounded-lg" style={{ color: "var(--color-muted-foreground)" }}><IconX size={20} /></button>
        </div>

        {/* Tab-Auswahl */}
        <div className="flex px-6 pt-3 gap-1" style={{ borderBottom: "1px solid var(--color-border-light)" }}>
          {[
            { key: "caldav" as const, label: "Zwei-Wege-Sync (CalDAV)" },
            { key: "feed" as const, label: "Nur lesen (iCal)" },
          ].map((t) => (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className="px-3 py-2 text-xs font-medium rounded-t-lg transition-colors"
              style={{
                background: tab === t.key ? "var(--color-surface)" : "transparent",
                color: tab === t.key ? "var(--color-primary)" : "var(--color-muted-foreground)",
                borderBottom: tab === t.key ? "2px solid var(--color-primary)" : "2px solid transparent",
              }}
            >
              {t.label}
            </button>
          ))}
        </div>

        <div className="px-6 py-5 space-y-5">
          {loadingToken ? (
            <div className="text-center py-4 text-sm" style={{ color: "var(--color-muted-foreground)" }}>Token wird geladen...</div>
          ) : tab === "caldav" ? (
            /* ========== CalDAV (Zwei-Wege-Sync) ========== */
            caldavToken ? (
              <>
                <div className="p-3 rounded-lg text-xs" style={{ background: "var(--color-success-light)", border: "1px solid var(--color-success)", color: "var(--color-success)" }}>
                  Zwei-Wege-Sync: Termine erstellen, bearbeiten und löschen — alles synchronisiert sich automatisch mit Project Prepper.
                </div>

                {/* Kompatibilität */}
                <div>
                  <h3 className="text-sm font-semibold mb-2">Kompatibilität</h3>
                  <div className="grid grid-cols-2 gap-1.5">
                    {[
                      { name: "Apple Calendar", ok: true, hint: "" },
                      { name: "Android (DAVx5)", ok: true, hint: "" },
                      { name: "Thunderbird", ok: true, hint: "Windows / Mac / Linux" },
                      { name: "Outlook Desktop", ok: true, hint: "mit Plugin" },
                      { name: "Google Calendar", ok: false, hint: "nur Feed" },
                      { name: "Outlook Web", ok: false, hint: "nur Feed" },
                    ].map((app) => (
                      <div key={app.name} className="flex items-center gap-2 px-2.5 py-1.5 rounded text-xs" style={{ background: "var(--color-muted)" }}>
                        <span style={{ color: app.ok ? "var(--color-success)" : "var(--color-muted-foreground)" }}>
                          {app.ok ? "\u2713" : "\u2717"}
                        </span>
                        <span style={{ color: app.ok ? "var(--color-foreground)" : "var(--color-muted-foreground)" }}>
                          {app.name}{app.hint ? <span className="opacity-60"> ({app.hint})</span> : null}
                        </span>
                      </div>
                    ))}
                  </div>
                  <p className="text-[11px] mt-1.5" style={{ color: "var(--color-muted-foreground)" }}>
                    Windows-Tipp: Thunderbird ist die beste kostenlose Option für Zwei-Wege-Sync. Google Calendar und Outlook Web nutzen den Nur-Lesen-Feed.
                  </p>
                </div>

                {/* Zugangsdaten — alles an einem Ort */}
                <div>
                  <h3 className="text-sm font-semibold mb-2">Zugangsdaten</h3>
                  <div className="space-y-2">
                    {[
                      { label: "Server", value: "caldav-proxy.post-cd8.workers.dev" },
                      { label: "Benutzer", value: orgName || "caldav" },
                      { label: "Passwort", value: caldavToken || "" },
                    ].map((field) => (
                      <div key={field.label} className="flex items-center gap-3 p-2.5 rounded-lg" style={{ background: "var(--color-muted)" }}>
                        <span className="text-xs font-medium w-16 flex-shrink-0" style={{ color: "var(--color-muted-foreground)" }}>{field.label}:</span>
                        <span className="text-[10px] font-mono flex-1 truncate select-all">{field.value}</span>
                        <button
                          onClick={() => copyToClipboard(field.value)}
                          className="px-2 py-1 rounded text-[10px] font-medium flex-shrink-0"
                          style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
                        >
                          Kopieren
                        </button>
                      </div>
                    ))}
                  </div>
                  <div className="p-2.5 rounded-lg text-[11px]" style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
                    <strong style={{ color: "var(--color-foreground)" }}>Nach dem Einrichten:</strong> Der Account erscheint mit der Server-Adresse als Name. Um ihn umzubenennen: Systemeinstellungen → Internetaccounts → Account anklicken → Beschreibung in &quot;{orgName || "Project Prepper"}&quot; ändern.
                  </div>
                </div>

                {/* CalDAV Anleitungen */}
                <div>
                  <h3 className="text-sm font-semibold mb-3">Anleitung</h3>
                  <div className="space-y-3">
                    {[
                      { title: "Apple Calendar (Mac)", icon: "🍎", steps: [
                        "Systemeinstellungen → Internetaccounts → Anderer Account",
                        "\"CalDAV-Account\" auswählen",
                        "Account-Typ: \"Manuell\"",
                        "Server: oben kopieren und einfügen",
                        "Benutzername: caldav",
                        "Passwort: oben kopieren und einfügen",
                      ]},
                      { title: "Apple Calendar (iPhone / iPad)", icon: "📱", steps: [
                        "Einstellungen → Kalender → Accounts → Account hinzufügen",
                        "\"Andere\" → CalDAV-Account hinzufügen",
                        "Server, Benutzername und Passwort von oben eintragen",
                      ]},
                      { title: "Android (DAVx5)", icon: "🤖", steps: [
                        "DAVx5 aus dem Play Store oder F-Droid installieren (kostenlos bei F-Droid)",
                        "Konto hinzufügen → \"Mit URL und Benutzername anmelden\"",
                        "URL: https://caldav-proxy.post-cd8.workers.dev",
                        "Benutzername: caldav, Passwort: oben kopieren",
                        "Kalender auswählen und synchronisieren",
                      ]},
                      { title: "Thunderbird (Windows / Mac / Linux)", icon: "🦊", steps: [
                        "thunderbird.net → kostenlos herunterladen und installieren",
                        "Kalender → Neuer Kalender → Im Netzwerk",
                        "Format: CalDAV",
                        "URL: https://caldav-proxy.post-cd8.workers.dev/.well-known/caldav",
                        "Zugangsdaten eingeben wenn gefragt",
                      ]},
                      { title: "Outlook Desktop (mit Plugin)", icon: "📧", steps: [
                        "caldavsynchronizer.org → \"CalDav Synchronizer\" herunterladen",
                        "Outlook schließen → Add-In installieren → Outlook starten",
                        "CalDav Synchronizer → Neues Profil anlegen",
                        "Server-URL und Zugangsdaten von oben eintragen",
                      ]},
                    ].map((instr) => (
                      <div key={instr.title} className="p-3 rounded-lg" style={{ background: "var(--color-muted)" }}>
                        <p className="text-sm font-semibold mb-1.5">{instr.icon} {instr.title}</p>
                        <ol className="space-y-1">
                          {instr.steps.map((step, i) => (
                            <li key={i} className="text-xs flex gap-2" style={{ color: "var(--color-muted-foreground)" }}>
                              <span className="font-bold flex-shrink-0" style={{ color: "var(--color-primary)" }}>{i + 1}.</span>
                              {step}
                            </li>
                          ))}
                        </ol>
                      </div>
                    ))}
                  </div>
                </div>

              </>
            ) : (
              <div className="text-center py-4 text-sm" style={{ color: "var(--color-destructive)" }}>Token konnte nicht erstellt werden.</div>
            )
          ) : (
            /* ========== iCal Feed (Nur lesen) ========== */
            feedToken ? (
              <>
                <div className="p-3 rounded-lg text-xs" style={{ background: "var(--color-primary-light)", border: "1px solid var(--color-primary)", color: "var(--color-primary)" }}>
                  Nur-Lesen-Feed: Termine werden automatisch synchronisiert. Neue Termine nur in der Web-App erstellen. Ideal für Google Calendar, Outlook Web und alle Apps ohne CalDAV-Support.
                </div>

                {/* Kompatibilität */}
                <div>
                  <h3 className="text-sm font-semibold mb-2">Kompatibilität</h3>
                  <div className="grid grid-cols-2 gap-1.5">
                    {[
                      { name: "Apple Calendar", hint: "Mac / iPhone" },
                      { name: "Google Calendar", hint: "Web / Android" },
                      { name: "Outlook", hint: "Desktop / Web / 365" },
                      { name: "Windows Kalender", hint: "Windows 10 / 11" },
                      { name: "Thunderbird", hint: "Windows / Mac / Linux" },
                      { name: "Android", hint: "Google / ICSx5" },
                    ].map((app) => (
                      <div key={app.name} className="flex items-center gap-2 px-2.5 py-1.5 rounded text-xs" style={{ background: "var(--color-muted)" }}>
                        <span style={{ color: "var(--color-success)" }}>{"\u2713"}</span>
                        <span>{app.name}</span>
                        {app.hint && <span className="ml-auto text-[10px]" style={{ color: "var(--color-muted-foreground)" }}>{app.hint}</span>}
                      </div>
                    ))}
                  </div>
                  <p className="text-[11px] mt-1.5" style={{ color: "var(--color-muted-foreground)" }}>
                    Alle Kalender-Apps unterstützen iCal-Feeds. Für Zwei-Wege-Sync (Termine bearbeiten) nutze den CalDAV-Tab.
                  </p>
                  <p className="text-[11px] mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                    💡 <strong>Windows-Tipp:</strong> Für Zwei-Wege-Sync auf Windows nutze Thunderbird (kostenlos) — siehe CalDAV-Tab.
                  </p>
                </div>

                {/* Direkt-Abo Button */}
                <div>
                  <a
                    href={webcalUrl}
                    className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium text-white transition-colors"
                    style={{ background: "var(--color-primary)" }}
                  >
                    <IconCalendar size={16} />
                    In Kalender-App abonnieren
                  </a>
                  <p className="text-[11px] mt-1.5" style={{ color: "var(--color-muted-foreground)" }}>
                    Klick öffnet direkt deine Standard-Kalender-App (Mac / iPhone).
                  </p>
                </div>

                {/* Abo-URL */}
                <div>
                  <h3 className="text-sm font-semibold mb-2">Abo-URL manuell einrichten</h3>
                  <div className="flex items-center gap-2">
                    <input
                      type="text"
                      readOnly
                      value={feedUrl}
                      className="flex-1 px-3 py-2 rounded-lg text-[10px] font-mono"
                      style={{ border: "1px solid var(--color-border)", background: "var(--color-muted)" }}
                      onClick={(e) => (e.target as HTMLInputElement).select()}
                    />
                    <button
                      onClick={() => copyToClipboard(feedUrl)}
                      className="px-3 py-2 rounded-lg text-xs font-medium text-white flex-shrink-0"
                      style={{ background: "var(--color-primary)" }}
                    >
                      Kopieren
                    </button>
                  </div>
                </div>

                {/* Pro Gruppe */}
                {groups.length > 1 && (
                  <div>
                    <h3 className="text-sm font-semibold mb-2">Einzelne Gruppen abonnieren</h3>
                    <div className="space-y-1.5">
                      {groups.map((group) => {
                        const groupUrl = `${feedUrl}&group_id=${group.id}`;
                        return (
                          <div key={group.id} className="flex items-center gap-2 p-2 rounded-lg" style={{ background: "var(--color-muted)" }}>
                            <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ background: group.color }} />
                            <span className="flex-1 text-sm font-medium truncate">{group.name}</span>
                            <button
                              onClick={() => copyToClipboard(groupUrl)}
                              className="px-2 py-1 rounded text-[10px] font-medium flex-shrink-0"
                              style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
                            >
                              URL kopieren
                            </button>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                )}

                {/* Anleitungen */}
                <div>
                  <h3 className="text-sm font-semibold mb-3">Anleitung</h3>
                  <div className="space-y-3">
                    {[
                      { title: "Apple Calendar (iPhone / Mac)", icon: "🍎", steps: [
                        "Einstellungen → Kalender → Accounts → Account hinzufügen",
                        "\"Andere\" → Kalenderabo hinzufügen",
                        "Die Abo-URL einfügen und bestätigen",
                      ]},
                      { title: "Google Calendar (Web / Android)", icon: "📅", steps: [
                        "Google Calendar am PC öffnen (calendar.google.com)",
                        "Links bei \"Weitere Kalender\" auf + → Per URL",
                        "Die Abo-URL einfügen und \"Kalender hinzufügen\"",
                        "Hinweis: Google aktualisiert nur alle paar Stunden",
                      ]},
                      { title: "Outlook Web / 365", icon: "📧", steps: [
                        "outlook.live.com oder outlook.office.com öffnen",
                        "Kalender → Kalender hinzufügen → Aus dem Internet abonnieren",
                        "Die Abo-URL einfügen und \"Importieren\"",
                      ]},
                      { title: "Outlook Desktop (Windows)", icon: "💼", steps: [
                        "Kalender-Ansicht öffnen → Startseite → Kalender öffnen → Aus dem Internet",
                        "Die Abo-URL einfügen und bestätigen",
                        "Hinweis: Für Zwei-Wege-Sync nutze CalDAV mit Thunderbird",
                      ]},
                      { title: "Windows Kalender-App", icon: "🪟", steps: [
                        "Einstellungen → Konten verwalten → Konto hinzufügen → Anderes Konto",
                        "Die Abo-URL als iCal-Feed eintragen",
                        "Alternativ: Abo-URL im Browser öffnen → \"Öffnen mit Kalender\" wählen",
                      ]},
                      { title: "Thunderbird (Windows / Mac / Linux)", icon: "🦊", steps: [
                        "Kalender → Neuer Kalender → Im Netzwerk",
                        "Format: iCalendar (ICS), die Abo-URL einfügen",
                        "Tipp: Thunderbird kann auch CalDAV für Zwei-Wege-Sync (siehe CalDAV-Tab)",
                      ]},
                      { title: "Android", icon: "🤖", steps: [
                        "Am einfachsten: Google Calendar nutzen (siehe oben)",
                        "Oder: ICSx5 App installieren (F-Droid / Play Store, kostenlos)",
                        "URL-Abo hinzufügen und Abo-URL einfügen",
                      ]},
                    ].map((instr) => (
                      <div key={instr.title} className="p-3 rounded-lg" style={{ background: "var(--color-muted)" }}>
                        <p className="text-sm font-semibold mb-1.5">{instr.icon} {instr.title}</p>
                        <ol className="space-y-1">
                          {instr.steps.map((step, i) => (
                            <li key={i} className="text-xs flex gap-2" style={{ color: "var(--color-muted-foreground)" }}>
                              <span className="font-bold flex-shrink-0" style={{ color: "var(--color-primary)" }}>{i + 1}.</span>
                              {step}
                            </li>
                          ))}
                        </ol>
                      </div>
                    ))}
                  </div>
                </div>

              </>
            ) : (
              <div className="text-center py-4 text-sm" style={{ color: "var(--color-destructive)" }}>Token konnte nicht erstellt werden.</div>
            )
          )}

          {/* Nextcloud Import (nur Admin) */}
          {isAdmin && (
            <div className="pt-3" style={{ borderTop: "1px solid var(--color-border-light)" }}>
              <h3 className="text-sm font-semibold mb-2">Nextcloud Import</h3>
              <p className="text-xs mb-3" style={{ color: "var(--color-muted-foreground)" }}>
                Alle Termine und Gruppen aus dem verbundenen Nextcloud-Kalender importieren.
              </p>
              <button
                onClick={handleImport}
                disabled={importing}
                className="px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50 transition-colors"
                style={{ background: "var(--color-primary)" }}
              >
                {importing ? "Importiere..." : "Aus Nextcloud importieren"}
              </button>
              {importResult && (
                <p className="text-xs mt-2 p-2 rounded-lg" style={{
                  background: importResult.startsWith("Import erfolgreich") ? "var(--color-success-light)" : "var(--color-destructive-light)",
                  color: importResult.startsWith("Import erfolgreich") ? "var(--color-success)" : "var(--color-destructive)",
                }}>
                  {importResult}
                </p>
              )}
            </div>
          )}
        </div>

        <div className="px-6 py-3 flex justify-end sticky bottom-0" style={{ borderTop: "1px solid var(--color-border-light)", background: "var(--color-surface)" }}>
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm font-medium" style={{ background: "var(--color-muted)" }}>
            Schließen
          </button>
        </div>
      </div>
    </div>
  );
}
