"use client";

import { useState, useCallback, useEffect, useMemo } from "react";
import { createClient } from "@/lib/supabase";
import type { OrgPoll, OrgPollOption, OrgPollVote } from "@/types/database";
import { PollDateGrid } from "./poll-date-grid";
import { PollChoiceView } from "./poll-choice-view";
import { IconTrash, IconChevronDown, IconCheck } from "@/components/ui/icons";
import { useRealtimeTable } from "@/hooks/use-realtime-table";

interface PollCardProps {
  poll: OrgPoll;
  currentUserId: string;
  isAdmin: boolean;
  members: { id: string; name: string; avatar_url: string | null }[];
}

export function PollCard({ poll, currentUserId, isAdmin, members }: PollCardProps) {
  const supabase = createClient();
  const [options, setOptions] = useState<OrgPollOption[]>([]);
  const [votes, setVotes] = useState<OrgPollVote[]>([]);
  const [expanded, setExpanded] = useState(poll.status === "active");
  const [loading, setLoading] = useState(true);

  const isCreator = poll.created_by === currentUserId;
  const canManage = isCreator || isAdmin;
  const isActive = poll.status === "active";
  const isPastDeadline = poll.deadline ? new Date(poll.deadline) < new Date() : false;
  const disabled = !isActive || isPastDeadline;

  const loadData = useCallback(async () => {
    const [{ data: opts }, { data: v }] = await Promise.all([
      supabase
        .from("org_poll_options")
        .select("*")
        .eq("poll_id", poll.id)
        .order("sort_order"),
      supabase
        .from("org_poll_votes")
        .select("*")
        .eq("poll_id", poll.id),
    ]);
    if (opts) setOptions(opts as OrgPollOption[]);
    if (v) setVotes(v as OrgPollVote[]);
    setLoading(false);
  }, [supabase, poll.id]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  useRealtimeTable({
    table: "org_poll_votes",
    filter: { column: "poll_id", value: poll.id },
    onDataChange: loadData,
  });

  const voterCount = useMemo(
    () => new Set(votes.map((v) => v.voter_id)).size,
    [votes]
  );

  const handleClose = async () => {
    await supabase
      .from("org_polls")
      .update({ status: "closed" })
      .eq("id", poll.id);
  };

  const handleReopen = async () => {
    await supabase
      .from("org_polls")
      .update({ status: "active" })
      .eq("id", poll.id);
  };

  const handleDelete = async () => {
    if (!confirm("Umfrage wirklich löschen?")) return;
    await supabase.from("org_polls").delete().eq("id", poll.id);
  };

  const creatorName = useMemo(
    () => members.find((m) => m.id === poll.created_by)?.name || "Unbekannt",
    [members, poll.created_by]
  );

  return (
    <div
      className="rounded-xl border overflow-hidden"
      style={{
        borderColor: isActive ? "var(--color-border)" : "var(--color-muted)",
        background: "var(--color-surface)",
        opacity: isActive ? 1 : 0.75,
      }}
    >
      {/* Header */}
      <button
        onClick={() => setExpanded(!expanded)}
        className="w-full flex items-center gap-3 px-5 py-4 text-left hover:opacity-90 transition-opacity"
      >
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <h3 className="text-base font-semibold" style={{ color: "var(--color-foreground)" }}>
              {poll.title}
            </h3>
            <span
              className="text-xs px-2 py-0.5 rounded-full font-medium"
              style={{
                background: poll.poll_type === "date"
                  ? "var(--color-info-light)"
                  : "var(--color-muted)",
                color: poll.poll_type === "date"
                  ? "var(--color-info)"
                  : "var(--color-muted-foreground)",
              }}
            >
              {poll.poll_type === "date" ? "Terminumfrage" : "Umfrage"}
            </span>
            {!isActive && (
              <span
                className="text-xs px-2 py-0.5 rounded-full font-medium"
                style={{
                  background: "var(--color-muted)",
                  color: "var(--color-muted-foreground)",
                }}
              >
                Geschlossen
              </span>
            )}
          </div>
          <div className="flex items-center gap-3 mt-1 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            <span>von {creatorName}</span>
            <span>{voterCount} Teilnehmer</span>
            {poll.deadline && (
              <span>
                bis {new Date(poll.deadline).toLocaleDateString("de-DE", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" })}
              </span>
            )}
          </div>
        </div>
        <IconChevronDown
          size={20}
          style={{
            color: "var(--color-muted-foreground)",
            transform: expanded ? "rotate(180deg)" : "rotate(0deg)",
            transition: "transform 0.2s",
          }}
        />
      </button>

      {/* Body */}
      {expanded && (
        <div className="px-5 pb-5">
          {poll.description && (
            <p className="text-sm mb-4" style={{ color: "var(--color-muted-foreground)" }}>
              {poll.description}
            </p>
          )}

          {loading ? (
            <div className="py-8 text-center text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              Laden...
            </div>
          ) : poll.poll_type === "date" ? (
            <PollDateGrid
              poll={poll}
              options={options}
              votes={votes}
              currentUserId={currentUserId}
              members={members}
              onVoteChange={loadData}
              disabled={disabled}
            />
          ) : (
            <PollChoiceView
              poll={poll}
              options={options}
              votes={votes}
              currentUserId={currentUserId}
              members={members}
              onVoteChange={loadData}
              disabled={disabled}
            />
          )}

          {/* Actions */}
          {canManage && (
            <div className="flex items-center gap-2 mt-4 pt-3 border-t" style={{ borderColor: "var(--color-border)" }}>
              {isActive ? (
                <button
                  onClick={handleClose}
                  className="flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg hover:opacity-80"
                  style={{ color: "var(--color-muted-foreground)", background: "var(--color-muted)" }}
                >
                  <IconCheck size={14} /> Schliessen
                </button>
              ) : (
                <button
                  onClick={handleReopen}
                  className="flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg hover:opacity-80"
                  style={{ color: "var(--color-primary)" }}
                >
                  Wieder öffnen
                </button>
              )}
              <button
                onClick={handleDelete}
                className="flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg hover:opacity-80 ml-auto"
                style={{ color: "var(--color-destructive)" }}
              >
                <IconTrash size={14} /> Löschen
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
