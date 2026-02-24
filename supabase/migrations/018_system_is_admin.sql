-- ── Migration 018: System-User hat Admin-Rechte auf DB-Ebene ──────────────
-- is_admin() erkennt jetzt auch is_system=true als Admin.
-- Damit kann der System-User über RLS andere Profile bearbeiten (toggle active etc.)

CREATE OR REPLACE FUNCTION public.is_admin()
RETURNS boolean AS $$
BEGIN
  RETURN EXISTS (
    SELECT 1 FROM public.profiles p
    LEFT JOIN public.roles r ON r.id = p.role_id
    WHERE p.id = auth.uid()
    AND (r.name = 'admin' OR p.is_system = true)
  );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;
