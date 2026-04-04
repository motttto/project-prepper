import { createClient } from "@/lib/supabase";
import type { ActivityAction } from "@/types/database";

interface LogActivityParams {
  orgId: string;
  action: ActivityAction;
  entityType?: string;
  entityId?: string;
  entityLabel?: string;
  metadata?: Record<string, unknown>;
}

/**
 * Fire-and-forget Activity Log.
 * Fehler werden geloggt aber blockieren nie die UI.
 */
export async function logActivity({
  orgId,
  action,
  entityType,
  entityId,
  entityLabel,
  metadata,
}: LogActivityParams): Promise<void> {
  try {
    const supabase = createClient();
    const { data: { user } } = await supabase.auth.getUser();

    await supabase.from("org_activity_log").insert({
      org_id: orgId,
      actor_id: user?.id ?? null,
      action,
      entity_type: entityType ?? null,
      entity_id: entityId ?? null,
      entity_label: entityLabel ?? null,
      metadata: metadata ?? {},
    });
  } catch (err) {
    console.warn("[ActivityLog] Fehler:", err);
  }
}
