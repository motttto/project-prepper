-- Migration 103: SET search_path = public fuer alle SECURITY DEFINER Functions
--
-- Hintergrund: SECURITY DEFINER laeuft als Function-Owner. Ohne expliziten
-- search_path nutzt die Function den Suchpfad des Aufrufers — ein User
-- mit Schema-Create-Recht koennte eigene Schemas davorhaengen und die
-- Functions auf bosartige Tabellen umlenken (Schema-Hijack).
--
-- Fix: search_path explizit auf 'public' setzen. Tut keinen Schaden bei
-- bereits korrekten Functions; idempotent.

ALTER FUNCTION public.calculate_exit_settlement(p_org_id uuid, p_profile_id uuid) SET search_path = public;
ALTER FUNCTION public.calculate_project_profit(p_project_id uuid) SET search_path = public;
ALTER FUNCTION public.check_agreement_signatures() SET search_path = public;
ALTER FUNCTION public.check_decision_complete() SET search_path = public;
ALTER FUNCTION public.check_group_invitation_complete() SET search_path = public;
ALTER FUNCTION public.check_overdue_loans(p_org_id uuid) SET search_path = public;
ALTER FUNCTION public.create_test_user(p_org_id uuid, p_name text, p_email text, p_role_id uuid) SET search_path = public;
ALTER FUNCTION public.delete_user_completely(p_user_id uuid) SET search_path = public;
ALTER FUNCTION public.get_inventory_total_value(p_org_id uuid) SET search_path = public;
ALTER FUNCTION public.get_member_asset_value(p_org_id uuid, p_profile_id uuid) SET search_path = public;
ALTER FUNCTION public.get_member_last_sign_in(p_org_id uuid) SET search_path = public;
ALTER FUNCTION public.get_member_loans_summary(p_org_id uuid) SET search_path = public;
ALTER FUNCTION public.get_partner_inventory(p_org_id uuid) SET search_path = public;
ALTER FUNCTION public.get_partnership_stats(p_org_id uuid) SET search_path = public;
ALTER FUNCTION public.get_user_project_org_ids(p_project_id uuid) SET search_path = public;
ALTER FUNCTION public.handle_invitation_user_acceptance() SET search_path = public;
ALTER FUNCTION public.handle_new_group() SET search_path = public;
ALTER FUNCTION public.handle_new_org() SET search_path = public;
ALTER FUNCTION public.handle_new_project_owner() SET search_path = public;
ALTER FUNCTION public.handle_new_user() SET search_path = public;
ALTER FUNCTION public.handle_project_org_removal() SET search_path = public;
ALTER FUNCTION public.is_admin() SET search_path = public;
ALTER FUNCTION public.is_group_founder(p_group_id uuid) SET search_path = public;
ALTER FUNCTION public.is_group_member(p_group_id uuid) SET search_path = public;
ALTER FUNCTION public.is_org_admin(p_org_id uuid) SET search_path = public;
ALTER FUNCTION public.is_org_member(p_org_id uuid) SET search_path = public;
ALTER FUNCTION public.is_org_member_of(p_org_id uuid) SET search_path = public;
ALTER FUNCTION public.is_project_member(p_project_id uuid) SET search_path = public;
ALTER FUNCTION public.is_project_org_member(p_project_id uuid) SET search_path = public;
ALTER FUNCTION public.is_project_owner(p_project_id uuid) SET search_path = public;
ALTER FUNCTION public.link_group_invitations_to_new_profile() SET search_path = public;
ALTER FUNCTION public.log_membership_changes() SET search_path = public;
ALTER FUNCTION public.remove_org_member(p_org_id uuid, p_profile_id uuid, p_delete_user boolean) SET search_path = public;
ALTER FUNCTION public.vote_as_user(p_decision_id uuid, p_voter_id uuid, p_vote text, p_comment text) SET search_path = public;
