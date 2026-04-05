import { NextResponse, type NextRequest } from "next/server";
import { createServerSupabaseClient } from "@/lib/supabase-server";

/**
 * DELETE /api/calendar/delete?id=xxx&org_id=xxx
 * Löscht ein Calendar-Event. Nutzt Server-seitige Auth (Session-Cookie).
 */
export async function DELETE(request: NextRequest) {
  const supabase = await createServerSupabaseClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const { searchParams } = new URL(request.url);
  const eventId = searchParams.get("id");
  const orgId = searchParams.get("org_id");

  if (!eventId || !orgId) {
    return NextResponse.json({ error: "id and org_id required" }, { status: 400 });
  }

  // Prüfen ob User Org-Mitglied ist
  const { data: membership } = await supabase
    .from("org_memberships")
    .select("id")
    .eq("org_id", orgId)
    .eq("profile_id", user.id)
    .eq("is_active", true)
    .maybeSingle();

  if (!membership) {
    return NextResponse.json({ error: "Not a member of this org" }, { status: 403 });
  }

  // Event löschen (mit Service Client für zuverlässigen Delete)
  const { error, count } = await supabase
    .from("calendar_events")
    .delete({ count: "exact" })
    .eq("id", eventId)
    .eq("org_id", orgId);

  if (error) {
    console.error("[Calendar Delete]", error);
    return NextResponse.json({ error: error.message }, { status: 500 });
  }

  if (count === 0) {
    return NextResponse.json({ error: "Event not found" }, { status: 404 });
  }

  return NextResponse.json({ success: true, deleted: count });
}
