-- 052: Feed-Tokens für Kalender-Abo (Apple Calendar, Google etc.)
-- Token-basierte Auth, damit Kalender-Apps ohne Session-Cookie zugreifen können

CREATE TABLE IF NOT EXISTS calendar_feed_tokens (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  org_id uuid NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
  profile_id uuid NOT NULL REFERENCES auth.users(id) ON DELETE CASCADE,
  token text NOT NULL UNIQUE DEFAULT encode(gen_random_bytes(32), 'hex'),
  label text, -- z.B. "Mein iPhone", "Google Calendar"
  created_at timestamptz NOT NULL DEFAULT now(),
  last_used_at timestamptz
);

CREATE INDEX idx_calendar_feed_tokens_token ON calendar_feed_tokens(token);
CREATE INDEX idx_calendar_feed_tokens_org ON calendar_feed_tokens(org_id, profile_id);

-- RLS
ALTER TABLE calendar_feed_tokens ENABLE ROW LEVEL SECURITY;

-- Jeder sieht nur eigene Tokens
CREATE POLICY "feed_tokens_select" ON calendar_feed_tokens
  FOR SELECT TO authenticated
  USING (profile_id = auth.uid());

CREATE POLICY "feed_tokens_insert" ON calendar_feed_tokens
  FOR INSERT TO authenticated
  WITH CHECK (profile_id = auth.uid() AND is_org_member(org_id));

CREATE POLICY "feed_tokens_delete" ON calendar_feed_tokens
  FOR DELETE TO authenticated
  USING (profile_id = auth.uid());
