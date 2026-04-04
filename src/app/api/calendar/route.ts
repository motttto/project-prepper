import { NextResponse, type NextRequest } from "next/server";
import { createServerSupabaseClient } from "@/lib/supabase-server";
import { randomUUID } from "crypto";

// === Auth + CalDAV Config Helper ===
async function getAuthAndConfig() {
  const supabase = await createServerSupabaseClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) return { error: "Unauthorized", status: 401 };

  const caldavUrl = process.env.CALDAV_URL;
  const caldavUser = process.env.CALDAV_USER;
  const caldavPassword = process.env.CALDAV_PASSWORD;
  if (!caldavUrl || !caldavUser || !caldavPassword) {
    return { error: "CalDAV not configured", status: 500 };
  }

  const authHeader = "Basic " + Buffer.from(`${caldavUser}:${caldavPassword}`).toString("base64");
  const baseUrl = caldavUrl.replace(/\/remote\.php\/dav$/, "");
  const calendarsUrl = `${caldavUrl}/calendars/${caldavUser}/`;

  return { user, caldavUrl, caldavUser, authHeader, baseUrl, calendarsUrl };
}

// === Kalender-Liste + Events laden ===
export async function GET(request: NextRequest) {
  const config = await getAuthAndConfig();
  if ("error" in config) return NextResponse.json({ error: config.error }, { status: config.status });

  const { searchParams } = new URL(request.url);
  const start = searchParams.get("start");
  const end = searchParams.get("end");
  const listOnly = searchParams.get("calendars") === "true";

  const { authHeader, baseUrl, calendarsUrl } = config;

  try {
    // PROPFIND — Kalender-Liste
    const propfindBody = `<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:x1="http://apple.com/ns/ical/">
  <d:prop>
    <d:displayname />
    <d:resourcetype />
    <x1:calendar-color />
  </d:prop>
</d:propfind>`;

    const propRes = await fetch(calendarsUrl, {
      method: "PROPFIND",
      headers: { Authorization: authHeader, "Content-Type": "application/xml; charset=utf-8", Depth: "1" },
      body: propfindBody,
    });

    if (!propRes.ok) {
      return NextResponse.json({ error: `CalDAV PROPFIND failed: ${propRes.status}` }, { status: 502 });
    }

    const propXml = await propRes.text();
    const calendars = extractCalendars(propXml, calendarsUrl);

    // Nur Kalender-Liste?
    if (listOnly) {
      return NextResponse.json({ calendars });
    }

    if (!start || !end) {
      return NextResponse.json({ error: "start and end params required" }, { status: 400 });
    }

    // Events aus allen Kalendern PARALLEL laden
    const eventResults = await Promise.all(
      calendars.map(async (cal) => {
        const calUrl = cal.path.startsWith("http") ? cal.path : `${baseUrl}${cal.path}`;

        const reportBody = `<?xml version="1.0" encoding="utf-8" ?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag />
    <d:href />
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

        try {
          const reportRes = await fetch(calUrl, {
            method: "REPORT",
            headers: { Authorization: authHeader, "Content-Type": "application/xml; charset=utf-8", Depth: "1" },
            body: reportBody,
          });

          if (reportRes.ok) {
            const reportXml = await reportRes.text();
            return parseICalEvents(reportXml, cal.id, cal.name);
          }
        } catch (e) {
          console.error(`[CalDAV] REPORT failed for ${cal.name}:`, e);
        }
        return [];
      })
    );

    const allEvents: CalendarEvent[] = eventResults.flat();

    allEvents.sort((a, b) => new Date(a.start).getTime() - new Date(b.start).getTime());
    return NextResponse.json({ calendars, events: allEvents });
  } catch (err) {
    console.error("[CalDAV] Error:", err);
    return NextResponse.json({ error: "CalDAV request failed" }, { status: 502 });
  }
}

// === Event erstellen / aktualisieren ===
export async function PUT(request: NextRequest) {
  const config = await getAuthAndConfig();
  if ("error" in config) return NextResponse.json({ error: config.error }, { status: config.status });

  const { authHeader, baseUrl } = config;
  const body = await request.json();
  const { calendarPath, uid, summary, start, end, allDay, location, description } = body;

  if (!calendarPath || !summary || !start) {
    return NextResponse.json({ error: "calendarPath, summary, start required" }, { status: 400 });
  }

  const eventUid = uid || randomUUID();
  const now = new Date().toISOString().replace(/[-:]/g, "").replace(/\.\d{3}/, "");

  // iCal generieren
  let dtstart: string;
  let dtend: string;

  if (allDay) {
    // DATE format: YYYYMMDD
    dtstart = `DTSTART;VALUE=DATE:${start.replace(/-/g, "")}`;
    dtend = end
      ? `DTEND;VALUE=DATE:${end.replace(/-/g, "")}`
      : `DTEND;VALUE=DATE:${start.replace(/-/g, "")}`;
  } else {
    dtstart = `DTSTART:${start.replace(/[-:]/g, "").replace(/\.\d{3}/, "")}`;
    dtend = end
      ? `DTEND:${end.replace(/[-:]/g, "").replace(/\.\d{3}/, "")}`
      : `DTEND:${start.replace(/[-:]/g, "").replace(/\.\d{3}/, "")}`;
  }

  const ical = [
    "BEGIN:VCALENDAR",
    "VERSION:2.0",
    "PRODID:-//Project Prepper//NONSGML v1.0//EN",
    "BEGIN:VEVENT",
    `UID:${eventUid}`,
    `DTSTAMP:${now}`,
    dtstart,
    dtend,
    `SUMMARY:${escapeICalText(summary)}`,
    location ? `LOCATION:${escapeICalText(location)}` : null,
    description ? `DESCRIPTION:${escapeICalText(description)}` : null,
    `LAST-MODIFIED:${now}`,
    "END:VEVENT",
    "END:VCALENDAR",
  ].filter(Boolean).join("\r\n");

  const eventUrl = `${baseUrl}${calendarPath}${eventUid}.ics`;

  try {
    const res = await fetch(eventUrl, {
      method: "PUT",
      headers: {
        Authorization: authHeader,
        "Content-Type": "text/calendar; charset=utf-8",
      },
      body: ical,
    });

    if (!res.ok && res.status !== 201 && res.status !== 204) {
      const text = await res.text();
      console.error("[CalDAV PUT]", res.status, text);
      return NextResponse.json({ error: `CalDAV PUT failed: ${res.status}` }, { status: 502 });
    }

    return NextResponse.json({ success: true, uid: eventUid });
  } catch (err) {
    console.error("[CalDAV PUT] Error:", err);
    return NextResponse.json({ error: "CalDAV PUT failed" }, { status: 502 });
  }
}

// === Event löschen ===
export async function DELETE(request: NextRequest) {
  const config = await getAuthAndConfig();
  if ("error" in config) return NextResponse.json({ error: config.error }, { status: config.status });

  const { authHeader, baseUrl } = config;
  const { searchParams } = new URL(request.url);
  const href = searchParams.get("href");

  if (!href) {
    return NextResponse.json({ error: "href param required" }, { status: 400 });
  }

  const eventUrl = `${baseUrl}${href}`;

  try {
    const res = await fetch(eventUrl, {
      method: "DELETE",
      headers: { Authorization: authHeader },
    });

    if (!res.ok && res.status !== 204) {
      return NextResponse.json({ error: `CalDAV DELETE failed: ${res.status}` }, { status: 502 });
    }

    return NextResponse.json({ success: true });
  } catch (err) {
    console.error("[CalDAV DELETE] Error:", err);
    return NextResponse.json({ error: "CalDAV DELETE failed" }, { status: 502 });
  }
}

// ====================== Helpers ======================

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

function escapeICalText(text: string): string {
  return text.replace(/\\/g, "\\\\").replace(/;/g, "\\;").replace(/,/g, "\\,").replace(/\n/g, "\\n");
}

function formatCalDavDate(isoDate: string): string {
  return new Date(isoDate).toISOString().replace(/[-:]/g, "").replace(/\.\d{3}/, "");
}

function extractCalendars(xml: string, baseUrl: string): CalendarInfo[] {
  const calendars: CalendarInfo[] = [];
  const responseBlocks = xml.split(/<d:response>/i).slice(1);

  for (const block of responseBlocks) {
    const isCalendar =
      block.includes("<cal:calendar") ||
      block.includes("<c:calendar") ||
      block.includes("calendar</");

    const hrefMatch = block.match(/<d:href>([^<]+)<\/d:href>/i);
    if (!hrefMatch) continue;

    const href = hrefMatch[1];

    if (href === new URL(baseUrl).pathname || href === new URL(baseUrl).pathname + "/") continue;
    if (href.includes("outbox") || href.includes("inbox") || href.includes("trashbin")) continue;

    if (isCalendar) {
      const nameMatch = block.match(/<d:displayname>([^<]*)<\/d:displayname>/i);
      const colorMatch = block.match(/<x1:calendar-color[^>]*>([^<]*)<\/x1:calendar-color>/i) ||
                         block.match(/<[^>]*calendar-color[^>]*>([^<]*)<\//i);

      const name = nameMatch?.[1] || href.split("/").filter(Boolean).pop() || "Kalender";
      const id = href.split("/").filter(Boolean).pop() || href;
      let color = colorMatch?.[1]?.trim();
      // Nextcloud color format: #FF0000FF (8 chars) → #FF0000
      if (color && color.length === 9) color = color.slice(0, 7);

      calendars.push({ id, name, path: href, color });
    }
  }

  return calendars;
}

function parseICalEvents(xml: string, calendarId: string, calendarName: string): CalendarEvent[] {
  const events: CalendarEvent[] = [];

  // Responses mit href + calendar-data extrahieren
  const responseBlocks = xml.split(/<d:response>/i).slice(1);

  for (const block of responseBlocks) {
    const hrefMatch = block.match(/<d:href>([^<]+)<\/d:href>/i);
    const href = hrefMatch?.[1] || "";

    const dataMatch = block.match(/<(?:c|cal):calendar-data[^>]*>([\s\S]*?)<\/(?:c|cal):calendar-data>/i);
    if (!dataMatch) continue;

    let ical = dataMatch[1].trim();
    ical = ical.replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&amp;/g, "&").replace(/&quot;/g, '"');

    const veventMatch = ical.match(/BEGIN:VEVENT([\s\S]*?)END:VEVENT/);
    if (!veventMatch) continue;

    const vevent = veventMatch[1];

    const uid = extractICalProp(vevent, "UID") || `event-${Math.random()}`;
    const summary = extractICalProp(vevent, "SUMMARY") || "(Kein Titel)";
    const location = extractICalProp(vevent, "LOCATION");
    const description = extractICalProp(vevent, "DESCRIPTION");

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
      calendarId,
      calendarName,
      href,
    });
  }

  return events;
}

function extractICalProp(vevent: string, prop: string): string | null {
  const regex = new RegExp(`^${prop}[;:]([^\\r\\n]*)`, "mi");
  const match = vevent.match(regex);
  if (!match) return null;

  let value = match[1];
  if (match[0].includes(";") && match[0].includes(":")) {
    const colonIdx = match[0].indexOf(":", prop.length);
    if (colonIdx > -1) {
      value = match[0].substring(colonIdx + 1);
    }
  }

  value = value.replace(/\r?\n[ \t]/g, "");
  value = value.replace(/\\n/g, "\n").replace(/\\,/g, ",").replace(/\\\\/g, "\\");
  return value.trim() || null;
}

function extractICalDateTime(vevent: string, prop: string): string | null {
  const regex = new RegExp(`${prop}[^:]*:([^\\r\\n]+)`, "mi");
  const match = vevent.match(regex);
  return match ? match[1].trim() : null;
}

function parseICalDate(dateStr: string): string {
  if (dateStr.length === 8) {
    return `${dateStr.slice(0, 4)}-${dateStr.slice(4, 6)}-${dateStr.slice(6, 8)}`;
  }
  if (dateStr.includes("T")) {
    const base = `${dateStr.slice(0, 4)}-${dateStr.slice(4, 6)}-${dateStr.slice(6, 8)}`;
    const timepart = dateStr.slice(9);
    const time = `${timepart.slice(0, 2)}:${timepart.slice(2, 4)}:${timepart.slice(4, 6)}`;
    const tz = timepart.endsWith("Z") ? "Z" : "";
    return `${base}T${time}${tz}`;
  }
  return dateStr;
}
