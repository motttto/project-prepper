"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";

export default function ResetPasswordPage() {
  const supabase = createClient();
  const router = useRouter();
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [loading, setLoading] = useState(false);
  const [ready, setReady] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Beim Mount: Recovery-Session aus URL-Hash laden
  useEffect(() => {
    let cancelled = false;
    async function check() {
      const { data } = await supabase.auth.getSession();
      if (cancelled) return;
      if (data.session) {
        setReady(true);
      } else {
        // Auf onAuthStateChange "PASSWORD_RECOVERY" warten
        const sub = supabase.auth.onAuthStateChange((event) => {
          if (event === "PASSWORD_RECOVERY" || event === "SIGNED_IN") {
            setReady(true);
          }
        });
        return () => sub.data.subscription.unsubscribe();
      }
    }
    check();
    return () => {
      cancelled = true;
    };
  }, [supabase]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (password !== confirm) {
      setError("Passwörter stimmen nicht überein.");
      return;
    }
    if (password.length < 6) {
      setError("Mindestens 6 Zeichen.");
      return;
    }
    setLoading(true);
    setError(null);
    const { error } = await supabase.auth.updateUser({ password });
    setLoading(false);
    if (error) {
      setError(error.message);
    } else {
      router.push("/dashboard");
    }
  }

  return (
    <div className="min-h-screen flex">
      <div className="flex-1 flex items-center justify-center p-8" style={{ background: "var(--color-background)" }}>
        <div className="w-full max-w-md space-y-6">
          <div>
            <h1 className="text-2xl font-bold mb-1">Neues Passwort</h1>
            <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              Wähle ein neues Passwort für dein Konto.
            </p>
          </div>

          {!ready ? (
            <div className="rounded-lg p-4 text-sm" style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
              Recovery-Session wird geprüft... Falls dieser Bildschirm hängenbleibt, öffne den Link aus der Email erneut.
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium mb-1.5">Neues Passwort</label>
                <input
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="w-full px-3 py-2.5 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
                  placeholder="Mindestens 6 Zeichen"
                  minLength={6}
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Bestätigen</label>
                <input
                  type="password"
                  value={confirm}
                  onChange={(e) => setConfirm(e.target.value)}
                  className="w-full px-3 py-2.5 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
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
                {loading ? "Wird gespeichert..." : "Passwort ändern"}
              </button>
            </form>
          )}
        </div>
      </div>
    </div>
  );
}
