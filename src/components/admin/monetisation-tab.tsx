"use client";

// Monetarisierungs-Tracker im Admin-Panel.
// Statische Roadmap mit Phasen-Status. Quelle der Wahrheit ist die
// Memory-Datei project_monetisation.md plus Git-Log.

type Status = "done" | "open";

type Task = {
  label: string;
  status: Status;
  note?: string;
};

type Phase = {
  title: string;
  status: Status;
  description: string;
  tasks: Task[];
};

const PHASES: Phase[] = [
  {
    title: "Phase 1 — Sicherheit & Recht",
    status: "done",
    description: "Datenbank-Hygiene, RLS-Lücken geschlossen, DSGVO-Pflichten erfüllt.",
    tasks: [
      { label: "Migration 102: project_files RLS schliessen", status: "done" },
      { label: "Migration 103: SET search_path fuer alle SECURITY DEFINER", status: "done" },
      { label: "Migration 104: Audit-FKs auf ON DELETE SET NULL", status: "done" },
      { label: "Migration 105: delete_my_account + export_my_data RPCs", status: "done" },
      { label: "/impressum, /datenschutz, /agb (Templates)", status: "done", note: "Anwaltliche Prüfung steht aus" },
      { label: "Profil: Daten-Export + Account-Löschen", status: "done" },
      { label: ".env.example committed", status: "done" },
    ],
  },
  {
    title: "Phase 2 — Production-Hygiene",
    status: "done",
    description: "Error-Boundaries, Monitoring, CI, Health-Endpoint, Input-Validation.",
    tasks: [
      { label: "Error-Boundaries (error.tsx, global-error.tsx, not-found.tsx)", status: "done" },
      { label: "Vercel Analytics + Speed Insights", status: "done" },
      { label: "GitHub Actions CI (tsc + build)", status: "done" },
      { label: "/api/health Endpoint", status: "done" },
      { label: "Edge Functions: UUID-Validation in 4 Invite-Routes", status: "done" },
      { label: "Sentry für tieferes Error-Tracking", status: "open", note: "DSN nötig, bei Bedarf nachziehen" },
    ],
  },
  {
    title: "Phase 3 — Monetarisierungs-Mechanik",
    status: "open",
    description: "Stripe + Tier-System + Quotas — geschätzt 2-3 Wochen Vollzeit.",
    tasks: [
      { label: "DB: plans, subscriptions, usage_quotas + stripe_customer_id", status: "open" },
      { label: "Stripe Checkout-Session API", status: "open" },
      { label: "Stripe Webhook /api/stripe/webhook", status: "open" },
      { label: "useSubscription() Hook + hasFeature() Helper", status: "open" },
      { label: "Quota-Checks vor Insert (Inventar, Verleih, Gruppen)", status: "open" },
      { label: "/pricing Page mit Tier-Cards", status: "open" },
      { label: "Customer-Portal-Link in Profil/Org-Settings", status: "open" },
      { label: "Upgrade-Banner wenn Limit erreicht", status: "open" },
    ],
  },
  {
    title: "Phase 4 — Polish",
    status: "open",
    description: "Tests, Performance, mobile App-Fähigkeit, Lokalisierung.",
    tasks: [
      { label: "Tests für kritische Pfade (Booking, Approval, Stripe-Webhook)", status: "open" },
      { label: "13 native <img> → next/image umstellen", status: "open" },
      { label: "Dependencies aktualisieren (@supabase/ssr 0.8 → 0.11)", status: "open" },
      { label: "PWA-Manifest + Service Worker", status: "open" },
      { label: "Branding-Cleanup: 'Dunkelstrom' aus email-templates raus", status: "open" },
      { label: "i18n vorbereiten (next-intl)", status: "open" },
    ],
  },
];

const HINTS = [
  {
    title: "Stripe vs Paddle",
    body: "Stripe = global, mehr Dokumentation, niedrigere Gebühren. Paddle = Merchant of Record (übernimmt USt-Abführung in EU/UK), bequemer aber teurer. Für Solo-Founder oft Paddle, für Skalierung Stripe.",
  },
  {
    title: "Anwaltliche Prüfung",
    body: "Die Legal-Pages enthalten Standard-Texte mit eingesetzten Defaults. Vor erstem Euro Umsatz: AGB, Widerrufsregelung und USt-Behandlung von einem Anwalt prüfen lassen (200-500 € Pauschal-Check üblich).",
  },
  {
    title: "DSGVO-Auftragsverarbeitung",
    body: "Mit Supabase (US-Mutter) DPA abschliessen (online verfügbar). Wenn EU-Daten-Garantie wichtig: Supabase Pro mit EU-Region. Vercel hat ebenfalls DPA. Für Telegram/Email-Anbieter pro Org separat.",
  },
  {
    title: "Sentry später",
    body: "Sentry Free-Tier (5k Events/Monat) reicht für den Start. Setup ist 15 Min: npm install @sentry/nextjs, npx @sentry/wizard, DSN in Vercel-Env eintragen.",
  },
];

const StatusBadge = ({ status }: { status: Status }) => (
  <span
    className="text-[10px] font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full"
    style={{
      background: status === "done" ? "var(--color-success-light)" : "var(--color-warning-light)",
      color: status === "done" ? "var(--color-success)" : "var(--color-warning)",
    }}
  >
    {status === "done" ? "Erledigt" : "Offen"}
  </span>
);

function TaskRow({ task }: { task: Task }) {
  return (
    <div
      className="flex items-start gap-3 py-2 px-3 rounded-lg"
      style={{ background: "var(--color-background)" }}
    >
      <span
        className="w-4 h-4 rounded-sm flex items-center justify-center flex-shrink-0 mt-0.5 text-[10px] font-bold"
        style={{
          background: task.status === "done" ? "var(--color-success)" : "var(--color-muted)",
          color: task.status === "done" ? "white" : "var(--color-muted-foreground)",
          border: task.status === "done" ? "none" : "1px solid var(--color-border)",
        }}
      >
        {task.status === "done" ? "✓" : ""}
      </span>
      <div className="flex-1 min-w-0">
        <div className="text-sm" style={{ color: task.status === "done" ? "var(--color-muted-foreground)" : "var(--color-foreground)" }}>
          {task.label}
        </div>
        {task.note && (
          <div className="text-xs mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>
            {task.note}
          </div>
        )}
      </div>
    </div>
  );
}

export function MonetisationTab() {
  const doneCount = PHASES.reduce((sum, p) => sum + p.tasks.filter((t) => t.status === "done").length, 0);
  const totalCount = PHASES.reduce((sum, p) => sum + p.tasks.length, 0);
  const progress = Math.round((doneCount / totalCount) * 100);

  return (
    <div className="space-y-6">
      {/* Header / Progress */}
      <div
        className="p-5 rounded-xl"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}
      >
        <div className="flex items-center justify-between mb-2">
          <h2 className="text-base font-semibold">Roadmap zur Monetarisierung</h2>
          <span className="text-xs font-medium tabular-nums" style={{ color: "var(--color-muted-foreground)" }}>
            {doneCount} / {totalCount} Aufgaben
          </span>
        </div>
        <div
          className="h-2 rounded-full overflow-hidden"
          style={{ background: "var(--color-muted)" }}
        >
          <div
            className="h-full transition-all"
            style={{
              width: `${progress}%`,
              background: "var(--color-success)",
            }}
          />
        </div>
        <p className="text-xs mt-3" style={{ color: "var(--color-muted-foreground)" }}>
          Quelle: memory/project_monetisation.md + Git-Log. Wird manuell gepflegt — bei Code-Änderungen
          den Status in dieser Datei aktualisieren.
        </p>
      </div>

      {/* Phasen */}
      {PHASES.map((phase) => (
        <div
          key={phase.title}
          className="p-5 rounded-xl"
          style={{
            background: "var(--color-surface)",
            border: `1px solid ${phase.status === "done" ? "var(--color-success-light)" : "var(--color-border-light)"}`,
          }}
        >
          <div className="flex items-center justify-between mb-1">
            <h3 className="text-sm font-semibold">{phase.title}</h3>
            <StatusBadge status={phase.status} />
          </div>
          <p className="text-xs mb-4" style={{ color: "var(--color-muted-foreground)" }}>
            {phase.description}
          </p>
          <div className="space-y-1.5">
            {phase.tasks.map((task) => (
              <TaskRow key={task.label} task={task} />
            ))}
          </div>
        </div>
      ))}

      {/* Hints */}
      <div
        className="p-5 rounded-xl"
        style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}
      >
        <h3 className="text-sm font-semibold mb-3">Entscheidungs-Hilfen</h3>
        <div className="space-y-4">
          {HINTS.map((h) => (
            <div key={h.title}>
              <div className="text-sm font-medium mb-1">{h.title}</div>
              <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                {h.body}
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
