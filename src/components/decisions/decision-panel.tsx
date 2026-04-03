"use client";

import { useState, useCallback, useEffect } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type { OrgDecision, OrgDecisionVote, DecisionType, VoteChoice } from "@/types/database";
import { IconPlus, IconCheck, IconX } from "@/components/ui/icons";

const decisionTypeLabels: Record<DecisionType, string> = {
  general: "Allgemein",
  asset_purchase: "Anschaffung",
  asset_disposal: "Veräußerung",
  profit_distribution: "Gewinnverteilung",
  exit_settlement: "Austritt",
  policy: "Regelung",
};

const statusLabels: Record<string, { label: string; color: string; bg: string }> = {
  open: { label: "Offen", color: "var(--color-warning)", bg: "var(--color-warning-light)" },
  approved: { label: "Angenommen", color: "var(--color-success)", bg: "var(--color-success-light)" },
  rejected: { label: "Abgelehnt", color: "var(--color-error)", bg: "var(--color-error-light)" },
  expired: { label: "Abgelaufen", color: "var(--color-muted-foreground)", bg: "var(--color-muted)" },
};

export function DecisionPanel() {
  const supabase = createClient();
  const { orgId } = useOrg();
  const user = useCurrentUser();

  const [decisions, setDecisions] = useState<OrgDecision[]>([]);
  const [votes, setVotes] = useState<Record<string, OrgDecisionVote[]>>({});
  const [activeMembers, setActiveMembers] = useState<{ id: string; name: string }[]>([]);
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);
  const [newTitle, setNewTitle] = useState("");
  const [newDescription, setNewDescription] = useState("");
  const [newType, setNewType] = useState<DecisionType>("general");
  const [newUnanimous, setNewUnanimous] = useState(true);
  const [creating, setCreating] = useState(false);
  const [votingId, setVotingId] = useState<string | null>(null);
  const [voteComment, setVoteComment] = useState("");

  const loadDecisions = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("org_decisions")
      .select("*, creator:created_by(name, avatar_url)")
      .eq("org_id", orgId)
      .order("created_at", { ascending: false });
    if (data) setDecisions(data);
    setLoading(false);
  }, [supabase, orgId]);

  const loadVotes = useCallback(async () => {
    if (!orgId || decisions.length === 0) return;
    const decisionIds = decisions.map((d) => d.id);
    const { data } = await supabase
      .from("org_decision_votes")
      .select("*, voter:voter_id(name, avatar_url)")
      .in("decision_id", decisionIds);
    if (data) {
      const grouped: Record<string, OrgDecisionVote[]> = {};
      data.forEach((v: OrgDecisionVote) => {
        if (!grouped[v.decision_id]) grouped[v.decision_id] = [];
        grouped[v.decision_id].push(v);
      });
      setVotes(grouped);
    }
  }, [supabase, orgId, decisions]);

  const loadMembers = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("org_memberships")
      .select("profile_id, profiles(name)")
      .eq("org_id", orgId)
      .eq("is_active", true);
    if (data) {
      setActiveMembers(
        data.map((m: any) => ({ id: m.profile_id, name: m.profiles?.name || "" }))
      );
    }
  }, [supabase, orgId]);

  useEffect(() => { loadDecisions(); loadMembers(); }, [loadDecisions, loadMembers]);
  useEffect(() => { loadVotes(); }, [loadVotes]);

  // Realtime
  useRealtimeTable({ table: "org_decisions", onDataChange: loadDecisions });
  useRealtimeTable({ table: "org_decision_votes", onDataChange: () => { loadDecisions(); loadVotes(); } });

  async function handleCreate() {
    if (!orgId || !user || !newTitle.trim()) return;
    setCreating(true);
    await supabase.from("org_decisions").insert({
      org_id: orgId,
      title: newTitle.trim(),
      description: newDescription.trim() || null,
      decision_type: newType,
      requires_unanimous: newUnanimous,
      created_by: user.id,
    });
    setNewTitle("");
    setNewDescription("");
    setNewType("general");
    setShowCreate(false);
    setCreating(false);
  }

  async function handleVote(decisionId: string, vote: VoteChoice) {
    if (!user) return;
    await supabase.from("org_decision_votes").upsert({
      decision_id: decisionId,
      voter_id: user.id,
      vote,
      comment: voteComment.trim() || null,
    }, { onConflict: "decision_id,voter_id" });
    setVotingId(null);
    setVoteComment("");
  }

  function getMyVote(decisionId: string): OrgDecisionVote | undefined {
    return votes[decisionId]?.find((v) => v.voter_id === user?.id);
  }

  const inputStyle = {
    border: "1px solid var(--color-border)",
    background: "var(--color-background)",
  };

  const openDecisions = decisions.filter((d) => d.status === "open");
  const closedDecisions = decisions.filter((d) => d.status !== "open");

  if (loading) {
    return (
      <div className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
        Lade Beschlüsse...
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h3 className="text-base font-bold">
          Beschlüsse
          {openDecisions.length > 0 && (
            <span
              className="ml-2 px-2 py-0.5 rounded-full text-xs font-medium"
              style={{ background: "var(--color-warning-light)", color: "var(--color-warning)" }}
            >
              {openDecisions.length} offen
            </span>
          )}
        </h3>
        <button
          onClick={() => setShowCreate(!showCreate)}
          className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-white transition-colors"
          style={{ background: "var(--color-primary)" }}
        >
          <IconPlus size={12} />
          Neuer Beschluss
        </button>
      </div>

      {/* Erstellen-Formular */}
      {showCreate && (
        <div
          className="rounded-lg p-4 space-y-3"
          style={{ background: "var(--color-muted)", border: "1px solid var(--color-border-light)" }}
        >
          <input
            type="text"
            value={newTitle}
            onChange={(e) => setNewTitle(e.target.value)}
            placeholder="Titel des Beschlusses"
            className="w-full px-3 py-2 rounded-lg text-sm"
            style={inputStyle}
            autoFocus
          />
          <textarea
            value={newDescription}
            onChange={(e) => setNewDescription(e.target.value)}
            placeholder="Beschreibung (optional)"
            className="w-full px-3 py-2 rounded-lg text-sm"
            style={inputStyle}
            rows={2}
          />
          <div className="flex gap-3 items-center">
            <select
              value={newType}
              onChange={(e) => setNewType(e.target.value as DecisionType)}
              className="px-3 py-2 rounded-lg text-sm"
              style={inputStyle}
            >
              {Object.entries(decisionTypeLabels).map(([key, label]) => (
                <option key={key} value={key}>{label}</option>
              ))}
            </select>
            <label className="flex items-center gap-2 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
              <input
                type="checkbox"
                checked={newUnanimous}
                onChange={(e) => setNewUnanimous(e.target.checked)}
              />
              Einstimmig erforderlich
            </label>
          </div>
          <div className="flex gap-2 justify-end">
            <button
              onClick={() => setShowCreate(false)}
              className="px-3 py-1.5 rounded-lg text-xs font-medium"
              style={{ border: "1px solid var(--color-border)" }}
            >
              Abbrechen
            </button>
            <button
              onClick={handleCreate}
              disabled={creating || !newTitle.trim()}
              className="px-3 py-1.5 rounded-lg text-xs font-medium text-white disabled:opacity-50"
              style={{ background: "var(--color-primary)" }}
            >
              {creating ? "Wird erstellt..." : "Beschluss erstellen"}
            </button>
          </div>
        </div>
      )}

      {/* Offene Beschlüsse */}
      {openDecisions.length === 0 && closedDecisions.length === 0 && !showCreate && (
        <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          Noch keine Beschlüsse vorhanden.
        </p>
      )}

      {openDecisions.map((decision) => {
        const decVotes = votes[decision.id] || [];
        const myVote = getMyVote(decision.id);
        const approvals = decVotes.filter((v) => v.vote === "approve").length;
        const rejections = decVotes.filter((v) => v.vote === "reject").length;
        const total = activeMembers.length;

        return (
          <div
            key={decision.id}
            className="rounded-lg p-4"
            style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}
          >
            <div className="flex items-start justify-between mb-2">
              <div>
                <h4 className="text-sm font-semibold">{decision.title}</h4>
                {decision.description && (
                  <p className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                    {decision.description}
                  </p>
                )}
              </div>
              <span
                className="shrink-0 px-2 py-0.5 rounded-full text-xs font-medium"
                style={{ background: statusLabels[decision.status].bg, color: statusLabels[decision.status].color }}
              >
                {statusLabels[decision.status].label}
              </span>
            </div>

            {/* Meta */}
            <div className="flex items-center gap-3 mb-3 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
              <span className="px-1.5 py-0.5 rounded" style={{ background: "var(--color-muted)" }}>
                {decisionTypeLabels[decision.decision_type]}
              </span>
              <span>von {(decision as any).creator?.name || "Unbekannt"}</span>
              <span>{new Date(decision.created_at).toLocaleDateString("de-DE")}</span>
              <span>{decision.requires_unanimous ? "Einstimmig" : "Mehrheit"}</span>
            </div>

            {/* Fortschritt */}
            <div className="mb-3">
              <div className="flex justify-between text-xs mb-1" style={{ color: "var(--color-muted-foreground)" }}>
                <span>{approvals} von {total} Stimmen</span>
                {rejections > 0 && (
                  <span style={{ color: "var(--color-error)" }}>{rejections} dagegen</span>
                )}
              </div>
              <div className="w-full rounded-full h-2 overflow-hidden" style={{ background: "var(--color-border)" }}>
                <div
                  className="h-full rounded-full transition-all"
                  style={{
                    width: `${total > 0 ? (approvals / total) * 100 : 0}%`,
                    background: "var(--color-success)",
                  }}
                />
              </div>
              {/* Wer hat abgestimmt */}
              <div className="flex flex-wrap gap-1.5 mt-2">
                {activeMembers.map((member) => {
                  const memberVote = decVotes.find((v) => v.voter_id === member.id);
                  return (
                    <span
                      key={member.id}
                      className="px-2 py-0.5 rounded-full text-xs"
                      style={{
                        background: memberVote
                          ? memberVote.vote === "approve"
                            ? "var(--color-success-light)"
                            : memberVote.vote === "reject"
                            ? "var(--color-error-light)"
                            : "var(--color-muted)"
                          : "var(--color-muted)",
                        color: memberVote
                          ? memberVote.vote === "approve"
                            ? "var(--color-success)"
                            : memberVote.vote === "reject"
                            ? "var(--color-error)"
                            : "var(--color-muted-foreground)"
                          : "var(--color-muted-foreground)",
                        opacity: memberVote ? 1 : 0.5,
                      }}
                    >
                      {memberVote?.vote === "approve" ? "✓ " : memberVote?.vote === "reject" ? "✗ " : ""}
                      {member.name}
                    </span>
                  );
                })}
              </div>
            </div>

            {/* Abstimmen */}
            {!myVote && decision.status === "open" && (
              <div>
                {votingId === decision.id ? (
                  <div className="flex items-center gap-2">
                    <input
                      type="text"
                      value={voteComment}
                      onChange={(e) => setVoteComment(e.target.value)}
                      placeholder="Kommentar (optional)"
                      className="flex-1 px-3 py-1.5 rounded-lg text-xs"
                      style={inputStyle}
                    />
                    <button
                      onClick={() => handleVote(decision.id, "approve")}
                      className="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium text-white"
                      style={{ background: "var(--color-success)" }}
                    >
                      <IconCheck size={12} /> Ja
                    </button>
                    <button
                      onClick={() => handleVote(decision.id, "reject")}
                      className="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium text-white"
                      style={{ background: "var(--color-error)" }}
                    >
                      <IconX size={12} /> Nein
                    </button>
                    <button
                      onClick={() => { setVotingId(null); setVoteComment(""); }}
                      className="px-2 py-1.5 rounded-lg text-xs"
                      style={{ color: "var(--color-muted-foreground)" }}
                    >
                      Abbrechen
                    </button>
                  </div>
                ) : (
                  <button
                    onClick={() => setVotingId(decision.id)}
                    className="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                    style={{ background: "var(--color-primary)", color: "#fff" }}
                  >
                    Abstimmen
                  </button>
                )}
              </div>
            )}

            {/* Meine Stimme */}
            {myVote && (
              <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                Deine Stimme: {myVote.vote === "approve" ? "✓ Zugestimmt" : myVote.vote === "reject" ? "✗ Abgelehnt" : "Enthalten"}
                {myVote.comment && <span className="ml-2">— &quot;{myVote.comment}&quot;</span>}
              </div>
            )}
          </div>
        );
      })}

      {/* Abgeschlossene Beschlüsse */}
      {closedDecisions.length > 0 && (
        <div className="space-y-2 pt-2" style={{ borderTop: "1px solid var(--color-border-light)" }}>
          <h4 className="text-xs font-semibold" style={{ color: "var(--color-muted-foreground)" }}>
            Abgeschlossen ({closedDecisions.length})
          </h4>
          {closedDecisions.map((decision) => (
            <div
              key={decision.id}
              className="flex items-center justify-between px-3 py-2 rounded-lg"
              style={{ background: "var(--color-muted)" }}
            >
              <div className="flex items-center gap-2">
                <span
                  className="px-1.5 py-0.5 rounded text-xs"
                  style={{ background: statusLabels[decision.status].bg, color: statusLabels[decision.status].color }}
                >
                  {statusLabels[decision.status].label}
                </span>
                <span className="text-sm">{decision.title}</span>
              </div>
              <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                {decision.resolved_at
                  ? new Date(decision.resolved_at).toLocaleDateString("de-DE")
                  : new Date(decision.created_at).toLocaleDateString("de-DE")}
              </span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
