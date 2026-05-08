# Telegram-Bot für Gruppe einrichten

Verbindet eine Gruppe in der App mit einer Telegram-Gruppe/Supergruppe/Topic, sodass Anfragen automatisch dort gepostet werden.

## Anweisung

Gruppe: $ARGUMENTS

### Voraussetzungen
- Bot existiert bereits bei `@BotFather` (Username z.B. `@project_prepper_bot`)
- Bot-Token ist als Edge-Function-Secret `TELEGRAM_BOT_TOKEN` in Supabase hinterlegt
- Webhook zeigt auf `https://wiywvuurxzkctvpwkncj.supabase.co/functions/v1/telegram-bot`

### Ablauf — Chat-ID + Thread-ID ermitteln

#### 1. Bot in den Telegram-Chat einladen
- **Normale Gruppe / Supergruppe:** Bot als **Mitglied** hinzufügen reicht
- **Broadcast-Kanal:** Bot als **Admin** mit "Nachrichten posten"
- **Forum/Topics:** Bot auf Gruppen-Ebene hinzufügen, dann in gewünschtes Topic posten

#### 2. Bot-Token holen
Token kann nicht aus Supabase ausgelesen werden (nur Hashes sichtbar):
```bash
TOKEN=$(security find-generic-password -s "supabase-deploy-token" -w 2>/dev/null)
SUPABASE_ACCESS_TOKEN="$TOKEN" npx supabase secrets list --project-ref wiywvuurxzkctvpwkncj
```
→ User muss Token bei `@BotFather` → `/mybots` → Bot wählen → `API Token` kopieren.

#### 3. Webhook pausieren (sonst konsumiert er die Updates)
```bash
# Webhook-URL merken
curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo" | python3 -m json.tool

# Webhook löschen
curl -s "https://api.telegram.org/bot<TOKEN>/deleteWebhook"
```

#### 4. User schreibt im Ziel-Chat/Topic
**Wegen Privacy-Mode** (`can_read_all_group_messages: false`) sieht der Bot nur:
- Commands: `/start@<bot_username>` ← einfachster Weg
- Direkte @Mentions
- Antworten auf eigene Nachrichten

In **echten Channels** muss Bot zusätzlich Admin sein.

#### 5. Updates abrufen
```bash
curl -s "https://api.telegram.org/bot<TOKEN>/getUpdates" | python3 -m json.tool
```
Im Ergebnis:
- `message.chat.id` → **`telegram_chat_id`** (negative Zahl, Supergruppen starten mit `-100`)
- `message.message_thread_id` → **`telegram_thread_id`** (nur bei Forum/Topics)
- `message.chat.is_forum: true` → bestätigt Forum-Modus

#### 6. Webhook wiederherstellen
```bash
curl -s -X POST "https://api.telegram.org/bot<TOKEN>/setWebhook" \
  -d "url=https://wiywvuurxzkctvpwkncj.supabase.co/functions/v1/telegram-bot" \
  -d "allowed_updates=%5B%22message%22%2C%22callback_query%22%2C%22my_chat_member%22%5D"
```

#### 7. IDs in App eintragen
In der App: `Gruppen → <Name> → Einstellungen → Telegram-Bot`
- `telegram_chat_id` (Pflicht)
- `telegram_thread_id` (optional, nur für Forum-Topics)

Nur **Founder** kann das speichern (RLS: `is_group_member` + UI-Check `isFounder`).

### Token-Rotation (bei Token-Leak)

Token wurde in Chat geteilt o.ä.:
1. `@BotFather` → `/revoke` → Bot wählen → neuen Token kopieren
2. Supabase-Secret aktualisieren:
```bash
TOKEN=$(security find-generic-password -s "supabase-deploy-token" -w)
SUPABASE_ACCESS_TOKEN="$TOKEN" npx supabase secrets set TELEGRAM_BOT_TOKEN="<neuer_token>" \
  --project-ref wiywvuurxzkctvpwkncj
```
3. Webhook mit neuem Token neu setzen (Schritt 6).

### Häufige Fehler

| Symptom | Ursache | Fix |
|---------|---------|-----|
| `getUpdates` liefert leer | Webhook noch aktiv ODER Privacy-Mode | Webhook löschen + `/start@bot` als Command schreiben |
| `Conflict: webhook is active` | Webhook nicht gelöscht | `deleteWebhook` zuerst |
| Edge Function gibt 404 (`Inquiry not found`) | PostgREST-Embed mehrdeutig wenn `inquiries` mehrere FKs auf `groups` hat | In `select(...)` explizit: `groups!inquiries_group_id_fkey(...)` |
| Bot sieht keine Nachrichten in Gruppe | Privacy-Mode aktiv (Default) | Nur Commands/@Mentions/Replies senden — ODER `@BotFather` → Bot Settings → Group Privacy → Disable |
| Nachricht landet im Hauptchat statt Topic | `telegram_thread_id` fehlt | Thread-ID in Group-Settings nachtragen |
| `Inquiry not found` trotz richtiger ID | Ambiguous Embed (s.o.) | FK explizit benennen |

### Relevante Dateien
- `supabase/functions/telegram-bot/index.ts` — Edge Function (Send + Webhook-Handler)
- `src/app/(dashboard)/groups/[id]/page.tsx` — `GroupSettings` mit Telegram-Feldern
- `src/components/inquiries/telegram-share-button.tsx` — App ruft `?action=send_inquiry`
- `supabase/migrations/082_group_settings.sql` — `telegram_chat_id`, `telegram_thread_id` auf `groups`

### DB-Schnellcheck (Service-Role)
```bash
SR=$(grep "^SUPABASE_SERVICE_ROLE_KEY=" /Users/mo/Documents/Claude_Files/project-prepper/.env.local | cut -d= -f2)

# Group-Telegram-Config prüfen
curl -s "https://wiywvuurxzkctvpwkncj.supabase.co/rest/v1/groups?select=id,name,telegram_chat_id,telegram_thread_id&name=eq.<Name>" \
  -H "apikey: $SR" -H "Authorization: Bearer $SR"

# Direkt setzen (Notfall, umgeht UI)
curl -s -X PATCH "https://wiywvuurxzkctvpwkncj.supabase.co/rest/v1/groups?id=eq.<UUID>" \
  -H "apikey: $SR" -H "Authorization: Bearer $SR" \
  -H "Content-Type: application/json" \
  -d '{"telegram_thread_id": <ID>}'
```
