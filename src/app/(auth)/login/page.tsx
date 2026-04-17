"use client";

import { useState, Suspense } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { IconZap } from "@/components/ui/icons";

export default function LoginPageWrapper() {
  return (
    <Suspense>
      <LoginPage />
    </Suspense>
  );
}

function LoginPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const supabase = createClient();

  // Query-Parameter: mode=register | invite_email | redirect
  const mode = searchParams.get("mode");
  const inviteEmail = searchParams.get("email") || searchParams.get("invite_email");
  const redirectTo = searchParams.get("redirect");

  const [email, setEmail] = useState(inviteEmail || "");
  const [password, setPassword] = useState("");
  const [name, setName] = useState("");
  const [isRegister, setIsRegister] = useState(mode === "register" || !!inviteEmail);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  async function handleLogin(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError(null);

    const { error } = await supabase.auth.signInWithPassword({
      email,
      password,
    });

    if (error) {
      setError(error.message);
      setLoading(false);
      return;
    }

    // MFA-Status prüfen
    const { data: factors } = await supabase.auth.mfa.listFactors();
    const verifiedFactors = factors?.totp?.filter((f) => f.status === "verified") ?? [];

    if (verifiedFactors.length > 0) {
      // MFA eingerichtet → Code-Eingabe
      router.push("/mfa/verify");
    } else {
      // Kein MFA → Setup erzwingen
      router.push("/mfa/setup");
    }
    router.refresh();
  }

  async function handleRegister(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError(null);

    const { error } = await supabase.auth.signUp({
      email,
      password,
      options: {
        data: { name },
      },
    });

    if (error) {
      setError(error.message);
      setLoading(false);
      return;
    }

    setMessage(
      "Registrierung erfolgreich! Prüfe dein E-Mail-Postfach für den Bestätigungslink."
    );
    setLoading(false);
  }

  return (
    <div className="min-h-screen flex" style={{ background: "var(--color-background)" }}>
      {/* Left: Branding */}
      <div
        className="hidden lg:flex w-[45%] flex-col justify-between p-12"
        style={{ background: "var(--color-sidebar)" }}
      >
        <div>
          <div className="flex items-center gap-3 mb-12">
            <div
              className="w-10 h-10 rounded-lg flex items-center justify-center"
              style={{ background: "var(--color-sidebar-active)" }}
            >
              <IconZap size={22} className="text-white" />
            </div>
            <div>
              <div className="font-bold text-lg" style={{ color: "var(--color-sidebar-text)" }}>
                Project Prepper
              </div>
            </div>
          </div>
          <h2
            className="text-3xl font-bold leading-tight mb-4"
            style={{ color: "var(--color-sidebar-text)" }}
          >
            Events planen.
            <br />
            Equipment verwalten.
            <br />
            Kosten im Blick.
          </h2>
          <p className="text-sm leading-relaxed" style={{ color: "var(--color-sidebar-text-muted)" }}>
            Projekte anlegen, Inventar buchen, Budgets kalkulieren
            – alles an einem Ort.
          </p>
        </div>
        <div className="text-xs" style={{ color: "var(--color-sidebar-text-muted)" }}>
          Project Prepper &middot; Projektmanagement
        </div>
      </div>

      {/* Right: Form */}
      <div className="flex-1 flex items-center justify-center p-8">
        <div className="w-full max-w-sm space-y-6">
          {/* Mobile Logo */}
          <div className="lg:hidden flex items-center gap-3 justify-center mb-4">
            <div
              className="w-9 h-9 rounded-lg flex items-center justify-center"
              style={{ background: "var(--color-primary)" }}
            >
              <IconZap size={20} className="text-white" />
            </div>
            <span className="font-bold text-lg">Project Prepper</span>
          </div>

          <div className="text-center lg:text-left">
            <h1 className="text-2xl font-bold">
              {isRegister ? "Konto erstellen" : "Willkommen zurück"}
            </h1>
            <p className="text-sm mt-1" style={{ color: "var(--color-muted-foreground)" }}>
              {isRegister
                ? "Registriere dich für den Projektplanner"
                : "Melde dich mit deinem Konto an"}
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

          {message && (
            <div
              className="p-3 rounded-lg text-sm"
              style={{
                background: "var(--color-success-light)",
                color: "var(--color-success)",
                border: "1px solid var(--color-success)",
              }}
            >
              {message}
            </div>
          )}

          <form
            onSubmit={isRegister ? handleRegister : handleLogin}
            className="space-y-4"
          >
            {isRegister && (
              <div>
                <label className="block text-sm font-medium mb-1.5">Name</label>
                <input
                  type="text"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  className="w-full px-3 py-2.5 rounded-lg text-sm"
                  style={{
                    border: "1px solid var(--color-border)",
                    background: "var(--color-surface)",
                  }}
                  placeholder="Max Mustermann"
                  required
                />
              </div>
            )}
            <div>
              <label className="block text-sm font-medium mb-1.5">E-Mail</label>
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full px-3 py-2.5 rounded-lg text-sm"
                style={{
                  border: "1px solid var(--color-border)",
                  background: "var(--color-surface)",
                }}
                placeholder="name@beispiel.de"
                required
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1.5">
                Passwort
              </label>
              <input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full px-3 py-2.5 rounded-lg text-sm"
                style={{
                  border: "1px solid var(--color-border)",
                  background: "var(--color-surface)",
                }}
                placeholder="Mindestens 6 Zeichen"
                minLength={6}
                required
              />
            </div>
            <button
              type="submit"
              disabled={loading}
              className="w-full py-2.5 rounded-lg text-sm font-medium text-white transition-colors disabled:opacity-50"
              style={{ background: "var(--color-primary)" }}
              onMouseEnter={(e) => !loading && (e.currentTarget.style.background = "var(--color-primary-hover)")}
              onMouseLeave={(e) => !loading && (e.currentTarget.style.background = "var(--color-primary)")}
            >
              {loading
                ? "Wird geladen..."
                : isRegister
                  ? "Registrieren"
                  : "Anmelden"}
            </button>
          </form>

          <div className="text-center text-sm">
            <button
              onClick={() => {
                setIsRegister(!isRegister);
                setError(null);
                setMessage(null);
              }}
              className="font-medium"
              style={{ color: "var(--color-primary)" }}
            >
              {isRegister
                ? "Bereits ein Konto? Anmelden"
                : "Noch kein Konto? Registrieren"}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
