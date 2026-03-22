# Anfragen-Pipeline bearbeiten

Änderungen an der Inquiry-Pipeline (Projektanfragen, Status-Tracking, Telegram-Integration).

## Anweisung

Aufgabe: $ARGUMENTS

### Kontext
- **Tabelle:** `inquiries` — Projektanfragen von Kunden
- **Status-Flow:** `new` → `reviewing` → `offer_sent` → `accepted` / `rejected` → `archived`
- **Felder:** client_name, client_contact_person, client_phone, client_email, title, description, venue_name, venue_address, event_date_start/end, estimated_budget, offer_amount, offer_date, offer_valid_until, probability, next_follow_up, notes, project_id (Verknüpfung), telegram_message_id
- **Team-Einladungen:** `inquiry_invitations` — Mitglieder pro Anfrage einladen (pending/accepted/declined)
- **Telegram:** Neue Anfragen können via Telegram-Bot erstellt werden (telegram_message_id)
- **RLS:** Org-scoped über `org_id`

### Relevante Dateien
- `src/app/(dashboard)/inquiries/page.tsx` — Anfragen-Übersicht (Kanban/Liste)
- `src/app/(dashboard)/inquiries/[id]/page.tsx` — Anfrage-Detail + Team
- `src/types/database.ts` — `Inquiry`, `InquiryInvitation` Types

### Migrations
- `022_inquiries.sql` — Haupttabelle
- `023_inquiry_invitations.sql` — Team-Einladungen
- `024_telegram_integration.sql` — Telegram-Felder

### Häufige Aufgaben
- Neues Status-Feld → CHECK constraint + UI-Status-Badge
- Anfrage → Projekt konvertieren → `project_id` setzen + Projekt erstellen
- Wahrscheinlichkeits-Berechnung → `probability` Feld (0-100%)
- Follow-Up Erinnerungen → `next_follow_up` Datum
- Angebots-Tracking → `offer_amount`, `offer_date`, `offer_valid_until`
