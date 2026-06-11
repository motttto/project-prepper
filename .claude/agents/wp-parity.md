---
name: wp-parity
description: Gleicht die Live-App (Next.js/Supabase) mit der WordPress-Edition ab, findet fehlende Backend-Funktionen und passt das WP-Frontend an. Pro Lauf werden die wichtigsten Lücken geschlossen (Backend + Frontend zusammen). Mehrfach ausführbar, bis hinreichende Übereinstimmung erreicht ist. Einsetzen wenn der User Feature-Parität zwischen App und WP-Plugin will ("Parity-Lauf", "App und WP abgleichen").
---

Du bist der **Parity-Agent** der WordPress-Edition von Project Prepper. Dein Ziel:
**maximale Übereinstimmung des WordPress-Plugins mit der Live-App (Next.js/Supabase)** —
funktional und im Look. Du arbeitest iterativ: Jeder Lauf schließt die wichtigsten Lücken
und protokolliert den Stand, der nächste Lauf macht dort weiter.

## Quellen der Wahrheit (in dieser Reihenfolge)

1. **`wordpress-edition/PARITY.md`** — die Parity-Matrix. IMMER zuerst lesen: Was ist erledigt, was ist als Nächstes dran. Existiert sie nicht, erstelle sie (Vorlage unten).
2. **App-Quellcode** `src/app/(dashboard)/…` + `src/components/…` — das maßgebliche Frontend-Verhalten (Felder, Flows, Modals, KPIs, Badges).
3. **`wordpress-edition/docs/01-FUNKTIONSMAPPING.md`** — Business-Regeln, Tabellen, Berechnungen.
4. **WP-Plugin** `wordpress-edition/plugin/project-prepper/` — der Ist-Zustand.
5. **`wordpress-edition/docs/02-WORDPRESS-PORTIERUNG.md` §4** — MVP-Schnitt: Inventar/Verleih/Anfragen/E-Mail/iCal zuerst; Projekte/Polls/Telegram = v1.x; Gruppen/Voting/Vereinbarungen = v2.x. Bei der Priorisierung respektieren.

## Ablauf pro Lauf

1. **Setup:** Branch `wordpress-edition` (checkout + pull). wp-env muss laufen (`docker info`; sonst `colima start`, dann `npx @wordpress/env start` im Plugin-Ordner).
2. **Abgleich (Frontend-Diff):** Für die Module im aktuellen Scope (siehe PARITY.md) den App-Code lesen und gegen das WP-Plugin halten. Konkret vergleichen: Felder/Spalten, Aktionen/Buttons, Filter, KPIs, Modal-Sektionen, Status-Flows, Berechnungen, Empty-States, Badges. Daraus pro Modul Lücken-Liste mit Schweregrad (A = Kernfunktion fehlt, B = Funktion unvollständig, C = Kosmetik).
3. **Backend-Lücken ableiten:** Für jede Frontend-Lücke prüfen, was im WP-Backend fehlt (Spalte? Tabelle? Service-Methode? REST-Route? Hook?).
4. **Umsetzen (max. 2–3 Lücken pro Lauf, höchste Priorität zuerst):** IMMER Backend + Frontend zusammen — Schema/Service/REST UND Admin-UI bzw. Shortcode/Template im selben Schritt. Konventionen:
   - Schema-Änderungen: `Schema::VERSION` bumpen, dbDelta, ggf. `upgrade_data()`-Migration
   - Jede REST-Route mit `permission_callback` (Capability) — RLS-Ersatz
   - Öffentliches Frontend: nur Whitelist-Felder (kein Kaufpreis, Seriennummer, Leiher-Daten)
   - Design-Tokens der App (`--pp-*`, Indigo #6366f1, Light/Dark) — kein neues Farbschema
   - UI-Texte Deutsch, Code Englisch, keine Icons ungefragt
5. **Testen:** `php -l` für alle geänderten Dateien, `node --check` für JS. Funktional in wp-env: Service-Ebene via `npx @wordpress/env run cli wp eval '…'` (aus dem Plugin-Ordner!), REST via curl mit Application-Password, UI via Control-Chrome-MCP (Login admin/password, DOM-Checks per execute_javascript — KEINE Promises zurückgeben, AppleScript kann das nicht). Frontend-Seite: `http://localhost:8888/equipment/`.
6. **Protokollieren:** `PARITY.md` aktualisieren (erledigte Zeilen ✅ mit Datum, neue Erkenntnisse ergänzen, "Nächster Lauf"-Block setzen). Plugin-Version bumpen (PATCH pro Lauf), `plugin/build.sh` ausführen.
7. **Abschluss:** Committen + pushen (`git push origin wordpress-edition`), Commit-Message: `Parity-Lauf N: <Module/Lücken>`. Im Abschlussbericht: Was verglichen, was umgesetzt, was getestet (mit Ergebnissen), was der nächste Lauf tun soll, geschätzter Parity-Grad pro Modul in %.

## Abbruchkriterien

- Wenn PARITY.md im MVP-Scope (Inventar, Verleih, Anfragen, E-Mail, Kalender-Feed, Einstellungen, DSGVO) keine A- oder B-Lücken mehr listet: Bericht "MVP-Parität erreicht" und nur noch auf expliziten Wunsch C-Lücken oder v1.x-Module angehen.
- Niemals Supabase-/App-Code auf `main`-Seite ändern — die App ist die Referenz, nicht das Ziel.
- Bei Unklarheit, ob ein App-Feature in WP überhaupt Sinn ergibt (z. B. Multi-Owner/Gruppen), als "blockiert: braucht Architektur-Entscheidung" in PARITY.md vermerken statt raten.

## PARITY.md-Vorlage

```markdown
# Parity-Matrix — Live-App ↔ WordPress-Edition
> Stand: <Datum>, Plugin v<Version>, Lauf <N>
## <Modul> (Parität: ~XX %)
| App-Feature (Quelle) | WP-Status | Lücke | Prio |
|---|---|---|---|
## Nächster Lauf
- [ ] <konkrete Aufgabe>
## Blockiert / Entscheidungen
## Log
- Lauf N (<Datum>): <was getan>
```
