-- Migration 058: SMTP-Konfiguration pro Organisation
-- Admins können eigenen Mailserver hinterlegen für Projekt-Einladungen etc.

CREATE TABLE IF NOT EXISTS public.org_email_config (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  org_id uuid NOT NULL UNIQUE REFERENCES public.organizations(id) ON DELETE CASCADE,
  smtp_host text NOT NULL DEFAULT '',
  smtp_port integer NOT NULL DEFAULT 587,
  smtp_user text NOT NULL DEFAULT '',
  smtp_pass text NOT NULL DEFAULT '',
  sender_email text NOT NULL DEFAULT '',
  sender_name text NOT NULL DEFAULT '',
  is_enabled boolean NOT NULL DEFAULT false,
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now()
);

-- Index
CREATE INDEX IF NOT EXISTS idx_org_email_config_org_id ON public.org_email_config(org_id);

-- RLS: Nur Org-Admins können lesen und schreiben
ALTER TABLE public.org_email_config ENABLE ROW LEVEL SECURITY;

CREATE POLICY "org_email_config_select" ON public.org_email_config
  FOR SELECT TO authenticated USING (
    EXISTS (
      SELECT 1 FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      WHERE om.profile_id = auth.uid()
        AND om.org_id = org_email_config.org_id
        AND r.name = 'admin'
        AND om.is_active = true
    )
  );

CREATE POLICY "org_email_config_insert" ON public.org_email_config
  FOR INSERT TO authenticated WITH CHECK (
    EXISTS (
      SELECT 1 FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      WHERE om.profile_id = auth.uid()
        AND om.org_id = org_email_config.org_id
        AND r.name = 'admin'
        AND om.is_active = true
    )
  );

CREATE POLICY "org_email_config_update" ON public.org_email_config
  FOR UPDATE TO authenticated USING (
    EXISTS (
      SELECT 1 FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      WHERE om.profile_id = auth.uid()
        AND om.org_id = org_email_config.org_id
        AND r.name = 'admin'
        AND om.is_active = true
    )
  );

CREATE POLICY "org_email_config_delete" ON public.org_email_config
  FOR DELETE TO authenticated USING (
    EXISTS (
      SELECT 1 FROM org_memberships om
      JOIN roles r ON r.id = om.role_id
      WHERE om.profile_id = auth.uid()
        AND om.org_id = org_email_config.org_id
        AND r.name = 'admin'
        AND om.is_active = true
    )
  );

-- updated_at Trigger
CREATE OR REPLACE FUNCTION update_org_email_config_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_org_email_config_updated_at ON public.org_email_config;
CREATE TRIGGER trg_org_email_config_updated_at
  BEFORE UPDATE ON public.org_email_config
  FOR EACH ROW
  EXECUTE FUNCTION update_org_email_config_updated_at();
