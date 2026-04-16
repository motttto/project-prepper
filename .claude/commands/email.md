# E-Mail System bearbeiten

Änderungen am E-Mail-Versand, SMTP-Konfiguration, Projekt-Einladungs-Emails.

## Anweisung

Aufgabe: $ARGUMENTS

### Kontext
- **Tabelle:** `org_email_config` (SMTP + IMAP pro Org, Migration 058-061)
- **Felder SMTP:** smtp_host, smtp_port, smtp_user, smtp_pass, smtp_security (none/ssl/starttls), smtp_auth (auto/plain/login/cram-md5)
- **Felder IMAP:** imap_host, imap_port, imap_user, imap_pass, imap_security, imap_auth, imap_enabled
- **Felder Absender:** sender_email, sender_name, bcc_email, is_enabled
- **RLS:** Nur Admins + System-User (is_system)
- **UI:** Admin-Panel → E-Mail Tab (`src/app/(dashboard)/admin/page.tsx`, Komponente `EmailConfigTab`)
- **Edge Functions:**
  - `supabase/functions/send-project-invite/index.ts` — Sendet HTML-Email bei Projekt-Einladung (nodemailer)
  - `supabase/functions/test-smtp/index.ts` — SMTP-Verbindungstest mit Test-Email
  - Beide deployed mit `--no-verify-jwt`
- **Trigger:** `project-members-panel.tsx` → `handleInvite()` ruft `send-project-invite` auf (fire & forget)
- **Fallback:** Ohne SMTP-Config werden Einladungen nur in-app angezeigt
- **Provider Dunkelstrom:** manitu (mail.manitu.de), Port 465 SSL, PLAIN Auth

### Email-Template
- HTML-Email mit Project Prepper Branding (dark header)
- Enthält: Projektname, Datum, Venue, Rolle, Einladender, "Zum Projekt" Button
- Template ist inline in `send-project-invite/index.ts` → `buildEmailHtml()`

### Wichtige Dateien
- `src/app/(dashboard)/admin/page.tsx` — EmailConfigTab Komponente (ab Zeile ~1794)
- `src/components/projects/project-members-panel.tsx` — Einladung + Email-Versand
- `supabase/functions/send-project-invite/index.ts` — Edge Function
- `supabase/functions/test-smtp/index.ts` — Edge Function
- `src/types/database.ts` — OrgEmailConfig Type

### Edge Function Deploy
```bash
SUPABASE_ACCESS_TOKEN=$(security find-generic-password -s "supabase-deploy-token" -w) \
  npx supabase functions deploy send-project-invite --no-verify-jwt --project-ref wiywvuurxzkctvpwkncj

SUPABASE_ACCESS_TOKEN=$(security find-generic-password -s "supabase-deploy-token" -w) \
  npx supabase functions deploy test-smtp --no-verify-jwt --project-ref wiywvuurxzkctvpwkncj
```
