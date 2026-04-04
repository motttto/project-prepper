import { NextRequest } from "next/server";

// .well-known/caldav — CalDAV Service Discovery (RFC 6764)
// Redirects to the CalDAV base URL. Client muss Token kennen.
export async function GET(_req: NextRequest) {
  return new Response(null, {
    status: 301,
    headers: { Location: "/api/caldav/" },
  });
}

// PROPFIND auf .well-known/caldav wird auch von manchen Clients gesendet
export async function PROPFIND(_req: NextRequest) {
  return new Response(null, {
    status: 301,
    headers: { Location: "/api/caldav/" },
  });
}
