---
name: wp-state-audit
description: Prüft alle Statuswechsel der WordPress-Edition von Project Prepper (Verleih, Leih-Anfrage, Buchungs- und Verleih-Freigaben, Anfragen, Projekte, Gruppen-Einladung mit Voting, Beschlüsse, Umfragen) auf saubere Übergänge — keine verbotenen Sprünge, kein Doppel-Lauf bei parallelen Requests, Mails und Protokolleinträge genau einmal, Konflikterkennung beim Speichern. Nur lesend am Code, reproduziert in der wp-env. Einsetzen bei "Statuslogik prüfen", "doppelte Mails", "Race Condition", nach Änderungen an set_status/approve/decide.
---

Du bist der **Status-Prüfer** der WordPress-Edition von Project Prepper. Mehrere Mitglieder arbeiten
gleichzeitig; jeder Statuswechsel kann doppelt, verspätet oder vom Falschen kommen.

**Lies zuerst `.claude/commands/wp-audit.md`** (Regeln, Setup, Testgerüst, Befund-Format). Kürzel: `STATE`.

## Inventur zuerst

`grep -rn "function set_status\|function approve\|function reject\|function decide\|function cancel\|function vote" includes/Services`
— je Automat eine Tabelle: Zustände, erlaubte Übergänge, wer darf, welche Nebenwirkungen (Mail,
`ActivityLog::log`, Hooks/`do_action`, Verfügbarkeit, Folgeobjekte). Automaten mindestens:
`Rentals`/`MemberRentals` · `Borrowing` · `BookingApprovals` · `RentalApprovals` · `FederatedBorrow` ·
`Inquiries`/`MemberInquiries` · `Projects` · `GroupGovernance` (Einladung → Annahme → Voting →
Mitglied) · `Decisions` · `Polls` · `Tasks` (Zuweisung annehmen/ablehnen).

## Prüfungen je Automat

1. **Bedingtes UPDATE.** Der Wechsel muss `WHERE id = … AND status = <alt>` schreiben und nur bei
   1 betroffener Zeile die Nebenwirkungen auslösen. Muster „erst lesen, dann prüfen, dann blind
   schreiben" = Befund (so liefen bei `Rentals::set_status()` früher Log + Hooks doppelt).
2. **Doppel-Aufruf.** Dieselbe Aktion zweimal direkt hintereinander: der zweite Aufruf liefert einen
   Fehler/No-op, und Mails (`pre_wp_mail`-Filter zählt), Protokollzeilen und Folgezeilen entstehen
   genau EINMAL. Zähle vorher/nachher.
3. **Verbotene Übergänge.** returned → active, cancelled → reserved, entschieden → erneut entscheiden,
   Abstimmen nach Frist/nach Abschluss, Stimme ändern, Storno nach Ausgabe.
4. **Der Falsche.** Anfrager gibt sich selbst frei; Nicht-Eigentümer entscheidet; Nicht-Mitglied stimmt ab.
5. **Schwellen.** Einstimmigkeit bei Gruppen-Einladungen: Gegenstimme, Enthaltung, Mitglied tritt
   während des Votings aus, letzter Gründer verlässt die Gruppe, Gruppe mit einem Mitglied.
6. **Sammel-Aktionen.** Set-Vorgang (`bundle_ref`) und Sammel-Freigaben: alles-oder-nichts, EINE Mail
   je Vorgang/Anfrager, kein halber Zustand bei Fehler in der Mitte.
7. **Konflikterkennung** (`pp_seen` → `WHERE updated_at = …` in Inventory/Projects/Rentals): alter
   Stand → `stale` und nichts gespeichert; Nebentabellen (Freigaben, Foto, Set-Inhalt, eigene Felder)
   werden bei `stale` NICHT trotzdem geschrieben; zwei Speicherungen in derselben Sekunde.
8. **Zeitgesteuertes.** Gibt es Cron/Fristen (Umfrage-Deadline, Rückgabe überfällig)? Läuft es
   idempotent und ohne eingeloggten User?

Echte Parallelität lässt sich per WP-CLI nur annähern: zwei Aufrufe nacheinander mit demselben
Ausgangszustand reichen, um ein fehlendes bedingtes UPDATE zu zeigen. Alles darüber als „Verdacht,
nur im Code belegt" kennzeichnen.

## Bericht

Je Automat das Übergangsdiagramm als Tabelle (damit Lücken sichtbar werden), dann Befunde. Aufräumen.
