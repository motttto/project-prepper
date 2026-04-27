-- Migration 093: Tracking + Erinnern für Group-Einladungen
-- ===========================================================
-- Zwei separate Counter:
--   send_count / last_sent_at — Mails an den Eingeladenen
--     (Initial + "User hat noch nicht akzeptiert"-Erinnerungen)
--   voting_reminder_count / voting_reminder_last_at — Mails an Members
--     wenn Voting laeuft

ALTER TABLE public.group_invitations
  ADD COLUMN IF NOT EXISTS send_count integer NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS last_sent_at timestamptz DEFAULT now(),
  ADD COLUMN IF NOT EXISTS voting_reminder_count integer NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS voting_reminder_last_at timestamptz;

-- ── Neues Email-Template fuer Erinnerung an den Eingeladenen ──

INSERT INTO public.email_templates (key, description, subject, html_body, text_body, available_vars) VALUES
('group_invite_pending_reminder',
 'Erinnerung an einen Eingeladenen, der noch nicht angenommen oder abgelehnt hat',
 'Erinnerung: Einladung in {{group_name}}',
 '<!DOCTYPE html><html lang="de"><body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,Roboto,sans-serif;">
<table width="100%" style="background:#f4f4f5;padding:40px 20px;"><tr><td align="center">
<table width="100%" style="max-width:520px;background:#fff;border-radius:12px;padding:32px;">
<tr><td>
<h1 style="margin:0 0 16px;font-size:20px;color:#111;">Erinnerung</h1>
<p style="margin:0 0 16px;color:#374151;">Hallo {{recipient_name}}, du hast eine Einladung in die Gruppe <strong>{{group_name}}</strong> von <strong>{{inviter_name}}</strong> bekommen, die noch offen ist.</p>
<p style="margin:0 0 16px;color:#374151;">Bitte akzeptiere oder lehne sie ab — andere Mitglieder warten darauf, dass das Voting starten kann:</p>
<p><a href="{{invitation_url}}" style="display:inline-block;background:#0ea5e9;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;">Zur Einladung</a></p>
</td></tr></table></td></tr></table></body></html>',
 'Erinnerung: Einladung in {{group_name}} von {{inviter_name}} ist noch offen. Bitte beantworten: {{invitation_url}}',
 ARRAY['group_name', 'inviter_name', 'invitation_url', 'recipient_name'])
ON CONFLICT (key) DO NOTHING;
