"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { IconBuilding, IconZap, IconUsers, IconHandshake, IconProjects, IconPackage } from "@/components/ui/icons";

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
    <div className="w-full max-w-xl space-y-6">
      {/* Info-Box: Wie funktioniert Project Prepper */}
      <div
        className="rounded-xl p-6"
        style={{
          background: "var(--color-surface)",
          boxShadow: "var(--shadow-lg)",
        }}
      >
        <h2
          className="text-lg font-semibold mb-2"
          style={{ color: "var(--color-foreground)" }}
        >
          Willkommen bei Project Prepper
        </h2>
        <p
          className="text-sm mb-4"
          style={{ color: "var(--color-muted-foreground)" }}
        >
          Project Prepper ist die kollaborative Planungs-App für Event- und
          Veranstaltungs-Crews:
        </p>

        {/* Kern-Features */}
        <div className="grid grid-cols-3 gap-3 mb-6">
          <div
            className="rounded-lg p-3 text-center"
            style={{ background: "var(--color-muted)" }}
          >
            <IconProjects
              size={20}
              className="mx-auto mb-1.5"
              style={{ color: "var(--color-primary)" }}
            />
            <div
              className="text-xs font-semibold"
              style={{ color: "var(--color-foreground)" }}
            >
              Projekte & Teams
            </div>
            <div
              className="text-[11px] mt-0.5"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              Crews zusammenstellen, Aufgaben verteilen, Zeitpläne
            </div>
          </div>
          <div
            className="rounded-lg p-3 text-center"
            style={{ background: "var(--color-muted)" }}
          >
            <IconPackage
              size={20}
              className="mx-auto mb-1.5"
              style={{ color: "var(--color-warning)" }}
            />
            <div
              className="text-xs font-semibold"
              style={{ color: "var(--color-foreground)" }}
            >
              Inventar Sharing
            </div>
            <div
              className="text-[11px] mt-0.5"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              Equipment verwalten, teilen, verleihen
            </div>
          </div>
          <div
            className="rounded-lg p-3 text-center"
            style={{ background: "var(--color-muted)" }}
          >
            <IconUsers
              size={20}
              className="mx-auto mb-1.5"
              style={{ color: "var(--color-success)" }}
            />
            <div
              className="text-xs font-semibold"
              style={{ color: "var(--color-foreground)" }}
            >
              Kollaboration
            </div>
            <div
              className="text-[11px] mt-0.5"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              Org-übergreifend mit Partnern & Freelancern
            </div>
          </div>
        </div>

        <p
          className="text-sm font-medium mb-3"
          style={{ color: "var(--color-foreground)" }}
        >
          So bekommen andere Zugang zu deinen Projekten:
        </p>

        <div className="space-y-3">
          {/* 1. Team */}
          <div className="flex gap-3">
            <div
              className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
              style={{ background: "var(--color-primary-light)" }}
            >
              <IconUsers size={18} style={{ color: "var(--color-primary)" }} />
            </div>
            <div>
              <div
                className="text-sm font-medium"
                style={{ color: "var(--color-foreground)" }}
              >
                Team-Einladung
              </div>
              <div
                className="text-xs"
                style={{ color: "var(--color-muted-foreground)" }}
              >
                Admin lädt Mitglieder per E-Mail in die Organisation ein. Diese
                werden Teil des Teams mit vollem Zugriff.
              </div>
            </div>
          </div>

          {/* 2. Partnerschaft */}
          <div className="flex gap-3">
            <div
              className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
              style={{ background: "var(--color-info-light)" }}
            >
              <IconHandshake size={18} style={{ color: "var(--color-info)" }} />
            </div>
            <div>
              <div
                className="text-sm font-medium"
                style={{ color: "var(--color-foreground)" }}
              >
                Partnerschaft (Cross-Org)
              </div>
              <div
                className="text-xs"
                style={{ color: "var(--color-muted-foreground)" }}
              >
                Zwei Organisationen teilen Inventar und Kontakte. Jeder bleibt
                in seiner eigenen Org, kann aber Equipment anfragen.
              </div>
            </div>
          </div>

          {/* 3. Projekt-Einladung */}
          <div className="flex gap-3">
            <div
              className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
              style={{ background: "var(--color-success-light)" }}
            >
              <IconProjects size={18} style={{ color: "var(--color-success)" }} />
            </div>
            <div>
              <div
                className="text-sm font-medium"
                style={{ color: "var(--color-foreground)" }}
              >
                Projekt-Einladung
              </div>
              <div
                className="text-xs"
                style={{ color: "var(--color-muted-foreground)" }}
              >
                Für externe Helfer/Freelancer: Zugriff nur auf ein bestimmtes
                Projekt, nicht die ganze Organisation.
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Formular */}
      <div
        className="rounded-xl p-8"
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
          Deine Organisation anlegen
        </h1>
        <p
          className="text-sm mt-2"
          style={{ color: "var(--color-muted-foreground)" }}
        >
          Du wirst automatisch Admin dieser Organisation.
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
    </div>
  );
}
