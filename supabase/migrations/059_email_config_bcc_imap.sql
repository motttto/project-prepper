-- Migration 059: BCC + IMAP-Postfach Felder für E-Mail Konfiguration

ALTER TABLE public.org_email_config
  ADD COLUMN IF NOT EXISTS bcc_email text NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS imap_host text NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS imap_port integer NOT NULL DEFAULT 993,
  ADD COLUMN IF NOT EXISTS imap_user text NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS imap_pass text NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS imap_enabled boolean NOT NULL DEFAULT false;
