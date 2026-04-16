"use client";

// Equipment-Tab: Wird in Phase 6.5 vollstaendig neu gebaut.
// Neuer Flow:
//   - Item-Picker zeigt eigenes Inventar + Items, die fuer die Projekt-Gruppe freigegeben sind
//   - Per-Projekt-Erlaubnis (inventory_project_grants) muss vom Owner bestaetigt werden
//   - Buchungen weiter ueber bookings-Tabelle

import type { Project } from "@/types/database";
import { IconPackage } from "@/components/ui/icons";

interface TabEquipmentProps {
  projectId: string;
  project: Project;
  currentOrgId: string | null;
}

export function TabEquipment(_props: TabEquipmentProps) {
  return (
    <div className="max-w-2xl mx-auto py-12 text-center">
      <IconPackage size={40} className="mx-auto mb-3" style={{ color: "var(--color-muted-foreground)", opacity: 0.5 }} />
      <h2 className="text-lg font-semibold mb-2" style={{ color: "var(--color-foreground)" }}>
        Equipment-Tab wird neu gebaut
      </h2>
      <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
        Neuer Flow: Item-Picker zeigt dein eigenes Inventar plus alles, was Mitglieder
        deiner Gruppe fuer das Projekt freigeben. Owner muss pro Projekt zustimmen.
      </p>
      <p className="text-xs mt-4" style={{ color: "var(--color-muted-foreground)" }}>
        Aktuell kannst du Inventar-Sharing ueber den Inventar-Bereich konfigurieren.
      </p>
    </div>
  );
}
