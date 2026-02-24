"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import { useCurrentUser } from "@/hooks/use-current-user";
import { IconBuilding } from "@/components/ui/icons";

export default function OrgSettingsPage() {
  const { orgId, orgName, reload } = useOrg();
  const currentUser = useCurrentUser();
  const supabase = createClient();

  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [slug, setSlug] = useState("");
  const [saving, setSaving] = useState(false);
  const [success, setSuccess] = useState(false);
  const [error, setError] = useState("");

  const isAdmin = currentUser?.roleName === "admin" || currentUser?.isSystem;

  const loadOrg = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("organizations")
      .select("name, slug, description")
      .eq("id", orgId)
      .single();
    if (data) {
      setName(data.name || "");
      setSlug(data.slug || "");
      setDescription(data.description || "");
    }
  }, [orgId, supabase]);

  useEffect(() => {
    loadOrg();
  }, [loadOrg]);

  async function handleSave(e: React.FormEvent) {
    e.preventDefault();
    if (!orgId || !isAdmin) return;

    setSaving(true);
    setError("");
    setSuccess(false);

    const { error: updateError } = await supabase
      .from("organizations")
      .update({
        name: name.trim(),
        description: description.trim() || null,
        updated_at: new Date().toISOString(),
      })
      .eq("id", orgId);

    if (updateError) {
      setError(updateError.message);
    } else {
      setSuccess(true);
      reload();
      setTimeout(() => setSuccess(false), 3000);
    }
    setSaving(false);
  }

  return (
    <div className="animate-fadeIn">
      {/* Header */}
      <div className="flex items-center gap-3 mb-6">
        <div
          className="w-10 h-10 rounded-lg flex items-center justify-center"
          style={{ background: "var(--color-primary-light)" }}
        >
          <IconBuilding size={20} style={{ color: "var(--color-primary)" }} />
        </div>
        <div>
          <h1
            className="text-2xl font-bold"
            style={{ color: "var(--color-foreground)" }}
          >
            Organisation
          </h1>
          <p
            className="text-sm"
            style={{ color: "var(--color-muted-foreground)" }}
          >
            Einstellungen für {orgName}
          </p>
        </div>
      </div>

      {/* Settings Card */}
      <div
        className="rounded-xl p-6 max-w-xl"
        style={{
          background: "var(--color-surface)",
          border: "1px solid var(--color-border)",
        }}
      >
        <form onSubmit={handleSave} className="space-y-5">
          {/* Name */}
          <div>
            <label
              className="block text-sm font-medium mb-1.5"
              style={{ color: "var(--color-foreground)" }}
            >
              Name
            </label>
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              disabled={!isAdmin}
              className="w-full px-3 py-2.5 rounded-lg text-sm disabled:opacity-60"
              style={{
                background: "var(--color-muted)",
                color: "var(--color-foreground)",
                border: "1px solid var(--color-border)",
              }}
            />
          </div>

          {/* Slug (read-only) */}
          <div>
            <label
              className="block text-sm font-medium mb-1.5"
              style={{ color: "var(--color-foreground)" }}
            >
              URL-Name
            </label>
            <input
              type="text"
              value={slug}
              disabled
              className="w-full px-3 py-2.5 rounded-lg text-sm font-mono opacity-60"
              style={{
                background: "var(--color-muted)",
                color: "var(--color-foreground)",
                border: "1px solid var(--color-border)",
              }}
            />
            <p
              className="text-xs mt-1"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              Kann nach der Erstellung nicht mehr geändert werden.
            </p>
          </div>

          {/* Description */}
          <div>
            <label
              className="block text-sm font-medium mb-1.5"
              style={{ color: "var(--color-foreground)" }}
            >
              Beschreibung
            </label>
            <textarea
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              disabled={!isAdmin}
              rows={3}
              className="w-full px-3 py-2.5 rounded-lg text-sm resize-none disabled:opacity-60"
              style={{
                background: "var(--color-muted)",
                color: "var(--color-foreground)",
                border: "1px solid var(--color-border)",
              }}
            />
          </div>

          {/* Messages */}
          {error && (
            <div
              className="text-sm px-3 py-2 rounded-lg"
              style={{
                background: "var(--color-destructive-light)",
                color: "var(--color-destructive)",
              }}
            >
              {error}
            </div>
          )}
          {success && (
            <div
              className="text-sm px-3 py-2 rounded-lg"
              style={{
                background: "var(--color-success-light)",
                color: "var(--color-success)",
              }}
            >
              Gespeichert!
            </div>
          )}

          {/* Save Button */}
          {isAdmin && (
            <button
              type="submit"
              disabled={saving || !name.trim()}
              className="px-4 py-2.5 rounded-lg text-sm font-medium text-white transition-colors disabled:opacity-50"
              style={{ background: "var(--color-primary)" }}
            >
              {saving ? "Wird gespeichert..." : "Speichern"}
            </button>
          )}
        </form>
      </div>
    </div>
  );
}
