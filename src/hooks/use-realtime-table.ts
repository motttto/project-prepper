"use client";

import { useEffect, useRef } from "react";
import { createClient } from "@/lib/supabase";
import type { RealtimeChannel } from "@supabase/supabase-js";

interface UseRealtimeTableOptions {
  table: string;
  filter?: { column: string; value: string };
  orgFilter?: string; // org_id Filter für Org-Scoping
  onDataChange: () => void;
  enabled?: boolean;
}

export function useRealtimeTable({
  table,
  filter,
  orgFilter,
  onDataChange,
  enabled = true,
}: UseRealtimeTableOptions): void {
  // Ref für Callback — verhindert Re-Subscribe wenn Callback-Identität sich ändert
  const callbackRef = useRef(onDataChange);
  callbackRef.current = onDataChange;

  useEffect(() => {
    if (!enabled) return;

    const supabase = createClient();

    // Eindeutiger Channel-Name pro Tabelle + Filter
    const filterParts: string[] = [];
    if (filter) filterParts.push(`${filter.column}=eq.${filter.value}`);
    if (orgFilter) filterParts.push(`org_id=eq.${orgFilter}`);

    const channelName = filterParts.length > 0
      ? `realtime:${table}:${filterParts.join(",")}`
      : `realtime:${table}`;

    // Supabase Realtime unterstützt nur einen Filter — wir nehmen den spezifischeren
    const pgFilter = filter
      ? `${filter.column}=eq.${filter.value}`
      : orgFilter
        ? `org_id=eq.${orgFilter}`
        : undefined;

    const channelConfig: {
      event: string;
      schema: string;
      table: string;
      filter?: string;
    } = {
      event: "*",
      schema: "public",
      table,
    };

    if (pgFilter) {
      channelConfig.filter = pgFilter;
    }

    const channel: RealtimeChannel = supabase
      .channel(channelName)
      .on(
        "postgres_changes" as never,
        channelConfig,
        () => {
          callbackRef.current();
        }
      )
      .subscribe();

    return () => {
      supabase.removeChannel(channel);
    };
  }, [table, filter?.column, filter?.value, orgFilter, enabled]);
}
