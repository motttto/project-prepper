"use client";

import { useState, useEffect, useCallback, useRef } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import {
  IconSave,
  IconCamera,
  IconMail,
  IconUser,
  IconTelegram,
  IconX,
  IconShield,
} from "@/components/ui/icons";
import imageCompression from "browser-image-compression";
import { RoleBadge, RoleBadgeGroup, orgBadgeStyles } from "@/components/ui/role-badge";

type ProfileData = {
  id: string;
  name: string;
  email: string;
  avatar_url: string | null;
  is_active: boolean;
  is_system: boolean;
  approved_at: string | null;
  telegram_user_id: number | null;
  inventory_suffix: string | null;
  notification_prefs: { voting?: boolean; polls?: boolean; loans?: boolean; feedback?: boolean } | null;
  created_at: string;
};

const roleBadgeStyles = orgBadgeStyles;

export default function ProfilePage() {
  const supabase = createClient();
  const { orgId } = useOrg();
  const fileInputRef = useRef<HTMLInputElement>(null);

  const [profile, setProfile] = useState<ProfileData | null>(null);
  const [orgRoleName, setOrgRoleName] = useState("member");
  const [orgApprovedAt, setOrgApprovedAt] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [uploadingAvatar, setUploadingAvatar] = useState(false);

  // Editable fields
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [authEmail, setAuthEmail] = useState("");
  const [inventorySuffix, setInventorySuffix] = useState("");
  const [notifVoting, setNotifVoting] = useState(true);
  const [notifPolls, setNotifPolls] = useState(true);
  const [notifLoans, setNotifLoans] = useState(true);

  // Messages
  const [successMsg, setSuccessMsg] = useState<string | null>(null);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [mfaEnabled, setMfaEnabled] = useState(false);
  const [mfaLoading, setMfaLoading] = useState(true);

  // Track changes
  const hasNameChange = profile ? name !== profile.name : false;
  const hasEmailChange = email !== authEmail;
  const hasSuffixChange = profile ? inventorySuffix !== (profile.inventory_suffix || "") : false;
  const suffixValid = inventorySuffix === "" || /^[A-Z0-9]{2,6}$/.test(inventorySuffix);
  const hasNotifChange = profile
    ? notifVoting !== (profile.notification_prefs?.voting !== false)
      || notifPolls !== (profile.notification_prefs?.polls !== false)
      || notifLoans !== (profile.notification_prefs?.loans !== false)
    : false;
  const hasChanges = hasNameChange || hasEmailChange || hasSuffixChange || hasNotifChange;

  const loadProfile = useCallback(async () => {
    const {
      data: { user },
    } = await supabase.auth.getUser();
    if (!user) return;

    setAuthEmail(user.email || "");

    // Profil laden
    const { data } = await supabase
      .from("profiles")
      .select("id, name, email, avatar_url, is_active, is_system, approved_at, telegram_user_id, inventory_suffix, notification_prefs, created_at")
      .eq("id", user.id)
      .single();

    if (data) {
      const p = data as ProfileData;
      setProfile(p);
      setName(p.name);
      setEmail(p.email);
      setInventorySuffix(p.inventory_suffix || "");
      const prefs = p.notification_prefs || {};
      setNotifVoting(prefs.voting !== false);
      setNotifPolls(prefs.polls !== false);
      setNotifLoans(prefs.loans !== false);
    }

    // Rolle aus group_memberships (user-first model)
    const { data: groupMembership } = await supabase
      .from("group_memberships")
      .select("is_founder, joined_at")
      .eq("profile_id", user.id)
      .eq("is_active", true)
      .limit(1)
      .maybeSingle();

    if (groupMembership) {
      setOrgRoleName(groupMembership.is_founder ? "founder" : "member");
      setOrgApprovedAt(groupMembership.joined_at);
    }

    setLoading(false);
  }, [supabase, orgId]);

  useEffect(() => {
    loadProfile();
  }, [loadProfile]);

  // MFA-Status laden
  useEffect(() => {
    async function checkMfa() {
      const { data: factors } = await supabase.auth.mfa.listFactors();
      const verified = factors?.totp?.filter((f) => f.status === "verified") ?? [];
      setMfaEnabled(verified.length > 0);
      setMfaLoading(false);
    }
    checkMfa();
  }, [supabase]);

  // Clear messages after 5s
  useEffect(() => {
    if (successMsg || errorMsg) {
      const t = setTimeout(() => {
        setSuccessMsg(null);
        setErrorMsg(null);
      }, 5000);
      return () => clearTimeout(t);
    }
  }, [successMsg, errorMsg]);

  async function handleSave() {
    if (!profile || !hasChanges) return;
    setSaving(true);
    setErrorMsg(null);
    setSuccessMsg(null);

    try {
      // Name speichern
      if (hasNameChange) {
        const { error } = await supabase
          .from("profiles")
          .update({ name })
          .eq("id", profile.id);
        if (error) throw new Error(error.message);
      }

      // Inventar-Suffix speichern
      if (hasSuffixChange) {
        if (!suffixValid) {
          throw new Error("Suffix muss 2-6 Großbuchstaben/Ziffern enthalten");
        }
        const { error } = await supabase
          .from("profiles")
          .update({ inventory_suffix: inventorySuffix === "" ? null : inventorySuffix })
          .eq("id", profile.id);
        if (error) throw new Error(error.message);
      }

      // Notification-Prefs speichern
      if (hasNotifChange) {
        const newPrefs = {
          ...(profile.notification_prefs || {}),
          voting: notifVoting,
          polls: notifPolls,
          loans: notifLoans,
        };
        const { error } = await supabase
          .from("profiles")
          .update({ notification_prefs: newPrefs })
          .eq("id", profile.id);
        if (error) throw new Error(error.message);
      }

      // Email ändern (via Auth API → Bestätigungsmail)
      if (hasEmailChange) {
        const { error } = await supabase.auth.updateUser({ email });
        if (error) throw new Error(error.message);

        // Email auch in profiles updaten
        await supabase
          .from("profiles")
          .update({ email })
          .eq("id", profile.id);

        setSuccessMsg(
          hasNameChange
            ? "Profil gespeichert! Eine Bestätigungsmail wurde an die neue Adresse gesendet."
            : "Eine Bestätigungsmail wurde an die neue Adresse gesendet."
        );
      } else {
        setSuccessMsg("Profil gespeichert!");
      }

      await loadProfile();
    } catch (err) {
      setErrorMsg(
        err instanceof Error ? err.message : "Fehler beim Speichern"
      );
    } finally {
      setSaving(false);
    }
  }

  async function handleAvatarUpload(file: File) {
    if (!profile) return;
    setUploadingAvatar(true);
    setErrorMsg(null);

    try {
      // Komprimieren
      const compressed = await imageCompression(file, {
        maxSizeMB: 0.1,
        maxWidthOrHeight: 400,
        useWebWorker: true,
        fileType: "image/webp",
      });

      const filePath = `${profile.id}/${Date.now()}.webp`;

      // Altes Bild löschen (falls vorhanden)
      if (profile.avatar_url) {
        const oldPath = profile.avatar_url.split("/avatars/")[1];
        if (oldPath) {
          await supabase.storage.from("avatars").remove([oldPath]);
        }
      }

      // Hochladen
      const { error: uploadError } = await supabase.storage
        .from("avatars")
        .upload(filePath, compressed, {
          contentType: "image/webp",
          upsert: true,
        });
      if (uploadError) throw new Error(uploadError.message);

      // Public URL holen
      const {
        data: { publicUrl },
      } = supabase.storage.from("avatars").getPublicUrl(filePath);

      // Profil updaten
      const { error: updateError } = await supabase
        .from("profiles")
        .update({ avatar_url: publicUrl })
        .eq("id", profile.id);
      if (updateError) throw new Error(updateError.message);

      setSuccessMsg("Profilbild aktualisiert!");
      await loadProfile();
    } catch (err) {
      setErrorMsg(
        err instanceof Error ? err.message : "Fehler beim Hochladen"
      );
    } finally {
      setUploadingAvatar(false);
    }
  }

  function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (file && file.type.startsWith("image/")) {
      handleAvatarUpload(file);
    }
    e.target.value = "";
  }

  const inputStyle = {
    border: "1px solid var(--color-border)",
    background: "var(--color-surface)",
  };

  if (loading) {
    return (
      <div
        className="flex items-center justify-center h-64"
        style={{ color: "var(--color-muted-foreground)" }}
      >
        Profil wird geladen...
      </div>
    );
  }

  if (!profile) {
    return (
      <div className="text-center py-12">
        <p className="text-lg">Profil nicht gefunden</p>
      </div>
    );
  }

  const roleName = orgRoleName;
  const badgeStyle = roleBadgeStyles[roleName] || roleBadgeStyles.member;

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold">Mein Profil</h1>
        <p
          className="text-sm mt-1"
          style={{ color: "var(--color-muted-foreground)" }}
        >
          Persönliche Einstellungen verwalten
        </p>
      </div>

      {/* Messages */}
      {successMsg && (
        <div
          className="p-3 rounded-lg text-sm mb-6"
          style={{
            background: "var(--color-success-light)",
            color: "var(--color-success)",
            border: "1px solid var(--color-success)",
          }}
        >
          {successMsg}
        </div>
      )}
      {errorMsg && (
        <div
          className="p-3 rounded-lg text-sm mb-6"
          style={{
            background: "var(--color-destructive-light)",
            color: "var(--color-destructive)",
            border: "1px solid var(--color-destructive)",
          }}
        >
          {errorMsg}
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left Column — Avatar & Info */}
        <div className="lg:col-span-1">
          <div
            className="p-6 rounded-xl"
            style={{ background: "var(--color-surface)" }}
          >
            <div className="flex flex-col items-center text-center">
              {/* Avatar */}
              <div
                className="relative group cursor-pointer mb-4"
                onClick={() => fileInputRef.current?.click()}
              >
                <div
                  className="w-24 h-24 rounded-full flex items-center justify-center overflow-hidden"
                  style={{
                    background: profile.avatar_url
                      ? "transparent"
                      : "var(--color-primary-light)",
                  }}
                >
                  {profile.avatar_url ? (
                    <img
                      src={profile.avatar_url}
                      alt={profile.name}
                      className="w-full h-full object-cover"
                    />
                  ) : (
                    <span
                      className="text-3xl font-bold"
                      style={{ color: "var(--color-primary)" }}
                    >
                      {profile.name.charAt(0).toUpperCase()}
                    </span>
                  )}
                </div>
                {/* Upload Overlay */}
                <div
                  className="absolute inset-0 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                  style={{ background: "rgba(0,0,0,0.5)" }}
                >
                  {uploadingAvatar ? (
                    <span className="text-white text-xs">...</span>
                  ) : (
                    <IconCamera size={20} style={{ color: "white" }} />
                  )}
                </div>
                <input
                  ref={fileInputRef}
                  type="file"
                  accept="image/*"
                  onChange={handleFileChange}
                  className="hidden"
                />
              </div>

              <p className="font-bold text-lg">{profile.name}</p>
              <p
                className="text-sm mt-0.5"
                style={{ color: "var(--color-muted-foreground)" }}
              >
                {profile.email}
              </p>

              <div className="flex items-center gap-2 mt-3">
                {profile.is_system ? (
                  <RoleBadgeGroup badges={[
                    { role: "system", type: "org" },
                    { role: roleName, type: "org" },
                  ]} />
                ) : (
                  <RoleBadge role={roleName} type="org" />
                )}
              </div>

              <p
                className="text-xs mt-3"
                style={{ color: "var(--color-muted-foreground)" }}
              >
                Mitglied seit{" "}
                {new Date(
                  orgApprovedAt || profile.approved_at || profile.created_at
                ).toLocaleDateString("de-DE", {
                  month: "long",
                  year: "numeric",
                })}
              </p>

              <button
                onClick={() => fileInputRef.current?.click()}
                disabled={uploadingAvatar}
                className="flex items-center gap-1.5 mt-4 pt-4 text-xs font-medium transition-colors disabled:opacity-50"
                style={{
                  color: "var(--color-primary)",
                  borderTop: "1px solid var(--color-border-light)",
                  width: "100%",
                  justifyContent: "center",
                }}
              >
                <IconCamera size={14} />
                {uploadingAvatar ? "Wird hochgeladen..." : "Foto ändern"}
              </button>
            </div>
          </div>
        </div>

        {/* Right Column — Form */}
        <div className="lg:col-span-2">
          <div
            className="p-6 rounded-xl space-y-5"
            style={{ background: "var(--color-surface)" }}
          >
            <h2 className="font-semibold text-base">Profil bearbeiten</h2>

            {/* Name */}
            <div>
              <label className="flex items-center gap-1.5 text-sm font-medium mb-1.5">
                <IconUser size={14} style={{ color: "var(--color-muted-foreground)" }} />
                Name
              </label>
              <input
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                className="w-full px-3 py-2.5 rounded-lg text-sm"
                style={inputStyle}
                placeholder="Dein Name"
                required
              />
            </div>

            {/* Email */}
            <div>
              <label className="flex items-center gap-1.5 text-sm font-medium mb-1.5">
                <IconMail size={14} style={{ color: "var(--color-muted-foreground)" }} />
                E-Mail-Adresse
              </label>
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full px-3 py-2.5 rounded-lg text-sm"
                style={inputStyle}
                placeholder="name@example.com"
              />
              {hasEmailChange && (
                <p
                  className="text-xs mt-1"
                  style={{ color: "var(--color-warning)" }}
                >
                  Eine Bestätigungsmail wird an die neue Adresse gesendet.
                </p>
              )}
            </div>

            {/* Inventar-Suffix */}
            <div>
              <label className="block text-sm font-medium mb-1.5">
                Inventar-Suffix
              </label>
              <input
                type="text"
                value={inventorySuffix}
                onChange={(e) => setInventorySuffix(e.target.value.toUpperCase().slice(0, 6))}
                className="w-full px-3 py-2.5 rounded-lg text-sm font-mono"
                style={inputStyle}
                placeholder="z.B. MOT"
                maxLength={6}
              />
              <p
                className="text-xs mt-1"
                style={{ color: hasSuffixChange && !suffixValid ? "var(--color-destructive)" : "var(--color-muted-foreground)" }}
              >
                {hasSuffixChange && !suffixValid
                  ? "2-6 Großbuchstaben oder Ziffern"
                  : "Wird an deine Inventarnummern gehängt (z.B. LIC-001-MOT)"}
              </p>
            </div>

            {/* Email-Benachrichtigungen */}
            <div className="pt-4 border-t" style={{ borderColor: "var(--color-border)" }}>
              <h3 className="text-sm font-semibold mb-2">E-Mail-Benachrichtigungen</h3>
              <p className="text-xs mb-3" style={{ color: "var(--color-muted-foreground)" }}>
                Wann sollen wir dir eine Mail schicken?
              </p>
              <div className="space-y-2">
                <label className="flex items-center gap-2 text-sm cursor-pointer">
                  <input
                    type="checkbox"
                    checked={notifVoting}
                    onChange={(e) => setNotifVoting(e.target.checked)}
                  />
                  <span>Abstimmung über neues Gruppenmitglied</span>
                </label>
                <label className="flex items-center gap-2 text-sm cursor-pointer">
                  <input
                    type="checkbox"
                    checked={notifPolls}
                    onChange={(e) => setNotifPolls(e.target.checked)}
                  />
                  <span>Neue Umfrage in einer meiner Gruppen</span>
                </label>
                <label className="flex items-center gap-2 text-sm cursor-pointer">
                  <input
                    type="checkbox"
                    checked={notifLoans}
                    onChange={(e) => setNotifLoans(e.target.checked)}
                  />
                  <span>Verleih-Anfrage für mein Equipment</span>
                </label>
              </div>
            </div>

            {/* Save Button */}
            <div className="pt-2">
              <button
                onClick={handleSave}
                disabled={!hasChanges || saving}
                className="flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium text-white transition-colors disabled:opacity-40"
                style={{ background: "var(--color-primary)" }}
                onMouseEnter={(e) => {
                  if (hasChanges && !saving)
                    e.currentTarget.style.background =
                      "var(--color-primary-hover)";
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.background = "var(--color-primary)";
                }}
              >
                <IconSave size={16} />
                {saving ? "Wird gespeichert..." : "Änderungen speichern"}
              </button>
            </div>
          </div>
        </div>

        {/* ────────────── 2FA-Status ────────────── */}
        <div
          className="rounded-xl overflow-hidden"
          style={{
            background: "var(--color-surface)",
            border: "1px solid var(--color-border)",
          }}
        >
          <div className="p-6">
            <div className="flex items-center gap-2 mb-4">
              <IconShield size={20} style={{ color: "var(--color-primary)" }} />
              <h2 className="text-base font-semibold">Zwei-Faktor-Authentifizierung</h2>
            </div>

            {mfaLoading ? (
              <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
                Wird geladen...
              </p>
            ) : mfaEnabled ? (
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <span
                    className="w-2 h-2 rounded-full"
                    style={{ background: "var(--color-success)" }}
                  />
                  <span className="text-sm">2FA ist aktiv</span>
                  <span
                    className="text-xs px-2 py-0.5 rounded-full"
                    style={{
                      background: "var(--color-success-light)",
                      color: "var(--color-success)",
                    }}
                  >
                    TOTP
                  </span>
                </div>
                <button
                  onClick={async () => {
                    const { data: factors } = await supabase.auth.mfa.listFactors();
                    const verified = factors?.totp?.filter((f) => f.status === "verified") ?? [];
                    for (const f of verified) {
                      await supabase.auth.mfa.unenroll({ factorId: f.id });
                    }
                    // Direkt zum Neu-Setup weiterleiten
                    window.location.href = "/mfa/setup";
                  }}
                  className="text-xs px-3 py-1.5 rounded-lg transition-colors"
                  style={{
                    color: "var(--color-muted-foreground)",
                    border: "1px solid var(--color-border)",
                  }}
                  onMouseEnter={(e) => (e.currentTarget.style.color = "var(--color-primary)")}
                  onMouseLeave={(e) => (e.currentTarget.style.color = "var(--color-muted-foreground)")}
                >
                  Neu einrichten
                </button>
              </div>
            ) : (
              <div>
                <p
                  className="text-sm mb-3"
                  style={{ color: "var(--color-muted-foreground)" }}
                >
                  2FA ist noch nicht eingerichtet. Diese Sicherheitsfunktion ist verpflichtend.
                </p>
                <button
                  onClick={() => (window.location.href = "/mfa/setup")}
                  className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium text-white transition-colors"
                  style={{ background: "var(--color-primary)" }}
                  onMouseEnter={(e) => (e.currentTarget.style.background = "var(--color-primary-hover)")}
                  onMouseLeave={(e) => (e.currentTarget.style.background = "var(--color-primary)")}
                >
                  <IconShield size={16} />
                  2FA jetzt einrichten
                </button>
              </div>
            )}
          </div>
        </div>

        {/* ────────────── Telegram-Verknüpfung ────────────── */}
        <div
          className="rounded-xl overflow-hidden"
          style={{
            background: "var(--color-surface)",
            border: "1px solid var(--color-border)",
          }}
        >
          <div className="p-6">
            <div className="flex items-center gap-2 mb-4">
              <IconTelegram size={20} style={{ color: "#229ED9" }} />
              <h2 className="text-base font-semibold">Telegram</h2>
            </div>

            {profile?.telegram_user_id ? (
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <span
                    className="w-2 h-2 rounded-full"
                    style={{ background: "var(--color-success)" }}
                  />
                  <span className="text-sm">Telegram verknüpft</span>
                  <span
                    className="text-xs px-2 py-0.5 rounded-full"
                    style={{
                      background: "var(--color-success-light)",
                      color: "var(--color-success)",
                    }}
                  >
                    ID: {profile.telegram_user_id}
                  </span>
                </div>
                <button
                  onClick={async () => {
                    await supabase
                      .from("profiles")
                      .update({ telegram_user_id: null })
                      .eq("id", profile.id);
                    setProfile({ ...profile, telegram_user_id: null });
                  }}
                  className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-colors"
                  style={{ color: "var(--color-muted-foreground)" }}
                  onMouseEnter={(e) =>
                    (e.currentTarget.style.color = "var(--color-destructive)")
                  }
                  onMouseLeave={(e) =>
                    (e.currentTarget.style.color =
                      "var(--color-muted-foreground)")
                  }
                >
                  <IconX size={12} />
                  Trennen
                </button>
              </div>
            ) : (
              <div>
                <p
                  className="text-sm mb-3"
                  style={{ color: "var(--color-muted-foreground)" }}
                >
                  Verknüpfe dein Telegram-Konto, um Anfragen-Einladungen direkt
                  in Telegram zu beantworten.
                </p>
                <div
                  className="p-3 rounded-lg text-sm"
                  style={{
                    background: "var(--color-muted)",
                    border: "1px solid var(--color-border)",
                  }}
                >
                  <p className="font-medium mb-1">So geht&apos;s:</p>
                  <ol
                    className="list-decimal list-inside space-y-1 text-xs"
                    style={{ color: "var(--color-muted-foreground)" }}
                  >
                    <li>Öffne den Bot in Telegram</li>
                    <li>
                      Sende <code className="px-1 py-0.5 rounded" style={{ background: "var(--color-surface)" }}>/start</code>
                    </li>
                    <li>Klicke auf den Link, den der Bot dir schickt</li>
                  </ol>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
