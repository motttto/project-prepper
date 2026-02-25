"use client";

import { useState, useRef, useEffect, useCallback } from "react";
import { IconCalendar, IconChevronLeft, IconChevronRight } from "@/components/ui/icons";

interface DateInputProps {
  value: string; // "YYYY-MM-DD" or ""
  onChange: (value: string) => void;
  required?: boolean;
  placeholder?: string;
  className?: string;
  style?: React.CSSProperties;
}

const MONTH_NAMES = [
  "Januar", "Februar", "März", "April", "Mai", "Juni",
  "Juli", "August", "September", "Oktober", "November", "Dezember",
];

const DAY_HEADERS = ["Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"];

function getDaysInMonth(year: number, month: number): number {
  return new Date(year, month + 1, 0).getDate();
}

// Montag = 0, Sonntag = 6
function getFirstDayOffset(year: number, month: number): number {
  const day = new Date(year, month, 1).getDay();
  return day === 0 ? 6 : day - 1;
}

function formatDateDE(iso: string): string {
  if (!iso) return "";
  const [y, m, d] = iso.split("-");
  return `${d}.${m}.${y}`;
}

function toISO(year: number, month: number, day: number): string {
  return `${year}-${String(month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
}

export function DateInput({
  value,
  onChange,
  required,
  placeholder = "tt.mm.jjjj",
  className = "",
  style,
}: DateInputProps) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const dropdownRef = useRef<HTMLDivElement>(null);

  // ViewMonth/Year initialisiert sich vom aktuellen Wert oder heute
  const parsed = value ? new Date(value) : new Date();
  const initYear = value ? parsed.getFullYear() : new Date().getFullYear();
  const initMonth = value ? parsed.getMonth() : new Date().getMonth();

  const [viewYear, setViewYear] = useState(initYear);
  const [viewMonth, setViewMonth] = useState(initMonth);

  // Wenn value sich ändert, view anpassen
  useEffect(() => {
    if (value) {
      const d = new Date(value);
      setViewYear(d.getFullYear());
      setViewMonth(d.getMonth());
    }
  }, [value]);

  // Click-outside
  useEffect(() => {
    function handleClick(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    if (open) {
      document.addEventListener("mousedown", handleClick);
      return () => document.removeEventListener("mousedown", handleClick);
    }
  }, [open]);

  // Dropdown-Position: prüfe ob genug Platz nach unten
  const [dropUp, setDropUp] = useState(false);
  useEffect(() => {
    if (open && ref.current) {
      const rect = ref.current.getBoundingClientRect();
      const spaceBelow = window.innerHeight - rect.bottom;
      setDropUp(spaceBelow < 340);
    }
  }, [open]);

  const prevMonth = useCallback(() => {
    setViewMonth((m) => {
      if (m === 0) {
        setViewYear((y) => y - 1);
        return 11;
      }
      return m - 1;
    });
  }, []);

  const nextMonth = useCallback(() => {
    setViewMonth((m) => {
      if (m === 11) {
        setViewYear((y) => y + 1);
        return 0;
      }
      return m + 1;
    });
  }, []);

  function handleSelectDay(day: number) {
    const iso = toISO(viewYear, viewMonth, day);
    onChange(iso);
    setOpen(false);
  }

  function handleClear() {
    onChange("");
    setOpen(false);
  }

  const today = new Date();
  const todayISO = toISO(today.getFullYear(), today.getMonth(), today.getDate());

  const daysInMonth = getDaysInMonth(viewYear, viewMonth);
  const firstDayOffset = getFirstDayOffset(viewYear, viewMonth);

  // Kalender-Grid: leere Felder + Tage
  const cells: (number | null)[] = [];
  for (let i = 0; i < firstDayOffset; i++) cells.push(null);
  for (let d = 1; d <= daysInMonth; d++) cells.push(d);

  return (
    <div ref={ref} className={`relative ${className}`} style={style}>
      {/* Input-Feld */}
      <div
        className="flex items-center w-full rounded-lg text-sm cursor-pointer"
        style={{
          border: "1px solid var(--color-border)",
          background: "var(--color-background)",
        }}
        onClick={() => setOpen(!open)}
      >
        <input
          type="text"
          readOnly
          value={formatDateDE(value)}
          placeholder={placeholder}
          required={required}
          className="flex-1 px-3 py-2 bg-transparent outline-none cursor-pointer text-sm"
          style={{ color: value ? "inherit" : "var(--color-text-muted)" }}
          // Hidden native input für Form-Validierung
          tabIndex={-1}
        />
        <div className="px-2" style={{ color: "var(--color-text-muted)" }}>
          <IconCalendar size={16} />
        </div>
      </div>

      {/* Hidden input für native form validation */}
      {required && (
        <input
          type="text"
          value={value}
          required
          tabIndex={-1}
          style={{
            position: "absolute",
            opacity: 0,
            width: 0,
            height: 0,
            pointerEvents: "none",
          }}
          onChange={() => {}}
        />
      )}

      {/* Kalender-Dropdown */}
      {open && (
        <div
          ref={dropdownRef}
          className="absolute left-0 z-50 w-72 rounded-xl shadow-xl overflow-hidden"
          style={{
            background: "var(--color-surface)",
            border: "1px solid var(--color-border)",
            ...(dropUp
              ? { bottom: "100%", marginBottom: 4 }
              : { top: "100%", marginTop: 4 }),
          }}
        >
          {/* Header: Monats-Navigation */}
          <div
            className="flex items-center justify-between px-3 py-2"
            style={{ borderBottom: "1px solid var(--color-border)" }}
          >
            <button
              type="button"
              onClick={prevMonth}
              className="p-1 rounded hover:opacity-70"
              style={{ color: "var(--color-text-muted)" }}
            >
              <IconChevronLeft size={16} />
            </button>
            <span className="text-sm font-semibold">
              {MONTH_NAMES[viewMonth]} {viewYear}
            </span>
            <button
              type="button"
              onClick={nextMonth}
              className="p-1 rounded hover:opacity-70"
              style={{ color: "var(--color-text-muted)" }}
            >
              <IconChevronRight size={16} />
            </button>
          </div>

          {/* Wochentags-Header */}
          <div className="grid grid-cols-7 px-2 pt-2">
            {DAY_HEADERS.map((d) => (
              <div
                key={d}
                className="text-center text-xs font-medium py-1"
                style={{ color: "var(--color-text-muted)" }}
              >
                {d}
              </div>
            ))}
          </div>

          {/* Tage-Grid */}
          <div className="grid grid-cols-7 px-2 pb-2">
            {cells.map((day, i) => {
              if (day === null) {
                return <div key={`empty-${i}`} />;
              }
              const iso = toISO(viewYear, viewMonth, day);
              const isSelected = iso === value;
              const isToday = iso === todayISO;

              return (
                <button
                  key={day}
                  type="button"
                  onClick={() => handleSelectDay(day)}
                  className="w-9 h-9 mx-auto rounded-lg text-sm flex items-center justify-center transition-colors"
                  style={{
                    background: isSelected
                      ? "var(--color-primary)"
                      : "transparent",
                    color: isSelected ? "white" : "inherit",
                    fontWeight: isSelected || isToday ? 600 : 400,
                    outline: isToday && !isSelected
                      ? "2px solid var(--color-primary)"
                      : "none",
                    outlineOffset: "-2px",
                  }}
                  onMouseEnter={(e) => {
                    if (!isSelected) {
                      e.currentTarget.style.background = "var(--color-muted)";
                    }
                  }}
                  onMouseLeave={(e) => {
                    if (!isSelected) {
                      e.currentTarget.style.background = "transparent";
                    }
                  }}
                >
                  {day}
                </button>
              );
            })}
          </div>

          {/* Footer: Heute + Löschen */}
          <div
            className="flex items-center justify-between px-3 py-2 text-xs"
            style={{ borderTop: "1px solid var(--color-border)" }}
          >
            <button
              type="button"
              onClick={() => {
                setViewYear(today.getFullYear());
                setViewMonth(today.getMonth());
                handleSelectDay(today.getDate());
              }}
              className="px-2 py-1 rounded hover:opacity-70 font-medium"
              style={{ color: "var(--color-primary)" }}
            >
              Heute
            </button>
            {!required && value && (
              <button
                type="button"
                onClick={handleClear}
                className="px-2 py-1 rounded hover:opacity-70"
                style={{ color: "var(--color-text-muted)" }}
              >
                Löschen
              </button>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
