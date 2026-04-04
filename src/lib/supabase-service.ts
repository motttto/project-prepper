// Service-Role Supabase Client (bypasses RLS)
// Wird für CalDAV-Server verwendet, da keine Session-Cookies vorhanden
import { createClient } from "@supabase/supabase-js";

export function createServiceClient() {
  const url = process.env.NEXT_PUBLIC_SUPABASE_URL;
  const key = process.env.SUPABASE_SERVICE_ROLE_KEY;

  if (!url || !key) {
    throw new Error("SUPABASE_SERVICE_ROLE_KEY not configured");
  }

  return createClient(url, key);
}
