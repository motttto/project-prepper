-- Migration 062: Send-Count + Last-Sent für Projekt-Einladungen (Erneut senden)

ALTER TABLE public.project_invitations
  ADD COLUMN IF NOT EXISTS send_count integer NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS last_sent_at timestamptz DEFAULT now();
