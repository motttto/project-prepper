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

    // === OPTIONS: direkt beantworten ===
    if (method === "OPTIONS") {
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
        return new Response("Unauthorized", {
          status: 401,
          headers: { "WWW-Authenticate": 'Basic realm="Project Prepper CalDAV"' },
        });
      }
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
        return new Response("Unauthorized", {
          status: 401,
          headers: { "WWW-Authenticate": 'Basic realm="Project Prepper CalDAV"' },
        });
      }
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
    const body = ["GET", "HEAD"].includes(method) ? null : await request.arrayBuffer();

    // Headers kopieren
    const headers = new Headers(request.headers);
    if (needsTunnel) {
      headers.set("X-HTTP-Method", method);
    }
    // Depth als Backup-Header (falls Vercel es strippt)
    const depth = request.headers.get("Depth");
    if (depth) {
      headers.set("X-Depth", depth);
    }

    // An Vercel weiterleiten
    const targetUrl = VERCEL_ORIGIN + url.pathname + url.search;

    const response = await fetch(targetUrl, {
      method: targetMethod,
      headers,
      body,
      redirect: "follow",
    });

    // Response durchreichen mit DAV Headers
    const respHeaders = new Headers(response.headers);
    respHeaders.set("DAV", "1, calendar-access");

    return new Response(response.body, {
      status: response.status,
      statusText: response.statusText,
      headers: respHeaders,
    });
  },
};
