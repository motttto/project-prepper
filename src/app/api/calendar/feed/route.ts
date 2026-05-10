import { type NextRequest } from "next/server";
import { createServerSupabaseClient } from "@/lib/supabase-server";

const LOANS_GROUP_ID = "loans";

/**
 * iCal Feed — exportiert Kalender-Events als .ics
 * GET /api/calendar/feed?token=xxx[&group_id=yyy]
 *
 * Authentifizierung über Feed-Token (kein Session-Cookie nötig).
 * Damit können Apple Calendar, Google Calendar, Outlook etc. den Feed abonnieren.
 *
 * group_id=loans liefert die virtuelle Verleih-Collection (Bookings als read-only Events).
 */
export async function GET(request: NextRequest) {
  const supabase = await createServerSupabaseClient();
  const { searchParams } = new URL(request.url);
  const token = searchParams.get("token");
  const groupId = searchParams.get("group_id");

  if (!token) {
    return new Response("token parameter required", { status: 400 });
  }

  // Token validieren + org_id laden (service-level query, bypasses RLS via server client)
  const { data: feedToken, error: tokenError } = await supabase
    .from("calendar_feed_tokens")
    .select("id, org_id, profile_id")
    .eq("token", token)
    .maybeSingle();

  if (tokenError || !feedToken) {
    return new Response("Invalid or expired token", { status: 401 });
  }

  const orgId = feedToken.org_id;

  // Update last_used_at (fire and forget)
  supabase
    .from("calendar_feed_tokens")
    .update({ last_used_at: new Date().toISOString() })
    .eq("id", feedToken.id)
    .then();

  const wantOnlyLoans = groupId === LOANS_GROUP_ID;
  const wantLoans = !groupId || wantOnlyLoans;

  // Fetch events (skip wenn nur Verleih angefragt)
  let events: any[] = [];
  if (!wantOnlyLoans) {
    let query = supabase
      .from("calendar_events")
      .select("*")
      .eq("org_id", orgId)
      .order("start_at", { ascending: true });

    if (groupId) {
      query = query.eq("group_id", groupId);
    }

    const { data, error } = await query;
    if (error) {
      return new Response("Database error", { status: 500 });
    }
    events = data || [];
  }

  // Verleih-Collection (Bookings → virtuelle Events)
  let loans: any[] = [];
  if (wantLoans) {
    const now = new Date();
    const start = new Date(now); start.setUTCMonth(start.getUTCMonth() - 1);
    const end = new Date(now); end.setUTCFullYear(end.getUTCFullYear() + 1);
    const startStr = start.toISOString().slice(0, 10);
    const endStr = end.toISOString().slice(0, 10);

    const { data: bookings } = await supabase
      .from("bookings")
      .select("id,quantity,date_from,date_to,status,approval_status,created_at,inventory_items(name),projects!inner(id,title,org_id)")
      .eq("projects.org_id", orgId)
      .neq("approval_status", "rejected")
      .lte("date_from", endStr)
      .gte("date_to", startStr);

    loans = bookings || [];
  }

  // Org-Name für Kalender-Titel
  const { data: org } = await supabase
    .from("organizations")
    .select("name")
    .eq("id", orgId)
    .single();

  let calName = org?.name ? `${org.name} — Team-Kalender` : "Team-Kalender";
  if (wantOnlyLoans) {
    calName = `${org?.name || "Team"} — Verleih`;
  } else if (groupId) {
    const { data: group } = await supabase
      .from("calendar_groups")
      .select("name")
      .eq("id", groupId)
      .single();
    if (group) calName = `${org?.name || "Team"} — ${group.name}`;
  }

  // iCal generieren
  const icsLines: string[] = [
    "BEGIN:VCALENDAR",
    "VERSION:2.0",
    "PRODID:-//Project Prepper//Team Calendar//DE",
    `X-WR-CALNAME:${escapeIcal(calName)}`,
    "CALSCALE:GREGORIAN",
    "METHOD:PUBLISH",
    "X-WR-TIMEZONE:Europe/Berlin",
  ];

  for (const evt of events) {
    const uid = evt.id + "@project-prepper";
    const created = formatIcalDate(evt.created_at);
    const modified = formatIcalDate(evt.updated_at || evt.created_at);

    icsLines.push("BEGIN:VEVENT");
    icsLines.push(`UID:${uid}`);
    icsLines.push(`DTSTAMP:${created}`);

    if (evt.all_day) {
      icsLines.push(`DTSTART;VALUE=DATE:${formatIcalDateOnly(evt.start_at)}`);
      if (evt.end_at) {
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

  // Verleih-Bookings als read-only VEVENTs
  for (const b of loans) {
    const itemName = b.inventory_items?.name ?? "Equipment";
    const projectTitle = b.projects?.title ?? "Projekt";
    const summary = `${b.quantity}x ${itemName} — ${projectTitle}`;
    const description = b.approval_status === "pending"
      ? "Status: Anfrage offen"
      : b.status === "checked_out" ? "Status: Ausgegeben"
      : b.status === "returned" ? "Status: Zurueck"
      : "Status: Reserviert";
    const stamp = formatIcalDate(b.created_at);
    const endDate = new Date(`${b.date_to}T00:00:00Z`);
    endDate.setUTCDate(endDate.getUTCDate() + 1);

    icsLines.push("BEGIN:VEVENT");
    icsLines.push(`UID:loan-${b.id}@project-prepper`);
    icsLines.push(`DTSTAMP:${stamp}`);
    icsLines.push(`DTSTART;VALUE=DATE:${b.date_from.replace(/-/g, "")}`);
    icsLines.push(`DTEND;VALUE=DATE:${formatIcalDateOnly(endDate.toISOString())}`);
    icsLines.push(`SUMMARY:${escapeIcal(summary)}`);
    icsLines.push(`DESCRIPTION:${escapeIcal(description)}`);
    icsLines.push(`LAST-MODIFIED:${stamp}`);
    icsLines.push("END:VEVENT");
  }

  icsLines.push("END:VCALENDAR");

  return new Response(icsLines.join("\r\n"), {
    headers: {
      "Content-Type": "text/calendar; charset=utf-8",
      "Content-Disposition": `inline; filename="calendar.ics"`,
      "Cache-Control": "public, max-age=300", // 5 Min Cache
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
