"use client";

import { IconShield, IconEye } from "./icons";

// ── Org-Level Rollen (profiles.role_id → roles) ──

export const orgRoleLabels: Record<string, string> = {
  system: "System",
  admin: "Admin",
  manager: "Manager",
  member: "Mitglied",
};

export const orgBadgeStyles: Record<string, { bg: string; color: string }> = {
  system: {
    bg: "var(--color-primary-light)",
    color: "var(--color-primary)",
  },
  admin: {
    bg: "var(--color-destructive-light)",
    color: "var(--color-destructive)",
  },
  manager: {
    bg: "var(--color-warning-light)",
    color: "var(--color-warning)",
  },
  member: {
    bg: "var(--color-muted)",
    color: "var(--color-muted-foreground)",
  },
};

// ── Projekt-Level Rollen (project_members.role) ──

export const projectRoleLabels: Record<string, string> = {
  owner: "Eigentümer",
  editor: "Bearbeiter",
  viewer: "Betrachter",
};

export const projectBadgeStyles: Record<string, { bg: string; color: string }> = {
  owner: { bg: "#fef3c7", color: "#b45309" },
  editor: { bg: "#dbeafe", color: "#1d4ed8" },
  viewer: { bg: "#f3f4f6", color: "#374151" },
};

// ── Badge Komponenten ──

type BadgeType = "org" | "project";

interface RoleBadgeProps {
  role: string;
  type?: BadgeType;
  showIcon?: boolean;
}

export function RoleBadge({ role, type = "org", showIcon = true }: RoleBadgeProps) {
  const isOrg = type === "org";
  const labels = isOrg ? orgRoleLabels : projectRoleLabels;
  const styles = isOrg ? orgBadgeStyles : projectBadgeStyles;
  const style = styles[role] || styles[isOrg ? "member" : "viewer"];
  const label = labels[role] || role;

  const hasShieldIcon = showIcon && (role === "admin" || role === "system" || role === "owner");
  const hasEyeIcon = showIcon && role === "viewer";

  return (
    <span
      className="px-2.5 py-1 rounded text-xs font-medium flex items-center gap-1 flex-shrink-0"
      style={{ background: style.bg, color: style.color }}
    >
      {hasShieldIcon && <IconShield size={11} />}
      {hasEyeIcon && <IconEye size={11} />}
      {label}
    </span>
  );
}

interface RoleBadgeGroupProps {
  badges: Array<{ role: string; type?: BadgeType }>;
}

export function RoleBadgeGroup({ badges }: RoleBadgeGroupProps) {
  return (
    <div className="flex items-center gap-1.5 flex-shrink-0">
      {badges.map((b, i) => (
        <RoleBadge key={i} role={b.role} type={b.type || "org"} />
      ))}
    </div>
  );
}
