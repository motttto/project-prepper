-- Migration 076: org_email_config wird user-level (Superadmin-eigene SMTP-Config)
-- ===============================================================================
-- Im neuen User-First-Modell ist Email-Config eine persoenliche Einstellung
-- des Superadmins (oder spaeter pro Group). org_id wird optional.

ALTER TABLE public.org_email_config
  ADD COLUMN IF NOT EXISTS owner_profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE,
  ALTER COLUMN org_id DROP NOT NULL;

-- UNIQUE Constraint anpassen: pro User, nicht pro Org
ALTER TABLE public.org_email_config DROP CONSTRAINT IF EXISTS org_email_config_org_id_key;
CREATE UNIQUE INDEX IF NOT EXISTS org_email_config_owner_unique
  ON public.org_email_config(owner_profile_id)
  WHERE owner_profile_id IS NOT NULL;

-- RLS: Owner sieht/editiert seine eigene Config
DROP POLICY IF EXISTS org_email_config_select ON public.org_email_config;
DROP POLICY IF EXISTS org_email_config_insert ON public.org_email_config;
DROP POLICY IF EXISTS org_email_config_update ON public.org_email_config;
DROP POLICY IF EXISTS org_email_config_delete ON public.org_email_config;

CREATE POLICY "email_config_select" ON public.org_email_config
  FOR SELECT TO authenticated USING (
    owner_profile_id = auth.uid()
    OR EXISTS (SELECT 1 FROM profiles WHERE id = auth.uid() AND is_system = true)
  );

CREATE POLICY "email_config_insert" ON public.org_email_config
  FOR INSERT TO authenticated WITH CHECK (
    owner_profile_id = auth.uid()
  );

CREATE POLICY "email_config_update" ON public.org_email_config
  FOR UPDATE TO authenticated USING (
    owner_profile_id = auth.uid()
  );

CREATE POLICY "email_config_delete" ON public.org_email_config
  FOR DELETE TO authenticated USING (
    owner_profile_id = auth.uid()
  );
