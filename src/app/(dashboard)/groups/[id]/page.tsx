"use client";

import { useState, useEffect, useCallback } from "react";
import { useParams, useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useWorkspace } from "@/contexts/org-context";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import {
  IconShield,
  IconUsers,
  IconPlus,
  IconCheck,
  IconX,
  IconClock,
  IconChevronLeft,
  IconMail,
  IconTrash,
} from "@/components/ui/icons";
import { showToast } from "@/hooks/use-toast";

interface Group {
  id: string;
  name: string;
  description: string | null;
  founded_by: string;
  founded_at: string;
}

interface Member {
  id: string;
  profile_id: string;
  is_active: boolean;
  is_founder: boolean;
  joined_at: string | null;
  profile?: { name: string; email: string; avatar_url: string | null };
}

interface Invitation {
  id: string;
  invited_email: string | null;
  invited_profile_id: string | null;
  invited_by: string;
  invited_message: string | null;
  status: string;
  accepted_by_user_at: string | null;
  voting_started_at: string | null;
  resolved_at: string | null;
  expires_at: string;
  created_at: string;
  invitee?: { name: string; email: string };
  inviter?: { name: string };
}

interface InvitationVote {
  id: string;
  invitation_id: string;
  voter_id: string;
  vote: string;
  comment: string | null;
  voted_at: string;
  voter?: { name: string };
}

interface GroupDecision {
  id: string;
  title: string;
  description: string | null;
  decision_type: string;
  status: string;
  requires_unanimous: boolean;
  related_project_id: string | null;
  created_by: string;
  created_at: string;
  resolved_at: string | null;
  metadata: Record<string, unknown> | null;
  creator?: { name: string };
}

interface DecisionVote {
  id: string;
  decision_id: string;
  voter_id: string;
  vote: string;
  comment: string | null;
  voter?: { name: string };
}

export default function GroupDetailPage() {
  const supabase = createClient();
  const router = useRouter();
  const params = useParams();
  const groupId = params.id as string;
  const currentUser = useCurrentUser();
  const { reload, switchWorkspace } = useWorkspace();

  const [group, setGroup] = useState<Group | null>(null);
  const [members, setMembers] = useState<Member[]>([]);
  const [invitations, setInvitations] = useState<Invitation[]>([]);
  const [votes, setVotes] = useState<InvitationVote[]>([]);
  const [decisions, setDecisions] = useState<GroupDecision[]>([]);
  const [decisionVotes, setDecisionVotes] = useState<DecisionVote[]>([]);
  const [loading, setLoading] = useState(true);

  const [showInvite, setShowInvite] = useState(false);
  const [inviteEmail, setInviteEmail] = useState("");
  const [inviteMessage, setInviteMessage] = useState("");
  const [inviting, setInviting] = useState(false);
  const [inviteError, setInviteError] = useState("");

  const loadAll = useCallback(async () => {
    if (!groupId) return;
    const [grpRes, memRes, invRes, voteRes] = await Promise.all([
      supabase.from("groups").select("*").eq("id", groupId).maybeSingle(),
      supabase
        .from("group_memberships")
        .select("*, profile:profiles(name, email, avatar_url)")
        .eq("group_id", groupId),
      supabase
        .from("group_invitations")
        .select("*, invitee:profiles!invited_profile_id(name, email), inviter:profiles!invited_by(name)")
        .eq("group_id", groupId)
        .order("created_at", { ascending: false }),
      // Votes fuer alle Invitations dieser Gruppe
      supabase
        .from("group_invitation_votes")
        .select("*, voter:profiles(name)")
        .in(
          "invitation_id",
          (
            await supabase
              .from("group_invitations")
              .select("id")
              .eq("group_id", groupId)
          ).data?.map((i) => i.id) || []
        ),
    ]);
    if (grpRes.data) setGroup(grpRes.data as Group);
    if (memRes.data) setMembers(memRes.data as Member[]);
    if (invRes.data) setInvitations(invRes.data as Invitation[]);
    if (voteRes.data) setVotes(voteRes.data as InvitationVote[]);

    // Group-Decisions laden
    const { data: decisionData } = await supabase
      .from("org_decisions")
      .select("*, creator:profiles!created_by(name)")
      .eq("group_id", groupId)
      .order("created_at", { ascending: false });
    if (decisionData) {
      setDecisions(decisionData as GroupDecision[]);
      const decIds = decisionData.map((d) => d.id);
      if (decIds.length > 0) {
        const { data: dvotes } = await supabase
          .from("org_decision_votes")
          .select("*, voter:profiles(name)")
          .in("decision_id", decIds);
        if (dvotes) setDecisionVotes(dvotes as DecisionVote[]);
      }
    }

    setLoading(false);
  }, [supabase, groupId]);

  useEffect(() => {
    loadAll();
  }, [loadAll]);

  useRealtimeTable({ table: "group_memberships", onDataChange: loadAll });
  useRealtimeTable({ table: "group_invitations", onDataChange: loadAll });
  useRealtimeTable({ table: "group_invitation_votes", onDataChange: loadAll });
  useRealtimeTable({ table: "org_decisions", onDataChange: loadAll });
  useRealtimeTable({ table: "org_decision_votes", onDataChange: loadAll });

  async function handleDecisionVote(decisionId: string, vote: "approve" | "reject", comment?: string) {
    if (!currentUser) return;
    await supabase
      .from("org_decision_votes")
      .upsert(
        {
          decision_id: decisionId,
          voter_id: currentUser.id,
          vote,
          comment: comment || null,
        },
        { onConflict: "decision_id,voter_id" }
      );

    // Pruefen ob alle Members zugestimmt haben (Trigger fehlt -> manuell)
    const { data: allVotes } = await supabase
      .from("org_decision_votes")
      .select("vote")
      .eq("decision_id", decisionId);
    const approvals = (allVotes || []).filter((v) => v.vote === "approve").length;
    const rejections = (allVotes || []).filter((v) => v.vote === "reject").length;
    const totalActive = members.filter((m) => m.is_active).length;

    if (rejections > 0) {
      await supabase.from("org_decisions").update({ status: "rejected", resolved_at: new Date().toISOString() }).eq("id", decisionId);
    } else if (approvals >= totalActive) {
      await supabase.from("org_decisions").update({ status: "approved", resolved_at: new Date().toISOString() }).eq("id", decisionId);
    }

    showToast(vote === "approve" ? "Zugestimmt" : "Abgelehnt", "success");
    loadAll();
  }

  const activeMembers = members.filter((m) => m.is_active);
  const isMember = !!currentUser && activeMembers.some((m) => m.profile_id === currentUser.id);
  const isFounder =
    !!currentUser && activeMembers.some((m) => m.profile_id === currentUser.id && m.is_founder);

  async function handleInvite(e: React.FormEvent) {
    e.preventDefault();
    if (!inviteEmail.trim() || !currentUser || !groupId) return;
    setInviting(true);
    setInviteError("");

    const email = inviteEmail.trim().toLowerCase();

    // Pruefen ob User mit Email existiert
    const { data: existing } = await supabase
      .from("profiles")
      .select("id, name")
      .eq("email", email)
      .maybeSingle();

    const { data: insertData, error } = await supabase
      .from("group_invitations")
      .insert({
        group_id: groupId,
        invited_by: currentUser.id,
        invited_email: email,
        invited_profile_id: existing?.id ?? null,
        invited_message: inviteMessage.trim() || null,
        status: "pending",
      })
      .select("id")
      .single();

    if (error) {
      setInviteError(error.message);
      setInviting(false);
      return;
    }

    setInviteEmail("");
    setInviteMessage("");
    setShowInvite(false);

    // Email-Versand (fire & forget) — funktioniert nur wenn Inviter SMTP konfiguriert hat
    let emailSent = false;
    if (insertData?.id) {
      try {
        const { data: fnData } = await supabase.functions.invoke("send-group-invite", {
          body: { invitation_id: insertData.id },
        });
        emailSent = fnData?.method === "email";
      } catch (e) {
        console.error("Email send error:", e);
      }
    }

    showToast(
      emailSent
        ? `Einladung an ${email} per Email verschickt`
        : existing
          ? `Einladung fuer ${existing.name} angelegt — User sieht sie im Dashboard`
          : `Einladung angelegt. Email konnte nicht gesendet werden (SMTP-Config in Admin?). User muss sich registrieren um Einladung zu sehen.`,
      emailSent ? "success" : "info"
    );
    loadAll();
    setInviting(false);
  }

  async function handleVote(invitationId: string, vote: "approve" | "reject", comment?: string) {
    if (!currentUser) return;
    await supabase
      .from("group_invitation_votes")
      .upsert(
        {
          invitation_id: invitationId,
          voter_id: currentUser.id,
          vote,
          comment: comment || null,
        },
        { onConflict: "invitation_id,voter_id" }
      );
    showToast(vote === "approve" ? "Zustimmung gespeichert" : "Ablehnung gespeichert", "success");
  }

  async function handleCancelInvitation(id: string) {
    await supabase.from("group_invitations").delete().eq("id", id);
    showToast("Einladung zurueckgezogen", "info");
  }

  async function handleLeaveGroup() {
    if (!currentUser || !groupId) return;
    if (!confirm("Wirklich aus der Gruppe austreten?")) return;
    await supabase
      .from("group_memberships")
      .delete()
      .eq("group_id", groupId)
      .eq("profile_id", currentUser.id);
    await reload();
    switchWorkspace(null);
    router.push("/dashboard");
  }

  if (loading) {
    return <div className="py-12 text-center text-sm" style={{ color: "var(--color-muted-foreground)" }}>Lade...</div>;
  }

  if (!group) {
    return (
      <div className="text-center py-12">
        <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          Gruppe nicht gefunden oder kein Zugriff.
        </p>
      </div>
    );
  }

  if (!isMember) {
    return (
      <div className="text-center py-12">
        <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          Du bist (noch) kein Mitglied dieser Gruppe.
        </p>
      </div>
    );
  }

  return (
    <div className="max-w-3xl">
      <button
        onClick={() => router.push("/dashboard")}
        className="inline-flex items-center gap-1 text-sm mb-4"
        style={{ color: "var(--color-muted-foreground)" }}
      >
        <IconChevronLeft size={14} /> Zurueck
      </button>

      {/* Header */}
      <div
        className="rounded-xl p-6 mb-5"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
      >
        <div className="flex items-start gap-4">
          <div
            className="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0"
            style={{ background: "var(--color-success-light)" }}
          >
            <IconShield size={22} style={{ color: "var(--color-success)" }} />
          </div>
          <div className="flex-1 min-w-0">
            <h1 className="text-xl font-bold" style={{ color: "var(--color-foreground)" }}>
              {group.name}
            </h1>
            {group.description && (
              <p className="text-sm mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                {group.description}
              </p>
            )}
            <p className="text-xs mt-2" style={{ color: "var(--color-muted-foreground)" }}>
              {activeMembers.length} aktive Mitglied{activeMembers.length !== 1 ? "er" : ""} ·
              gegruendet am {new Date(group.founded_at).toLocaleDateString("de-DE")}
            </p>
          </div>
          {!isFounder && (
            <button
              onClick={handleLeaveGroup}
              className="text-xs px-3 py-1.5 rounded-lg"
              style={{ color: "var(--color-destructive)", border: "1px solid var(--color-destructive)" }}
            >
              Verlassen
            </button>
          )}
        </div>
      </div>

      {/* Mitglieder */}
      <div
        className="rounded-xl p-6 mb-5"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
      >
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-2">
            <IconUsers size={18} style={{ color: "var(--color-primary)" }} />
            <h2 className="text-base font-semibold" style={{ color: "var(--color-foreground)" }}>
              Mitglieder ({activeMembers.length})
            </h2>
          </div>
          <button
            onClick={() => setShowInvite(!showInvite)}
            className="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium text-white"
            style={{ background: "var(--color-primary)" }}
          >
            <IconPlus size={12} /> Einladen
          </button>
        </div>

        {/* Einladungs-Formular */}
        {showInvite && (
          <form onSubmit={handleInvite} className="mb-4 p-4 rounded-lg" style={{ background: "var(--color-muted)" }}>
            <div className="text-xs font-medium mb-3" style={{ color: "var(--color-foreground)" }}>
              Mitglied einladen — alle bestehenden Mitglieder muessen einstimmig zustimmen.
            </div>
            <input
              type="email"
              value={inviteEmail}
              onChange={(e) => setInviteEmail(e.target.value)}
              placeholder="email@example.com"
              required
              className="w-full px-3 py-2 rounded-lg text-sm mb-2"
              style={{
                background: "var(--color-background)",
                border: "1px solid var(--color-border)",
                color: "var(--color-foreground)",
              }}
            />
            <textarea
              value={inviteMessage}
              onChange={(e) => setInviteMessage(e.target.value)}
              placeholder="Optionale Nachricht..."
              rows={2}
              className="w-full px-3 py-2 rounded-lg text-sm mb-2 resize-none"
              style={{
                background: "var(--color-background)",
                border: "1px solid var(--color-border)",
                color: "var(--color-foreground)",
              }}
            />
            {inviteError && (
              <div className="text-xs mb-2" style={{ color: "var(--color-destructive)" }}>
                {inviteError}
              </div>
            )}
            <div className="flex gap-2">
              <button
                type="submit"
                disabled={inviting || !inviteEmail.trim()}
                className="px-4 py-2 rounded-lg text-xs font-medium text-white disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}
              >
                <IconMail size={12} className="inline mr-1" />
                {inviting ? "Sende..." : "Einladung senden"}
              </button>
              <button
                type="button"
                onClick={() => setShowInvite(false)}
                className="px-3 py-2 rounded-lg text-xs"
                style={{ color: "var(--color-muted-foreground)" }}
              >
                Abbrechen
              </button>
            </div>
          </form>
        )}

        {/* Mitglieder-Liste */}
        <div className="space-y-2">
          {activeMembers.map((m) => (
            <div
              key={m.id}
              className="flex items-center justify-between p-3 rounded-lg"
              style={{ background: "var(--color-muted)" }}
            >
              <div className="flex items-center gap-3">
                <div
                  className="w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium overflow-hidden"
                  style={{ background: "var(--color-primary-light)", color: "var(--color-primary)" }}
                >
                  {m.profile?.avatar_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={m.profile.avatar_url} alt="" className="w-full h-full object-cover" />
                  ) : (
                    m.profile?.name?.charAt(0).toUpperCase() || "?"
                  )}
                </div>
                <div>
                  <div className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>
                    {m.profile?.name}
                    {m.is_founder && (
                      <span
                        className="ml-2 text-[10px] px-1.5 py-0.5 rounded font-bold"
                        style={{ background: "var(--color-primary)", color: "white" }}
                      >
                        FOUNDER
                      </span>
                    )}
                    {m.profile_id === currentUser?.id && (
                      <span className="ml-2 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                        (du)
                      </span>
                    )}
                  </div>
                  <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    {m.profile?.email}
                  </div>
                </div>
              </div>
              <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                Seit{" "}
                {m.joined_at
                  ? new Date(m.joined_at).toLocaleDateString("de-DE", { month: "short", year: "numeric" })
                  : "?"}
              </span>
            </div>
          ))}
        </div>
      </div>

      {/* Einladungen */}
      {invitations.length > 0 && (
        <div
          className="rounded-xl p-6 mb-5"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
        >
          <h2 className="text-base font-semibold mb-4" style={{ color: "var(--color-foreground)" }}>
            Einladungen ({invitations.filter((i) => ["pending", "accepted_by_user", "voting_in_progress"].includes(i.status)).length} offen)
          </h2>
          <div className="space-y-3">
            {invitations.map((inv) => (
              <InvitationCard
                key={inv.id}
                invitation={inv}
                votes={votes.filter((v) => v.invitation_id === inv.id)}
                activeMembers={activeMembers}
                currentUserId={currentUser?.id || ""}
                onVote={handleVote}
                onCancel={handleCancelInvitation}
              />
            ))}
          </div>
        </div>
      )}

      {/* Beschluesse */}
      {decisions.length > 0 && (
        <div
          className="rounded-xl p-6"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
        >
          <div className="flex items-center gap-2 mb-4">
            <IconShield size={18} style={{ color: "var(--color-warning)" }} />
            <h2 className="text-base font-semibold" style={{ color: "var(--color-foreground)" }}>
              Beschluesse ({decisions.filter((d) => d.status === "open").length} offen)
            </h2>
          </div>
          <div className="space-y-3">
            {decisions.map((d) => (
              <DecisionCard
                key={d.id}
                decision={d}
                votes={decisionVotes.filter((v) => v.decision_id === d.id)}
                activeMembers={activeMembers}
                currentUserId={currentUser?.id || ""}
                onVote={handleDecisionVote}
              />
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

// ─── Decision-Karte mit Voting ──────────────────────────────────────────────

function DecisionCard({
  decision,
  votes,
  activeMembers,
  currentUserId,
  onVote,
}: {
  decision: GroupDecision;
  votes: DecisionVote[];
  activeMembers: Member[];
  currentUserId: string;
  onVote: (id: string, vote: "approve" | "reject", comment?: string) => void;
}) {
  const [comment, setComment] = useState("");
  const [showRejectInput, setShowRejectInput] = useState(false);

  const myVote = votes.find((v) => v.voter_id === currentUserId);
  const approvalCount = votes.filter((v) => v.vote === "approve").length;
  const rejectionCount = votes.filter((v) => v.vote === "reject").length;
  const eligibleVoters = activeMembers.length;
  const canVote = decision.status === "open" && !myVote;

  const statusColors: Record<string, { bg: string; color: string; label: string }> = {
    open: { bg: "var(--color-warning-light)", color: "var(--color-warning)", label: "Abstimmung laeuft" },
    approved: { bg: "var(--color-success-light)", color: "var(--color-success)", label: "Beschlossen" },
    rejected: { bg: "var(--color-destructive-light)", color: "var(--color-destructive)", label: "Abgelehnt" },
    expired: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)", label: "Abgelaufen" },
  };
  const statusInfo = statusColors[decision.status] || statusColors.open;

  const decisionTypeLabels: Record<string, string> = {
    profit_distribution: "Gewinnverteilung",
    asset_purchase: "Anschaffung",
    asset_disposal: "Veraeusserung",
    exit_settlement: "Austritt",
    policy: "Richtlinie",
    general: "Allgemein",
  };

  return (
    <div className="p-4 rounded-lg" style={{ background: "var(--color-muted)" }}>
      <div className="flex items-start justify-between mb-2">
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <span
              className="text-xs px-2 py-0.5 rounded-full font-medium"
              style={{ background: "var(--color-info-light)", color: "var(--color-info)" }}
            >
              {decisionTypeLabels[decision.decision_type] || decision.decision_type}
            </span>
            <h4 className="text-sm font-semibold" style={{ color: "var(--color-foreground)" }}>
              {decision.title}
            </h4>
          </div>
          <div className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
            von {decision.creator?.name || "?"} · {new Date(decision.created_at).toLocaleDateString("de-DE")}
            {decision.requires_unanimous && <span> · Einstimmigkeit erforderlich</span>}
          </div>
        </div>
        <span
          className="text-xs px-2 py-0.5 rounded-full font-medium"
          style={{ background: statusInfo.bg, color: statusInfo.color }}
        >
          {statusInfo.label}
        </span>
      </div>

      {decision.description && (
        <pre className="text-xs whitespace-pre-wrap mt-2 mb-2 font-sans" style={{ color: "var(--color-muted-foreground)" }}>
          {decision.description}
        </pre>
      )}

      {decision.status === "open" && (
        <div className="my-3">
          <div className="text-xs mb-1.5" style={{ color: "var(--color-muted-foreground)" }}>
            {approvalCount} von {eligibleVoters} haben zugestimmt
            {rejectionCount > 0 && (
              <span style={{ color: "var(--color-destructive)" }}> · {rejectionCount} abgelehnt</span>
            )}
          </div>
          <div className="w-full h-1.5 rounded-full" style={{ background: "var(--color-border)" }}>
            <div
              className="h-full rounded-full transition-all"
              style={{
                width: `${eligibleVoters > 0 ? (approvalCount / eligibleVoters) * 100 : 0}%`,
                background: "var(--color-success)",
              }}
            />
          </div>
        </div>
      )}

      {canVote && (
        <div className="mt-3 space-y-2">
          {showRejectInput && (
            <input
              type="text"
              value={comment}
              onChange={(e) => setComment(e.target.value)}
              placeholder="Begruendung (empfohlen)..."
              className="w-full px-3 py-1.5 rounded text-xs"
              style={{
                background: "var(--color-background)",
                border: "1px solid var(--color-border)",
                color: "var(--color-foreground)",
              }}
            />
          )}
          <div className="flex gap-2">
            <button
              onClick={() => onVote(decision.id, "approve")}
              className="flex items-center gap-1 px-3 py-1.5 rounded text-xs font-medium text-white"
              style={{ background: "var(--color-success)" }}
            >
              <IconCheck size={12} /> Zustimmen
            </button>
            {!showRejectInput ? (
              <button
                onClick={() => setShowRejectInput(true)}
                className="flex items-center gap-1 px-3 py-1.5 rounded text-xs font-medium"
                style={{ border: "1px solid var(--color-destructive)", color: "var(--color-destructive)" }}
              >
                <IconX size={12} /> Ablehnen
              </button>
            ) : (
              <button
                onClick={() => onVote(decision.id, "reject", comment)}
                className="flex items-center gap-1 px-3 py-1.5 rounded text-xs font-medium text-white"
                style={{ background: "var(--color-destructive)" }}
              >
                <IconX size={12} /> Ablehnen bestaetigen
              </button>
            )}
          </div>
        </div>
      )}

      {myVote && (
        <div className="mt-3 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
          Deine Stimme: {myVote.vote === "approve" ? "✓ Zugestimmt" : "✗ Abgelehnt"}
          {myVote.comment && <span> — &quot;{myVote.comment}&quot;</span>}
        </div>
      )}
    </div>
  );
}

// ─── Einzelne Einladungs-Karte mit Voting ────────────────────────────────────

function InvitationCard({
  invitation,
  votes,
  activeMembers,
  currentUserId,
  onVote,
  onCancel,
}: {
  invitation: Invitation;
  votes: InvitationVote[];
  activeMembers: Member[];
  currentUserId: string;
  onVote: (id: string, vote: "approve" | "reject", comment?: string) => void;
  onCancel: (id: string) => void;
}) {
  const [comment, setComment] = useState("");
  const [showRejectInput, setShowRejectInput] = useState(false);

  const myVote = votes.find((v) => v.voter_id === currentUserId);
  // Voting laeuft wenn jemand bereits akzeptiert hat
  const isVoting = ["accepted_by_user", "voting_in_progress"].includes(invitation.status);
  // Andere Mitglieder die abstimmen muessen (ohne den Eingeladenen selbst)
  const eligibleVoters = activeMembers.filter(
    (m) => m.profile_id !== invitation.invited_profile_id
  );
  const approvalCount = votes.filter((v) => v.vote === "approve").length;
  const rejectionCount = votes.filter((v) => v.vote === "reject").length;
  const canVote =
    isVoting && eligibleVoters.some((m) => m.profile_id === currentUserId) && !myVote;

  const statusColors: Record<string, { bg: string; color: string; label: string }> = {
    pending: { bg: "var(--color-warning-light)", color: "var(--color-warning)", label: "Wartet auf Antwort" },
    accepted_by_user: { bg: "var(--color-info-light)", color: "var(--color-info)", label: "Akzeptiert · Voting laeuft" },
    voting_in_progress: { bg: "var(--color-info-light)", color: "var(--color-info)", label: "Voting laeuft" },
    approved: { bg: "var(--color-success-light)", color: "var(--color-success)", label: "Aufgenommen" },
    declined_by_user: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)", label: "Vom User abgelehnt" },
    rejected_by_member: { bg: "var(--color-destructive-light)", color: "var(--color-destructive)", label: "Von Mitglied abgelehnt" },
    cancelled: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)", label: "Zurueckgezogen" },
    expired: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)", label: "Abgelaufen" },
  };
  const statusInfo = statusColors[invitation.status] || statusColors.pending;

  return (
    <div className="p-4 rounded-lg" style={{ background: "var(--color-muted)" }}>
      <div className="flex items-start justify-between mb-2">
        <div>
          <div className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>
            {invitation.invitee?.name || invitation.invited_email}
          </div>
          <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            Eingeladen von {invitation.inviter?.name || "?"} ·{" "}
            {new Date(invitation.created_at).toLocaleDateString("de-DE")}
          </div>
        </div>
        <span
          className="text-xs px-2 py-0.5 rounded-full font-medium"
          style={{ background: statusInfo.bg, color: statusInfo.color }}
        >
          {statusInfo.label}
        </span>
      </div>

      {invitation.invited_message && (
        <p className="text-xs italic mb-3" style={{ color: "var(--color-muted-foreground)" }}>
          &quot;{invitation.invited_message}&quot;
        </p>
      )}

      {/* Voting-Status */}
      {isVoting && (
        <div className="mb-3">
          <div
            className="text-xs mb-1.5"
            style={{ color: "var(--color-muted-foreground)" }}
          >
            {approvalCount} von {eligibleVoters.length} haben zugestimmt
            {rejectionCount > 0 && (
              <span style={{ color: "var(--color-destructive)" }}>
                {" "}· {rejectionCount} abgelehnt
              </span>
            )}
          </div>
          <div className="w-full h-1.5 rounded-full" style={{ background: "var(--color-border)" }}>
            <div
              className="h-full rounded-full transition-all"
              style={{
                width: `${eligibleVoters.length > 0 ? (approvalCount / eligibleVoters.length) * 100 : 0}%`,
                background: "var(--color-success)",
              }}
            />
          </div>
        </div>
      )}

      {/* Voting-UI */}
      {canVote && (
        <div className="mt-3 space-y-2">
          {showRejectInput && (
            <input
              type="text"
              value={comment}
              onChange={(e) => setComment(e.target.value)}
              placeholder="Begruendung (empfohlen)..."
              className="w-full px-3 py-1.5 rounded text-xs"
              style={{
                background: "var(--color-background)",
                border: "1px solid var(--color-border)",
                color: "var(--color-foreground)",
              }}
            />
          )}
          <div className="flex gap-2">
            <button
              onClick={() => onVote(invitation.id, "approve")}
              className="flex items-center gap-1 px-3 py-1.5 rounded text-xs font-medium text-white"
              style={{ background: "var(--color-success)" }}
            >
              <IconCheck size={12} /> Zustimmen
            </button>
            {!showRejectInput ? (
              <button
                onClick={() => setShowRejectInput(true)}
                className="flex items-center gap-1 px-3 py-1.5 rounded text-xs font-medium"
                style={{ border: "1px solid var(--color-destructive)", color: "var(--color-destructive)" }}
              >
                <IconX size={12} /> Ablehnen
              </button>
            ) : (
              <button
                onClick={() => onVote(invitation.id, "reject", comment)}
                className="flex items-center gap-1 px-3 py-1.5 rounded text-xs font-medium text-white"
                style={{ background: "var(--color-destructive)" }}
              >
                <IconX size={12} /> Ablehnen bestaetigen
              </button>
            )}
          </div>
        </div>
      )}

      {/* Eigene Stimme (wenn schon abgegeben) */}
      {myVote && (
        <div className="mt-3 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
          Deine Stimme: {myVote.vote === "approve" ? "✓ Zugestimmt" : "✗ Abgelehnt"}
          {myVote.comment && <span> — &quot;{myVote.comment}&quot;</span>}
        </div>
      )}

      {/* Status-Hinweis Pending */}
      {invitation.status === "pending" && (
        <div className="mt-2 text-xs flex items-center gap-1" style={{ color: "var(--color-warning)" }}>
          <IconClock size={12} /> Eingeladener User hat noch nicht akzeptiert
        </div>
      )}

      {/* Cancel-Button (nur Inviter) */}
      {invitation.invited_by === currentUserId &&
        ["pending", "accepted_by_user", "voting_in_progress"].includes(invitation.status) && (
          <div className="mt-3">
            <button
              onClick={() => onCancel(invitation.id)}
              className="text-xs flex items-center gap-1"
              style={{ color: "var(--color-destructive)" }}
            >
              <IconTrash size={12} /> Einladung zurueckziehen
            </button>
          </div>
        )}
    </div>
  );
}
