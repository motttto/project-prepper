"use client";

import type { SaveState } from "@/hooks/use-debounced-save";
import { IconCheck } from "@/components/ui/icons";

interface SaveIndicatorProps {
  state: SaveState;
}

/**
 * Kompakter Save-Status Indikator.
 *
 * idle    → nichts (unsichtbar)
 * pending → grauer Punkt (änderungen wartend)
 * saving  → Spinner
 * saved   → grüner Haken "Gespeichert"
 * error   → roter Text "Fehler"
 */
export function SaveIndicator({ state }: SaveIndicatorProps) {
  if (state === "idle") return null;

  return (
    <div className="flex items-center gap-1.5 text-xs font-medium">
      {state === "pending" && (
        <span
          className="flex items-center gap-1"
          style={{ color: "var(--color-muted-foreground)" }}
        >
          <span
            className="w-1.5 h-1.5 rounded-full"
            style={{ background: "var(--color-muted-foreground)" }}
          />
          Änderungen...
        </span>
      )}

      {state === "saving" && (
        <span
          className="flex items-center gap-1.5"
          style={{ color: "var(--color-muted-foreground)" }}
        >
          <span
            className="w-3.5 h-3.5 border-2 border-current rounded-full animate-spin"
            style={{ borderTopColor: "transparent" }}
          />
          Speichern...
        </span>
      )}

      {state === "saved" && (
        <span
          className="flex items-center gap-1"
          style={{ color: "var(--color-success)" }}
        >
          <IconCheck size={14} />
          Gespeichert
        </span>
      )}

      {state === "error" && (
        <span
          className="flex items-center gap-1"
          style={{ color: "var(--color-destructive)" }}
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="12" cy="12" r="10" />
            <line x1="15" y1="9" x2="9" y2="15" />
            <line x1="9" y1="9" x2="15" y2="15" />
          </svg>
          Fehler beim Speichern
        </span>
      )}
    </div>
  );
}
