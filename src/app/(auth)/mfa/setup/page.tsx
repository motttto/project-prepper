"use client";

import { useState, useEffect, Suspense } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { IconShield, IconZap } from "@/components/ui/icons";

export default function MfaSetupPage() {
  return (
    <Suspense>
      <MfaSetupInner />
    </Suspense>
  );
}

function MfaSetupInner() {
  const [qrCode, setQrCode] = useState<string | null>(null);
  const [secret, setSecret] = useState<string | null>(null);
  const [factorId, setFactorId] = useState<string | null>(null);
  const [verifyCode, setVerifyCode] = useState("");
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
    async function enrollMfa() {
      // Prüfe ob User eingeloggt
      const { data: { user } } = await supabase.auth.getUser();
      if (!user) {
        router.push("/login");
        return;
      }

      // Prüfe ob bereits MFA-Faktoren vorhanden
      const { data: factors } = await supabase.auth.mfa.listFactors();
      const verified = factors?.totp?.filter((f) => f.status === "verified") ?? [];
      if (verified.length > 0) {
        // Schon eingerichtet → weiter zum Dashboard
        router.push(safeRedirect);
        return;
      }

      // Unverified Faktoren entfernen (z.B. abgebrochene Setups)
      const allFactors = factors?.totp ?? [];
      const unverified = allFactors.filter((f) => (f.status as string) !== "verified");
      for (const f of unverified) {
        await supabase.auth.mfa.unenroll({ factorId: f.id });
      }

      // Neuen TOTP-Faktor erstellen
      const { data, error: enrollError } = await supabase.auth.mfa.enroll({
        factorType: "totp",
        friendlyName: "Project Prepper 2FA",
      });

      if (enrollError || !data) {
        setError(enrollError?.message || "Fehler beim Einrichten der 2FA");
        setLoading(false);
        return;
      }

      setQrCode(data.totp.qr_code);
      setSecret(data.totp.secret);
      setFactorId(data.id);
      setLoading(false);
    }

    enrollMfa();
  }, [supabase, router]);

  async function handleVerify(e: React.FormEvent) {
    e.preventDefault();
    if (!factorId || !verifyCode) return;

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
        code: verifyCode,
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
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center p-4" style={{ background: "var(--color-background)" }}>
      <div className="w-full max-w-md space-y-6">
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
          <div className="text-center">
            <div
              className="w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3"
              style={{ background: "var(--color-primary-light)" }}
            >
              <IconShield size={24} style={{ color: "var(--color-primary)" }} />
            </div>
            <h1 className="text-xl font-bold">Zwei-Faktor-Authentifizierung</h1>
            <p className="text-sm mt-1" style={{ color: "var(--color-muted-foreground)" }}>
              Richte 2FA ein, um dein Konto zu schützen
            </p>
          </div>

          {loading ? (
            <div className="text-center py-8" style={{ color: "var(--color-muted-foreground)" }}>
              QR-Code wird generiert...
            </div>
          ) : (
            <>
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

              {/* Anleitung */}
              <div className="space-y-3">
                <div
                  className="p-3 rounded-lg text-sm"
                  style={{ background: "var(--color-muted)", border: "1px solid var(--color-border)" }}
                >
                  <p className="font-medium mb-2">So geht&apos;s:</p>
                  <ol className="list-decimal list-inside space-y-1 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    <li>Installiere eine Authenticator-App (z.B. Google Authenticator, Authy)</li>
                    <li>Scanne den QR-Code mit der App</li>
                    <li>Gib den 6-stelligen Code ein</li>
                  </ol>
                </div>
              </div>

              {/* QR Code */}
              {qrCode && (
                <div className="flex flex-col items-center gap-3">
                  <div className="p-3 bg-white rounded-lg">
                    <img src={qrCode} alt="2FA QR Code" className="w-48 h-48" />
                  </div>

                  {/* Secret für manuelle Eingabe */}
                  {secret && (
                    <details className="w-full">
                      <summary
                        className="text-xs cursor-pointer"
                        style={{ color: "var(--color-muted-foreground)" }}
                      >
                        QR-Code kann nicht gescannt werden?
                      </summary>
                      <div
                        className="mt-2 p-2 rounded-lg text-xs font-mono text-center break-all"
                        style={{ background: "var(--color-muted)", border: "1px solid var(--color-border)" }}
                      >
                        {secret}
                      </div>
                    </details>
                  )}
                </div>
              )}

              {/* Verifizierung */}
              <form onSubmit={handleVerify} className="space-y-4">
                <div>
                  <label className="block text-sm font-medium mb-1.5">
                    Bestätigungscode
                  </label>
                  <input
                    type="text"
                    inputMode="numeric"
                    pattern="[0-9]{6}"
                    maxLength={6}
                    value={verifyCode}
                    onChange={(e) => setVerifyCode(e.target.value.replace(/\D/g, ""))}
                    className="w-full px-3 py-2.5 rounded-lg text-sm text-center text-2xl tracking-[0.5em] font-mono"
                    style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
                    placeholder="000000"
                    autoFocus
                    required
                  />
                </div>
                <button
                  type="submit"
                  disabled={verifying || verifyCode.length !== 6}
                  className="w-full py-2.5 rounded-lg text-sm font-medium text-white transition-colors disabled:opacity-50"
                  style={{ background: "var(--color-primary)" }}
                  onMouseEnter={(e) => !verifying && (e.currentTarget.style.background = "var(--color-primary-hover)")}
                  onMouseLeave={(e) => !verifying && (e.currentTarget.style.background = "var(--color-primary)")}
                >
                  {verifying ? "Wird überprüft..." : "2FA aktivieren"}
                </button>
              </form>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
