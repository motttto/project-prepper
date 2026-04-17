"use client";

import { useState, useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { IconShield, IconZap } from "@/components/ui/icons";

export default function MfaVerifyPage() {
  const [code, setCode] = useState("");
  const [factorId, setFactorId] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [verifying, setVerifying] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const router = useRouter();
  const searchParams = useSearchParams();
  const supabase = createClient();
  const redirectTo = searchParams.get("redirect");
  const safeRedirect =
    redirectTo && redirectTo.startsWith("/") && !redirectTo.startsWith("//")
      ? redirectTo
      : "/dashboard";

  useEffect(() => {
    async function checkMfa() {
      const { data: { user } } = await supabase.auth.getUser();
      if (!user) {
        router.push("/login");
        return;
      }

      // MFA-Faktoren laden
      const { data: factors } = await supabase.auth.mfa.listFactors();
      const verified = factors?.totp?.filter((f) => f.status === "verified") ?? [];

      if (verified.length === 0) {
        // Kein MFA eingerichtet → Setup
        router.push("/mfa/setup");
        return;
      }

      // Prüfe ob bereits AAL2 (MFA verifiziert in Session)
      const { data: aal } = await supabase.auth.mfa.getAuthenticatorAssuranceLevel();
      if (aal?.currentLevel === "aal2") {
        router.push(safeRedirect);
        return;
      }

      setFactorId(verified[0].id);
      setLoading(false);
    }

    checkMfa();
  }, [supabase, router]);

  async function handleVerify(e: React.FormEvent) {
    e.preventDefault();
    if (!factorId || !code) return;

    setVerifying(true);
    setError(null);

    try {
      // Challenge erstellen
      const { data: challenge, error: challengeError } =
        await supabase.auth.mfa.challenge({ factorId });

      if (challengeError || !challenge) {
        throw new Error(challengeError?.message || "Challenge fehlgeschlagen");
      }

      // Code verifizieren
      const { error: verifyError } = await supabase.auth.mfa.verify({
        factorId,
        challengeId: challenge.id,
        code,
      });

      if (verifyError) {
        throw new Error(
          verifyError.message === "Invalid TOTP code"
            ? "Ungültiger Code. Bitte versuche es erneut."
            : verifyError.message
        );
      }

      // Erfolgreich → Dashboard
      router.push(safeRedirect);
      router.refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Verifizierung fehlgeschlagen");
      setVerifying(false);
      setCode("");
    }
  }

  async function handleLogout() {
    await supabase.auth.signOut();
    router.push("/login");
  }

  return (
    <div className="min-h-screen flex items-center justify-center p-4" style={{ background: "var(--color-background)" }}>
      <div className="w-full max-w-sm space-y-6">
        {/* Logo */}
        <div className="flex items-center gap-3 justify-center mb-2">
          <div
            className="w-9 h-9 rounded-lg flex items-center justify-center"
            style={{ background: "var(--color-primary)" }}
          >
            <IconZap size={20} className="text-white" />
          </div>
          <span className="font-bold text-lg">Project Prepper</span>
        </div>

        <div
          className="p-6 rounded-xl space-y-5"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
        >
          {loading ? (
            <div className="text-center py-8" style={{ color: "var(--color-muted-foreground)" }}>
              Wird geladen...
            </div>
          ) : (
            <>
              <div className="text-center">
                <div
                  className="w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3"
                  style={{ background: "var(--color-primary-light)" }}
                >
                  <IconShield size={24} style={{ color: "var(--color-primary)" }} />
                </div>
                <h1 className="text-xl font-bold">Zwei-Faktor-Authentifizierung</h1>
                <p className="text-sm mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                  Gib den Code aus deiner Authenticator-App ein
                </p>
              </div>

              {error && (
                <div
                  className="p-3 rounded-lg text-sm"
                  style={{
                    background: "var(--color-destructive-light)",
                    color: "var(--color-destructive)",
                    border: "1px solid var(--color-destructive)",
                  }}
                >
                  {error}
                </div>
              )}

              <form onSubmit={handleVerify} className="space-y-4">
                <div>
                  <input
                    type="text"
                    inputMode="numeric"
                    pattern="[0-9]{6}"
                    maxLength={6}
                    value={code}
                    onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))}
                    className="w-full px-3 py-3 rounded-lg text-center text-2xl tracking-[0.5em] font-mono"
                    style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
                    placeholder="000000"
                    autoFocus
                    required
                  />
                </div>
                <button
                  type="submit"
                  disabled={verifying || code.length !== 6}
                  className="w-full py-2.5 rounded-lg text-sm font-medium text-white transition-colors disabled:opacity-50"
                  style={{ background: "var(--color-primary)" }}
                  onMouseEnter={(e) => !verifying && (e.currentTarget.style.background = "var(--color-primary-hover)")}
                  onMouseLeave={(e) => !verifying && (e.currentTarget.style.background = "var(--color-primary)")}
                >
                  {verifying ? "Wird überprüft..." : "Bestätigen"}
                </button>
              </form>

              <div className="text-center">
                <button
                  onClick={handleLogout}
                  className="text-xs"
                  style={{ color: "var(--color-muted-foreground)" }}
                >
                  Mit anderem Konto anmelden
                </button>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
