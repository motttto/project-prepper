-- 053: CalDAV Server Support
-- ETag, CalDAV-UID, CTag und Read/Write-Flag für CalDAV-Kompatibilität

-- ETag für optimistic concurrency
ALTER TABLE calendar_events
  ADD COLUMN IF NOT EXISTS etag text NOT NULL DEFAULT encode(gen_random_bytes(16), 'hex');

-- Stabiler CalDAV-UID
ALTER TABLE calendar_events
  ADD COLUMN IF NOT EXISTS caldav_uid text;

-- Bestehende Events: caldav_uid aus ID generieren
UPDATE calendar_events
  SET caldav_uid = id::text || '@project-prepper'
  WHERE caldav_uid IS NULL;

ALTER TABLE calendar_events
  ALTER COLUMN caldav_uid SET NOT NULL;

ALTER TABLE calendar_events
  ALTER COLUMN caldav_uid SET DEFAULT gen_random_uuid()::text || '@project-prepper';

CREATE UNIQUE INDEX IF NOT EXISTS idx_calendar_events_caldav_uid
  ON calendar_events(org_id, caldav_uid);

-- Auto-update ETag bei jedem UPDATE
CREATE OR REPLACE FUNCTION update_calendar_event_etag()
RETURNS TRIGGER AS $$
BEGIN
  NEW.etag = encode(gen_random_bytes(16), 'hex');
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER set_calendar_event_etag
  BEFORE UPDATE ON calendar_events
  FOR EACH ROW
  EXECUTE FUNCTION update_calendar_event_etag();

-- CTag auf calendar_groups (Collection-Tag für Sync)
ALTER TABLE calendar_groups
  ADD COLUMN IF NOT EXISTS ctag integer NOT NULL DEFAULT 1;

-- CTag bei Event-Änderungen inkrementieren
CREATE OR REPLACE FUNCTION bump_calendar_group_ctag()
RETURNS TRIGGER AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    UPDATE calendar_groups SET ctag = ctag + 1 WHERE id = OLD.group_id;
  ELSE
    UPDATE calendar_groups SET ctag = ctag + 1 WHERE id = NEW.group_id;
    -- Bei Gruppenänderung auch alte Gruppe updaten
    IF TG_OP = 'UPDATE' AND OLD.group_id IS DISTINCT FROM NEW.group_id AND OLD.group_id IS NOT NULL THEN
      UPDATE calendar_groups SET ctag = ctag + 1 WHERE id = OLD.group_id;
    END IF;
  END IF;
  RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER bump_ctag_on_event_change
  AFTER INSERT OR UPDATE OR DELETE ON calendar_events
  FOR EACH ROW
  EXECUTE FUNCTION bump_calendar_group_ctag();

-- Read/Write-Flag auf Feed-Tokens
ALTER TABLE calendar_feed_tokens
  ADD COLUMN IF NOT EXISTS read_write boolean NOT NULL DEFAULT false;
