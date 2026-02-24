"use client";

import { useEffect, useRef } from "react";
import { createClient } from "@/lib/supabase";
import type { RealtimeChannel } from "@supabase/supabase-js";

interface UseRealtimeTableOptions {
  table: string;
  filter?: { column: string; value: string };
  onDataChange: () => void;
  enabled?: boolean;
}

export function useRealtimeTable({
  table,
  filter,
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
    const channelName = filter
      ? `realtime:${table}:${filter.column}=eq.${filter.value}`
      : `realtime:${table}`;

    const pgFilter = filter
      ? `${filter.column}=eq.${filter.value}`
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
  }, [table, filter?.column, filter?.value, enabled]);
}
