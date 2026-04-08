"use client";

import { useMemo } from "react";
import { createClient } from "@/lib/supabase";
import type { OrgPoll, OrgPollOption, OrgPollVote } from "@/types/database";
import { IconCheck } from "@/components/ui/icons";

interface PollChoiceViewProps {
  poll: OrgPoll;
  options: OrgPollOption[];
  votes: OrgPollVote[];
  currentUserId: string;
  members: { id: string; name: string; avatar_url: string | null }[];
  onVoteChange: () => void;
  disabled?: boolean;
}

export function PollChoiceView({ poll, options, votes, currentUserId, members, onVoteChange, disabled }: PollChoiceViewProps) {
  const supabase = createClient();

  const myVotes = useMemo(
    () => new Set(votes.filter((v) => v.voter_id === currentUserId).map((v) => v.option_id)),
    [votes, currentUserId]
  );

  const totalVoters = useMemo(
    () => new Set(votes.filter((v) => v.vote === "yes").map((v) => v.voter_id)).size,
    [votes]
  );

  const optionVotes = useMemo(() => {
    const map: Record<string, { count: number; voters: string[] }> = {};
    for (const opt of options) {
      const optVotes = votes.filter((v) => v.option_id === opt.id && v.vote === "yes");
      map[opt.id] = {
        count: optVotes.length,
        voters: optVotes.map((v) => {
          const m = members.find((m) => m.id === v.voter_id);
          return m?.name || "Unbekannt";
        }),
      };
    }
    return map;
  }, [options, votes, members]);

  const maxVotes = Math.max(...Object.values(optionVotes).map((v) => v.count), 1);

  const handleToggle = async (optionId: string) => {
    if (disabled) return;

    if (myVotes.has(optionId)) {
      // Vote entfernen
      await supabase
        .from("org_poll_votes")
        .delete()
        .eq("poll_id", poll.id)
        .eq("option_id", optionId)
        .eq("voter_id", currentUserId);
    } else {
      if (!poll.allows_multiple) {
        // Bei Einzelauswahl: bisherige Votes löschen
        await supabase
          .from("org_poll_votes")
          .delete()
          .eq("poll_id", poll.id)
          .eq("voter_id", currentUserId);
      }
      // Vote hinzufügen
      await supabase.from("org_poll_votes").upsert(
        {
          poll_id: poll.id,
          option_id: optionId,
          voter_id: currentUserId,
          vote: "yes",
        },
        { onConflict: "poll_id,option_id,voter_id" }
      );
    }
    onVoteChange();
  };

  return (
    <div className="space-y-2">
      {options.map((opt) => {
        const data = optionVotes[opt.id] || { count: 0, voters: [] };
        const isSelected = myVotes.has(opt.id);
        const pct = totalVoters > 0 ? Math.round((data.count / totalVoters) * 100) : 0;

        return (
          <button
            key={opt.id}
            onClick={() => handleToggle(opt.id)}
            disabled={disabled}
            className="w-full text-left rounded-lg p-3 transition-all relative overflow-hidden disabled:opacity-60"
            style={{
              border: `2px solid ${isSelected ? "var(--color-primary)" : "var(--color-border)"}`,
              background: "var(--color-surface)",
            }}
          >
            {/* Fortschrittsbalken Hintergrund */}
            <div
              className="absolute inset-0 transition-all duration-300"
              style={{
                width: `${pct}%`,
                background: isSelected
                  ? "var(--color-primary-light, rgba(59,130,246,0.08))"
                  : "var(--color-muted)",
                opacity: 0.5,
              }}
            />

            <div className="relative flex items-center gap-3">
              {/* Checkbox */}
              <div
                className="w-5 h-5 rounded flex-shrink-0 flex items-center justify-center border-2 transition-colors"
                style={{
                  borderColor: isSelected ? "var(--color-primary)" : "var(--color-border)",
                  background: isSelected ? "var(--color-primary)" : "transparent",
                }}
              >
                {isSelected && <IconCheck size={12} className="text-white" />}
              </div>

              {/* Label + Voter-Count */}
              <div className="flex-1 min-w-0">
                <div className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>
                  {opt.label}
                </div>
                {!poll.is_anonymous && data.voters.length > 0 && (
                  <div className="text-xs mt-0.5 truncate" style={{ color: "var(--color-muted-foreground)" }}>
                    {data.voters.join(", ")}
                  </div>
                )}
              </div>

              {/* Prozent + Absolute */}
              <div className="text-right flex-shrink-0">
                <div
                  className="text-sm font-bold"
                  style={{
                    color: data.count === maxVotes && maxVotes > 0
                      ? "var(--color-primary)"
                      : "var(--color-foreground)",
                  }}
                >
                  {pct}%
                </div>
                <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                  {data.count} {data.count === 1 ? "Stimme" : "Stimmen"}
                </div>
              </div>
            </div>
          </button>
        );
      })}

      <div className="text-xs pt-1" style={{ color: "var(--color-muted-foreground)" }}>
        {totalVoters} {totalVoters === 1 ? "Person hat" : "Personen haben"} abgestimmt
        {poll.allows_multiple && " (Mehrfachauswahl)"}
      </div>
    </div>
  );
}
