import { NextResponse } from "next/server";
import { createServerSupabaseClient } from "@/lib/supabase-server";

// Callback für Email-Bestätigung nach Registrierung
export async function GET(request: Request) {
  const { searchParams, origin } = new URL(request.url);
  const code = searchParams.get("code");

  if (code) {
    const supabase = await createServerSupabaseClient();
    await supabase.auth.exchangeCodeForSession(code);

    // MFA-Status prüfen — neue User müssen 2FA einrichten
    const { data: factors } = await supabase.auth.mfa.listFactors();
    const hasVerifiedFactor = factors?.totp?.some((f) => f.status === "verified") ?? false;

    if (!hasVerifiedFactor) {
      return NextResponse.redirect(`${origin}/mfa/setup`);
    }
  }

  return NextResponse.redirect(`${origin}/dashboard`);
}
