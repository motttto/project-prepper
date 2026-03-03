"use client";

import { useState, useEffect, useCallback, useRef } from "react";
import { createClient } from "@/lib/supabase";
import { useInquiryInvitations } from "@/hooks/use-inquiry-invitations";
import {
  IconUserPlus,
  IconUserCheck,
  IconX,
  IconCheck,
} from "@/components/ui/icons";

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

// ─────────────────────────────────────────────────────────────────────────
// Component
// ─────────────────────────────────────────────────────────────────────────
export function InquiryTeamSection({
  inquiryId,
  currentUserId,
  orgId,
  isCreator,
  isAdmin,
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

  // Einladen-Dropdown
  const [showDropdown, setShowDropdown] = useState(false);
  const [processingId, setProcessingId] = useState<string | null>(null);
  const dropdownRef = useRef<HTMLDivElement>(null);

  // Click-outside
  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setShowDropdown(false);
      }
    }
    if (showDropdown) document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, [showDropdown]);

  // Noch nicht eingeladene Profile (ohne aktuellen User)
  const invitedIds = new Set(invitations.map((i) => i.invited_profile_id));
  const uninvitedProfiles = allProfiles.filter(
    (p) => !invitedIds.has(p.id) && p.id !== currentUserId
  );

  // Gruppierung der Einladungen
  const accepted = invitations.filter((i) => i.status === "accepted");
  const pending = invitations.filter((i) => i.status === "pending");
  const declined = invitations.filter((i) => i.status === "declined");

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

  // Profil-Name lookup
  function getProfileName(profileId: string): string {
    const profile = allProfiles.find((p) => p.id === profileId);
    return profile?.name || (invitations.find((i) => i.invited_profile_id === profileId)?.profiles?.name) || "Unbekannt";
  }

  function getProfileAvatar(profileId: string): string | null {
    const profile = allProfiles.find((p) => p.id === profileId);
    return profile?.avatar_url || invitations.find((i) => i.invited_profile_id === profileId)?.profiles?.avatar_url || null;
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
              {accepted.length}/{invitations.length}
            </span>
          )}
        </div>

        {/* Einladen-Button */}
        {canInvite && (
          <div className="relative" ref={dropdownRef}>
            <button
              onClick={() => setShowDropdown(!showDropdown)}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
              style={{
                background: showDropdown ? "var(--color-primary)" : "var(--color-muted)",
                color: showDropdown ? "white" : "var(--color-foreground)",
              }}
              onMouseEnter={(e) => {
                if (!showDropdown) {
                  e.currentTarget.style.background = "var(--color-primary)";
                  e.currentTarget.style.color = "white";
                }
              }}
              onMouseLeave={(e) => {
                if (!showDropdown) {
                  e.currentTarget.style.background = "var(--color-muted)";
                  e.currentTarget.style.color = "var(--color-foreground)";
                }
              }}
            >
              <IconUserPlus size={14} />
              Einladen
            </button>

            {/* Dropdown */}
            {showDropdown && (
              <div
                className="absolute right-0 top-full mt-1 w-72 rounded-xl shadow-xl z-50 overflow-hidden"
                style={{
                  background: "var(--color-surface)",
                  border: "1px solid var(--color-border)",
                }}
              >
                {uninvitedProfiles.length === 0 ? (
                  <div
                    className="p-4 text-center text-xs"
                    style={{ color: "var(--color-muted-foreground)" }}
                  >
                    Alle Team-Mitglieder bereits eingeladen
                  </div>
                ) : (
                  <div className="max-h-60 overflow-y-auto">
                    {uninvitedProfiles.map((profile) => (
                      <div
                        key={profile.id}
                        className="flex items-center justify-between p-2.5 transition-colors cursor-pointer"
                        style={{ borderBottom: "1px solid var(--color-border)" }}
                        onMouseEnter={(e) =>
                          (e.currentTarget.style.background = "var(--color-muted)")
                        }
                        onMouseLeave={(e) =>
                          (e.currentTarget.style.background = "transparent")
                        }
                      >
                        <div className="flex items-center gap-2.5 min-w-0">
                          <Avatar
                            name={profile.name}
                            avatarUrl={profile.avatar_url}
                            size={28}
                          />
                          <div className="min-w-0">
                            <p className="text-sm font-medium truncate">
                              {profile.name}
                            </p>
                            <p
                              className="text-xs truncate"
                              style={{ color: "var(--color-muted-foreground)" }}
                            >
                              {profile.email}
                            </p>
                          </div>
                        </div>
                        <button
                          onClick={() => handleInvite(profile.id)}
                          disabled={processingId === profile.id}
                          className="flex-shrink-0 px-2.5 py-1 rounded-lg text-xs font-medium text-white disabled:opacity-50"
                          style={{ background: "var(--color-primary)" }}
                        >
                          {processingId === profile.id ? "..." : "+ Einladen"}
                        </button>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}
          </div>
        )}
      </div>

      {/* Einladungen-Übersicht */}
      {invitations.length === 0 ? (
        <div
          className="text-center py-4 text-xs"
          style={{ color: "var(--color-muted-foreground)" }}
        >
          Noch niemand eingeladen — wähle Team-Mitglieder aus, um ihre Verfügbarkeit anzufragen.
        </div>
      ) : (
        <div className="space-y-1.5">
          {/* Zugesagt */}
          {accepted.map((inv) => (
            <div
              key={inv.id}
              className="flex items-center justify-between px-3 py-2 rounded-lg"
              style={{ background: "color-mix(in srgb, var(--color-success) 8%, transparent)" }}
            >
              <div className="flex items-center gap-2.5">
                <span
                  className="w-2 h-2 rounded-full flex-shrink-0"
                  style={{ background: "var(--color-success)" }}
                />
                <Avatar
                  name={getProfileName(inv.invited_profile_id)}
                  avatarUrl={getProfileAvatar(inv.invited_profile_id)}
                  size={26}
                />
                <span className="text-sm font-medium">
                  {getProfileName(inv.invited_profile_id)}
                </span>
              </div>
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
            </div>
          ))}

          {/* Eingeladen (pending) */}
          {pending.map((inv) => (
            <div
              key={inv.id}
              className="flex items-center justify-between px-3 py-2 rounded-lg"
              style={{ background: "color-mix(in srgb, var(--color-warning) 8%, transparent)" }}
            >
              <div className="flex items-center gap-2.5">
                <span
                  className="w-2 h-2 rounded-full flex-shrink-0 animate-pulse"
                  style={{ background: "var(--color-warning)" }}
                />
                <Avatar
                  name={getProfileName(inv.invited_profile_id)}
                  avatarUrl={getProfileAvatar(inv.invited_profile_id)}
                  size={26}
                />
                <span className="text-sm">
                  {getProfileName(inv.invited_profile_id)}
                </span>
              </div>
              <div className="flex items-center gap-2">
                <span
                  className="text-xs px-2 py-0.5 rounded-full"
                  style={{
                    background: "var(--color-warning-light)",
                    color: "var(--color-warning)",
                  }}
                >
                  Eingeladen
                </span>
                {canInvite && (
                  <button
                    onClick={() => handleRevoke(inv.id)}
                    disabled={processingId === inv.id}
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
              </div>
            </div>
          ))}

          {/* Abgesagt */}
          {declined.map((inv) => (
            <div
              key={inv.id}
              className="flex items-center justify-between px-3 py-2 rounded-lg"
              style={{ background: "var(--color-muted)" }}
            >
              <div className="flex items-center gap-2.5">
                <span
                  className="w-2 h-2 rounded-full flex-shrink-0"
                  style={{ background: "var(--color-muted-foreground)" }}
                />
                <Avatar
                  name={getProfileName(inv.invited_profile_id)}
                  avatarUrl={getProfileAvatar(inv.invited_profile_id)}
                  size={26}
                />
                <span
                  className="text-sm"
                  style={{ color: "var(--color-muted-foreground)" }}
                >
                  {getProfileName(inv.invited_profile_id)}
                </span>
              </div>
              <div className="flex items-center gap-2">
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
                    onClick={() => handleInvite(inv.invited_profile_id)}
                    disabled={processingId === inv.invited_profile_id}
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
                    title="Erneut einladen"
                  >
                    Erneut einladen
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
