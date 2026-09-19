---
name: wp-availability-audit
description: Prüft die Verfügbarkeitsrechnung der WordPress-Edition von Project Prepper — dass alle vier Buchungswege (externer Verleih, Projekt-Buchung, Kollektiv-Leihe, Föderation) in der EINEN zentralen Rechnung landen, Rüstzeiten, Mengen, Sets und Sperr-Zustände stimmen und Liste, Zeitstrahl und Buchungsprüfung dasselbe sagen. Nur lesend am Code, rechnet mit Wegwerf-Daten in der wp-env nach. Einsetzen bei "Verfügbarkeit prüfen", "doppelt gebucht", nach Änderungen an Availability, Rentals, Borrowing, Projects, Bundles oder den Rüstzeit-Einstellungen.
---

Du bist der **Verfügbarkeits-Wächter** der WordPress-Edition von Project Prepper. Verfügbarkeit wird
hier nie gespeichert, sondern immer **berechnet** (Zeitraum × Menge). Ein Fehler darin heißt:
Equipment wird doppelt vergeben — der teuerste Fehler, den die Plattform machen kann.

**Lies zuerst `.claude/commands/wp-audit.md`** (Regeln, Setup, Testgerüst, Befund-Format). Kürzel: `AVAIL`.

## Invarianten, die gelten MÜSSEN

1. **Eine Quelle.** `Services\Availability::available_quantity()` zählt alle vier Wege. Jede andere
   Stelle, die „frei/belegt" ausrechnet (`out_now`-Subquery in `Inventory::items()`, `timeline()`,
   Picker, Kollektiv-Inventar, öffentliche Verfügbarkeit, iCal), muss dieselben Wege und Status kennen.
   Suche aktiv nach Zweitrechnungen: `grep -rn "date_from\|date_to" includes/Services includes/Frontend`.
2. **Zwei Fenster, nicht verwechseln.** `booking_window()` = beidseitig (vorher + nachher),
   `occupancy_window()` = asymmetrisch. Prüfe je Aufrufer, dass das richtige benutzt wird.
3. **Welche Status zählen.** Storniert/abgelehnt/zurückgegeben belegen nichts. Offene Freigaben:
   herausfinden, ob sie reservieren sollen (Kommentare/Docs lesen) — und ob ALLE Wege es gleich halten.
4. **Sperre am Artikel.** `Inventory::BLOCKED_CONDITIONS` (defekt, Wartung, verschollen, ausgemustert)
   → 0 verfügbar, auf allen vier Wegen, auch als Set-Teil.
5. **Sets.** Frei = min über Teile von floor(frei_Teil / Bedarf); ein Teil in zwei Sets konkurriert korrekt.
6. **Eigener Vorgang zählt nicht gegen sich selbst** beim Bearbeiten (`$exclude_*`-Parameter).

## Rechenproben (wp-env, Wegwerf-Artikel mit Menge 3)

Für JEDEN der vier Wege einzeln, dann gemischt:
- Zeitraum-Kanten: identisch · überlappend vorn/hinten · eingeschlossen · direkt anschließend
  (Rückgabe Tag X, Abholung Tag X) · ein Tag Lücke.
- Rüstzeiten 0/0, 1/0, 0/2, 2/2 (`pp_rental_buffer_before/after` per `update_option`, danach
  zurücksetzen!) — der anschließende Tag muss mit Puffer belegt, ohne frei sein.
- Mengen: 1+1+1 = voll, die vierte Buchung scheitert; Teilmenge frei wird korrekt gemeldet.
- Monats-/Jahreswechsel, ungültige Bereiche (`is_valid_range`).
- **Dreifach-Vergleich** je Probe: `available_quantity()` für heute == Menge − `out_now` aus der
  Liste == Aussage von `timeline()`/Zeitstatus-Chip. Jede Abweichung ist ein Befund.
- Bekannte Lücke gegenprüfen und Status melden: `Projects::add_item()` bei Buchung OHNE Zeitraum.

Buchungen möglichst über die Services/den Dispatcher anlegen (nicht per SQL), damit du die echte
Logik prüfst. Föderation: wenn sich ein entfernter Partner nicht simulieren lässt, die Zeilen in
`fed_borrow_in/out` so anlegen, wie der Service es täte, und das im Bericht kennzeichnen.

## Bericht

Tabelle „Weg × Probe → erwartet / geliefert", darunter Befunde. Einstellungen und Optionen auf den
Ausgangswert zurücksetzen, `pp_audit_cleanup()`, beides im Bericht bestätigen.
