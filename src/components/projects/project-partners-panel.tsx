"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { useProjectOrgs } from "@/hooks/use-project-orgs";
import { useOrg } from "@/contexts/org-context";
import type { Organization } from "@/types/database";
import { IconX, IconHandshake, IconCheck, IconTrash } from "@/components/ui/icons";

interface ProjectPartnersPanelProps {
  projectId: string;
  isOwner: boolean;
  show: boolean;
  onClose: () => void;
}

const statusLabels: Record<string, string> = {
  accepted: "Aktiv",
  pending: "Ausstehend",
  declined: "Abgelehnt",
  removed: "Entfernt",
};

const statusColors: Record<string, { bg: string; text: string }> = {
  accepted: { bg: "#dcfce7", text: "#16a34a" },
  pending: { bg: "#fef3c7", text: "#b45309" },
  declined: { bg: "#fee2e2", text: "#dc2626" },
  removed: { bg: "#f3f4f6", text: "#6b7280" },
};

export function ProjectPartnersPanel({
  projectId,
  isOwner,
  show,
  onClose,
}: ProjectPartnersPanelProps) {
  const supabase = createClient();
  const { orgId } = useOrg();
  const {
    projectOrgs,
    acceptedOrgs,
    pendingOrgs,
    loading,
    inviteOrg,
    acceptPartnership,
    declinePartnership,
    removeOrg,
  } = useProjectOrgs(projectId);

  const [mode, setMode] = useState<"invite" | null>(null);
  const [allOrgs, setAllOrgs] = useState<Organization[]>([]);
  const [selectedOrgId, setSelectedOrgId] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Alle Organisationen laden (für Einladungs-Dropdown)
  const loadOrgs = useCallback(async () => {
    if (!show || !mode) return;
    const { data } = await supabase
      .from("organizations")
      .select("id, name, slug, description, logo_url, created_by, created_at, updated_at")
      .order("name");
    if (data) setAllOrgs(data as Organization[]);
  }, [supabase, show, mode]);

  useEffect(() => {
    loadOrgs();
  }, [loadOrgs]);

  // Bereits eingeladene/aktive Org-IDs
  const existingOrgIds = new Set(projectOrgs.map((po) => po.org_id));
  const availableOrgs = allOrgs.filter((o) => !existingOrgIds.has(o.id));

  // Prüfe ob MEINE Org eine pending Einladung hat
  const myPendingInvite = projectOrgs.find(
    (po) => po.org_id === orgId && po.status === "pending"
  );

  async function handleInvite() {
    if (!selectedOrgId) return;
    setSaving(true);
    setError(null);
    const result = await inviteOrg(selectedOrgId);
    if (result.error) {
      setError(result.error);
    } else {
      setSelectedOrgId("");
      setMode(null);
    }
    setSaving(false);
  }

  async function handleAccept() {
    if (!myPendingInvite) return;
    setSaving(true);
    await acceptPartnership(myPendingInvite.id);
    setSaving(false);
  }

  async function handleDecline() {
    if (!myPendingInvite) return;
    setSaving(true);
    await declinePartnership(myPendingInvite.id);
    setSaving(false);
    onClose();
  }

  async function handleRemove(projectOrgId: string) {
    if (!confirm("Partner-Organisation wirklich entfernen? Offene Buchungsanfragen werden abgelehnt.")) return;
    await removeOrg(projectOrgId);
  }

  if (!show) return null;

  const activePartners = projectOrgs.filter(
    (po) => po.status === "accepted" || po.status === "pending"
  );

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center"
      style={{ background: "rgba(0,0,0,0.4)" }}
    >
      <div
        className="rounded-xl shadow-2xl w-full max-w-lg max-h-[80vh] overflow-y-auto"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between p-4"
          style={{ borderBottom: "1px solid var(--color-border)" }}
        >
          <h2 className="text-lg font-semibold flex items-center gap-2">
            <IconHandshake size={20} />
            Partner-Organisationen
          </h2>
          <button onClick={onClose} className="p-1 rounded hover:opacity-70">
            <IconX size={20} />
          </button>
        </div>

        <div className="p-4 space-y-4">
          {loading ? (
            <p style={{ color: "var(--color-text-muted)" }}>Laden...</p>
          ) : (
            <>
              {/* Eingehende Einladung für MEINE Org */}
              {myPendingInvite && (
                <div
                  className="p-4 rounded-lg"
                  style={{ background: "var(--color-info-light)", border: "1px solid var(--color-info)" }}
                >
                  <p className="text-sm font-medium mb-2">
                    Deine Organisation wurde als Partner zu diesem Projekt eingeladen.
                  </p>
                  <div className="flex gap-2">
                    <button
                      onClick={handleAccept}
                      disabled={saving}
                      className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium disabled:opacity-50"
                      style={{ background: "var(--color-primary)", color: "white" }}
                    >
                      <IconCheck size={14} /> Annehmen
                    </button>
                    <button
                      onClick={handleDecline}
                      disabled={saving}
                      className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium disabled:opacity-50"
                      style={{ border: "1px solid var(--color-border)", color: "var(--color-text-muted)" }}
                    >
                      <IconX size={14} /> Ablehnen
                    </button>
                  </div>
                </div>
              )}

              {/* Partner-Liste */}
              <div>
                <h3
                  className="text-sm font-semibold mb-2"
                  style={{ color: "var(--color-text-muted)" }}
                >
                  Organisationen ({activePartners.length})
                </h3>
                <div className="space-y-2">
                  {activePartners.map((po) => {
                    const color = statusColors[po.status] || statusColors.pending;
                    return (
                      <div
                        key={po.id}
                        className="flex items-center justify-between p-2 rounded-lg"
                        style={{ background: "var(--color-muted)" }}
                      >
                        <div className="flex items-center gap-3">
                          <div
                            className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-semibold"
                            style={{ background: color.bg, color: color.text }}
                          >
                            {po.organizations?.name?.[0]?.toUpperCase() || "?"}
                          </div>
                          <div>
                            <p className="text-sm font-medium">
                              {po.organizations?.name || "Unbekannt"}
                            </p>
                            <p className="text-xs" style={{ color: "var(--color-text-muted)" }}>
                              {po.role === "creator" ? "Ersteller" : "Partner"}
                              {po.organizations?.slug && ` · ${po.organizations.slug}`}
                            </p>
                          </div>
                        </div>
                        <div className="flex items-center gap-2">
                          <span
                            className="px-2 py-0.5 rounded-full text-xs font-medium"
                            style={{ background: color.bg, color: color.text }}
                          >
                            {statusLabels[po.status]}
                          </span>
                          {isOwner && po.role !== "creator" && po.status === "accepted" && (
                            <button
                              onClick={() => handleRemove(po.id)}
                              className="p-1 rounded hover:opacity-70"
                              style={{ color: "var(--color-destructive)" }}
                              title="Entfernen"
                            >
                              <IconTrash size={14} />
                            </button>
                          )}
                        </div>
                      </div>
                    );
                  })}
                  {activePartners.length === 0 && (
                    <p className="text-sm py-2" style={{ color: "var(--color-text-muted)" }}>
                      Noch keine Partner-Organisationen.
                    </p>
                  )}
                </div>
              </div>

              {/* Einladen (nur für Owner) */}
              {isOwner && !mode && (
                <button
                  onClick={() => setMode("invite")}
                  className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium"
                  style={{ background: "var(--color-primary)", color: "white" }}
                >
                  <IconHandshake size={16} /> Organisation einladen
                </button>
              )}

              {isOwner && mode === "invite" && (
                <div
                  className="p-3 rounded-lg space-y-3"
                  style={{ border: "1px solid var(--color-border)" }}
                >
                  <h3 className="text-sm font-semibold">Organisation einladen</h3>

                  {availableOrgs.length === 0 ? (
                    <p className="text-sm" style={{ color: "var(--color-text-muted)" }}>
                      Keine weiteren Organisationen verfügbar.
                    </p>
                  ) : (
                    <div>
                      <label
                        className="block text-xs mb-1"
                        style={{ color: "var(--color-text-muted)" }}
                      >
                        Organisation
                      </label>
                      <select
                        value={selectedOrgId}
                        onChange={(e) => setSelectedOrgId(e.target.value)}
                        className="w-full p-2 rounded-lg text-sm"
                        style={{
                          background: "var(--color-surface)",
                          border: "1px solid var(--color-border)",
                        }}
                      >
                        <option value="">Bitte wählen...</option>
                        {availableOrgs.map((o) => (
                          <option key={o.id} value={o.id}>
                            {o.name} ({o.slug})
                          </option>
                        ))}
                      </select>
                    </div>
                  )}

                  {error && (
                    <p className="text-xs" style={{ color: "var(--color-destructive)" }}>
                      {error}
                    </p>
                  )}

                  <div className="flex gap-2">
                    <button
                      onClick={handleInvite}
                      disabled={!selectedOrgId || saving}
                      className="px-3 py-1.5 rounded-lg text-sm font-medium disabled:opacity-50"
                      style={{ background: "var(--color-primary)", color: "white" }}
                    >
                      {saving ? "Wird eingeladen..." : "Einladen"}
                    </button>
                    <button
                      onClick={() => {
                        setMode(null);
                        setSelectedOrgId("");
                        setError(null);
                      }}
                      className="px-3 py-1.5 rounded-lg text-sm"
                      style={{ border: "1px solid var(--color-border)" }}
                    >
                      Abbrechen
                    </button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  );
}
