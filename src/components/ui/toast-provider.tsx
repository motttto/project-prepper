"use client";

import { useToastState } from "@/hooks/use-toast";

export function ToastProvider() {
  const { toasts, dismiss } = useToastState();

  if (toasts.length === 0) return null;

  return (
    <div className="toast-container">
      {toasts.map((t) => (
        <div
          key={t.id}
          className={`toast toast-${t.type}`}
          onClick={() => dismiss(t.id)}
          role="alert"
        >
          {t.type === "success" && "✓ "}
          {t.type === "error" && "✕ "}
          {t.message}
        </div>
      ))}
    </div>
  );
}
