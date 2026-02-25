"use client";

import { useState, useRef, useEffect, useCallback } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { useInvitations } from "@/hooks/use-invitations";
import { useBookingApprovals } from "@/hooks/use-booking-approvals";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import {
  IconBell,
  IconCheck,
  IconX,
  IconHandshake,
} from "@/components/ui/icons";

interface InvitationBellProps {
  userId: string | undefined;
  orgId: string | null;
}

// Partner-Einladung Type (inline, kein separater Hook nötig)
interface PartnerInvite {
  id: string;
  project_id: string;
  status: string;
  created_at: string;
  projects: { name: string } | null;
  profiles: { name: string } | null;
}

export function InvitationBell({ userId, orgId }: InvitationBellProps) {
  const supabase = createClient();
  const { invitations, pendingCount: projectPendingCount, accept, decline } =
    useInvitations(userId);
  const {
    pendingBookings,
    pendingCount: bookingPendingCount,
    approve: approveBooking,
    reject: rejectBooking,
  } = useBookingApprovals(orgId);

  const [partnerInvites, setPartnerInvites] = useState<PartnerInvite[]>([]);
  const [open, setOpen] = useState(false);
  const [processing, setProcessing] = useState<string | null>(null);
  const ref = useRef<HTMLDivElement>(null);
  const router = useRouter();

  // Partner-Einladungen laden (pending project_orgs für meine Org)
  const loadPartnerInvites = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("project_orgs")
      .select(
        "id, project_id, status, created_at, projects(name), profiles!invited_by(name)"
      )
      .eq("org_id", orgId)
      .eq("status", "pending")
      .order("created_at", { ascending: false });
    if (data) setPartnerInvites(data as unknown as PartnerInvite[]);
  }, [supabase, orgId]);

  useEffect(() => {
    loadPartnerInvites();
  }, [loadPartnerInvites]);

  // Realtime: Partner-Einladungen live
  useRealtimeTable({
    table: "project_orgs",
    onDataChange: loadPartnerInvites,
    enabled: !!orgId,
  });

  const totalCount =
    projectPendingCount + partnerInvites.length + bookingPendingCount;

  // Click-Outside schließt Dropdown
  useEffect(() => {
    function handleClick(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClick);
    return () => document.removeEventListener("mousedown", handleClick);
  }, []);

  // --- Projekt-Einladungen ---
  async function handleAcceptProject(id: string, projectId: string) {
    setProcessing(id);
    await accept(id);
    setProcessing(null);
    router.push(`/projects/${projectId}`);
    setOpen(false);
  }

  async function handleDeclineProject(id: string) {
    setProcessing(id);
    await decline(id);
    setProcessing(null);
  }

  // --- Partner-Einladungen ---
  async function handleAcceptPartner(invite: PartnerInvite) {
    setProcessing(invite.id);
    await supabase
      .from("project_orgs")
      .update({
        status: "accepted",
        responded_at: new Date().toISOString(),
      })
      .eq("id", invite.id);
    setProcessing(null);
    loadPartnerInvites();
    router.push(`/projects/${invite.project_id}`);
    setOpen(false);
  }

  async function handleDeclinePartner(invite: PartnerInvite) {
    setProcessing(invite.id);
    await supabase
      .from("project_orgs")
      .update({
        status: "declined",
        responded_at: new Date().toISOString(),
      })
      .eq("id", invite.id);
    setProcessing(null);
    loadPartnerInvites();
  }

  // --- Buchungsanfragen ---
  async function handleApproveBooking(id: string) {
    setProcessing(id);
    await approveBooking(id);
    setProcessing(null);
  }

  async function handleRejectBooking(id: string) {
    setProcessing(id);
    await rejectBooking(id);
    setProcessing(null);
  }

  return (
    <div ref={ref} className="relative">
      <button
        onClick={() => setOpen(!open)}
        className="relative p-2 rounded-lg hover:opacity-80 transition-opacity"
        style={{
          color:
            totalCount > 0
              ? "var(--color-primary)"
              : "var(--color-sidebar-text-muted)",
        }}
        title="Benachrichtigungen"
      >
        <IconBell size={20} />
        {totalCount > 0 && (
          <span
            className="absolute -top-0.5 -right-0.5 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold"
            style={{ background: "var(--color-destructive)", color: "white" }}
          >
            {totalCount > 9 ? "9+" : totalCount}
          </span>
        )}
      </button>

      {open && (
        <div
          className="absolute bottom-full mb-2 left-0 w-80 rounded-xl shadow-xl z-50 overflow-hidden"
          style={{
            background: "var(--color-surface)",
            border: "1px solid var(--color-border)",
          }}
        >
          <div
            className="px-3 py-2 text-sm font-semibold"
            style={{ borderBottom: "1px solid var(--color-border)" }}
          >
            Benachrichtigungen
            {totalCount > 0 && (
              <span
                className="ml-2 text-xs px-1.5 py-0.5 rounded-full font-medium"
                style={{
                  background: "var(--color-destructive)",
                  color: "white",
                }}
              >
                {totalCount}
              </span>
            )}
          </div>

          <div className="max-h-80 overflow-y-auto">
            {totalCount === 0 ? (
              <div
                className="p-4 text-center text-sm"
                style={{ color: "var(--color-text-muted)" }}
              >
                Keine offenen Benachrichtigungen
              </div>
            ) : (
              <>
                {/* Sektion: Partner-Einladungen */}
                {partnerInvites.length > 0 && (
                  <div>
                    <div
                      className="px-3 py-1.5 text-xs font-semibold uppercase tracking-wider flex items-center gap-1.5"
                      style={{
                        color: "var(--color-text-muted)",
                        background: "var(--color-muted)",
                      }}
                    >
                      <IconHandshake size={12} />
                      Partner-Einladungen
                    </div>
                    {partnerInvites.map((inv) => (
                      <div
                        key={inv.id}
                        className="p-3"
                        style={{
                          borderBottom: "1px solid var(--color-border)",
                        }}
                      >
                        <p className="text-sm font-medium">
                          {inv.projects?.name || "Projekt"}
                        </p>
                        <p
                          className="text-xs mt-0.5"
                          style={{ color: "var(--color-text-muted)" }}
                        >
                          {inv.profiles?.name || "Jemand"} lädt deine Org als
                          Partner ein
                        </p>
                        <div className="flex gap-2 mt-2">
                          <button
                            onClick={() => handleAcceptPartner(inv)}
                            disabled={processing === inv.id}
                            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium disabled:opacity-50"
                            style={{
                              background: "var(--color-primary)",
                              color: "white",
                            }}
                          >
                            <IconCheck size={14} /> Annehmen
                          </button>
                          <button
                            onClick={() => handleDeclinePartner(inv)}
                            disabled={processing === inv.id}
                            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium disabled:opacity-50"
                            style={{
                              border: "1px solid var(--color-border)",
                              color: "var(--color-text-muted)",
                            }}
                          >
                            <IconX size={14} /> Ablehnen
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}

                {/* Sektion: Buchungsanfragen */}
                {bookingPendingCount > 0 && (
                  <div>
                    <div
                      className="px-3 py-1.5 text-xs font-semibold uppercase tracking-wider"
                      style={{
                        color: "var(--color-text-muted)",
                        background: "var(--color-muted)",
                      }}
                    >
                      Buchungsanfragen
                    </div>
                    {pendingBookings.map((b) => (
                      <div
                        key={b.id}
                        className="p-3"
                        style={{
                          borderBottom: "1px solid var(--color-border)",
                        }}
                      >
                        <p className="text-sm font-medium">
                          {b.inventory_items?.name || "Equipment"}
                          <span
                            className="text-xs font-normal ml-1"
                            style={{ color: "var(--color-text-muted)" }}
                          >
                            ({b.quantity}x)
                          </span>
                        </p>
                        <p
                          className="text-xs mt-0.5"
                          style={{ color: "var(--color-text-muted)" }}
                        >
                          {b.profiles?.name || "Jemand"} ·{" "}
                          {b.projects?.name || "Projekt"} ·{" "}
                          {new Date(b.date_from).toLocaleDateString("de-DE")}–
                          {new Date(b.date_to).toLocaleDateString("de-DE")}
                        </p>
                        <div className="flex gap-2 mt-2">
                          <button
                            onClick={() => handleApproveBooking(b.id)}
                            disabled={processing === b.id}
                            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium disabled:opacity-50"
                            style={{
                              background: "var(--color-primary)",
                              color: "white",
                            }}
                          >
                            <IconCheck size={14} /> Genehmigen
                          </button>
                          <button
                            onClick={() => handleRejectBooking(b.id)}
                            disabled={processing === b.id}
                            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium disabled:opacity-50"
                            style={{
                              border: "1px solid var(--color-border)",
                              color: "var(--color-text-muted)",
                            }}
                          >
                            <IconX size={14} /> Ablehnen
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}

                {/* Sektion: Projekt-Einladungen */}
                {projectPendingCount > 0 && (
                  <div>
                    <div
                      className="px-3 py-1.5 text-xs font-semibold uppercase tracking-wider"
                      style={{
                        color: "var(--color-text-muted)",
                        background: "var(--color-muted)",
                      }}
                    >
                      Projekt-Einladungen
                    </div>
                    {invitations.map((inv) => (
                      <div
                        key={inv.id}
                        className="p-3"
                        style={{
                          borderBottom: "1px solid var(--color-border)",
                        }}
                      >
                        <p className="text-sm font-medium">
                          {inv.projects?.name || "Projekt"}
                        </p>
                        <p
                          className="text-xs mt-0.5"
                          style={{ color: "var(--color-text-muted)" }}
                        >
                          Von {inv.profiles?.name || "Unbekannt"} — als{" "}
                          {inv.role === "editor" ? "Bearbeiter" : "Betrachter"}
                        </p>
                        <div className="flex gap-2 mt-2">
                          <button
                            onClick={() =>
                              handleAcceptProject(inv.id, inv.project_id)
                            }
                            disabled={processing === inv.id}
                            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium disabled:opacity-50"
                            style={{
                              background: "var(--color-primary)",
                              color: "white",
                            }}
                          >
                            <IconCheck size={14} /> Annehmen
                          </button>
                          <button
                            onClick={() => handleDeclineProject(inv.id)}
                            disabled={processing === inv.id}
                            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium disabled:opacity-50"
                            style={{
                              border: "1px solid var(--color-border)",
                              color: "var(--color-text-muted)",
                            }}
                          >
                            <IconX size={14} /> Ablehnen
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
