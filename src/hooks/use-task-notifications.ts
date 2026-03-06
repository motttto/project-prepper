"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type { TaskNotification } from "@/types/database";

export function useTaskNotifications(userId: string | undefined) {
  const supabase = createClient();
  const [notifications, setNotifications] = useState<TaskNotification[]>([]);

  const load = useCallback(async () => {
    if (!userId) return;
    const { data } = await supabase
      .from("task_notifications")
      .select("*, project_tasks(title, project_id, projects(name))")
      .eq("profile_id", userId)
      .eq("is_read", false)
      .order("created_at", { ascending: false });
    if (data) setNotifications(data as TaskNotification[]);
  }, [supabase, userId]);

  useEffect(() => {
    load();
  }, [load]);

  useRealtimeTable({
    table: "task_notifications",
    onDataChange: load,
    enabled: !!userId,
  });

  const markRead = useCallback(
    async (notificationId: string) => {
      await supabase
        .from("task_notifications")
        .update({ is_read: true })
        .eq("id", notificationId);
      load();
    },
    [supabase, load]
  );

  const markAllRead = useCallback(async () => {
    if (!userId) return;
    await supabase
      .from("task_notifications")
      .update({ is_read: true })
      .eq("profile_id", userId)
      .eq("is_read", false);
    load();
  }, [supabase, userId, load]);

  // Accept task + mark notification as read
  const acceptTask = useCallback(
    async (taskId: string, notificationId: string) => {
      await Promise.all([
        supabase.from("project_tasks").update({ assignment_status: "accepted" }).eq("id", taskId),
        supabase.from("task_notifications").update({ is_read: true }).eq("id", notificationId),
      ]);
      load();
    },
    [supabase, load]
  );

  // Decline task + mark notification as read
  const declineTask = useCallback(
    async (taskId: string, notificationId: string) => {
      await Promise.all([
        supabase.from("project_tasks").update({ assignment_status: "declined" }).eq("id", taskId),
        supabase.from("task_notifications").update({ is_read: true }).eq("id", notificationId),
      ]);
      load();
    },
    [supabase, load]
  );

  return {
    notifications,
    pendingCount: notifications.length,
    markRead,
    markAllRead,
    acceptTask,
    declineTask,
    reload: load,
  };
}
