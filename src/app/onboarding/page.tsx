"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import {
  IconShield,
  IconCheck,
  IconPackage,
  IconUsers,
  IconChevronRight,
  IconChevronLeft,
  IconProjects,
} from "@/components/ui/icons";

// Versionsnummer erhoehen, wenn sich die Kollaborationsbasis aendert
const COLLAB_VERSION = "1.0";

type Step = {
  title: string;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  icon: any;
  color: string;
  content: React.ReactNode;
};

export default function OnboardingPage() {
  const router = useRouter();
  const supabase = createClient();
  const [stepIndex, setStepIndex] = useState(0);
  const [accepted, setAccepted] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const steps: Step[] = [
    {
      title: "Willkommen bei Project Prepper",
      icon: IconShield,
      color: "var(--color-primary)",
      content: (
        <div className="space-y-3">
          <p className="text-sm" style={{ color: "var(--color-foreground)" }}>
            Project Prepper ist eine <strong>kollaborative Planungs-App für Event- und
            Veranstaltungs-Crews</strong>. Bevor du loslegst, erklären wir dir kurz die
            Grundidee und die Spielregeln.
          </p>
          <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            Die nächsten drei Schritte musst du lesen und bestätigen. Am Ende stimmst du
            der Kollaborationsbasis zu — erst dann kannst du dein Inventar teilen oder
            an Kooperationsvereinbarungen teilnehmen.
          </p>
        </div>
      ),
    },
    {
      title: "Dein Inventar bleibt dein Eigentum",
      icon: IconPackage,
      color: "var(--color-warning)",
      content: (
        <div className="space-y-3 text-sm" style={{ color: "var(--color-foreground)" }}>
          <p>
            Du legst dein Equipment in <strong>deinem eigenen Inventar</strong> an. Es
            gehört dir, wird nicht kollektiviert, und du bestimmst, ob, wann und zu
            welchen Bedingungen andere es nutzen dürfen.
          </p>
          <ul className="space-y-2 pl-4">
            <li className="flex gap-2">
              <IconCheck size={16} style={{ color: "var(--color-success)" }} className="flex-shrink-0 mt-0.5" />
              <span>Du setzt den <strong>Tagessatz</strong> bei jeder Bereitstellung selbst</span>
            </li>
            <li className="flex gap-2">
              <IconCheck size={16} style={{ color: "var(--color-success)" }} className="flex-shrink-0 mt-0.5" />
              <span>Du kannst <strong>Notizen / Bedingungen</strong> festlegen (Haftung, Bedienung, etc.)</span>
            </li>
            <li className="flex gap-2">
              <IconCheck size={16} style={{ color: "var(--color-success)" }} className="flex-shrink-0 mt-0.5" />
              <span>Beim Austritt aus einem Projekt gilt die <strong>vereinbarte Rückgabefrist</strong></span>
            </li>
          </ul>
        </div>
      ),
    },
    {
      title: "Gewinn wird kollektiv entschieden",
      icon: IconUsers,
      color: "var(--color-success)",
      content: (
        <div className="space-y-3 text-sm" style={{ color: "var(--color-foreground)" }}>
          <p>
            Wenn du an einem Kollektiv-Projekt teilnimmst, wird vor Projektstart eine{" "}
            <strong>Kooperationsvereinbarung</strong> geschlossen. Alle Beteiligten
            unterschreiben — erst dann startet das Projekt verbindlich.
          </p>
          <ul className="space-y-2 pl-4">
            <li className="flex gap-2">
              <IconCheck size={16} style={{ color: "var(--color-success)" }} className="flex-shrink-0 mt-0.5" />
              <span>Verteilungs-Formel (Stunden / Inventar / Kapital / Festbetrag) ist <strong>vorher festgelegt</strong></span>
            </li>
            <li className="flex gap-2">
              <IconCheck size={16} style={{ color: "var(--color-success)" }} className="flex-shrink-0 mt-0.5" />
              <span>Jede Ausschüttung wird <strong>demokratisch abgestimmt</strong> — meist einstimmig</span>
            </li>
            <li className="flex gap-2">
              <IconCheck size={16} style={{ color: "var(--color-success)" }} className="flex-shrink-0 mt-0.5" />
              <span>Änderungen an der Vereinbarung brauchen die <strong>Zustimmung aller</strong></span>
            </li>
          </ul>
          <p style={{ color: "var(--color-muted-foreground)" }}>
            Das schützt dich davor, im Nachhinein benachteiligt zu werden — und
            verpflichtet dich, Vereinbarungen einzuhalten.
          </p>
        </div>
      ),
    },
    {
      title: "Kollaborationsbasis — deine Zustimmung",
      icon: IconProjects,
      color: "var(--color-primary)",
      content: (
        <div className="space-y-3 text-sm" style={{ color: "var(--color-foreground)" }}>
          <p>Mit deiner Zustimmung erkennst du an:</p>
          <ol className="space-y-2 pl-4 list-decimal">
            <li>
              <strong>Eigentum:</strong> Inventar-Einträge in der App gehören dem jeweiligen
              User. Die Plattform überträgt kein Eigentum.
            </li>
            <li>
              <strong>Verbindlichkeit:</strong> Eine unterschriebene Kooperationsvereinbarung
              ist zwischen den Beteiligten verbindlich. Änderungen nur via Amendment.
            </li>
            <li>
              <strong>Transparenz:</strong> Einnahmen, Kosten und Gewinnverteilung sind für
              alle Beteiligten einsehbar.
            </li>
            <li>
              <strong>Faire Beteiligung:</strong> Die Verteilungsformel wird vor Projektstart
              vereinbart und gilt ohne Hintertüren.
            </li>
            <li>
              <strong>Exit-Respekt:</strong> Bei Austritt aus Projekten gelten die
              vereinbarten Exit-Regeln (Frist, Inventar-Rückgabe, ggf. Gewinnverzicht).
            </li>
            <li>
              <strong>Keine Beratung:</strong> Project Prepper bietet keine Rechts-, Steuer-
              oder Finanzberatung. Für rechtliche Sicherheit sind die Beteiligten selbst
              verantwortlich.
            </li>
          </ol>

          <label
            className="flex items-start gap-3 p-4 rounded-lg cursor-pointer mt-4"
            style={{ background: "var(--color-muted)", border: `2px solid ${accepted ? "var(--color-success)" : "var(--color-border)"}` }}
          >
            <input
              type="checkbox"
              checked={accepted}
              onChange={(e) => setAccepted(e.target.checked)}
              className="mt-0.5"
              style={{ accentColor: "var(--color-success)" }}
            />
            <div>
              <div className="text-sm font-semibold" style={{ color: "var(--color-foreground)" }}>
                Ich stimme der Kollaborationsbasis (Version {COLLAB_VERSION}) zu.
              </div>
              <div className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                Ohne Zustimmung kannst du die Plattform im Solo-Modus nutzen, aber kein
                Inventar teilen oder an Kooperationsvereinbarungen teilnehmen.
              </div>
            </div>
          </label>
        </div>
      ),
    },
  ];

  const currentStep = steps[stepIndex];
  const isLast = stepIndex === steps.length - 1;
  const canProceed = isLast ? accepted : true;

  async function handleFinish() {
    if (!accepted) return;
    setSaving(true);
    setError("");

    const { data: { user } } = await supabase.auth.getUser();
    if (!user) {
      setError("Nicht eingeloggt");
      setSaving(false);
      return;
    }

    const ts = new Date().toISOString();
    const userAgent = navigator.userAgent;

    const [profUpdate, historyInsert] = await Promise.all([
      supabase
        .from("profiles")
        .update({
          collaboration_accepted_version: COLLAB_VERSION,
          collaboration_accepted_at: ts,
        })
        .eq("id", user.id),
      supabase
        .from("collaboration_acceptances")
        .insert({
          profile_id: user.id,
          version: COLLAB_VERSION,
          accepted_at: ts,
          user_agent: userAgent,
        }),
    ]);

    if (profUpdate.error) {
      setError(profUpdate.error.message);
      setSaving(false);
      return;
    }
    // historyInsert Error ist nicht kritisch, aber loggen
    if (historyInsert.error) console.warn("History insert failed:", historyInsert.error);

    router.push("/dashboard");
    router.refresh();
  }

  const StepIcon = currentStep.icon;

  return (
    <div
      className="min-h-screen flex items-center justify-center p-4"
      style={{ background: "var(--color-background)" }}
    >
      <div
        className="w-full max-w-xl rounded-xl p-8"
        style={{
          background: "var(--color-surface)",
          boxShadow: "var(--shadow-lg)",
        }}
      >
        {/* Progress */}
        <div className="flex gap-1 mb-6">
          {steps.map((_, i) => (
            <div
              key={i}
              className="flex-1 h-1 rounded-full"
              style={{ background: i <= stepIndex ? "var(--color-primary)" : "var(--color-muted)" }}
            />
          ))}
        </div>

        {/* Header */}
        <div className="flex items-center gap-3 mb-5">
          <div
            className="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0"
            style={{ background: `${currentStep.color}20` }}
          >
            <StepIcon size={22} style={{ color: currentStep.color }} />
          </div>
          <div>
            <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
              Schritt {stepIndex + 1} von {steps.length}
            </div>
            <h1 className="text-lg font-bold" style={{ color: "var(--color-foreground)" }}>
              {currentStep.title}
            </h1>
          </div>
        </div>

        {/* Content */}
        <div className="mb-6 min-h-[200px]">{currentStep.content}</div>

        {/* Error */}
        {error && (
          <div
            className="text-sm px-3 py-2 rounded-lg mb-4"
            style={{ background: "var(--color-destructive-light)", color: "var(--color-destructive)" }}
          >
            {error}
          </div>
        )}

        {/* Footer */}
        <div className="flex items-center justify-between pt-4 border-t" style={{ borderColor: "var(--color-border)" }}>
          <button
            onClick={() => setStepIndex(stepIndex - 1)}
            disabled={stepIndex === 0 || saving}
            className="flex items-center gap-1 px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-30"
            style={{ color: "var(--color-muted-foreground)" }}
          >
            <IconChevronLeft size={14} /> Zurück
          </button>

          {!isLast ? (
            <button
              onClick={() => setStepIndex(stepIndex + 1)}
              disabled={!canProceed}
              className="flex items-center gap-1 px-5 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
              style={{ background: "var(--color-primary)" }}
            >
              Weiter <IconChevronRight size={14} />
            </button>
          ) : (
            <button
              onClick={handleFinish}
              disabled={!accepted || saving}
              className="flex items-center gap-1 px-5 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
              style={{ background: "var(--color-success)" }}
            >
              <IconCheck size={14} /> {saving ? "Speichere..." : "Zustimmen & starten"}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
