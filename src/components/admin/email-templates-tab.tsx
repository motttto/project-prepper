"use client";

import { useEffect, useState, useCallback, useMemo } from "react";
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

// Beispielwerte für Vorschau (per Template-Key)
const sampleVars: Record<string, Record<string, string>> = {
  group_invite_voting_needed: {
    group_name: "Dunkelstrom",
    invitee_name: "Lukas Jesiek",
    voting_url: "https://project-prepper.dunkelstrom.net/groups/abc",
    recipient_name: "Mo",
  },
  new_poll: {
    poll_title: "Wann machen wir den nächsten Workshop?",
    creator_name: "Mo",
    deadline: "15. Mai 2026",
    poll_url: "https://project-prepper.dunkelstrom.net/polls",
    recipient_name: "Lukas",
  },
  loan_request_received: {
    item_name: "Pioneer XDJ-RX3",
    requester_name: "Anna Schmidt",
    dates: "12. Mai 2026 – 15. Mai 2026",
    message: "Wir machen ein Open-Air-Event und brauchen das Pult — wäre super!",
    request_url: "https://project-prepper.dunkelstrom.net/inventory",
    recipient_name: "Mo",
  },
  feedback_received: {
    feedback_type: "Bug",
    sender_name: "Lukas",
    sender_email: "lukas@example.com",
    app_route: "/inventory",
    message: "Wenn ich auf Auswertung klicke, lädt die Seite ewig.",
    admin_url: "https://project-prepper.dunkelstrom.net/admin",
    recipient_name: "Superadmin",
  },
};

function renderTemplate(tpl: string, vars: Record<string, string>): string {
  return tpl.replace(/\{\{(\w+)\}\}/g, (_, key) => vars[key] ?? `{{${key}}}`);
}

export function EmailTemplatesTab() {
  const supabase = createClient();
  const [templates, setTemplates] = useState<EmailTemplate[]>([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState<EmailTemplate | null>(null);
  const [previewing, setPreviewing] = useState<EmailTemplate | null>(null);
  const [draft, setDraft] = useState<{ subject: string; html_body: string; text_body: string }>({
    subject: "",
    html_body: "",
    text_body: "",
  });
  const [view, setView] = useState<"edit" | "preview">("edit");
  const [saving, setSaving] = useState(false);

  const previewVars = useMemo(() => {
    if (!editing) return {};
    return sampleVars[editing.key] || {};
  }, [editing]);

  const renderedSubject = useMemo(
    () => renderTemplate(draft.subject, previewVars),
    [draft.subject, previewVars]
  );
  const renderedHtml = useMemo(
    () => renderTemplate(draft.html_body, previewVars),
    [draft.html_body, previewVars]
  );

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
    setView("edit");
    setDraft({
      subject: t.subject,
      html_body: t.html_body,
      text_body: t.text_body || "",
    });
  }

  function openPreview(t: EmailTemplate) {
    setPreviewing(t);
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
            <div className="flex gap-1.5">
              <button
                onClick={() => openPreview(t)}
                className="px-3 py-1.5 rounded-lg text-xs font-medium"
                style={{ border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}
              >
                Vorschau
              </button>
              <button
                onClick={() => openEditor(t)}
                className="px-3 py-1.5 rounded-lg text-xs font-medium text-white"
                style={{ background: "var(--color-primary)" }}
              >
                Bearbeiten
              </button>
            </div>
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
          className="fixed inset-0 z-50 flex items-start justify-center p-4 pt-[5vh] overflow-y-auto"
          style={{ background: "rgba(0,0,0,0.5)" }}
          onClick={() => setEditing(null)}
        >
          <div
            className="w-full max-w-4xl rounded-xl p-6"
            style={{ background: "var(--color-surface)" }}
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between mb-3">
              <div>
                <h2 className="text-lg font-bold">Template bearbeiten</h2>
                <p className="font-mono text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                  {editing.key}
                </p>
              </div>
              <div className="flex gap-1 rounded-lg p-1" style={{ background: "var(--color-muted)" }}>
                {(["edit", "preview"] as const).map((v) => (
                  <button
                    key={v}
                    onClick={() => setView(v)}
                    className="px-3 py-1.5 rounded-md text-xs font-medium"
                    style={{
                      background: view === v ? "var(--color-primary)" : "transparent",
                      color: view === v ? "var(--color-primary-foreground)" : "var(--color-muted-foreground)",
                    }}
                  >
                    {v === "edit" ? "Bearbeiten" : "Vorschau"}
                  </button>
                ))}
              </div>
            </div>

            {view === "edit" ? (
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
            ) : (
              <div className="space-y-2">
                <div className="text-xs p-3 rounded-lg" style={{ background: "var(--color-muted)" }}>
                  <div className="font-medium mb-1">Betreff</div>
                  <div>{renderedSubject}</div>
                </div>
                <div>
                  <div className="text-xs font-medium mb-1">Gerenderte E-Mail (mit Beispieldaten)</div>
                  <iframe
                    title="Email-Vorschau"
                    srcDoc={renderedHtml}
                    sandbox=""
                    className="w-full rounded-lg"
                    style={{ height: "60vh", border: "1px solid var(--color-border)", background: "#fff" }}
                  />
                </div>
                <p className="text-[11px]" style={{ color: "var(--color-muted-foreground)" }}>
                  Beispieldaten kommen aus einem Sample-Set pro Template-Typ — der echte Versand setzt die Werte aus dem jeweiligen Anlass ein.
                </p>
              </div>
            )}

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

      {previewing && (
        <div
          className="fixed inset-0 z-50 flex items-start justify-center p-4 pt-[5vh] overflow-y-auto"
          style={{ background: "rgba(0,0,0,0.5)" }}
          onClick={() => setPreviewing(null)}
        >
          <div
            className="w-full max-w-3xl rounded-xl p-6"
            style={{ background: "var(--color-surface)" }}
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between mb-3">
              <div>
                <h2 className="text-lg font-bold">Vorschau</h2>
                <p className="font-mono text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                  {previewing.key}
                </p>
              </div>
              <button
                onClick={() => setPreviewing(null)}
                className="px-3 py-1.5 rounded-md text-xs font-medium"
                style={{ border: "1px solid var(--color-border)" }}
              >
                Schließen
              </button>
            </div>

            <div className="text-xs p-3 rounded-lg mb-2" style={{ background: "var(--color-muted)" }}>
              <div className="font-medium mb-1">Betreff</div>
              <div>{renderTemplate(previewing.subject, sampleVars[previewing.key] || {})}</div>
            </div>
            <iframe
              title="Email-Vorschau"
              srcDoc={renderTemplate(previewing.html_body, sampleVars[previewing.key] || {})}
              sandbox=""
              className="w-full rounded-lg"
              style={{ height: "60vh", border: "1px solid var(--color-border)", background: "#fff" }}
            />
            <p className="text-[11px] mt-2" style={{ color: "var(--color-muted-foreground)" }}>
              Beispieldaten — der echte Versand setzt die Werte aus dem jeweiligen Anlass ein.
            </p>
          </div>
        </div>
      )}
    </div>
  );
}
