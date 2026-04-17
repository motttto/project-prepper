"use client";

// Workspace-Switcher (früher OrgSwitcher).
// Optionen:
// - "Solo Workspace" (kein Group-Modus)
// - jede Group in der User Mitglied ist
// - "+ Neue Gruppe" -> /groups/new

import { useRouter } from "next/navigation";
import { useWorkspace } from "@/contexts/org-context";
import { IconChevronDown, IconUser, IconShield } from "@/components/ui/icons";

export function OrgSwitcher() {
  const { groupId, groups, switchWorkspace, loading } = useWorkspace();
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

  const activeGroups = groups.filter((g) => g.isActive);

  return (
    <div className="mx-3 mb-2 relative">
      <select
        value={groupId || "__solo__"}
        onChange={(e) => {
          const v = e.target.value;
          if (v === "__new__") {
            router.push("/groups/new");
          } else if (v === "__solo__") {
            switchWorkspace(null);
          } else {
            switchWorkspace(v);
          }
        }}
        className="w-full appearance-none cursor-pointer px-3 py-2.5 pr-8 rounded-lg text-sm font-medium border-0 focus:outline-none"
        style={{
          background: "rgba(255,255,255,0.05)",
          color: "var(--color-sidebar-text)",
        }}
      >
        <option
          value="__solo__"
          style={{ background: "var(--color-sidebar)", color: "var(--color-sidebar-text)" }}
        >
          {groupId === null ? "👤 Solo-Workspace" : "Solo-Workspace"}
        </option>
        {activeGroups.length > 0 && (
          <option disabled style={{ background: "var(--color-sidebar)" }}>
            ─── Gruppen ───
          </option>
        )}
        {activeGroups.map((g) => (
          <option
            key={g.id}
            value={g.id}
            style={{ background: "var(--color-sidebar)", color: "var(--color-sidebar-text)" }}
          >
            🛡️ {g.name}
          </option>
        ))}
        <option disabled style={{ background: "var(--color-sidebar)" }}>
          ─────────────
        </option>
        <option
          value="__new__"
          style={{ background: "var(--color-sidebar)", color: "var(--color-sidebar-text-muted)" }}
        >
          + Neue Gruppe gründen
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
