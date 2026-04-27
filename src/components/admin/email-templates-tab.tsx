"use client";

import { useEffect, useState, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { showToast } from "@/hooks/use-toast";

type EmailTemplate = {
  key: string;
  description: string | null;
  subject: string;
  html_body: string;
  text_body: string | null;
  available_vars: string[] | null;
  updated_at: string | null;
};

export function EmailTemplatesTab() {
  const supabase = createClient();
  const [templates, setTemplates] = useState<EmailTemplate[]>([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState<EmailTemplate | null>(null);
  const [draft, setDraft] = useState<{ subject: string; html_body: string; text_body: string }>({
    subject: "",
    html_body: "",
    text_body: "",
  });
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    const { data } = await supabase
      .from("email_templates")
      .select("key, description, subject, html_body, text_body, available_vars, updated_at")
      .order("key");
    setTemplates((data as EmailTemplate[]) || []);
    setLoading(false);
  }, [supabase]);

  useEffect(() => {
    load();
  }, [load]);

  function openEditor(t: EmailTemplate) {
    setEditing(t);
    setDraft({
      subject: t.subject,
      html_body: t.html_body,
      text_body: t.text_body || "",
    });
  }

  async function save() {
    if (!editing) return;
    setSaving(true);
    const { error } = await supabase
      .from("email_templates")
      .update({
        subject: draft.subject,
        html_body: draft.html_body,
        text_body: draft.text_body || null,
      })
      .eq("key", editing.key);
    setSaving(false);
    if (error) {
      showToast("Fehler beim Speichern: " + error.message, "error");
      return;
    }
    showToast("Template gespeichert", "success");
    setEditing(null);
    load();
  }

  if (loading) {
    return <div className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>Lade Templates...</div>;
  }

  return (
    <div className="space-y-3">
      <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
        Email-Vorlagen für Notifications und Einladungen. Nutze {"{{variablen}}"} als Platzhalter — verfügbare Variablen pro Template sind unten gelistet.
      </p>

      {templates.map((t) => (
        <div
          key={t.key}
          className="rounded-xl p-4"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
        >
          <div className="flex items-start justify-between gap-3 mb-2">
            <div className="flex-1 min-w-0">
              <div className="font-mono text-xs px-2 py-0.5 rounded inline-block mb-1" style={{ background: "var(--color-muted)" }}>
                {t.key}
              </div>
              {t.description && (
                <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
                  {t.description}
                </p>
              )}
            </div>
            <button
              onClick={() => openEditor(t)}
              className="px-3 py-1.5 rounded-lg text-xs font-medium text-white"
              style={{ background: "var(--color-primary)" }}
            >
              Bearbeiten
            </button>
          </div>
          <div className="text-xs mt-2" style={{ color: "var(--color-muted-foreground)" }}>
            <span className="font-medium">Betreff:</span> {t.subject}
          </div>
          {t.available_vars && t.available_vars.length > 0 && (
            <div className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
              <span className="font-medium">Variablen:</span>{" "}
              {t.available_vars.map((v) => (
                <code key={v} className="font-mono mx-0.5 px-1 rounded" style={{ background: "var(--color-muted)" }}>
                  {`{{${v}}}`}
                </code>
              ))}
            </div>
          )}
          {t.updated_at && (
            <div className="text-[11px] mt-2" style={{ color: "var(--color-muted-foreground)" }}>
              Zuletzt geändert: {new Date(t.updated_at).toLocaleString("de-DE")}
            </div>
          )}
        </div>
      ))}

      {editing && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center p-4"
          style={{ background: "rgba(0,0,0,0.5)" }}
          onClick={() => setEditing(null)}
        >
          <div
            className="w-full max-w-3xl max-h-[90vh] overflow-auto rounded-xl p-6"
            style={{ background: "var(--color-surface)" }}
            onClick={(e) => e.stopPropagation()}
          >
            <h2 className="text-lg font-bold mb-1">Template bearbeiten</h2>
            <p className="font-mono text-xs mb-4" style={{ color: "var(--color-muted-foreground)" }}>
              {editing.key}
            </p>

            <div className="space-y-3">
              <div>
                <label className="block text-xs font-medium mb-1">Betreff</label>
                <input
                  type="text"
                  value={draft.subject}
                  onChange={(e) => setDraft({ ...draft, subject: e.target.value })}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
                />
              </div>
              <div>
                <label className="block text-xs font-medium mb-1">HTML-Body</label>
                <textarea
                  value={draft.html_body}
                  onChange={(e) => setDraft({ ...draft, html_body: e.target.value })}
                  rows={14}
                  className="w-full px-3 py-2 rounded-lg text-xs font-mono"
                  style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
                />
              </div>
              <div>
                <label className="block text-xs font-medium mb-1">Text-Body (Plain-Text-Fallback, optional)</label>
                <textarea
                  value={draft.text_body}
                  onChange={(e) => setDraft({ ...draft, text_body: e.target.value })}
                  rows={3}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
                />
              </div>
              {editing.available_vars && editing.available_vars.length > 0 && (
                <div className="text-xs p-3 rounded-lg" style={{ background: "var(--color-muted)" }}>
                  <span className="font-medium">Verfügbare Platzhalter:</span>{" "}
                  {editing.available_vars.map((v) => (
                    <code key={v} className="font-mono mx-0.5 px-1 rounded" style={{ background: "var(--color-background)" }}>
                      {`{{${v}}}`}
                    </code>
                  ))}
                </div>
              )}
            </div>

            <div className="flex gap-2 mt-5 justify-end">
              <button
                onClick={() => setEditing(null)}
                className="px-4 py-2 rounded-lg text-sm font-medium"
                style={{ border: "1px solid var(--color-border)" }}
              >
                Abbrechen
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
