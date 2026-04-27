"use client";

import { useEffect, useState, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { showToast } from "@/hooks/use-toast";

type Feedback = {
  id: string;
  profile_id: string;
  feedback_type: "bug" | "idea" | "other";
  message: string;
  app_route: string | null;
  status: "new" | "in_review" | "resolved" | "wontfix";
  response: string | null;
  resolved_at: string | null;
  created_at: string;
  profile?: { name: string; email: string };
};

const typeLabels: Record<string, string> = {
  bug: "Bug",
  idea: "Idee",
  other: "Sonstiges",
};

const statusLabels: Record<string, string> = {
  new: "Neu",
  in_review: "In Prüfung",
  resolved: "Erledigt",
  wontfix: "Verworfen",
};

const statusColors: Record<string, { bg: string; fg: string }> = {
  new: { bg: "#dbeafe", fg: "#1e40af" },
  in_review: { bg: "#fef3c7", fg: "#92400e" },
  resolved: { bg: "#dcfce7", fg: "#166534" },
  wontfix: { bg: "#f3f4f6", fg: "#4b5563" },
};

export function FeedbackTab() {
  const supabase = createClient();
  const [items, setItems] = useState<Feedback[]>([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<"all" | "new" | "in_review" | "resolved">("all");
  const [editing, setEditing] = useState<Feedback | null>(null);
  const [response, setResponse] = useState("");
  const [newStatus, setNewStatus] = useState<Feedback["status"]>("new");
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    const { data } = await supabase
      .from("app_feedback")
      .select("*, profile:profiles!profile_id(name, email)")
      .order("created_at", { ascending: false });
    setItems((data as Feedback[]) || []);
    setLoading(false);
  }, [supabase]);

  useEffect(() => {
    load();
  }, [load]);

  function open(item: Feedback) {
    setEditing(item);
    setResponse(item.response || "");
    setNewStatus(item.status);
  }

  async function save() {
    if (!editing) return;
    setSaving(true);
    const { data: { user } } = await supabase.auth.getUser();
    const update: Record<string, unknown> = {
      response: response || null,
      status: newStatus,
    };
    if (newStatus === "resolved" || newStatus === "wontfix") {
      update.resolved_at = new Date().toISOString();
      update.resolved_by = user?.id;
    } else {
      update.resolved_at = null;
      update.resolved_by = null;
    }
    const { error } = await supabase
      .from("app_feedback")
      .update(update)
      .eq("id", editing.id);
    setSaving(false);
    if (error) {
      showToast("Fehler: " + error.message, "error");
      return;
    }
    showToast("Gespeichert", "success");
    setEditing(null);
    load();
  }

  const filtered = filter === "all" ? items : items.filter((i) => i.status === filter);

  if (loading) {
    return <div className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>Lade Feedback...</div>;
  }

  return (
    <div className="space-y-3">
      {/* Filter */}
      <div className="flex gap-1 rounded-lg p-1 inline-flex" style={{ background: "var(--color-muted)" }}>
        {(["all", "new", "in_review", "resolved"] as const).map((f) => (
          <button
            key={f}
            onClick={() => setFilter(f)}
            className="px-3 py-1.5 rounded-md text-xs font-medium"
            style={{
              background: filter === f ? "var(--color-primary)" : "transparent",
              color: filter === f ? "var(--color-primary-foreground)" : "var(--color-muted-foreground)",
            }}
          >
            {f === "all" ? "Alle" : statusLabels[f]} ({f === "all" ? items.length : items.filter((i) => i.status === f).length})
          </button>
        ))}
      </div>

      {filtered.length === 0 ? (
        <div className="rounded-xl p-8 text-center" style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)", color: "var(--color-muted-foreground)" }}>
          Keine Einträge.
        </div>
      ) : (
        filtered.map((item) => {
          const sColor = statusColors[item.status];
          return (
            <div
              key={item.id}
              onClick={() => open(item)}
              className="rounded-xl p-4 cursor-pointer transition-colors"
              style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
              onMouseEnter={(e) => (e.currentTarget.style.background = "var(--color-muted)")}
              onMouseLeave={(e) => (e.currentTarget.style.background = "var(--color-surface)")}
            >
              <div className="flex items-center gap-2 mb-2 flex-wrap">
                <span className="text-xs font-mono px-2 py-0.5 rounded" style={{ background: "var(--color-muted)" }}>
                  {typeLabels[item.feedback_type]}
                </span>
                <span className="text-xs font-medium px-2 py-0.5 rounded" style={{ background: sColor.bg, color: sColor.fg }}>
                  {statusLabels[item.status]}
                </span>
                {item.app_route && (
                  <span className="text-xs font-mono" style={{ color: "var(--color-muted-foreground)" }}>
                    {item.app_route}
                  </span>
                )}
                <span className="text-xs ml-auto" style={{ color: "var(--color-muted-foreground)" }}>
                  {new Date(item.created_at).toLocaleString("de-DE")}
                </span>
              </div>
              <p className="text-sm whitespace-pre-wrap line-clamp-3">{item.message}</p>
              <p className="text-xs mt-2" style={{ color: "var(--color-muted-foreground)" }}>
                {item.profile?.name || "?"} ({item.profile?.email || "?"})
              </p>
            </div>
          );
        })
      )}

      {/* Detail Modal */}
      {editing && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center p-4"
          style={{ background: "rgba(0,0,0,0.5)" }}
          onClick={() => setEditing(null)}
        >
          <div
            className="w-full max-w-2xl max-h-[90vh] overflow-auto rounded-xl p-6"
            style={{ background: "var(--color-surface)" }}
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center gap-2 mb-3 flex-wrap">
              <span className="text-xs font-mono px-2 py-0.5 rounded" style={{ background: "var(--color-muted)" }}>
                {typeLabels[editing.feedback_type]}
              </span>
              {editing.app_route && (
                <span className="text-xs font-mono" style={{ color: "var(--color-muted-foreground)" }}>
                  {editing.app_route}
                </span>
              )}
            </div>
            <p className="text-xs mb-4" style={{ color: "var(--color-muted-foreground)" }}>
              {editing.profile?.name} · {editing.profile?.email} ·{" "}
              {new Date(editing.created_at).toLocaleString("de-DE")}
            </p>
            <div className="rounded-lg p-3 mb-4 whitespace-pre-wrap text-sm" style={{ background: "var(--color-muted)" }}>
              {editing.message}
            </div>

            <div className="space-y-3">
              <div>
                <label className="block text-xs font-medium mb-1">Status</label>
                <select
                  value={newStatus}
                  onChange={(e) => setNewStatus(e.target.value as Feedback["status"])}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
                >
                  <option value="new">Neu</option>
                  <option value="in_review">In Prüfung</option>
                  <option value="resolved">Erledigt</option>
                  <option value="wontfix">Verworfen</option>
                </select>
              </div>
              <div>
                <label className="block text-xs font-medium mb-1">Antwort / interne Notiz</label>
                <textarea
                  value={response}
                  onChange={(e) => setResponse(e.target.value)}
                  rows={4}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
                />
              </div>
            </div>

            <div className="flex gap-2 mt-5 justify-end">
              <button
                onClick={() => setEditing(null)}
                className="px-4 py-2 rounded-lg text-sm font-medium"
                style={{ border: "1px solid var(--color-border)" }}
              >
                Schließen
              </button>
              <button
                onClick={save}
                disabled={saving}
                className="px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}
              >
                {saving ? "Speichere..." : "Speichern"}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
