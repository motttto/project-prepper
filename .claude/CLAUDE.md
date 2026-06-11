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

- **Zwei Entwicklungsebenen (seit 2026-06-11):**
  - `main` — Haupt-App (Next.js/Supabase), deployt auf Vercel
  - `wordpress-edition` — WordPress-Plugin-Entwicklung (Doku: `wordpress-edition/`)
- **Bei Projektstart IMMER zuerst fragen, welcher Branch bearbeitet werden soll** (main oder wordpress-edition), dann `git checkout` + `git pull` für diesen Branch (siehe `/sync` Skill)
- Innerhalb der Ebenen: keine weiteren Feature-Branches, keine PRs — direkter Push
- **Immer committen + pushen** am Ende einer Session (auf den aktiven Branch)
- User hat wenig Git-Erfahrung — klare Anweisungen geben, nicht zu technisch

## Bekannte Entscheidungen

- UI-Sprache ist Deutsch, Code/Variablen auf Englisch
- Supabase als einziges Backend (kein eigener API-Server)
- `.env.local` wird NICHT committet (enthält Supabase-Keys)
- **WordPress-Edition = Option A** (Plugin-Neuentwicklung in PHP), siehe `wordpress-edition/docs/02-WORDPRESS-PORTIERUNG.md`

## UI-Regeln

- **Keine Icons ungefragt einfügen.** Nicht neben Labels, Buttons, Headlines etc., auch wenn anderswo im Code dasselbe Muster steht. User entscheidet selbst, wo Icons hin sollen.
- Wenn ein Icon zwingend nötig erscheint (z.B. icon-only Button) → vorher fragen.
- Bestehende Icons in Code, den ich bearbeite, bleiben unangetastet — nicht zwanghaft entfernen.

## Session-Log

- **2026-03-03:** GitHub CLI installiert, SSH eingerichtet, Remote synchronisiert (8 Commits vom anderen Rechner geholt), Multi-Machine Workflow in CLAUDE.md dokumentiert
