"use client";

import { useWorkspace } from "@/contexts/org-context";
import { IconCosts } from "@/components/ui/icons";

export default function CostsPage() {
  const { isSolo } = useWorkspace();

  if (isSolo) {
    return (
      <div className="max-w-2xl mx-auto px-4 py-12 text-center">
        <IconCosts size={40} className="mx-auto mb-3" style={{ color: "var(--color-muted-foreground)", opacity: 0.5 }} />
        <h1 className="text-xl font-semibold mb-2" style={{ color: "var(--color-foreground)" }}>
          Kostenuebersicht ist nur in Gruppen verfuegbar
        </h1>
        <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          Im Solo-Modus siehst du Kosten direkt im jeweiligen Projekt.
        </p>
      </div>
    );
  }

  return (
    <div className="max-w-2xl mx-auto px-4 py-12 text-center">
      <IconCosts size={40} className="mx-auto mb-3" style={{ color: "var(--color-muted-foreground)", opacity: 0.5 }} />
      <h1 className="text-xl font-semibold mb-2" style={{ color: "var(--color-foreground)" }}>
        Gruppen-Kostenuebersicht
      </h1>
      <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
        Diese Funktion wird gerade ueberarbeitet.
      </p>
    </div>
  );
}
