"use client";

import { useState } from "react";
import { createClient } from "@/lib/supabase";

export default function ForgotPasswordPage() {
  const supabase = createClient();
  const [email, setEmail] = useState("");
  const [loading, setLoading] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError(null);
    const redirectTo =
      typeof window !== "undefined"
        ? `${window.location.origin}/reset-password`
        : "/reset-password";
    const { error } = await supabase.auth.resetPasswordForEmail(email, { redirectTo });
    setLoading(false);
    if (error) {
      setError(error.message);
    } else {
      setDone(true);
    }
  }

  return (
    <div className="min-h-screen flex">
      <div className="flex-1 flex items-center justify-center p-8" style={{ background: "var(--color-background)" }}>
        <div className="w-full max-w-md space-y-6">
          <div>
            <h1 className="text-2xl font-bold mb-1">Passwort zurücksetzen</h1>
            <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              Gib deine E-Mail-Adresse ein. Wir schicken dir einen Link zum Zurücksetzen.
            </p>
          </div>

          {done ? (
            <div className="rounded-lg p-4 text-sm" style={{ background: "var(--color-success-light, #dcfce7)", color: "var(--color-success, #16a34a)" }}>
              Wir haben dir eine E-Mail mit einem Link zum Zurücksetzen geschickt — sofern ein Konto mit dieser Adresse existiert.
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium mb-1.5">E-Mail</label>
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="w-full px-3 py-2.5 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
                  placeholder="name@beispiel.de"
                  required
                />
              </div>
              {error && (
                <div className="rounded-lg p-3 text-sm" style={{ background: "var(--color-destructive-light, #fef2f2)", color: "var(--color-destructive, #dc2626)" }}>
                  {error}
                </div>
              )}
              <button
                type="submit"
                disabled={loading}
                className="w-full py-2.5 rounded-lg text-sm font-medium text-white disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}
              >
                {loading ? "Wird gesendet..." : "Link senden"}
              </button>
            </form>
          )}

          <div className="text-center text-sm">
            <a href="/login" className="font-medium" style={{ color: "var(--color-primary)" }}>
              Zurück zum Login
            </a>
          </div>
        </div>
      </div>
    </div>
  );
}
