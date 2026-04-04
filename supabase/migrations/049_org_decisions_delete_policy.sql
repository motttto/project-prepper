-- 049: Fehlende DELETE-Policy für org_decisions
-- Ersteller oder Admins können Decisions löschen

CREATE POLICY "org_decisions_delete" ON public.org_decisions
  FOR DELETE TO authenticated USING (
    created_by = auth.uid() OR is_admin()
  );
