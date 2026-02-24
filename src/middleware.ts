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

  // Nicht eingeloggt? → Weiterleitung zum Login
  // (außer man ist bereits auf /login, Startseite oder Auth-Callback)
  if (!user && !isAuthPage && !isHomePage && !isAuthCallback) {
    const url = request.nextUrl.clone();
    url.pathname = "/login";
    return NextResponse.redirect(url);
  }

  // Eingeloggt → Profil-Status prüfen
  if (user) {
    // Profil laden (einfacher PK-Lookup, < 5ms)
    const { data: profile } = await supabase
      .from("profiles")
      .select("is_active")
      .eq("id", user.id)
      .single();

    const isActive = profile?.is_active ?? false;

    // Eingeloggt auf Login-Seite → weiterleiten
    if (isAuthPage) {
      const url = request.nextUrl.clone();
      url.pathname = isActive ? "/dashboard" : "/pending";
      return NextResponse.redirect(url);
    }

    // Inaktiver User auf geschützten Seiten → /pending
    if (!isActive && !isPendingPage && !isHomePage && !isAuthCallback) {
      const url = request.nextUrl.clone();
      url.pathname = "/pending";
      return NextResponse.redirect(url);
    }

    // Aktiver User auf /pending → /dashboard
    if (isActive && isPendingPage) {
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
