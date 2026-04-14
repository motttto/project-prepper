"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type {
  CooperationAgreement,
  AgreementInventoryContribution,
  AgreementRole,
  AgreementSignature,
} from "@/types/database";
import { AgreementWizard } from "./agreement-wizard";
import { IconPlus, IconCheck, IconX, IconShield, IconClock } from "@/components/ui/icons";
import { showToast } from "@/hooks/use-toast";
import { formatFormulaLabel } from "@/lib/agreement-calc";

interface TabAgreementProps {
  projectId: string;
}

export function TabAgreement({ projectId }: TabAgreementProps) {
  const supabase = createClient();
  const currentUser = useCurrentUser();

  const [agreement, setAgreement] = useState<CooperationAgreement | null>(null);
  const [contributions, setContributions] = useState<AgreementInventoryContribution[]>([]);
  const [roles, setRoles] = useState<AgreementRole[]>([]);
  const [signatures, setSignatures] = useState<AgreementSignature[]>([]);
  const [loading, setLoading] = useState(true);
  const [showWizard, setShowWizard] = useState(false);
  const [signingComment, setSigningComment] = useState("");
  const [showDeclineDialog, setShowDeclineDialog] = useState(false);

  const loadAgreement = useCallback(async () => {
    const { data: ag } = await supabase
      .from("cooperation_agreements")
      .select("*")
      .eq("project_id", projectId)
      .maybeSingle();

    if (!ag) {
      setAgreement(null);
      setLoading(false);
      return;
    }

    setAgreement(ag as CooperationAgreement);

    const [contribRes, rolesRes, sigRes] = await Promise.all([
      supabase
        .from("agreement_inventory_contributions")
        .select("*, inventory_items(name, inventory_number), contributor:contributor_id(name)")
        .eq("agreement_id", ag.id),
      supabase
        .from("agreement_roles")
        .select("*, profiles(name, avatar_url)")
        .eq("agreement_id", ag.id),
      supabase
        .from("agreement_signatures")
        .select("*, profiles(name, avatar_url)")
        .eq("agreement_id", ag.id),
    ]);

    if (contribRes.data) setContributions(contribRes.data as AgreementInventoryContribution[]);
    if (rolesRes.data) setRoles(rolesRes.data as AgreementRole[]);
    if (sigRes.data) setSignatures(sigRes.data as AgreementSignature[]);
    setLoading(false);
  }, [supabase, projectId]);

  useEffect(() => { loadAgreement(); }, [loadAgreement]);

  useRealtimeTable({
    table: "cooperation_agreements",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadAgreement,
  });
  useRealtimeTable({
    table: "agreement_signatures",
    onDataChange: loadAgreement,
    enabled: !!agreement,
  });

  const mySignature = signatures.find((s) => s.profile_id === currentUser?.id);
  const canSign = mySignature && !mySignature.signed_at && !mySignature.declined_at && agreement?.status === "signing";
  const signedCount = signatures.filter((s) => s.signed_at).length;
  const totalRequired = signatures.length;

  async function handleSign(vote: "sign" | "decline") {
    if (!currentUser || !agreement) return;
    const update = vote === "sign"
      ? { signed_at: new Date().toISOString(), declined_at: null, comment: signingComment.trim() || null }
      : { declined_at: new Date().toISOString(), signed_at: null, comment: signingComment.trim() || null };

    const { error } = await supabase
      .from("agreement_signatures")
      .update(update)
      .eq("agreement_id", agreement.id)
      .eq("profile_id", currentUser.id);

    if (error) {
      showToast("Fehler: " + error.message, "error");
    } else {
      showToast(vote === "sign" ? "Vereinbarung unterschrieben" : "Abgelehnt", "success");
      setSigningComment("");
      setShowDeclineDialog(false);
    }
  }

  if (loading) {
    return <div className="py-8 text-center text-sm" style={{ color: "var(--color-muted-foreground)" }}>Laden...</div>;
  }

  // Keine Vereinbarung → Wizard anbieten
  if (!agreement) {
    return (
      <div>
        <div
          className="rounded-xl p-8 text-center"
          style={{ background: "var(--color-surface)", border: "2px dashed var(--color-border)" }}
        >
          <IconShield size={40} className="mx-auto mb-3" style={{ color: "var(--color-primary)", opacity: 0.6 }} />
          <h2 className="text-lg font-semibold mb-2" style={{ color: "var(--color-foreground)" }}>
            Noch keine Kooperationsvereinbarung
          </h2>
          <p className="text-sm mb-5" style={{ color: "var(--color-muted-foreground)" }}>
            Bevor Gewinnverteilung berechnet werden kann, müssen alle Beteiligten eine Vereinbarung abschließen:
            Wer bringt was ein, wer macht was, wie wird der Gewinn aufgeteilt?
          </p>
          <button
            onClick={() => setShowWizard(true)}
            className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium text-white"
            style={{ background: "var(--color-primary)" }}
          >
            <IconPlus size={16} /> Vereinbarung erstellen
          </button>
        </div>

        {showWizard && (
          <AgreementWizard
            projectId={projectId}
            onClose={() => setShowWizard(false)}
            onCreated={() => {
              setShowWizard(false);
              loadAgreement();
            }}
          />
        )}
      </div>
    );
  }

  const statusColors: Record<string, { bg: string; color: string; label: string }> = {
    draft: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)", label: "Entwurf" },
    signing: { bg: "var(--color-warning-light)", color: "var(--color-warning)", label: "Unterschriften ausstehend" },
    active: { bg: "var(--color-success-light)", color: "var(--color-success)", label: "Aktiv · gelockt" },
    amended: { bg: "var(--color-info-light)", color: "var(--color-info)", label: "Geändert" },
    terminated: { bg: "var(--color-destructive-light)", color: "var(--color-destructive)", label: "Beendet" },
  };
  const statusInfo = statusColors[agreement.status];

  return (
    <div className="space-y-5">
      {/* Header-Karte */}
      <div
        className="rounded-xl p-5 flex items-center justify-between"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
      >
        <div>
          <div className="flex items-center gap-2 mb-1">
            <IconShield size={18} style={{ color: "var(--color-primary)" }} />
            <h2 className="text-lg font-semibold" style={{ color: "var(--color-foreground)" }}>
              Kooperationsvereinbarung
            </h2>
            <span
              className="text-xs px-2 py-0.5 rounded-full font-medium"
              style={{ background: statusInfo.bg, color: statusInfo.color }}
            >
              {statusInfo.label}
            </span>
          </div>
          <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            Version {agreement.version} · {signedCount}/{totalRequired} unterschrieben
          </p>
        </div>
      </div>

      {/* Signaturen-Status */}
      <div
        className="rounded-xl p-5"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
      >
        <h3 className="text-sm font-semibold mb-3" style={{ color: "var(--color-foreground)" }}>
          Unterschriften
        </h3>
        <div className="space-y-2">
          {signatures.map((s) => {
            const isMe = s.profile_id === currentUser?.id;
            return (
              <div key={s.id} className="flex items-center justify-between p-2.5 rounded-lg" style={{ background: "var(--color-muted)" }}>
                <div className="flex items-center gap-2">
                  {s.signed_at ? (
                    <IconCheck size={16} style={{ color: "var(--color-success)" }} />
                  ) : s.declined_at ? (
                    <IconX size={16} style={{ color: "var(--color-destructive)" }} />
                  ) : (
                    <IconClock size={16} style={{ color: "var(--color-warning)" }} />
                  )}
                  <span className="text-sm" style={{ color: "var(--color-foreground)" }}>
                    {s.profiles?.name || "Unbekannt"}
                    {isMe && <span className="text-xs ml-1" style={{ color: "var(--color-muted-foreground)" }}>(du)</span>}
                  </span>
                  {s.comment && (
                    <span className="text-xs italic" style={{ color: "var(--color-muted-foreground)" }}>
                      &quot;{s.comment}&quot;
                    </span>
                  )}
                </div>
                <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                  {s.signed_at ? `Unterschrieben ${new Date(s.signed_at).toLocaleDateString("de-DE")}`
                    : s.declined_at ? `Abgelehnt ${new Date(s.declined_at).toLocaleDateString("de-DE")}`
                      : "Ausstehend"}
                </span>
              </div>
            );
          })}
        </div>

        {/* Eigene Signatur */}
        {canSign && (
          <div className="mt-4 pt-4 border-t" style={{ borderColor: "var(--color-border)" }}>
            <input
              type="text"
              value={signingComment}
              onChange={(e) => setSigningComment(e.target.value)}
              placeholder="Kommentar zur Unterschrift (optional)"
              className="w-full px-3 py-2 rounded-lg text-sm mb-3"
              style={{ background: "var(--color-muted)", border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}
            />
            <div className="flex gap-2">
              <button
                onClick={() => handleSign("sign")}
                className="flex items-center gap-1 px-4 py-2 rounded-lg text-sm font-medium text-white"
                style={{ background: "var(--color-success)" }}
              >
                <IconCheck size={14} /> Unterschreiben
              </button>
              <button
                onClick={() => setShowDeclineDialog(true)}
                className="flex items-center gap-1 px-4 py-2 rounded-lg text-sm font-medium text-white"
                style={{ background: "var(--color-destructive)" }}
              >
                <IconX size={14} /> Ablehnen
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Rollen */}
      {roles.length > 0 && (
        <div
          className="rounded-xl p-5"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
        >
          <h3 className="text-sm font-semibold mb-3" style={{ color: "var(--color-foreground)" }}>
            Rollen & Arbeitsanteile
          </h3>
          <div className="space-y-2">
            {roles.map((r) => (
              <div key={r.id} className="p-3 rounded-lg" style={{ background: "var(--color-muted)" }}>
                <div className="flex items-center justify-between mb-1">
                  <div className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>
                    {r.profiles?.name} — {r.role_title}
                  </div>
                </div>
                {r.responsibilities && (
                  <p className="text-xs mb-2" style={{ color: "var(--color-muted-foreground)" }}>
                    {r.responsibilities}
                  </p>
                )}
                <div className="flex flex-wrap gap-3 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                  {Number(r.hourly_rate) > 0 && <span>{Number(r.hours_estimate)}h × {Number(r.hourly_rate).toFixed(2)}€</span>}
                  {Number(r.capital_contribution) > 0 && <span>Kapital: {Number(r.capital_contribution).toFixed(2)}€</span>}
                  {Number(r.fixed_amount) > 0 && <span>Festbetrag: {Number(r.fixed_amount).toFixed(2)}€</span>}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Inventar-Beiträge */}
      {contributions.length > 0 && (
        <div
          className="rounded-xl p-5"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
        >
          <h3 className="text-sm font-semibold mb-3" style={{ color: "var(--color-foreground)" }}>
            Inventar-Beiträge
          </h3>
          <div className="space-y-2">
            {contributions.map((c) => (
              <div key={c.id} className="flex items-center justify-between p-3 rounded-lg" style={{ background: "var(--color-muted)" }}>
                <div>
                  <div className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>
                    {c.inventory_items?.name} {c.quantity > 1 && `(${c.quantity}x)`}
                  </div>
                  <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    von {c.contributor?.name} · {Number(c.daily_rate).toFixed(2)}€/Tag
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Gewinn-Formel */}
      <div
        className="rounded-xl p-5"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
      >
        <h3 className="text-sm font-semibold mb-2" style={{ color: "var(--color-foreground)" }}>
          Gewinnverteilung
        </h3>
        <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          {formatFormulaLabel(agreement.profit_formula)}
        </p>
        {agreement.profit_formula.pre_deductions && agreement.profit_formula.pre_deductions.length > 0 && (
          <div className="mt-3 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            <div className="font-medium">Vorab-Abzüge:</div>
            {agreement.profit_formula.pre_deductions.map((d, i) => (
              <div key={i}>· {d.label}: {d.amount.toFixed(2)}€</div>
            ))}
          </div>
        )}
      </div>

      {/* Exit-Regeln */}
      <div
        className="rounded-xl p-5"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
      >
        <h3 className="text-sm font-semibold mb-2" style={{ color: "var(--color-foreground)" }}>
          Exit-Regeln
        </h3>
        <ul className="text-sm space-y-1" style={{ color: "var(--color-foreground)" }}>
          <li>· {agreement.exit_rules.forfeit_if_exit_before_event ? "Bei Austritt vor Event: Verzicht auf Gewinnanteil" : "Gewinnanteil bleibt auch bei Austritt erhalten"}</li>
          <li>· Inventar-Rückgabefrist: {agreement.exit_rules.inventory_return_window_days ?? 7} Tage</li>
        </ul>
      </div>

      {/* Freitext */}
      {agreement.additional_terms && (
        <div
          className="rounded-xl p-5"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
        >
          <h3 className="text-sm font-semibold mb-2" style={{ color: "var(--color-foreground)" }}>
            Zusatzklauseln
          </h3>
          <p className="text-sm whitespace-pre-wrap" style={{ color: "var(--color-foreground)" }}>
            {agreement.additional_terms}
          </p>
        </div>
      )}

      {/* Decline-Dialog */}
      {showDeclineDialog && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: "rgba(0,0,0,0.5)" }}>
          <div className="w-full max-w-md rounded-xl p-5" style={{ background: "var(--color-surface)" }}>
            <h3 className="text-lg font-semibold mb-3" style={{ color: "var(--color-foreground)" }}>
              Vereinbarung ablehnen?
            </h3>
            <p className="text-sm mb-4" style={{ color: "var(--color-muted-foreground)" }}>
              Die Vereinbarung wird auf &quot;Entwurf&quot; zurückgesetzt und muss überarbeitet werden.
            </p>
            <input
              type="text"
              value={signingComment}
              onChange={(e) => setSigningComment(e.target.value)}
              placeholder="Grund (empfohlen)"
              className="w-full px-3 py-2 rounded-lg text-sm mb-4"
              style={{ background: "var(--color-muted)", border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}
            />
            <div className="flex gap-2 justify-end">
              <button onClick={() => setShowDeclineDialog(false)} className="px-4 py-2 rounded-lg text-sm" style={{ color: "var(--color-muted-foreground)" }}>
                Abbrechen
              </button>
              <button onClick={() => handleSign("decline")} className="px-4 py-2 rounded-lg text-sm font-medium text-white" style={{ background: "var(--color-destructive)" }}>
                Ablehnen
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
