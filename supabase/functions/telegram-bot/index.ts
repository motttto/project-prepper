// Supabase Edge Function: Telegram Bot für Anfragen
// Deno Runtime — keine node_modules, URL-Imports
//
// Drei Handler:
// A) ?action=send_inquiry → Anfrage an Telegram-Gruppe senden
// B) Callback Query        → Zusagen/Absagen per Button
// C) /start Command        → Telegram-Konto verknüpfen

import { createClient } from "https://esm.sh/@supabase/supabase-js@2";

// ─────────────────────────────────────────────────────────────────────────
// Env
// ─────────────────────────────────────────────────────────────────────────
const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const SUPABASE_SERVICE_ROLE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;
const BOT_TOKEN = Deno.env.get("TELEGRAM_BOT_TOKEN")!;
const WEBHOOK_SECRET = Deno.env.get("TELEGRAM_WEBHOOK_SECRET") || "";
const APP_URL = Deno.env.get("APP_URL") || "http://localhost:3000";

const TELEGRAM_API = `https://api.telegram.org/bot${BOT_TOKEN}`;

// ─────────────────────────────────────────────────────────────────────────
// Supabase Client (Service Role — bypasses RLS)
// ─────────────────────────────────────────────────────────────────────────
function getServiceClient() {
  return createClient(SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY);
}

// Authenticated client (verifies user JWT)
function getAuthClient(authHeader: string) {
  return createClient(SUPABASE_URL, Deno.env.get("SUPABASE_ANON_KEY")!, {
    global: { headers: { Authorization: authHeader } },
  });
}

// ─────────────────────────────────────────────────────────────────────────
// Telegram API Helpers
// ─────────────────────────────────────────────────────────────────────────
async function telegramSendMessage(
  chatId: number,
  text: string,
  replyMarkup?: Record<string, unknown>,
) {
  const body: Record<string, unknown> = {
    chat_id: chatId,
    text,
    parse_mode: "Markdown",
  };
  if (replyMarkup) body.reply_markup = replyMarkup;

  const res = await fetch(`${TELEGRAM_API}/sendMessage`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  return res.json();
}

async function telegramEditMessage(
  chatId: number,
  messageId: number,
  text: string,
  replyMarkup?: Record<string, unknown>,
) {
  const body: Record<string, unknown> = {
    chat_id: chatId,
    message_id: messageId,
    text,
    parse_mode: "Markdown",
  };
  if (replyMarkup) body.reply_markup = replyMarkup;

  const res = await fetch(`${TELEGRAM_API}/editMessageText`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  return res.json();
}

async function telegramAnswerCallback(callbackQueryId: string, text: string) {
  await fetch(`${TELEGRAM_API}/answerCallbackQuery`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      callback_query_id: callbackQueryId,
      text,
    }),
  });
}

// ─────────────────────────────────────────────────────────────────────────
// Message Formatting
// ─────────────────────────────────────────────────────────────────────────
interface InquiryData {
  id: string;
  title: string;
  client_name: string;
  description?: string | null;
  venue_name?: string | null;
  event_date_start?: string | null;
  event_date_end?: string | null;
}

interface InvitationData {
  invited_profile_id: string;
  status: string;
  profiles: { name: string } | null;
}

function formatInquiryMessage(
  inquiry: InquiryData,
  invitations: InvitationData[],
): string {
  const lines: string[] = [];

  lines.push(`📋 *${escapeMarkdown(inquiry.title)}*`);
  lines.push("");
  lines.push(`👤 Kunde: ${escapeMarkdown(inquiry.client_name)}`);

  if (inquiry.event_date_start) {
    const start = formatDate(inquiry.event_date_start);
    const end = inquiry.event_date_end
      ? ` – ${formatDate(inquiry.event_date_end)}`
      : "";
    lines.push(`📅 ${start}${end}`);
  }

  if (inquiry.venue_name) {
    lines.push(`📍 ${escapeMarkdown(inquiry.venue_name)}`);
  }

  if (inquiry.description) {
    lines.push("");
    lines.push(`📝 ${escapeMarkdown(inquiry.description)}`);
  }

  if (invitations.length > 0) {
    lines.push("");
    lines.push("*Team-Status:*");
    for (const inv of invitations) {
      const name = inv.profiles?.name || "Unbekannt";
      const icon =
        inv.status === "accepted"
          ? "✅"
          : inv.status === "declined"
            ? "❌"
            : "⏳";
      lines.push(`${icon} ${escapeMarkdown(name)}`);
    }
  }

  return lines.join("\n");
}

function buildInlineKeyboard(inquiryId: string) {
  return {
    inline_keyboard: [
      [
        { text: "✅ Zusagen", callback_data: `accept:${inquiryId}` },
        { text: "❌ Absagen", callback_data: `decline:${inquiryId}` },
      ],
      [
        {
          text: "🔗 Anfrage öffnen",
          url: `${APP_URL}/inquiries/${inquiryId}`,
        },
      ],
    ],
  };
}

function escapeMarkdown(text: string): string {
  return text.replace(/[_*[\]()~`>#+\-=|{}.!]/g, "\\$&");
}

function formatDate(iso: string): string {
  try {
    return new Date(iso).toLocaleDateString("de-DE", {
      day: "2-digit",
      month: "2-digit",
      year: "numeric",
    });
  } catch {
    return iso;
  }
}

// ─────────────────────────────────────────────────────────────────────────
// Handler A: App → Telegram (Anfrage posten)
// ─────────────────────────────────────────────────────────────────────────
async function handleSendInquiry(req: Request): Promise<Response> {
  // Verify user JWT
  const authHeader = req.headers.get("Authorization");
  if (!authHeader) {
    return jsonResponse({ error: "Missing Authorization header" }, 401);
  }

  const authClient = getAuthClient(authHeader);
  const {
    data: { user },
  } = await authClient.auth.getUser();
  if (!user) {
    return jsonResponse({ error: "Invalid token" }, 401);
  }

  const { inquiry_id } = await req.json();
  if (!inquiry_id) {
    return jsonResponse({ error: "Missing inquiry_id" }, 400);
  }

  const supabase = getServiceClient();

  // Lade Anfrage mit Org-Daten
  const { data: inquiry, error: inquiryError } = await supabase
    .from("inquiries")
    .select("id, title, client_name, description, venue_name, event_date_start, event_date_end, org_id, telegram_message_id, organizations(telegram_chat_id)")
    .eq("id", inquiry_id)
    .single();

  if (inquiryError || !inquiry) {
    return jsonResponse({ error: "Inquiry not found" }, 404);
  }

  // deno-lint-ignore no-explicit-any
  const chatId = (inquiry as any).organizations?.telegram_chat_id;
  if (!chatId) {
    return jsonResponse({ error: "No Telegram chat configured for this organization" }, 400);
  }

  // Lade Einladungen
  const { data: invitations } = await supabase
    .from("inquiry_invitations")
    .select("invited_profile_id, status, profiles!invited_profile_id(name)")
    .eq("inquiry_id", inquiry_id);

  const message = formatInquiryMessage(inquiry, invitations || []);
  const keyboard = buildInlineKeyboard(inquiry_id);

  // Wenn schon gepostet → editieren, sonst neu senden
  if (inquiry.telegram_message_id) {
    const editResult = await telegramEditMessage(
      chatId,
      inquiry.telegram_message_id,
      message,
      keyboard,
    );
    // "message is not modified" ist kein Fehler — Inhalt war identisch
    if (!editResult.ok && !editResult.description?.includes("not modified")) {
      console.error("Telegram edit error:", editResult);
      return jsonResponse({ error: "Telegram-Nachricht konnte nicht aktualisiert werden", details: editResult }, 500);
    }
    return jsonResponse({ ok: true, action: "updated" });
  }

  // Neue Nachricht senden
  const result = await telegramSendMessage(chatId, message, keyboard);

  if (result.ok && result.result?.message_id) {
    // Message-ID speichern
    await supabase
      .from("inquiries")
      .update({ telegram_message_id: result.result.message_id })
      .eq("id", inquiry_id);

    return jsonResponse({ ok: true, action: "sent" });
  }

  return jsonResponse(
    { error: "Failed to send Telegram message", details: result },
    500,
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Handler B: Callback Query (Button-Klick in Telegram)
// ─────────────────────────────────────────────────────────────────────────
async function handleCallbackQuery(
  // deno-lint-ignore no-explicit-any
  callbackQuery: any,
): Promise<Response> {
  const telegramUserId = callbackQuery.from?.id;
  const data = callbackQuery.data || "";
  const callbackId = callbackQuery.id;

  const [action, inquiryId] = data.split(":");

  if (!action || !inquiryId) {
    await telegramAnswerCallback(callbackId, "Ungültige Aktion.");
    return jsonResponse({ ok: true });
  }

  if (action !== "accept" && action !== "decline") {
    await telegramAnswerCallback(callbackId, "Unbekannte Aktion.");
    return jsonResponse({ ok: true });
  }

  const supabase = getServiceClient();

  // Finde App-Profil für diesen Telegram-User
  const { data: profile } = await supabase
    .from("profiles")
    .select("id, name")
    .eq("telegram_user_id", telegramUserId)
    .single();

  if (!profile) {
    await telegramAnswerCallback(
      callbackId,
      "Dein Telegram ist nicht verknüpft. Sende /start an den Bot.",
    );
    return jsonResponse({ ok: true });
  }

  // Finde Einladung
  const { data: invitation } = await supabase
    .from("inquiry_invitations")
    .select("id, status")
    .eq("inquiry_id", inquiryId)
    .eq("invited_profile_id", profile.id)
    .single();

  if (!invitation) {
    await telegramAnswerCallback(
      callbackId,
      "Du wurdest für diese Anfrage nicht eingeladen.",
    );
    return jsonResponse({ ok: true });
  }

  // Status updaten
  const newStatus = action === "accept" ? "accepted" : "declined";
  await supabase
    .from("inquiry_invitations")
    .update({
      status: newStatus,
      responded_at: new Date().toISOString(),
    })
    .eq("id", invitation.id);

  const statusText =
    newStatus === "accepted"
      ? `✅ ${profile.name} hat zugesagt`
      : `❌ ${profile.name} hat abgesagt`;
  await telegramAnswerCallback(callbackId, statusText);

  // Gruppen-Nachricht aktualisieren
  await updateGroupMessage(supabase, inquiryId);

  return jsonResponse({ ok: true });
}

// ─────────────────────────────────────────────────────────────────────────
// Handler C: /start Command (Telegram verknüpfen)
// ─────────────────────────────────────────────────────────────────────────
// deno-lint-ignore no-explicit-any
async function handleStartCommand(message: any): Promise<Response> {
  const chatId = message.chat.id;
  const telegramUserId = message.from.id;
  const telegramUsername = message.from.username || null;

  const supabase = getServiceClient();

  // Prüfen ob schon verknüpft
  const { data: existingProfile } = await supabase
    .from("profiles")
    .select("name")
    .eq("telegram_user_id", telegramUserId)
    .single();

  if (existingProfile) {
    await telegramSendMessage(
      chatId,
      `Du bist bereits verknüpft als *${escapeMarkdown(existingProfile.name)}*. ✓`,
    );
    return jsonResponse({ ok: true });
  }

  // Link-Token erstellen
  const { data: tokenRecord, error: tokenError } = await supabase
    .from("telegram_link_tokens")
    .insert({
      telegram_user_id: telegramUserId,
      telegram_username: telegramUsername,
    })
    .select("token")
    .single();

  if (tokenError || !tokenRecord) {
    await telegramSendMessage(
      chatId,
      "Fehler beim Erstellen des Verknüpfungs-Links. Bitte versuche es erneut.",
    );
    return jsonResponse({ ok: true });
  }

  const linkUrl = `${APP_URL}/profile/telegram-link?token=${tokenRecord.token}`;

  await telegramSendMessage(
    chatId,
    `Klicke auf den Link um dein Telegram mit Project Prepper zu verknüpfen:\n\n${linkUrl}\n\n_Der Link ist 15 Minuten gültig._`,
  );

  return jsonResponse({ ok: true });
}

// ─────────────────────────────────────────────────────────────────────────
// Helper: Gruppen-Nachricht nach Status-Änderung updaten
// ─────────────────────────────────────────────────────────────────────────
// deno-lint-ignore no-explicit-any
async function updateGroupMessage(supabase: any, inquiryId: string) {
  const { data: inquiry } = await supabase
    .from("inquiries")
    .select(
      "id, title, client_name, description, venue_name, event_date_start, event_date_end, telegram_message_id, organizations(telegram_chat_id)",
    )
    .eq("id", inquiryId)
    .single();

  if (!inquiry?.telegram_message_id) return;

  // deno-lint-ignore no-explicit-any
  const chatId = (inquiry as any).organizations?.telegram_chat_id;
  if (!chatId) return;

  const { data: invitations } = await supabase
    .from("inquiry_invitations")
    .select("invited_profile_id, status, profiles!invited_profile_id(name)")
    .eq("inquiry_id", inquiryId);

  const message = formatInquiryMessage(inquiry, invitations || []);
  const keyboard = buildInlineKeyboard(inquiryId);

  await telegramEditMessage(chatId, inquiry.telegram_message_id, message, keyboard);
}

// ─────────────────────────────────────────────────────────────────────────
// Utility
// ─────────────────────────────────────────────────────────────────────────
function jsonResponse(data: unknown, status = 200): Response {
  return new Response(JSON.stringify(data), {
    status,
    headers: {
      "Content-Type": "application/json",
      "Access-Control-Allow-Origin": "*",
      "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
    },
  });
}

// ─────────────────────────────────────────────────────────────────────────
// Main Handler
// ─────────────────────────────────────────────────────────────────────────
Deno.serve(async (req: Request) => {
  // CORS Preflight
  if (req.method === "OPTIONS") {
    return new Response("ok", {
      headers: {
        "Access-Control-Allow-Origin": "*",
        "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
      },
    });
  }

  try {
    const url = new URL(req.url);
    const action = url.searchParams.get("action");

    // Route A: App schickt Anfrage an Telegram
    if (action === "send_inquiry") {
      return await handleSendInquiry(req);
    }

    // Route B/C: Telegram-Webhook
    // Verify webhook secret
    if (WEBHOOK_SECRET) {
      const secretHeader = req.headers.get("x-telegram-bot-api-secret-token");
      if (secretHeader !== WEBHOOK_SECRET) {
        return jsonResponse({ error: "Invalid webhook secret" }, 401);
      }
    }

    const update = await req.json();

    // /start Command
    if (update.message?.text?.startsWith("/start")) {
      return await handleStartCommand(update.message);
    }

    // Callback Query (Button-Klick)
    if (update.callback_query) {
      return await handleCallbackQuery(update.callback_query);
    }

    // Andere Updates ignorieren
    return jsonResponse({ ok: true });
  } catch (error) {
    console.error("Edge Function error:", error);
    return jsonResponse({ error: "Internal error" }, 500);
  }
});
