"use client";

import { useState, useEffect, useCallback } from "react";
import { IconCalendar, IconPlus, IconTrash, IconX } from "@/components/ui/icons";
import { showToast } from "@/hooks/use-toast";
import { appConfirm } from "@/components/ui/confirm-dialog";

type CalendarInfo = {
  id: string;
  name: string;
  path: string;
  color?: string;
};

type CalendarEvent = {
  uid: string;
  summary: string;
  start: string;
  end: string;
  allDay: boolean;
  location?: string;
  description?: string;
  calendarId: string;
  calendarName: string;
  href: string;
};

type ViewMode = "month" | "week";

const WEEKDAYS = ["Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"];
const MONTH_NAMES = [
  "Januar", "Februar", "März", "April", "Mai", "Juni",
  "Juli", "August", "September", "Oktober", "November", "Dezember",
];

// Fallback-Farben pro Kalender-Index
const CALENDAR_COLORS = [
  "#0066FF", "#22C55E", "#F59E0B", "#EF4444", "#8B5CF6", "#EC4899", "#06B6D4", "#F97316",
];

function getCalColor(cal: CalendarInfo, idx: number): string {
  return cal.color || CALENDAR_COLORS[idx % CALENDAR_COLORS.length];
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
  const [calendars, setCalendars] = useState<CalendarInfo[]>([]);
  const [events, setEvents] = useState<CalendarEvent[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [currentDate, setCurrentDate] = useState(new Date());
  const [viewMode, setViewMode] = useState<ViewMode>("month");
  const [hiddenCalendars, setHiddenCalendars] = useState<Set<string>>(new Set());

  // Modals
  const [selectedEvent, setSelectedEvent] = useState<CalendarEvent | null>(null);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [editEvent, setEditEvent] = useState<CalendarEvent | null>(null);
  const [createForDate, setCreateForDate] = useState<Date | null>(null);

  // Helpers
  const getCalColorForEvent = useCallback((event: CalendarEvent) => {
    const idx = calendars.findIndex((c) => c.id === event.calendarId);
    const cal = calendars[idx];
    return cal ? getCalColor(cal, idx) : "#666";
  }, [calendars]);

  const fetchEvents = useCallback(async () => {
    setLoading(true);
    setError(null);

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

    try {
      const res = await fetch(`/api/calendar?start=${start.toISOString()}&end=${end.toISOString()}`);
      const data = await res.json();
      if (!res.ok) { setError(data.error || "Fehler"); setEvents([]); }
      else {
        setCalendars(data.calendars || []);
        setEvents(data.events || []);
      }
    } catch { setError("Verbindung fehlgeschlagen"); }
    finally { setLoading(false); }
  }, [currentDate, viewMode]);

  useEffect(() => { fetchEvents(); }, [fetchEvents]);

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

  // Toggle Kalender-Sichtbarkeit
  function toggleCalendar(calId: string) {
    setHiddenCalendars((prev) => {
      const next = new Set(prev);
      next.has(calId) ? next.delete(calId) : next.add(calId);
      return next;
    });
  }

  // Gefilterte Events
  function getEventsForDay(date: Date): CalendarEvent[] {
    return events.filter((e) => {
      if (hiddenCalendars.has(e.calendarId)) return false;
      const eStart = new Date(e.start);
      const eEnd = new Date(e.end);
      if (e.allDay) {
        const dayStart = new Date(date); dayStart.setHours(0, 0, 0, 0);
        const dayEnd = new Date(date); dayEnd.setHours(23, 59, 59, 999);
        return eStart <= dayEnd && eEnd > dayStart;
      }
      return isSameDay(eStart, date);
    });
  }

  // Delete
  async function handleDelete(event: CalendarEvent) {
    if (!(await appConfirm(`"${event.summary}" wirklich löschen?`, { variant: "danger", confirmLabel: "Löschen" }))) return;
    try {
      const res = await fetch(`/api/calendar?href=${encodeURIComponent(event.href)}`, { method: "DELETE" });
      if (res.ok) {
        showToast("Termin gelöscht", "success");
        setSelectedEvent(null);
        setEditEvent(null);
        fetchEvents();
      } else {
        showToast("Fehler beim Löschen", "error");
      }
    } catch { showToast("Fehler beim Löschen", "error"); }
  }

  // Save (create/update)
  async function handleSave(data: {
    calendarPath: string;
    uid?: string;
    summary: string;
    start: string;
    end: string;
    allDay: boolean;
    location?: string;
    description?: string;
  }) {
    try {
      const res = await fetch("/api/calendar", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
      });
      if (res.ok) {
        showToast(data.uid ? "Termin aktualisiert" : "Termin erstellt", "success");
        setShowCreateModal(false);
        setEditEvent(null);
        setSelectedEvent(null);
        fetchEvents();
      } else {
        const err = await res.json();
        showToast(err.error || "Fehler", "error");
      }
    } catch { showToast("Fehler beim Speichern", "error"); }
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

  return (
    <div>
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
        <div>
          <h1 className="text-2xl font-bold flex items-center gap-2">
            <IconCalendar size={24} />
            Team-Kalender
          </h1>
          <p className="text-sm mt-1" style={{ color: "var(--color-muted-foreground)" }}>
            Gemeinsamer Kalender des Teams
          </p>
        </div>

        <div className="flex items-center gap-2">
          {/* Neuer Termin */}
          <button
            onClick={() => { setCreateForDate(new Date()); setShowCreateModal(true); }}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-white transition-colors"
            style={{ background: "var(--color-primary)" }}
          >
            <IconPlus size={14} />
            Neuer Termin
          </button>

          {/* View Toggle */}
          <div className="flex rounded-lg overflow-hidden" style={{ border: "1px solid var(--color-border)" }}>
            <button
              onClick={() => setViewMode("month")}
              className="px-3 py-1.5 text-xs font-medium transition-colors"
              style={{
                background: viewMode === "month" ? "var(--color-primary)" : "transparent",
                color: viewMode === "month" ? "white" : "var(--color-muted-foreground)",
              }}
            >Monat</button>
            <button
              onClick={() => setViewMode("week")}
              className="px-3 py-1.5 text-xs font-medium transition-colors"
              style={{
                background: viewMode === "week" ? "var(--color-primary)" : "transparent",
                color: viewMode === "week" ? "white" : "var(--color-muted-foreground)",
              }}
            >Woche</button>
          </div>

          {/* Nav */}
          <button onClick={goToday} className="px-3 py-1.5 rounded-lg text-xs font-medium" style={{ border: "1px solid var(--color-border)" }}>Heute</button>
          <button onClick={goPrev} className="w-8 h-8 rounded-lg flex items-center justify-center" style={{ border: "1px solid var(--color-border)" }}>&lsaquo;</button>
          <span className="text-sm font-semibold min-w-[160px] text-center">
            {viewMode === "month"
              ? `${MONTH_NAMES[currentDate.getMonth()]} ${currentDate.getFullYear()}`
              : (() => { const d = getWeekDays(); return `${d[0].getDate()}. ${MONTH_NAMES[d[0].getMonth()].slice(0, 3)} – ${d[6].getDate()}. ${MONTH_NAMES[d[6].getMonth()].slice(0, 3)} ${d[6].getFullYear()}`; })()}
          </span>
          <button onClick={goNext} className="w-8 h-8 rounded-lg flex items-center justify-center" style={{ border: "1px solid var(--color-border)" }}>&rsaquo;</button>
        </div>
      </div>

      {/* Kalender-Legende / Filter */}
      {calendars.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 mb-4">
          {calendars.map((cal, idx) => {
            const color = getCalColor(cal, idx);
            const isHidden = hiddenCalendars.has(cal.id);
            return (
              <button
                key={cal.id}
                onClick={() => toggleCalendar(cal.id)}
                className="flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium transition-all"
                style={{
                  background: isHidden ? "var(--color-muted)" : `${color}18`,
                  color: isHidden ? "var(--color-muted-foreground)" : color,
                  border: `1.5px solid ${isHidden ? "transparent" : color}`,
                  opacity: isHidden ? 0.5 : 1,
                }}
              >
                <span
                  className="w-2.5 h-2.5 rounded-full flex-shrink-0"
                  style={{ background: isHidden ? "var(--color-muted-foreground)" : color }}
                />
                {cal.name}
              </button>
            );
          })}
        </div>
      )}

      {/* Error */}
      {error && (
        <div className="p-3 rounded-lg text-sm mb-4" style={{
          background: "var(--color-destructive-light)", color: "var(--color-destructive)",
          border: "1px solid var(--color-destructive)",
        }}>{error}</div>
      )}

      {loading && <div className="text-center py-4 text-sm" style={{ color: "var(--color-muted-foreground)" }}>Kalender wird geladen...</div>}

      {/* === MONATSANSICHT === */}
      {viewMode === "month" && (
        <div className="rounded-xl overflow-hidden" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}>
          <div className="grid grid-cols-7" style={{ borderBottom: "1px solid var(--color-border)" }}>
            {WEEKDAYS.map((day) => (
              <div key={day} className="text-center text-xs font-semibold py-2" style={{ color: "var(--color-muted-foreground)" }}>{day}</div>
            ))}
          </div>
          {getMonthDays().map((week, wIdx) => (
            <div key={wIdx} className="grid grid-cols-7" style={{ borderBottom: wIdx < getMonthDays().length - 1 ? "1px solid var(--color-border-light)" : "none", minHeight: 100 }}>
              {week.map((day, dIdx) => {
                const isCurrentMonth = day.getMonth() === currentDate.getMonth();
                const isToday = isSameDay(day, today);
                const dayEvents = getEventsForDay(day);
                return (
                  <div
                    key={dIdx}
                    className="p-1 min-h-[80px] relative group cursor-pointer"
                    style={{ borderRight: dIdx < 6 ? "1px solid var(--color-border-light)" : "none", opacity: isCurrentMonth ? 1 : 0.35 }}
                    onDoubleClick={() => { setCreateForDate(day); setShowCreateModal(true); }}
                  >
                    <div className="flex justify-center mb-0.5">
                      <span className="text-xs font-medium w-6 h-6 flex items-center justify-center rounded-full"
                        style={{ background: isToday ? "var(--color-primary)" : "transparent", color: isToday ? "white" : "var(--color-foreground)" }}>
                        {day.getDate()}
                      </span>
                    </div>
                    {/* Add button on hover */}
                    <button
                      onClick={(e) => { e.stopPropagation(); setCreateForDate(day); setShowCreateModal(true); }}
                      className="absolute top-0.5 right-0.5 w-5 h-5 rounded flex items-center justify-center opacity-0 group-hover:opacity-60 hover:!opacity-100 transition-opacity"
                      style={{ color: "var(--color-primary)" }}
                      title="Termin hinzufügen"
                    >
                      <IconPlus size={12} />
                    </button>
                    <div className="space-y-0.5">
                      {dayEvents.slice(0, 3).map((event) => {
                        const color = getCalColorForEvent(event);
                        return (
                          <button
                            key={event.uid + event.start}
                            onClick={() => setSelectedEvent(event)}
                            className="w-full text-left px-1 py-0.5 rounded text-[10px] leading-tight truncate font-medium transition-opacity hover:opacity-80"
                            style={{ background: `${color}20`, color }}
                            title={event.summary}
                          >
                            {!event.allDay && <span className="font-normal opacity-75">{formatTime(event.start)} </span>}
                            {event.summary}
                          </button>
                        );
                      })}
                      {dayEvents.length > 3 && (
                        <span className="text-[10px] px-1" style={{ color: "var(--color-muted-foreground)" }}>+{dayEvents.length - 3} weitere</span>
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
          getCalColorForEvent={getCalColorForEvent}
          onEventClick={setSelectedEvent}
          onCreateClick={(date) => { setCreateForDate(date); setShowCreateModal(true); }}
        />
      )}

      {/* === EVENT DETAIL MODAL === */}
      {selectedEvent && !editEvent && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: "rgba(0,0,0,0.5)" }} onClick={() => setSelectedEvent(null)}>
          <div className="w-full max-w-md rounded-xl overflow-hidden" style={{ background: "var(--color-surface)", boxShadow: "var(--shadow-lg)" }} onClick={(e) => e.stopPropagation()}>
            <div className="px-6 py-4" style={{ background: `${getCalColorForEvent(selectedEvent)}15`, borderBottom: "1px solid var(--color-border-light)" }}>
              <div className="flex items-start justify-between gap-3">
                <h2 className="text-lg font-bold" style={{ color: getCalColorForEvent(selectedEvent) }}>{selectedEvent.summary}</h2>
                <span className="text-[10px] px-2 py-0.5 rounded-full flex-shrink-0 font-medium mt-1"
                  style={{ background: `${getCalColorForEvent(selectedEvent)}25`, color: getCalColorForEvent(selectedEvent) }}>
                  {selectedEvent.calendarName}
                </span>
              </div>
            </div>
            <div className="px-6 py-4 space-y-3">
              <div className="flex items-start gap-3">
                <IconCalendar size={16} style={{ color: "var(--color-muted-foreground)", marginTop: 2 }} />
                <div>
                  <p className="text-sm font-medium">{new Date(selectedEvent.start).toLocaleDateString("de-DE", { weekday: "long", day: "numeric", month: "long", year: "numeric" })}</p>
                  <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    {selectedEvent.allDay ? "Ganztägig" : `${formatTime(selectedEvent.start)} – ${formatTime(selectedEvent.end)}`}
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
          calendars={calendars}
          event={editEvent || undefined}
          defaultDate={createForDate || new Date()}
          inputStyle={inputStyle}
          onSave={handleSave}
          onDelete={editEvent ? () => handleDelete(editEvent) : undefined}
          onClose={() => { setShowCreateModal(false); setEditEvent(null); setCreateForDate(null); }}
        />
      )}
    </div>
  );
}

// === Week Time Grid (Apple Calendar Style) ===
const HOUR_HEIGHT = 60; // px per hour
const START_HOUR = 6;
const END_HOUR = 23;
const TOTAL_HOURS = END_HOUR - START_HOUR;

function WeekTimeGrid({
  days,
  today,
  getEventsForDay,
  getCalColorForEvent,
  onEventClick,
  onCreateClick,
}: {
  days: Date[];
  today: Date;
  getEventsForDay: (date: Date) => CalendarEvent[];
  getCalColorForEvent: (event: CalendarEvent) => string;
  onEventClick: (event: CalendarEvent) => void;
  onCreateClick: (date: Date) => void;
}) {
  // All-day events per day
  const allDayRows: { day: Date; events: CalendarEvent[] }[] = days.map((day) => ({
    day,
    events: getEventsForDay(day).filter((e) => e.allDay),
  }));
  const hasAllDay = allDayRows.some((r) => r.events.length > 0);
  const maxAllDay = Math.max(1, ...allDayRows.map((r) => r.events.length));

  // Current time indicator
  const now = new Date();
  const nowMinutes = now.getHours() * 60 + now.getMinutes();
  const nowTop = ((nowMinutes - START_HOUR * 60) / (TOTAL_HOURS * 60)) * (TOTAL_HOURS * HOUR_HEIGHT);

  return (
    <div className="rounded-xl overflow-hidden" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}>
      {/* Column Headers */}
      <div className="flex" style={{ borderBottom: "1px solid var(--color-border)" }}>
        {/* Time gutter */}
        <div className="flex-shrink-0 w-[52px]" />
        {/* Day columns */}
        {days.map((day, idx) => {
          const isToday = isSameDay(day, today);
          const dayShort = day.toLocaleDateString("de-DE", { weekday: "short" });
          return (
            <div
              key={idx}
              className="flex-1 text-center py-2 min-w-0"
              style={{
                borderLeft: "1px solid var(--color-border-light)",
                background: isToday ? "var(--color-primary-light)" : "transparent",
              }}
            >
              <p className="text-[10px] uppercase font-semibold" style={{ color: isToday ? "var(--color-primary)" : "var(--color-muted-foreground)" }}>
                {dayShort}
              </p>
              <p
                className="text-lg font-bold leading-tight w-8 h-8 mx-auto flex items-center justify-center rounded-full"
                style={{
                  background: isToday ? "var(--color-primary)" : "transparent",
                  color: isToday ? "white" : "var(--color-foreground)",
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
          <div className="flex-shrink-0 w-[52px] flex items-start justify-end pr-2 pt-1">
            <span className="text-[10px]" style={{ color: "var(--color-muted-foreground)" }}>Ganzt.</span>
          </div>
          {days.map((day, idx) => {
            const dayAllDay = allDayRows[idx].events;
            return (
              <div
                key={idx}
                className="flex-1 min-w-0 p-0.5"
                style={{
                  borderLeft: "1px solid var(--color-border-light)",
                  minHeight: maxAllDay * 22 + 4,
                }}
              >
                {dayAllDay.map((event) => {
                  const color = getCalColorForEvent(event);
                  return (
                    <button
                      key={event.uid}
                      onClick={() => onEventClick(event)}
                      className="w-full text-left text-[10px] font-medium px-1 py-0.5 rounded truncate mb-0.5 transition-opacity hover:opacity-80"
                      style={{ background: `${color}25`, color, lineHeight: "1.3" }}
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
        {/* Hour labels */}
        <div className="flex-shrink-0 w-[52px] relative" style={{ height: TOTAL_HOURS * HOUR_HEIGHT }}>
          {Array.from({ length: TOTAL_HOURS }, (_, i) => (
            <div
              key={i}
              className="absolute right-2 text-[10px] font-medium"
              style={{
                top: i * HOUR_HEIGHT - 6,
                color: "var(--color-muted-foreground)",
              }}
            >
              {String(START_HOUR + i).padStart(2, "0")}:00
            </div>
          ))}
        </div>

        {/* Day columns with events */}
        {days.map((day, dayIdx) => {
          const isToday = isSameDay(day, today);
          const timedEvents = getEventsForDay(day).filter((e) => !e.allDay);

          // Layout overlapping events
          const positioned = layoutEvents(timedEvents);

          return (
            <div
              key={dayIdx}
              className="flex-1 min-w-0 relative"
              style={{
                height: TOTAL_HOURS * HOUR_HEIGHT,
                borderLeft: "1px solid var(--color-border-light)",
                background: isToday ? "rgba(var(--color-primary-rgb, 0,102,255), 0.03)" : "transparent",
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
              {/* Hour grid lines */}
              {Array.from({ length: TOTAL_HOURS }, (_, i) => (
                <div
                  key={i}
                  className="absolute left-0 right-0"
                  style={{
                    top: i * HOUR_HEIGHT,
                    height: 1,
                    background: "var(--color-border-light)",
                  }}
                />
              ))}

              {/* Half-hour lines */}
              {Array.from({ length: TOTAL_HOURS }, (_, i) => (
                <div
                  key={`half-${i}`}
                  className="absolute left-0 right-0"
                  style={{
                    top: i * HOUR_HEIGHT + HOUR_HEIGHT / 2,
                    height: 1,
                    background: "var(--color-border-light)",
                    opacity: 0.4,
                  }}
                />
              ))}

              {/* Current time line */}
              {isToday && nowMinutes >= START_HOUR * 60 && nowMinutes <= END_HOUR * 60 && (
                <div className="absolute left-0 right-0 z-10 pointer-events-none" style={{ top: nowTop }}>
                  <div className="relative">
                    <div className="absolute -left-[5px] -top-[5px] w-[10px] h-[10px] rounded-full" style={{ background: "var(--color-destructive)" }} />
                    <div className="h-[2px]" style={{ background: "var(--color-destructive)" }} />
                  </div>
                </div>
              )}

              {/* Events */}
              {positioned.map(({ event, top, height, left, width }) => {
                const color = getCalColorForEvent(event);
                return (
                  <button
                    key={event.uid}
                    onClick={(e) => { e.stopPropagation(); onEventClick(event); }}
                    className="absolute rounded px-1.5 py-0.5 overflow-hidden text-left transition-opacity hover:opacity-90 z-[5]"
                    style={{
                      top,
                      height: Math.max(height, 18),
                      left: `${left}%`,
                      width: `${width}%`,
                      background: `${color}20`,
                      borderLeft: `3px solid ${color}`,
                      color,
                    }}
                    title={`${event.summary}\n${formatTime(event.start)} – ${formatTime(event.end)}`}
                  >
                    <p className="text-[10px] font-semibold truncate leading-tight">{event.summary}</p>
                    {height > 28 && (
                      <p className="text-[9px] opacity-70 truncate">{formatTime(event.start)} – {formatTime(event.end)}</p>
                    )}
                    {height > 44 && event.location && (
                      <p className="text-[9px] opacity-60 truncate">📍 {event.location}</p>
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

  // Convert to { event, startMin, endMin }
  const items = events.map((event) => {
    const s = new Date(event.start);
    const e = new Date(event.end);
    const startMin = s.getHours() * 60 + s.getMinutes();
    const endMin = e.getHours() * 60 + e.getMinutes();
    return { event, startMin, endMin: Math.max(endMin, startMin + 15) };
  }).sort((a, b) => a.startMin - b.startMin || a.endMin - b.endMin);

  // Assign columns
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

  // Build column index map
  const colMap = new Map<string, number>();
  columns.forEach((col, colIdx) => {
    col.forEach((item) => {
      colMap.set(item.event.uid, colIdx);
    });
  });

  const totalCols = columns.length;
  const padding = 2; // % padding between

  return items.map((item) => {
    const colIdx = colMap.get(item.event.uid) || 0;
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
  calendars,
  event,
  defaultDate,
  inputStyle,
  onSave,
  onDelete,
  onClose,
}: {
  calendars: CalendarInfo[];
  event?: CalendarEvent;
  defaultDate: Date;
  inputStyle: React.CSSProperties;
  onSave: (data: any) => Promise<void>;
  onDelete?: () => void;
  onClose: () => void;
}) {
  const isEdit = !!event;

  const [calendarId, setCalendarId] = useState(event?.calendarId || calendars[0]?.id || "");
  const [summary, setSummary] = useState(event?.summary || "");
  const [allDay, setAllDay] = useState(event?.allDay ?? true);
  const [startDate, setStartDate] = useState(() => {
    if (event) return event.allDay ? event.start : toDateTimeInputValue(new Date(event.start));
    return toDateInputValue(defaultDate);
  });
  const [endDate, setEndDate] = useState(() => {
    if (event) return event.allDay ? event.end : toDateTimeInputValue(new Date(event.end));
    return toDateInputValue(defaultDate);
  });
  const [location, setLocation] = useState(event?.location || "");
  const [description, setDescription] = useState(event?.description || "");
  const [saving, setSaving] = useState(false);

  // Update date format when allDay changes
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
    if (!summary.trim() || !calendarId) return;
    setSaving(true);

    const cal = calendars.find((c) => c.id === calendarId);
    if (!cal) return;

    let formattedStart = startDate;
    let formattedEnd = endDate;

    if (!allDay) {
      // Convert to full ISO
      formattedStart = new Date(startDate).toISOString();
      formattedEnd = new Date(endDate).toISOString();
    }

    await onSave({
      calendarPath: cal.path,
      uid: event?.uid,
      summary: summary.trim(),
      start: formattedStart,
      end: formattedEnd,
      allDay,
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
          {/* Titel */}
          <div>
            <label className="block text-sm font-medium mb-1">Titel *</label>
            <input type="text" value={summary} onChange={(e) => setSummary(e.target.value)}
              className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle}
              placeholder="Termin-Titel" required autoFocus />
          </div>

          {/* Kalender */}
          <div>
            <label className="block text-sm font-medium mb-1">Kalender</label>
            <select value={calendarId} onChange={(e) => setCalendarId(e.target.value)}
              className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle}>
              {calendars.map((cal, idx) => (
                <option key={cal.id} value={cal.id}>
                  {cal.name}
                </option>
              ))}
            </select>
          </div>

          {/* Ganztägig Toggle */}
          <label className="flex items-center gap-2 text-sm cursor-pointer select-none">
            <input type="checkbox" checked={allDay} onChange={(e) => setAllDay(e.target.checked)}
              style={{ accentColor: "var(--color-primary)" }} />
            Ganztägig
          </label>

          {/* Datum/Zeit */}
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>Von</label>
              <input
                type={allDay ? "date" : "datetime-local"}
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
                required
              />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>Bis</label>
              <input
                type={allDay ? "date" : "datetime-local"}
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
              />
            </div>
          </div>

          {/* Ort */}
          <div>
            <label className="block text-sm font-medium mb-1">Ort</label>
            <input type="text" value={location} onChange={(e) => setLocation(e.target.value)}
              className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle}
              placeholder="Veranstaltungsort" />
          </div>

          {/* Beschreibung */}
          <div>
            <label className="block text-sm font-medium mb-1">Beschreibung</label>
            <textarea value={description} onChange={(e) => setDescription(e.target.value)}
              className="w-full px-3 py-2 rounded-lg text-sm resize-none" style={inputStyle}
              rows={3} placeholder="Notizen zum Termin..." />
          </div>

          {/* Buttons */}
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
