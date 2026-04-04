import { type NextRequest } from "next/server";
import { createServerSupabaseClient } from "@/lib/supabase-server";

/**
 * iCal Feed — exportiert Kalender-Events als .ics für Abo in Apple Calendar, Google etc.
 * GET /api/calendar/feed?org_id=xxx[&group_id=yyy]
 *
 * URL kann direkt als "Kalender abonnieren" in Apple Calendar / Google Calendar / Outlook verwendet werden.
 */
export async function GET(request: NextRequest) {
  const supabase = await createServerSupabaseClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) {
    return new Response("Unauthorized", { status: 401 });
  }

  const { searchParams } = new URL(request.url);
  const orgId = searchParams.get("org_id");
  const groupId = searchParams.get("group_id");

  if (!orgId) {
    return new Response("org_id required", { status: 400 });
  }

  // Fetch events
  let query = supabase
    .from("calendar_events")
    .select("*")
    .eq("org_id", orgId)
    .order("start_at", { ascending: true });

  if (groupId) {
    query = query.eq("group_id", groupId);
  }

  const { data: events, error } = await query;
  if (error) {
    return new Response("Database error", { status: 500 });
  }

  // Fetch org name for calendar title
  const { data: org } = await supabase
    .from("organizations")
    .select("name")
    .eq("id", orgId)
    .single();

  // Fetch group name if filtered
  let calName = org?.name ? `${org.name} — Team-Kalender` : "Team-Kalender";
  if (groupId) {
    const { data: group } = await supabase
      .from("calendar_groups")
      .select("name")
      .eq("id", groupId)
      .single();
    if (group) calName = `${org?.name || "Team"} — ${group.name}`;
  }

  // Generate iCal
  const icsLines: string[] = [
    "BEGIN:VCALENDAR",
    "VERSION:2.0",
    "PRODID:-//Project Prepper//Team Calendar//DE",
    `X-WR-CALNAME:${escapeIcal(calName)}`,
    "CALSCALE:GREGORIAN",
    "METHOD:PUBLISH",
  ];

  for (const evt of events || []) {
    const uid = evt.id + "@project-prepper";
    const created = formatIcalDate(evt.created_at);
    const modified = formatIcalDate(evt.updated_at || evt.created_at);

    icsLines.push("BEGIN:VEVENT");
    icsLines.push(`UID:${uid}`);
    icsLines.push(`DTSTAMP:${created}`);

    if (evt.all_day) {
      icsLines.push(`DTSTART;VALUE=DATE:${formatIcalDateOnly(evt.start_at)}`);
      if (evt.end_at) {
        // iCal: all-day DTEND is exclusive (next day)
        const endDate = new Date(evt.end_at);
        endDate.setDate(endDate.getDate() + 1);
        icsLines.push(`DTEND;VALUE=DATE:${formatIcalDateOnly(endDate.toISOString())}`);
      }
    } else {
      icsLines.push(`DTSTART:${formatIcalDate(evt.start_at)}`);
      if (evt.end_at) {
        icsLines.push(`DTEND:${formatIcalDate(evt.end_at)}`);
      }
    }

    icsLines.push(`SUMMARY:${escapeIcal(evt.summary)}`);
    if (evt.location) icsLines.push(`LOCATION:${escapeIcal(evt.location)}`);
    if (evt.description) icsLines.push(`DESCRIPTION:${escapeIcal(evt.description)}`);
    icsLines.push(`LAST-MODIFIED:${modified}`);
    icsLines.push("END:VEVENT");
  }

  icsLines.push("END:VCALENDAR");

  const icsContent = icsLines.join("\r\n");

  return new Response(icsContent, {
    headers: {
      "Content-Type": "text/calendar; charset=utf-8",
      "Content-Disposition": `attachment; filename="${calName}.ics"`,
      "Cache-Control": "no-cache, no-store, must-revalidate",
    },
  });
}

function escapeIcal(text: string): string {
  return text
    .replace(/\\/g, "\\\\")
    .replace(/;/g, "\\;")
    .replace(/,/g, "\\,")
    .replace(/\n/g, "\\n");
}

function formatIcalDate(isoDate: string): string {
  return new Date(isoDate).toISOString().replace(/[-:]/g, "").replace(/\.\d{3}/, "");
}

function formatIcalDateOnly(isoDate: string): string {
  const d = new Date(isoDate);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}${m}${day}`;
}
