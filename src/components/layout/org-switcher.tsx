"use client";

import { useRouter } from "next/navigation";
import { useOrg } from "@/contexts/org-context";
import { IconChevronDown, IconPlus } from "@/components/ui/icons";

export function OrgSwitcher() {
  const { orgId, orgName, orgs, switchOrg, loading } = useOrg();
  const router = useRouter();

  if (loading) {
    return (
      <div className="mx-3 mb-2">
        <div
          className="h-10 rounded-lg skeleton"
          style={{ background: "rgba(255,255,255,0.05)" }}
        />
      </div>
    );
  }

  // Nur eine Org → kompakte Anzeige ohne Dropdown
  if (orgs.length <= 1) {
    return (
      <div
        className="mx-3 mb-2 px-3 py-2.5 rounded-lg flex items-center justify-between"
        style={{ background: "rgba(255,255,255,0.05)" }}
      >
        <span
          className="text-sm font-medium truncate"
          style={{ color: "var(--color-sidebar-text)" }}
        >
          {orgName || "Organisation"}
        </span>
        <button
          onClick={() => router.push("/org/new")}
          className="p-1 rounded transition-colors flex-shrink-0"
          style={{ color: "var(--color-sidebar-text-muted)" }}
          title="Neue Organisation erstellen"
        >
          <IconPlus size={14} />
        </button>
      </div>
    );
  }

  // Mehrere Orgs → Dropdown
  return (
    <div className="mx-3 mb-2 relative">
      <select
        value={orgId || ""}
        onChange={(e) => {
          const value = e.target.value;
          if (value === "__new__") {
            router.push("/org/new");
          } else {
            switchOrg(value);
          }
        }}
        className="w-full appearance-none cursor-pointer px-3 py-2.5 pr-8 rounded-lg text-sm font-medium border-0 focus:outline-none"
        style={{
          background: "rgba(255,255,255,0.05)",
          color: "var(--color-sidebar-text)",
        }}
      >
        {orgs
          .filter((o) => o.isActive)
          .map((org) => (
            <option
              key={org.id}
              value={org.id}
              style={{ background: "var(--color-sidebar)", color: "var(--color-sidebar-text)" }}
            >
              {org.name}
            </option>
          ))}
        <option
          value="__new__"
          style={{ background: "var(--color-sidebar)", color: "var(--color-sidebar-text-muted)" }}
        >
          + Neue Organisation
        </option>
      </select>
      <div
        className="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none"
        style={{ color: "var(--color-sidebar-text-muted)" }}
      >
        <IconChevronDown size={14} />
      </div>
    </div>
  );
}
