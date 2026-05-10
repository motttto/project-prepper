/**
 * Cloudflare Worker — Eigenständiger CalDAV-Server
 *
 * Kein Proxy mehr! Dieser Worker handelt alle CalDAV-Operationen selbst
 * und redet direkt mit Supabase. Apple Calendar hat nur EINEN Server.
 *
 * URL-Struktur:
 *   /api/caldav/{token}/                          → Principal (PROPFIND)
 *   /api/caldav/{token}/calendars/                → Calendar Home (PROPFIND)
 *   /api/caldav/{token}/calendars/{groupId}/      → Calendar Collection (PROPFIND, REPORT)
 *   /api/caldav/{token}/calendars/{gId}/{eId}.ics → Event (GET, PUT, DELETE)
 */

// ============================================================
// Config — Supabase Credentials via Environment
// ============================================================

const DAV_HEADERS = {
  "Content-Type": "application/xml; charset=utf-8",
  DAV: "1, calendar-access",
};

// Virtuelle Verleih-Collection: Bookings als read-only iCal-Events
const LOANS_GROUP_ID = "loans";
const LOANS_GROUP_NAME = "Verleih";
const LOANS_GROUP_COLOR = "#A855F7";

// ============================================================
// XML Builder (ported from xml.ts)
// ============================================================

const XML_HEADER = '<?xml version="1.0" encoding="utf-8"?>';
const NS = 'xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/" xmlns:ical="http://apple.com/ns/ical/"';

function escapeXml(text) {
  return String(text).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function multistatus(inner) {
  return `${XML_HEADER}\n<d:multistatus ${NS}>\n${inner}</d:multistatus>`;
}

function xmlResponse(href, propstat) {
  return `<d:response>\n<d:href>${escapeXml(href)}</d:href>\n${propstat}</d:response>\n`;
}

function propstatOk(props) {
  return `<d:propstat>\n<d:prop>\n${props}</d:prop>\n<d:status>HTTP/1.1 200 OK</d:status>\n</d:propstat>\n`;
}

function principalProps(baseHref, displayName) {
  return propstatOk([
    `<d:current-user-principal><d:href>${escapeXml(baseHref)}</d:href></d:current-user-principal>`,
    `<d:principal-URL><d:href>${escapeXml(baseHref)}</d:href></d:principal-URL>`,
    `<cal:calendar-home-set><d:href>${escapeXml(baseHref)}calendars/</d:href></cal:calendar-home-set>`,
    `<d:displayname>${escapeXml(displayName)}</d:displayname>`,
    `<d:resourcetype><d:collection/><d:principal/></d:resourcetype>`,
  ].join("\n"));
}

function calendarHomeProps(baseHref) {
  return propstatOk([
    `<d:resourcetype><d:collection/></d:resourcetype>`,
    `<d:current-user-principal><d:href>${escapeXml(baseHref)}</d:href></d:current-user-principal>`,
  ].join("\n"));
}

function calendarProps(name, color, ctag) {
  return propstatOk([
    `<d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>`,
    `<d:displayname>${escapeXml(name)}</d:displayname>`,
    `<ical:calendar-color>${escapeXml(color)}FF</ical:calendar-color>`,
    `<cs:getctag>${ctag}</cs:getctag>`,
    `<cal:supported-calendar-component-set><cal:comp name="VEVENT"/></cal:supported-calendar-component-set>`,
    `<d:supported-report-set>`,
    `<d:supported-report><d:report><cal:calendar-multiget/></d:report></d:supported-report>`,
    `<d:supported-report><d:report><cal:calendar-query/></d:report></d:supported-report>`,
    `</d:supported-report-set>`,
    `<d:current-user-privilege-set>`,
    `<d:privilege><d:read/></d:privilege>`,
    `<d:privilege><d:write/></d:privilege>`,
    `<d:privilege><d:write-content/></d:privilege>`,
    `<d:privilege><d:bind/></d:privilege>`,
    `<d:privilege><d:unbind/></d:privilege>`,
    `</d:current-user-privilege-set>`,
  ].join("\n"));
}

function eventPropsXml(etag) {
  return propstatOk([
    `<d:getetag>"${escapeXml(etag)}"</d:getetag>`,
    `<d:getcontenttype>text/calendar; charset=utf-8; component=VEVENT</d:getcontenttype>`,
    `<d:resourcetype/>`,
  ].join("\n"));
}

function eventWithData(etag, calendarData) {
  return propstatOk([
    `<d:getetag>"${escapeXml(etag)}"</d:getetag>`,
    `<cal:calendar-data>${escapeXml(calendarData)}</cal:calendar-data>`,
  ].join("\n"));
}

// ============================================================
// iCal Builder/Parser (ported from ical.ts)
// ============================================================

function escapeICal(text) {
  return String(text).replace(/\\/g, "\\\\").replace(/;/g, "\\;").replace(/,/g, "\\,").replace(/\n/g, "\\n");
}

function formatICalDate(isoDate) {
  return new Date(isoDate).toISOString().replace(/[-:]/g, "").replace(/\.\d{3}/, "");
}

function formatICalDateOnlyUTC(d) {
  const y = d.getUTCFullYear();
  const m = String(d.getUTCMonth() + 1).padStart(2, "0");
  const day = String(d.getUTCDate()).padStart(2, "0");
  return `${y}${m}${day}`;
}

function formatICalDateOnly(isoDate) {
  return formatICalDateOnlyUTC(new Date(isoDate));
}

function generateVCalendar(event) {
  const created = formatICalDate(event.created_at);
  const modified = formatICalDate(event.updated_at || event.created_at);
  const lines = [
    "BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//Project Prepper//CalDAV//DE",
    "BEGIN:VEVENT", `UID:${event.caldav_uid}`, `DTSTAMP:${created}`,
  ];
  if (event.all_day) {
    lines.push(`DTSTART;VALUE=DATE:${formatICalDateOnly(event.start_at)}`);
    const endStr = event.end_at || event.start_at;
    const endDate = new Date(endStr);
    endDate.setUTCDate(endDate.getUTCDate() + 1);
    lines.push(`DTEND;VALUE=DATE:${formatICalDateOnlyUTC(endDate)}`);
  } else {
    lines.push(`DTSTART:${formatICalDate(event.start_at)}`);
    if (event.end_at) lines.push(`DTEND:${formatICalDate(event.end_at)}`);
  }
  lines.push(`SUMMARY:${escapeICal(event.summary)}`);
  if (event.location) lines.push(`LOCATION:${escapeICal(event.location)}`);
  if (event.description) lines.push(`DESCRIPTION:${escapeICal(event.description)}`);
  lines.push(`LAST-MODIFIED:${modified}`);
  lines.push("END:VEVENT", "END:VCALENDAR");
  return lines.join("\r\n");
}

function parseVEvent(icalString) {
  const ical = icalString.replace(/\r?\n[ \t]/g, "");
  const veventMatch = ical.match(/BEGIN:VEVENT([\s\S]*?)END:VEVENT/);
  if (!veventMatch) return null;
  const vevent = veventMatch[1];

  function extractProp(prop) {
    const regex = new RegExp(`^${prop}[;:]([^\\r\\n]*)`, "mi");
    const match = vevent.match(regex);
    if (!match) return null;
    let value = match[1];
    const fullMatch = match[0];
    if (fullMatch.includes(";") && fullMatch.includes(":")) {
      const colonIdx = fullMatch.indexOf(":", prop.length);
      if (colonIdx > -1) value = fullMatch.substring(colonIdx + 1);
    }
    return value.replace(/\\n/g, "\n").replace(/\\,/g, ",").replace(/\\\\/g, "\\").trim() || null;
  }

  function extractDateTime(prop) {
    const regex = new RegExp(`${prop}([^:]*):([^\\r\\n]+)`, "mi");
    const match = vevent.match(regex);
    if (!match) return null;
    const params = match[1];
    const value = match[2].trim();
    const tzidMatch = params.match(/TZID=([^;:]+)/i);
    return { value, tzid: tzidMatch ? tzidMatch[1] : undefined };
  }

  function parseICalDateToISO(dateStr) {
    if (dateStr.length === 8) return `${dateStr.slice(0, 4)}-${dateStr.slice(4, 6)}-${dateStr.slice(6, 8)}`;
    if (dateStr.includes("T")) {
      const base = `${dateStr.slice(0, 4)}-${dateStr.slice(4, 6)}-${dateStr.slice(6, 8)}`;
      const tp = dateStr.slice(9);
      const time = `${tp.slice(0, 2)}:${tp.slice(2, 4)}:${tp.slice(4, 6)}`;
      const tz = tp.endsWith("Z") ? "Z" : "";
      return `${base}T${time}${tz}`;
    }
    return dateStr;
  }

  function toUTCString(isoStr, tzid) {
    if (isoStr.endsWith("Z")) return isoStr;
    if (!tzid) return isoStr + "Z";
    try {
      const fakeUtc = new Date(isoStr + "Z");
      const parts = new Intl.DateTimeFormat("en-US", {
        timeZone: tzid, year: "numeric", month: "2-digit", day: "2-digit",
        hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false,
      }).formatToParts(fakeUtc);
      const get = (type) => parts.find(p => p.type === type)?.value || "0";
      const h = get("hour") === "24" ? "00" : get("hour");
      const tzLocal = new Date(`${get("year")}-${get("month")}-${get("day")}T${h}:${get("minute")}:${get("second")}Z`);
      const offsetMs = tzLocal.getTime() - fakeUtc.getTime();
      return new Date(fakeUtc.getTime() - offsetMs).toISOString();
    } catch { return isoStr + "Z"; }
  }

  const uid = extractProp("UID") || "";
  const summary = extractProp("SUMMARY") || "(Kein Titel)";
  const description = extractProp("DESCRIPTION");
  const location = extractProp("LOCATION");
  const dtstart = extractDateTime("DTSTART");
  const dtend = extractDateTime("DTEND");
  if (!dtstart) return null;

  const allDay = !dtstart.value.includes("T");
  const startISO = parseICalDateToISO(dtstart.value);
  const endISO = dtend ? parseICalDateToISO(dtend.value) : null;

  let startAt, endAt;
  if (allDay) {
    startAt = startISO + "T00:00:00Z";
    endAt = endISO ? endISO + "T00:00:00Z" : startAt;
  } else {
    startAt = toUTCString(startISO, dtstart.tzid);
    endAt = endISO ? toUTCString(endISO, dtend?.tzid) : null;
  }

  return { uid, summary, description, location, allDay, startAt, endAt };
}

// ============================================================
// XML Request Parsing
// ============================================================

function parseMultigetHrefs(xml) {
  const hrefs = [];
  // Apple Calendar sendet <A:href xmlns:A="DAV:"> statt <d:href>
  const regex = /<(?:\w+:)?href[^>]*>([^<]+)<\/(?:\w+:)?href>/gi;
  let m;
  while ((m = regex.exec(xml)) !== null) hrefs.push(m[1].trim());
  return hrefs;
}

function parseTimeRange(xml) {
  const m = xml.match(/time-range\s+start="([^"]*)"(?:\s+end="([^"]*)")?/i);
  return m ? { start: m[1], end: m[2] } : null;
}

function parseRequestedProps(xml) {
  const propMatch = xml.match(/<(?:\w+:)?prop[^>]*>([\s\S]*?)<\/(?:\w+:)?prop>/i);
  if (!propMatch) return ["allprop"];
  const props = [];
  const tagRegex = /<(?:\w+:)?(\w[\w-]*)\s*\/?>?/gi;
  let m;
  while ((m = tagRegex.exec(propMatch[1])) !== null) props.push(m[1].toLowerCase());
  return props.length > 0 ? props : ["allprop"];
}

// ============================================================
// Supabase Client
// ============================================================

function supabaseQuery(env, path, options = {}) {
  const url = `${env.SUPABASE_URL}/rest/v1/${path}`;
  const headers = {
    apikey: env.SUPABASE_SERVICE_ROLE_KEY,
    Authorization: `Bearer ${env.SUPABASE_SERVICE_ROLE_KEY}`,
    "Content-Type": "application/json",
    Prefer: options.prefer || "return=representation",
  };
  if (options.single) headers.Accept = "application/vnd.pgrst.object+json";
  return fetch(url, {
    method: options.method || "GET",
    headers,
    body: options.body ? JSON.stringify(options.body) : undefined,
  }).then(async (r) => {
    if (!r.ok) return { data: null, error: await r.text(), status: r.status };
    const text = await r.text();
    if (!text) return { data: null, error: null };
    return { data: JSON.parse(text), error: null };
  });
}

// ============================================================
// Loans (Verleih) — virtuelle CalDAV-Collection aus Bookings
// ============================================================

function simpleHash(str) {
  let h = 0;
  for (let i = 0; i < str.length; i++) {
    h = ((h << 5) - h) + str.charCodeAt(i);
    h |= 0;
  }
  return Math.abs(h).toString(36);
}

function bookingToVirtualEvent(b) {
  const itemName = b.inventory_items?.name ?? "Equipment";
  const projectTitle = b.projects?.title ?? "Projekt";
  const summary = `${b.quantity}x ${itemName} — ${projectTitle}`;
  const description = b.approval_status === "pending"
    ? "Status: Anfrage offen"
    : b.status === "checked_out" ? "Status: Ausgegeben"
    : b.status === "returned" ? "Status: Zurueck"
    : "Status: Reserviert";
  const sig = `${b.id}|${b.date_from}|${b.date_to}|${b.quantity}|${b.status}|${b.approval_status}`;
  const etag = simpleHash(sig);
  return {
    id: b.id,
    caldav_uid: `loan-${b.id}@project-prepper`,
    summary,
    description,
    location: null,
    all_day: true,
    // generateVCalendar erwartet ISO-Strings; nur Datum reicht (parseDate)
    start_at: `${b.date_from}T00:00:00Z`,
    end_at: `${b.date_to}T00:00:00Z`,
    created_at: b.created_at,
    updated_at: b.created_at,
    etag,
  };
}

async function fetchLoanEvents(env, orgId) {
  // Zeitfenster: heute -1 Monat bis +1 Jahr
  const now = new Date();
  const start = new Date(now); start.setUTCMonth(start.getUTCMonth() - 1);
  const end = new Date(now); end.setUTCFullYear(end.getUTCFullYear() + 1);
  const startStr = start.toISOString().slice(0, 10);
  const endStr = end.toISOString().slice(0, 10);

  const select = "id,quantity,date_from,date_to,status,approval_status,created_at,inventory_items(name),projects!inner(id,title,org_id)";
  const filter = `projects.org_id=eq.${orgId}&approval_status=neq.rejected&date_from=lte.${endStr}&date_to=gte.${startStr}`;
  const path = `bookings?select=${encodeURIComponent(select)}&${filter}`;

  const { data } = await supabaseQuery(env, path);
  if (!data || !Array.isArray(data)) return [];
  return data.map(bookingToVirtualEvent);
}

function loanCollectionCtag(events) {
  if (events.length === 0) return "0";
  return `${events.length}-${simpleHash(events.map(e => e.etag).join(","))}`;
}

async function handleLoansPropfindCalendar(env, auth, baseHref, depth) {
  const events = await fetchLoanEvents(env, auth.orgId);
  const ctag = loanCollectionCtag(events);
  const calHref = `${baseHref}calendars/${LOANS_GROUP_ID}/`;
  let inner = xmlResponse(calHref, calendarProps(LOANS_GROUP_NAME, LOANS_GROUP_COLOR, ctag));

  if (depth === "1") {
    for (const e of events) {
      inner += xmlResponse(`${calHref}${e.caldav_uid}.ics`, eventPropsXml(e.etag));
    }
  }
  return davResponse(multistatus(inner));
}

async function handleLoansReport(env, auth, baseHref, body) {
  const calHref = `${baseHref}calendars/${LOANS_GROUP_ID}/`;

  if (body.includes("sync-collection")) {
    return new Response(
      `${XML_HEADER}\n<d:error xmlns:d="DAV:"><d:valid-sync-token>DAV:valid-sync-token</d:valid-sync-token></d:error>`,
      { status: 403, headers: DAV_HEADERS }
    );
  }

  const events = await fetchLoanEvents(env, auth.orgId);
  const byUid = new Map(events.map(e => [e.caldav_uid, e]));

  if (body.includes("calendar-multiget")) {
    const hrefs = parseMultigetHrefs(body);
    const uids = hrefs.map(h => { const m = h.match(/\/([^/]+)\.ics$/); return m ? decodeURIComponent(m[1]) : null; }).filter(Boolean);
    let inner = "";
    for (const uid of uids) {
      const e = byUid.get(uid);
      if (e) inner += xmlResponse(`${calHref}${e.caldav_uid}.ics`, eventWithData(e.etag, generateVCalendar(e)));
    }
    return davResponse(multistatus(inner));
  }

  // calendar-query (mit optionalem time-range)
  const timeRange = parseTimeRange(body);
  const requestedProps = parseRequestedProps(body);
  const wantData = requestedProps.includes("calendar-data");

  let filtered = events;
  if (timeRange?.start || timeRange?.end) {
    const tStart = timeRange.start ? new Date(timeRange.start) : null;
    const tEnd = timeRange.end ? new Date(timeRange.end) : null;
    filtered = events.filter(e => {
      const s = new Date(e.start_at);
      if (tEnd && s > tEnd) return false;
      if (tStart && new Date(e.end_at || e.start_at) < tStart) return false;
      return true;
    });
  }

  let inner = "";
  for (const e of filtered) {
    const eventHref = `${calHref}${e.caldav_uid}.ics`;
    inner += xmlResponse(eventHref, wantData ? eventWithData(e.etag, generateVCalendar(e)) : eventPropsXml(e.etag));
  }
  return davResponse(multistatus(inner));
}

async function handleLoansGetEvent(env, auth, eventFile) {
  const uid = decodeURIComponent(eventFile.replace(/\.ics$/i, ""));
  const events = await fetchLoanEvents(env, auth.orgId);
  const event = events.find(e => e.caldav_uid === uid);
  if (!event) return new Response("Not Found", { status: 404 });
  return new Response(generateVCalendar(event), {
    status: 200,
    headers: { "Content-Type": "text/calendar; charset=utf-8", ETag: `"${event.etag}"`, DAV: "1, calendar-access" },
  });
}

// ============================================================
// Auth
// ============================================================

async function validateToken(env, token) {
  const { data } = await supabaseQuery(env,
    `calendar_feed_tokens?token=eq.${token}&select=id,org_id,profile_id,read_write&limit=1`
  );
  if (!data || data.length === 0) return null;
  const d = data[0];
  // Update last_used_at (fire and forget)
  supabaseQuery(env, `calendar_feed_tokens?id=eq.${d.id}`, {
    method: "PATCH",
    body: { last_used_at: new Date().toISOString() },
    prefer: "return=minimal",
  });
  return { orgId: d.org_id, profileId: d.profile_id, readWrite: d.read_write ?? false };
}

function extractTokenFromBasicAuth(request) {
  const auth = request.headers.get("Authorization");
  if (!auth || !auth.startsWith("Basic ")) return null;
  try {
    const decoded = atob(auth.slice(6));
    const colon = decoded.indexOf(":");
    return colon > -1 ? decoded.slice(colon + 1) : null;
  } catch { return null; }
}

function extractTokenFromPath(pathname) {
  const match = pathname.match(/\/api\/caldav\/([a-f0-9]{20,})/);
  return match ? match[1] : null;
}

// ============================================================
// Path Parsing
// ============================================================

function parsePath(pathname, token) {
  const base = `/api/caldav/${token}`;
  let rest = pathname.slice(base.length).replace(/\/+$/, "");
  if (!rest || rest === "") return { type: "principal" };
  if (rest === "/calendars") return { type: "calendar-home" };
  const calMatch = rest.match(/^\/calendars\/([^/]+)$/);
  if (calMatch) return { type: "calendar", groupId: calMatch[1] };
  const evMatch = rest.match(/^\/calendars\/([^/]+)\/(.+\.ics)$/);
  if (evMatch) return { type: "event", groupId: evMatch[1], eventFile: evMatch[2] };
  return { type: "unknown" };
}

// ============================================================
// DAV Response Helpers
// ============================================================

function davResponse(xml, status = 207) {
  return new Response(xml, { status, headers: DAV_HEADERS });
}

function unauthorized() {
  return new Response("Unauthorized", {
    status: 401,
    headers: { "WWW-Authenticate": 'Basic realm="Project Prepper CalDAV"', DAV: "1, calendar-access" },
  });
}

// ============================================================
// CalDAV Handlers
// ============================================================

async function handlePropfindPrincipal(env, auth, baseHref) {
  const { data } = await supabaseQuery(env,
    `organizations?id=eq.${auth.orgId}&select=name`, { single: true }
  );
  const displayName = data?.name || "Project Prepper";
  return davResponse(multistatus(xmlResponse(baseHref, principalProps(baseHref, displayName))));
}

async function handlePropfindCalendarHome(env, auth, baseHref, depth) {
  let inner = xmlResponse(baseHref + "calendars/", calendarHomeProps(baseHref));
  if (depth === "1") {
    const { data: groups } = await supabaseQuery(env,
      `calendar_groups?org_id=eq.${auth.orgId}&select=id,name,color,ctag&order=sort_order`
    );
    if (groups) {
      for (const g of groups) {
        inner += xmlResponse(`${baseHref}calendars/${g.id}/`, calendarProps(g.name, g.color, g.ctag ?? 1));
      }
    }
    // Verleih-Collection (virtuell, read-only)
    const loanEvents = await fetchLoanEvents(env, auth.orgId);
    const ctag = loanCollectionCtag(loanEvents);
    inner += xmlResponse(`${baseHref}calendars/${LOANS_GROUP_ID}/`, calendarProps(LOANS_GROUP_NAME, LOANS_GROUP_COLOR, ctag));
  }
  return davResponse(multistatus(inner));
}

async function handlePropfindCalendar(env, auth, groupId, baseHref, depth) {
  if (groupId === LOANS_GROUP_ID) {
    return handleLoansPropfindCalendar(env, auth, baseHref, depth);
  }
  const { data: group } = await supabaseQuery(env,
    `calendar_groups?id=eq.${groupId}&org_id=eq.${auth.orgId}&select=id,name,color,ctag`, { single: true }
  );
  if (!group) return new Response("Not Found", { status: 404 });

  const calHref = `${baseHref}calendars/${groupId}/`;
  let inner = xmlResponse(calHref, calendarProps(group.name, group.color, group.ctag ?? 1));

  if (depth === "1") {
    const { data: events } = await supabaseQuery(env,
      `calendar_events?org_id=eq.${auth.orgId}&group_id=eq.${groupId}&select=id,etag,caldav_uid`
    );
    console.log(`[CalDAV] PROPFIND calendar Depth:1 → ${events?.length ?? 0} events for group ${groupId}`);
    if (events) {
      for (const e of events) {
        inner += xmlResponse(`${calHref}${e.caldav_uid}.ics`, eventPropsXml(e.etag));
      }
    }
  }
  return davResponse(multistatus(inner));
}

async function handleReport(env, auth, groupId, baseHref, body) {
  if (groupId === LOANS_GROUP_ID) {
    return handleLoansReport(env, auth, baseHref, body);
  }
  const calHref = `${baseHref}calendars/${groupId}/`;

  // sync-collection → 403 (force CTag-based sync)
  if (body.includes("sync-collection")) {
    return new Response(
      `${XML_HEADER}\n<d:error xmlns:d="DAV:"><d:valid-sync-token>DAV:valid-sync-token</d:valid-sync-token></d:error>`,
      { status: 403, headers: DAV_HEADERS }
    );
  }

  // calendar-multiget
  if (body.includes("calendar-multiget")) {
    console.log(`[CalDAV] REPORT body (first 500): ${body.slice(0, 500)}`);
    const hrefs = parseMultigetHrefs(body);
    const uids = hrefs.map(h => { const m = h.match(/\/([^/]+)\.ics$/); return m ? decodeURIComponent(m[1]) : null; }).filter(Boolean);
    console.log(`[CalDAV] REPORT multiget: ${hrefs.length} hrefs, ${uids.length} UIDs: ${uids.slice(0,3).join(", ")}${uids.length > 3 ? "..." : ""}`);
    if (uids.length === 0) return davResponse(multistatus(""));

    const { data: events } = await supabaseQuery(env,
      `calendar_events?org_id=eq.${auth.orgId}&group_id=eq.${groupId}&caldav_uid=in.(${uids.join(",")})&select=id,org_id,group_id,summary,description,location,all_day,start_at,end_at,created_at,updated_at,etag,caldav_uid`
    );
    console.log(`[CalDAV] REPORT multiget: ${events?.length ?? 0} events found in DB`);
    let inner = "";
    if (events) {
      for (const e of events) {
        inner += xmlResponse(`${calHref}${e.caldav_uid}.ics`, eventWithData(e.etag, generateVCalendar(e)));
      }
    }
    return davResponse(multistatus(inner));
  }

  // calendar-query
  const timeRange = parseTimeRange(body);
  const requestedProps = parseRequestedProps(body);
  const wantData = requestedProps.includes("calendar-data");

  let filter = `org_id=eq.${auth.orgId}&group_id=eq.${groupId}`;
  if (timeRange?.start) filter += `&start_at=gte.${timeRange.start}`;
  if (timeRange?.end) filter += `&start_at=lte.${timeRange.end}`;
  console.log(`[CalDAV] REPORT calendar-query: timeRange=${JSON.stringify(timeRange)} wantData=${wantData} filter=${filter}`);

  const { data: events } = await supabaseQuery(env,
    `calendar_events?${filter}&select=id,org_id,group_id,summary,description,location,all_day,start_at,end_at,created_at,updated_at,etag,caldav_uid`
  );
  console.log(`[CalDAV] REPORT calendar-query: ${events?.length ?? 0} events found`);

  let inner = "";
  if (events) {
    for (const e of events) {
      const eventHref = `${calHref}${e.caldav_uid}.ics`;
      inner += xmlResponse(eventHref, wantData ? eventWithData(e.etag, generateVCalendar(e)) : eventPropsXml(e.etag));
    }
  }
  return davResponse(multistatus(inner));
}

async function handleGetEvent(env, auth, groupId, eventFile) {
  if (groupId === LOANS_GROUP_ID) {
    return handleLoansGetEvent(env, auth, eventFile);
  }
  const uid = decodeURIComponent(eventFile.replace(/\.ics$/i, ""));
  const { data: event } = await supabaseQuery(env,
    `calendar_events?org_id=eq.${auth.orgId}&group_id=eq.${groupId}&caldav_uid=eq.${uid}&select=*`,
    { single: true }
  );
  if (!event) return new Response("Not Found", { status: 404 });
  return new Response(generateVCalendar(event), {
    status: 200,
    headers: { "Content-Type": "text/calendar; charset=utf-8", ETag: `"${event.etag}"`, DAV: "1, calendar-access" },
  });
}

async function handlePutEvent(env, auth, groupId, eventFile, icalBody, ifMatch, baseHref) {
  const uid = decodeURIComponent(eventFile.replace(/\.ics$/i, ""));
  const parsed = parseVEvent(icalBody);
  if (!parsed) return new Response("Bad Request — invalid iCalendar data", { status: 400 });

  // Check existing
  const { data: existingArr } = await supabaseQuery(env,
    `calendar_events?org_id=eq.${auth.orgId}&caldav_uid=eq.${uid}&select=id,etag`
  );
  const existing = existingArr && existingArr.length > 0 ? existingArr[0] : null;

  if (existing) {
    if (ifMatch) {
      const cleanEtag = ifMatch.replace(/"/g, "");
      if (cleanEtag !== existing.etag) return new Response("Precondition Failed", { status: 412 });
    }
    const { data: updated, error } = await supabaseQuery(env,
      `calendar_events?id=eq.${existing.id}`, {
        method: "PATCH",
        body: {
          summary: parsed.summary, description: parsed.description, location: parsed.location,
          all_day: parsed.allDay, start_at: parsed.startAt, end_at: parsed.endAt,
          group_id: groupId, updated_at: new Date().toISOString(),
        },
      }
    );
    if (error) return new Response(`Server Error: ${error}`, { status: 500 });
    const etag = updated && updated[0] ? updated[0].etag : existing.etag;
    return new Response(null, { status: 204, headers: { ETag: `"${etag}"`, DAV: "1, calendar-access" } });
  } else {
    if (ifMatch && ifMatch !== "*") return new Response("Precondition Failed", { status: 412 });
    const { data: created, error } = await supabaseQuery(env,
      `calendar_events`, {
        method: "POST",
        body: {
          org_id: auth.orgId, group_id: groupId, caldav_uid: uid,
          summary: parsed.summary, description: parsed.description, location: parsed.location,
          all_day: parsed.allDay, start_at: parsed.startAt, end_at: parsed.endAt,
          created_by: auth.profileId,
        },
      }
    );
    if (error) return new Response(`Server Error: ${error}`, { status: 500 });
    const etag = created && created[0] ? created[0].etag : "new";
    return new Response(null, {
      status: 201,
      headers: { ETag: `"${etag}"`, Location: `${baseHref}calendars/${groupId}/${uid}.ics`, DAV: "1, calendar-access" },
    });
  }
}

async function handleDeleteEvent(env, auth, groupId, eventFile) {
  const uid = decodeURIComponent(eventFile.replace(/\.ics$/i, ""));
  const { data, error } = await supabaseQuery(env,
    `calendar_events?org_id=eq.${auth.orgId}&group_id=eq.${groupId}&caldav_uid=eq.${uid}`, {
      method: "DELETE", prefer: "return=representation",
    }
  );
  if (error) return new Response(`Server Error: ${error}`, { status: 500 });
  if (!data || data.length === 0) return new Response("Not Found", { status: 404 });
  return new Response(null, { status: 204, headers: { DAV: "1, calendar-access" } });
}

// ============================================================
// Main Router
// ============================================================

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const method = request.method.toUpperCase();
    const pathname = url.pathname;
    const depth = request.headers.get("Depth") || "0";

    // OPTIONS
    if (method === "OPTIONS") {
      return new Response(null, {
        status: 200,
        headers: {
          Allow: "OPTIONS, GET, PUT, DELETE, PROPFIND, PROPPATCH, REPORT",
          DAV: "1, calendar-access",
          "Content-Length": "0",
        },
      });
    }

    // .well-known/caldav Discovery
    // Apple Calendar erwartet 301 Redirect → folgt dem Redirect zum Principal
    if (pathname === "/.well-known/caldav" || pathname === "/.well-known/caldav/") {
      const token = extractTokenFromBasicAuth(request);
      if (!token) return unauthorized();
      const auth = await validateToken(env, token);
      if (!auth) return unauthorized();
      const location = `/api/caldav/${token}/`;
      console.log(`[CalDAV] ${method} well-known → 301 ${location}`);
      return new Response(null, {
        status: 301,
        headers: { Location: location, DAV: "1, calendar-access" },
      });
    }

    // Root PROPFIND → redirect to principal
    if ((pathname === "/" || pathname === "") && (method === "PROPFIND" || method === "REPORT")) {
      const token = extractTokenFromBasicAuth(request);
      if (!token) return unauthorized();
      const auth = await validateToken(env, token);
      if (!auth) return unauthorized();
      const location = `/api/caldav/${token}/`;
      console.log(`[CalDAV] ${method} root → 301 ${location}`);
      return new Response(null, {
        status: 301,
        headers: { Location: location, DAV: "1, calendar-access" },
      });
    }

    // Extract token
    let token = extractTokenFromPath(pathname);
    if (!token) {
      token = extractTokenFromBasicAuth(request);
      if (!token) return unauthorized();
    }

    // Validate token
    const auth = await validateToken(env, token);
    if (!auth) return unauthorized();

    const parsed = parsePath(pathname, token);
    const baseHref = `/api/caldav/${token}/`;

    console.log(`[CalDAV] ${method} ${parsed.type} Depth:${depth}`);

    switch (method) {
      case "PROPFIND":
        switch (parsed.type) {
          case "principal": return handlePropfindPrincipal(env, auth, baseHref);
          case "calendar-home": return handlePropfindCalendarHome(env, auth, baseHref, depth);
          case "calendar": return handlePropfindCalendar(env, auth, parsed.groupId, baseHref, depth);
          case "event": return handleGetEvent(env, auth, parsed.groupId, parsed.eventFile);
          default: return new Response("Not Found", { status: 404, headers: { DAV: "1, calendar-access" } });
        }

      case "REPORT": {
        const body = await request.text();
        if (parsed.type === "calendar") return handleReport(env, auth, parsed.groupId, baseHref, body);
        return new Response("Bad Request", { status: 400, headers: { DAV: "1, calendar-access" } });
      }

      case "GET":
        if (parsed.type === "event") return handleGetEvent(env, auth, parsed.groupId, parsed.eventFile);
        return new Response("CalDAV Server — Project Prepper", { status: 200, headers: { "Content-Type": "text/plain", DAV: "1, calendar-access" } });

      case "PUT": {
        if (!auth.readWrite) return new Response("Forbidden — read-only token", { status: 403 });
        if (parsed.type !== "event") return new Response("Bad Request", { status: 400 });
        if (parsed.groupId === LOANS_GROUP_ID) return new Response("Forbidden — Verleih ist read-only", { status: 403 });
        const body = await request.text();
        const ifMatch = request.headers.get("If-Match");
        return handlePutEvent(env, auth, parsed.groupId, parsed.eventFile, body, ifMatch, baseHref);
      }

      case "DELETE":
        if (!auth.readWrite) return new Response("Forbidden", { status: 403 });
        if (parsed.type !== "event") return new Response("Bad Request", { status: 400 });
        if (parsed.groupId === LOANS_GROUP_ID) return new Response("Forbidden — Verleih ist read-only", { status: 403 });
        return handleDeleteEvent(env, auth, parsed.groupId, parsed.eventFile);

      case "PROPPATCH": {
        // Apple Calendar sendet PROPPATCH um Kalender-Farben etc zu setzen
        // Wir ignorieren die Änderungen, antworten aber mit 207 OK
        const href = `/api/caldav/${token}/${parsed.type === "calendar" ? `calendars/${parsed.groupId}/` : ""}`;
        return davResponse(`<?xml version="1.0" encoding="utf-8"?>\n<d:multistatus xmlns:d="DAV:">\n<d:response>\n<d:href>${href}</d:href>\n<d:propstat>\n<d:prop/>\n<d:status>HTTP/1.1 200 OK</d:status>\n</d:propstat>\n</d:response>\n</d:multistatus>`);
      }

      default:
        return new Response(`Method ${method} not supported`, { status: 405, headers: { DAV: "1, calendar-access" } });
    }
  },
};
