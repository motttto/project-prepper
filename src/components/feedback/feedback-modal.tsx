"use client";

import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import { usePathname } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { showToast } from "@/hooks/use-toast";

type FeedbackType = "bug" | "idea" | "other";

export function FeedbackModal({ onClose }: { onClose: () => void }) {
  const supabase = createClient();
  const pathname = usePathname();
  const [feedbackType, setFeedbackType] = useState<FeedbackType>("idea");
  const [message, setMessage] = useState("");
  const [saving, setSaving] = useState(false);
  const [mounted, setMounted] = useState(false);

  // Portal-Mount erst nach Hydration (vermeidet SSR-Mismatch)
  useEffect(() => {
    setMounted(true);
  }, []);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!message.trim()) return;
    setSaving(true);
    const { data: { user } } = await supabase.auth.getUser();
    if (!user) {
      setSaving(false);
      showToast("Nicht eingeloggt", "error");
      return;
    }
    const { error } = await supabase.from("app_feedback").insert({
      profile_id: user.id,
      feedback_type: feedbackType,
      message: message.trim(),
      app_route: pathname || null,
    });
    if (error) {
      setSaving(false);
      showToast("Fehler: " + error.message, "error");
      return;
    }

    // Notification an Superadmins
    try {
      const { data: admins } = await supabase
        .from("profiles")
        .select("id")
        .eq("is_system", true);
      const adminIds = (admins || []).map((a) => a.id);
      if (adminIds.length > 0) {
        const { data: profile } = await supabase
          .from("profiles")
          .select("name, email")
          .eq("id", user.id)
          .single();
        await supabase.functions.invoke("send-notification", {
          body: {
            template_key: "feedback_received",
            recipients: adminIds,
            pref_key: "feedback",
            vars: {
              feedback_type:
                feedbackType === "bug" ? "Bug" : feedbackType === "idea" ? "Idee" : "Sonstiges",
              sender_name: profile?.name || "?",
              sender_email: profile?.email || "?",
              app_route: pathname || "/",
              message: message.trim(),
              admin_url: `${typeof window !== "undefined" ? window.location.origin : ""}/admin`,
            },
          },
        });
      }
    } catch {
      // Silent fail — Feedback ist trotzdem gespeichert
    }

    setSaving(false);
    showToast("Danke für dein Feedback!", "success");
    onClose();
  }

  if (!mounted) return null;

  const modal = (
    <div
      className="fixed inset-0 z-[100] flex items-start justify-center p-4 pt-[10vh] overflow-y-auto"
      style={{ background: "rgba(0,0,0,0.5)" }}
      onClick={onClose}
    >
      <div
        className="w-full max-w-lg rounded-xl p-6"
        style={{ background: "var(--color-surface)" }}
        onClick={(e) => e.stopPropagation()}
      >
        <h2 className="text-lg font-bold mb-1">Feedback an die Entwickler</h2>
        <p className="text-sm mb-4" style={{ color: "var(--color-muted-foreground)" }}>
          Bug, Idee oder andere Rückmeldung — geht direkt an den Superadmin.
        </p>

        <form onSubmit={submit} className="space-y-3">
          <div>
            <label className="block text-xs font-medium mb-1.5">Typ</label>
            <div className="flex gap-1 rounded-lg p-1" style={{ background: "var(--color-muted)" }}>
              {(["bug", "idea", "other"] as const).map((t) => (
                <button
                  key={t}
                  type="button"
                  onClick={() => setFeedbackType(t)}
                  className="flex-1 px-3 py-1.5 rounded-md text-sm font-medium"
                  style={{
                    background: feedbackType === t ? "var(--color-primary)" : "transparent",
                    color: feedbackType === t ? "var(--color-primary-foreground)" : "var(--color-muted-foreground)",
                  }}
                >
                  {t === "bug" ? "Bug" : t === "idea" ? "Idee" : "Sonstiges"}
                </button>
              ))}
            </div>
          </div>

          <div>
            <label className="block text-xs font-medium mb-1.5">Nachricht</label>
            <textarea
              value={message}
              onChange={(e) => setMessage(e.target.value)}
              rows={6}
              required
              className="w-full px-3 py-2 rounded-lg text-sm"
              style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
              placeholder="Was möchtest du teilen?"
            />
          </div>

          <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            Aktuelle Seite ({pathname}) wird automatisch mitgesendet.
          </p>

          <div className="flex gap-2 justify-end pt-2">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 rounded-lg text-sm font-medium"
              style={{ border: "1px solid var(--color-border)" }}
            >
              Abbrechen
            </button>
            <button
              type="submit"
              disabled={saving || !message.trim()}
              className="px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
              style={{ background: "var(--color-primary)" }}
            >
              {saving ? "Sende..." : "Absenden"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );

  return createPortal(modal, document.body);
}
