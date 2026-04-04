import { NextResponse, type NextRequest } from "next/server";
import { createServerSupabaseClient } from "@/lib/supabase-server";

/**
 * One-time import: Nextcloud CalDAV → Supabase calendar_groups + calendar_events
 * POST /api/calendar/import?org_id=xxx
 */
export async function POST(request: NextRequest) {
  const supabase = await createServerSupabaseClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) return NextResponse.json({ error: "Unauthorized" }, { status: 401 });

  const { searchParams } = new URL(request.url);
  const orgId = searchParams.get("org_id");
  if (!orgId) return NextResponse.json({ error: "org_id required" }, { status: 400 });

  const caldavUrl = process.env.CALDAV_URL;
  const caldavUser = process.env.CALDAV_USER;
  const caldavPassword = process.env.CALDAV_PASSWORD;
  if (!caldavUrl || !caldavUser || !caldavPassword) {
    return NextResponse.json({ error: "CalDAV env vars not configured" }, { status: 500 });
  }

  const authHeader = "Basic " + Buffer.from(`${caldavUser}:${caldavPassword}`).toString("base64");
  const baseUrl = caldavUrl.replace(/\/remote\.php\/dav$/, "");
  const calendarsUrl = `${caldavUrl}/calendars/${caldavUser}/`;

  try {
    // 1. PROPFIND — discover calendars
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
      return NextResponse.json({ error: `PROPFIND failed: ${propRes.status}` }, { status: 502 });
    }

    const propXml = await propRes.text();
    const calendars = extractCalendars(propXml, calendarsUrl);

    if (calendars.length === 0) {
      return NextResponse.json({ error: "No calendars found", xml: propXml.slice(0, 500) }, { status: 404 });
    }

    // 2. Create calendar_groups in Supabase
    const groupMap = new Map<string, string>(); // caldav-id → supabase-group-id
    let groupsCreated = 0;

    for (let i = 0; i < calendars.length; i++) {
      const cal = calendars[i];

      // Check if group already exists
      const { data: existing } = await supabase
        .from("calendar_groups")
        .select("id")
        .eq("org_id", orgId)
        .eq("name", cal.name)
        .maybeSingle();

      if (existing) {
        groupMap.set(cal.id, existing.id);
      } else {
        const { data: newGroup, error } = await supabase
          .from("calendar_groups")
          .insert({
            org_id: orgId,
            name: cal.name,
            color: cal.color || "#0066FF",
            sort_order: i,
            created_by: user.id,
          })
          .select("id")
          .single();

        if (error) {
          console.error("[Import] Group insert error:", error);
          continue;
        }
        groupMap.set(cal.id, newGroup.id);
        groupsCreated++;
      }
    }

    // 3. Fetch ALL events from each calendar (wide range)
    const startDate = "20200101T000000Z";
    const endDate = "20271231T235959Z";
    let totalEvents = 0;
    let eventsCreated = 0;

    for (const cal of calendars) {
      const calUrl = cal.path.startsWith("http") ? cal.path : `${baseUrl}${cal.path}`;
      const groupId = groupMap.get(cal.id);

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
        <c:time-range start="${startDate}" end="${endDate}" />
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>`;

      const reportRes = await fetch(calUrl, {
        method: "REPORT",
        headers: { Authorization: authHeader, "Content-Type": "application/xml; charset=utf-8", Depth: "1" },
        body: reportBody,
      });

      if (!reportRes.ok) continue;

      const reportXml = await reportRes.text();
      const events = parseICalEvents(reportXml);
      totalEvents += events.length;

      // Batch insert events
      for (const evt of events) {
        const { error } = await supabase.from("calendar_events").insert({
          org_id: orgId,
          group_id: groupId || null,
          summary: evt.summary,
          description: evt.description || null,
          location: evt.location || null,
          all_day: evt.allDay,
          start_at: evt.start,
          end_at: evt.end || evt.start,
          created_by: user.id,
        });

        if (!error) eventsCreated++;
        else console.error("[Import] Event insert error:", error.message, evt.summary);
      }
    }

    return NextResponse.json({
      success: true,
      calendars: calendars.length,
      groupsCreated,
      totalEvents,
      eventsCreated,
    });
  } catch (err) {
    console.error("[Import] Error:", err);
    return NextResponse.json({ error: "Import failed" }, { status: 500 });
  }
}

// ===== Helpers =====

type CalInfo = { id: string; name: string; path: string; color?: string };

function extractCalendars(xml: string, baseUrl: string): CalInfo[] {
  const calendars: CalInfo[] = [];
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
      if (color && color.length === 9) color = color.slice(0, 7);

      calendars.push({ id, name, path: href, color });
    }
  }

  return calendars;
}

type ParsedEvent = {
  uid: string;
  summary: string;
  start: string;
  end: string | null;
  allDay: boolean;
  location?: string;
  description?: string;
};

function parseICalEvents(xml: string): ParsedEvent[] {
  const events: ParsedEvent[] = [];
  const responseBlocks = xml.split(/<d:response>/i).slice(1);

  for (const block of responseBlocks) {
    const dataMatch = block.match(/<(?:c|cal):calendar-data[^>]*>([\s\S]*?)<\/(?:c|cal):calendar-data>/i);
    if (!dataMatch) continue;

    let ical = dataMatch[1].trim();
    ical = ical.replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&amp;/g, "&").replace(/&quot;/g, '"');

    const veventMatch = ical.match(/BEGIN:VEVENT([\s\S]*?)END:VEVENT/);
    if (!veventMatch) continue;

    const vevent = veventMatch[1];
    const uid = extractProp(vevent, "UID") || `import-${Math.random()}`;
    const summary = extractProp(vevent, "SUMMARY") || "(Kein Titel)";
    const location = extractProp(vevent, "LOCATION");
    const description = extractProp(vevent, "DESCRIPTION");

    const dtstart = extractDateTime(vevent, "DTSTART");
    const dtend = extractDateTime(vevent, "DTEND");
    if (!dtstart) continue;

    const allDay = !dtstart.includes("T");
    const startISO = parseICalDate(dtstart);
    const endISO = dtend ? parseICalDate(dtend) : null;

    // Convert to proper ISO for timestamptz
    let startAt: string;
    let endAt: string | null;

    if (allDay) {
      startAt = startISO + "T00:00:00Z";
      endAt = endISO ? endISO + "T23:59:59Z" : startISO + "T23:59:59Z";
    } else {
      startAt = startISO.endsWith("Z") ? startISO : startISO + "Z";
      endAt = endISO ? (endISO.endsWith("Z") ? endISO : endISO + "Z") : null;
    }

    events.push({ uid, summary, start: startAt, end: endAt, allDay, location: location || undefined, description: description || undefined });
  }

  return events;
}

function extractProp(vevent: string, prop: string): string | null {
  const regex = new RegExp(`^${prop}[;:]([^\\r\\n]*)`, "mi");
  const match = vevent.match(regex);
  if (!match) return null;

  let value = match[1];
  if (match[0].includes(";") && match[0].includes(":")) {
    const colonIdx = match[0].indexOf(":", prop.length);
    if (colonIdx > -1) value = match[0].substring(colonIdx + 1);
  }

  value = value.replace(/\r?\n[ \t]/g, "");
  value = value.replace(/\\n/g, "\n").replace(/\\,/g, ",").replace(/\\\\/g, "\\");
  return value.trim() || null;
}

function extractDateTime(vevent: string, prop: string): string | null {
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
