-- Fix: CTag-Bump Trigger als SECURITY DEFINER ausfuehren
-- Damit der CTag auch bei Web-App Inserts/Updates zuverlaessig bumpt
-- (ohne SECURITY DEFINER kann RLS den UPDATE auf calendar_groups blockieren)

CREATE OR REPLACE FUNCTION bump_calendar_group_ctag()
RETURNS TRIGGER
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    IF OLD.group_id IS NOT NULL THEN
      UPDATE calendar_groups SET ctag = ctag + 1 WHERE id = OLD.group_id;
    END IF;
  ELSE
    IF NEW.group_id IS NOT NULL THEN
      UPDATE calendar_groups SET ctag = ctag + 1 WHERE id = NEW.group_id;
    END IF;
    -- Bei Gruppenänderung auch alte Gruppe updaten
    IF TG_OP = 'UPDATE' AND OLD.group_id IS DISTINCT FROM NEW.group_id AND OLD.group_id IS NOT NULL THEN
      UPDATE calendar_groups SET ctag = ctag + 1 WHERE id = OLD.group_id;
    END IF;
  END IF;
  RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;
