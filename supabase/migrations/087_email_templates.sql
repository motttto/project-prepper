-- Migration 087: Editierbare Email-Templates
-- ===========================================
-- Templates fuer Notifications, Einladungen, Reset-Mails etc.
-- Im Superadmin-Panel einsehbar/editierbar. Edge-Functions lesen daraus.

CREATE TABLE IF NOT EXISTS public.email_templates (
  key text PRIMARY KEY,
  description text,
  subject text NOT NULL,
  html_body text NOT NULL,
  text_body text,
  available_vars text[] DEFAULT '{}',
  updated_by uuid REFERENCES public.profiles(id),
  updated_at timestamptz DEFAULT now()
);

ALTER TABLE public.email_templates ENABLE ROW LEVEL SECURITY;

-- Lesen fuer alle Authenticated (Edge-Functions, Admin-UI)
CREATE POLICY "email_templates_select" ON public.email_templates
  FOR SELECT TO authenticated USING (true);

-- Bearbeiten nur Superadmin
CREATE POLICY "email_templates_update" ON public.email_templates
  FOR UPDATE TO authenticated USING (
    EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "email_templates_insert" ON public.email_templates
  FOR INSERT TO authenticated WITH CHECK (
    EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true)
  );

-- Auto-update updated_at
CREATE OR REPLACE FUNCTION public.email_templates_set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = now();
  NEW.updated_by = auth.uid();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS email_templates_updated_at ON public.email_templates;
CREATE TRIGGER email_templates_updated_at
  BEFORE UPDATE ON public.email_templates
  FOR EACH ROW EXECUTE FUNCTION public.email_templates_set_updated_at();

-- ────────────────────────────────────────────────────────────────────────────
-- Default-Templates (Seed)
-- ────────────────────────────────────────────────────────────────────────────

INSERT INTO public.email_templates (key, description, subject, html_body, text_body, available_vars) VALUES

('group_invite_voting_needed',
 'Wird an aktive Gruppen-Mitglieder gesendet, wenn eine Einladung in die Voting-Phase geht',
 'Abstimmung: {{invitee_name}} moechte {{group_name}} beitreten',
 '<!DOCTYPE html><html lang="de"><body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,Roboto,sans-serif;">
<table width="100%" style="background:#f4f4f5;padding:40px 20px;"><tr><td align="center">
<table width="100%" style="max-width:520px;background:#fff;border-radius:12px;padding:32px;">
<tr><td>
<h1 style="margin:0 0 16px;font-size:20px;color:#111;">Neue Abstimmung</h1>
<p style="margin:0 0 16px;color:#374151;">{{invitee_name}} moechte der Gruppe <strong>{{group_name}}</strong> beitreten.</p>
<p style="margin:0 0 16px;color:#374151;">Beitritt erfordert die einstimmige Zustimmung aller aktiven Mitglieder. Bitte stimme ab:</p>
<p><a href="{{voting_url}}" style="display:inline-block;background:#0ea5e9;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;">Zur Abstimmung</a></p>
</td></tr></table></td></tr></table></body></html>',
 'Neue Abstimmung: {{invitee_name}} moechte der Gruppe {{group_name}} beitreten. Stimme ab: {{voting_url}}',
 ARRAY['group_name', 'invitee_name', 'voting_url']),

('new_poll',
 'Wird an Gruppen-Mitglieder gesendet, wenn eine neue Umfrage erstellt wird',
 'Neue Umfrage: {{poll_title}}',
 '<!DOCTYPE html><html lang="de"><body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,Roboto,sans-serif;">
<table width="100%" style="background:#f4f4f5;padding:40px 20px;"><tr><td align="center">
<table width="100%" style="max-width:520px;background:#fff;border-radius:12px;padding:32px;">
<tr><td>
<h1 style="margin:0 0 16px;font-size:20px;color:#111;">Neue Umfrage</h1>
<p style="margin:0 0 16px;color:#374151;"><strong>{{creator_name}}</strong> hat eine neue Umfrage gestartet:</p>
<p style="margin:0 0 16px;color:#111;font-size:18px;font-weight:600;">{{poll_title}}</p>
<p style="margin:0 0 16px;color:#6b7280;font-size:14px;">Frist: {{deadline}}</p>
<p><a href="{{poll_url}}" style="display:inline-block;background:#0ea5e9;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;">Jetzt abstimmen</a></p>
</td></tr></table></td></tr></table></body></html>',
 'Neue Umfrage von {{creator_name}}: {{poll_title}}. Abstimmen: {{poll_url}}',
 ARRAY['poll_title', 'creator_name', 'deadline', 'poll_url']),

('loan_request_received',
 'Wird an den Eigentuemer eines Inventar-Items gesendet, wenn jemand eine Verleih-Anfrage stellt',
 'Verleih-Anfrage: {{item_name}}',
 '<!DOCTYPE html><html lang="de"><body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,Roboto,sans-serif;">
<table width="100%" style="background:#f4f4f5;padding:40px 20px;"><tr><td align="center">
<table width="100%" style="max-width:520px;background:#fff;border-radius:12px;padding:32px;">
<tr><td>
<h1 style="margin:0 0 16px;font-size:20px;color:#111;">Neue Verleih-Anfrage</h1>
<p style="margin:0 0 16px;color:#374151;"><strong>{{requester_name}}</strong> moechte folgendes Equipment ausleihen:</p>
<p style="margin:0 0 16px;color:#111;font-size:18px;font-weight:600;">{{item_name}}</p>
<p style="margin:0 0 16px;color:#374151;">Zeitraum: {{dates}}</p>
<p style="margin:0 0 16px;color:#6b7280;font-style:italic;">{{message}}</p>
<p><a href="{{request_url}}" style="display:inline-block;background:#0ea5e9;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;">Anfrage ansehen</a></p>
</td></tr></table></td></tr></table></body></html>',
 'Verleih-Anfrage von {{requester_name}} fuer {{item_name}} ({{dates}}). Anfrage: {{request_url}}',
 ARRAY['item_name', 'requester_name', 'dates', 'message', 'request_url']),

('feedback_received',
 'Wird an Superadmins gesendet, wenn ein User Feedback abschickt',
 'Neues Feedback: {{feedback_type}}',
 '<!DOCTYPE html><html lang="de"><body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,Roboto,sans-serif;">
<table width="100%" style="background:#f4f4f5;padding:40px 20px;"><tr><td align="center">
<table width="100%" style="max-width:520px;background:#fff;border-radius:12px;padding:32px;">
<tr><td>
<h1 style="margin:0 0 16px;font-size:20px;color:#111;">Neues Feedback</h1>
<p style="margin:0 0 8px;color:#6b7280;font-size:14px;">Typ: {{feedback_type}}</p>
<p style="margin:0 0 16px;color:#6b7280;font-size:14px;">Von: {{sender_name}} ({{sender_email}})</p>
<p style="margin:0 0 16px;color:#6b7280;font-size:14px;">Route: {{app_route}}</p>
<div style="background:#f9fafb;padding:16px;border-radius:8px;color:#111;white-space:pre-wrap;">{{message}}</div>
<p style="margin-top:24px;"><a href="{{admin_url}}" style="display:inline-block;background:#0ea5e9;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;">Im Admin-Panel oeffnen</a></p>
</td></tr></table></td></tr></table></body></html>',
 '{{feedback_type}} von {{sender_name}}: {{message}}',
 ARRAY['feedback_type', 'sender_name', 'sender_email', 'app_route', 'message', 'admin_url'])

ON CONFLICT (key) DO NOTHING;
