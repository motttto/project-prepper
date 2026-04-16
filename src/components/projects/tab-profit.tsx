"use client";

// Profit-Tab: Wird in Phase 6.5 vollstaendig neu gebaut.
// Neuer Flow:
//   - Projekt-Profit pro Beteiligten gemaess Cooperation Agreement
//   - Anschaffungen via group_decisions (statt org_decisions)
//   - Auszahlungs-Vote ueber alle Group-Members des Projekts

import type { Project } from "@/types/database";
import { IconCosts } from "@/components/ui/icons";

interface TabProfitProps {
  project: Project;
  canEdit: boolean;
}

export function TabProfit(_props: TabProfitProps) {
  return (
    <div className="max-w-2xl mx-auto py-12 text-center">
      <IconCosts size={40} className="mx-auto mb-3" style={{ color: "var(--color-muted-foreground)", opacity: 0.5 }} />
      <h2 className="text-lg font-semibold mb-2" style={{ color: "var(--color-foreground)" }}>
        Gewinn-Tab wird neu gebaut
      </h2>
      <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
        Profit wird zukuenftig automatisch nach der Kooperationsvereinbarung des
        Projekts berechnet. Auszahlungen werden gruppen-intern abgestimmt.
      </p>
      <p className="text-xs mt-4" style={{ color: "var(--color-muted-foreground)" }}>
        Aktuell ist der Tab pausiert waehrend des Refactors.
      </p>
    </div>
  );
}
