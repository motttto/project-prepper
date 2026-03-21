"use client";

import { useImpersonate } from "@/contexts/impersonate-context";
import { IconX, IconEye } from "@/components/ui/icons";

export function ImpersonateBanner() {
  const { impersonating, stopImpersonating } = useImpersonate();

  if (!impersonating) return null;

  return (
    <div
      className="flex items-center justify-between gap-3 px-4 py-2.5 rounded-lg mb-4"
      style={{
        background: "var(--color-warning-light)",
        border: "1px solid var(--color-warning)",
      }}
    >
      <div className="flex items-center gap-2 text-sm font-medium" style={{ color: "var(--color-warning)" }}>
        <IconEye size={16} />
        Du siehst die App als <strong>{impersonating.name}</strong> ({impersonating.roleName})
      </div>
      <button
        onClick={stopImpersonating}
        className="flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-medium transition-colors"
        style={{
          background: "var(--color-warning)",
          color: "#fff",
        }}
      >
        <IconX size={12} />
        Zurück zur Admin-Ansicht
      </button>
    </div>
  );
}
