/**
 * Cloudflare Worker — CalDAV Proxy für Vercel
 *
 * Problem: Vercel blockt PROPFIND/REPORT (non-standard HTTP Methoden).
 * Lösung: Dieser Worker empfängt alle CalDAV-Requests von Apple Calendar etc.
 *         und leitet sie als POST mit X-HTTP-Method Header an Vercel weiter.
 *
 * Deployment:
 *   npx wrangler deploy
 *
 * Danach in Apple Calendar die Worker-URL als CalDAV-Server eintragen.
 */

const VERCEL_ORIGIN = "https://project-prepper.vercel.app";

export default {
  async fetch(request) {
    const url = new URL(request.url);
    const method = request.method.toUpperCase();

    // CORS Preflight
    if (method === "OPTIONS") {
      return new Response(null, {
        status: 200,
        headers: {
          Allow: "OPTIONS, GET, POST, PUT, DELETE, PROPFIND, REPORT",
          DAV: "1, calendar-access",
          "Access-Control-Allow-Origin": "*",
          "Access-Control-Allow-Methods": "OPTIONS, GET, POST, PUT, DELETE, PROPFIND, REPORT",
          "Access-Control-Allow-Headers": "Content-Type, Depth, Authorization, If-Match, If-None-Match, X-HTTP-Method",
          "Content-Length": "0",
        },
      });
    }

    // Standard-Methoden (GET, PUT, DELETE) direkt durchleiten
    // Non-Standard (PROPFIND, REPORT) → POST + X-HTTP-Method Header
    const needsTunnel = method === "PROPFIND" || method === "REPORT";
    const targetMethod = needsTunnel ? "POST" : method;

    // Request-Body lesen (falls vorhanden)
    const body = ["GET", "HEAD"].includes(method) ? null : await request.arrayBuffer();

    // Headers kopieren + Method-Override setzen
    const headers = new Headers(request.headers);
    if (needsTunnel) {
      headers.set("X-HTTP-Method", method);
    }
    // Depth Header als X-Depth kopieren (falls Vercel es strippt)
    const depth = request.headers.get("Depth");
    if (depth) {
      headers.set("X-Depth", depth);
    }

    // An Vercel weiterleiten (gleicher Pfad)
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
    // CORS für Browser-Tests
    respHeaders.set("Access-Control-Allow-Origin", "*");

    return new Response(response.body, {
      status: response.status,
      statusText: response.statusText,
      headers: respHeaders,
    });
  },
};
