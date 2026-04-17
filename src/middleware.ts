import { createServerClient } from "@supabase/ssr";
import { NextResponse, type NextRequest } from "next/server";

export async function middleware(request: NextRequest) {
  let supabaseResponse = NextResponse.next({ request });

  const supabase = createServerClient(
    process.env.NEXT_PUBLIC_SUPABASE_URL!,
    process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY!,
    {
      cookies: {
        getAll() {
          return request.cookies.getAll();
        },
        setAll(cookiesToSet) {
          cookiesToSet.forEach(({ name, value }) =>
            request.cookies.set(name, value)
          );
          supabaseResponse = NextResponse.next({ request });
          cookiesToSet.forEach(({ name, value, options }) =>
            supabaseResponse.cookies.set(name, value, options)
          );
        },
      },
    }
  );

  // Session refresh - wichtig damit Tokens nicht ablaufen
  const {
    data: { user },
  } = await supabase.auth.getUser();

  const pathname = request.nextUrl.pathname;

  // Calendar-Feed hat eigene Token-Auth — Middleware skippen
  if (pathname.startsWith("/api/calendar/feed")) {
    return supabaseResponse;
  }

  const isAuthPage = pathname === "/login";
  const isHomePage = pathname === "/";
  const isPendingPage = pathname === "/pending";
  const isAuthCallback = pathname.startsWith("/auth/callback");
  const isOrgPage = pathname.startsWith("/org/");
  const isMfaPage = pathname.startsWith("/mfa/");
  const isPartnerInvitePage = pathname === "/partner-invite";
  const isOnboardingPage = pathname === "/onboarding";

  // Nicht eingeloggt? → Weiterleitung zum Login
  // (außer man ist bereits auf /login, Startseite, Auth-Callback oder MFA-Seite)
  if (!user && !isAuthPage && !isHomePage && !isAuthCallback && !isMfaPage) {
    const url = request.nextUrl.clone();
    url.pathname = "/login";
    return NextResponse.redirect(url);
  }

  // Eingeloggt → MFA + Org-Status prüfen
  if (user) {
    // Profil laden (wird auch für MFA-Check gebraucht, System-User skippen MFA)
    const { data: profile } = await supabase
      .from("profiles")
      .select("is_system, collaboration_accepted_at")
      .eq("id", user.id)
      .single();

    const isSystem = profile?.is_system ?? false;
    const hasAcceptedCollab = !!profile?.collaboration_accepted_at;

    // MFA-Enforcement für alle normalen User (System-User skippen MFA)
    // Globaler Toggle in app_settings.mfa_enabled — umschaltbar im Superadmin-Panel
    const { data: settings } = await supabase
      .from("app_settings")
      .select("mfa_enabled")
      .eq("id", true)
      .maybeSingle();
    const mfaEnabled = settings?.mfa_enabled === true;
    if (mfaEnabled && !isSystem && !isMfaPage && !isAuthCallback && !isAuthPage) {
      const { data: aal } = await supabase.auth.mfa.getAuthenticatorAssuranceLevel();

      if (aal) {
        // User hat MFA eingerichtet aber nicht in dieser Session verifiziert
        if (aal.nextLevel === "aal2" && aal.currentLevel !== "aal2") {
          const url = request.nextUrl.clone();
          url.pathname = "/mfa/verify";
          return NextResponse.redirect(url);
        }

        // User hat kein MFA eingerichtet → Setup erzwingen
        if (aal.nextLevel === "aal1" && aal.currentLevel === "aal1") {
          const { data: factors } = await supabase.auth.mfa.listFactors();
          const hasVerifiedFactor = factors?.totp?.some((f) => f.status === "verified") ?? false;

          if (!hasVerifiedFactor) {
            const url = request.nextUrl.clone();
            url.pathname = "/mfa/setup";
            return NextResponse.redirect(url);
          }
        }
      }
    }

    // PFLICHT: Kollaborationsbasis-Zustimmung — VOR Org-Flow
    // Gilt fuer alle User (auch Superadmins). Ohne Zustimmung kein Zugang.
    if (!hasAcceptedCollab && !isOnboardingPage && !isAuthCallback && !isHomePage && !isAuthPage && !isMfaPage) {
      const url = request.nextUrl.clone();
      url.pathname = "/onboarding";
      return NextResponse.redirect(url);
    }

    // Zustimmung bereits erteilt aber User auf Onboarding -> direkt zum Dashboard
    if (hasAcceptedCollab && isOnboardingPage) {
      const url = request.nextUrl.clone();
      url.pathname = "/dashboard";
      return NextResponse.redirect(url);
    }

    // Eingeloggt auf Login-Seite → Dashboard (Solo-Modus default)
    if (isAuthPage) {
      const url = request.nextUrl.clone();
      url.pathname = "/dashboard";
      return NextResponse.redirect(url);
    }

    // Im neuen User-First-Modell: kein Org-Check mehr.
    // User kann immer auf Solo-Bereich (Inventar, Anfragen, Projekte).
    // Group-spezifische Pages (Polls, Kalender, Beschluesse) blocken sich selbst.

    // Pending-Seite ist obsolet — User auf /pending soll zu Dashboard
    if (isPendingPage) {
      const url = request.nextUrl.clone();
      url.pathname = "/dashboard";
      return NextResponse.redirect(url);
    }
  }

  return supabaseResponse;
}

export const config = {
  matcher: [
    // Alles außer statische Dateien und API-Routen
    "/((?!_next/static|_next/image|favicon.ico|.*\\.(?:svg|png|jpg|jpeg|gif|webp)$).*)",
  ],
};
