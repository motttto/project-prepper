"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import { useCurrentUser } from "@/hooks/use-current-user";
import { IconBuilding, IconMail, IconEye, IconCheck } from "@/components/ui/icons";
import { OrgPartnerships } from "@/components/org/org-partnerships";
import type { OrgEmailConfig } from "@/types/database";

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

  // SMTP State
  const [smtpHost, setSmtpHost] = useState("");
  const [smtpPort, setSmtpPort] = useState(587);
  const [smtpUser, setSmtpUser] = useState("");
  const [smtpPass, setSmtpPass] = useState("");
  const [senderEmail, setSenderEmail] = useState("");
  const [senderName, setSenderName] = useState("");
  const [smtpEnabled, setSmtpEnabled] = useState(false);
  const [smtpSaving, setSmtpSaving] = useState(false);
  const [smtpSuccess, setSmtpSuccess] = useState("");
  const [smtpError, setSmtpError] = useState("");
  const [smtpTesting, setSmtpTesting] = useState(false);
  const [showPass, setShowPass] = useState(false);
  const [smtpConfigId, setSmtpConfigId] = useState<string | null>(null);

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

  const loadSmtpConfig = useCallback(async () => {
    if (!orgId || !isAdmin) return;
    const { data } = await supabase
      .from("org_email_config")
      .select("*")
      .eq("org_id", orgId)
      .single();
    if (data) {
      const config = data as OrgEmailConfig;
      setSmtpConfigId(config.id);
      setSmtpHost(config.smtp_host);
      setSmtpPort(config.smtp_port);
      setSmtpUser(config.smtp_user);
      setSmtpPass(config.smtp_pass);
      setSenderEmail(config.sender_email);
      setSenderName(config.sender_name);
      setSmtpEnabled(config.is_enabled);
    }
  }, [orgId, isAdmin, supabase]);

  useEffect(() => {
    loadOrg();
    loadSmtpConfig();
  }, [loadOrg, loadSmtpConfig]);

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

  async function handleSmtpSave() {
    if (!orgId || !isAdmin) return;
    setSmtpSaving(true);
    setSmtpError("");
    setSmtpSuccess("");

    const payload = {
      org_id: orgId,
      smtp_host: smtpHost.trim(),
      smtp_port: smtpPort,
      smtp_user: smtpUser.trim(),
      smtp_pass: smtpPass,
      sender_email: senderEmail.trim(),
      sender_name: senderName.trim(),
      is_enabled: smtpEnabled,
    };

    if (smtpConfigId) {
      const { error } = await supabase
        .from("org_email_config")
        .update(payload)
        .eq("id", smtpConfigId);
      if (error) setSmtpError(error.message);
      else {
        setSmtpSuccess("E-Mail-Einstellungen gespeichert");
        setTimeout(() => setSmtpSuccess(""), 3000);
      }
    } else {
      const { data, error } = await supabase
        .from("org_email_config")
        .insert(payload)
        .select("id")
        .single();
      if (error) setSmtpError(error.message);
      else {
        setSmtpConfigId(data?.id || null);
        setSmtpSuccess("E-Mail-Einstellungen gespeichert");
        setTimeout(() => setSmtpSuccess(""), 3000);
      }
    }
    setSmtpSaving(false);
  }

  async function handleSmtpTest() {
    if (!orgId) return;
    setSmtpTesting(true);
    setSmtpError("");
    setSmtpSuccess("");

    // Erst speichern, dann testen
    await handleSmtpSave();

    try {
      const { data, error } = await supabase.functions.invoke("test-smtp", {
        body: { org_id: orgId, test_email: currentUser?.email },
      });

      if (error) {
        setSmtpError(`Test fehlgeschlagen: ${error.message}`);
      } else if (data?.error) {
        setSmtpError(data.error);
      } else {
        setSmtpSuccess(data?.message || "Test-Email gesendet!");
        setTimeout(() => setSmtpSuccess(""), 5000);
      }
    } catch (err) {
      setSmtpError(`Fehler: ${(err as Error).message}`);
    }
    setSmtpTesting(false);
  }

  const inputStyle = {
    background: "var(--color-muted)",
    color: "var(--color-foreground)",
    border: "1px solid var(--color-border)",
  };

  return (
    <div className="animate-fadeIn max-w-xl">
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
        className="rounded-xl p-6"
        style={{
          background: "var(--color-surface)",
          border: "1px solid var(--color-border)",
        }}
      >
        <form onSubmit={handleSave} className="space-y-5">
          {/* Name */}
          <div>
            <label className="block text-sm font-medium mb-1.5" style={{ color: "var(--color-foreground)" }}>
              Name
            </label>
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              disabled={!isAdmin}
              className="w-full px-3 py-2.5 rounded-lg text-sm disabled:opacity-60"
              style={inputStyle}
            />
          </div>

          {/* Slug (read-only) */}
          <div>
            <label className="block text-sm font-medium mb-1.5" style={{ color: "var(--color-foreground)" }}>
              URL-Name
            </label>
            <input
              type="text"
              value={slug}
              disabled
              className="w-full px-3 py-2.5 rounded-lg text-sm font-mono opacity-60"
              style={inputStyle}
            />
            <p className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
              Kann nach der Erstellung nicht mehr geändert werden.
            </p>
          </div>

          {/* Description */}
          <div>
            <label className="block text-sm font-medium mb-1.5" style={{ color: "var(--color-foreground)" }}>
              Beschreibung
            </label>
            <textarea
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              disabled={!isAdmin}
              rows={3}
              className="w-full px-3 py-2.5 rounded-lg text-sm resize-none disabled:opacity-60"
              style={inputStyle}
            />
          </div>

          {error && (
            <div className="text-sm px-3 py-2 rounded-lg" style={{ background: "var(--color-destructive-light)", color: "var(--color-destructive)" }}>
              {error}
            </div>
          )}
          {success && (
            <div className="text-sm px-3 py-2 rounded-lg" style={{ background: "var(--color-success-light)", color: "var(--color-success)" }}>
              Gespeichert!
            </div>
          )}

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

      {/* E-Mail Einstellungen (nur Admins) */}
      {isAdmin && (
        <div
          className="rounded-xl p-6 mt-6"
          style={{
            background: "var(--color-surface)",
            border: "1px solid var(--color-border)",
          }}
        >
          <div className="flex items-center gap-2 mb-5">
            <IconMail size={20} style={{ color: "var(--color-primary)" }} />
            <h2 className="text-lg font-semibold" style={{ color: "var(--color-foreground)" }}>
              E-Mail Einstellungen
            </h2>
          </div>
          <p className="text-sm mb-5" style={{ color: "var(--color-muted-foreground)" }}>
            SMTP-Server für Projekt-Einladungen per E-Mail. Ohne Konfiguration werden Einladungen nur in der App angezeigt.
          </p>

          <div className="space-y-4">
            {/* Aktiviert Toggle */}
            <label className="flex items-center gap-3 cursor-pointer">
              <div
                className="relative w-10 h-6 rounded-full transition-colors"
                style={{ background: smtpEnabled ? "var(--color-primary)" : "var(--color-muted)" }}
                onClick={() => setSmtpEnabled(!smtpEnabled)}
              >
                <div
                  className="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform"
                  style={{ left: smtpEnabled ? 18 : 2 }}
                />
              </div>
              <span className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>
                E-Mail-Versand aktiviert
              </span>
            </label>

            {/* SMTP Host + Port */}
            <div className="grid grid-cols-3 gap-3">
              <div className="col-span-2">
                <label className="block text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>
                  SMTP Host
                </label>
                <input
                  type="text"
                  value={smtpHost}
                  onChange={(e) => setSmtpHost(e.target.value)}
                  placeholder="smtp.example.com"
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={inputStyle}
                />
              </div>
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>
                  Port
                </label>
                <input
                  type="number"
                  value={smtpPort}
                  onChange={(e) => setSmtpPort(parseInt(e.target.value) || 587)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={inputStyle}
                />
              </div>
            </div>

            {/* SMTP User + Pass */}
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>
                Benutzername
              </label>
              <input
                type="text"
                value={smtpUser}
                onChange={(e) => setSmtpUser(e.target.value)}
                placeholder="user@example.com"
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
              />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>
                Passwort
              </label>
              <div className="relative">
                <input
                  type={showPass ? "text" : "password"}
                  value={smtpPass}
                  onChange={(e) => setSmtpPass(e.target.value)}
                  placeholder="••••••••"
                  className="w-full px-3 py-2 pr-10 rounded-lg text-sm"
                  style={inputStyle}
                />
                <button
                  type="button"
                  onClick={() => setShowPass(!showPass)}
                  className="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded hover:opacity-70"
                  style={{ color: "var(--color-muted-foreground)" }}
                >
                  <IconEye size={16} />
                </button>
              </div>
            </div>

            {/* Absender */}
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>
                  Absender-Name
                </label>
                <input
                  type="text"
                  value={senderName}
                  onChange={(e) => setSenderName(e.target.value)}
                  placeholder="Project Prepper"
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={inputStyle}
                />
              </div>
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: "var(--color-muted-foreground)" }}>
                  Absender-Email
                </label>
                <input
                  type="email"
                  value={senderEmail}
                  onChange={(e) => setSenderEmail(e.target.value)}
                  placeholder="noreply@example.com"
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={inputStyle}
                />
              </div>
            </div>

            {/* Messages */}
            {smtpError && (
              <div className="text-sm px-3 py-2 rounded-lg" style={{ background: "var(--color-destructive-light)", color: "var(--color-destructive)" }}>
                {smtpError}
              </div>
            )}
            {smtpSuccess && (
              <div className="flex items-center gap-2 text-sm px-3 py-2 rounded-lg" style={{ background: "var(--color-success-light)", color: "var(--color-success)" }}>
                <IconCheck size={14} /> {smtpSuccess}
              </div>
            )}

            {/* Buttons */}
            <div className="flex gap-3 pt-2">
              <button
                onClick={handleSmtpSave}
                disabled={smtpSaving}
                className="px-4 py-2.5 rounded-lg text-sm font-medium text-white disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}
              >
                {smtpSaving ? "Speichern..." : "Speichern"}
              </button>
              <button
                onClick={handleSmtpTest}
                disabled={smtpTesting || !smtpHost || !smtpUser}
                className="px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50"
                style={{
                  border: "1px solid var(--color-border)",
                  color: "var(--color-foreground)",
                }}
              >
                {smtpTesting ? "Teste..." : "Verbindung testen"}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Partnerschaften */}
      <div
        className="rounded-xl p-6 mt-6"
        style={{
          background: "var(--color-surface)",
          border: "1px solid var(--color-border)",
        }}
      >
        <OrgPartnerships />
      </div>
    </div>
  );
}
