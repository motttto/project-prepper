"use client";

import { useState } from "react";
import { createClient } from "@/lib/supabase";
import { IconX, IconPlus, IconTrash } from "@/components/ui/icons";
import type { PollType } from "@/types/database";

interface PollCreateModalProps {
  orgId: string;
  projectId?: string;
  onClose: () => void;
  onCreated: () => void;
}

export function PollCreateModal({ orgId, projectId, onClose, onCreated }: PollCreateModalProps) {
  const supabase = createClient();
  const [pollType, setPollType] = useState<PollType>("choice");
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [allowsMultiple, setAllowsMultiple] = useState(true);
  const [isAnonymous, setIsAnonymous] = useState(false);
  const [deadline, setDeadline] = useState("");
  const [saving, setSaving] = useState(false);

  // Choice options
  const [choiceOptions, setChoiceOptions] = useState<string[]>(["", ""]);

  // Date options
  const [dateOptions, setDateOptions] = useState<{ date: string; time: string }[]>([
    { date: "", time: "" },
  ]);

  const addChoiceOption = () => setChoiceOptions([...choiceOptions, ""]);
  const removeChoiceOption = (i: number) => {
    if (choiceOptions.length <= 2) return;
    setChoiceOptions(choiceOptions.filter((_, idx) => idx !== i));
  };
  const updateChoiceOption = (i: number, val: string) => {
    const next = [...choiceOptions];
    next[i] = val;
    setChoiceOptions(next);
  };

  const addDateOption = () => setDateOptions([...dateOptions, { date: "", time: "" }]);
  const removeDateOption = (i: number) => {
    if (dateOptions.length <= 1) return;
    setDateOptions(dateOptions.filter((_, idx) => idx !== i));
  };
  const updateDateOption = (i: number, field: "date" | "time", val: string) => {
    const next = [...dateOptions];
    next[i] = { ...next[i], [field]: val };
    setDateOptions(next);
  };

  const canSave = () => {
    if (!title.trim()) return false;
    if (pollType === "choice") {
      return choiceOptions.filter((o) => o.trim()).length >= 2;
    }
    return dateOptions.filter((o) => o.date).length >= 1;
  };

  const handleSave = async () => {
    if (!canSave()) return;
    setSaving(true);

    const { data: poll, error } = await supabase
      .from("org_polls")
      .insert({
        group_id: orgId, // prop heisst noch orgId, ist aber group_id
        project_id: projectId || null,
        title: title.trim(),
        description: description.trim() || null,
        poll_type: pollType,
        allows_multiple: pollType === "date" ? true : allowsMultiple,
        is_anonymous: isAnonymous,
        deadline: deadline || null,
        created_by: (await supabase.auth.getUser()).data.user!.id,
      })
      .select("id")
      .single();

    if (error || !poll) {
      setSaving(false);
      return;
    }

    // Optionen einfügen
    const options =
      pollType === "choice"
        ? choiceOptions
            .filter((o) => o.trim())
            .map((label, i) => ({
              poll_id: poll.id,
              label: label.trim(),
              sort_order: i,
            }))
        : dateOptions
            .filter((o) => o.date)
            .map((opt, i) => {
              const d = new Date(opt.date);
              const label = d.toLocaleDateString("de-DE", {
                weekday: "short",
                day: "2-digit",
                month: "2-digit",
                year: "numeric",
              }) + (opt.time ? ` ${opt.time}` : "");
              return {
                poll_id: poll.id,
                label,
                date_value: opt.date,
                time_value: opt.time || null,
                sort_order: i,
              };
            });

    await supabase.from("org_poll_options").insert(options);

    // Notification an Group-Member
    if (orgId) {
      try {
        const { data: members } = await supabase
          .from("group_memberships")
          .select("profile_id")
          .eq("group_id", orgId)
          .eq("is_active", true);
        const { data: { user } } = await supabase.auth.getUser();
        const recipients = (members || [])
          .map((m) => m.profile_id)
          .filter((id) => id !== user?.id);
        if (recipients.length > 0) {
          const { data: creator } = await supabase
            .from("profiles")
            .select("name")
            .eq("id", user?.id || "")
            .single();
          await supabase.functions.invoke("send-notification", {
            body: {
              template_key: "new_poll",
              recipients,
              pref_key: "polls",
              vars: {
                poll_title: title.trim(),
                creator_name: creator?.name || "Jemand",
                deadline: deadline
                  ? new Date(deadline).toLocaleDateString("de-DE")
                  : "kein Limit",
                poll_url: `${window.location.origin}/polls`,
              },
            },
          });
        }
      } catch {
        // silent
      }
    }

    setSaving(false);
    onCreated();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: "rgba(0,0,0,0.5)" }}>
      <div
        className="w-full max-w-lg rounded-xl shadow-2xl max-h-[90vh] overflow-y-auto"
        style={{ background: "var(--color-surface)" }}
      >
        {/* Header */}
        <div className="flex items-center justify-between p-5 border-b" style={{ borderColor: "var(--color-border)" }}>
          <h2 className="text-lg font-semibold" style={{ color: "var(--color-foreground)" }}>
            Neue Umfrage
          </h2>
          <button onClick={onClose} className="p-1 rounded-md hover:opacity-70">
            <IconX size={20} />
          </button>
        </div>

        <div className="p-5 space-y-5">
          {/* Typ-Auswahl */}
          <div className="flex gap-2">
            <button
              onClick={() => setPollType("choice")}
              className="flex-1 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors"
              style={{
                background: pollType === "choice" ? "var(--color-primary)" : "var(--color-muted)",
                color: pollType === "choice" ? "#fff" : "var(--color-foreground)",
              }}
            >
              Allgemeine Umfrage
            </button>
            <button
              onClick={() => setPollType("date")}
              className="flex-1 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors"
              style={{
                background: pollType === "date" ? "var(--color-primary)" : "var(--color-muted)",
                color: pollType === "date" ? "#fff" : "var(--color-foreground)",
              }}
            >
              Terminumfrage
            </button>
          </div>

          {/* Titel */}
          <div>
            <label className="block text-sm font-medium mb-1.5" style={{ color: "var(--color-foreground)" }}>
              {pollType === "date" ? "Anlass" : "Frage"}
            </label>
            <input
              type="text"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              placeholder={pollType === "date" ? "z.B. Nächstes Meeting" : "z.B. Welches Logo gefällt euch?"}
              className="w-full px-3 py-2 rounded-lg text-sm"
              style={{
                background: "var(--color-background)",
                border: "1px solid var(--color-border)",
                color: "var(--color-foreground)",
              }}
            />
          </div>

          {/* Beschreibung */}
          <div>
            <label className="block text-sm font-medium mb-1.5" style={{ color: "var(--color-foreground)" }}>
              Beschreibung <span style={{ color: "var(--color-muted-foreground)" }}>(optional)</span>
            </label>
            <textarea
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              rows={2}
              className="w-full px-3 py-2 rounded-lg text-sm resize-none"
              style={{
                background: "var(--color-background)",
                border: "1px solid var(--color-border)",
                color: "var(--color-foreground)",
              }}
            />
          </div>

          {/* Optionen */}
          {pollType === "choice" ? (
            <div>
              <label className="block text-sm font-medium mb-2" style={{ color: "var(--color-foreground)" }}>
                Antwortoptionen
              </label>
              <div className="space-y-2">
                {choiceOptions.map((opt, i) => (
                  <div key={i} className="flex gap-2">
                    <input
                      type="text"
                      value={opt}
                      onChange={(e) => updateChoiceOption(i, e.target.value)}
                      placeholder={`Option ${i + 1}`}
                      className="flex-1 px-3 py-2 rounded-lg text-sm"
                      style={{
                        background: "var(--color-background)",
                        border: "1px solid var(--color-border)",
                        color: "var(--color-foreground)",
                      }}
                    />
                    {choiceOptions.length > 2 && (
                      <button
                        onClick={() => removeChoiceOption(i)}
                        className="p-2 rounded-lg hover:opacity-70"
                        style={{ color: "var(--color-destructive)" }}
                      >
                        <IconTrash size={16} />
                      </button>
                    )}
                  </div>
                ))}
              </div>
              <button
                onClick={addChoiceOption}
                className="mt-2 flex items-center gap-1.5 text-sm font-medium px-3 py-1.5 rounded-lg hover:opacity-80"
                style={{ color: "var(--color-primary)" }}
              >
                <IconPlus size={14} /> Option hinzufügen
              </button>
            </div>
          ) : (
            <div>
              <label className="block text-sm font-medium mb-2" style={{ color: "var(--color-foreground)" }}>
                Terminvorschläge
              </label>
              <div className="space-y-2">
                {dateOptions.map((opt, i) => (
                  <div key={i} className="flex gap-2">
                    <input
                      type="date"
                      value={opt.date}
                      onChange={(e) => updateDateOption(i, "date", e.target.value)}
                      className="flex-1 px-3 py-2 rounded-lg text-sm"
                      style={{
                        background: "var(--color-background)",
                        border: "1px solid var(--color-border)",
                        color: "var(--color-foreground)",
                      }}
                    />
                    <input
                      type="time"
                      value={opt.time}
                      onChange={(e) => updateDateOption(i, "time", e.target.value)}
                      className="w-28 px-3 py-2 rounded-lg text-sm"
                      style={{
                        background: "var(--color-background)",
                        border: "1px solid var(--color-border)",
                        color: "var(--color-foreground)",
                      }}
                    />
                    {dateOptions.length > 1 && (
                      <button
                        onClick={() => removeDateOption(i)}
                        className="p-2 rounded-lg hover:opacity-70"
                        style={{ color: "var(--color-destructive)" }}
                      >
                        <IconTrash size={16} />
                      </button>
                    )}
                  </div>
                ))}
              </div>
              <button
                onClick={addDateOption}
                className="mt-2 flex items-center gap-1.5 text-sm font-medium px-3 py-1.5 rounded-lg hover:opacity-80"
                style={{ color: "var(--color-primary)" }}
              >
                <IconPlus size={14} /> Termin hinzufügen
              </button>
            </div>
          )}

          {/* Einstellungen */}
          <div className="space-y-3 pt-2 border-t" style={{ borderColor: "var(--color-border)" }}>
            {pollType === "choice" && (
              <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: "var(--color-foreground)" }}>
                <input
                  type="checkbox"
                  checked={allowsMultiple}
                  onChange={(e) => setAllowsMultiple(e.target.checked)}
                  className="rounded"
                />
                Mehrfachauswahl erlauben
              </label>
            )}
            <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: "var(--color-foreground)" }}>
              <input
                type="checkbox"
                checked={isAnonymous}
                onChange={(e) => setIsAnonymous(e.target.checked)}
                className="rounded"
              />
              Anonyme Abstimmung
            </label>
            <div>
              <label className="block text-sm mb-1.5" style={{ color: "var(--color-foreground)" }}>
                Deadline <span style={{ color: "var(--color-muted-foreground)" }}>(optional)</span>
              </label>
              <input
                type="datetime-local"
                value={deadline}
                onChange={(e) => setDeadline(e.target.value)}
                className="px-3 py-2 rounded-lg text-sm"
                style={{
                  background: "var(--color-background)",
                  border: "1px solid var(--color-border)",
                  color: "var(--color-foreground)",
                }}
              />
            </div>
          </div>
        </div>

        {/* Footer */}
        <div className="flex justify-end gap-3 p-5 border-t" style={{ borderColor: "var(--color-border)" }}>
          <button
            onClick={onClose}
            className="px-4 py-2 rounded-lg text-sm font-medium"
            style={{ color: "var(--color-muted-foreground)" }}
          >
            Abbrechen
          </button>
          <button
            onClick={handleSave}
            disabled={!canSave() || saving}
            className="px-5 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
            style={{ background: "var(--color-primary)" }}
          >
            {saving ? "Erstelle..." : "Umfrage erstellen"}
          </button>
        </div>
      </div>
    </div>
  );
}
