-- 050: Activity Log / Audit Trail für Admins
-- Append-only Log aller wichtigen Aktionen pro Org

CREATE TABLE IF NOT EXISTS public.org_activity_log (
  id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  org_id UUID NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
  actor_id UUID REFERENCES profiles(id) ON DELETE SET NULL,
  action TEXT NOT NULL,
  entity_type TEXT,
  entity_id UUID,
  entity_label TEXT,
  metadata JSONB DEFAULT '{}',
  created_at TIMESTAMPTZ DEFAULT now()
);

-- Indexes
CREATE INDEX idx_activity_log_org_created ON org_activity_log(org_id, created_at DESC);
CREATE INDEX idx_activity_log_action ON org_activity_log(action);

-- RLS (nur Admins lesen, alle authentifizierten User schreiben)
ALTER TABLE org_activity_log ENABLE ROW LEVEL SECURITY;

CREATE POLICY "activity_log_select" ON org_activity_log
  FOR SELECT TO authenticated USING (
    EXISTS (
      SELECT 1 FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      WHERE om.profile_id = auth.uid()
        AND om.org_id = org_activity_log.org_id
        AND r.name = 'admin'
        AND om.is_active = true
    )
    OR EXISTS (
      SELECT 1 FROM profiles WHERE id = auth.uid() AND is_system = true
    )
  );

CREATE POLICY "activity_log_insert" ON org_activity_log
  FOR INSERT TO authenticated WITH CHECK (
    EXISTS (
      SELECT 1 FROM org_memberships
      WHERE profile_id = auth.uid()
        AND org_id = org_activity_log.org_id
    )
  );

-- Kein UPDATE oder DELETE (append-only)

-- Realtime
ALTER PUBLICATION supabase_realtime ADD TABLE org_activity_log;

-- Trigger: Membership-Änderungen automatisch loggen
CREATE OR REPLACE FUNCTION log_membership_changes() RETURNS trigger AS $$
BEGIN
  -- Freigabe (approved_at war NULL, jetzt gesetzt)
  IF OLD.approved_at IS NULL AND NEW.approved_at IS NOT NULL AND OLD.is_active = false AND NEW.is_active = true THEN
    INSERT INTO org_activity_log (org_id, actor_id, action, entity_type, entity_id, entity_label)
    VALUES (NEW.org_id, auth.uid(), 'member.approved', 'member', NEW.profile_id,
            (SELECT name FROM profiles WHERE id = NEW.profile_id));
  END IF;

  -- Rollenwechsel
  IF OLD.role_id IS DISTINCT FROM NEW.role_id THEN
    INSERT INTO org_activity_log (org_id, actor_id, action, entity_type, entity_id, entity_label, metadata)
    VALUES (NEW.org_id, auth.uid(), 'member.role_changed', 'member', NEW.profile_id,
            (SELECT name FROM profiles WHERE id = NEW.profile_id),
            jsonb_build_object(
              'old_role', (SELECT name FROM roles WHERE id = OLD.role_id),
              'new_role', (SELECT name FROM roles WHERE id = NEW.role_id)
            ));
  END IF;

  -- Deaktivierung
  IF OLD.is_active = true AND NEW.is_active = false THEN
    INSERT INTO org_activity_log (org_id, actor_id, action, entity_type, entity_id, entity_label)
    VALUES (NEW.org_id, auth.uid(), 'member.deactivated', 'member', NEW.profile_id,
            (SELECT name FROM profiles WHERE id = NEW.profile_id));
  END IF;

  -- Reaktivierung
  IF OLD.is_active = false AND NEW.is_active = true AND OLD.approved_at IS NOT NULL THEN
    INSERT INTO org_activity_log (org_id, actor_id, action, entity_type, entity_id, entity_label)
    VALUES (NEW.org_id, auth.uid(), 'member.reactivated', 'member', NEW.profile_id,
            (SELECT name FROM profiles WHERE id = NEW.profile_id));
  END IF;

  RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE TRIGGER trg_log_membership_changes
  AFTER UPDATE ON org_memberships
  FOR EACH ROW EXECUTE FUNCTION log_membership_changes();
