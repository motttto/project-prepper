"use client";

import { useState } from "react";
import { usePresence } from "@/hooks/use-presence";
import { useCurrentUser } from "@/hooks/use-current-user";

export function OnlineIndicator() {
  const currentUser = useCurrentUser();
  const [open, setOpen] = useState(false);
  const { users } = usePresence({
    channelName: "presence:global",
    currentUser: currentUser
      ? { id: currentUser.id, name: currentUser.name, email: currentUser.email }
      : null,
  });

  if (!currentUser) return null;

  // andere Online-User (ohne mich)
  const others = users.filter((u) => u.userId !== currentUser.id);
  const totalOnline = users.length;

  return (
    <div className="relative">
      <button
        onClick={() => setOpen(!open)}
        className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium transition-colors"
        style={{ color: "var(--color-muted-foreground)" }}
        onMouseEnter={(e) => (e.currentTarget.style.background = "var(--color-muted)")}
        onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}
        title={
          others.length === 0
            ? "Du bist alleine online"
            : `${others.length} weitere${others.length !== 1 ? "" : "r"} online`
        }
      >
        <span
          className="w-2 h-2 rounded-full"
          style={{ background: totalOnline > 0 ? "#22c55e" : "var(--color-muted-foreground)" }}
        />
        <span>{totalOnline} online</span>
      </button>

      {open && others.length > 0 && (
        <>
          <div
            className="fixed inset-0 z-40"
            onClick={() => setOpen(false)}
          />
          <div
            className="absolute right-0 top-full mt-2 z-50 w-64 rounded-xl shadow-lg p-2"
            style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
          >
            <div className="px-2 py-1 text-xs font-semibold mb-1" style={{ color: "var(--color-muted-foreground)" }}>
              Gerade online
            </div>
            {others.map((u) => (
              <div
                key={u.userId}
                className="flex items-center gap-2 px-2 py-1.5 rounded-md text-sm"
                title={u.email}
              >
                <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ background: "#22c55e" }} />
                <span className="truncate">{u.name}</span>
              </div>
            ))}
          </div>
        </>
      )}
    </div>
  );
}
