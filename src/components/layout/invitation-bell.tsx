"use client";

import { useState, useRef, useEffect } from "react";
import { useRouter } from "next/navigation";
import { useInvitations } from "@/hooks/use-invitations";
import { IconBell, IconCheck, IconX } from "@/components/ui/icons";

interface InvitationBellProps {
  userId: string | undefined;
}

export function InvitationBell({ userId }: InvitationBellProps) {
  const { invitations, pendingCount, accept, decline } = useInvitations(userId);
  const [open, setOpen] = useState(false);
  const [processing, setProcessing] = useState<string | null>(null);
  const ref = useRef<HTMLDivElement>(null);
  const router = useRouter();

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

  async function handleAccept(id: string, projectId: string) {
    setProcessing(id);
    await accept(id);
    setProcessing(null);
    // Optional: zum Projekt navigieren
    router.push(`/projects/${projectId}`);
    setOpen(false);
  }

  async function handleDecline(id: string) {
    setProcessing(id);
    await decline(id);
    setProcessing(null);
  }

  return (
    <div ref={ref} className="relative">
      <button
        onClick={() => setOpen(!open)}
        className="relative p-2 rounded-lg hover:opacity-80 transition-opacity"
        style={{
          color: pendingCount > 0 ? "var(--color-primary)" : "var(--color-text-muted)",
        }}
        title="Einladungen"
      >
        <IconBell size={20} />
        {pendingCount > 0 && (
          <span
            className="absolute -top-0.5 -right-0.5 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold"
            style={{ background: "var(--color-destructive)", color: "white" }}
          >
            {pendingCount}
          </span>
        )}
      </button>

      {open && (
        <div
          className="absolute bottom-full mb-2 left-0 w-72 sm:w-80 rounded-xl shadow-xl z-50 overflow-hidden"
          style={{
            background: "var(--color-surface)",
            border: "1px solid var(--color-border)",
          }}
        >
          <div
            className="px-3 py-2 text-sm font-semibold"
            style={{ borderBottom: "1px solid var(--color-border)" }}
          >
            Einladungen
          </div>

          {invitations.length === 0 ? (
            <div className="p-4 text-center text-sm" style={{ color: "var(--color-text-muted)" }}>
              Keine offenen Einladungen
            </div>
          ) : (
            <div className="max-h-64 overflow-y-auto">
              {invitations.map((inv) => (
                <div
                  key={inv.id}
                  className="p-3"
                  style={{ borderBottom: "1px solid var(--color-border)" }}
                >
                  <p className="text-sm font-medium">{inv.projects?.name || "Projekt"}</p>
                  <p className="text-xs mt-0.5" style={{ color: "var(--color-text-muted)" }}>
                    Eingeladen von {inv.profiles?.name || "Unbekannt"} — als{" "}
                    {inv.role === "editor" ? "Bearbeiter" : "Betrachter"}
                  </p>
                  <div className="flex gap-2 mt-3">
                    <button
                      onClick={() => handleAccept(inv.id, inv.project_id)}
                      disabled={processing === inv.id}
                      className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium disabled:opacity-50"
                      style={{ background: "var(--color-primary)", color: "white" }}
                    >
                      <IconCheck size={14} /> Annehmen
                    </button>
                    <button
                      onClick={() => handleDecline(inv.id)}
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
        </div>
      )}
    </div>
  );
}
