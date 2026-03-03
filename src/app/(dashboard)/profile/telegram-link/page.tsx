"use client";

import { Suspense, useState, useEffect } from "react";
import { useSearchParams, useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { IconTelegram, IconCheck, IconX } from "@/components/ui/icons";

function TelegramLinkContent() {
  const searchParams = useSearchParams();
  const router = useRouter();
  const supabase = createClient();
  const token = searchParams.get("token");

  const [state, setState] = useState<"loading" | "success" | "error">("loading");
  const [errorMsg, setErrorMsg] = useState("");

  useEffect(() => {
    if (!token) {
      setState("error");
      setErrorMsg("Kein Token angegeben.");
      return;
    }

    async function linkTelegram() {
      // 1. User prüfen
      const {
        data: { user },
      } = await supabase.auth.getUser();
      if (!user) {
        setState("error");
        setErrorMsg("Du bist nicht eingeloggt. Bitte erst einloggen.");
        return;
      }

      // 2. Token laden + validieren
      const { data: tokenRecord, error: tokenError } = await supabase
        .from("telegram_link_tokens")
        .select("id, telegram_user_id, consumed_by, expires_at")
        .eq("token", token)
        .single();

      if (tokenError || !tokenRecord) {
        setState("error");
        setErrorMsg("Ungültiger oder abgelaufener Link.");
        return;
      }

      if (tokenRecord.consumed_by) {
        setState("error");
        setErrorMsg("Dieser Link wurde bereits verwendet.");
        return;
      }

      if (new Date(tokenRecord.expires_at) < new Date()) {
        setState("error");
        setErrorMsg("Dieser Link ist abgelaufen. Sende /start erneut an den Bot.");
        return;
      }

      // 3. Profil verknüpfen
      const { error: updateError } = await supabase
        .from("profiles")
        .update({ telegram_user_id: tokenRecord.telegram_user_id })
        .eq("id", user.id);

      if (updateError) {
        setState("error");
        setErrorMsg(
          updateError.message.includes("unique")
            ? "Dieses Telegram-Konto ist bereits mit einem anderen Profil verknüpft."
            : `Fehler: ${updateError.message}`,
        );
        return;
      }

      // 4. Token als verbraucht markieren
      await supabase
        .from("telegram_link_tokens")
        .update({
          consumed_by: user.id,
          consumed_at: new Date().toISOString(),
        })
        .eq("id", tokenRecord.id);

      setState("success");

      // Nach 3 Sekunden zum Profil weiterleiten
      setTimeout(() => router.push("/profile"), 3000);
    }

    linkTelegram();
  }, [token, supabase, router]);

  const cardStyle = {
    background: "var(--color-surface)",
    border: "1px solid var(--color-border)",
  };

  return (
    <div className="max-w-md mx-auto mt-20">
      <div className="rounded-xl p-8 text-center" style={cardStyle}>
        {state === "loading" && (
          <>
            <div className="animate-pulse mb-4">
              <IconTelegram
                size={48}
                className="mx-auto"
                style={{ color: "#229ED9" }}
              />
            </div>
            <h2 className="text-lg font-semibold mb-2">Verknüpfe Telegram...</h2>
            <p
              className="text-sm"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              Einen Moment bitte.
            </p>
          </>
        )}

        {state === "success" && (
          <>
            <div
              className="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4"
              style={{
                background: "var(--color-success-light)",
                color: "var(--color-success)",
              }}
            >
              <IconCheck size={32} />
            </div>
            <h2 className="text-lg font-semibold mb-2">Telegram verknüpft! ✓</h2>
            <p
              className="text-sm"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              Du wirst gleich zum Profil weitergeleitet...
            </p>
          </>
        )}

        {state === "error" && (
          <>
            <div
              className="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4"
              style={{
                background: "var(--color-destructive-light)",
                color: "var(--color-destructive)",
              }}
            >
              <IconX size={32} />
            </div>
            <h2 className="text-lg font-semibold mb-2">Verknüpfung fehlgeschlagen</h2>
            <p
              className="text-sm mb-4"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              {errorMsg}
            </p>
            <button
              onClick={() => router.push("/profile")}
              className="px-4 py-2 rounded-lg text-sm font-medium text-white"
              style={{ background: "var(--color-primary)" }}
            >
              Zum Profil
            </button>
          </>
        )}
      </div>
    </div>
  );
}

export default function TelegramLinkPage() {
  return (
    <Suspense
      fallback={
        <div className="max-w-md mx-auto mt-20">
          <div
            className="rounded-xl p-8 text-center"
            style={{
              background: "var(--color-surface)",
              border: "1px solid var(--color-border)",
            }}
          >
            <div className="animate-pulse mb-4">
              <IconTelegram
                size={48}
                className="mx-auto"
                style={{ color: "#229ED9" }}
              />
            </div>
            <h2 className="text-lg font-semibold mb-2">Laden...</h2>
          </div>
        </div>
      }
    >
      <TelegramLinkContent />
    </Suspense>
  );
}
