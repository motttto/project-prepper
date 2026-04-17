-- Migration 082: Gruppen-Einstellungen
-- ============================================================
-- Telegram-Chat-ID pro Gruppe (fuer Bot-Benachrichtigungen).
-- Optional inquiries.group_id um Anfragen an eine Gruppe zu binden.

ALTER TABLE public.groups
  ADD COLUMN IF NOT EXISTS telegram_chat_id bigint,
  ADD COLUMN IF NOT EXISTS telegram_thread_id integer;

ALTER TABLE public.inquiries
  ADD COLUMN IF NOT EXISTS group_id uuid REFERENCES public.groups(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS inquiries_group_id_idx ON public.inquiries(group_id);
