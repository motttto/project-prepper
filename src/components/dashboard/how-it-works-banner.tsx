"use client";

import { useState, useEffect } from "react";
import {
  IconShield,
  IconX,
  IconProjects,
  IconPackage,
  IconUsers,
  IconCheck,
  IconChevronDown,
} from "@/components/ui/icons";

const STORAGE_KEY = "pp_how_it_works_dismissed_v2";

export function HowItWorksBanner() {
  const [dismissed, setDismissed] = useState(true); // default true bis useEffect prueft
  const [expanded, setExpanded] = useState(false);

  useEffect(() => {
    const stored = localStorage.getItem(STORAGE_KEY);
    setDismissed(stored === "true");
    // Bei neuen Usern automatisch aufgeklappt
    if (stored !== "true") setExpanded(true);
  }, []);

  function handleDismiss() {
    localStorage.setItem(STORAGE_KEY, "true");
    setDismissed(true);
  }

  if (dismissed) return null;

  return (
    <div
      className="rounded-xl mb-6 overflow-hidden"
      style={{
        background: "var(--color-surface)",
        border: "1px solid var(--color-primary)",
      }}
    >
      {/* Header */}
      <button
        onClick={() => setExpanded(!expanded)}
        className="w-full flex items-center gap-3 px-5 py-4 text-left"
        style={{ background: "var(--color-primary-light)" }}
      >
        <IconShield size={20} style={{ color: "var(--color-primary)" }} />
        <div className="flex-1">
          <div className="text-sm font-semibold" style={{ color: "var(--color-primary)" }}>
            So funktioniert Project Prepper
          </div>
          <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            Kollaborative Eventplanung mit transparenter Gewinnverteilung
          </div>
        </div>
        <IconChevronDown
          size={18}
          style={{
            color: "var(--color-primary)",
            transform: expanded ? "rotate(180deg)" : "rotate(0deg)",
            transition: "transform 0.2s",
          }}
        />
      </button>

      {/* Body */}
      {expanded && (
        <div className="p-5 space-y-5">
          {/* Grundprinzip */}
          <div>
            <h3 className="text-sm font-semibold mb-3" style={{ color: "var(--color-foreground)" }}>
              Grundprinzip
            </h3>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div className="flex gap-2">
                <div
                  className="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                  style={{ background: "var(--color-primary-light)" }}
                >
                  <IconProjects size={16} style={{ color: "var(--color-primary)" }} />
                </div>
                <div>
                  <div className="text-xs font-semibold" style={{ color: "var(--color-foreground)" }}>
                    Eigenes Inventar
                  </div>
                  <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    Jeder User besitzt sein eigenes Equipment
                  </div>
                </div>
              </div>
              <div className="flex gap-2">
                <div
                  className="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                  style={{ background: "var(--color-warning-light)" }}
                >
                  <IconPackage size={16} style={{ color: "var(--color-warning)" }} />
                </div>
                <div>
                  <div className="text-xs font-semibold" style={{ color: "var(--color-foreground)" }}>
                    Zu eigenen Bedingungen bereitstellen
                  </div>
                  <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    Tagessatz, Menge, Regeln — du entscheidest
                  </div>
                </div>
              </div>
              <div className="flex gap-2">
                <div
                  className="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                  style={{ background: "var(--color-success-light)" }}
                >
                  <IconUsers size={16} style={{ color: "var(--color-success)" }} />
                </div>
                <div>
                  <div className="text-xs font-semibold" style={{ color: "var(--color-foreground)" }}>
                    Gemeinsam über Gewinn entscheiden
                  </div>
                  <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    Transparent, demokratisch, abgestimmt
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* Workflow */}
          <div>
            <h3 className="text-sm font-semibold mb-3" style={{ color: "var(--color-foreground)" }}>
              So läuft ein Kollektiv-Projekt ab
            </h3>
            <ol className="space-y-2">
              {[
                {
                  title: "Projekt anlegen & Team einladen",
                  desc: "Eigene Mitglieder, Freelancer oder Partner-Orgs",
                },
                {
                  title: "Kooperationsvereinbarung erstellen",
                  desc: "Wer bringt welches Inventar zu welchem Satz? Wer macht welche Arbeit? Wie wird der Gewinn aufgeteilt?",
                },
                {
                  title: "Alle Beteiligten unterschreiben",
                  desc: "Erst wenn alle zustimmen, startet das Projekt verbindlich",
                },
                {
                  title: "Projekt durchführen",
                  desc: "Kosten, Arbeitszeit und Equipment-Nutzung werden erfasst",
                },
                {
                  title: "Gewinn berechnen (Tab Gewinn → 'Aus Vereinbarung')",
                  desc: "System verteilt automatisch nach der vereinbarten Formel",
                },
                {
                  title: "Ausschüttung zur Abstimmung bringen",
                  desc: "Alle Beteiligten stimmen zu — erst dann ist die Auszahlung verbindlich",
                },
              ].map((step, i) => (
                <li key={i} className="flex gap-3">
                  <div
                    className="w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5"
                    style={{ background: "var(--color-primary)", color: "#fff" }}
                  >
                    {i + 1}
                  </div>
                  <div>
                    <div className="text-sm font-medium" style={{ color: "var(--color-foreground)" }}>
                      {step.title}
                    </div>
                    <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                      {step.desc}
                    </div>
                  </div>
                </li>
              ))}
            </ol>
          </div>

          {/* Drei Wege zu Zugang */}
          <div>
            <h3 className="text-sm font-semibold mb-3" style={{ color: "var(--color-foreground)" }}>
              So arbeiten andere bei dir mit
            </h3>
            <div className="space-y-2">
              <div className="flex gap-3 p-3 rounded-lg" style={{ background: "var(--color-muted)" }}>
                <IconUsers size={16} style={{ color: "var(--color-primary)" }} className="flex-shrink-0 mt-0.5" />
                <div className="text-xs">
                  <strong style={{ color: "var(--color-foreground)" }}>Team-Einladung</strong>{" "}
                  <span style={{ color: "var(--color-muted-foreground)" }}>
                    — User wird volles Mitglied deiner Org (Admin → Team)
                  </span>
                </div>
              </div>
              <div className="flex gap-3 p-3 rounded-lg" style={{ background: "var(--color-muted)" }}>
                <IconShield size={16} style={{ color: "var(--color-info)" }} className="flex-shrink-0 mt-0.5" />
                <div className="text-xs">
                  <strong style={{ color: "var(--color-foreground)" }}>Partnerschaft</strong>{" "}
                  <span style={{ color: "var(--color-muted-foreground)" }}>
                    — Zwei Orgs teilen Inventar &amp; Kontakte (Org → Partner einladen)
                  </span>
                </div>
              </div>
              <div className="flex gap-3 p-3 rounded-lg" style={{ background: "var(--color-muted)" }}>
                <IconProjects size={16} style={{ color: "var(--color-success)" }} className="flex-shrink-0 mt-0.5" />
                <div className="text-xs">
                  <strong style={{ color: "var(--color-foreground)" }}>Projekt-Einladung</strong>{" "}
                  <span style={{ color: "var(--color-muted-foreground)" }}>
                    — Freelancer bekommen Zugriff nur auf ein Projekt (Projekt → Mitglieder)
                  </span>
                </div>
              </div>
            </div>
          </div>

          {/* Dismiss */}
          <div
            className="flex items-center justify-between pt-3 border-t"
            style={{ borderColor: "var(--color-border)" }}
          >
            <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
              Diese Einführung kannst du jederzeit ausblenden.
            </p>
            <button
              onClick={handleDismiss}
              className="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium"
              style={{ background: "var(--color-primary)", color: "#fff" }}
            >
              <IconCheck size={12} /> Verstanden, ausblenden
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
