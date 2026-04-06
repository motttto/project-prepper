/**
 * CalDAV Catch-All Route Handler
 *
 * Vercel blockt non-standard HTTP Methoden (PROPFIND, REPORT) auf CDN-Level.
 * Lösung: Cloudflare Worker als Proxy, der PROPFIND/REPORT → POST + X-HTTP-Method Header umschreibt.
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
// Zentraler Dispatcher — verarbeitet die echte CalDAV-Methode
// ============================================================

async function dispatch(
  method: string,
  req: NextRequest,
  token: string,
  path: string[] | undefined
): Promise<Response> {
  const auth = await authenticate(req, token);
  if (!auth) return unauthorizedResponse();

  const parsed = parsePath(path);
  const baseHref = `/api/caldav/${token}/`;
  const pathStr = path?.join("/") || "/";
  const depthHeader = req.headers.get("depth") || req.headers.get("x-depth") || "-";

  // Log-Wrapper
  const log = (resp: Response, extra = "") => {
    console.log(`[CalDAV] ${method} /${pathStr} Depth:${depthHeader} → ${resp.status}${extra ? " " + extra : ""}`);
    return resp;
  };

  switch (method) {
    case "OPTIONS":
      return log(handleOptions());

    case "PROPFIND": {
      const body = await req.text();
      const depth = req.headers.get("depth") || req.headers.get("x-depth") || "0";
      switch (parsed.type) {
        case "principal":
          return log(await handlePropfindPrincipal(auth, baseHref, body, depth), `type=principal`);
        case "calendar-home":
          return log(await handlePropfindCalendarHome(auth, baseHref, body, depth), `type=calendar-home`);
        case "calendar":
          return log(await handlePropfindCalendar(auth, parsed.groupId, baseHref, body, depth), `type=calendar`);
        case "event":
          return log(await handleGetEvent(auth, parsed.groupId, parsed.eventFile, baseHref), `type=event`);
        default:
          return log(new Response("Not Found", { status: 404 }));
      }
    }

    case "REPORT": {
      const body = await req.text();
      const reportType = body.includes("sync-collection") ? "sync-collection" : body.includes("calendar-multiget") ? "multiget" : "calendar-query";
      if (parsed.type === "calendar") {
        return log(await handleReport(auth, parsed.groupId, baseHref, body), `report=${reportType}`);
      }
      return log(new Response("Bad Request", { status: 400 }), `report=${reportType}`);
    }

    case "GET": {
      if (parsed.type === "event") {
        return log(await handleGetEvent(auth, parsed.groupId, parsed.eventFile, baseHref));
      }
      return log(new Response("CalDAV Server — Project Prepper", {
        status: 200,
        headers: { "Content-Type": "text/plain", DAV: "1, calendar-access" },
      }));
    }

    case "PUT": {
      if (!auth.readWrite) {
        return log(new Response("Forbidden — read-only token", { status: 403 }));
      }
      if (parsed.type !== "event") {
        return log(new Response("Bad Request", { status: 400 }));
      }
      const body = await req.text();
      const ifMatch = req.headers.get("if-match");
      return log(await handlePutEvent(auth, parsed.groupId, parsed.eventFile, body, ifMatch, baseHref));
    }

    case "DELETE": {
      if (!auth.readWrite) {
        return log(new Response("Forbidden — read-only token", { status: 403 }));
      }
      if (parsed.type !== "event") {
        return log(new Response("Bad Request", { status: 400 }));
      }
      return log(await handleDeleteEvent(auth, parsed.groupId, parsed.eventFile));
    }

    default:
      return log(new Response(`Method ${method} not supported`, { status: 405 }));
  }
}

// ============================================================
// Route Handlers
// ============================================================

// POST = Tunnel für PROPFIND/REPORT (via Cloudflare Worker Proxy)
// Der Proxy setzt X-HTTP-Method auf die echte Methode
export async function POST(
  req: NextRequest,
  { params }: { params: Promise<{ token: string; path?: string[] }> }
) {
  const { token, path } = await params;
  const realMethod = req.headers.get("x-http-method") || "POST";
  return dispatch(realMethod.toUpperCase(), req, token, path);
}

// Native Methoden — funktionieren lokal, nicht auf Vercel
export async function OPTIONS(
  req: NextRequest,
  { params }: { params: Promise<{ token: string; path?: string[] }> }
) {
  const { token, path } = await params;
  return dispatch("OPTIONS", req, token, path);
}

export async function GET(
  req: NextRequest,
  { params }: { params: Promise<{ token: string; path?: string[] }> }
) {
  const { token, path } = await params;
  return dispatch("GET", req, token, path);
}

export async function PUT(
  req: NextRequest,
  { params }: { params: Promise<{ token: string; path?: string[] }> }
) {
  const { token, path } = await params;
  return dispatch("PUT", req, token, path);
}

export async function DELETE(
  req: NextRequest,
  { params }: { params: Promise<{ token: string; path?: string[] }> }
) {
  const { token, path } = await params;
  return dispatch("DELETE", req, token, path);
}
