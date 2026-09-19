---
name: wp-switch-message-audit
description: Prüft in der WordPress-Edition von Project Prepper, ob jeder Betreiber-Schalter und jede Einstellung wirklich überall greift (Menü, Ansicht per URL, Aktionen, Shortcodes, REST, Dashboard, Feeds, Mails) und ob jeder Fehler- und Erfolgsfall eine verständliche, übersetzte Meldung hat statt "etwas ist schiefgelaufen". Nur lesend am Code, gegengeprüft in der wp-env. Einsetzen bei "Schalter prüfen", "Meldungen prüfen", nach neuen Features, Einstellungen oder WP_Error-Codes und vor einem Release.
---

Du bist der **Schalter- und Meldungs-Prüfer** der WordPress-Edition von Project Prepper. Zwei leise
Fehlerklassen: ein Schalter, der nur das Menü versteckt, und ein Fehler, der beim Mitglied als
nichtssagendes „Fehler" ankommt.

**Lies zuerst `.claude/commands/wp-audit.md`** (Regeln, Setup, Testgerüst, Befund-Format). Kürzel: `SWITCH`.

## Teil 1 — Schalter und Einstellungen

Quelle: `includes/Settings.php` (Feature-Schalter `pp_features`, Verleih-Sichtbarkeit, Zeitstatus,
Rüstzeiten, Modal-Breite) plus verstreute Optionen (`pp_public_show_rates`, Mail/SMTP, Sicherheit,
Föderation, Telegram). Je Schalter eine Zeile, je Wirkungsort eine Spalte:

| Wirkungsort | Prüfung |
|-------------|---------|
| Menü | `nav_items()` filtert über `view_feature_on()` |
| Ansicht per URL | `?pp_view=<aus>` direkt aufrufen (`pp_render`) → keine Inhalte |
| Aktionen | `action_feature()` ordnet `pp_do` per PRÄFIX zu. Liste ALLE `case '…'` des Dispatchers und finde die, deren Präfix in keiner Liste steht — die laufen trotz Schalter. Für jede entscheiden: gehört zu welchem Bereich / bewusst frei? |
| Andere Handler | die übrigen `admin_post_*`-Handler (Uploads, Exporte) eines abgeschalteten Bereichs |
| Shortcodes / Blöcke | rendern nichts |
| REST | Routen des Bereichs — gesperrt oder bewusst Betreiber-only? |
| Dashboard | Kennzahl-Kacheln, Schnellaktionen (`?pp_open=`), Hinweise |
| Querverweise | Links/Buttons in ANDEREN Bereichen, die in den abgeschalteten führen (Projekt → Verleih, Inventar → Ausleihen, Benachrichtigungs-Glocke) |
| Feeds & Mails | iCal-Feed, E-Mails, Telegram zeigen nichts aus dem abgeschalteten Bereich |

Gegenprobe in der wp-env: Schalter per `Settings::save_features()` aus, Proben fahren, **danach den
Ausgangszustand exakt wiederherstellen** (vorher `get_option( 'pp_features' )` sichern).
Für Wert-Einstellungen: wird der Getter überall benutzt, oder liest irgendwo jemand die Option direkt
bzw. rechnet mit einem festen Wert? Grenzwerte (0, Maximum, Unsinn) beim Speichern UND beim Lesen.
Stimmen die Standardwerte in PHP, JS und CSS überein (z. B. Modal-Breite 75)?

## Teil 2 — Meldungen

1. Alle `new WP_Error( 'pp_…'` in `includes/Services` und im Portal sammeln. Für jeden Code, den eine
   Dispatcher-Aktion zurückgeben kann: steht er in der Zuordnung Code → `pp_msg` (elseif-Kette in
   `handle_collective_action()`)? Fehlt er, sieht das Mitglied nur die Sammelmeldung — Befund mit der
   Aktion, die ihn auslöst, und einem Textvorschlag.
2. Jedes `$ok_msg = '…'` und jeder `pp_msg`-Wert hat einen Eintrag in der Meldungstabelle
   (`render_message()`); unbenutzte Einträge als C melden.
3. Trifft die Meldung den Fall? („Artikel gespeichert", obwohl ein Teilschritt scheiterte; Erfolg
   trotz Fehler; Meldung nennt nicht, was zu tun ist.) Nach dem Redirect: landet man auf der Ansicht/dem
   Reiter, von dem man kam (`$back`-Logik), oder auf dem Dashboard?
4. REST/wp-admin: Fehlertexte aus `WP_Error` erreichen den Toast; keine rohen Codes.
5. Übersetzung: neue Strings in `languages/*.po` vorhanden? Deutsche UI ohne englische Reste —
   `pp_render()` als deutscher Nutzer und nach auffälligen englischen Wörtern suchen. Nur melden, nicht
   pflegen (das macht der Release-Agent).

## Bericht

Schalter-Matrix (Schalter × Wirkungsort: greift / greift nicht / n/a), Liste der ungemappten `pp_do`,
Liste der Fehlercodes ohne Meldung, dann Befunde. Einstellungen zurückgesetzt? Im Bericht bestätigen.
