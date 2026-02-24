"use client";

import type { PresenceUser } from "@/hooks/use-presence";

interface PresenceAvatarsProps {
  users: PresenceUser[];
  currentUserId?: string;
}

const avatarColors = [
  { bg: "#dbeafe", text: "#1d4ed8" },
  { bg: "#dcfce7", text: "#16a34a" },
  { bg: "#fef3c7", text: "#b45309" },
  { bg: "#fce7f3", text: "#be185d" },
  { bg: "#e0e7ff", text: "#4338ca" },
  { bg: "#f3e8ff", text: "#7c3aed" },
  { bg: "#ffe4e6", text: "#be123c" },
  { bg: "#f1f5f9", text: "#475569" },
];

function getColorForUser(userId: string) {
  let hash = 0;
  for (let i = 0; i < userId.length; i++) {
    hash = (hash << 5) - hash + userId.charCodeAt(i);
    hash |= 0;
  }
  return avatarColors[Math.abs(hash) % avatarColors.length];
}

function getInitials(name: string): string {
  const parts = name.trim().split(/\s+/);
  if (parts.length >= 2) {
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }
  return name.substring(0, 2).toUpperCase();
}

export function PresenceAvatars({
  users,
  currentUserId,
}: PresenceAvatarsProps) {
  if (users.length === 0) return null;

  // Eigener User zuletzt, andere zuerst
  const sorted = [...users].sort((a, b) => {
    if (a.userId === currentUserId) return 1;
    if (b.userId === currentUserId) return -1;
    return 0;
  });

  return (
    <div className="flex items-center gap-1.5">
      <span
        className="text-xs mr-1"
        style={{ color: "var(--color-text-muted)" }}
      >
        Online:
      </span>
      <div className="flex -space-x-2">
        {sorted.map((user) => {
          const color = getColorForUser(user.userId);
          const isSelf = user.userId === currentUserId;
          return (
            <div
              key={user.userId}
              className="relative w-8 h-8 rounded-full flex items-center justify-center text-xs font-semibold"
              style={{
                background: color.bg,
                color: color.text,
                opacity: isSelf ? 0.7 : 1,
                border: "2px solid var(--color-surface)",
              }}
              title={`${user.name}${isSelf ? " (Du)" : ""}`}
            >
              {getInitials(user.name)}
              {/* Grüner Online-Punkt */}
              <div
                className="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full"
                style={{
                  background: "#22c55e",
                  border: "2px solid var(--color-surface)",
                }}
              />
            </div>
          );
        })}
      </div>
    </div>
  );
}
