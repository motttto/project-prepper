# Claude Code Memory

> Session-übergreifende Notizen und Learnings. Wird automatisch ergänzt.

## Rechner-Setup

| Rechner | Auth | Pfad | Status |
|---------|------|------|--------|
| Mo MacStudio | SSH (`id_ed25519`) | — | Hauptrechner |
| Zweiter Rechner | SSH (`id_ed25519`) | `/Users/i/Desktop/project-prepper` | Eingerichtet 2026-03-03 |

- Beide Rechner nutzen SSH-Auth (`git@github.com:motttto/project-prepper.git`)
- `gh` CLI ist auf dem zweiten Rechner unter `~/bin/gh` installiert (kein Homebrew)
- Kein Homebrew auf dem zweiten Rechner (kein sudo-Zugang)

## Workflow-Hinweise

- **Kein Branch-Workflow** — alles direkt auf `main`
- **Immer `git pull` vor Arbeitsbeginn** (siehe `/sync` Skill)
- **Immer committen + pushen** am Ende einer Session
- User hat wenig Git-Erfahrung — klare Anweisungen geben, nicht zu technisch

## Bekannte Entscheidungen

- UI-Sprache ist Deutsch, Code/Variablen auf Englisch
- Supabase als einziges Backend (kein eigener API-Server)
- Keine Feature-Branches, keine PRs — direkter Push auf main
- `.env.local` wird NICHT committet (enthält Supabase-Keys)

## UI-Regeln

- **Keine Icons ungefragt einfügen.** Nicht neben Labels, Buttons, Headlines etc., auch wenn anderswo im Code dasselbe Muster steht. User entscheidet selbst, wo Icons hin sollen.
- Wenn ein Icon zwingend nötig erscheint (z.B. icon-only Button) → vorher fragen.
- Bestehende Icons in Code, den ich bearbeite, bleiben unangetastet — nicht zwanghaft entfernen.

## Session-Log

- **2026-03-03:** GitHub CLI installiert, SSH eingerichtet, Remote synchronisiert (8 Commits vom anderen Rechner geholt), Multi-Machine Workflow in CLAUDE.md dokumentiert
