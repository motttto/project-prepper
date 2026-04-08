"use client";

import { useMemo } from "react";
import { createClient } from "@/lib/supabase";
import type { OrgPoll, OrgPollOption, OrgPollVote, PollVoteChoice } from "@/types/database";

interface PollDateGridProps {
  poll: OrgPoll;
  options: OrgPollOption[];
  votes: OrgPollVote[];
  currentUserId: string;
  members: { id: string; name: string; avatar_url: string | null }[];
  onVoteChange: () => void;
  disabled?: boolean;
}

const voteIcons: Record<PollVoteChoice, { label: string; bg: string; color: string }> = {
  yes: { label: "\u2713", bg: "var(--color-success-light)", color: "var(--color-success)" },
  no: { label: "\u2717", bg: "var(--color-destructive-light)", color: "var(--color-destructive)" },
  maybe: { label: "?", bg: "var(--color-warning-light, #fef3c7)", color: "var(--color-warning, #d97706)" },
};

const voteOrder: PollVoteChoice[] = ["yes", "maybe", "no"];

export function PollDateGrid({ poll, options, votes, currentUserId, members, onVoteChange, disabled }: PollDateGridProps) {
  const supabase = createClient();

  // Alle Voter (die bereits abgestimmt haben + aktuelle User)
  const voterIds = useMemo(() => {
    const ids = new Set(votes.map((v) => v.voter_id));
    ids.add(currentUserId);
    return Array.from(ids);
  }, [votes, currentUserId]);

  const voterNames = useMemo(() => {
    const map: Record<string, string> = {};
    for (const m of members) map[m.id] = m.name;
    return map;
  }, [members]);

  // Vote-Lookup: `${optionId}-${voterId}` → vote
  const voteLookup = useMemo(() => {
    const map: Record<string, PollVoteChoice> = {};
    for (const v of votes) {
      map[`${v.option_id}-${v.voter_id}`] = v.vote;
    }
    return map;
  }, [votes]);

  // Summen pro Option
  const optionSums = useMemo(() => {
    const sums: Record<string, number> = {};
    for (const opt of options) {
      sums[opt.id] = votes.filter((v) => v.option_id === opt.id && v.vote === "yes").length;
    }
    return sums;
  }, [options, votes]);

  const handleVote = async (optionId: string, currentVote: PollVoteChoice | undefined) => {
    if (disabled) return;
    // Zyklisch: undefined → yes → maybe → no → löschen
    const nextIdx = currentVote ? voteOrder.indexOf(currentVote) + 1 : 0;

    if (nextIdx >= voteOrder.length) {
      // Löschen
      await supabase
        .from("org_poll_votes")
        .delete()
        .eq("poll_id", poll.id)
        .eq("option_id", optionId)
        .eq("voter_id", currentUserId);
    } else {
      const nextVote = voteOrder[nextIdx];
      // Upsert
      await supabase.from("org_poll_votes").upsert(
        {
          poll_id: poll.id,
          option_id: optionId,
          voter_id: currentUserId,
          vote: nextVote,
        },
        { onConflict: "poll_id,option_id,voter_id" }
      );
    }
    onVoteChange();
  };

  const maxSum = Math.max(...Object.values(optionSums), 1);

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm" style={{ borderCollapse: "separate", borderSpacing: 0 }}>
        <thead>
          <tr>
            <th
              className="text-left px-3 py-2 sticky left-0 z-10"
              style={{ background: "var(--color-surface)", minWidth: 120 }}
            />
            {options.map((opt) => (
              <th
                key={opt.id}
                className="px-3 py-2 text-center font-medium whitespace-nowrap"
                style={{ color: "var(--color-foreground)", minWidth: 80 }}
              >
                <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                  {opt.date_value
                    ? new Date(opt.date_value + "T00:00:00").toLocaleDateString("de-DE", { weekday: "short" })
                    : ""}
                </div>
                <div>
                  {opt.date_value
                    ? new Date(opt.date_value + "T00:00:00").toLocaleDateString("de-DE", { day: "2-digit", month: "2-digit" })
                    : opt.label}
                </div>
                {opt.time_value && (
                  <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    {opt.time_value.slice(0, 5)}
                  </div>
                )}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {voterIds.map((voterId) => {
            const isMe = voterId === currentUserId;
            return (
              <tr
                key={voterId}
                className="border-t"
                style={{
                  borderColor: "var(--color-border)",
                  background: isMe ? "var(--color-primary-light, rgba(59,130,246,0.05))" : undefined,
                }}
              >
                <td
                  className="px-3 py-2 font-medium sticky left-0 z-10 whitespace-nowrap"
                  style={{
                    color: "var(--color-foreground)",
                    background: isMe ? "var(--color-primary-light, rgba(59,130,246,0.05))" : "var(--color-surface)",
                  }}
                >
                  {voterNames[voterId] || "Unbekannt"}
                  {isMe && (
                    <span className="ml-1 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                      (du)
                    </span>
                  )}
                </td>
                {options.map((opt) => {
                  const key = `${opt.id}-${voterId}`;
                  const vote = voteLookup[key];
                  const style = vote ? voteIcons[vote] : null;

                  return (
                    <td key={opt.id} className="px-1 py-1 text-center">
                      {isMe ? (
                        <button
                          onClick={() => handleVote(opt.id, vote)}
                          disabled={disabled}
                          className="w-10 h-10 rounded-lg text-base font-bold transition-all mx-auto flex items-center justify-center disabled:opacity-50"
                          style={{
                            background: style?.bg || "var(--color-muted)",
                            color: style?.color || "var(--color-muted-foreground)",
                          }}
                          title={vote ? `Aktuell: ${vote === "yes" ? "Ja" : vote === "maybe" ? "Vielleicht" : "Nein"}` : "Klick zum Abstimmen"}
                        >
                          {style?.label || "\u00B7"}
                        </button>
                      ) : (
                        <div
                          className="w-10 h-10 rounded-lg text-base font-bold mx-auto flex items-center justify-center"
                          style={{
                            background: style?.bg || "transparent",
                            color: style?.color || "var(--color-muted-foreground)",
                          }}
                        >
                          {style?.label || ""}
                        </div>
                      )}
                    </td>
                  );
                })}
              </tr>
            );
          })}
          {/* Summen-Zeile */}
          <tr className="border-t-2" style={{ borderColor: "var(--color-border)" }}>
            <td
              className="px-3 py-2 font-semibold text-xs sticky left-0 z-10"
              style={{ color: "var(--color-muted-foreground)", background: "var(--color-surface)" }}
            >
              Zusagen
            </td>
            {options.map((opt) => (
              <td key={opt.id} className="px-1 py-2 text-center">
                <div
                  className="text-sm font-bold"
                  style={{
                    color: optionSums[opt.id] === maxSum && maxSum > 0
                      ? "var(--color-success)"
                      : "var(--color-foreground)",
                  }}
                >
                  {optionSums[opt.id]}
                </div>
              </td>
            ))}
          </tr>
        </tbody>
      </table>
    </div>
  );
}
