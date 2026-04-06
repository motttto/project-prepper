/**
 * Cloudflare Worker — CalDAV Proxy für Vercel
 *
 * Problem: Vercel blockt PROPFIND/REPORT (non-standard HTTP Methoden).
 * Lösung: Dieser Worker empfängt alle CalDAV-Requests von Apple Calendar etc.
 *         und leitet sie als POST mit X-HTTP-Method Header an Vercel weiter.
 *
 * Zusätzlich: Handles .well-known/caldav Discovery und extrahiert den Token
 * aus Basic Auth für die URL-Konstruktion.
 */

const VERCEL_ORIGIN = "https://project-prepper.vercel.app";

// In-Memory Request-Log (letzte 50 Requests)
const requestLog = [];
const MAX_LOG = 50;

function logRequest(method, path, depth, status, extra = "") {
  const entry = {
    ts: new Date().toISOString(),
    method,
    path,
    depth: depth || "-",
    status,
    extra,
  };
  requestLog.push(entry);
  if (requestLog.length > MAX_LOG) requestLog.shift();
  console.log(`[CalDAV] ${method} ${path} Depth:${entry.depth} → ${status} ${extra}`);
}

/**
 * Extrahiert den Token aus dem Basic Auth Header.
 * Apple Calendar sendet: Authorization: Basic base64(username:password)
 * Wir nutzen das Passwort als Token.
 */
function extractTokenFromBasicAuth(request) {
  const auth = request.headers.get("Authorization");
  if (!auth || !auth.startsWith("Basic ")) return null;
  try {
    const decoded = atob(auth.slice(6));
    const colon = decoded.indexOf(":");
    return colon > -1 ? decoded.slice(colon + 1) : null;
  } catch {
    return null;
  }
}

/**
 * Extrahiert den Token aus dem URL-Pfad: /api/caldav/{token}/...
 */
function extractTokenFromPath(pathname) {
  const match = pathname.match(/\/api\/caldav\/([^/]+)/);
  return match ? match[1] : null;
}

export default {
  async fetch(request) {
    const url = new URL(request.url);
    const method = request.method.toUpperCase();
    const pathname = url.pathname;
    const depth = request.headers.get("Depth") || "-";

    // === Debug Log Endpoint ===
    if (pathname === "/debug/log" && method === "GET") {
      return new Response(JSON.stringify(requestLog, null, 2), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      });
    }

    // === OPTIONS: direkt beantworten ===
    if (method === "OPTIONS") {
      logRequest(method, pathname, depth, 200);
      return new Response(null, {
        status: 200,
        headers: {
          Allow: "OPTIONS, GET, POST, PUT, DELETE, PROPFIND, REPORT",
          DAV: "1, calendar-access",
          "Access-Control-Allow-Origin": "*",
          "Access-Control-Allow-Methods": "OPTIONS, GET, POST, PUT, DELETE, PROPFIND, REPORT",
          "Access-Control-Allow-Headers": "Content-Type, Depth, Authorization, If-Match, If-None-Match",
          "Content-Length": "0",
        },
      });
    }

    // === .well-known/caldav Discovery ===
    // Apple Calendar fragt hier zuerst an. Wir leiten auf /api/caldav/{token}/ um.
    if (pathname === "/.well-known/caldav" || pathname === "/.well-known/caldav/") {
      const token = extractTokenFromBasicAuth(request);
      if (!token) {
        logRequest(method, pathname, depth, 401, "no-auth");
        return new Response("Unauthorized", {
          status: 401,
          headers: { "WWW-Authenticate": 'Basic realm="Project Prepper CalDAV"' },
        });
      }
      logRequest(method, pathname, depth, 301, "→ principal");
      // 301 Redirect zum Principal
      return new Response(null, {
        status: 301,
        headers: {
          Location: `/api/caldav/${token}/`,
          DAV: "1, calendar-access",
        },
      });
    }

    // === Root PROPFIND: Apple Calendar macht das manchmal ===
    if ((pathname === "/" || pathname === "") && (method === "PROPFIND" || method === "REPORT")) {
      const token = extractTokenFromBasicAuth(request);
      if (!token) {
        logRequest(method, pathname, depth, 401, "no-auth");
        return new Response("Unauthorized", {
          status: 401,
          headers: { "WWW-Authenticate": 'Basic realm="Project Prepper CalDAV"' },
        });
      }
      logRequest(method, pathname, depth, 301, "→ principal");
      // Redirect zum Principal
      return new Response(null, {
        status: 301,
        headers: {
          Location: `/api/caldav/${token}/`,
          DAV: "1, calendar-access",
        },
      });
    }

    // === Reguläre CalDAV-Requests an Vercel weiterleiten ===

    // Token muss entweder im Pfad oder im Basic Auth sein
    let token = extractTokenFromPath(pathname);
    if (!token) {
      token = extractTokenFromBasicAuth(request);
      if (!token) {
        logRequest(method, pathname, depth, 401, "no-token");
        return new Response("Unauthorized", {
          status: 401,
          headers: { "WWW-Authenticate": 'Basic realm="Project Prepper CalDAV"' },
        });
      }
    }

    // Non-Standard Methoden → POST + X-HTTP-Method Header
    const needsTunnel = method === "PROPFIND" || method === "REPORT";
    const targetMethod = needsTunnel ? "POST" : method;

    // Request-Body lesen
    const bodyBuf = ["GET", "HEAD"].includes(method) ? null : await request.arrayBuffer();

    // Body-Snippet für Debug
    let bodySnippet = "";
    if (bodyBuf && bodyBuf.byteLength > 0) {
      bodySnippet = new TextDecoder().decode(bodyBuf.slice(0, 200));
    }

    // Headers für Vercel vorbereiten
    const headers = new Headers();
    // Nur relevante Headers kopieren (NICHT Host, der muss zu Vercel passen)
    const copyHeaders = ["content-type", "authorization", "depth", "if-match", "if-none-match", "prefer"];
    for (const h of copyHeaders) {
      const val = request.headers.get(h);
      if (val) headers.set(h, val);
    }
    if (needsTunnel) {
      headers.set("X-HTTP-Method", method);
    }
    // Depth als Backup-Header (falls Vercel es strippt)
    const origDepth = request.headers.get("Depth");
    if (origDepth) {
      headers.set("X-Depth", origDepth);
    }
    // Host muss zum Ziel passen
    headers.set("Host", "project-prepper.vercel.app");

    // An Vercel weiterleiten — Trailing Slash entfernen (Vercel 308-Redirect vermeiden)
    let targetPath = url.pathname;
    if (targetPath.length > 1 && targetPath.endsWith("/")) {
      targetPath = targetPath.slice(0, -1);
    }
    const targetUrl = VERCEL_ORIGIN + targetPath + url.search;

    const response = await fetch(targetUrl, {
      method: targetMethod,
      headers,
      body: bodyBuf,
      redirect: "manual",
    });

    // Kurzen Pfad für Log (Token maskieren)
    const shortPath = pathname.replace(/\/api\/caldav\/[^/]+/, "/api/caldav/***");
    logRequest(method, shortPath, depth, response.status, bodySnippet.includes("sync-collection") ? "sync-collection" : bodySnippet.includes("calendar-multiget") ? "multiget" : bodySnippet.includes("calendar-query") ? "cal-query" : "");

    // Response mit sauberen Headers (keine Vercel-Header leaken)
    const respHeaders = new Headers();
    respHeaders.set("Content-Type", response.headers.get("Content-Type") || "application/xml; charset=utf-8");
    respHeaders.set("DAV", "1, calendar-access");
    const etag = response.headers.get("ETag");
    if (etag) respHeaders.set("ETag", etag);
    const loc = response.headers.get("Location");
    if (loc) respHeaders.set("Location", loc);

    return new Response(response.body, {
      status: response.status,
      headers: respHeaders,
    });
  },
};
