"use client";

import { useState, useEffect, useCallback } from "react";
import { IconCalendar } from "@/components/ui/icons";

type CalendarEvent = {
  uid: string;
  summary: string;
  start: string;
  end: string;
  allDay: boolean;
  location?: string;
  description?: string;
};

type ViewMode = "month" | "week";

const WEEKDAYS = ["Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"];
const MONTH_NAMES = [
  "Januar", "Februar", "März", "April", "Mai", "Juni",
  "Juli", "August", "September", "Oktober", "November", "Dezember",
];

// Farben für Events (rotieren)
const EVENT_COLORS = [
  { bg: "var(--color-primary-light)", color: "var(--color-primary)" },
  { bg: "var(--color-success-light)", color: "var(--color-success)" },
  { bg: "var(--color-warning-light)", color: "var(--color-warning)" },
  { bg: "var(--color-info-light)", color: "var(--color-info)" },
  { bg: "#fce4ec", color: "#c62828" },
  { bg: "#e8eaf6", color: "#283593" },
];

function getEventColor(uid: string) {
  let hash = 0;
  for (let i = 0; i < uid.length; i++) {
    hash = ((hash << 5) - hash + uid.charCodeAt(i)) | 0;
  }
  return EVENT_COLORS[Math.abs(hash) % EVENT_COLORS.length];
}

function isSameDay(d1: Date, d2: Date): boolean {
  return d1.getFullYear() === d2.getFullYear() &&
    d1.getMonth() === d2.getMonth() &&
    d1.getDate() === d2.getDate();
}

function formatTime(dateStr: string): string {
  const d = new Date(dateStr);
  return d.toLocaleTimeString("de-DE", { hour: "2-digit", minute: "2-digit" });
}

// Montag-basierte Woche
function getMonday(d: Date): Date {
  const result = new Date(d);
  const day = result.getDay();
  const diff = result.getDate() - day + (day === 0 ? -6 : 1);
  result.setDate(diff);
  result.setHours(0, 0, 0, 0);
  return result;
}

export default function CalendarPage() {
  const [events, setEvents] = useState<CalendarEvent[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [currentDate, setCurrentDate] = useState(new Date());
  const [viewMode, setViewMode] = useState<ViewMode>("month");
  const [selectedEvent, setSelectedEvent] = useState<CalendarEvent | null>(null);

  const fetchEvents = useCallback(async () => {
    setLoading(true);
    setError(null);

    let start: Date;
    let end: Date;

    if (viewMode === "month") {
      // Monat +/- 1 Woche Buffer
      start = new Date(currentDate.getFullYear(), currentDate.getMonth(), -7);
      end = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 14);
    } else {
      // Wochenansicht
      const monday = getMonday(currentDate);
      start = new Date(monday);
      start.setDate(start.getDate() - 1);
      end = new Date(monday);
      end.setDate(end.getDate() + 8);
    }

    try {
      const res = await fetch(
        `/api/calendar?start=${start.toISOString()}&end=${end.toISOString()}`
      );
      const data = await res.json();

      if (!res.ok) {
        setError(data.error || "Fehler beim Laden");
        setEvents([]);
      } else {
        setEvents(data.events || []);
      }
    } catch {
      setError("Verbindung zum Kalender fehlgeschlagen");
    } finally {
      setLoading(false);
    }
  }, [currentDate, viewMode]);

  useEffect(() => {
    fetchEvents();
  }, [fetchEvents]);

  // Navigation
  function goNext() {
    if (viewMode === "month") {
      setCurrentDate(new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 1));
    } else {
      const next = new Date(currentDate);
      next.setDate(next.getDate() + 7);
      setCurrentDate(next);
    }
  }

  function goPrev() {
    if (viewMode === "month") {
      setCurrentDate(new Date(currentDate.getFullYear(), currentDate.getMonth() - 1, 1));
    } else {
      const prev = new Date(currentDate);
      prev.setDate(prev.getDate() - 7);
      setCurrentDate(prev);
    }
  }

  function goToday() {
    setCurrentDate(new Date());
  }

  // Events für einen Tag filtern
  function getEventsForDay(date: Date): CalendarEvent[] {
    return events.filter((e) => {
      const eStart = new Date(e.start);
      const eEnd = new Date(e.end);

      if (e.allDay) {
        // All-day: Start <= date < End
        const dayStart = new Date(date);
        dayStart.setHours(0, 0, 0, 0);
        const dayEnd = new Date(date);
        dayEnd.setHours(23, 59, 59, 999);
        return eStart <= dayEnd && eEnd > dayStart;
      }

      return isSameDay(eStart, date);
    });
  }

  // Monatskalender-Grid generieren
  function getMonthDays(): Date[][] {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);

    // Start-Wochentag (0=So → Mo=1 usw.)
    let startOffset = firstDay.getDay() - 1;
    if (startOffset < 0) startOffset = 6; // Sonntag → 6

    const weeks: Date[][] = [];
    let currentWeek: Date[] = [];

    // Tage vom Vormonat
    for (let i = startOffset - 1; i >= 0; i--) {
      const d = new Date(year, month, -i);
      currentWeek.push(d);
    }

    // Tage des Monats + Nachmonat
    for (let day = 1; day <= lastDay.getDate(); day++) {
      currentWeek.push(new Date(year, month, day));
      if (currentWeek.length === 7) {
        weeks.push(currentWeek);
        currentWeek = [];
      }
    }

    // Restliche Woche auffüllen
    if (currentWeek.length > 0) {
      let nextDay = 1;
      while (currentWeek.length < 7) {
        currentWeek.push(new Date(year, month + 1, nextDay++));
      }
      weeks.push(currentWeek);
    }

    return weeks;
  }

  // Wochenansicht-Tage
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

  return (
    <div>
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
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
          {/* View Toggle */}
          <div
            className="flex rounded-lg overflow-hidden"
            style={{ border: "1px solid var(--color-border)" }}
          >
            <button
              onClick={() => setViewMode("month")}
              className="px-3 py-1.5 text-xs font-medium transition-colors"
              style={{
                background: viewMode === "month" ? "var(--color-primary)" : "transparent",
                color: viewMode === "month" ? "white" : "var(--color-muted-foreground)",
              }}
            >
              Monat
            </button>
            <button
              onClick={() => setViewMode("week")}
              className="px-3 py-1.5 text-xs font-medium transition-colors"
              style={{
                background: viewMode === "week" ? "var(--color-primary)" : "transparent",
                color: viewMode === "week" ? "white" : "var(--color-muted-foreground)",
              }}
            >
              Woche
            </button>
          </div>

          {/* Navigation */}
          <button
            onClick={goToday}
            className="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
            style={{ border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}
          >
            Heute
          </button>
          <button
            onClick={goPrev}
            className="w-8 h-8 rounded-lg flex items-center justify-center transition-colors"
            style={{ border: "1px solid var(--color-border)" }}
          >
            &lsaquo;
          </button>
          <span className="text-sm font-semibold min-w-[140px] text-center">
            {viewMode === "month"
              ? `${MONTH_NAMES[currentDate.getMonth()]} ${currentDate.getFullYear()}`
              : (() => {
                  const days = getWeekDays();
                  const first = days[0];
                  const last = days[6];
                  return `${first.getDate()}. ${MONTH_NAMES[first.getMonth()].slice(0, 3)} – ${last.getDate()}. ${MONTH_NAMES[last.getMonth()].slice(0, 3)} ${last.getFullYear()}`;
                })()
            }
          </span>
          <button
            onClick={goNext}
            className="w-8 h-8 rounded-lg flex items-center justify-center transition-colors"
            style={{ border: "1px solid var(--color-border)" }}
          >
            &rsaquo;
          </button>
        </div>
      </div>

      {/* Error */}
      {error && (
        <div
          className="p-3 rounded-lg text-sm mb-4"
          style={{
            background: "var(--color-destructive-light)",
            color: "var(--color-destructive)",
            border: "1px solid var(--color-destructive)",
          }}
        >
          {error}
        </div>
      )}

      {/* Loading Overlay */}
      {loading && (
        <div className="text-center py-4 text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          Kalender wird geladen...
        </div>
      )}

      {/* === MONATSANSICHT === */}
      {viewMode === "month" && (
        <div
          className="rounded-xl overflow-hidden"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
        >
          {/* Wochentag-Header */}
          <div className="grid grid-cols-7" style={{ borderBottom: "1px solid var(--color-border)" }}>
            {WEEKDAYS.map((day) => (
              <div
                key={day}
                className="text-center text-xs font-semibold py-2"
                style={{ color: "var(--color-muted-foreground)" }}
              >
                {day}
              </div>
            ))}
          </div>

          {/* Tage-Grid */}
          {getMonthDays().map((week, wIdx) => (
            <div
              key={wIdx}
              className="grid grid-cols-7"
              style={{
                borderBottom: wIdx < getMonthDays().length - 1 ? "1px solid var(--color-border-light)" : "none",
                minHeight: 100,
              }}
            >
              {week.map((day, dIdx) => {
                const isCurrentMonth = day.getMonth() === currentDate.getMonth();
                const isToday = isSameDay(day, today);
                const dayEvents = getEventsForDay(day);

                return (
                  <div
                    key={dIdx}
                    className="p-1 min-h-[80px] relative"
                    style={{
                      borderRight: dIdx < 6 ? "1px solid var(--color-border-light)" : "none",
                      opacity: isCurrentMonth ? 1 : 0.35,
                    }}
                  >
                    {/* Datum */}
                    <div className="flex justify-center mb-0.5">
                      <span
                        className={`text-xs font-medium w-6 h-6 flex items-center justify-center rounded-full ${
                          isToday ? "text-white" : ""
                        }`}
                        style={{
                          background: isToday ? "var(--color-primary)" : "transparent",
                          color: isToday ? "white" : "var(--color-foreground)",
                        }}
                      >
                        {day.getDate()}
                      </span>
                    </div>

                    {/* Events */}
                    <div className="space-y-0.5">
                      {dayEvents.slice(0, 3).map((event) => {
                        const c = getEventColor(event.uid);
                        return (
                          <button
                            key={event.uid}
                            onClick={() => setSelectedEvent(event)}
                            className="w-full text-left px-1 py-0.5 rounded text-[10px] leading-tight truncate font-medium transition-opacity hover:opacity-80"
                            style={{ background: c.bg, color: c.color }}
                            title={event.summary}
                          >
                            {!event.allDay && (
                              <span className="font-normal opacity-75">
                                {formatTime(event.start)}{" "}
                              </span>
                            )}
                            {event.summary}
                          </button>
                        );
                      })}
                      {dayEvents.length > 3 && (
                        <span
                          className="text-[10px] px-1"
                          style={{ color: "var(--color-muted-foreground)" }}
                        >
                          +{dayEvents.length - 3} weitere
                        </span>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          ))}
        </div>
      )}

      {/* === WOCHENANSICHT === */}
      {viewMode === "week" && (
        <div
          className="rounded-xl overflow-hidden"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
        >
          {getWeekDays().map((day, idx) => {
            const isToday = isSameDay(day, today);
            const dayEvents = getEventsForDay(day);
            const dayName = day.toLocaleDateString("de-DE", { weekday: "long" });

            return (
              <div
                key={idx}
                style={{
                  borderBottom: idx < 6 ? "1px solid var(--color-border-light)" : "none",
                  background: isToday ? "var(--color-primary-light)" : "transparent",
                }}
              >
                {/* Tag-Header */}
                <div className="flex items-center gap-3 px-4 py-2.5">
                  <div
                    className={`w-9 h-9 rounded-full flex items-center justify-center font-bold text-sm ${
                      isToday ? "text-white" : ""
                    }`}
                    style={{
                      background: isToday ? "var(--color-primary)" : "var(--color-muted)",
                      color: isToday ? "white" : "var(--color-foreground)",
                    }}
                  >
                    {day.getDate()}
                  </div>
                  <div>
                    <p className="text-sm font-medium">{dayName}</p>
                    <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                      {day.toLocaleDateString("de-DE", { day: "numeric", month: "long" })}
                    </p>
                  </div>
                  {dayEvents.length === 0 && (
                    <span className="text-xs ml-auto" style={{ color: "var(--color-muted-foreground)" }}>
                      Keine Termine
                    </span>
                  )}
                </div>

                {/* Events */}
                {dayEvents.length > 0 && (
                  <div className="px-4 pb-3 space-y-1.5 ml-12">
                    {dayEvents.map((event) => {
                      const c = getEventColor(event.uid);
                      return (
                        <button
                          key={event.uid}
                          onClick={() => setSelectedEvent(event)}
                          className="w-full text-left flex items-center gap-3 px-3 py-2 rounded-lg transition-opacity hover:opacity-80"
                          style={{ background: c.bg }}
                        >
                          <div
                            className="w-1 h-8 rounded-full flex-shrink-0"
                            style={{ background: c.color }}
                          />
                          <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium truncate" style={{ color: c.color }}>
                              {event.summary}
                            </p>
                            <p className="text-xs" style={{ color: c.color, opacity: 0.7 }}>
                              {event.allDay
                                ? "Ganztägig"
                                : `${formatTime(event.start)} – ${formatTime(event.end)}`}
                              {event.location && ` · ${event.location}`}
                            </p>
                          </div>
                        </button>
                      );
                    })}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      {/* === EVENT-DETAIL MODAL === */}
      {selectedEvent && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center p-4"
          style={{ background: "rgba(0,0,0,0.5)" }}
          onClick={() => setSelectedEvent(null)}
        >
          <div
            className="w-full max-w-md rounded-xl overflow-hidden"
            style={{ background: "var(--color-surface)", boxShadow: "var(--shadow-lg)" }}
            onClick={(e) => e.stopPropagation()}
          >
            {/* Header */}
            <div
              className="px-6 py-4"
              style={{
                background: getEventColor(selectedEvent.uid).bg,
                borderBottom: "1px solid var(--color-border-light)",
              }}
            >
              <h2 className="text-lg font-bold" style={{ color: getEventColor(selectedEvent.uid).color }}>
                {selectedEvent.summary}
              </h2>
            </div>

            {/* Body */}
            <div className="px-6 py-4 space-y-3">
              {/* Datum/Zeit */}
              <div className="flex items-start gap-3">
                <IconCalendar size={16} style={{ color: "var(--color-muted-foreground)", marginTop: 2 }} />
                <div>
                  <p className="text-sm font-medium">
                    {new Date(selectedEvent.start).toLocaleDateString("de-DE", {
                      weekday: "long",
                      day: "numeric",
                      month: "long",
                      year: "numeric",
                    })}
                  </p>
                  <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    {selectedEvent.allDay
                      ? "Ganztägig"
                      : `${formatTime(selectedEvent.start)} – ${formatTime(selectedEvent.end)}`}
                  </p>
                </div>
              </div>

              {/* Ort */}
              {selectedEvent.location && (
                <div className="flex items-start gap-3">
                  <span className="text-sm mt-0.5">📍</span>
                  <p className="text-sm">{selectedEvent.location}</p>
                </div>
              )}

              {/* Beschreibung */}
              {selectedEvent.description && (
                <div className="flex items-start gap-3">
                  <span className="text-sm mt-0.5">📝</span>
                  <p
                    className="text-sm whitespace-pre-wrap"
                    style={{ color: "var(--color-muted-foreground)" }}
                  >
                    {selectedEvent.description}
                  </p>
                </div>
              )}
            </div>

            {/* Footer */}
            <div
              className="px-6 py-3 flex justify-end"
              style={{ borderTop: "1px solid var(--color-border-light)" }}
            >
              <button
                onClick={() => setSelectedEvent(null)}
                className="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                style={{ background: "var(--color-muted)", color: "var(--color-foreground)" }}
              >
                Schließen
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
