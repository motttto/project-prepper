// Health-Endpoint fuer Uptime-Monitoring (Vercel, BetterStack, Pingdom, ...).
//
// GET /api/health returnt 200 wenn Server lebt und Supabase erreichbar ist,
// 503 wenn DB-Ping fehlschlaegt.
//
// Kein Auth — Output ist generisch und enthaelt keine sensitiven Daten.

import { NextResponse } from "next/server";
import { createClient } from "@supabase/supabase-js";

export const dynamic = "force-dynamic";
export const revalidate = 0;

export async function GET() {
  const url = process.env.NEXT_PUBLIC_SUPABASE_URL;
  const anon = process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY;

  if (!url || !anon) {
    return NextResponse.json(
      { status: "error", error: "missing-env" },
      { status: 503 }
    );
  }

  // Leichter DB-Ping: simple Query auf eine oeffentliche read-only-Tabelle.
  // 'roles' wird im Init-Seed angelegt und ist anon-readable.
  try {
    const supabase = createClient(url, anon);
    const start = Date.now();
    const { error } = await supabase.from("roles").select("id").limit(1);
    const ms = Date.now() - start;

    if (error) {
      return NextResponse.json(
        { status: "error", db: "fail", error: error.message },
        { status: 503 }
      );
    }

    return NextResponse.json({
      status: "ok",
      db: "ok",
      db_latency_ms: ms,
      timestamp: new Date().toISOString(),
    });
  } catch (err) {
    return NextResponse.json(
      { status: "error", error: err instanceof Error ? err.message : "unknown" },
      { status: 503 }
    );
  }
}
