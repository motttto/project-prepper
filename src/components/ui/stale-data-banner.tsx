"use client";

interface StaleDataBannerProps {
  show: boolean;
  onReload: () => void;
}

/**
 * Banner das angezeigt wird, wenn ein anderer User Änderungen gemacht hat
 * während der aktuelle User ein Feld bearbeitet.
 */
export function StaleDataBanner({ show, onReload }: StaleDataBannerProps) {
  if (!show) return null;

  return (
    <div
      className="flex items-center justify-between px-4 py-2.5 rounded-lg text-sm mb-4"
      style={{
        background: "var(--color-info-light)",
        color: "var(--color-info)",
        border: "1px solid var(--color-info)",
      }}
    >
      <span>Ein anderer Nutzer hat dieses Projekt geändert.</span>
      <button
        onClick={onReload}
        className="px-3 py-1 rounded-md text-xs font-medium transition-opacity hover:opacity-80"
        style={{
          background: "var(--color-info)",
          color: "white",
        }}
      >
        Neu laden
      </button>
    </div>
  );
}
