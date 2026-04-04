-- 051: Eigener Kalender in Supabase (ersetzt externes CalDAV)
-- Kalender-Gruppen (Untergruppen wie "Team", "Veranstaltungen", etc.)
CREATE TABLE IF NOT EXISTS calendar_groups (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  org_id uuid NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
  name text NOT NULL,
  color text NOT NULL DEFAULT '#0066FF',
  sort_order integer NOT NULL DEFAULT 0,
  created_by uuid REFERENCES auth.users(id),
  created_at timestamptz NOT NULL DEFAULT now()
);

-- Kalender-Events
CREATE TABLE IF NOT EXISTS calendar_events (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  org_id uuid NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
  group_id uuid REFERENCES calendar_groups(id) ON DELETE SET NULL,
  summary text NOT NULL,
  description text,
  location text,
  all_day boolean NOT NULL DEFAULT false,
  start_at timestamptz NOT NULL,
  end_at timestamptz,
  created_by uuid REFERENCES auth.users(id),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

-- Indexes
CREATE INDEX idx_calendar_events_org ON calendar_events(org_id);
CREATE INDEX idx_calendar_events_range ON calendar_events(org_id, start_at, end_at);
CREATE INDEX idx_calendar_groups_org ON calendar_groups(org_id);

-- RLS
ALTER TABLE calendar_groups ENABLE ROW LEVEL SECURITY;
ALTER TABLE calendar_events ENABLE ROW LEVEL SECURITY;

-- Alle Org-Mitglieder dürfen Kalender-Gruppen sehen
CREATE POLICY "calendar_groups_select" ON calendar_groups
  FOR SELECT TO authenticated
  USING (is_org_member(org_id));

-- Admins + Manager dürfen Gruppen erstellen/bearbeiten/löschen
CREATE POLICY "calendar_groups_insert" ON calendar_groups
  FOR INSERT TO authenticated
  WITH CHECK (is_org_member(org_id));

CREATE POLICY "calendar_groups_update" ON calendar_groups
  FOR UPDATE TO authenticated
  USING (is_org_member(org_id));

CREATE POLICY "calendar_groups_delete" ON calendar_groups
  FOR DELETE TO authenticated
  USING (is_org_admin(org_id));

-- Alle Org-Mitglieder dürfen Events sehen
CREATE POLICY "calendar_events_select" ON calendar_events
  FOR SELECT TO authenticated
  USING (is_org_member(org_id));

-- Alle Org-Mitglieder dürfen Events erstellen
CREATE POLICY "calendar_events_insert" ON calendar_events
  FOR INSERT TO authenticated
  WITH CHECK (is_org_member(org_id));

-- Ersteller + Admins dürfen Events bearbeiten
CREATE POLICY "calendar_events_update" ON calendar_events
  FOR UPDATE TO authenticated
  USING (is_org_member(org_id));

-- Ersteller + Admins dürfen Events löschen
CREATE POLICY "calendar_events_delete" ON calendar_events
  FOR DELETE TO authenticated
  USING (created_by = auth.uid() OR is_org_admin(org_id));

-- Realtime
ALTER PUBLICATION supabase_realtime ADD TABLE calendar_groups;
ALTER PUBLICATION supabase_realtime ADD TABLE calendar_events;

-- updated_at Trigger
CREATE TRIGGER set_calendar_events_updated_at
  BEFORE UPDATE ON calendar_events
  FOR EACH ROW
  EXECUTE FUNCTION handle_updated_at();
