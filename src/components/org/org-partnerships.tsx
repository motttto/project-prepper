"use client";

import { useState, useCallback, useEffect } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import { useCurrentUser, isOrgAdmin } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type { OrgPartnership, PartnershipStatus, EquipmentRequest, PartnerInventoryItem } from "@/types/database";
import { IconPlus, IconCheck, IconX, IconHandshake, IconSearch, IconPackage } from "@/components/ui/icons";

const statusLabels: Record<PartnershipStatus, string> = {
  pending: "Ausstehend",
  active: "Aktiv",
  paused: "Pausiert",
  ended: "Beendet",
};

const statusColors: Record<PartnershipStatus, { bg: string; color: string }> = {
  pending: { bg: "var(--color-warning-light)", color: "var(--color-warning)" },
  active: { bg: "var(--color-success-light)", color: "var(--color-success)" },
  paused: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)" },
  ended: { bg: "var(--color-destructive-light)", color: "var(--color-destructive)" },
};

const requestStatusLabels: Record<string, string> = {
  pending: "Ausstehend",
  approved: "Genehmigt",
  rejected: "Abgelehnt",
  cancelled: "Storniert",
  returned: "Zurückgegeben",
};

export function OrgPartnerships() {
  const supabase = createClient();
  const { orgId } = useOrg();
  const currentUser = useCurrentUser();
  const isAdmin = isOrgAdmin(currentUser);

  const [partnerships, setPartnerships] = useState<OrgPartnership[]>([]);
  const [allOrgs, setAllOrgs] = useState<{ id: string; name: string; slug: string }[]>([]);
  const [requests, setRequests] = useState<EquipmentRequest[]>([]);
  const [partnerInventory, setPartnerInventory] = useState<PartnerInventoryItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [showInvite, setShowInvite] = useState(false);
  const [inviteEmail, setInviteEmail] = useState("");
  const [inviteNotes, setInviteNotes] = useState("");
  const [shareInventory, setShareInventory] = useState(true);
  const [shareContacts, setShareContacts] = useState(false);
  const [inviting, setInviting] = useState(false);
  const [inviteError, setInviteError] = useState<string | null>(null);
  const [inviteSuccess, setInviteSuccess] = useState<string | null>(null);
  const [tab, setTab] = useState<"partners" | "requests" | "catalog">("partners");

  // Equipment-Request Form
  const [showRequestForm, setShowRequestForm] = useState(false);
  const [reqItemId, setReqItemId] = useState("");
  const [reqSupplyingOrg, setReqSupplyingOrg] = useState("");
  const [reqQuantity, setReqQuantity] = useState(1);
  const [reqDateFrom, setReqDateFrom] = useState("");
  const [reqDateTo, setReqDateTo] = useState("");
  const [reqPurpose, setReqPurpose] = useState("");

  const loadPartnerships = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("org_partnerships")
      .select(`
        *,
        organization:org_id(name, slug, logo_url),
        partner_organization:partner_org_id(name, slug, logo_url),
        inviter:invited_by(name)
      `)
      .or(`org_id.eq.${orgId},partner_org_id.eq.${orgId}`)
      .order("created_at", { ascending: false });
    if (data) setPartnerships(data as OrgPartnership[]);
    setLoading(false);
  }, [supabase, orgId]);

  const loadOrgs = useCallback(async () => {
    const { data } = await supabase
      .from("organizations")
      .select("id, name, slug")
      .order("name");
    if (data) setAllOrgs(data);
  }, [supabase]);

  const loadRequests = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("equipment_requests")
      .select(`
        *,
        requester:requested_by(name),
        responder:responded_by(name),
        inventory_items:inventory_item_id(name, inventory_number, image_url),
        requesting_org:requesting_org_id(name),
        supplying_org:supplying_org_id(name)
      `)
      .or(`requesting_org_id.eq.${orgId},supplying_org_id.eq.${orgId}`)
      .order("created_at", { ascending: false });
    if (data) setRequests(data as EquipmentRequest[]);
  }, [supabase, orgId]);

  const loadPartnerInventory = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase.rpc("get_partner_inventory", { p_org_id: orgId });
    if (data) setPartnerInventory(data as PartnerInventoryItem[]);
  }, [supabase, orgId]);

  useEffect(() => {
    loadPartnerships();
    loadOrgs();
    loadRequests();
    loadPartnerInventory();
  }, [loadPartnerships, loadOrgs, loadRequests, loadPartnerInventory]);

  useRealtimeTable({ table: "org_partnerships", onDataChange: loadPartnerships });
  useRealtimeTable({ table: "equipment_requests", onDataChange: loadRequests });

  // Partner-Name aus Perspektive der eigenen Org
  function getPartnerName(p: OrgPartnership) {
    if (p.org_id === orgId) {
      return p.partner_organization?.name || "–";
    }
    return p.organization?.name || "–";
  }

  function getPartnerId(p: OrgPartnership) {
    return p.org_id === orgId ? p.partner_org_id : p.org_id;
  }

  // Partnerschafts-Einladung per E-Mail versenden
  async function handleInvite() {
    if (!orgId || !inviteEmail.trim() || !currentUser?.id) return;
    setInviting(true);
    setInviteError(null);
    setInviteSuccess(null);

    // 1. Einladung in DB anlegen
    const { data: inv, error: insertError } = await supabase
      .from("partnership_invitations")
      .insert({
        org_id: orgId,
        invited_by: currentUser.id,
        email: inviteEmail.trim().toLowerCase(),
        share_inventory: shareInventory,
        share_team_contacts: shareContacts,
        notes: inviteNotes.trim() || null,
      })
      .select("id")
      .single();

    if (insertError || !inv) {
      setInviteError(insertError?.message || "Konnte Einladung nicht anlegen");
      setInviting(false);
      return;
    }

    // 2. Edge Function triggern (verschickt E-Mail via org_email_config)
    const { error: fnError } = await supabase.functions.invoke("send-partnership-invite", {
      body: { invitation_id: inv.id },
    });

    if (fnError) {
      setInviteError(`Einladung angelegt, aber E-Mail-Versand fehlgeschlagen: ${fnError.message}`);
      setInviting(false);
      return;
    }

    setInviteSuccess(`Einladung an ${inviteEmail} verschickt`);
    setInviteEmail("");
    setInviteNotes("");
    setInviting(false);
    setTimeout(() => {
      setShowInvite(false);
      setInviteSuccess(null);
    }, 2500);
  }

  // Partnerschaft akzeptieren
  async function acceptPartnership(id: string) {
    await supabase
      .from("org_partnerships")
      .update({
        status: "active",
        accepted_by: currentUser?.id,
        started_at: new Date().toISOString(),
      })
      .eq("id", id);
    await loadPartnerships();
    await loadPartnerInventory();
  }

  // Partnerschaft ablehnen/beenden
  async function endPartnership(id: string, newStatus: "ended" | "paused") {
    await supabase
      .from("org_partnerships")
      .update({
        status: newStatus,
        ended_at: newStatus === "ended" ? new Date().toISOString() : null,
      })
      .eq("id", id);
    await loadPartnerships();
  }

  // Einstellungen ändern
  async function toggleSetting(id: string, field: string, value: boolean) {
    await supabase.from("org_partnerships").update({ [field]: value }).eq("id", id);
    await loadPartnerships();
  }

  // Equipment-Anfrage erstellen
  async function handleEquipmentRequest(e: React.FormEvent) {
    e.preventDefault();
    if (!orgId || !reqItemId || !reqDateFrom || !reqDateTo) return;
    await supabase.from("equipment_requests").insert({
      requesting_org_id: orgId,
      supplying_org_id: reqSupplyingOrg,
      inventory_item_id: reqItemId,
      quantity: reqQuantity,
      date_from: reqDateFrom,
      date_to: reqDateTo,
      purpose: reqPurpose || null,
      requested_by: currentUser?.id,
    });
    setShowRequestForm(false);
    setReqItemId("");
    setReqSupplyingOrg("");
    setReqQuantity(1);
    setReqDateFrom("");
    setReqDateTo("");
    setReqPurpose("");
    await loadRequests();
  }

  // Anfrage genehmigen
  async function approveRequest(id: string) {
    await supabase
      .from("equipment_requests")
      .update({
        status: "approved",
        responded_by: currentUser?.id,
        responded_at: new Date().toISOString(),
      })
      .eq("id", id);
    await loadRequests();
  }

  // Anfrage ablehnen
  async function rejectRequest(id: string) {
    const reason = prompt("Ablehnungsgrund (optional):");
    await supabase
      .from("equipment_requests")
      .update({
        status: "rejected",
        responded_by: currentUser?.id,
        responded_at: new Date().toISOString(),
        rejection_reason: reason || null,
      })
      .eq("id", id);
    await loadRequests();
  }

  // Anfrage als zurückgegeben markieren
  async function markReturned(id: string) {
    await supabase
      .from("equipment_requests")
      .update({
        status: "returned",
        returned_at: new Date().toISOString(),
      })
      .eq("id", id);
    await loadRequests();
  }

  const activePartners = partnerships.filter((p) => p.status === "active");
  const pendingPartners = partnerships.filter((p) => p.status === "pending");

  const incomingRequests = requests.filter((r) => r.supplying_org_id === orgId && r.status === "pending");
  const outgoingRequests = requests.filter((r) => r.requesting_org_id === orgId);

  const inputStyle = {
    border: "1px solid var(--color-border)",
    background: "var(--color-background)",
  };

  if (loading) {
    return <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>Lade Partnerschaften...</p>;
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-lg font-bold flex items-center gap-2">
            <IconHandshake size={20} />
            Partnerschaften
          </h2>
          <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            {activePartners.length} aktive Partner
            {incomingRequests.length > 0 && (
              <span style={{ color: "var(--color-warning)" }}> · {incomingRequests.length} offene Anfragen</span>
            )}
          </p>
        </div>
        {isAdmin && (
          <button
            onClick={() => setShowInvite(true)}
            className="flex items-center gap-1 px-3 py-2 rounded-lg text-sm font-medium text-white"
            style={{ background: "var(--color-primary)" }}
          >
            <IconPlus size={14} />
            Partner einladen
          </button>
        )}
      </div>

      {/* Tabs */}
      <div className="flex gap-1">
        {[
          { key: "partners" as const, label: `Partner (${partnerships.length})` },
          { key: "requests" as const, label: `Anfragen (${requests.length})` },
          { key: "catalog" as const, label: `Katalog (${partnerInventory.length})` },
        ].map((t) => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            className="px-3 py-1.5 rounded-lg text-sm font-medium"
            style={{
              background: tab === t.key ? "var(--color-primary)" : "transparent",
              color: tab === t.key ? "white" : "var(--color-muted-foreground)",
            }}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* Einladungs-Formular */}
      {showInvite && (
        <div
          className="rounded-lg p-4 space-y-3"
          style={{ background: "var(--color-muted)", border: "1px solid var(--color-border)" }}
        >
          <h3 className="text-sm font-semibold">Partner-Organisation einladen</h3>
          <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            E-Mail des Org-Admins eingeben. Die Partnerschaft wird erst aktiv, nachdem der Empfänger sie
            im Browser per Klick bestätigt hat.
          </p>
          <input
            type="email"
            value={inviteEmail}
            onChange={(e) => setInviteEmail(e.target.value)}
            placeholder="partner@beispiel.de"
            className="w-full px-3 py-2 rounded-lg text-sm"
            style={inputStyle}
          />
          <textarea
            value={inviteNotes}
            onChange={(e) => setInviteNotes(e.target.value)}
            placeholder="Notiz (optional, erscheint in der Einladungsmail)"
            rows={2}
            className="w-full px-3 py-2 rounded-lg text-sm resize-none"
            style={inputStyle}
          />
          <div className="flex flex-wrap gap-4">
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={shareInventory}
                onChange={(e) => setShareInventory(e.target.checked)}
              />
              Inventar teilen
            </label>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={shareContacts}
                onChange={(e) => setShareContacts(e.target.checked)}
              />
              Kontakte teilen
            </label>
          </div>
          {inviteError && (
            <div className="text-xs p-2 rounded" style={{ background: "var(--color-destructive-light)", color: "var(--color-destructive)" }}>
              {inviteError}
            </div>
          )}
          {inviteSuccess && (
            <div className="text-xs p-2 rounded" style={{ background: "var(--color-success-light)", color: "var(--color-success)" }}>
              {inviteSuccess}
            </div>
          )}
          <div className="flex gap-2">
            <button
              onClick={handleInvite}
              disabled={!inviteEmail.trim() || inviting}
              className="px-3 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
              style={{ background: "var(--color-primary)" }}
            >
              {inviting ? "Wird gesendet..." : "Einladung senden"}
            </button>
            <button
              onClick={() => setShowInvite(false)}
              className="px-3 py-2 rounded-lg text-sm"
              style={{ border: "1px solid var(--color-border)" }}
            >
              Abbrechen
            </button>
          </div>
        </div>
      )}

      {/* Tab: Partner */}
      {tab === "partners" && (
        <div className="space-y-3">
          {/* Offene Einladungen an uns */}
          {pendingPartners
            .filter((p) => p.partner_org_id === orgId)
            .map((p) => (
              <div
                key={p.id}
                className="rounded-lg p-4"
                style={{ background: "var(--color-warning-light)", border: "1px solid var(--color-warning)" }}
              >
                <div className="flex items-center justify-between">
                  <div>
                    <span className="text-sm font-medium">{getPartnerName(p)}</span>
                    <span className="text-xs ml-2" style={{ color: "var(--color-muted-foreground)" }}>
                      möchte eine Partnerschaft
                      {p.inviter && ` (von ${p.inviter.name})`}
                    </span>
                  </div>
                  <div className="flex gap-2">
                    <button
                      onClick={() => acceptPartnership(p.id)}
                      className="flex items-center gap-1 px-3 py-1.5 rounded text-xs font-medium text-white"
                      style={{ background: "var(--color-success)" }}
                    >
                      <IconCheck size={12} />
                      Annehmen
                    </button>
                    <button
                      onClick={() => endPartnership(p.id, "ended")}
                      className="flex items-center gap-1 px-3 py-1.5 rounded text-xs"
                      style={{ border: "1px solid var(--color-border)" }}
                    >
                      Ablehnen
                    </button>
                  </div>
                </div>
              </div>
            ))}

          {/* Alle Partnerschaften */}
          {partnerships.length === 0 ? (
            <p className="text-sm py-8 text-center" style={{ color: "var(--color-muted-foreground)" }}>
              Noch keine Partnerschaften. Lade eine Organisation ein.
            </p>
          ) : (
            partnerships.map((p) => (
              <div
                key={p.id}
                className="rounded-lg p-4"
                style={{ background: "var(--color-muted)", border: "1px solid var(--color-border-light)" }}
              >
                <div className="flex items-center justify-between">
                  <div>
                    <span className="text-sm font-bold">{getPartnerName(p)}</span>
                    <span
                      className="ml-2 px-2 py-0.5 rounded-full text-xs font-medium"
                      style={{ background: statusColors[p.status].bg, color: statusColors[p.status].color }}
                    >
                      {statusLabels[p.status]}
                    </span>
                  </div>
                  {p.status === "active" && isAdmin && (
                    <div className="flex gap-2">
                      <button
                        onClick={() => endPartnership(p.id, "paused")}
                        className="px-2 py-1 rounded text-xs"
                        style={{ border: "1px solid var(--color-border)" }}
                      >
                        Pausieren
                      </button>
                      <button
                        onClick={() => endPartnership(p.id, "ended")}
                        className="px-2 py-1 rounded text-xs"
                        style={{ color: "var(--color-destructive)" }}
                      >
                        Beenden
                      </button>
                    </div>
                  )}
                </div>

                {/* Settings */}
                {p.status === "active" && (
                  <div className="flex flex-wrap gap-4 mt-3 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    <label className="flex items-center gap-1.5">
                      <input
                        type="checkbox"
                        checked={p.share_inventory}
                        onChange={(e) => isAdmin && toggleSetting(p.id, "share_inventory", e.target.checked)}
                        disabled={!isAdmin}
                      />
                      Inventar teilen
                    </label>
                    <label className="flex items-center gap-1.5">
                      <input
                        type="checkbox"
                        checked={p.share_team_contacts}
                        onChange={(e) => isAdmin && toggleSetting(p.id, "share_team_contacts", e.target.checked)}
                        disabled={!isAdmin}
                      />
                      Kontakte teilen
                    </label>
                    <label className="flex items-center gap-1.5">
                      <input
                        type="checkbox"
                        checked={p.allow_equipment_requests}
                        onChange={(e) => isAdmin && toggleSetting(p.id, "allow_equipment_requests", e.target.checked)}
                        disabled={!isAdmin}
                      />
                      Equipment-Anfragen
                    </label>
                    {p.started_at && (
                      <span>Seit {new Date(p.started_at).toLocaleDateString("de-DE")}</span>
                    )}
                  </div>
                )}
              </div>
            ))
          )}
        </div>
      )}

      {/* Tab: Anfragen */}
      {tab === "requests" && (
        <div className="space-y-3">
          {/* Eingehende Anfragen */}
          {incomingRequests.length > 0 && (
            <div>
              <h3 className="text-xs font-semibold mb-2" style={{ color: "var(--color-muted-foreground)" }}>
                EINGEHENDE ANFRAGEN
              </h3>
              {incomingRequests.map((r) => (
                <div
                  key={r.id}
                  className="rounded-lg p-3 mb-2"
                  style={{ background: "var(--color-warning-light)", border: "1px solid var(--color-warning)" }}
                >
                  <div className="flex items-center justify-between">
                    <div>
                      <span className="text-sm font-medium">
                        {r.inventory_items?.inventory_number} — {r.inventory_items?.name}
                      </span>
                      <div className="text-xs mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>
                        Von: {(r.requesting_org as any)?.name} · {r.quantity}× ·{" "}
                        {new Date(r.date_from).toLocaleDateString("de-DE")} – {new Date(r.date_to).toLocaleDateString("de-DE")}
                        {r.purpose && ` · ${r.purpose}`}
                      </div>
                    </div>
                    <div className="flex gap-2">
                      <button
                        onClick={() => approveRequest(r.id)}
                        className="flex items-center gap-1 px-2 py-1 rounded text-xs font-medium text-white"
                        style={{ background: "var(--color-success)" }}
                      >
                        <IconCheck size={12} />
                      </button>
                      <button
                        onClick={() => rejectRequest(r.id)}
                        className="flex items-center gap-1 px-2 py-1 rounded text-xs"
                        style={{ color: "var(--color-destructive)", border: "1px solid var(--color-destructive)" }}
                      >
                        <IconX size={12} />
                      </button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}

          {/* Alle Anfragen */}
          <h3 className="text-xs font-semibold" style={{ color: "var(--color-muted-foreground)" }}>
            ALLE ANFRAGEN
          </h3>
          {requests.length === 0 ? (
            <p className="text-sm py-4 text-center" style={{ color: "var(--color-muted-foreground)" }}>
              Noch keine Equipment-Anfragen.
            </p>
          ) : (
            requests.map((r) => (
              <div
                key={r.id}
                className="rounded-lg p-3"
                style={{ background: "var(--color-muted)", border: "1px solid var(--color-border-light)" }}
              >
                <div className="flex items-center justify-between">
                  <div>
                    <span className="text-sm font-medium">
                      {r.inventory_items?.inventory_number} — {r.inventory_items?.name}
                    </span>
                    <div className="text-xs mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>
                      {r.requesting_org_id === orgId ? "An" : "Von"}: {r.requesting_org_id === orgId
                        ? (r.supplying_org as any)?.name
                        : (r.requesting_org as any)?.name
                      } · {r.quantity}× · {new Date(r.date_from).toLocaleDateString("de-DE")} – {new Date(r.date_to).toLocaleDateString("de-DE")}
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <span
                      className="px-2 py-0.5 rounded-full text-xs font-medium"
                      style={{
                        background: r.status === "approved" ? "var(--color-success-light)" : r.status === "pending" ? "var(--color-warning-light)" : "var(--color-muted)",
                        color: r.status === "approved" ? "var(--color-success)" : r.status === "pending" ? "var(--color-warning)" : "var(--color-muted-foreground)",
                      }}
                    >
                      {requestStatusLabels[r.status]}
                    </span>
                    {r.status === "approved" && r.requesting_org_id === orgId && (
                      <button
                        onClick={() => markReturned(r.id)}
                        className="px-2 py-1 rounded text-xs"
                        style={{ border: "1px solid var(--color-border)" }}
                      >
                        Zurückgeben
                      </button>
                    )}
                  </div>
                </div>
              </div>
            ))
          )}
        </div>
      )}

      {/* Tab: Partner-Katalog */}
      {tab === "catalog" && (
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              Teilbare Artikel von Partner-Organisationen
            </p>
            {partnerInventory.length > 0 && (
              <button
                onClick={() => setShowRequestForm(true)}
                className="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium text-white"
                style={{ background: "var(--color-primary)" }}
              >
                <IconPackage size={14} />
                Anfrage stellen
              </button>
            )}
          </div>

          {/* Request-Form */}
          {showRequestForm && (
            <form
              onSubmit={handleEquipmentRequest}
              className="rounded-lg p-4 space-y-3"
              style={{ background: "var(--color-muted)", border: "1px solid var(--color-border)" }}
            >
              <h3 className="text-sm font-semibold">Equipment anfragen</h3>
              <select
                value={reqItemId}
                onChange={(e) => {
                  setReqItemId(e.target.value);
                  const item = partnerInventory.find((i) => i.item_id === e.target.value);
                  if (item) setReqSupplyingOrg(item.partner_org_id);
                }}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
                required
              >
                <option value="">Artikel wählen...</option>
                {partnerInventory.map((item) => (
                  <option key={item.item_id} value={item.item_id}>
                    {item.inventory_number} — {item.item_name} ({item.partner_org_name})
                  </option>
                ))}
              </select>
              <div className="grid grid-cols-3 gap-3">
                <div>
                  <label className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Anzahl</label>
                  <input type="number" value={reqQuantity} onChange={(e) => setReqQuantity(Number(e.target.value))} min={1} className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} />
                </div>
                <div>
                  <label className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Von</label>
                  <input type="date" value={reqDateFrom} onChange={(e) => setReqDateFrom(e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} required />
                </div>
                <div>
                  <label className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Bis</label>
                  <input type="date" value={reqDateTo} onChange={(e) => setReqDateTo(e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} required />
                </div>
              </div>
              <input type="text" value={reqPurpose} onChange={(e) => setReqPurpose(e.target.value)} placeholder="Zweck (optional)" className="w-full px-3 py-2 rounded-lg text-sm" style={inputStyle} />
              <div className="flex gap-2">
                <button type="submit" disabled={!reqItemId || !reqDateFrom || !reqDateTo} className="px-3 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50" style={{ background: "var(--color-primary)" }}>Anfrage senden</button>
                <button type="button" onClick={() => setShowRequestForm(false)} className="px-3 py-2 rounded-lg text-sm" style={{ border: "1px solid var(--color-border)" }}>Abbrechen</button>
              </div>
            </form>
          )}

          {partnerInventory.length === 0 ? (
            <p className="text-sm py-8 text-center" style={{ color: "var(--color-muted-foreground)" }}>
              {activePartners.length === 0
                ? "Keine aktiven Partnerschaften. Lade zuerst Partner ein."
                : "Keine Partner haben Artikel zum Teilen freigegeben."}
            </p>
          ) : (
            /* Nach Partner gruppiert */
            Object.entries(
              partnerInventory.reduce<Record<string, PartnerInventoryItem[]>>((acc, item) => {
                const key = item.partner_org_name;
                if (!acc[key]) acc[key] = [];
                acc[key].push(item);
                return acc;
              }, {})
            ).map(([orgName, items]) => (
              <div key={orgName}>
                <h4 className="text-xs font-semibold mb-1.5" style={{ color: "var(--color-muted-foreground)" }}>
                  {orgName.toUpperCase()}
                </h4>
                <div className="space-y-1.5">
                  {items.map((item) => (
                    <div
                      key={item.item_id}
                      className="flex items-center justify-between px-3 py-2 rounded-lg"
                      style={{ background: "var(--color-muted)", border: "1px solid var(--color-border-light)" }}
                    >
                      <div>
                        <span className="text-sm font-medium">{item.inventory_number} — {item.item_name}</span>
                        <span className="text-xs ml-2" style={{ color: "var(--color-muted-foreground)" }}>
                          {item.category} · {item.quantity}× · {Number(item.cost_per_day).toFixed(2)} €/Tag
                        </span>
                        {item.sharing_notes && (
                          <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>{item.sharing_notes}</div>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ))
          )}
        </div>
      )}
    </div>
  );
}
