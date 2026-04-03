-- Migration 042: Fix infinite recursion in project_members RLS policies
-- Problem: INSERT/DELETE policies on project_members queried project_members
-- to check ownership, triggering SELECT policy → infinite recursion.
-- Fix: SECURITY DEFINER function bypasses RLS for the ownership check.

-- Helper function: Check if current user is project owner (bypasses RLS)
CREATE OR REPLACE FUNCTION public.is_project_owner(p_project_id uuid)
RETURNS boolean AS $$
BEGIN
  RETURN EXISTS (
    SELECT 1 FROM public.project_members
    WHERE project_id = p_project_id
    AND profile_id = auth.uid()
    AND role = 'owner'
  );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Replace INSERT policy to use SECURITY DEFINER function
DROP POLICY IF EXISTS members_insert ON public.project_members;
CREATE POLICY members_insert ON public.project_members
  FOR INSERT TO authenticated
  WITH CHECK (
    (EXISTS (SELECT 1 FROM projects p WHERE p.id = project_members.project_id
      AND (is_org_member(p.org_id) OR is_project_org_member(p.id))))
    AND (is_project_owner(project_members.project_id) OR is_admin())
  );

-- Replace DELETE policy to use SECURITY DEFINER function
DROP POLICY IF EXISTS members_delete ON public.project_members;
CREATE POLICY members_delete ON public.project_members
  FOR DELETE TO authenticated
  USING (
    (EXISTS (SELECT 1 FROM projects p WHERE p.id = project_members.project_id
      AND (is_org_member(p.org_id) OR is_project_org_member(p.id))))
    AND (is_project_owner(project_members.project_id) OR is_admin())
  );
