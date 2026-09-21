# WP-Audit — App-Logik der WordPress-Edition prüfen

Sechs Prüf-Agenten (`.claude/agents/wp-*-audit.md`, `wp-flow-test.md`) kontrollieren die Logik des
Plugins. Dieser Skill ist ihr **gemeinsames Regelwerk** — jeder Agent liest ihn zuerst — und die
Anleitung, wie man sie startet.

| Agent | Frage, die er beantwortet |
|-------|---------------------------|
| `wp-access-audit` | Kann jemand etwas sehen oder ändern, das ihm nicht gehört? |
| `wp-flow-test` | Funktionieren die echten Abläufe Ende-zu-Ende (zwei Nutzer)? |
| `wp-availability-audit` | Stimmt die Verfügbarkeit über alle vier Buchungswege und Rüstzeiten? |
| `wp-state-audit` | Sind Statuswechsel sauber — kein Doppel-Lauf, Mails/Protokoll genau einmal? |
| `wp-data-lifecycle-audit` | Wird jede Tabelle aufgeräumt, exportiert und bei Deinstallation gelöscht? |
| `wp-switch-message-audit` | Greifen Feature-Schalter überall, hat jeder Fehler eine verständliche Meldung? |

**Starten:** einzeln per Agent-Tool (`subagent_type: wp-access-audit` …) oder alle sechs parallel in
EINER Nachricht. Bricht ein Lauf am Limit ab: NICHT neu starten, sondern per `SendMessage` an die
Agent-ID fortsetzen (Kontext bleibt erhalten). Ein abgebrochener Lauf heißt „ungeprüft", nie „sauber". `wp-flow-test` legt Daten an — nicht zweimal gleichzeitig laufen lassen.
Reihenfolge bei knapper Zeit: access → flow → availability → state → lifecycle → switch.

---

## Gemeinsame Regeln (für alle sechs)

1. **Nur prüfen, nichts reparieren.** Kein Edit an `wordpress-edition/plugin/`, kein Commit, kein
   Push, kein Release. Der User entscheidet über Fixes. Einzige Schreibziele: Testskripte im
   Scratchpad/`/tmp` und der Bericht.
2. **Nur die lokale wp-env.** Nie die Live-Instanz (project-prepper.voxelwarp.com) ansteuern.
   Keine Logins, keine Passwörter — `wp_set_current_user()` reicht für alles.
3. **Nur Wegwerf-Daten.** Alles, was ein Test anlegt, heißt `ZZ-AUDIT …` und wird am Ende mit
   `pp_audit_cleanup()` entfernt. Nie auf Bestandsartikeln schreiben: das Artikel-Formular postet den
   Wunschzustand ALLER Felder — ein POST mit zwei Feldern leert Tagessatz und hebt Freigaben auf.
4. **Befund nur mit Beleg.** Reproduziert (Testausgabe) oder lückenlos im Code nachverfolgt
   (Aufrufkette mit Datei:Zeile). Alles andere heißt „Verdacht" und steht getrennt.
5. **Bericht NICHT ins Repo.** Das Repo ist öffentlich — Sicherheitsfunde gehören nicht in Commits.
   Bericht nach `wordpress-edition/audits/JJJJ-MM-TT-<agent>.md` (Ordner ist gitignored) und als
   Zusammenfassung in die Abschlussnachricht.
7. **Früh und laufend sichern.** Die Berichtsdatei gleich nach der Inventur anlegen (erste Zeile
   „ZWISCHENSTAND — Lauf noch nicht abgeschlossen") und nach jedem Prüfblock fortschreiben; die Zeile
   erst am Ende entfernen. Ein Lauf kann jederzeit am Nutzungslimit abreißen — beim ersten Großlauf
   (6 Agenten parallel) brachen alle sechs ab, BEVOR ein einziger Bericht existierte.
6. **Vorherigen Bericht lesen**, falls vorhanden: erledigte Funde als „behoben" bestätigen statt neu
   zu melden; offene Funde mit ihrer alten ID weiterführen.

## Parallelbetrieb (mehrere Agenten, eine Datenbank)

- **Eigenes Kürzel setzen**, bevor die Bibliothek geladen wird: `define( 'PP_AUDIT_TAG', 'FLOW' );`
  → Daten heißen `ZZ-AUDIT-FLOW …`, und `pp_audit_cleanup()` räumt NUR die eigenen ab.
  Wegwerf-Objekte, die du ohne `pp_audit_item()` anlegst (Verleih, Projekt, Anfrage, eigenes Feld),
  bekommen denselben Namensanfang `PP_AUDIT_PREFIX`.
- **Nie `update_option()` für Schalter, Puffer oder Einstellungen.** Stattdessen prozess-lokal:
  `pp_audit_option( 'pp_rental_buffer_after', 2 )` bzw. `pp_audit_option( 'pp_features', [...] )` —
  wirkt nur im eigenen Skriptlauf und muss nicht zurückgesetzt werden.
- Testskripte im Container eindeutig benennen (`/tmp/<kürzel>-….php`).
- Bestandsdaten anderer (auch fremde `ZZ-AUDIT-*`) nicht anfassen; tauchen sie in Zählungen auf, herausrechnen.

## Setup

```bash
export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
docker info >/dev/null 2>&1 || colima start
( cd wordpress-edition/plugin/project-prepper && npx --yes @wordpress/env start )   # ~3 min
CLI=$(docker ps --format '{{.Names}}' | grep -E 'cli-1$' | grep -v tests | head -1)
docker cp wordpress-edition/tools/audit/pp-audit-lib.php "$CLI":/tmp/pp-audit-lib.php
```

Testskript schreiben → `docker cp skript.php "$CLI":/tmp/` →
`( cd wordpress-edition/plugin/project-prepper && npx @wordpress/env run cli wp eval-file /tmp/skript.php )`.
Immer Subshell oder absolute Pfade — parallele Bash-Aufrufe teilen sich das Arbeitsverzeichnis.

**Testnutzer:** `1` Betreiber/Admin · `41` portaltest (Gründer Gruppe 23 „Test Kollektiv 5028") ·
`42` memberB (Mitglied 23) · `3` tester1. Für „Fremder ohne Gruppe" einen User wählen, der NICHT in
Gruppe 23 ist (`Groups::user_groups()` prüfen).

## Testgerüst `pp-audit-lib.php`

| Funktion | Zweck |
|----------|-------|
| `pp_dispatch( $user, [ 'pp_do' => '…', … ], $valid_nonce = true )` | Portal-Aktion wie ein Formular-POST; liefert den `pp_msg`-Code (`item_saved`, `forbidden`, `stale`, `error` …) |
| `pp_rest( $user, 'GET', '/items', $body )` | REST als User → `[ status, data ]` |
| `pp_render( $user, 'inventory', $get, $solo )` | Portal-Ansicht als HTML (zum Prüfen, was jemand SIEHT) |
| `pp_audit_item( $owner, 'Name', $qty, $share_group, $requires_approval )` | Wegwerf-Artikel anlegen |
| `pp_audit_track( 'rentals', $id )` | selbst angelegtes Objekt vormerken (`items`/`rentals`/`projects`/`inquiries`/`item_field_defs`/`attachments`) |
| `pp_audit_cleanup()` | alles mit `ZZ-AUDIT` entfernen (abhängige Zeilen generisch über `item_id`/`part_item_id`/`bundle_item_id`/`rental_id`/`project_id`/`inquiry_id`/`field_id`) |

⚠️ **Was du selbst anlegst, sofort `pp_audit_track()`n** — sonst findet das Aufräumen es nicht mehr,
sobald der Test es über den Plugin-Weg gelöscht hat, und seine Kindzeilen bleiben liegen (genau so
blieben im ersten Großlauf Leih-Anfragen, Föderations- und Team-Zeilen zurück). `pp_audit_item()`
merkt sich selbst vor. Hochgeladene Medien als `attachments` vormerken — das Plugin räumt sie nicht ab.

Der Dispatcher ist `MemberPortal::handle_collective_action()` (ein `pp_do` je Aktion, Nonce
`pp_collective`). Weitere Einstiege: die übrigen `admin_post_*`-Handler und `includes/Rest/*`.
Private Methoden bei Bedarf per `ReflectionMethod` aufrufen.

## Befund-Format

```markdown
### ACC-03 · A · Fremder liest Verleih-Details über REST
- **Wo:** includes/Rest/RentalsController.php:88
- **Szenario:** User 3 (kein Mitglied) → GET /rentals/12 → 200 mit Leiher-Adresse
- **Beleg:** <Testausgabe oder Aufrufkette>
- **Erwartet:** 403/404
- **Vorschlag:** Sichtbarkeit über MemberRentals::visible() prüfen
```

Schweregrad: **A** Datenleck, Datenverlust, falsches Geld/Verfügbarkeit · **B** falsches Verhalten
mit Umweg · **C** unsauber, aber folgenlos. IDs mit Agent-Kürzel: ACC, FLOW, AVAIL, STATE, LIFE, SWITCH.

Bericht-Kopf: Datum, Plugin-Version, Schema-Version, geprüfter Commit (`git rev-parse --short HEAD`),
was geprüft wurde UND was nicht (ehrlich — ein ungeprüfter Bereich ist kein sauberer Bereich).
Bricht ein Lauf ab (Limit, Fehler), steht das als erste Zeile im Bericht.
