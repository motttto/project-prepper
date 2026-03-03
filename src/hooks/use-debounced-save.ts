"use client";

import { useState, useRef, useCallback, useEffect } from "react";

export type SaveState = "idle" | "pending" | "saving" | "saved" | "error";

interface UseDebouncedSaveOptions<T> {
  saveFn: (value: T) => Promise<void>;
  delay?: number;
}

/**
 * Debounce-Save Hook für Auto-Save.
 *
 * - `debouncedSave(value)` — speichert nach `delay` ms Inaktivität
 * - `flush()` — sofort speichern (z.B. bei Tab-Wechsel oder Unmount)
 * - `saveState` — aktueller Status für SaveIndicator
 */
export function useDebouncedSave<T>({
  saveFn,
  delay = 800,
}: UseDebouncedSaveOptions<T>) {
  const [saveState, setSaveState] = useState<SaveState>("idle");
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const pendingValueRef = useRef<T | null>(null);
  const saveFnRef = useRef(saveFn);
  saveFnRef.current = saveFn;

  const savedTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Cleanup bei Unmount
  useEffect(() => {
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
      if (savedTimerRef.current) clearTimeout(savedTimerRef.current);
    };
  }, []);

  const executeSave = useCallback(async (value: T) => {
    setSaveState("saving");
    try {
      await saveFnRef.current(value);
      setSaveState("saved");
      // "Gespeichert" für 2s anzeigen, dann idle
      if (savedTimerRef.current) clearTimeout(savedTimerRef.current);
      savedTimerRef.current = setTimeout(() => setSaveState("idle"), 2000);
    } catch {
      setSaveState("error");
    }
    pendingValueRef.current = null;
  }, []);

  const debouncedSave = useCallback(
    (value: T) => {
      pendingValueRef.current = value;
      setSaveState("pending");
      if (timerRef.current) clearTimeout(timerRef.current);
      timerRef.current = setTimeout(() => {
        executeSave(value);
      }, delay);
    },
    [delay, executeSave]
  );

  // Sofort speichern (z.B. bei Tab-Wechsel, Navigation, onBlur)
  const flush = useCallback(() => {
    if (timerRef.current) {
      clearTimeout(timerRef.current);
      timerRef.current = null;
    }
    if (pendingValueRef.current !== null) {
      executeSave(pendingValueRef.current);
    }
  }, [executeSave]);

  return { debouncedSave, flush, saveState };
}
