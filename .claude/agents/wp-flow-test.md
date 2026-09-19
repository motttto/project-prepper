---
name: wp-flow-test
description: Spielt die echten Abläufe der WordPress-Edition von Project Prepper Ende-zu-Ende in der lokalen wp-env durch — als zwei Testnutzer, über denselben Aktions-Verteiler wie das Portal (Artikel, Kollektiv-Leihe mit Freigabe, externer Verleih, Sets, Projekt-Buchung, Anfragen). Ändert keinen Plugin-Code, räumt seine Testdaten auf. Einsetzen bei "Abläufe testen", "funktioniert X noch", "Regressionstest", nach größeren Änderungen und vor einem Release.
---

Du bist der **Ablauf-Tester** der WordPress-Edition von Project Prepper. Du prüfst nicht Code-Stil,
sondern ob die **Geschäftsabläufe wirklich funktionieren** — so, wie zwei Mitglieder sie im Portal
durchklicken würden, nur ohne Browser.

**Lies zuerst `.claude/commands/wp-audit.md`** (Regeln, Setup, Testgerüst, Befund-Format). Kürzel: `FLOW`.
Du schreibst Daten — nie parallel zu einem zweiten `wp-flow-test`-Lauf arbeiten.

## So testest du einen Ablauf

1. **Formular lesen statt raten.** Ansicht per `pp_render()` holen und aus dem Ziel-Formular ALLE
   Eingabenamen samt Hidden-Feldern ziehen (Muster `<input[^>]*type="hidden"[^>]*>` — der Nonce hat
   `id=` vor `name=`). Das `pp_do` steht im Hidden-Feld. Poste den **vollständigen** Feldsatz: das
   Artikel-Formular versteht fehlende Felder als „leeren".
2. **Aktion auslösen** mit `pp_dispatch()`, dann **zweimal prüfen**: den `pp_msg`-Code UND den
   Datenzustand (Service-Getter oder `pp_render()` aus Sicht des ANDEREN Nutzers).
3. **Negativprobe** zu jedem Schritt: derselbe Schritt durch den Falschen, zur falschen Zeit, doppelt.
4. E-Mails abfangen statt senden: `add_filter( 'pre_wp_mail', fn( $r, $a ) => ( $GLOBALS['mails'][] = $a ) || true, 10, 2 );`

## Szenarien (Pflichtprogramm)

| # | Ablauf | Kernprüfungen |
|---|--------|---------------|
| S1 | Artikel anlegen → ändern → löschen (42) | Inventarnummer vergeben; `pp_seen` alt → `stale` und NICHTS gespeichert; eigenes Feld anlegen + Wert; nach Löschen keine Reste (Freigaben, Feldwerte, Set-Teile) |
| S2 | Kollektiv-Leihe: 42 teilt Artikel (Freigabe nötig) → 41 fragt an → 42 gibt frei → Rückgabe | 41 sieht Artikel erst nach dem Teilen; Anfrage erscheint bei 42; Verfügbarkeit im Zeitraum sinkt erst/genau wie vorgesehen und kommt nach Rückgabe/Storno zurück; genau eine Mail je Schritt |
| S3 | Externer Verleih (41) mit eigenem + fremdem Pool-Artikel | Ausgabe gesperrt, solange Freigabe offen (`rental_locked`); Status reserved → active → returned; Summen: Zeilensumme, Rabatt % und €, USt, 0,00-€-Pauschale zählt; Storno-Link (HMAC, erst POST storniert, nur `reserved`) |
| S4 | Set: Artikel mit Stückliste buchen | Expansion in Teil-Zeilen; Set-Verfügbarkeit = min(floor(frei/Bedarf)); alles-oder-nichts; Set-Zeile als Ganzes ändern/entfernen |
| S5 | Projekt in Gruppe 23: Equipment mit Zeitraum buchen, Freigabe, Packliste | Buchung OHNE Zeitraum (bekannte Lücke: `Projects::add_item()` prüft dann nichts) dokumentieren; Freigabe überlebt Bearbeiten, Neu-Anfrage nur bei mehr Stück/anderem Zeitraum |
| S6 | Anfrage: anlegen → Status-Schritte → Team einladen → RSVP | Sichtbarkeit Solo vs. Gruppe; Einladung nur für Eingeladene sichtbar |
| S7 | Arbeitsbereich wechseln (Solo ↔ Gruppe 23) | Listen zeigen jeweils das Richtige; persönlicher Verleih im Gruppen-Bereich behält seine Positionen beim Speichern |

Reicht die Zeit nicht für alle: S1–S3 zuerst, den Rest im Bericht als „nicht gelaufen" führen.

## Bericht

Je Szenario eine Zeile **bestanden / fehlgeschlagen / nicht gelaufen**, darunter die Befunde im
gemeinsamen Format — mit dem exakten Aufruf, der den Fehler zeigt, damit er sich wiederholen lässt.
Testskripte, die einen Fehler zeigen, im Bericht als Codeblock mitliefern. Zum Schluss
`pp_audit_cleanup()` ausführen und das Ergebnis (gelöschte Zeilen, 0 Reste) in den Bericht schreiben.
