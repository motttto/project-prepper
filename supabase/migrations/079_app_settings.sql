-- Migration 079: app_settings (single-row global config)
-- ============================================================
-- Aktuell: mfa_enabled flag (waehrend Refactoring deaktiviert,
-- soll spaeter via Superadmin-Panel umschaltbar sein).

CREATE TABLE IF NOT EXISTS public.app_settings (
  id boolean PRIMARY KEY DEFAULT true,  -- erzwingt Einzel-Zeile
  mfa_enabled boolean NOT NULL DEFAULT false,
  updated_at timestamp with time zone NOT NULL DEFAULT now(),
  updated_by uuid REFERENCES auth.users(id) ON DELETE SET NULL,
  CONSTRAINT app_settings_singleton CHECK (id = true)
);

INSERT INTO public.app_settings (id, mfa_enabled)
VALUES (true, false)
ON CONFLICT (id) DO NOTHING;

ALTER TABLE public.app_settings ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS app_settings_select ON public.app_settings;
DROP POLICY IF EXISTS app_settings_update ON public.app_settings;

-- Lesen: jeder authenticated User (Middleware braucht es)
CREATE POLICY app_settings_select ON public.app_settings
  FOR SELECT TO authenticated USING (true);

-- Schreiben: nur Superadmins
CREATE POLICY app_settings_update ON public.app_settings
  FOR UPDATE TO authenticated
  USING (EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true))
  WITH CHECK (EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND is_system = true));

-- Auto-update timestamp
CREATE OR REPLACE FUNCTION public.app_settings_touch()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = now();
  NEW.updated_by = auth.uid();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS app_settings_touch_trg ON public.app_settings;
CREATE TRIGGER app_settings_touch_trg
  BEFORE UPDATE ON public.app_settings
  FOR EACH ROW EXECUTE FUNCTION public.app_settings_touch();
