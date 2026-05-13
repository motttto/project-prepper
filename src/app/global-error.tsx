"use client";

// Letzte Verteidigungslinie: faengt auch Fehler im Root-Layout.
// Muss eigene <html>/<body>-Tags rendern, weil das Layout selbst kaputt ist.

import { useEffect } from "react";

export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    console.error("Global error:", error, "digest:", error.digest);
  }, [error]);

  return (
    <html lang="de">
      <body
        style={{
          fontFamily: "system-ui, -apple-system, sans-serif",
          margin: 0,
          minHeight: "100vh",
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          background: "#f5f5f5",
        }}
      >
        <div style={{ maxWidth: 480, textAlign: "center", padding: "0 16px" }}>
          <h1 style={{ fontSize: 28, marginBottom: 12 }}>Anwendungsfehler</h1>
          <p style={{ color: "#666", marginBottom: 24 }}>
            Die Anwendung kann nicht geladen werden. Bitte lade die Seite neu.
          </p>
          {error.digest && (
            <p
              style={{
                fontFamily: "monospace",
                fontSize: 12,
                color: "#999",
                marginBottom: 24,
              }}
            >
              Fehler-ID: {error.digest}
            </p>
          )}
          <button
            type="button"
            onClick={reset}
            style={{
              background: "#0066FF",
              color: "white",
              border: "none",
              padding: "10px 20px",
              borderRadius: 8,
              fontSize: 14,
              cursor: "pointer",
            }}
          >
            Seite neu laden
          </button>
        </div>
      </body>
    </html>
  );
}
