"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { useInquiryInvitations } from "@/hooks/use-inquiry-invitations";
import {
  IconUserCheck,
  IconX,
  IconCheck,
} from "@/components/ui/icons";
import { TelegramShareButton } from "@/components/inquiries/telegram-share-button";

// ─────────────────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────────────────
interface OrgProfile {
  id: string;
  name: string;
  email: string;
  avatar_url: string | null;
  is_active: boolean;
}

interface InquiryTeamSectionProps {
  inquiryId: string;
  currentUserId: string;
  orgId: string;
  isCreator: boolean;
  isAdmin: boolean;
  telegramChatId: number | null;
  telegramMessageId: number | null;
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────
function Avatar({ name, avatarUrl, size = 32 }: { name: string; avatarUrl?: string | null; size?: number }) {
  if (avatarUrl) {
    return (
      <img
        src={avatarUrl}
        alt={name}
        className="rounded-full object-cover"
        style={{ width: size, height: size }}
      />
    );
  }
  return (
    <div
      className="rounded-full flex items-center justify-center text-xs font-semibold"
      style={{
        width: size,
        height: size,
        background: "var(--color-primary-light)",
        color: "var(--color-primary)",
      }}
    >
      {name?.[0]?.toUpperCase() || "?"}
    </div>
  );
}

// Status-Sortierung: Zugesagt → Eingeladen → Nicht angefragt → Abgesagt
function statusOrder(status: string | null): number {
  switch (status) {
    case "accepted": return 0;
    case "pending": return 1;
    case null: return 2; // nicht angefragt
    case "declined": return 3;
    default: return 4;
  }
}

// ─────────────────────────────────────────────────────────────────────────
// Component
// ─────────────────────────────────────────────────────────────────────────
export function InquiryTeamSection({
  inquiryId,
  currentUserId,
  orgId,
  isCreator,
  isAdmin,
  telegramChatId,
  telegramMessageId,
}: InquiryTeamSectionProps) {
  const supabase = createClient();
  const canInvite = isCreator || isAdmin;

  // Einladungen laden
  const { invitations, loading, invite, revoke } = useInquiryInvitations({
    inquiryId,
  });

  // Org-Profile laden
  const [allProfiles, setAllProfiles] = useState<OrgProfile[]>([]);
  const [profilesLoading, setProfilesLoading] = useState(true);

  const loadProfiles = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("org_memberships")
      .select("profile_id, is_active, profiles(id, name, email, avatar_url)")
      .eq("org_id", orgId)
      .eq("is_active", true);
    if (data) {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      setAllProfiles(
        data.map((m: any) => ({
          id: m.profiles?.id || m.profile_id,
          name: m.profiles?.name || "",
          email: m.profiles?.email || "",
          avatar_url: m.profiles?.avatar_url || null,
          is_active: m.is_active,
        }))
      );
    }
    setProfilesLoading(false);
  }, [supabase, orgId]);

  useEffect(() => {
    loadProfiles();
  }, [loadProfiles]);

  const [processingId, setProcessingId] = useState<string | null>(null);

  // Invitation-Lookup Map: profileId → invitation
  const invitationByProfile = new Map(
    invitations.map((inv) => [inv.invited_profile_id, inv])
  );

  // Alle Mitglieder außer dem aktuellen User, sortiert nach Status
  const teamMembers = allProfiles
    .filter((p) => p.id !== currentUserId)
    .map((p) => ({
      profile: p,
      invitation: invitationByProfile.get(p.id) || null,
      status: invitationByProfile.get(p.id)?.status || null,
    }))
    .sort((a, b) => statusOrder(a.status) - statusOrder(b.status));

  // Zähler
  const acceptedCount = invitations.filter((i) => i.status === "accepted").length;
  const totalMembers = teamMembers.length;

  async function handleInvite(profileId: string) {
    setProcessingId(profileId);
    await invite(profileId, currentUserId);
    setProcessingId(null);
  }

  async function handleRevoke(invitationId: string) {
    setProcessingId(invitationId);
    await revoke(invitationId);
    setProcessingId(null);
  }

  const cardStyle = {
    background: "var(--color-surface)",
    border: "1px solid var(--color-border)",
  };

  if (loading || profilesLoading) {
    return (
      <div className="p-4 rounded-xl mb-4 animate-pulse" style={cardStyle}>
        <div className="h-5 w-40 skeleton rounded mb-3" />
        <div className="h-10 skeleton rounded" />
      </div>
    );
  }

  return (
    <div className="p-4 rounded-xl mb-4" style={cardStyle}>
      {/* Header */}
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-2">
          <IconUserCheck size={16} style={{ color: "var(--color-muted-foreground)" }} />
          <h3
            className="text-sm font-semibold uppercase tracking-wider"
            style={{ color: "var(--color-muted-foreground)" }}
          >
            Team-Verfügbarkeit
          </h3>
          {invitations.length > 0 && (
            <span
              className="text-xs px-1.5 py-0.5 rounded-full font-medium"
            style={{
              background: "var(--color-success-light)",
              color: "var(--color-success)",
            }}
          >
            {acceptedCount}/{totalMembers}
            </span>
          )}
        </div>

        {/* Telegram-Button */}
        {canInvite && telegramChatId && (
          <TelegramShareButton
            inquiryId={inquiryId}
            telegramMessageId={telegramMessageId}
          />
        )}
      </div>

      {/* Team-Liste */}
      {teamMembers.length === 0 ? (
        <div
          className="text-center py-4 text-xs"
          style={{ color: "var(--color-muted-foreground)" }}
        >
          Keine weiteren Team-Mitglieder in der Organisation.
        </div>
      ) : (
        <div className="space-y-1">
          {teamMembers.map(({ profile, invitation, status }) => (
            <MemberRow
              key={profile.id}
              profile={profile}
              invitation={invitation}
              status={status}
              canInvite={canInvite}
              processingId={processingId}
              onInvite={() => handleInvite(profile.id)}
              onRevoke={() => invitation && handleRevoke(invitation.id)}
              onReinvite={() => handleInvite(profile.id)}
            />
          ))}
        </div>
      )}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// MemberRow — einzelnes Team-Mitglied
// ─────────────────────────────────────────────────────────────────────────
interface MemberRowProps {
  profile: OrgProfile;
  invitation: { id: string; status: string } | null;
  status: string | null;
  canInvite: boolean;
  processingId: string | null;
  onInvite: () => void;
  onRevoke: () => void;
  onReinvite: () => void;
}

function MemberRow({
  profile,
  invitation,
  status,
  canInvite,
  processingId,
  onInvite,
  onRevoke,
  onReinvite,
}: MemberRowProps) {
  const isProcessing = processingId === profile.id || processingId === invitation?.id;

  // Hintergrund + Dot-Farbe je nach Status
  const rowStyle = (() => {
    switch (status) {
      case "accepted":
        return {
          bg: "color-mix(in srgb, var(--color-success) 8%, transparent)",
          dot: "var(--color-success)",
          dotPulse: false,
        };
      case "pending":
        return {
          bg: "color-mix(in srgb, var(--color-warning) 8%, transparent)",
          dot: "var(--color-warning)",
          dotPulse: true,
        };
      case "declined":
        return {
          bg: "var(--color-muted)",
          dot: "var(--color-muted-foreground)",
          dotPulse: false,
        };
      default: // nicht angefragt
        return {
          bg: "transparent",
          dot: "var(--color-border)",
          dotPulse: false,
        };
    }
  })();

  return (
    <div
      className="flex items-center justify-between px-3 py-2 rounded-lg"
      style={{ background: rowStyle.bg }}
    >
      {/* Links: Dot + Avatar + Name */}
      <div className="flex items-center gap-2.5 min-w-0">
        <span
          className={`w-2 h-2 rounded-full flex-shrink-0 ${rowStyle.dotPulse ? "animate-pulse" : ""}`}
          style={{ background: rowStyle.dot }}
        />
        <Avatar
          name={profile.name}
          avatarUrl={profile.avatar_url}
          size={26}
        />
        <span
          className="text-sm truncate"
          style={{
            fontWeight: status === "accepted" ? 500 : 400,
            color: status === "declined" ? "var(--color-muted-foreground)" : undefined,
          }}
        >
          {profile.name}
        </span>
      </div>

      {/* Rechts: Status-Badge + Aktion */}
      <div className="flex items-center gap-2 flex-shrink-0">
        {status === "accepted" && (
          <span
            className="text-xs font-medium px-2 py-0.5 rounded-full"
            style={{
              background: "var(--color-success-light)",
              color: "var(--color-success)",
            }}
          >
            <IconCheck size={10} className="inline mr-0.5" />
            Zugesagt
          </span>
        )}

        {status === "pending" && (
          <>
            <span
              className="text-xs px-2 py-0.5 rounded-full"
              style={{
                background: "var(--color-warning-light)",
                color: "var(--color-warning)",
              }}
            >
              Angefragt
            </span>
            {canInvite && (
              <button
                onClick={onRevoke}
                disabled={isProcessing}
                className="p-1 rounded transition-colors disabled:opacity-50"
                style={{ color: "var(--color-muted-foreground)" }}
                onMouseEnter={(e) =>
                  (e.currentTarget.style.color = "var(--color-destructive)")
                }
                onMouseLeave={(e) =>
                  (e.currentTarget.style.color = "var(--color-muted-foreground)")
                }
                title="Einladung zurückziehen"
              >
                <IconX size={14} />
              </button>
            )}
          </>
        )}

        {status === "declined" && (
          <>
            <span
              className="text-xs px-2 py-0.5 rounded-full"
              style={{
                background: "var(--color-muted)",
                color: "var(--color-muted-foreground)",
                border: "1px solid var(--color-border)",
              }}
            >
              Abgesagt
            </span>
            {canInvite && (
              <button
                onClick={onReinvite}
                disabled={isProcessing}
                className="text-xs px-2 py-0.5 rounded-lg font-medium disabled:opacity-50 transition-colors"
                style={{
                  color: "var(--color-primary)",
                  border: "1px solid var(--color-primary)",
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.background = "var(--color-primary)";
                  e.currentTarget.style.color = "white";
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.background = "transparent";
                  e.currentTarget.style.color = "var(--color-primary)";
                }}
              >
                Erneut
              </button>
            )}
          </>
        )}

        {status === null && canInvite && (
          <button
            onClick={onInvite}
            disabled={isProcessing}
            className="text-xs px-2.5 py-1 rounded-lg font-medium disabled:opacity-50 transition-colors text-white"
            style={{ background: "var(--color-primary)" }}
          >
            {isProcessing ? "..." : "Einladen"}
          </button>
        )}

        {status === null && !canInvite && (
          <span
            className="text-xs"
            style={{ color: "var(--color-muted-foreground)" }}
          >
            —
          </span>
        )}
      </div>
    </div>
  );
}
