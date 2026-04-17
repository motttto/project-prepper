"use client";

import Link from "next/link";
import { useWorkspace } from "@/contexts/org-context";
import { IconUsers, IconChevronRight } from "@/components/ui/icons";

export default function TeamPage() {
  const { isSolo, groupId, groupName } = useWorkspace();

  if (isSolo) {
    return (
      <div className="max-w-2xl mx-auto px-4 py-12 text-center">
        <IconUsers size={40} className="mx-auto mb-3" style={{ color: "var(--color-muted-foreground)", opacity: 0.5 }} />
        <h1 className="text-xl font-semibold mb-2" style={{ color: "var(--color-foreground)" }}>
          Team-Verwaltung ist nur in Gruppen verfügbar
        </h1>
        <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          Wechsel oben links zu einer Gruppe oder gründe eine neue, um Mitglieder zu verwalten.
        </p>
      </div>
    );
  }

  // In Gruppe -> Direkt zur Gruppen-Detail-Seite leiten (dort ist die Member-Verwaltung)
  return (
    <div className="max-w-2xl">
      <div
        className="rounded-xl p-6"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
      >
        <div className="flex items-center gap-3 mb-4">
          <IconUsers size={20} style={{ color: "var(--color-primary)" }} />
          <h1 className="text-lg font-semibold" style={{ color: "var(--color-foreground)" }}>
            Team von &quot;{groupName}&quot;
          </h1>
        </div>
        <p className="text-sm mb-4" style={{ color: "var(--color-muted-foreground)" }}>
          Die Mitglieder-Verwaltung ist Teil der Gruppen-Seite.
        </p>
        {groupId && (
          <Link
            href={`/groups/${groupId}`}
            className="inline-flex items-center gap-1 px-4 py-2 rounded-lg text-sm font-medium text-white"
            style={{ background: "var(--color-primary)" }}
          >
            Zur Gruppen-Seite <IconChevronRight size={14} />
          </Link>
        )}
      </div>
    </div>
  );
}
