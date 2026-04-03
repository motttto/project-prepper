-- Migration 045: Org-Admins bei Projekt-Beschlüssen berücksichtigen
-- Problem: Admins die nicht in project_members sind, deren Stimmen wurden ignoriert.
-- Fix: Stimmberechtigte = project_members UNION org_admins

CREATE OR REPLACE FUNCTION check_decision_complete()
RETURNS TRIGGER AS $$
DECLARE
  v_decision org_decisions%ROWTYPE;
  v_total_active integer;
  v_total_votes integer;
  v_approvals integer;
  v_rejections integer;
BEGIN
  SELECT * INTO v_decision FROM org_decisions WHERE id = NEW.decision_id;

  IF v_decision.status != 'open' THEN
    RETURN NEW;
  END IF;

  IF v_decision.related_project_id IS NOT NULL THEN
    -- Projekt-bezogen: Projekt-Mitglieder + Org-Admins (vereinigt, ohne Duplikate)
    SELECT COUNT(*) INTO v_total_active
    FROM (
      SELECT pm.profile_id FROM project_members pm
      WHERE pm.project_id = v_decision.related_project_id
      UNION
      SELECT om.profile_id FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      WHERE om.org_id = v_decision.org_id AND om.is_active = true AND r.name = 'admin'
    ) eligible;

    -- Stimmen zählen (nur von Stimmberechtigten)
    SELECT COUNT(*),
           COUNT(*) FILTER (WHERE dv.vote = 'approve'),
           COUNT(*) FILTER (WHERE dv.vote = 'reject')
    INTO v_total_votes, v_approvals, v_rejections
    FROM org_decision_votes dv
    WHERE dv.decision_id = NEW.decision_id
      AND dv.voter_id IN (
        SELECT pm.profile_id FROM project_members pm
        WHERE pm.project_id = v_decision.related_project_id
        UNION
        SELECT om.profile_id FROM org_memberships om
        JOIN roles r ON r.id = om.role_id
        WHERE om.org_id = v_decision.org_id AND om.is_active = true AND r.name = 'admin'
      );
  ELSE
    SELECT COUNT(*) INTO v_total_active
    FROM org_memberships
    WHERE org_id = v_decision.org_id AND is_active = true;

    SELECT COUNT(*),
           COUNT(*) FILTER (WHERE vote = 'approve'),
           COUNT(*) FILTER (WHERE vote = 'reject')
    INTO v_total_votes, v_approvals, v_rejections
    FROM org_decision_votes
    WHERE decision_id = NEW.decision_id;
  END IF;

  IF v_decision.requires_unanimous THEN
    IF v_approvals = v_total_active THEN
      UPDATE org_decisions SET status = 'approved', resolved_at = now()
      WHERE id = NEW.decision_id;
    ELSIF v_rejections > 0 THEN
      UPDATE org_decisions SET status = 'rejected', resolved_at = now()
      WHERE id = NEW.decision_id;
    END IF;
  ELSE
    IF v_total_votes = v_total_active THEN
      IF v_approvals > v_rejections THEN
        UPDATE org_decisions SET status = 'approved', resolved_at = now()
        WHERE id = NEW.decision_id;
      ELSE
        UPDATE org_decisions SET status = 'rejected', resolved_at = now()
        WHERE id = NEW.decision_id;
      END IF;
    END IF;
  END IF;

  RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
