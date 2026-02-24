-- 017: System-User Flag
-- Ein System-User kann nicht deaktiviert oder degradiert werden.

-- Flag hinzufügen
ALTER TABLE profiles ADD COLUMN is_system boolean DEFAULT false NOT NULL;

-- System-User setzen
UPDATE profiles SET is_system = true
WHERE email = 'post@michaotto.com';

-- RLS: System-User kann nur von sich selbst bearbeitet werden
-- Andere Admins können is_system-User nicht ändern
DROP POLICY IF EXISTS admin_update_profiles ON profiles;

CREATE POLICY admin_update_profiles ON profiles
  FOR UPDATE USING (
    -- Eigenes Profil bearbeiten ODER
    id = auth.uid()
    OR (
      -- Admin darf andere bearbeiten, ABER nur wenn Ziel kein System-User ist
      public.is_admin()
      AND is_system = false
    )
  )
  WITH CHECK (
    id = auth.uid()
    OR (
      public.is_admin()
      AND is_system = false
    )
  );
