-- Migration 102: Security-Fix project_files + Storage-Policies enger ziehen
--
-- Bisher (Migration 034): project_files hatte alle 4 Policies mit USING(true)
-- → JEDER authenticated User konnte alle Dateien aller Orgs sehen/loeschen.
-- Storage-Policies fuer Bucket 'project-files' waren ebenfalls offen.
--
-- Fix:
-- 1. Helper user_can_access_project() (SECURITY DEFINER mit search_path)
-- 2. project_files-Policies via Helper
-- 3. Storage-Policies pruefen den Pfad-Praefix gegen Projekt-Zugriff
--    Pfad-Schema: {orgId}/{projectId}/{timestamp}_{safe_name}

-- ════════════════════════════════════════════════════════════════════════════
-- 1. Helper: user_can_access_project
-- ════════════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION public.user_can_access_project(p_project_id uuid)
RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  SELECT EXISTS (
    SELECT 1 FROM public.projects p
    WHERE p.id = p_project_id
      AND (
        p.owner_profile_id = auth.uid()
        OR (p.owner_group_id IS NOT NULL AND public.is_group_member(p.owner_group_id))
        OR (p.group_id IS NOT NULL AND public.is_group_member(p.group_id))
        OR EXISTS (
          SELECT 1 FROM public.project_members pm
          WHERE pm.project_id = p_project_id AND pm.profile_id = auth.uid()
        )
        OR EXISTS (
          SELECT 1 FROM public.profiles pr
          WHERE pr.id = auth.uid() AND pr.is_system = true
        )
      )
  );
$$;

GRANT EXECUTE ON FUNCTION public.user_can_access_project(uuid) TO authenticated;

-- ════════════════════════════════════════════════════════════════════════════
-- 2. project_files: alte using(true)-Policies ersetzen
-- ════════════════════════════════════════════════════════════════════════════

DROP POLICY IF EXISTS "Authenticated users can select project files" ON public.project_files;
DROP POLICY IF EXISTS "Authenticated users can insert project files" ON public.project_files;
DROP POLICY IF EXISTS "Authenticated users can update project files" ON public.project_files;
DROP POLICY IF EXISTS "Authenticated users can delete project files" ON public.project_files;

CREATE POLICY project_files_select ON public.project_files
  FOR SELECT TO authenticated USING (
    public.user_can_access_project(project_id)
  );

CREATE POLICY project_files_insert ON public.project_files
  FOR INSERT TO authenticated WITH CHECK (
    public.user_can_access_project(project_id)
    AND uploaded_by = auth.uid()
  );

CREATE POLICY project_files_update ON public.project_files
  FOR UPDATE TO authenticated USING (
    public.user_can_access_project(project_id)
    AND (uploaded_by = auth.uid() OR EXISTS (
      SELECT 1 FROM public.projects p WHERE p.id = project_id AND p.owner_profile_id = auth.uid()
    ))
  );

CREATE POLICY project_files_delete ON public.project_files
  FOR DELETE TO authenticated USING (
    public.user_can_access_project(project_id)
  );

-- ════════════════════════════════════════════════════════════════════════════
-- 3. Storage-Policies fuer Bucket 'project-files' (Pfad: orgId/projectId/...)
-- ════════════════════════════════════════════════════════════════════════════

DROP POLICY IF EXISTS "Authenticated users can upload project files" ON storage.objects;
DROP POLICY IF EXISTS "Authenticated users can update project files" ON storage.objects;
DROP POLICY IF EXISTS "Authenticated users can delete project files" ON storage.objects;
DROP POLICY IF EXISTS "Public read access for project files" ON storage.objects;

-- Pfad-Praefix [1] = orgId, [2] = projectId. user_can_access_project prueft Zugriff.
CREATE POLICY "project_files_storage_select" ON storage.objects
  FOR SELECT TO authenticated USING (
    bucket_id = 'project-files'
    AND public.user_can_access_project(
      ((storage.foldername(name))[2])::uuid
    )
  );

CREATE POLICY "project_files_storage_insert" ON storage.objects
  FOR INSERT TO authenticated WITH CHECK (
    bucket_id = 'project-files'
    AND public.user_can_access_project(
      ((storage.foldername(name))[2])::uuid
    )
  );

CREATE POLICY "project_files_storage_update" ON storage.objects
  FOR UPDATE TO authenticated USING (
    bucket_id = 'project-files'
    AND public.user_can_access_project(
      ((storage.foldername(name))[2])::uuid
    )
  );

CREATE POLICY "project_files_storage_delete" ON storage.objects
  FOR DELETE TO authenticated USING (
    bucket_id = 'project-files'
    AND public.user_can_access_project(
      ((storage.foldername(name))[2])::uuid
    )
  );

COMMENT ON FUNCTION public.user_can_access_project(uuid) IS
  'RLS-Helper: prueft ob auth.uid() Zugriff auf das Projekt hat (Owner, Group-Member, Project-Member, Superadmin). SECURITY DEFINER umgeht RLS-Rekursion.';
