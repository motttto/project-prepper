-- Migration 061: RLS-Fix für org_email_config — System-User + is_admin() erlauben

-- Alte Policies entfernen
DROP POLICY IF EXISTS "org_email_config_select" ON public.org_email_config;
DROP POLICY IF EXISTS "org_email_config_insert" ON public.org_email_config;
DROP POLICY IF EXISTS "org_email_config_update" ON public.org_email_config;
DROP POLICY IF EXISTS "org_email_config_delete" ON public.org_email_config;

-- Neue Policies: Admin-Rolle ODER System-User (is_system)
CREATE POLICY "org_email_config_select" ON public.org_email_config
  FOR SELECT TO authenticated USING (
    EXISTS (
      SELECT 1 FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      LEFT JOIN profiles p ON p.id = om.profile_id
      WHERE om.profile_id = auth.uid()
        AND om.org_id = org_email_config.org_id
        AND om.is_active = true
        AND (r.name = 'admin' OR p.is_system = true)
    )
  );

CREATE POLICY "org_email_config_insert" ON public.org_email_config
  FOR INSERT TO authenticated WITH CHECK (
    EXISTS (
      SELECT 1 FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      LEFT JOIN profiles p ON p.id = om.profile_id
      WHERE om.profile_id = auth.uid()
        AND om.org_id = org_email_config.org_id
        AND om.is_active = true
        AND (r.name = 'admin' OR p.is_system = true)
    )
  );

CREATE POLICY "org_email_config_update" ON public.org_email_config
  FOR UPDATE TO authenticated USING (
    EXISTS (
      SELECT 1 FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      LEFT JOIN profiles p ON p.id = om.profile_id
      WHERE om.profile_id = auth.uid()
        AND om.org_id = org_email_config.org_id
        AND om.is_active = true
        AND (r.name = 'admin' OR p.is_system = true)
    )
  );

CREATE POLICY "org_email_config_delete" ON public.org_email_config
  FOR DELETE TO authenticated USING (
    EXISTS (
      SELECT 1 FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      LEFT JOIN profiles p ON p.id = om.profile_id
      WHERE om.profile_id = auth.uid()
        AND om.org_id = org_email_config.org_id
        AND om.is_active = true
        AND (r.name = 'admin' OR p.is_system = true)
    )
  );
