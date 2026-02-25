"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import type { Booking, InventoryItem } from "@/types/database";
import { useRealtimeTable } from "@/hooks/use-realtime-table";

export type BookingWithDetails = Booking & {
  inventory_items: Pick<InventoryItem, "name" | "inventory_number" | "cost_per_day" | "org_id">;
  projects: { name: string };
  profiles: { name: string; email: string } | null;
};

interface UseBookingApprovalsResult {
  pendingBookings: BookingWithDetails[];
  pendingCount: number;
  loading: boolean;
  approve: (bookingId: string) => Promise<void>;
  reject: (bookingId: string, reason?: string) => Promise<void>;
}

export function useBookingApprovals(orgId: string | null): UseBookingApprovalsResult {
  const supabase = createClient();
  const [pendingBookings, setPendingBookings] = useState<BookingWithDetails[]>([]);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    if (!orgId) {
      setLoading(false);
      return;
    }

    const { data } = await supabase
      .from("bookings")
      .select(
        `*,
        inventory_items(name, inventory_number, cost_per_day, org_id),
        projects(name),
        profiles!requested_by(name, email)`
      )
      .eq("source_org_id", orgId)
      .eq("approval_status", "pending")
      .order("created_at", { ascending: false });

    if (data) setPendingBookings(data as BookingWithDetails[]);
    setLoading(false);
  }, [supabase, orgId]);

  useEffect(() => {
    load();
  }, [load]);

  // Realtime: Buchungen live aktualisieren
  useRealtimeTable({
    table: "bookings",
    onDataChange: load,
    enabled: !!orgId,
  });

  async function approve(bookingId: string) {
    const {
      data: { user },
    } = await supabase.auth.getUser();
    if (!user) return;

    // 1. Approval setzen
    await supabase
      .from("bookings")
      .update({
        approval_status: "approved",
        approved_by: user.id,
        approved_at: new Date().toISOString(),
      })
      .eq("id", bookingId);

    // 2. Cost-Item erstellen
    const booking = pendingBookings.find((b) => b.id === bookingId);
    if (booking && booking.inventory_items) {
      const days =
        Math.ceil(
          (new Date(booking.date_to).getTime() - new Date(booking.date_from).getTime()) /
            (1000 * 60 * 60 * 24)
        ) + 1;
      const totalCost = Number(booking.inventory_items.cost_per_day) * booking.quantity * days;

      await supabase.from("cost_items").insert({
        project_id: booking.project_id,
        category: "inventory",
        description: `${booking.inventory_items.name} (${booking.quantity}x, ${days} Tage) [extern]`,
        amount_planned: totalCost,
        amount_actual: totalCost,
      });
    }

    load();
  }

  async function reject(bookingId: string, reason?: string) {
    await supabase
      .from("bookings")
      .update({
        approval_status: "rejected",
        rejection_reason: reason || null,
      })
      .eq("id", bookingId);
    load();
  }

  return {
    pendingBookings,
    pendingCount: pendingBookings.length,
    loading,
    approve,
    reject,
  };
}
