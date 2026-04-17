import { NextResponse } from "next/server";
import { createServerSupabaseClient } from "@/lib/supabase-server";

// Callback für Email-Bestätigung nach Registrierung
export async function GET(request: Request) {
  const { searchParams, origin } = new URL(request.url);
  const code = searchParams.get("code");
  const requested = searchParams.get("redirect") || searchParams.get("next");
  const safeRedirect =
    requested && requested.startsWith("/") && !requested.startsWith("//")
      ? requested
      : "/dashboard";

  if (code) {
    const supabase = await createServerSupabaseClient();
    await supabase.auth.exchangeCodeForSession(code);

    // Globalen MFA-Toggle pruefen
    const { data: settings } = await supabase
      .from("app_settings")
      .select("mfa_enabled")
      .eq("id", true)
      .maybeSingle();
    const mfaEnabled = settings?.mfa_enabled === true;

    if (mfaEnabled) {
      const { data: factors } = await supabase.auth.mfa.listFactors();
      const hasVerifiedFactor = factors?.totp?.some((f) => f.status === "verified") ?? false;
      if (!hasVerifiedFactor) {
        return NextResponse.redirect(
          `${origin}/mfa/setup?redirect=${encodeURIComponent(safeRedirect)}`
        );
      }
    }
  }

  return NextResponse.redirect(`${origin}${safeRedirect}`);
}
