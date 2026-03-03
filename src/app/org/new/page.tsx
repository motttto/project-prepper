"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { IconBuilding, IconZap } from "@/components/ui/icons";

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

export default function NewOrgPage() {
  const router = useRouter();
  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [slugEdited, setSlugEdited] = useState(false);
  const [description, setDescription] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  function handleNameChange(value: string) {
    setName(value);
    if (!slugEdited) {
      setSlug(slugify(value));
    }
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!name.trim()) return;

    setSaving(true);
    setError("");

    const supabase = createClient();
    const {
      data: { user },
    } = await supabase.auth.getUser();

    if (!user) {
      setError("Nicht eingeloggt.");
      setSaving(false);
      return;
    }

    const finalSlug = slug || slugify(name);

    const { error: insertError } = await supabase
      .from("organizations")
      .insert({
        name: name.trim(),
        slug: finalSlug,
        description: description.trim() || null,
        created_by: user.id,
      });

    if (insertError) {
      if (insertError.message.includes("duplicate") || insertError.message.includes("unique")) {
        setError("Dieser URL-Name ist bereits vergeben. Bitte wähle einen anderen.");
      } else {
        setError(insertError.message);
      }
      setSaving(false);
      return;
    }

    // Cookie setzen für die neue Org
    const { data: newOrg } = await supabase
      .from("organizations")
      .select("id")
      .eq("slug", finalSlug)
      .single();

    if (newOrg) {
      document.cookie = `pp_org_id=${newOrg.id};path=/;max-age=${60 * 60 * 24 * 365};SameSite=Lax`;
    }

    router.push("/dashboard");
    router.refresh();
  }

  return (
    <div
      className="w-full max-w-md rounded-xl p-8"
      style={{
        background: "var(--color-surface)",
        boxShadow: "var(--shadow-lg)",
      }}
    >
      {/* Header */}
      <div className="text-center mb-8">
        <div
          className="w-14 h-14 rounded-xl flex items-center justify-center mx-auto mb-4"
          style={{ background: "var(--color-primary-light)" }}
        >
          <IconZap size={28} style={{ color: "var(--color-primary)" }} />
        </div>
        <h1
          className="text-2xl font-bold"
          style={{ color: "var(--color-foreground)" }}
        >
          Neue Organisation
        </h1>
        <p
          className="text-sm mt-2"
          style={{ color: "var(--color-muted-foreground)" }}
        >
          Erstelle eine Organisation, um Projekte, Inventar und Team zu
          verwalten.
        </p>
      </div>

      {/* Form */}
      <form onSubmit={handleSubmit} className="space-y-5">
        {/* Name */}
        <div>
          <label
            className="block text-sm font-medium mb-1.5"
            style={{ color: "var(--color-foreground)" }}
          >
            Name *
          </label>
          <input
            type="text"
            value={name}
            onChange={(e) => handleNameChange(e.target.value)}
            placeholder="z.B. Meine Firma"
            required
            className="w-full px-3 py-2.5 rounded-lg text-sm"
            style={{
              background: "var(--color-muted)",
              color: "var(--color-foreground)",
              border: "1px solid var(--color-border)",
            }}
          />
        </div>

        {/* Slug */}
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
            onChange={(e) => {
              setSlug(slugify(e.target.value));
              setSlugEdited(true);
            }}
            placeholder="meine-firma"
            className="w-full px-3 py-2.5 rounded-lg text-sm font-mono"
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
            Wird automatisch aus dem Namen generiert.
          </p>
        </div>

        {/* Description */}
        <div>
          <label
            className="block text-sm font-medium mb-1.5"
            style={{ color: "var(--color-foreground)" }}
          >
            Beschreibung (optional)
          </label>
          <textarea
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={2}
            placeholder="Kurze Beschreibung der Organisation..."
            className="w-full px-3 py-2.5 rounded-lg text-sm resize-none"
            style={{
              background: "var(--color-muted)",
              color: "var(--color-foreground)",
              border: "1px solid var(--color-border)",
            }}
          />
        </div>

        {/* Error */}
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

        {/* Submit */}
        <button
          type="submit"
          disabled={saving || !name.trim()}
          className="w-full py-2.5 rounded-lg text-sm font-medium text-white transition-colors disabled:opacity-50"
          style={{ background: "var(--color-primary)" }}
        >
          {saving ? "Wird erstellt..." : "Organisation erstellen"}
        </button>
      </form>
    </div>
  );
}
