-- Migration 072: Voting-Trigger fuer Group-Einladungen (Phase 1)
-- ===============================================================
-- Wenn alle bestehenden Mitglieder einer Gruppe einer Einladung zugestimmt haben,
-- wird die Membership automatisch aktiviert (oder bei Ablehnung als rejected markiert).

CREATE OR REPLACE FUNCTION public.check_group_invitation_complete()
RETURNS TRIGGER AS $$
DECLARE
  v_invitation group_invitations%ROWTYPE;
  v_total_members integer;
  v_total_votes integer;
  v_approvals integer;
  v_rejections integer;
BEGIN
  -- Einladung laden
  SELECT * INTO v_invitation FROM group_invitations WHERE id = NEW.invitation_id;

  -- Nur weiter wenn Voting laeuft
  IF v_invitation.status NOT IN ('voting_in_progress', 'accepted_by_user') THEN
    RETURN NEW;
  END IF;

  -- Aktive Mitglieder zaehlen (ausschliesslich des einzuladenden Users)
  SELECT COUNT(*) INTO v_total_members
  FROM group_memberships
  WHERE group_id = v_invitation.group_id
    AND is_active = true
    AND profile_id != COALESCE(v_invitation.invited_profile_id, '00000000-0000-0000-0000-000000000000'::uuid);

  -- Abgegebene Stimmen
  SELECT COUNT(*),
         COUNT(*) FILTER (WHERE vote = 'approve'),
         COUNT(*) FILTER (WHERE vote = 'reject')
  INTO v_total_votes, v_approvals, v_rejections
  FROM group_invitation_votes
  WHERE invitation_id = NEW.invitation_id;

  -- Bei Ablehnung sofort beenden
  IF v_rejections > 0 THEN
    UPDATE group_invitations
    SET status = 'rejected_by_member', resolved_at = now()
    WHERE id = NEW.invitation_id;
    RETURN NEW;
  END IF;

  -- Wenn ALLE bestehenden Mitglieder zugestimmt haben -> Membership aktivieren
  IF v_approvals >= v_total_members AND v_total_members > 0 THEN
    -- Membership aktivieren (oder anlegen falls noch nicht vorhanden)
    INSERT INTO group_memberships (group_id, profile_id, is_active, joined_at)
    VALUES (v_invitation.group_id, v_invitation.invited_profile_id, true, now())
    ON CONFLICT (group_id, profile_id)
    DO UPDATE SET is_active = true, joined_at = now();

    UPDATE group_invitations
    SET status = 'approved', resolved_at = now()
    WHERE id = NEW.invitation_id;
  END IF;

  RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

DROP TRIGGER IF EXISTS trg_check_group_invitation ON public.group_invitation_votes;
CREATE TRIGGER trg_check_group_invitation
  AFTER INSERT OR UPDATE ON public.group_invitation_votes
  FOR EACH ROW EXECUTE FUNCTION public.check_group_invitation_complete();

-- ── Trigger: Bei Group-Erstellung Founder als aktives Mitglied eintragen ────
CREATE OR REPLACE FUNCTION public.handle_new_group()
RETURNS TRIGGER AS $$
BEGIN
  INSERT INTO group_memberships (group_id, profile_id, is_active, is_founder, joined_at)
  VALUES (NEW.id, NEW.founded_by, true, true, now())
  ON CONFLICT (group_id, profile_id) DO NOTHING;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

DROP TRIGGER IF EXISTS trg_new_group_founder ON public.groups;
CREATE TRIGGER trg_new_group_founder
  AFTER INSERT ON public.groups
  FOR EACH ROW EXECUTE FUNCTION public.handle_new_group();

-- ── Trigger: Wenn User Einladung akzeptiert + nur 1 Mitglied -> sofort approved ──
-- Sonst: Voting starten
CREATE OR REPLACE FUNCTION public.handle_invitation_user_acceptance()
RETURNS TRIGGER AS $$
DECLARE
  v_total_members integer;
BEGIN
  -- Nur wenn Status auf accepted_by_user wechselt
  IF NEW.status = 'accepted_by_user' AND (OLD.status IS NULL OR OLD.status != 'accepted_by_user') THEN
    SELECT COUNT(*) INTO v_total_members
    FROM group_memberships
    WHERE group_id = NEW.group_id AND is_active = true;

    IF v_total_members <= 1 THEN
      -- Nur Founder vorhanden -> sofort aktivieren
      INSERT INTO group_memberships (group_id, profile_id, is_active, joined_at)
      VALUES (NEW.group_id, NEW.invited_profile_id, true, now())
      ON CONFLICT (group_id, profile_id)
      DO UPDATE SET is_active = true, joined_at = now();

      UPDATE group_invitations
      SET status = 'approved', resolved_at = now()
      WHERE id = NEW.id;
    ELSE
      -- Mehrere Mitglieder -> Voting starten
      UPDATE group_invitations
      SET status = 'voting_in_progress', voting_started_at = now()
      WHERE id = NEW.id;

      -- Membership-Platzhalter (inaktiv) anlegen
      INSERT INTO group_memberships (group_id, profile_id, is_active)
      VALUES (NEW.group_id, NEW.invited_profile_id, false)
      ON CONFLICT (group_id, profile_id) DO NOTHING;
    END IF;
  END IF;

  RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

DROP TRIGGER IF EXISTS trg_invitation_user_acceptance ON public.group_invitations;
CREATE TRIGGER trg_invitation_user_acceptance
  AFTER UPDATE OF status ON public.group_invitations
  FOR EACH ROW EXECUTE FUNCTION public.handle_invitation_user_acceptance();
