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
  const isAuthPage = pathname === "/login";
  const isHomePage = pathname === "/";
  const isPendingPage = pathname === "/pending";
  const isAuthCallback = pathname.startsWith("/auth/callback");
  const isOrgPage = pathname.startsWith("/org/");

  // Nicht eingeloggt? → Weiterleitung zum Login
  // (außer man ist bereits auf /login, Startseite oder Auth-Callback)
  if (!user && !isAuthPage && !isHomePage && !isAuthCallback) {
    const url = request.nextUrl.clone();
    url.pathname = "/login";
    return NextResponse.redirect(url);
  }

  // Eingeloggt → Org-Status prüfen
  if (user) {
    // Profil laden
    const { data: profile } = await supabase
      .from("profiles")
      .select("is_system")
      .eq("id", user.id)
      .single();

    const isSystem = profile?.is_system ?? false;

    // System-User hat immer Zugang
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
        url.pathname = "/org/new";
      } else {
        const hasActiveOrg = memberships.some((m) => m.is_active);
        url.pathname = hasActiveOrg ? "/dashboard" : "/pending";
      }
      return NextResponse.redirect(url);
    }

    // Keine Orgs → /org/new (außer man ist schon dort)
    if (!hasAnyOrg && !isOrgPage && !isPendingPage && !isHomePage && !isAuthCallback) {
      const url = request.nextUrl.clone();
      url.pathname = "/org/new";
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

      // Inaktiv → /pending
      if (!isActive && !isPendingPage && !isHomePage && !isAuthCallback) {
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
