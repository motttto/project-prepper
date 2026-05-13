import Link from "next/link";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Seite nicht gefunden — Project Prepper",
};

export default function NotFound() {
  return (
    <div
      className="min-h-screen flex items-center justify-center px-4"
      style={{ background: "var(--color-background)" }}
    >
      <div className="max-w-md w-full text-center">
        <div
          className="text-6xl font-bold mb-4"
          style={{ color: "var(--color-muted-foreground)" }}
        >
          404
        </div>
        <h1 className="text-2xl font-bold mb-3">Seite nicht gefunden</h1>
        <p className="text-sm mb-6" style={{ color: "var(--color-muted-foreground)" }}>
          Die angeforderte Seite existiert nicht oder wurde verschoben.
        </p>
        <div className="flex items-center justify-center gap-3">
          <Link
            href="/dashboard"
            className="px-4 py-2 rounded-lg text-sm font-medium text-white"
            style={{ background: "var(--color-primary)" }}
          >
            Zum Dashboard
          </Link>
          <Link
            href="/"
            className="px-4 py-2 rounded-lg text-sm font-medium"
            style={{ border: "1px solid var(--color-border)" }}
          >
            Startseite
          </Link>
        </div>
      </div>
    </div>
  );
}
