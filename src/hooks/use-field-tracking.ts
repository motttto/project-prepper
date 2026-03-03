"use client";

import { useState, useCallback, useRef } from "react";

/**
 * Field-Level Dirty-Tracking für Multi-User Auto-Save.
 *
 * Verfolgt welche Felder der lokale User gerade bearbeitet (dirty).
 * Remote-Updates via `mergeRemote()` überschreiben nur clean fields.
 *
 * Usage:
 * ```tsx
 * const { localData, updateField, mergeRemote, dirtyFields, isDirty, markAllClean } =
 *   useFieldTracking(serverProject);
 * ```
 */
export function useFieldTracking<T extends Record<string, unknown>>(serverData: T) {
  const [localData, setLocalData] = useState<T>(serverData);
  const [dirtyFields, setDirtyFields] = useState<Set<string>>(new Set());

  // Ref um den letzten serverData-Stand zu tracken (für mergeRemote)
  const serverRef = useRef(serverData);
  serverRef.current = serverData;

  /**
   * Einzelnes Feld lokal ändern → markiert als dirty.
   */
  const updateField = useCallback(<K extends keyof T>(field: K, value: T[K]) => {
    setDirtyFields((prev) => new Set([...prev, field as string]));
    setLocalData((prev) => ({ ...prev, [field]: value }));
  }, []);

  /**
   * Remote-Daten einmergen (z.B. nach Realtime-Update).
   * Überschreibt nur Felder, die der User NICHT gerade editiert.
   */
  const mergeRemote = useCallback(
    (remoteData: T) => {
      setLocalData((prev) => {
        const merged = { ...remoteData };
        // Dirty fields behalten den lokalen Wert
        for (const field of dirtyFields) {
          if (field in prev) {
            (merged as Record<string, unknown>)[field] = prev[field as keyof T];
          }
        }
        return merged;
      });
    },
    [dirtyFields]
  );

  /**
   * Bestimmte Felder als "clean" markieren (nach erfolgreichem Save).
   */
  const markClean = useCallback((fields: (keyof T)[]) => {
    setDirtyFields((prev) => {
      const next = new Set(prev);
      for (const f of fields) next.delete(f as string);
      return next;
    });
  }, []);

  /**
   * Alle Felder als clean markieren.
   */
  const markAllClean = useCallback(() => {
    setDirtyFields(new Set());
  }, []);

  /**
   * Lokale Daten komplett auf Server-Stand zurücksetzen.
   */
  const resetToServer = useCallback((data: T) => {
    setLocalData(data);
    setDirtyFields(new Set());
  }, []);

  /**
   * Gibt nur die geänderten Felder als Partial<T> zurück (für den Save-Payload).
   */
  const getDirtyPayload = useCallback((): Partial<T> => {
    const payload: Partial<T> = {};
    for (const field of dirtyFields) {
      (payload as Record<string, unknown>)[field] = localData[field as keyof T];
    }
    return payload;
  }, [dirtyFields, localData]);

  return {
    localData,
    dirtyFields,
    isDirty: dirtyFields.size > 0,
    updateField,
    mergeRemote,
    markClean,
    markAllClean,
    resetToServer,
    getDirtyPayload,
  };
}
