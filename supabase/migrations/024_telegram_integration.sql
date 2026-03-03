-- Migration 024: Telegram Integration
-- Telegram-Bot für Anfragen: Gruppe benachrichtigen, Zusagen/Absagen per Button

-- ─────────────────────────────────────────────────────────────────────────
-- 1a: Telegram-User-ID auf profiles (für Zuordnung Telegram → App-Profil)
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE public.profiles
  ADD COLUMN IF NOT EXISTS telegram_user_id BIGINT UNIQUE;

-- ─────────────────────────────────────────────────────────────────────────
-- 1b: Telegram-Gruppen-Chat-ID auf organizations
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE public.organizations
  ADD COLUMN IF NOT EXISTS telegram_chat_id BIGINT;

-- ─────────────────────────────────────────────────────────────────────────
-- 1c: Telegram-Message-ID auf inquiries (zum Editieren der Nachricht)
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE public.inquiries
  ADD COLUMN IF NOT EXISTS telegram_message_id BIGINT;

-- ─────────────────────────────────────────────────────────────────────────
-- 1d: Link-Token Tabelle (für /start → Profil-Verknüpfung)
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.telegram_link_tokens (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  telegram_user_id BIGINT NOT NULL,
  telegram_username TEXT,
  token TEXT NOT NULL UNIQUE DEFAULT encode(gen_random_bytes(32), 'hex'),
  consumed_by uuid REFERENCES public.profiles(id),
  consumed_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ DEFAULT now(),
  expires_at TIMESTAMPTZ DEFAULT (now() + interval '15 minutes')
);

CREATE INDEX IF NOT EXISTS idx_telegram_link_tokens_token
  ON public.telegram_link_tokens(token);

-- ─────────────────────────────────────────────────────────────────────────
-- RLS für telegram_link_tokens
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE public.telegram_link_tokens ENABLE ROW LEVEL SECURITY;

-- SELECT: authentifizierte User können Tokens lesen (um sie zu konsumieren)
CREATE POLICY "telegram_link_tokens_select"
  ON public.telegram_link_tokens
  FOR SELECT TO authenticated
  USING (true);

-- UPDATE: authentifizierter User kann Token konsumieren (nicht abgelaufen, nicht verbraucht)
CREATE POLICY "telegram_link_tokens_update"
  ON public.telegram_link_tokens
  FOR UPDATE TO authenticated
  USING (consumed_by IS NULL AND expires_at > now());

-- INSERT: nur via service_role (Edge Function) — kein Policy nötig
-- DELETE: nur via service_role — kein Policy nötig
