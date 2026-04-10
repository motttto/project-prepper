"use client";

import { useState, useCallback, useEffect } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import { useCurrentUser, isOrgAdmin } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type { OrgDecision, OrgDecisionVote, DecisionType, VoteChoice } from "@/types/database";
import { IconPlus, IconCheck, IconX, IconChevronRight } from "@/components/ui/icons";

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
  const router = useRouter();
  const { orgId } = useOrg();
  const user = useCurrentUser();

  const [decisions, setDecisions] = useState<OrgDecision[]>([]);
  const [votes, setVotes] = useState<Record<string, OrgDecisionVote[]>>({});
  const [activeMembers, setActiveMembers] = useState<{ id: string; name: string }[]>([]);
  const [allMembers, setAllMembers] = useState<{ id: string; name: string; active: boolean }[]>([]);
  const [projectMembers, setProjectMembers] = useState<Record<string, string[]>>({}); // projectId → profileIds
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);
  const [newTitle, setNewTitle] = useState("");
  const [newDescription, setNewDescription] = useState("");
  const [newType, setNewType] = useState<DecisionType>("general");
  const [newUnanimous, setNewUnanimous] = useState(true);
  const [creating, setCreating] = useState(false);
  const [votingId, setVotingId] = useState<string | null>(null);
  const [voteComment, setVoteComment] = useState("");
  const [voteAsUserId, setVoteAsUserId] = useState<string>("");
  const isAdmin = isOrgAdmin(user);

  const loadDecisions = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("org_decisions")
      .select("*, creator:created_by(name, avatar_url), project:related_project_id(id, name)")
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
    const [activeRes, allRes] = await Promise.all([
      supabase
        .from("org_memberships")
        .select("profile_id, profiles(name)")
        .eq("org_id", orgId)
        .eq("is_active", true),
      supabase
        .from("org_memberships")
        .select("profile_id, is_active, profiles(name)")
        .eq("org_id", orgId),
    ]);
    if (activeRes.data) {
      setActiveMembers(
        activeRes.data.map((m: any) => ({ id: m.profile_id, name: m.profiles?.name || "" }))
      );
    }
    if (allRes.data) {
      setAllMembers(
        allRes.data.map((m: any) => ({ id: m.profile_id, name: m.profiles?.name || "", active: m.is_active }))
      );
    }
  }, [supabase, orgId]);

  // Projekt-Mitglieder laden für projekt-bezogene Beschlüsse
  const loadProjectMembers = useCallback(async () => {
    const projectDecisions = decisions.filter((d) => d.related_project_id);
    if (projectDecisions.length === 0) return;
    const projectIds = [...new Set(projectDecisions.map((d) => d.related_project_id!))];
    const { data } = await supabase
      .from("project_members")
      .select("project_id, profile_id")
      .in("project_id", projectIds);
    if (data) {
      const grouped: Record<string, string[]> = {};
      data.forEach((pm: { project_id: string; profile_id: string }) => {
        if (!grouped[pm.project_id]) grouped[pm.project_id] = [];
        grouped[pm.project_id].push(pm.profile_id);
      });
      setProjectMembers(grouped);
    }
  }, [supabase, decisions]);

  useEffect(() => { loadDecisions(); loadMembers(); }, [loadDecisions, loadMembers]);
  useEffect(() => { loadVotes(); loadProjectMembers(); }, [loadVotes, loadProjectMembers]);

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

  async function handleVote(decisionId: string, vote: VoteChoice, asUserId?: string) {
    if (!user) return;
    const voterId = asUserId || user.id;

    if (asUserId && asUserId !== user.id) {
      // Admin stimmt im Namen eines anderen Users ab (via RPC)
      await supabase.rpc("vote_as_user", {
        p_decision_id: decisionId,
        p_voter_id: voterId,
        p_vote: vote,
        p_comment: voteComment.trim() || null,
      });
    } else {
      await supabase.from("org_decision_votes").upsert({
        decision_id: decisionId,
        voter_id: voterId,
        vote,
        comment: voteComment.trim() || null,
      }, { onConflict: "decision_id,voter_id" });
    }
    setVotingId(null);
    setVoteComment("");
    setVoteAsUserId("");
  }

  // Berechtigte Abstimmer: bei Projekt-Beschlüssen nur Projekt-Mitglieder, sonst alle
  function getEligibleMembers(decision: OrgDecision) {
    if (decision.related_project_id && projectMembers[decision.related_project_id]) {
      const pmIds = new Set(projectMembers[decision.related_project_id]);
      return allMembers.filter((m) => pmIds.has(m.id));
    }
    return activeMembers.map((m) => ({ ...m, active: true }));
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
        const eligible = getEligibleMembers(decision);
        const approvals = decVotes.filter((v) => v.vote === "approve").length;
        const rejections = decVotes.filter((v) => v.vote === "reject").length;
        const total = eligible.filter((m) => m.active).length;

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
            <div className="flex items-center gap-3 mb-3 text-xs flex-wrap" style={{ color: "var(--color-muted-foreground)" }}>
              <span className="px-1.5 py-0.5 rounded" style={{ background: "var(--color-muted)" }}>
                {decisionTypeLabels[decision.decision_type]}
              </span>
              {(decision as any).project?.name && (
                <button
                  onClick={() => router.push(`/projects/${(decision as any).project.id}?tab=profit`)}
                  className="flex items-center gap-1 px-1.5 py-0.5 rounded font-medium transition-colors hover:opacity-80"
                  style={{ background: "#dbeafe", color: "#1d4ed8" }}
                >
                  📁 {(decision as any).project.name}
                  <IconChevronRight size={10} />
                </button>
              )}
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
                {eligible.map((member) => {
                  const memberVote = decVotes.find((v) => v.voter_id === member.id);
                  // Inaktive nur zeigen wenn sie abgestimmt haben
                  if (!member.active && !memberVote) return null;
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

            {/* Abstimmen — nur wenn berechtigt (Projekt-Mitglied oder allgemeiner Beschluss) */}
            {decision.status === "open" && (isAdmin || eligible.some((m) => m.id === user?.id)) && (
              <div>
                {votingId === decision.id ? (
                  <div className="space-y-2">
                    {/* Im Namen von (nur Admin) */}
                    {isAdmin && (
                      <div className="flex items-center gap-2">
                        <label className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Im Namen von:</label>
                        <select
                          value={voteAsUserId}
                          onChange={(e) => setVoteAsUserId(e.target.value)}
                          className="px-2 py-1 rounded-lg text-xs flex-1"
                          style={inputStyle}
                        >
                          <option value="">Ich selbst</option>
                          {eligible.filter((m) => m.id !== user?.id).map((m) => (
                            <option key={m.id} value={m.id}>
                              {m.name}{!m.active ? " (ehem.)" : ""}
                            </option>
                          ))}
                        </select>
                      </div>
                    )}
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
                        onClick={() => handleVote(decision.id, "approve", voteAsUserId || undefined)}
                        className="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium text-white"
                        style={{ background: "var(--color-success)" }}
                      >
                        <IconCheck size={12} /> Ja
                      </button>
                      <button
                        onClick={() => handleVote(decision.id, "reject", voteAsUserId || undefined)}
                        className="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium text-white"
                        style={{ background: "var(--color-error)" }}
                      >
                        <IconX size={12} /> Nein
                      </button>
                      <button
                        onClick={() => { setVotingId(null); setVoteComment(""); setVoteAsUserId(""); }}
                        className="px-2 py-1.5 rounded-lg text-xs"
                        style={{ color: "var(--color-muted-foreground)" }}
                      >
                        Abbrechen
                      </button>
                    </div>
                  </div>
                ) : !myVote ? (
                  <button
                    onClick={() => setVotingId(decision.id)}
                    className="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                    style={{ background: "var(--color-primary)", color: "#fff" }}
                  >
                    Abstimmen
                  </button>
                ) : isAdmin ? (
                  <button
                    onClick={() => setVotingId(decision.id)}
                    className="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                    style={{ border: "1px solid var(--color-border)", color: "var(--color-muted-foreground)" }}
                  >
                    Im Namen von...
                  </button>
                ) : null}
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
          {closedDecisions.map((decision) => {
            const proj = (decision as any).project;
            return (
              <div
                key={decision.id}
                className={`flex items-center justify-between px-3 py-2 rounded-lg ${proj ? "cursor-pointer hover:opacity-80" : ""}`}
                style={{ background: "var(--color-muted)" }}
                onClick={proj ? () => router.push(`/projects/${proj.id}?tab=profit`) : undefined}
              >
                <div className="flex items-center gap-2 flex-wrap">
                  <span
                    className="px-1.5 py-0.5 rounded text-xs"
                    style={{ background: statusLabels[decision.status].bg, color: statusLabels[decision.status].color }}
                  >
                    {statusLabels[decision.status].label}
                  </span>
                  <span className="text-sm">{decision.title}</span>
                  {proj && (
                    <span className="text-xs px-1.5 py-0.5 rounded" style={{ background: "#dbeafe", color: "#1d4ed8" }}>
                      📁 {proj.name}
                    </span>
                  )}
                </div>
                <span className="text-xs shrink-0" style={{ color: "var(--color-muted-foreground)" }}>
                  {decision.resolved_at
                    ? new Date(decision.resolved_at).toLocaleDateString("de-DE")
                    : new Date(decision.created_at).toLocaleDateString("de-DE")}
                </span>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
