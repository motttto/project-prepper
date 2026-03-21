-- 033: Pro-User Berechtigungen (Checkboxen)
-- permissions JSONB auf org_memberships für individuelle Rechte
ALTER TABLE org_memberships
  ADD COLUMN IF NOT EXISTS permissions jsonb;

-- null = erbt alle Rechte der Rolle
-- Format: {"projects": true, "inventory": true, "costs": false, "team": false, "inquiries": true}
