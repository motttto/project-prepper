"use client";

import { useState, useEffect, useCallback, Suspense } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { IconZap, IconCheck } from "@/components/ui/icons";

type InvitationData = {
  id: string;
  email: string;
  status: string;
  org_id: string;
  role_id: string;
  organizations: { name: string } | null;
  roles: { name: string } | null;
  profiles: { name: string } | null;
};

export default function JoinPageWrapper() {
  return (
    <Suspense fallback={
      <div className="min-h-screen flex items-center justify-center" style={{ background: "var(--color-background)", color: "var(--color-muted-foreground)" }}>
        Einladung wird geladen...
      </div>
    }>
      <JoinPage />
    </Suspense>
  );
}

function JoinPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const token = searchParams.get("token");
  const supabase = createClient();

  const [loading, setLoading] = useState(true);
  const [accepting, setAccepting] = useState(false);
  const [invitation, setInvitation] = useState<InvitationData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);
  const [userId, setUserId] = useState<string | null>(null);

  // User-Status prüfen
  useEffect(() => {
    supabase.auth.getUser().then(({ data: { user } }) => {
      if (user) setUserId(user.id);
    });
  }, [supabase]);

  // Einladung laden
  const loadInvitation = useCallback(async () => {
    if (!token) {
      setError("Kein Einladungs-Token gefunden.");
      setLoading(false);
      return;
    }

    const { data, error: fetchError } = await supabase
      .from("org_invitations")
      .select(`
        id, email, status, org_id, role_id,
        organizations:org_id(name),
        roles:role_id(name),
        profiles:invited_by(name)
      `)
      .eq("id", token)
      .single();

    if (fetchError || !data) {
      setError("Einladung nicht gefunden oder abgelaufen.");
      setLoading(false);
      return;
    }

    if (data.status !== "pending") {
      setError("Diese Einladung wurde bereits angenommen oder ist abgelaufen.");
      setLoading(false);
      return;
    }

    setInvitation(data as unknown as InvitationData);
    setLoading(false);
  }, [supabase, token]);

  useEffect(() => {
    loadInvitation();
  }, [loadInvitation]);

  // Einladung annehmen
  async function handleAccept() {
    if (!invitation || !userId) return;
    setAccepting(true);
    setError(null);

    try {
      // Membership erstellen
      const { error: memberError } = await supabase.from("org_memberships").insert({
        org_id: invitation.org_id,
        profile_id: userId,
        role_id: invitation.role_id,
        is_active: true,
        approved_at: new Date().toISOString(),
      });

      if (memberError) {
        if (memberError.code === "23505") {
          // Bereits Mitglied — Einladung trotzdem akzeptieren
        } else {
          throw memberError;
        }
      }

      // Einladung als akzeptiert markieren
      await supabase
        .from("org_invitations")
        .update({ status: "accepted", accepted_at: new Date().toISOString() })
        .eq("id", invitation.id);

      setSuccess(true);
      setTimeout(() => {
        router.push("/dashboard");
        router.refresh();
      }, 2000);
    } catch (err) {
      console.error("Accept error:", err);
      setError("Fehler beim Annehmen der Einladung.");
      setAccepting(false);
    }
  }

  const orgName = (invitation?.organizations as any)?.name || "Team";
  const roleName = (invitation?.roles as any)?.name || "Mitglied";
  const inviterName = (invitation?.profiles as any)?.name || "";

  return (
    <div
      className="min-h-screen flex items-center justify-center p-4"
      style={{ background: "var(--color-background)" }}
    >
      <div
        className="w-full max-w-md rounded-xl p-8 space-y-6"
        style={{
          background: "var(--color-surface)",
          border: "1px solid var(--color-border)",
          boxShadow: "var(--shadow-lg)",
        }}
      >
        {/* Logo */}
        <div className="flex items-center gap-3 justify-center">
          <div
            className="w-10 h-10 rounded-lg flex items-center justify-center"
            style={{ background: "var(--color-primary)" }}
          >
            <IconZap size={22} className="text-white" />
          </div>
          <span className="font-bold text-lg">Project Prepper</span>
        </div>

        {loading ? (
          <div className="text-center py-8" style={{ color: "var(--color-muted-foreground)" }}>
            Einladung wird geladen...
          </div>
        ) : error && !invitation ? (
          <div className="text-center space-y-4">
            <p
              className="p-3 rounded-lg text-sm"
              style={{
                background: "var(--color-destructive-light)",
                color: "var(--color-destructive)",
              }}
            >
              {error}
            </p>
            <button
              onClick={() => router.push("/login")}
              className="text-sm font-medium"
              style={{ color: "var(--color-primary)" }}
            >
              Zur Anmeldung
            </button>
          </div>
        ) : success ? (
          <div className="text-center space-y-4 py-4">
            <div
              className="w-16 h-16 rounded-full flex items-center justify-center mx-auto"
              style={{ background: "var(--color-success-light)" }}
            >
              <IconCheck size={32} style={{ color: "var(--color-success)" }} />
            </div>
            <div>
              <p className="font-semibold text-lg">Willkommen bei {orgName}!</p>
              <p className="text-sm mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                Du wirst weitergeleitet...
              </p>
            </div>
          </div>
        ) : (
          <>
            <div className="text-center space-y-2">
              <h1 className="text-xl font-bold">Einladung zum Team</h1>
              <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
                {inviterName
                  ? `${inviterName} hat dich eingeladen`
                  : "Du wurdest eingeladen"}
              </p>
            </div>

            {/* Einladungs-Details */}
            <div
              className="p-4 rounded-xl space-y-3"
              style={{
                background: "var(--color-muted)",
                border: "1px solid var(--color-border)",
              }}
            >
              <div className="flex justify-between text-sm">
                <span style={{ color: "var(--color-muted-foreground)" }}>Organisation</span>
                <span className="font-medium">{orgName}</span>
              </div>
              <div className="flex justify-between text-sm">
                <span style={{ color: "var(--color-muted-foreground)" }}>Rolle</span>
                <span className="font-medium capitalize">{roleName}</span>
              </div>
              <div className="flex justify-between text-sm">
                <span style={{ color: "var(--color-muted-foreground)" }}>E-Mail</span>
                <span className="font-medium">{invitation?.email}</span>
              </div>
            </div>

            {error && (
              <p
                className="p-3 rounded-lg text-sm"
                style={{
                  background: "var(--color-destructive-light)",
                  color: "var(--color-destructive)",
                }}
              >
                {error}
              </p>
            )}

            {userId ? (
              <button
                onClick={handleAccept}
                disabled={accepting}
                className="w-full py-2.5 rounded-lg text-sm font-medium text-white transition-colors disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}
                onMouseEnter={(e) =>
                  !accepting && (e.currentTarget.style.background = "var(--color-primary-hover)")
                }
                onMouseLeave={(e) =>
                  !accepting && (e.currentTarget.style.background = "var(--color-primary)")
                }
              >
                {accepting ? "Wird angenommen..." : "Einladung annehmen"}
              </button>
            ) : (
              <div className="space-y-3">
                <p className="text-sm text-center" style={{ color: "var(--color-muted-foreground)" }}>
                  Melde dich an oder registriere dich, um die Einladung anzunehmen.
                </p>
                <button
                  onClick={() =>
                    router.push(`/login?redirect=${encodeURIComponent(`/join?token=${token}`)}`)
                  }
                  className="w-full py-2.5 rounded-lg text-sm font-medium text-white"
                  style={{ background: "var(--color-primary)" }}
                >
                  Anmelden / Registrieren
                </button>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
