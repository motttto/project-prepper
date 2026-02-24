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
  created_at: string;
};

export type Project = {
  id: string;
  name: string;
  description: string | null;
  status: "draft" | "planning" | "active" | "completed" | "cancelled";
  date_start: string | null;
  date_end: string | null;
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
  created_at: string;
};
