import { NextResponse, type NextRequest } from "next/server";
import { createServerSupabaseClient } from "@/lib/supabase-server";

// CalDAV REPORT request — fetches events in a date range
export async function GET(request: NextRequest) {
  // Auth check — nur eingeloggte User
  const supabase = await createServerSupabaseClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const { searchParams } = new URL(request.url);
  const start = searchParams.get("start"); // ISO date
  const end = searchParams.get("end"); // ISO date

  if (!start || !end) {
    return NextResponse.json({ error: "start and end params required" }, { status: 400 });
  }

  const caldavUrl = process.env.CALDAV_URL;
  const caldavUser = process.env.CALDAV_USER;
  const caldavPassword = process.env.CALDAV_PASSWORD;

  if (!caldavUrl || !caldavUser || !caldavPassword) {
    return NextResponse.json({ error: "CalDAV not configured" }, { status: 500 });
  }

  try {
    // 1. Kalender-URL ermitteln via PROPFIND
    const calendarsUrl = `${caldavUrl}/calendars/${caldavUser}/`;
    const authHeader = "Basic " + Buffer.from(`${caldavUser}:${caldavPassword}`).toString("base64");

    // Alle Kalender des Users finden
    const propfindBody = `<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:displayname />
    <d:resourcetype />
    <cs:getctag />
  </d:prop>
</d:propfind>`;

    const propRes = await fetch(calendarsUrl, {
      method: "PROPFIND",
      headers: {
        Authorization: authHeader,
        "Content-Type": "application/xml; charset=utf-8",
        Depth: "1",
      },
      body: propfindBody,
    });

    if (!propRes.ok) {
      return NextResponse.json(
        { error: `CalDAV PROPFIND failed: ${propRes.status}` },
        { status: 502 }
      );
    }

    const propXml = await propRes.text();

    // Kalender-Pfade extrahieren (Nextcloud-Kalender haben <cal:calendar> resourcetype)
    const calendarPaths = extractCalendarPaths(propXml, calendarsUrl);

    if (calendarPaths.length === 0) {
      return NextResponse.json({ events: [] });
    }

    // 2. Events aus allen Kalendern laden via REPORT
    const allEvents: CalendarEvent[] = [];

    for (const calPath of calendarPaths) {
      const calUrl = calPath.startsWith("http") ? calPath : `${caldavUrl.replace(/\/remote\.php\/dav$/, "")}${calPath}`;

      const reportBody = `<?xml version="1.0" encoding="utf-8" ?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag />
    <c:calendar-data />
  </d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VEVENT">
        <c:time-range start="${formatCalDavDate(start)}" end="${formatCalDavDate(end)}" />
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>`;

      const reportRes = await fetch(calUrl, {
        method: "REPORT",
        headers: {
          Authorization: authHeader,
          "Content-Type": "application/xml; charset=utf-8",
          Depth: "1",
        },
        body: reportBody,
      });

      if (reportRes.ok) {
        const reportXml = await reportRes.text();
        const events = parseICalEvents(reportXml);
        allEvents.push(...events);
      }
    }

    // Nach Startdatum sortieren
    allEvents.sort((a, b) => new Date(a.start).getTime() - new Date(b.start).getTime());

    return NextResponse.json({ events: allEvents });
  } catch (err) {
    console.error("[CalDAV] Error:", err);
    return NextResponse.json(
      { error: "CalDAV request failed" },
      { status: 502 }
    );
  }
}

// === Helpers ===

type CalendarEvent = {
  uid: string;
  summary: string;
  start: string;
  end: string;
  allDay: boolean;
  location?: string;
  description?: string;
};

function formatCalDavDate(isoDate: string): string {
  // CalDAV will UTC format: 20240101T000000Z
  return new Date(isoDate).toISOString().replace(/[-:]/g, "").replace(/\.\d{3}/, "");
}

function extractCalendarPaths(xml: string, baseUrl: string): string[] {
  const paths: string[] = [];
  // Suche nach <d:href> in Responses die einen Kalender-Resourcetype haben
  const responseBlocks = xml.split(/<d:response>/i).slice(1);

  for (const block of responseBlocks) {
    // Prüfe ob es ein Kalender ist (hat <cal:calendar/> oder <c:calendar/> im resourcetype)
    const isCalendar =
      block.includes("<cal:calendar") ||
      block.includes("<c:calendar") ||
      block.includes("calendar</");

    // Überspringe die Root-Collection
    const hrefMatch = block.match(/<d:href>([^<]+)<\/d:href>/i);
    if (!hrefMatch) continue;

    const href = hrefMatch[1];

    // Root-URL überspringen (endet mit /calendars/user/)
    if (href === new URL(baseUrl).pathname || href === new URL(baseUrl).pathname + "/") continue;

    // Trash/Outbox/Inbox überspringen
    if (href.includes("outbox") || href.includes("inbox") || href.includes("trashbin")) continue;

    if (isCalendar) {
      paths.push(href);
    }
  }

  return paths;
}

function parseICalEvents(xml: string): CalendarEvent[] {
  const events: CalendarEvent[] = [];

  // calendar-data Blöcke extrahieren
  const dataBlocks = xml.match(/<c:calendar-data[^>]*>([\s\S]*?)<\/c:calendar-data>/gi) ||
                     xml.match(/<cal:calendar-data[^>]*>([\s\S]*?)<\/cal:calendar-data>/gi) || [];

  for (const block of dataBlocks) {
    // iCal-Daten extrahieren (zwischen den Tags)
    const icalMatch = block.match(/>([^]*)</);
    if (!icalMatch) continue;

    let ical = icalMatch[1].trim();
    // XML-Entities decodieren
    ical = ical.replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&amp;/g, "&").replace(/&quot;/g, '"');

    // VEVENT parsen
    const veventMatch = ical.match(/BEGIN:VEVENT([\s\S]*?)END:VEVENT/);
    if (!veventMatch) continue;

    const vevent = veventMatch[1];

    const uid = extractICalProp(vevent, "UID") || `event-${Math.random()}`;
    const summary = extractICalProp(vevent, "SUMMARY") || "(Kein Titel)";
    const location = extractICalProp(vevent, "LOCATION");
    const description = extractICalProp(vevent, "DESCRIPTION");

    // Start/End — können DATE oder DATE-TIME sein
    const dtstart = extractICalDateTime(vevent, "DTSTART");
    const dtend = extractICalDateTime(vevent, "DTEND");

    if (!dtstart) continue;

    const allDay = !dtstart.includes("T");
    const startDate = parseICalDate(dtstart);
    const endDate = dtend ? parseICalDate(dtend) : startDate;

    events.push({
      uid,
      summary,
      start: startDate,
      end: endDate,
      allDay,
      location: location || undefined,
      description: description || undefined,
    });
  }

  return events;
}

function extractICalProp(vevent: string, prop: string): string | null {
  // Handles multiline values (folded lines starting with space)
  const regex = new RegExp(`^${prop}[;:]([^\\r\\n]*)`, "mi");
  const match = vevent.match(regex);
  if (!match) return null;

  let value = match[1];
  // Entferne ggf. Parameter vor dem Wert (z.B. ";VALUE=DATE:" → Wert nach letztem :)
  if (match[0].includes(";") && match[0].includes(":")) {
    const colonIdx = match[0].indexOf(":", prop.length);
    if (colonIdx > -1) {
      value = match[0].substring(colonIdx + 1);
    }
  }

  // Unfold (RFC 5545 line folding)
  value = value.replace(/\r?\n[ \t]/g, "");
  // Escape-Sequenzen
  value = value.replace(/\\n/g, "\n").replace(/\\,/g, ",").replace(/\\\\/g, "\\");
  return value.trim() || null;
}

function extractICalDateTime(vevent: string, prop: string): string | null {
  // Suche nach DTSTART;VALUE=DATE:20240101 oder DTSTART:20240101T120000Z etc.
  const regex = new RegExp(`${prop}[^:]*:([^\\r\\n]+)`, "mi");
  const match = vevent.match(regex);
  return match ? match[1].trim() : null;
}

function parseICalDate(dateStr: string): string {
  // 20240101 → 2024-01-01
  // 20240101T120000Z → 2024-01-01T12:00:00Z
  // 20240101T120000 → 2024-01-01T12:00:00
  if (dateStr.length === 8) {
    return `${dateStr.slice(0, 4)}-${dateStr.slice(4, 6)}-${dateStr.slice(6, 8)}`;
  }
  if (dateStr.includes("T")) {
    const d = dateStr.replace("T", "");
    const base = `${dateStr.slice(0, 4)}-${dateStr.slice(4, 6)}-${dateStr.slice(6, 8)}`;
    const timepart = dateStr.slice(9);
    const time = `${timepart.slice(0, 2)}:${timepart.slice(2, 4)}:${timepart.slice(4, 6)}`;
    const tz = timepart.endsWith("Z") ? "Z" : "";
    return `${base}T${time}${tz}`;
  }
  return dateStr;
}
