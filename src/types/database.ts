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

export type User = {
  id: string;
  email: string;
  name: string;
  role_id: string;
  is_active: boolean;
  approved_at: string | null;
  avatar_url: string | null;
  created_at: string;
};

export type Project = {
  id: string;
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
  teardown_date: string | null;
  // Budget
  budget_planned: number | null;
  budget_honorar: number | null;
  budget_technik: number | null;
  budget_transport: number | null;
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
  created_at: string;
};

export type Booking = {
  id: string;
  project_id: string;
  inventory_item_id: string;
  quantity: number;
  date_from: string;
  date_to: string;
  status: "reserved" | "checked_out" | "returned";
  notes: string | null;
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

export type ProjectTask = {
  id: string;
  project_id: string;
  title: string;
  description: string | null;
  status: TaskStatus;
  priority: TaskPriority;
  assigned_to: string | null;
  due_date: string | null;
  created_by: string | null;
  sort_order: number;
  created_at: string;
  profiles?: { name: string } | null;
};

// === Team-Freigabe (Votes) ===

export type TeamVote = {
  id: string;
  candidate_id: string;
  voter_id: string;
  created_at: string;
};
