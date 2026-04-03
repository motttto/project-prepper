// Kern-Datentypen für den Projektplanner

export type UserRole = {
  id: string;
  name: string;
  permissions: Permission[];
};

export type Permission = {
  module: "projects" | "inventory" | "costs" | "users" | "settings";
  actions: ("create" | "read" | "update" | "delete")[];
};

// Pro-User Berechtigungen (Checkboxen)
export type PermissionKey =
  | "projects_view" | "projects_edit"
  | "inventory_view" | "inventory_edit"
  | "excel_export" | "excel_import"
  | "costs_view" | "costs_edit"
  | "team_view" | "team_manage"
  | "inquiries_view" | "inquiries_edit" | "inquiries_create";

// Backward-Compat: grobe Module für Sidebar-Filter
export type PermissionModule = "projects" | "inventory" | "costs" | "team" | "inquiries";

export type UserPermissions = Record<string, boolean>;

export type PermissionGroup = {
  label: string;
  permissions: { key: PermissionKey; label: string }[];
};

export const permissionGroups: PermissionGroup[] = [
  {
    label: "Projekte",
    permissions: [
      { key: "projects_view", label: "Projekte sehen" },
      { key: "projects_edit", label: "Projekte bearbeiten" },
    ],
  },
  {
    label: "Inventar",
    permissions: [
      { key: "inventory_view", label: "Inventar sehen" },
      { key: "inventory_edit", label: "Inventar bearbeiten" },
      { key: "excel_export", label: "Excel-Export" },
      { key: "excel_import", label: "Excel-Import" },
    ],
  },
  {
    label: "Finanzen",
    permissions: [
      { key: "costs_view", label: "Kosten & Budget einsehen" },
      { key: "costs_edit", label: "Kosten bearbeiten" },
    ],
  },
  {
    label: "Team",
    permissions: [
      { key: "team_view", label: "Team sehen" },
      { key: "team_manage", label: "Team verwalten" },
    ],
  },
  {
    label: "Anfragen",
    permissions: [
      { key: "inquiries_view", label: "Anfragen sehen" },
      { key: "inquiries_edit", label: "Anfragen bearbeiten" },
      { key: "inquiries_create", label: "Anfrage stellen" },
    ],
  },
];

// Alle Permission-Keys flach
export const allPermissionKeys: PermissionKey[] = permissionGroups.flatMap((g) => g.permissions.map((p) => p.key));

// Default-Permissions pro Rolle
export const defaultPermissionsByRole: Record<string, UserPermissions> = {
  admin: Object.fromEntries(allPermissionKeys.map((k) => [k, true])),
  manager: {
    projects_view: true, projects_edit: true,
    inventory_view: true, inventory_edit: true,
    excel_export: true, excel_import: true,
    costs_view: true, costs_edit: true,
    team_view: true, team_manage: false,
    inquiries_view: true, inquiries_edit: true, inquiries_create: true,
  },
  member: {
    projects_view: true, projects_edit: false,
    inventory_view: true, inventory_edit: false,
    excel_export: false, excel_import: false,
    costs_view: false, costs_edit: false,
    team_view: true, team_manage: false,
    inquiries_view: false, inquiries_edit: false, inquiries_create: false,
  },
};

// Mapping: grobe Module → welche Permission-Keys benötigt (für Sidebar)
export const modulePermissionMap: Record<PermissionModule, PermissionKey> = {
  projects: "projects_view",
  inventory: "inventory_view",
  costs: "costs_view",
  team: "team_view",
  inquiries: "inquiries_view",
};

export type User = {
  id: string;
  email: string;
  name: string;
  role_id: string;
  is_active: boolean;
  is_system: boolean;
  approved_at: string | null;
  avatar_url: string | null;
  telegram_user_id: number | null;
  created_at: string;
};

export type Project = {
  id: string;
  org_id: string;
  name: string;
  description: string | null;
  status: "draft" | "planning" | "active" | "completed" | "cancelled";
  date_start: string | null;
  date_end: string | null;
  // Venue / Spielstätte
  venue_name: string | null;
  venue_address: string | null;
  venue_contact_person: string | null;
  venue_phone: string | null;
  venue_notes: string | null;
  venue_maps_url: string | null;
  // Client / Auftraggeber
  client_name: string | null;
  client_contact_person: string | null;
  client_phone: string | null;
  client_email: string | null;
  // Show Details
  show_date: string | null;
  arrival_time: string | null;
  departure_time: string | null;
  setup_date: string | null;
  setup_date_end: string | null;
  teardown_date: string | null;
  teardown_date_end: string | null;
  // Budget
  budget_planned: number | null;
  budget_honorar: number | null;
  budget_technik: number | null;
  budget_transport: number | null;
  // Einnahmen / Gewinn (Migration 039)
  revenue_actual: number | null;
  profit_distribution_status: "pending" | "distributed" | "settled";
  // Notes
  transport_notes: string | null;
  internal_notes: string | null;
  // Meta
  created_by: string;
  created_at: string;
  updated_at: string;
};

export type InventoryItem = {
  id: string;
  org_id: string;
  inventory_number: string;
  name: string;
  description: string | null;
  category: string;
  quantity: number;
  condition: "new" | "good" | "fair" | "poor" | "broken" | "retired";
  cost_per_day: number;
  location: string | null;
  owner: string | null;
  purchased_by: string | null;
  purchased_at: string | null;
  image_url: string | null;
  device_name: string | null;
  serial_number: string | null;
  purchase_price: number | null;
  dimensions: string | null;
  power_watts: number | null;
  accessories: string[] | null;
  custom_field: string | null;
  manufacturer_url: string | null;
  manual_url: string | null;
  // Eigentum & Abschreibung (Migration 036)
  ownership_type: "organization" | "member" | "shared";
  owner_profile_id: string | null;
  ownership_shares: { profile_id: string; percentage: number; invested: number }[] | null;
  funding_source: "organization" | "self" | "project" | "sponsor";
  receipt_url: string | null;
  depreciation_method: "linear" | "none";
  depreciation_years: number;
  residual_value: number;
  current_value: number | null;
  created_at: string;
};

export type InventoryUnit = {
  id: string;
  item_id: string;
  org_id: string;
  unit_number: number;
  condition: InventoryItem["condition"];
  notes: string | null;
  created_at: string;
};

export type InventoryCategory = {
  id: string;
  org_id: string;
  name: string;
  icon: string;
  prefix: string;
  sort_order: number;
  created_at: string;
};

export type BookingApprovalStatus = "pending" | "approved" | "rejected";

export type Booking = {
  id: string;
  project_id: string;
  inventory_item_id: string;
  quantity: number;
  date_from: string;
  date_to: string;
  status: "reserved" | "checked_out" | "returned";
  notes: string | null;
  // Approval-Workflow (Cross-Org)
  requested_by: string | null;
  approval_status: BookingApprovalStatus;
  approved_by: string | null;
  approved_at: string | null;
  rejection_reason: string | null;
  source_org_id: string | null;
  created_at: string;
};

export type CostItem = {
  id: string;
  project_id: string;
  category: "personnel" | "material" | "inventory" | "external" | "other";
  description: string;
  amount_planned: number;
  amount_actual: number | null;
  vat_rate: number;
  created_at: string;
};

// === Neue Types für Event-Hub ===

export type ScheduleEntry = {
  id: string;
  project_id: string;
  title: string;
  schedule_date: string;
  time_start: string | null;
  time_end: string | null;
  description: string | null;
  sort_order: number;
  created_at: string;
};

export type TeamMember = {
  id: string;
  project_id: string;
  name: string;
  role: string;
  department: string | null;
  phone: string | null;
  email: string | null;
  notes: string | null;
  created_at: string;
};

export type ProjectContact = {
  id: string;
  project_id: string;
  name: string;
  role: string;
  company: string | null;
  phone: string | null;
  email: string | null;
  created_at: string;
};

export type Consumable = {
  id: string;
  project_id: string;
  name: string;
  quantity: number;
  unit: string;
  cost: number | null;
  notes: string | null;
  created_at: string;
};

export type Checklist = {
  id: string;
  project_id: string;
  name: string;
  sort_order: number;
  created_at: string;
};

export type ChecklistItem = {
  id: string;
  checklist_id: string;
  label: string;
  checked: boolean;
  sort_order: number;
  created_at: string;
};

export type ChecklistWithItems = Checklist & {
  project_checklist_items: ChecklistItem[];
};

// === Projekt-Mitgliedschaft & Einladungen ===

export type ProjectMemberRole = "owner" | "editor" | "viewer";

export type ProjectMember = {
  id: string;
  project_id: string;
  profile_id: string;
  role: ProjectMemberRole;
  created_at: string;
  profiles?: {
    name: string;
    email: string;
  };
};

export type InvitationStatus = "pending" | "accepted" | "declined";

export type ProjectInvitation = {
  id: string;
  project_id: string;
  invited_by: string;
  invited_profile_id: string;
  role: "editor" | "viewer";
  status: InvitationStatus;
  created_at: string;
  responded_at: string | null;
  profiles?: {
    name: string;
    email: string;
  };
  projects?: {
    name: string;
  };
};

export type EffectiveProjectRole =
  | "owner"
  | "editor"
  | "viewer"
  | "admin"
  | "none";

// === Aufgaben (Tasks) ===

export type TaskStatus = "todo" | "in_progress" | "done";
export type TaskPriority = "low" | "medium" | "high";
export type TaskAssignmentStatus = "pending" | "accepted" | "declined";

export type ProjectTask = {
  id: string;
  project_id: string;
  title: string;
  description: string | null;
  status: TaskStatus;
  priority: TaskPriority;
  assigned_to: string | null;
  assigned_to_team_id: string | null;
  assigned_to_contact_id: string | null;
  assignment_status: TaskAssignmentStatus;
  assigned_at: string | null;
  due_date: string | null;
  created_by: string | null;
  sort_order: number;
  created_at: string;
  // Joined data
  profiles?: { name: string } | null;
  project_team?: { name: string; role: string | null; department: string | null } | null;
  project_contacts?: { name: string; role: string | null; company: string | null } | null;
};

// === Task Notifications ===

export type TaskNotificationType = "assigned" | "unassigned";

export type TaskNotification = {
  id: string;
  task_id: string;
  profile_id: string;
  type: TaskNotificationType;
  is_read: boolean;
  created_at: string;
  // Joined data
  project_tasks?: {
    title: string;
    project_id: string;
    projects?: { name: string };
  };
};

// === Team-Freigabe (Votes) ===

export type TeamVote = {
  id: string;
  candidate_id: string;
  voter_id: string;
  org_id: string;
  created_at: string;
};

// === Organisationen (Multi-Tenant) ===

export type Organization = {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  logo_url: string | null;
  telegram_chat_id: number | null;
  created_by: string;
  created_at: string;
  updated_at: string;
};

export type OrgRoleName = "admin" | "manager" | "member";

// === Cross-Org Collaboration ===

export type ProjectOrgRole = "creator" | "partner";
export type ProjectOrgStatus = "pending" | "accepted" | "declined" | "removed";

export type ProjectOrg = {
  id: string;
  project_id: string;
  org_id: string;
  role: ProjectOrgRole;
  status: ProjectOrgStatus;
  invited_by: string | null;
  responded_at: string | null;
  created_at: string;
  // Joined data
  organizations?: { name: string; slug: string; logo_url: string | null };
};

export type OrgMembership = {
  id: string;
  org_id: string;
  profile_id: string;
  role_id: string;
  is_active: boolean;
  approved_at: string | null;
  created_at: string;
  // Joined data
  organizations?: { name: string; slug: string };
  profiles?: { name: string; email: string; avatar_url: string | null };
  roles?: { name: string };
};

// === Anfragen (Inquiry Pipeline) ===

export type InquiryStatus = "new" | "reviewing" | "offer_sent" | "accepted" | "rejected" | "archived";

export type Inquiry = {
  id: string;
  org_id: string;
  status: InquiryStatus;
  client_name: string;
  client_contact_person: string | null;
  client_phone: string | null;
  client_email: string | null;
  title: string;
  description: string | null;
  venue_name: string | null;
  venue_address: string | null;
  event_date_start: string | null;
  event_date_end: string | null;
  estimated_budget: number | null;
  offer_amount: number | null;
  offer_date: string | null;
  offer_valid_until: string | null;
  probability: number | null;
  next_follow_up: string | null;
  notes: string | null;
  project_id: string | null;
  telegram_message_id: number | null;
  created_by: string | null;
  created_at: string;
  updated_at: string;
};

// === Org-Einladungen ===

export type OrgInvitationStatus = "pending" | "accepted" | "expired" | "cancelled";

export type OrgInvitation = {
  id: string;
  org_id: string;
  email: string;
  invited_by: string;
  role_id: string;
  status: OrgInvitationStatus;
  created_at: string;
  accepted_at: string | null;
  // Joined data
  profiles?: { name: string };
  roles?: { name: string };
};

// === Projekt-Dateien ===

export type ProjectFile = {
  id: string;
  project_id: string;
  org_id: string;
  file_name: string;
  file_path: string;
  file_url: string;
  file_type: string;
  file_size: number | null;
  uploaded_by: string | null;
  created_at: string;
  // Joined data
  profiles?: { name: string } | null;
};

// === Anfragen-Einladungen (Inquiry Team) ===

export type InquiryInvitationStatus = "pending" | "accepted" | "declined";

export type InquiryInvitation = {
  id: string;
  inquiry_id: string;
  invited_by: string;
  invited_profile_id: string;
  status: InquiryInvitationStatus;
  created_at: string;
  responded_at: string | null;
  // Joined data
  inquiries?: { title: string };
  profiles?: { name: string; email: string; avatar_url: string | null };
  inviters?: { name: string };
};

// Beschlüsse (Migration 037)
export type DecisionType =
  | "general"
  | "asset_purchase"
  | "asset_disposal"
  | "profit_distribution"
  | "exit_settlement"
  | "policy";

export type DecisionStatus = "open" | "approved" | "rejected" | "expired";

export type OrgDecision = {
  id: string;
  org_id: string;
  title: string;
  description: string | null;
  decision_type: DecisionType;
  status: DecisionStatus;
  requires_unanimous: boolean;
  deadline: string | null;
  related_item_id: string | null;
  related_project_id: string | null;
  related_profile_id: string | null;
  metadata: Record<string, unknown> | null;
  created_by: string;
  resolved_at: string | null;
  created_at: string;
  // Joined
  creator?: { name: string; avatar_url: string | null };
  votes?: OrgDecisionVote[];
};

export type VoteChoice = "approve" | "reject" | "abstain";

export type OrgDecisionVote = {
  id: string;
  decision_id: string;
  voter_id: string;
  vote: VoteChoice;
  comment: string | null;
  created_at: string;
  // Joined
  voter?: { name: string; avatar_url: string | null };
};

// Austritts-Auslöse (Migration 038)
export type ExitSettlementStatus = "draft" | "pending_approval" | "approved" | "completed" | "cancelled";
export type SettlementMethod = "contribution_based" | "proportional" | "zero" | "custom";
export type SettlementAction = "buyout" | "return" | "keep" | "donate";

export type ExitSettlement = {
  id: string;
  org_id: string;
  profile_id: string;
  status: ExitSettlementStatus;
  decision_id: string | null;
  exit_date: string;
  total_owned_value: number;
  total_funded_value: number;
  total_payout: number;
  settlement_method: SettlementMethod;
  notes: string | null;
  created_by: string;
  resolved_at: string | null;
  created_at: string;
  // Joined
  profiles?: { name: string; email: string; avatar_url: string | null };
  items?: ExitSettlementItem[];
};

export type ExitSettlementItem = {
  id: string;
  settlement_id: string;
  inventory_item_id: string;
  ownership_type: string;
  current_value: number;
  action: SettlementAction;
  buyout_price: number | null;
  notes: string | null;
  created_at: string;
  // Joined
  inventory_items?: { name: string; inventory_number: string };
};

export type ExitCalculation = {
  profile_id: string;
  owned_items: {
    id: string;
    name: string;
    inventory_number: string;
    purchase_price: number | null;
    current_value: number | null;
    ownership_type: string;
    condition: string;
  }[];
  funded_items: {
    id: string;
    name: string;
    inventory_number: string;
    purchase_price: number | null;
    current_value: number | null;
    ownership_type: string;
    funding_source: string;
    condition: string;
  }[];
  total_owned_value: number;
  total_funded_value: number;
  total_settlement: number;
};

// Gewinnverteilung (Migration 039)
export type ShareType = "fixed" | "percentage" | "hourly";

export type ProjectProfitShare = {
  id: string;
  project_id: string;
  profile_id: string;
  share_type: ShareType;
  share_value: number;
  hours_worked: number | null;
  calculated_amount: number;
  is_paid: boolean;
  paid_at: string | null;
  notes: string | null;
  created_at: string;
  // Joined
  profiles?: { name: string; avatar_url: string | null };
};

// Leihgaben (Migration 040)
export type LoanStatus = "requested" | "active" | "returned" | "overdue" | "lost";

export type EquipmentLoan = {
  id: string;
  org_id: string;
  inventory_item_id: string;
  unit_id: string | null;
  quantity: number;
  borrower_id: string;
  approved_by: string | null;
  borrowed_at: string;
  due_date: string | null;
  returned_at: string | null;
  status: LoanStatus;
  condition_at_checkout: InventoryItem["condition"] | null;
  condition_at_return: InventoryItem["condition"] | null;
  purpose: string | null;
  notes: string | null;
  return_notes: string | null;
  created_at: string;
  updated_at: string;
  // Joined
  borrower?: { name: string; avatar_url: string | null };
  approver?: { name: string };
  inventory_items?: { name: string; inventory_number: string; image_url: string | null };
};

export type MemberLoanSummary = {
  borrower_id: string;
  borrower_name: string;
  active_loans: number;
  overdue_loans: number;
  total_items_out: number;
};
