/**
 * CalDAV Method Handlers
 *
 * Jeder Handler bekommt Auth + Pfad-Info und liefert eine Response.
 * DB-Zugriff über Service-Role Client (kein RLS).
 */

import { createServiceClient } from "@/lib/supabase-service";
import { type CalDavAuth } from "./auth";
import { generateVCalendar, parseVEvent } from "./ical";
import {
  multistatus,
  response,
  propstatOk,
  principalProps,
  calendarHomeProps,
  calendarProps,
  eventProps,
  eventWithData,
  parseRequestedProps,
  parseTimeRange,
  parseMultigetHrefs,
  isCalendarMultiget,
  isCalendarQuery,
} from "./xml";

// ============================================================
// Helpers
// ============================================================

function davResponse(xml: string, status = 207): Response {
  return new Response(xml, {
    status,
    headers: {
      "Content-Type": "application/xml; charset=utf-8",
      DAV: "1, calendar-access",
    },
  });
}

function eventIdFromFile(filename: string): string {
  // "some-uuid.ics" → "some-uuid"
  return filename.replace(/\.ics$/i, "");
}

// ============================================================
// OPTIONS
// ============================================================

export function handleOptions(): Response {
  return new Response(null, {
    status: 200,
    headers: {
      Allow: "OPTIONS, GET, PUT, DELETE, PROPFIND, REPORT",
      DAV: "1, calendar-access",
      "Content-Length": "0",
    },
  });
}

// ============================================================
// PROPFIND — Principal (/)
// ============================================================

export async function handlePropfindPrincipal(
  auth: CalDavAuth,
  baseHref: string,
  _body: string,
  _depth: string
): Promise<Response> {
  const supabase = createServiceClient();

  // Org-Name als Account-Displayname holen
  const { data: org } = await supabase
    .from("organizations")
    .select("name")
    .eq("id", auth.orgId)
    .single();

  const displayName = org?.name || "Project Prepper";

  const xml = multistatus(
    response(baseHref, principalProps(baseHref, displayName))
  );

  return davResponse(xml);
}

// ============================================================
// PROPFIND — Calendar Home (/calendars/)
// ============================================================

export async function handlePropfindCalendarHome(
  auth: CalDavAuth,
  baseHref: string,
  body: string,
  depth: string
): Promise<Response> {
  const supabase = createServiceClient();
  let inner = response(baseHref + "calendars/", calendarHomeProps());

  // Depth 1: auch alle Kalender auflisten
  if (depth === "1") {
    const { data: groups } = await supabase
      .from("calendar_groups")
      .select("id, name, color, ctag")
      .eq("org_id", auth.orgId)
      .order("sort_order");

    if (groups) {
      for (const g of groups) {
        const href = `${baseHref}calendars/${g.id}/`;
        inner += response(href, calendarProps(g.name, g.color, g.ctag ?? 1));
      }
    }
  }

  return davResponse(multistatus(inner));
}

// ============================================================
// PROPFIND — Calendar Collection (/calendars/{groupId}/)
// ============================================================

export async function handlePropfindCalendar(
  auth: CalDavAuth,
  groupId: string,
  baseHref: string,
  body: string,
  depth: string
): Promise<Response> {
  const supabase = createServiceClient();

  // Kalender-Metadaten
  const { data: group } = await supabase
    .from("calendar_groups")
    .select("id, name, color, ctag")
    .eq("id", groupId)
    .eq("org_id", auth.orgId)
    .single();

  if (!group) {
    return new Response("Not Found", { status: 404 });
  }

  const calHref = `${baseHref}calendars/${groupId}/`;
  let inner = response(calHref, calendarProps(group.name, group.color, group.ctag ?? 1));

  // Depth 1: Event-Listing (nur HREFs + ETags)
  if (depth === "1") {
    const { data: events } = await supabase
      .from("calendar_events")
      .select("id, etag, caldav_uid")
      .eq("org_id", auth.orgId)
      .eq("group_id", groupId);

    if (events) {
      for (const e of events) {
        const eventHref = `${calHref}${e.caldav_uid}.ics`;
        inner += response(eventHref, eventProps(e.etag));
      }
    }
  }

  return davResponse(multistatus(inner));
}

// ============================================================
// REPORT — calendar-query / calendar-multiget
// ============================================================

export async function handleReport(
  auth: CalDavAuth,
  groupId: string,
  baseHref: string,
  body: string
): Promise<Response> {
  const supabase = createServiceClient();
  const calHref = `${baseHref}calendars/${groupId}/`;

  if (isCalendarMultiget(body)) {
    return handleMultiget(auth, groupId, baseHref, body);
  }

  // calendar-query (mit optionalem time-range Filter)
  const timeRange = parseTimeRange(body);
  const requestedProps = parseRequestedProps(body);
  const wantData = requestedProps.includes("calendar-data");

  let query = supabase
    .from("calendar_events")
    .select("id, org_id, group_id, summary, description, location, all_day, start_at, end_at, created_at, updated_at, etag, caldav_uid")
    .eq("org_id", auth.orgId)
    .eq("group_id", groupId);

  if (timeRange?.start) {
    query = query.gte("start_at", timeRange.start);
  }
  if (timeRange?.end) {
    query = query.lte("start_at", timeRange.end);
  }

  const { data: events } = await query;

  let inner = "";
  if (events) {
    for (const e of events) {
      const eventHref = `${calHref}${e.caldav_uid}.ics`;
      if (wantData) {
        const ical = generateVCalendar(e);
        inner += response(eventHref, eventWithData(e.etag, ical));
      } else {
        inner += response(eventHref, eventProps(e.etag));
      }
    }
  }

  return davResponse(multistatus(inner));
}

async function handleMultiget(
  auth: CalDavAuth,
  groupId: string,
  baseHref: string,
  body: string
): Promise<Response> {
  const supabase = createServiceClient();
  const hrefs = parseMultigetHrefs(body);

  // UIDs aus HREFs extrahieren
  const uids = hrefs
    .map((h) => {
      const match = h.match(/\/([^/]+)\.ics$/);
      return match ? match[1] : null;
    })
    .filter(Boolean) as string[];

  if (uids.length === 0) {
    return davResponse(multistatus(""));
  }

  const { data: events } = await supabase
    .from("calendar_events")
    .select("id, org_id, group_id, summary, description, location, all_day, start_at, end_at, created_at, updated_at, etag, caldav_uid")
    .eq("org_id", auth.orgId)
    .eq("group_id", groupId)
    .in("caldav_uid", uids);

  let inner = "";
  if (events) {
    const calHref = `${baseHref}calendars/${groupId}/`;
    for (const e of events) {
      const eventHref = `${calHref}${e.caldav_uid}.ics`;
      const ical = generateVCalendar(e);
      inner += response(eventHref, eventWithData(e.etag, ical));
    }
  }

  return davResponse(multistatus(inner));
}

// ============================================================
// GET — Single Event
// ============================================================

export async function handleGetEvent(
  auth: CalDavAuth,
  groupId: string,
  eventFile: string,
  _baseHref: string
): Promise<Response> {
  const supabase = createServiceClient();
  const uid = eventIdFromFile(eventFile);

  const { data: event } = await supabase
    .from("calendar_events")
    .select("*")
    .eq("org_id", auth.orgId)
    .eq("group_id", groupId)
    .eq("caldav_uid", uid)
    .single();

  if (!event) {
    return new Response("Not Found", { status: 404 });
  }

  const ical = generateVCalendar(event);

  return new Response(ical, {
    status: 200,
    headers: {
      "Content-Type": "text/calendar; charset=utf-8",
      ETag: `"${event.etag}"`,
    },
  });
}

// ============================================================
// PUT — Create or Update Event
// ============================================================

export async function handlePutEvent(
  auth: CalDavAuth,
  groupId: string,
  eventFile: string,
  icalBody: string,
  ifMatch: string | null,
  baseHref: string
): Promise<Response> {
  const supabase = createServiceClient();
  const uid = eventIdFromFile(eventFile);

  // iCal parsen
  const parsed = parseVEvent(icalBody);
  if (!parsed) {
    return new Response("Bad Request — invalid iCalendar data", { status: 400 });
  }

  // Prüfen ob Event existiert
  const { data: existing } = await supabase
    .from("calendar_events")
    .select("id, etag")
    .eq("org_id", auth.orgId)
    .eq("caldav_uid", uid)
    .maybeSingle();

  if (existing) {
    // === UPDATE ===
    // ETag-Check (If-Match)
    if (ifMatch) {
      const cleanEtag = ifMatch.replace(/"/g, "");
      if (cleanEtag !== existing.etag) {
        return new Response("Precondition Failed — ETag mismatch", { status: 412 });
      }
    }

    const { data: updated, error } = await supabase
      .from("calendar_events")
      .update({
        summary: parsed.summary,
        description: parsed.description,
        location: parsed.location,
        all_day: parsed.allDay,
        start_at: parsed.startAt,
        end_at: parsed.endAt,
        group_id: groupId,
        updated_at: new Date().toISOString(),
      })
      .eq("id", existing.id)
      .select("etag")
      .single();

    if (error) {
      return new Response(`Server Error: ${error.message}`, { status: 500 });
    }

    return new Response(null, {
      status: 204,
      headers: {
        ETag: `"${updated.etag}"`,
      },
    });
  } else {
    // === CREATE ===
    // If-Match bei neuem Event → Conflict
    if (ifMatch && ifMatch !== "*") {
      return new Response("Precondition Failed — event does not exist", { status: 412 });
    }

    const { data: created, error } = await supabase
      .from("calendar_events")
      .insert({
        org_id: auth.orgId,
        group_id: groupId,
        caldav_uid: uid,
        summary: parsed.summary,
        description: parsed.description,
        location: parsed.location,
        all_day: parsed.allDay,
        start_at: parsed.startAt,
        end_at: parsed.endAt,
        created_by: auth.profileId,
      })
      .select("etag")
      .single();

    if (error) {
      return new Response(`Server Error: ${error.message}`, { status: 500 });
    }

    return new Response(null, {
      status: 201,
      headers: {
        ETag: `"${created.etag}"`,
        Location: `${baseHref}calendars/${groupId}/${uid}.ics`,
      },
    });
  }
}

// ============================================================
// DELETE — Remove Event
// ============================================================

export async function handleDeleteEvent(
  auth: CalDavAuth,
  groupId: string,
  eventFile: string
): Promise<Response> {
  const supabase = createServiceClient();
  const uid = eventIdFromFile(eventFile);

  const { error, count } = await supabase
    .from("calendar_events")
    .delete({ count: "exact" })
    .eq("org_id", auth.orgId)
    .eq("group_id", groupId)
    .eq("caldav_uid", uid);

  if (error) {
    return new Response(`Server Error: ${error.message}`, { status: 500 });
  }

  if (count === 0) {
    return new Response("Not Found", { status: 404 });
  }

  return new Response(null, { status: 204 });
}
