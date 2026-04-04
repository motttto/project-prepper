import { createServiceClient } from "@/lib/supabase-service";

export type CalDavAuth = {
  orgId: string;
  profileId: string;
  readWrite: boolean;
};

/**
 * Validiert einen CalDAV-Token und gibt org_id + profile_id zurück.
 * Returns null wenn Token ungültig.
 */
export async function validateCalDavToken(token: string): Promise<CalDavAuth | null> {
  const supabase = createServiceClient();

  const { data, error } = await supabase
    .from("calendar_feed_tokens")
    .select("id, org_id, profile_id, read_write")
    .eq("token", token)
    .maybeSingle();

  if (error || !data) return null;

  // Update last_used_at (fire and forget)
  supabase
    .from("calendar_feed_tokens")
    .update({ last_used_at: new Date().toISOString() })
    .eq("id", data.id)
    .then();

  return {
    orgId: data.org_id,
    profileId: data.profile_id,
    readWrite: data.read_write ?? false,
  };
}

/**
 * 401 Response mit WWW-Authenticate Header
 */
export function unauthorizedResponse(): Response {
  return new Response("Unauthorized", {
    status: 401,
    headers: { "WWW-Authenticate": 'Basic realm="Project Prepper CalDAV"' },
  });
}
