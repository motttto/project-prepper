"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { useWorkspace } from "@/contexts/org-context";
import { useCurrentUser } from "@/hooks/use-current-user";
import { IconShield, IconChevronLeft } from "@/components/ui/icons";
import Link from "next/link";

function slugify(text: string): string {
  return text
    .toLowerCase()
    .replace(/[äÄ]/g, "ae")
    .replace(/[öÖ]/g, "oe")
    .replace(/[üÜ]/g, "ue")
    .replace(/[ß]/g, "ss")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "")
    .substring(0, 50);
}

export default function NewGroupPage() {
  const router = useRouter();
  const supabase = createClient();
  const currentUser = useCurrentUser();
  const { reload, switchWorkspace } = useWorkspace();
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!name.trim() || !currentUser) return;
    setSaving(true);
    setError("");

    const slug = `${slugify(name)}-${Date.now().toString(36).slice(-4)}`;

    const { data, error: err } = await supabase
      .from("groups")
      .insert({
        name: name.trim(),
        slug,
        description: description.trim() || null,
        founded_by: currentUser.id,
      })
      .select("id")
      .single();

    if (err || !data) {
      setError(err?.message || "Konnte Gruppe nicht erstellen");
      setSaving(false);
      return;
    }

    // Workspace umstellen auf neue Group
    await reload();
    switchWorkspace(data.id);
    router.push("/dashboard");
  }

  return (
    <div className="max-w-xl">
      <Link
        href="/dashboard"
        className="inline-flex items-center gap-1 text-sm mb-4"
        style={{ color: "var(--color-muted-foreground)" }}
      >
        <IconChevronLeft size={14} /> Zurueck
      </Link>

      <div
        className="rounded-xl p-6"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
      >
        <div className="flex items-center gap-3 mb-5">
          <div
            className="w-11 h-11 rounded-xl flex items-center justify-center"
            style={{ background: "var(--color-success-light)" }}
          >
            <IconShield size={22} style={{ color: "var(--color-success)" }} />
          </div>
          <div>
            <h1 className="text-xl font-bold" style={{ color: "var(--color-foreground)" }}>
              Neue Gruppe gruenden
            </h1>
            <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
              Du wirst Founder. Mitglieder kannst du danach einladen — Beitritt erfordert Einstimmigkeit aller Bestehenden.
            </p>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium mb-1.5" style={{ color: "var(--color-foreground)" }}>
              Name *
            </label>
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="z.B. Crew Süddeutschland"
              required
              className="w-full px-3 py-2.5 rounded-lg text-sm"
              style={{
                background: "var(--color-muted)",
                color: "var(--color-foreground)",
                border: "1px solid var(--color-border)",
              }}
            />
          </div>

          <div>
            <label className="block text-sm font-medium mb-1.5" style={{ color: "var(--color-foreground)" }}>
              Beschreibung (optional)
            </label>
            <textarea
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              rows={3}
              placeholder="Worum geht es bei dieser Gruppe?"
              className="w-full px-3 py-2.5 rounded-lg text-sm resize-none"
              style={{
                background: "var(--color-muted)",
                color: "var(--color-foreground)",
                border: "1px solid var(--color-border)",
              }}
            />
          </div>

          {error && (
            <div
              className="text-sm px-3 py-2 rounded-lg"
              style={{ background: "var(--color-destructive-light)", color: "var(--color-destructive)" }}
            >
              {error}
            </div>
          )}

          <button
            type="submit"
            disabled={saving || !name.trim()}
            className="px-5 py-2.5 rounded-lg text-sm font-medium text-white disabled:opacity-50"
            style={{ background: "var(--color-success)" }}
          >
            {saving ? "Wird erstellt..." : "Gruppe gruenden"}
          </button>
        </form>
      </div>
    </div>
  );
}
