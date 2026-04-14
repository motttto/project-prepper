"use client";

import { useState, useEffect, useCallback, Suspense } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { IconZap, IconCheck, IconHandshake } from "@/components/ui/icons";

type PartnershipInvite = {
  id: string;
  email: string;
  status: string;
  org_id: string;
  share_inventory: boolean;
  share_team_contacts: boolean;
  notes: string | null;
  expires_at: string | null;
  organizations: { name: string } | null;
  inviter: { name: string } | null;
};

type UserOrg = {
  org_id: string;
  is_active: boolean;
  role_name: string;
  org_name: string;
};

export default function PartnerInvitePageWrapper() {
  return (
    <Suspense>
      <PartnerInvitePage />
    </Suspense>
  );
}

function PartnerInvitePage() {
  const supabase = createClient();
  const router = useRouter();
  const searchParams = useSearchParams();
  const token = searchParams.get("token");

  const [loading, setLoading] = useState(true);
  const [invitation, setInvitation] = useState<PartnershipInvite | null>(null);
  const [userId, setUserId] = useState<string | null>(null);
  const [userOrgs, setUserOrgs] = useState<UserOrg[]>([]);
  const [selectedOrgId, setSelectedOrgId] = useState<string>("");
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const load = useCallback(async () => {
    if (!token) {
      setError("Kein Einladungs-Token in der URL.");
      setLoading(false);
      return;
    }

    // User-Status
    const { data: { user } } = await supabase.auth.getUser();
    setUserId(user?.id ?? null);

    // Einladung laden (RLS: authenticated USING true)
    const { data: invData, error: invErr } = await supabase
      .from("partnership_invitations")
      .select(`
        id, email, status, org_id, share_inventory, share_team_contacts, notes, expires_at,
        organizations:org_id(name),
        inviter:invited_by(name)
      `)
      .eq("id", token)
      .single();

    if (invErr || !invData) {
      setError("Einladung nicht gefunden oder abgelaufen.");
      setLoading(false);
      return;
    }

    // deno-lint-ignore no-explicit-any
    setInvitation(invData as unknown as PartnershipInvite);

    // Orgs des Users laden (nur wenn eingeloggt)
    if (user) {
      const { data: memberships } = await supabase
        .from("org_memberships")
        .select(`
          org_id, is_active,
          roles:role_id(name),
          organizations:org_id(name)
        `)
        .eq("profile_id", user.id);

      const orgs: UserOrg[] = (memberships ?? []).map((m) => ({
        org_id: m.org_id,
        is_active: m.is_active,
        // deno-lint-ignore no-explicit-any
        role_name: (m.roles as any)?.name ?? "",
        // deno-lint-ignore no-explicit-any
        org_name: (m.organizations as any)?.name ?? "Unbekannt",
      }));
      setUserOrgs(orgs);

      // Vorauswahl: erste aktive Admin-Org, die nicht die einladende ist
      const admin = orgs.find(
        (o) => o.is_active && o.role_name === "admin" && o.org_id !== invData.org_id
      );
      if (admin) setSelectedOrgId(admin.org_id);
    }

    setLoading(false);
  }, [supabase, token]);

  useEffect(() => {
    load();
  }, [load]);

  // Einladung akzeptieren
  async function handleAccept() {
    if (!invitation || !userId || !selectedOrgId) return;
    setProcessing(true);
    setError(null);

    try {
      // 1. Partnership anlegen (direkt aktiv, da zweiseitige Zustimmung durch den Klick erfolgt)
      const { data: partnership, error: partErr } = await supabase
        .from("org_partnerships")
        .insert({
          org_id: invitation.org_id,
          partner_org_id: selectedOrgId,
          status: "active",
          share_inventory: invitation.share_inventory,
          share_team_contacts: invitation.share_team_contacts,
          accepted_by: userId,
          started_at: new Date().toISOString(),
        })
        .select("id")
        .single();

      if (partErr || !partnership) {
        throw new Error(partErr?.message || "Partnership konnte nicht angelegt werden");
      }

      // 2. Einladung als akzeptiert markieren
      await supabase
        .from("partnership_invitations")
        .update({
          status: "accepted",
          responded_at: new Date().toISOString(),
          responded_by: userId,
          accepted_partnership_id: partnership.id,
        })
        .eq("id", invitation.id);

      setSuccess(true);
      setTimeout(() => router.push("/dashboard"), 2000);
    } catch (err) {
      setError((err as Error).message);
      setProcessing(false);
    }
  }

  // Einladung ablehnen
  async function handleDecline() {
    if (!invitation || !userId) return;
    setProcessing(true);
    await supabase
      .from("partnership_invitations")
      .update({
        status: "declined",
        responded_at: new Date().toISOString(),
        responded_by: userId,
      })
      .eq("id", invitation.id);
    setSuccess(true);
    setTimeout(() => router.push("/dashboard"), 2000);
  }

  // ── Rendering ──
  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center" style={{ background: "var(--color-background)" }}>
        <div className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>Lade Einladung...</div>
      </div>
    );
  }

  if (error) {
    return (
      <Shell>
        <h1 className="text-xl font-bold">Einladung ungültig</h1>
        <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>{error}</p>
        <Btn onClick={() => router.push("/login")}>Zum Login</Btn>
      </Shell>
    );
  }

  if (!invitation) return null;

  // Schon abgeschlossen?
  if (invitation.status !== "pending") {
    return (
      <Shell>
        <h1 className="text-xl font-bold">Einladung bereits beantwortet</h1>
        <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          Diese Einladung wurde bereits {invitation.status === "accepted" ? "angenommen" : "abgelehnt"} oder ist abgelaufen.
        </p>
        <Btn onClick={() => router.push("/dashboard")}>Zum Dashboard</Btn>
      </Shell>
    );
  }

  // Erfolgs-Bestätigung
  if (success) {
    return (
      <Shell>
        <div className="flex flex-col items-center gap-3">
          <div className="w-12 h-12 rounded-full flex items-center justify-center" style={{ background: "var(--color-success-light)" }}>
            <IconCheck size={24} style={{ color: "var(--color-success)" }} />
          </div>
          <h1 className="text-xl font-bold">Erledigt</h1>
          <p className="text-sm text-center" style={{ color: "var(--color-muted-foreground)" }}>
            Du wirst zum Dashboard weitergeleitet...
          </p>
        </div>
      </Shell>
    );
  }

  const orgName = invitation.organizations?.name ?? "Ein Team";
  const inviterName = invitation.inviter?.name ?? "";
  const sharingParts: string[] = [];
  if (invitation.share_inventory) sharingParts.push("Inventar");
  if (invitation.share_team_contacts) sharingParts.push("Team-Kontakte");
  const sharingText = sharingParts.length ? sharingParts.join(" & ") : "keine Datenfreigaben";

  // Nicht eingeloggt
  if (!userId) {
    return (
      <Shell>
        <h1 className="text-xl font-bold">Partnerschaftsanfrage</h1>
        <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          <strong>{orgName}</strong> möchte mit deiner Organisation zusammenarbeiten.
          Melde dich an, um die Anfrage zu prüfen.
        </p>
        <Btn onClick={() => router.push(`/login?redirect=/partner-invite?token=${invitation.id}`)}>
          Anmelden
        </Btn>
      </Shell>
    );
  }

  // Eingeloggt, aber keine aktive Admin-Org außer vielleicht der einladenden
  const eligibleOrgs = userOrgs.filter(
    (o) => o.is_active && o.role_name === "admin" && o.org_id !== invitation.org_id
  );

  if (eligibleOrgs.length === 0) {
    return (
      <Shell>
        <h1 className="text-xl font-bold">Eigene Organisation benötigt</h1>
        <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          Um eine Partnerschaft mit <strong>{orgName}</strong> einzugehen, musst du Admin
          einer eigenen Organisation sein. Lege eine an und komme dann über den Link aus der
          Einladungsmail zurück.
        </p>
        <Btn onClick={() => router.push("/org/new")}>Eigene Organisation anlegen</Btn>
      </Shell>
    );
  }

  // Haupt-Flow: Zustimmung
  return (
    <Shell>
      <div className="flex items-center gap-3">
        <div className="w-10 h-10 rounded-full flex items-center justify-center" style={{ background: "var(--color-primary)" }}>
          <IconHandshake size={20} className="text-white" />
        </div>
        <div>
          <h1 className="text-lg font-bold leading-tight">Partnerschaftsanfrage</h1>
          <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            von {orgName}{inviterName && ` · ${inviterName}`}
          </p>
        </div>
      </div>

      <div
        className="rounded-lg p-4 space-y-2"
        style={{ background: "var(--color-muted)", border: "1px solid var(--color-border)" }}
      >
        <div className="text-xs font-semibold" style={{ color: "var(--color-muted-foreground)" }}>
          Folgende Datenfreigaben werden aktiviert
        </div>
        <div className="text-sm">{sharingText}</div>
        {invitation.notes && (
          <div className="text-xs italic pt-2 border-t" style={{ borderColor: "var(--color-border)" }}>
            „{invitation.notes}"
          </div>
        )}
      </div>

      {eligibleOrgs.length > 1 && (
        <div>
          <label className="block text-xs font-semibold mb-1" style={{ color: "var(--color-muted-foreground)" }}>
            Partnerschaft für meine Organisation
          </label>
          <select
            value={selectedOrgId}
            onChange={(e) => setSelectedOrgId(e.target.value)}
            className="w-full px-3 py-2 rounded-lg text-sm"
            style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
          >
            {eligibleOrgs.map((o) => (
              <option key={o.org_id} value={o.org_id}>{o.org_name}</option>
            ))}
          </select>
        </div>
      )}

      {eligibleOrgs.length === 1 && (
        <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
          Partnerschaft wird für deine Organisation <strong>{eligibleOrgs[0].org_name}</strong> angelegt.
        </p>
      )}

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

      <div className="flex gap-2">
        <Btn onClick={handleAccept} disabled={processing || !selectedOrgId}>
          {processing ? "..." : "Annehmen"}
        </Btn>
        <button
          onClick={handleDecline}
          disabled={processing}
          className="px-4 py-2 rounded-lg text-sm font-medium"
          style={{
            border: "1px solid var(--color-border)",
            background: "transparent",
            color: "var(--color-foreground)",
          }}
        >
          Ablehnen
        </button>
      </div>
    </Shell>
  );
}

// ── Helper-Komponenten ──
function Shell({ children }: { children: React.ReactNode }) {
  return (
    <div
      className="min-h-screen flex items-center justify-center p-4"
      style={{ background: "var(--color-background)" }}
    >
      <div
        className="w-full max-w-md rounded-xl p-8 space-y-4"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
      >
        <div className="flex items-center gap-2 mb-2">
          <div className="w-8 h-8 rounded-lg flex items-center justify-center" style={{ background: "var(--color-primary)" }}>
            <IconZap size={16} className="text-white" />
          </div>
          <span className="font-bold">Project Prepper</span>
        </div>
        {children}
      </div>
    </div>
  );
}

function Btn({
  onClick,
  disabled,
  children,
}: {
  onClick: () => void;
  disabled?: boolean;
  children: React.ReactNode;
}) {
  return (
    <button
      onClick={onClick}
      disabled={disabled}
      className="px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
      style={{ background: "var(--color-primary)" }}
    >
      {children}
    </button>
  );
}
