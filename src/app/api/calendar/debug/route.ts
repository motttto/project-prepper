import { NextResponse, type NextRequest } from "next/server";
import { createServerSupabaseClient } from "@/lib/supabase-server";
import { createServiceClient } from "@/lib/supabase-service";
import { generateVCalendar } from "@/lib/caldav/ical";

/**
 * GET /api/calendar/debug?org_id=xxx
 * Debug-Endpoint fuer CalDAV-Diagnose. Nur fuer Admins.
 *
 * POST /api/calendar/debug?org_id=xxx&action=bump_ctags
 * Force-Bump aller CTags (erzwingt Apple Calendar Re-Sync)
 */

export async function GET(request: NextRequest) {
  const supabase = await createServerSupabaseClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) return NextResponse.json({ error: "Unauthorized" }, { status: 401 });

  const { searchParams } = new URL(request.url);
  const orgId = searchParams.get("org_id");
  if (!orgId) return NextResponse.json({ error: "org_id required" }, { status: 400 });

  const service = createServiceClient();

  // 1. Gruppen + CTags
  const { data: groups } = await service
    .from("calendar_groups")
    .select("id, name, color, ctag")
    .eq("org_id", orgId)
    .order("sort_order");

  // 2. Letzte 5 Events
  const { data: events } = await service
    .from("calendar_events")
    .select("id, summary, start_at, end_at, all_day, group_id, caldav_uid, etag, created_at")
    .eq("org_id", orgId)
    .order("created_at", { ascending: false })
    .limit(5);

  // 3. Tokens
  const { data: tokens } = await service
    .from("calendar_feed_tokens")
    .select("id, token, read_write, created_at")
    .eq("org_id", orgId);

  // 4. iCal-Output des neuesten Events
  let icalSample = "";
  if (events && events.length > 0) {
    const e = events[0];
    icalSample = generateVCalendar({
      caldav_uid: e.caldav_uid,
      summary: e.summary,
      all_day: e.all_day,
      start_at: e.start_at,
      end_at: e.end_at,
      created_at: e.created_at,
    });
  }

  // 5. Test CalDAV GET (server-seitig)
  let caldavGetTest = null;
  if (events && events.length > 0 && tokens) {
    const rwToken = tokens.find(t => t.read_write);
    const ev = events[0];
    if (rwToken && ev.group_id) {
      const testUrl = `${process.env.NEXT_PUBLIC_SUPABASE_URL ? "" : ""}https://${request.headers.get("host")}/api/caldav/${rwToken.token}/calendars/${ev.group_id}/${ev.caldav_uid}.ics`;
      try {
        const res = await fetch(testUrl);
        caldavGetTest = {
          url: testUrl.replace(rwToken.token, "***"),
          status: res.status,
          body: res.ok ? (await res.text()).substring(0, 500) : await res.text(),
        };
      } catch (err: any) {
        caldavGetTest = { url: testUrl.replace(rwToken.token, "***"), status: 0, body: err.message };
      }
    }
  }

  return NextResponse.json({
    groups: groups || [],
    recentEvents: events || [],
    tokens: (tokens || []).map(t => ({ ...t, token: t.token.substring(0, 8) + "***" })),
    icalSample,
    caldavGetTest,
  });
}

export async function POST(request: NextRequest) {
  const supabase = await createServerSupabaseClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) return NextResponse.json({ error: "Unauthorized" }, { status: 401 });

  const { searchParams } = new URL(request.url);
  const orgId = searchParams.get("org_id");
  const action = searchParams.get("action");

  if (!orgId) return NextResponse.json({ error: "org_id required" }, { status: 400 });

  if (action === "bump_ctags") {
    const service = createServiceClient();

    // Alle CTags um 1 erhoehen
    const { data: groups } = await service
      .from("calendar_groups")
      .select("id, name, ctag")
      .eq("org_id", orgId);

    if (!groups || groups.length === 0) {
      return NextResponse.json({ error: "Keine Gruppen gefunden" }, { status: 404 });
    }

    const results = [];
    for (const g of groups) {
      const { error } = await service
        .from("calendar_groups")
        .update({ ctag: g.ctag + 1 })
        .eq("id", g.id);

      results.push({
        name: g.name,
        oldCtag: g.ctag,
        newCtag: g.ctag + 1,
        success: !error,
        error: error?.message || null,
      });
    }

    return NextResponse.json({ action: "bump_ctags", results });
  }

  return NextResponse.json({ error: "Unknown action" }, { status: 400 });
}
