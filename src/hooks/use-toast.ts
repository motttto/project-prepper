"use client";

import { useState, useEffect, useCallback } from "react";

export interface Toast {
  id: string;
  message: string;
  type: "success" | "error" | "info";
}

/** Show a toast from anywhere — uses CustomEvent on window */
export function showToast(message: string, type: Toast["type"] = "info") {
  const id = Math.random().toString(36).slice(2);
  window.dispatchEvent(
    new CustomEvent("app-toast", { detail: { id, message, type } })
  );
}

/** Hook for the ToastProvider component */
export function useToastState() {
  const [toasts, setToasts] = useState<Toast[]>([]);

  useEffect(() => {
    function handler(e: Event) {
      const toast = (e as CustomEvent).detail as Toast;
      setToasts((prev) => [...prev, toast]);
      setTimeout(() => {
        setToasts((prev) => prev.filter((t) => t.id !== toast.id));
      }, 3500);
    }
    window.addEventListener("app-toast", handler);
    return () => window.removeEventListener("app-toast", handler);
  }, []);

  const dismiss = useCallback((id: string) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  return { toasts, dismiss };
}
