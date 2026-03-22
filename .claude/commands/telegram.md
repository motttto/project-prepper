# Telegram-Integration bearbeiten

Änderungen an der Telegram-Bot-Anbindung (Benachrichtigungen, Anfragen-Weiterleitung).

## Anweisung

Aufgabe: $ARGUMENTS

### Kontext
- **Telegram-Felder:** `organizations.telegram_chat_id`, `profiles.telegram_user_id`, `inquiries.telegram_message_id`
- **Linking:** User verknüpft Telegram-Account unter `/profile/telegram-link`
- **Org-Chat:** Org kann einen Telegram-Chat hinterlegen für Gruppen-Benachrichtigungen
- **Anfragen:** Neue Inquiries können über Telegram-Bot erstellt werden
- **Migration:** `024_telegram_integration.sql`

### Relevante Dateien
- `src/app/(dashboard)/profile/telegram-link/page.tsx` — Telegram-Account verknüpfen
- `src/app/(dashboard)/org/page.tsx` — Org-Telegram-Chat konfigurieren
- `src/app/(dashboard)/inquiries/[id]/page.tsx` — Telegram-Message-ID Anzeige
- `src/types/database.ts` — `telegram_user_id`, `telegram_chat_id`, `telegram_message_id`

### Bot-API Patterns
```typescript
// Telegram Bot API Base URL
const TELEGRAM_API = `https://api.telegram.org/bot${BOT_TOKEN}`;

// Nachricht senden
await fetch(`${TELEGRAM_API}/sendMessage`, {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify({ chat_id, text, parse_mode: "HTML" }),
});
```

### Häufige Aufgaben
- Benachrichtigungen senden → Bot-API `sendMessage` an Org-Chat
- Inline-Buttons → `reply_markup` mit `inline_keyboard`
- Webhook einrichten → `setWebhook` auf Supabase Edge Function
- User-Linking validieren → `telegram_user_id` auf Profile prüfen
- Anfrage aus Telegram erstellen → `telegram_message_id` auf Inquiry setzen
