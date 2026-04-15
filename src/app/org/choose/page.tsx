"use client";

import { useRouter } from "next/navigation";
import Link from "next/link";
import {
  IconZap,
  IconUsers,
  IconMail,
  IconChevronRight,
  IconBuilding,
} from "@/components/ui/icons";

export default function OrgChoosePage() {
  const router = useRouter();

  return (
    <div className="w-full max-w-xl space-y-6">
      {/* Header */}
      <div className="text-center mb-2">
        <div
          className="w-14 h-14 rounded-xl flex items-center justify-center mx-auto mb-4"
          style={{ background: "var(--color-primary-light)" }}
        >
          <IconBuilding size={28} style={{ color: "var(--color-primary)" }} />
        </div>
        <h1 className="text-2xl font-bold" style={{ color: "var(--color-foreground)" }}>
          Wie möchtest du starten?
        </h1>
        <p className="text-sm mt-2" style={{ color: "var(--color-muted-foreground)" }}>
          Du kannst eine eigene Organisation gründen oder einer bestehenden beitreten.
        </p>
      </div>

      {/* Option 1: Eigene Org */}
      <Link
        href="/org/new"
        className="block rounded-xl p-6 transition-all"
        style={{
          background: "var(--color-surface)",
          border: "2px solid var(--color-border)",
          boxShadow: "var(--shadow-sm)",
        }}
        onMouseEnter={(e) => (e.currentTarget.style.borderColor = "var(--color-primary)")}
        onMouseLeave={(e) => (e.currentTarget.style.borderColor = "var(--color-border)")}
      >
        <div className="flex items-start gap-4">
          <div
            className="w-11 h-11 rounded-lg flex items-center justify-center flex-shrink-0"
            style={{ background: "var(--color-primary-light)" }}
          >
            <IconZap size={22} style={{ color: "var(--color-primary)" }} />
          </div>
          <div className="flex-1 min-w-0">
            <h2 className="text-base font-semibold mb-1" style={{ color: "var(--color-foreground)" }}>
              Eigene Organisation gründen
            </h2>
            <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              Du wirst Admin deiner eigenen Org. Du kannst Team einladen, Partner verbinden
              oder allein arbeiten. Eigenes Inventar und eigene Projekte.
            </p>
          </div>
          <IconChevronRight
            size={20}
            style={{ color: "var(--color-muted-foreground)", flexShrink: 0 }}
          />
        </div>
      </Link>

      {/* Option 2: Einladungs-Link */}
      <div
        className="rounded-xl p-6"
        style={{
          background: "var(--color-surface)",
          border: "2px solid var(--color-border)",
          boxShadow: "var(--shadow-sm)",
        }}
      >
        <div className="flex items-start gap-4">
          <div
            className="w-11 h-11 rounded-lg flex items-center justify-center flex-shrink-0"
            style={{ background: "var(--color-success-light)" }}
          >
            <IconMail size={22} style={{ color: "var(--color-success)" }} />
          </div>
          <div className="flex-1 min-w-0">
            <h2 className="text-base font-semibold mb-1" style={{ color: "var(--color-foreground)" }}>
              Einladung zu einer Organisation
            </h2>
            <p className="text-sm mb-3" style={{ color: "var(--color-muted-foreground)" }}>
              Du wurdest eingeladen? Der Admin muss dich freischalten, bevor du Zugriff
              bekommst. Bei Fragen wende dich an den Admin deiner Organisation.
            </p>
            <div
              className="text-xs p-3 rounded-lg"
              style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}
            >
              💡 Hast du einen Einladungs-Link per E-Mail bekommen? Klicke einfach auf den
              Link in der Mail — du wirst automatisch zur richtigen Stelle geführt.
            </div>
          </div>
        </div>
      </div>

      {/* Option 3: Partnerschaft */}
      <div
        className="rounded-xl p-6"
        style={{
          background: "var(--color-surface)",
          border: "2px solid var(--color-border)",
          boxShadow: "var(--shadow-sm)",
        }}
      >
        <div className="flex items-start gap-4">
          <div
            className="w-11 h-11 rounded-lg flex items-center justify-center flex-shrink-0"
            style={{ background: "var(--color-info-light)" }}
          >
            <IconUsers size={22} style={{ color: "var(--color-info)" }} />
          </div>
          <div className="flex-1 min-w-0">
            <h2 className="text-base font-semibold mb-1" style={{ color: "var(--color-foreground)" }}>
              Partnerschaft zwischen Orgs
            </h2>
            <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              Du hast bereits eine eigene Org? Dann kannst du nach der Gründung
              Partnerschaften mit anderen Orgs eingehen — Inventar &amp; Kontakte teilen,
              ohne die Org zu wechseln.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
