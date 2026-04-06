/**
 * Shared iCalendar helpers — used by CalDAV server + iCal feed
 */

export type ParsedVEvent = {
  uid: string;
  summary: string;
  description: string | null;
  location: string | null;
  allDay: boolean;
  startAt: string; // ISO string
  endAt: string | null; // ISO string
};

/**
 * Parst einen VCALENDAR-String und extrahiert das erste VEVENT
 */
export function parseVEvent(icalString: string): ParsedVEvent | null {
  // Handle folded lines (RFC 5545)
  const ical = icalString.replace(/\r?\n[ \t]/g, "");

  const veventMatch = ical.match(/BEGIN:VEVENT([\s\S]*?)END:VEVENT/);
  if (!veventMatch) return null;

  const vevent = veventMatch[1];
  const uid = extractProp(vevent, "UID") || "";
  const summary = extractProp(vevent, "SUMMARY") || "(Kein Titel)";
  const description = extractProp(vevent, "DESCRIPTION");
  const location = extractProp(vevent, "LOCATION");

  const dtstart = extractDateTime(vevent, "DTSTART");
  const dtend = extractDateTime(vevent, "DTEND");
  if (!dtstart) return null;

  const allDay = !dtstart.value.includes("T");
  const startISO = parseICalDateToISO(dtstart.value);
  const endISO = dtend ? parseICalDateToISO(dtend.value) : null;

  let startAt: string;
  let endAt: string | null;

  if (allDay) {
    startAt = startISO + "T00:00:00Z";
    endAt = endISO ? endISO + "T00:00:00Z" : startAt;
  } else {
    startAt = toUTCString(startISO, dtstart.tzid);
    endAt = endISO ? toUTCString(endISO, dtend?.tzid) : null;
  }

  return { uid, summary, description, location, allDay, startAt, endAt };
}

/**
 * Generiert einen VCALENDAR/VEVENT-String aus Event-Daten
 */
export function generateVCalendar(event: {
  caldav_uid: string;
  summary: string;
  description?: string | null;
  location?: string | null;
  all_day: boolean;
  start_at: string;
  end_at?: string | null;
  created_at: string;
  updated_at?: string;
}): string {
  const created = formatICalDate(event.created_at);
  const modified = formatICalDate(event.updated_at || event.created_at);

  const lines: string[] = [
    "BEGIN:VCALENDAR",
    "VERSION:2.0",
    "PRODID:-//Project Prepper//CalDAV//DE",
    "BEGIN:VEVENT",
    `UID:${event.caldav_uid}`,
    `DTSTAMP:${created}`,
  ];

  if (event.all_day) {
    lines.push(`DTSTART;VALUE=DATE:${formatICalDateOnly(event.start_at)}`);
    // iCal DTEND bei VALUE=DATE ist exklusiv → nächster Tag
    const endStr = event.end_at || event.start_at;
    const endDate = new Date(endStr);
    endDate.setUTCDate(endDate.getUTCDate() + 1);
    lines.push(`DTEND;VALUE=DATE:${formatICalDateOnlyUTC(endDate)}`);
  } else {
    lines.push(`DTSTART:${formatICalDate(event.start_at)}`);
    if (event.end_at) {
      lines.push(`DTEND:${formatICalDate(event.end_at)}`);
    }
  }

  lines.push(`SUMMARY:${escapeICal(event.summary)}`);
  if (event.location) lines.push(`LOCATION:${escapeICal(event.location)}`);
  if (event.description) lines.push(`DESCRIPTION:${escapeICal(event.description)}`);
  lines.push(`LAST-MODIFIED:${modified}`);
  lines.push("END:VEVENT");
  lines.push("END:VCALENDAR");

  return lines.join("\r\n");
}

// ===== Low-level helpers =====

export function escapeICal(text: string): string {
  return text
    .replace(/\\/g, "\\\\")
    .replace(/;/g, "\\;")
    .replace(/,/g, "\\,")
    .replace(/\n/g, "\\n");
}

export function formatICalDate(isoDate: string): string {
  return new Date(isoDate).toISOString().replace(/[-:]/g, "").replace(/\.\d{3}/, "");
}

export function formatICalDateOnly(isoDate: string): string {
  const d = new Date(isoDate);
  return formatICalDateOnlyUTC(d);
}

function formatICalDateOnlyUTC(d: Date): string {
  const y = d.getUTCFullYear();
  const m = String(d.getUTCMonth() + 1).padStart(2, "0");
  const day = String(d.getUTCDate()).padStart(2, "0");
  return `${y}${m}${day}`;
}

function extractProp(vevent: string, prop: string): string | null {
  const regex = new RegExp(`^${prop}[;:]([^\\r\\n]*)`, "mi");
  const match = vevent.match(regex);
  if (!match) return null;

  let value = match[1];
  const fullMatch = match[0];

  if (fullMatch.includes(";") && fullMatch.includes(":")) {
    const colonIdx = fullMatch.indexOf(":", prop.length);
    if (colonIdx > -1) value = fullMatch.substring(colonIdx + 1);
  }

  value = value.replace(/\\n/g, "\n").replace(/\\,/g, ",").replace(/\\\\/g, "\\");
  return value.trim() || null;
}

function extractDateTime(vevent: string, prop: string): { value: string; tzid?: string } | null {
  const regex = new RegExp(`${prop}([^:]*):([^\\r\\n]+)`, "mi");
  const match = vevent.match(regex);
  if (!match) return null;

  const params = match[1]; // e.g. ";TZID=Europe/Berlin;VALUE=DATE"
  const value = match[2].trim();

  const tzidMatch = params.match(/TZID=([^;:]+)/i);
  const tzid = tzidMatch ? tzidMatch[1] : undefined;

  return { value, tzid };
}

/**
 * Konvertiert ein iCal-Datum mit optionalem TZID nach UTC ISO-String.
 * Ohne TZID und ohne Z → wird als UTC behandelt (Fallback).
 */
function toUTCString(isoStr: string, tzid?: string): string {
  if (isoStr.endsWith("Z")) return isoStr;
  if (!tzid) return isoStr + "Z";

  try {
    // Trick: isoStr als UTC interpretieren, dann Offset zur Timezone berechnen
    const fakeUtc = new Date(isoStr + "Z");
    const parts = new Intl.DateTimeFormat("en-US", {
      timeZone: tzid,
      year: "numeric", month: "2-digit", day: "2-digit",
      hour: "2-digit", minute: "2-digit", second: "2-digit",
      hour12: false,
    }).formatToParts(fakeUtc);

    const get = (type: string) => parts.find(p => p.type === type)?.value || "0";
    const h = get("hour") === "24" ? "00" : get("hour"); // midnight edge case
    const tzLocal = new Date(`${get("year")}-${get("month")}-${get("day")}T${h}:${get("minute")}:${get("second")}Z`);

    const offsetMs = tzLocal.getTime() - fakeUtc.getTime();
    const actualUtc = new Date(fakeUtc.getTime() - offsetMs);
    return actualUtc.toISOString();
  } catch {
    // Unbekannte Timezone → als UTC behandeln
    return isoStr + "Z";
  }
}

function parseICalDateToISO(dateStr: string): string {
  if (dateStr.length === 8) {
    return `${dateStr.slice(0, 4)}-${dateStr.slice(4, 6)}-${dateStr.slice(6, 8)}`;
  }
  if (dateStr.includes("T")) {
    const base = `${dateStr.slice(0, 4)}-${dateStr.slice(4, 6)}-${dateStr.slice(6, 8)}`;
    const tp = dateStr.slice(9);
    const time = `${tp.slice(0, 2)}:${tp.slice(2, 4)}:${tp.slice(4, 6)}`;
    const tz = tp.endsWith("Z") ? "Z" : "";
    return `${base}T${time}${tz}`;
  }
  return dateStr;
}
