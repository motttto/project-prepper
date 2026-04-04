/**
 * CalDAV Catch-All Route Handler
 *
 * URL-Struktur:
 *   /api/caldav/{token}/                          → Principal (PROPFIND)
 *   /api/caldav/{token}/calendars/                → Calendar Home (PROPFIND)
 *   /api/caldav/{token}/calendars/{groupId}/      → Calendar Collection (PROPFIND, REPORT)
 *   /api/caldav/{token}/calendars/{gId}/{eId}.ics → Event (GET, PUT, DELETE)
 */

import { NextRequest } from "next/server";
import { validateCalDavToken, unauthorizedResponse, type CalDavAuth } from "@/lib/caldav/auth";
import {
  handleOptions,
  handlePropfindPrincipal,
  handlePropfindCalendarHome,
  handlePropfindCalendar,
  handleReport,
  handleGetEvent,
  handlePutEvent,
  handleDeleteEvent,
} from "@/lib/caldav/handlers";

// ============================================================
// Path parsing
// ============================================================

type ParsedPath =
  | { type: "principal" }
  | { type: "calendar-home" }
  | { type: "calendar"; groupId: string }
  | { type: "event"; groupId: string; eventFile: string };

function parsePath(segments: string[] | undefined): ParsedPath {
  if (!segments || segments.length === 0) {
    return { type: "principal" };
  }

  // ["calendars"]
  if (segments.length === 1 && segments[0] === "calendars") {
    return { type: "calendar-home" };
  }

  // ["calendars", "{groupId}"]
  if (segments.length === 2 && segments[0] === "calendars") {
    return { type: "calendar", groupId: segments[1] };
  }

  // ["calendars", "{groupId}", "{eventId}.ics"]
  if (segments.length === 3 && segments[0] === "calendars") {
    return { type: "event", groupId: segments[1], eventFile: segments[2] };
  }

  return { type: "principal" }; // Fallback
}

// ============================================================
// Auth helper — Token aus URL + optional Basic Auth
// ============================================================

async function authenticate(
  req: NextRequest,
  tokenParam: string
): Promise<CalDavAuth | null> {
  // Primär: Token aus URL
  let auth = await validateCalDavToken(tokenParam);
  if (auth) return auth;

  // Fallback: HTTP Basic Auth (Password = Token)
  const authHeader = req.headers.get("authorization");
  if (authHeader?.startsWith("Basic ")) {
    try {
      const decoded = atob(authHeader.slice(6));
      const password = decoded.split(":")[1]; // username:password
      if (password) {
        auth = await validateCalDavToken(password);
      }
    } catch {
      // Invalid base64
    }
  }

  return auth;
}

// ============================================================
// DAV Headers (für alle Responses)
// ============================================================

function davHeaders(extra?: Record<string, string>): Record<string, string> {
  return {
    DAV: "1, calendar-access",
    "Content-Type": "application/xml; charset=utf-8",
    ...extra,
  };
}

// ============================================================
// Route Handlers
// ============================================================

export async function OPTIONS(
  req: NextRequest,
  { params }: { params: Promise<{ token: string; path?: string[] }> }
) {
  const { token } = await params;
  const auth = await authenticate(req, token);
  if (!auth) return unauthorizedResponse();

  return handleOptions();
}

export async function GET(
  req: NextRequest,
  { params }: { params: Promise<{ token: string; path?: string[] }> }
) {
  const { token, path } = await params;
  const auth = await authenticate(req, token);
  if (!auth) return unauthorizedResponse();

  const parsed = parsePath(path);
  const baseHref = `/api/caldav/${token}/`;

  if (parsed.type === "event") {
    return handleGetEvent(auth, parsed.groupId, parsed.eventFile, baseHref);
  }

  // GET auf andere Pfade → leere 200 (manche Clients machen das)
  return new Response("CalDAV Server — Project Prepper", {
    status: 200,
    headers: davHeaders({ "Content-Type": "text/plain" }),
  });
}

export async function PROPFIND(
  req: NextRequest,
  { params }: { params: Promise<{ token: string; path?: string[] }> }
) {
  const { token, path } = await params;
  const auth = await authenticate(req, token);
  if (!auth) return unauthorizedResponse();

  const parsed = parsePath(path);
  const baseHref = `/api/caldav/${token}/`;
  const body = await req.text();
  const depth = req.headers.get("depth") || "0";

  switch (parsed.type) {
    case "principal":
      return handlePropfindPrincipal(auth, baseHref, body, depth);
    case "calendar-home":
      return handlePropfindCalendarHome(auth, baseHref, body, depth);
    case "calendar":
      return handlePropfindCalendar(auth, parsed.groupId, baseHref, body, depth);
    case "event":
      return handleGetEvent(auth, parsed.groupId, parsed.eventFile, baseHref);
    default:
      return new Response("Not Found", { status: 404 });
  }
}

export async function REPORT(
  req: NextRequest,
  { params }: { params: Promise<{ token: string; path?: string[] }> }
) {
  const { token, path } = await params;
  const auth = await authenticate(req, token);
  if (!auth) return unauthorizedResponse();

  const parsed = parsePath(path);
  const baseHref = `/api/caldav/${token}/`;
  const body = await req.text();

  if (parsed.type === "calendar") {
    return handleReport(auth, parsed.groupId, baseHref, body);
  }

  return new Response("Bad Request", { status: 400 });
}

export async function PUT(
  req: NextRequest,
  { params }: { params: Promise<{ token: string; path?: string[] }> }
) {
  const { token, path } = await params;
  const auth = await authenticate(req, token);
  if (!auth) return unauthorizedResponse();

  if (!auth.readWrite) {
    return new Response("Forbidden — read-only token", { status: 403 });
  }

  const parsed = parsePath(path);
  if (parsed.type !== "event") {
    return new Response("Bad Request", { status: 400 });
  }

  const body = await req.text();
  const ifMatch = req.headers.get("if-match");

  return handlePutEvent(auth, parsed.groupId, parsed.eventFile, body, ifMatch, `/api/caldav/${token}/`);
}

export async function DELETE(
  req: NextRequest,
  { params }: { params: Promise<{ token: string; path?: string[] }> }
) {
  const { token, path } = await params;
  const auth = await authenticate(req, token);
  if (!auth) return unauthorizedResponse();

  if (!auth.readWrite) {
    return new Response("Forbidden — read-only token", { status: 403 });
  }

  const parsed = parsePath(path);
  if (parsed.type !== "event") {
    return new Response("Bad Request", { status: 400 });
  }

  return handleDeleteEvent(auth, parsed.groupId, parsed.eventFile);
}
