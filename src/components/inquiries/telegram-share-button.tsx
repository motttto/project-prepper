"use client";

import { useState } from "react";
import { createClient } from "@/lib/supabase";
import { IconTelegram } from "@/components/ui/icons";

interface TelegramShareButtonProps {
  inquiryId: string;
  telegramMessageId: number | null;
}

export function TelegramShareButton({
  inquiryId,
  telegramMessageId,
}: TelegramShareButtonProps) {
  const supabase = createClient();
  const [state, setState] = useState<"idle" | "sending" | "success" | "error">("idle");
  const [errorMsg, setErrorMsg] = useState("");

  async function handleSend() {
    setState("sending");
    setErrorMsg("");

    try {
      // User-Session für Authorization Header
      const { data: { session } } = await supabase.auth.getSession();
      if (!session) {
        setState("error");
        setErrorMsg("Nicht eingeloggt");
        return;
      }

      const supabaseUrl = process.env.NEXT_PUBLIC_SUPABASE_URL;
      const res = await fetch(
        `${supabaseUrl}/functions/v1/telegram-bot?action=send_inquiry`,
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${session.access_token}`,
          },
          body: JSON.stringify({ inquiry_id: inquiryId }),
        },
      );

      const data = await res.json();

      if (res.ok && data.ok) {
        setState("success");
        setTimeout(() => setState("idle"), 3000);
      } else {
        setState("error");
        setErrorMsg(data.error || "Fehler beim Senden");
      }
    } catch {
      setState("error");
      setErrorMsg("Netzwerkfehler");
    }
  }

  const isUpdate = !!telegramMessageId;
  const label =
    state === "sending"
      ? "Sende..."
      : state === "success"
        ? "✓ Gesendet"
        : isUpdate
          ? "Erneut senden"
          : "An Telegram senden";

  return (
    <div>
      <button
        onClick={handleSend}
        disabled={state === "sending" || state === "success"}
        className="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors disabled:opacity-60"
        style={{
          background:
            state === "success"
              ? "var(--color-success-light)"
              : "var(--color-info-light)",
          color:
            state === "success" ? "var(--color-success)" : "var(--color-info)",
        }}
        onMouseEnter={(e) => {
          if (state === "idle") {
            e.currentTarget.style.background = "var(--color-info)";
            e.currentTarget.style.color = "white";
          }
        }}
        onMouseLeave={(e) => {
          if (state === "idle") {
            e.currentTarget.style.background = isUpdate
              ? "var(--color-muted)"
              : "var(--color-info-light)";
            e.currentTarget.style.color = "var(--color-info)";
          }
        }}
      >
        <IconTelegram size={14} />
        {label}
      </button>
      {state === "error" && errorMsg && (
        <p
          className="text-xs mt-1"
          style={{ color: "var(--color-destructive)" }}
        >
          {errorMsg}
        </p>
      )}
    </div>
  );
}
