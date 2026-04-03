-- Migration 046: Admin kann als anderer User abstimmen (Impersonation)
-- SECURITY DEFINER umgeht RLS, prüft aber dass Aufrufer Org-Admin ist.

CREATE OR REPLACE FUNCTION vote_as_user(
  p_decision_id uuid,
  p_voter_id uuid,
  p_vote text,
  p_comment text DEFAULT NULL
)
RETURNS void AS $$
DECLARE
  v_decision org_decisions%ROWTYPE;
  v_is_admin boolean;
BEGIN
  -- Beschluss laden
  SELECT * INTO v_decision FROM org_decisions WHERE id = p_decision_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'Beschluss nicht gefunden';
  END IF;

  -- Prüfen ob Aufrufer Org-Admin ist
  SELECT EXISTS (
    SELECT 1 FROM org_memberships om
    JOIN roles r ON r.id = om.role_id
    WHERE om.profile_id = auth.uid()
      AND om.org_id = v_decision.org_id
      AND om.is_active = true
      AND r.name = 'admin'
  ) INTO v_is_admin;

  -- Oder System-User
  IF NOT v_is_admin THEN
    SELECT EXISTS (
      SELECT 1 FROM profiles WHERE id = auth.uid() AND is_system = true
    ) INTO v_is_admin;
  END IF;

  IF NOT v_is_admin THEN
    RAISE EXCEPTION 'Nur Admins können als andere User abstimmen';
  END IF;

  -- Vote einfügen/updaten (umgeht RLS)
  INSERT INTO org_decision_votes (decision_id, voter_id, vote, comment)
  VALUES (p_decision_id, p_voter_id, p_vote, p_comment)
  ON CONFLICT (decision_id, voter_id)
  DO UPDATE SET vote = p_vote, comment = p_comment;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
