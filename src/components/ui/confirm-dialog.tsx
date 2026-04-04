"use client";

import { useEffect, useRef } from "react";

// ─── Global Confirm Dialog (ersetzt window.confirm) ───

type ConfirmResolve = (value: boolean) => void;

let showConfirmFn: ((message: string, options?: ConfirmOptions) => Promise<boolean>) | null = null;

interface ConfirmOptions {
  title?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  variant?: "danger" | "default";
}

/**
 * Ersatz für window.confirm() — zeigt einen In-App Dialog.
 * Gibt ein Promise<boolean> zurück.
 */
export function appConfirm(message: string, options?: ConfirmOptions): Promise<boolean> {
  if (showConfirmFn) {
    return showConfirmFn(message, options);
  }
  // Fallback falls Provider nicht mounted
  return Promise.resolve(window.confirm(message));
}

/**
 * Ersatz für window.alert() — zeigt einen In-App Dialog ohne Cancel.
 */
export function appAlert(message: string, options?: { title?: string }): Promise<boolean> {
  if (showConfirmFn) {
    return showConfirmFn(message, { title: options?.title, cancelLabel: "", variant: "default" });
  }
  window.alert(message);
  return Promise.resolve(true);
}

// ─── Provider-Komponente (einmal in Layout mounten) ───

interface DialogState {
  message: string;
  options: ConfirmOptions;
  resolve: ConfirmResolve;
}

import { useState, useCallback } from "react";

export function ConfirmDialogProvider() {
  const [dialog, setDialog] = useState<DialogState | null>(null);
  const backdropRef = useRef<HTMLDivElement>(null);

  const show = useCallback((message: string, options?: ConfirmOptions): Promise<boolean> => {
    return new Promise<boolean>((resolve) => {
      setDialog({ message, options: options || {}, resolve });
    });
  }, []);

  // Registriere globale Funktion
  useEffect(() => {
    showConfirmFn = show;
    return () => { showConfirmFn = null; };
  }, [show]);

  const handleConfirm = useCallback(() => {
    dialog?.resolve(true);
    setDialog(null);
  }, [dialog]);

  const handleCancel = useCallback(() => {
    dialog?.resolve(false);
    setDialog(null);
  }, [dialog]);

  // Keyboard: Enter = Confirm, Escape = Cancel
  useEffect(() => {
    if (!dialog) return;
    function onKey(e: KeyboardEvent) {
      if (e.key === "Enter") { e.preventDefault(); handleConfirm(); }
      if (e.key === "Escape") { e.preventDefault(); handleCancel(); }
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [dialog, handleConfirm, handleCancel]);

  if (!dialog) return null;

  const { message, options } = dialog;
  const title = options.title || "Bestätigung";
  const confirmLabel = options.confirmLabel || "OK";
  const cancelLabel = options.cancelLabel;
  const showCancel = cancelLabel !== "";
  const isDanger = options.variant === "danger";

  return (
    <div
      ref={backdropRef}
      className="fixed inset-0 z-[100] flex items-center justify-center"
      style={{ background: "rgba(0,0,0,0.45)" }}
      onClick={(e) => { if (e.target === backdropRef.current) handleCancel(); }}
    >
      <div
        className="rounded-xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden animate-in"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
      >
        {/* Header */}
        <div className="px-5 pt-5 pb-2">
          <h3 className="text-base font-semibold">{title}</h3>
        </div>

        {/* Body */}
        <div className="px-5 pb-5">
          <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            {message}
          </p>
        </div>

        {/* Actions */}
        <div
          className="flex justify-end gap-2 px-5 py-3"
          style={{ borderTop: "1px solid var(--color-border)", background: "var(--color-muted)" }}
        >
          {showCancel && (
            <button
              onClick={handleCancel}
              className="px-4 py-2 text-sm rounded-lg font-medium transition-colors"
              style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
            >
              {cancelLabel || "Abbrechen"}
            </button>
          )}
          <button
            onClick={handleConfirm}
            className="px-4 py-2 text-sm rounded-lg font-medium transition-colors text-white"
            style={{
              background: isDanger ? "var(--color-destructive, var(--color-error))" : "var(--color-primary)",
            }}
            autoFocus
          >
            {confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
