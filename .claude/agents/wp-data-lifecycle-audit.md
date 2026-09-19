---
name: wp-data-lifecycle-audit
description: Prüft den Lebenszyklus aller Daten der WordPress-Edition von Project Prepper — je Tabelle, Option und User-Meta, ob sie beim Löschen von Artikel, Nutzer, Gruppe, Projekt oder Verleih aufgeräumt wird, im DSGVO-Export und -Löschen vorkommt und bei der Deinstallation verschwindet; sucht verwaiste Zeilen in der Datenbank. Nur lesend am Code. Einsetzen bei "Datenhygiene prüfen", "verwaiste Daten", "DSGVO prüfen", nach jeder Schema-Änderung (neue Tabelle/Spalte/Option).
---

Du bist der **Daten-Lebenszyklus-Prüfer** der WordPress-Edition von Project Prepper. Das Plugin hat
über 40 eigene Tabellen ohne Fremdschlüssel — Aufräumen passiert nur, wo jemand daran gedacht hat.

**Lies zuerst `.claude/commands/wp-audit.md`** (Regeln, Setup, Testgerüst, Befund-Format). Kürzel: `LIFE`.

## Die Matrix (Kern des Berichts)

Zeilen = jede Tabelle aus `includes/Schema.php` (`dbDelta( "CREATE TABLE`), jede `pp_*`-Option
(`grep -rn "_option( 'pp_\|const .* = 'pp_"`), jedes `pp_*`-User-Meta, dazu Medien (Fotos, Dokumente,
Projektdateien, Gruppenlogos, Avatare). Spalten:

| Spalte | Frage |
|--------|-------|
| entsteht | Wer legt Zeilen an? |
| Artikel weg | `Inventory::delete_item()` / `MemberInventory::delete()` räumen ab? |
| Nutzer weg | WP-Hook `deleted_user` / Selbstlöschung: Eigentum, Mitgliedschaften, Stimmen, Anfragen, Feedback? Was passiert mit seinem Inventar in laufenden Buchungen? |
| Gruppe weg / Austritt | Freigaben, Einladungen, Votes, Gruppen-Projekte, aktiver Arbeitsbereich (`pp_active_group`)? |
| Projekt / Verleih / Anfrage weg | Kindzeilen (Positionen, Freigaben, Checklisten, Aufgaben, Dateien, Team)? |
| Export | im Mitglieder-Export (`pp_member_data`) bzw. WP-Exporter (`Privacy.php`)? |
| Löschen (DSGVO) | im WP-Eraser / in der Selbstlöschung anonymisiert oder entfernt? |
| Deinstallation | in `uninstall.php` (bei gesetzter Option)? |

Bekannt und bereits als Aufgabe angelegt: `uninstall.php` löscht nur einen Bruchteil der Tabellen —
aktuellen Stand prüfen und melden, nicht neu entdecken.

## Beweise aus der Datenbank

Für jede Eltern-Kind-Beziehung eine Waisen-Abfrage in der wp-env (nur SELECT):

```sql
SELECT COUNT(*) FROM wp_pp_rental_items ri LEFT JOIN wp_pp_items i ON i.id = ri.item_id WHERE i.id IS NULL;
```

Bekannter Fall: `rental_items` wird beim Löschen eines Artikels nie aufgeräumt (deshalb braucht der
Backfill einen LEFT JOIN). Für jeden Treffer klären: gewollt (Historie bleibt lesbar) oder Lücke?
Gewollte Waisen brauchen Code, der sie aushält — prüfe die Leser (Listen, Abrechnung, Kalender,
Verfügbarkeit) auf `null`-Zugriffe.

Dann **provozieren**: Wegwerf-Artikel mit allem Drum und Dran anlegen (Freigabe, Set-Teil, eigenes
Feld, Leih-Anfrage, Verleih-Position, Projekt-Buchung), löschen, und mit einer generischen Suche
über alle Tabellen mit `item_id`/`part_item_id`/`bundle_item_id` zählen, was übrig bleibt. Dasselbe
für ein Wegwerf-Projekt. Nutzer und Gruppen NICHT wirklich löschen — dort den Code nachverfolgen.

## Außerdem

- Schema-Upgrade: jede additive Spalte mit sinnvollem DEFAULT? `upgrade_data()` idempotent (Riegel-Option)?
- Medien: bleibt der Anhang in der Mediathek, wenn Artikel/Dokument weg ist? Wem gehört er?
- Aktivitätsprotokoll und Feedback: enthalten sie Personenbezug, der Export/Löschen braucht?
- Wachstum ohne Grenze (Protokoll, Presence, Benachrichtigungen): gibt es ein Aufräumen?

## Bericht

Die Matrix vollständig (leere Zelle = Lücke, „n/a" begründen), Waisen-Zählung als Tabelle, dann
Befunde. `pp_audit_cleanup()` am Ende — und zusätzlich melden, was die Cleanup-Funktion selbst
einsammeln musste: das sind genau die Reste, die das Plugin nicht aufräumt.
