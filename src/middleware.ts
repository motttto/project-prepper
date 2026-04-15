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
    if (!isSystem && !isMfaPage && !isAuthCallback && !isAuthPage) {
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

    // Zustimmung bereits erteilt aber User auf Onboarding -> weiterleiten
    if (hasAcceptedCollab && isOnboardingPage) {
      // Hat Org? -> Dashboard. Sonst -> /org/new
      const { data: orgCheck } = await supabase
        .from("org_memberships")
        .select("org_id")
        .eq("profile_id", user.id)
        .limit(1);
      const url = request.nextUrl.clone();
      url.pathname = orgCheck && orgCheck.length > 0 ? "/dashboard" : "/org/choose";
      return NextResponse.redirect(url);
    }

    // System-User hat ab hier immer Zugang
    if (isSystem) {
      if (isAuthPage) {
        const url = request.nextUrl.clone();
        url.pathname = "/dashboard";
        return NextResponse.redirect(url);
      }
      return supabaseResponse;
    }

    // Org-Mitgliedschaften prüfen
    const { data: memberships } = await supabase
      .from("org_memberships")
      .select("org_id, is_active")
      .eq("profile_id", user.id);

    const hasAnyOrg = memberships && memberships.length > 0;

    // Eingeloggt auf Login-Seite → weiterleiten
    if (isAuthPage) {
      const url = request.nextUrl.clone();
      if (!hasAnyOrg) {
        url.pathname = "/org/choose";
      } else {
        const hasActiveOrg = memberships.some((m) => m.is_active);
        url.pathname = hasActiveOrg ? "/dashboard" : "/pending";
      }
      return NextResponse.redirect(url);
    }

    // Keine Orgs → /org/choose (Wahl zwischen eigener Org / Einladung)
    if (!hasAnyOrg && !isOrgPage && !isPendingPage && !isHomePage && !isAuthCallback && !isPartnerInvitePage) {
      const url = request.nextUrl.clone();
      url.pathname = "/org/choose";
      return NextResponse.redirect(url);
    }

    // Hat Orgs → Prüfe ob aktiv in aktueller Org
    if (hasAnyOrg && !isOrgPage) {
      const orgIdCookie = request.cookies.get("pp_org_id")?.value;
      const currentOrgMembership = orgIdCookie
        ? memberships.find((m) => m.org_id === orgIdCookie)
        : null;

      const isActiveInCurrentOrg = currentOrgMembership?.is_active;
      const isActiveInAnyOrg = memberships.some((m) => m.is_active);
      const isActive = isActiveInCurrentOrg || isActiveInAnyOrg;

      // Inaktiv → /pending (aber /partner-invite darf aufgerufen werden um Einladung anzunehmen)
      if (!isActive && !isPendingPage && !isHomePage && !isAuthCallback && !isPartnerInvitePage) {
        const url = request.nextUrl.clone();
        url.pathname = "/pending";
        return NextResponse.redirect(url);
      }

      // Aktiv auf /pending → /dashboard
      if (isActive && isPendingPage) {
        const url = request.nextUrl.clone();
        url.pathname = "/dashboard";
        return NextResponse.redirect(url);
      }
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
