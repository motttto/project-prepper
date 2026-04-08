-- Migration 060: Verbindungssicherheit + Authentifizierungsmethode für SMTP/IMAP

ALTER TABLE public.org_email_config
  ADD COLUMN IF NOT EXISTS smtp_security text NOT NULL DEFAULT 'starttls'
    CHECK (smtp_security IN ('none', 'ssl', 'starttls')),
  ADD COLUMN IF NOT EXISTS smtp_auth text NOT NULL DEFAULT 'auto'
    CHECK (smtp_auth IN ('auto', 'plain', 'login', 'cram-md5')),
  ADD COLUMN IF NOT EXISTS imap_security text NOT NULL DEFAULT 'ssl'
    CHECK (imap_security IN ('none', 'ssl', 'starttls')),
  ADD COLUMN IF NOT EXISTS imap_auth text NOT NULL DEFAULT 'auto'
    CHECK (imap_auth IN ('auto', 'plain', 'login', 'cram-md5'));
