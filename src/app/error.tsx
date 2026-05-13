"use client";

// App-Level Error-Boundary (Next.js App Router).
// Faengt Render-Errors in (auth)/(dashboard)/etc. ab — User sieht
// statt White-Screen eine verstaendliche Meldung mit Reload-Option.

import { useEffect } from "react";
import Link from "next/link";

export default function GlobalRouteError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    // Spaeter durch Sentry/Monitoring ersetzt — fuer jetzt console.
    console.error("App error:", error, "digest:", error.digest);
  }, [error]);

  return (
    <div
      className="min-h-screen flex items-center justify-center px-4"
      style={{ background: "var(--color-background)" }}
    >
      <div className="max-w-md w-full text-center">
        <h1 className="text-2xl font-bold mb-3">Etwas ist schief gelaufen</h1>
        <p className="text-sm mb-6" style={{ color: "var(--color-muted-foreground)" }}>
          Beim Laden der Seite ist ein Fehler aufgetreten. Versuch es nochmal oder geh zurück
          zum Dashboard.
        </p>
        {error.digest && (
          <p className="text-xs mb-6 font-mono" style={{ color: "var(--color-muted-foreground)" }}>
            Fehler-ID: {error.digest}
          </p>
        )}
        <div className="flex items-center justify-center gap-3">
          <button
            type="button"
            onClick={reset}
            className="px-4 py-2 rounded-lg text-sm font-medium text-white"
            style={{ background: "var(--color-primary)" }}
          >
            Erneut versuchen
          </button>
          <Link
            href="/dashboard"
            className="px-4 py-2 rounded-lg text-sm font-medium"
            style={{ border: "1px solid var(--color-border)" }}
          >
            Zum Dashboard
          </Link>
        </div>
      </div>
    </div>
  );
}
