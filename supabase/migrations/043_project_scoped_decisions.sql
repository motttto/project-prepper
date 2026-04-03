-- Migration 043: Projekt-bezogene Beschlüsse nur durch Projekt-Mitglieder
-- Wenn ein Beschluss ein related_project_id hat, werden nur Projekt-Mitglieder
-- als Stimmberechtigte gezählt (statt alle Org-Mitglieder).

CREATE OR REPLACE FUNCTION check_decision_complete()
RETURNS TRIGGER AS $$
DECLARE
  v_decision org_decisions%ROWTYPE;
  v_total_active integer;
  v_total_votes integer;
  v_approvals integer;
  v_rejections integer;
BEGIN
  -- Beschluss laden
  SELECT * INTO v_decision FROM org_decisions WHERE id = NEW.decision_id;

  -- Nur offene Beschlüsse prüfen
  IF v_decision.status != 'open' THEN
    RETURN NEW;
  END IF;

  -- Stimmberechtigte zählen: Projekt-Mitglieder oder Org-Mitglieder
  IF v_decision.related_project_id IS NOT NULL THEN
    -- Projekt-bezogen: nur Projekt-Mitglieder
    SELECT COUNT(*) INTO v_total_active
    FROM project_members
    WHERE project_id = v_decision.related_project_id;
  ELSE
    -- Org-weit: alle aktiven Org-Mitglieder
    SELECT COUNT(*) INTO v_total_active
    FROM org_memberships
    WHERE org_id = v_decision.org_id AND is_active = true;
  END IF;

  -- Stimmen zählen (nur von Stimmberechtigten)
  IF v_decision.related_project_id IS NOT NULL THEN
    SELECT COUNT(*),
           COUNT(*) FILTER (WHERE vote = 'approve'),
           COUNT(*) FILTER (WHERE vote = 'reject')
    INTO v_total_votes, v_approvals, v_rejections
    FROM org_decision_votes dv
    JOIN project_members pm ON pm.profile_id = dv.voter_id
      AND pm.project_id = v_decision.related_project_id
    WHERE dv.decision_id = NEW.decision_id;
  ELSE
    SELECT COUNT(*),
           COUNT(*) FILTER (WHERE vote = 'approve'),
           COUNT(*) FILTER (WHERE vote = 'reject')
    INTO v_total_votes, v_approvals, v_rejections
    FROM org_decision_votes
    WHERE decision_id = NEW.decision_id;
  END IF;

  -- Ergebnis prüfen
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
