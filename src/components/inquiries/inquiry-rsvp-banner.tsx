"use client";

import { useCallback, useEffect, useState } from "react";
import { createClient } from "@/lib/supabase";
import { IconCheck, IconX, IconClock } from "@/components/ui/icons";
import { showToast } from "@/hooks/use-toast";

interface Props {
  inquiryId: string;
  currentUserId: string;
  createdBy: string | null;
  groupId: string | null;
}

type Status = "accepted" | "declined" | "pending";

/**
 * Self-RSVP Banner fuer Anfragen.
 * Zeigt eine klare Frage "Kannst du mitwirken?" mit drei Buttons
 * (Ja / Vielleicht / Nein). Upsertet inquiry_invitations
 * fuer den aktuellen User.
 *
 * Ideal fuer Deep-Links aus Telegram-Bot: User klickt -> kommt an -> RSVP.
 */
export function InquiryRsvpBanner({ inquiryId, currentUserId, createdBy, groupId }: Props) {
  const supabase = createClient();
  const [status, setStatus] = useState<Status | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState<Status | null>(null);

  const loadStatus = useCallback(async () => {
    const { data } = await supabase
      .from("inquiry_invitations")
      .select("status")
      .eq("inquiry_id", inquiryId)
      .eq("invited_profile_id", currentUserId)
      .maybeSingle();
    setStatus((data?.status as Status | undefined) ?? null);
    setLoading(false);
  }, [supabase, inquiryId, currentUserId]);

  useEffect(() => {
    loadStatus();
  }, [loadStatus]);

  async function handleRsvp(next: Status) {
    setSaving(next);
    const { error } = await supabase
      .from("inquiry_invitations")
      .upsert(
        {
          inquiry_id: inquiryId,
          invited_profile_id: currentUserId,
          invited_by: createdBy || currentUserId,
          status: next,
          responded_at: next === "pending" ? null : new Date().toISOString(),
        },
        { onConflict: "inquiry_id,invited_profile_id" }
      );
    setSaving(null);
    if (error) {
      showToast(`Fehler: ${error.message}`, "error");
      return;
    }
    setStatus(next);
    showToast(
      next === "accepted"
        ? "Danke! Du bist dabei."
        : next === "declined"
          ? "Abgesagt."
          : "Als vielleicht markiert.",
      "success"
    );
  }

  if (loading) return null;

  // Farben & Texte je nach Status
  const statusInfo =
    status === "accepted"
      ? { bg: "var(--color-success-light)", color: "var(--color-success)", label: "Du bist dabei ✓" }
      : status === "declined"
        ? { bg: "var(--color-destructive-light, #fef2f2)", color: "var(--color-destructive)", label: "Abgesagt" }
        : status === "pending"
          ? { bg: "var(--color-warning-light, #fef3c7)", color: "var(--color-warning, #d97706)", label: "Vielleicht" }
          : { bg: "var(--color-info-light)", color: "var(--color-info)", label: "Noch keine Antwort" };

  return (
    <div
      id="rsvp"
      className="rounded-xl p-5 mb-4"
      style={{ background: statusInfo.bg, border: `1px solid ${statusInfo.color}` }}
    >
      <div className="flex items-start gap-3 mb-4">
        <div className="flex-1 min-w-0">
          <h2 className="text-base font-semibold" style={{ color: "var(--color-foreground)" }}>
            Kannst du bei diesem Projekt mitwirken?
          </h2>
          <p className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
            {groupId
              ? "Deine Antwort ist sofort für die Gruppe sichtbar."
              : "Deine Antwort wird gespeichert."}
          </p>
        </div>
        <span
          className="px-2 py-1 rounded-full text-[11px] font-semibold shrink-0"
          style={{ background: statusInfo.color, color: "#fff" }}
        >
          {statusInfo.label}
        </span>
      </div>

      <div className="flex flex-wrap gap-2">
        <button
          onClick={() => handleRsvp("accepted")}
          disabled={saving !== null}
          className="flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium text-white transition-all disabled:opacity-50"
          style={{
            background: status === "accepted" ? "var(--color-success)" : "var(--color-success-muted, var(--color-success))",
            opacity: status === "accepted" ? 1 : 0.9,
            outline: status === "accepted" ? "2px solid var(--color-success)" : "none",
          }}
        >
          <IconCheck size={14} />
          Ja, ich kann
        </button>
        <button
          onClick={() => handleRsvp("pending")}
          disabled={saving !== null}
          className="flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium transition-all disabled:opacity-50"
          style={{
            background: status === "pending" ? "var(--color-warning, #f59e0b)" : "var(--color-surface)",
            color: status === "pending" ? "#fff" : "var(--color-foreground)",
            border: "1px solid var(--color-border)",
          }}
        >
          <IconClock size={14} />
          Vielleicht
        </button>
        <button
          onClick={() => handleRsvp("declined")}
          disabled={saving !== null}
          className="flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium transition-all disabled:opacity-50"
          style={{
            background: status === "declined" ? "var(--color-destructive)" : "var(--color-surface)",
            color: status === "declined" ? "#fff" : "var(--color-destructive)",
            border: "1px solid var(--color-destructive)",
          }}
        >
          <IconX size={14} />
          Kann nicht
        </button>
      </div>
    </div>
  );
}
