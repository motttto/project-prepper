---
name: wp-release
description: Veröffentlicht ein neues Release der WordPress-Edition von Project Prepper. Bumpt die Version, pflegt Changelog + i18n, prüft mit Plugin Check, baut das ZIP, committet/pusht auf wordpress-edition und legt ein GitHub-Release mit angehängtem ZIP an (damit der In-Plugin-Updater es ausliefert). Einsetzen wenn der User "Release", "Version veröffentlichen", "neues Release bauen" o.ä. sagt.
---

Du bist der **Release-Agent** der WordPress-Edition von Project Prepper. Dein Ziel:
aus dem aktuellen Stand auf `wordpress-edition` ein **sauberes, installier- und
auslieferbares Release** machen — Version, Changelog, i18n, Plugin Check, Build,
Commit/Push und **GitHub-Release mit angehängtem ZIP**. Letzteres ist entscheidend:
der In-Plugin-Updater (`includes/Updater.php`) zieht genau dieses Release-Asset.

## Vorbedingungen (zuerst prüfen)

1. Branch `wordpress-edition` ausgecheckt + `git pull origin wordpress-edition`.
2. Arbeitsbaum: alle gewünschten Änderungen sind da. Uncommittete Reste sind ok —
   du committest sie mit. Nichts Fremdes mitnehmen (kurz `git status` zeigen).
3. wp-env läuft (für Plugin Check, `.mo`-Bau, Tests): `docker info` bzw.
   `colima start` + `npx @wordpress/env start` im Ordner
   `wordpress-edition/plugin/project-prepper`.
4. `gh auth status` ok (für das GitHub-Release).

Arbeitsverzeichnis für alle Plugin-/wp-cli-Befehle: `wordpress-edition/plugin/project-prepper`.

## Zielversion bestimmen

- Wenn der User eine Version nennt, nimm die. Sonst **MINOR** erhöhen
  (Projekt-Konvention: `0.63.0 → 0.64.0` pro Feature; PATCH nur für reine Fixes).
- Aktuelle Version steht in `project-prepper.php` (`Version:` Header + `PP_VERSION`).

## Ablauf

1. **Version bumpen** (alle drei Stellen müssen übereinstimmen):
   - `project-prepper.php`: Header-`Version:` **und** `define( 'PP_VERSION', '…' )`.
   - `readme.txt`: `Stable tag: …`.
   - Prüfe per `grep`, dass keine alte Version übrig bleibt.
2. **Changelog** in `readme.txt` unter `== Changelog ==` einen neuen Block
   `= X.Y.Z =` ganz oben einfügen (knappe, nutzerorientierte Bullet-Punkte —
   was der Betreiber/Nutzer merkt, kein interner Jargon). Dieser Text landet auch
   im GitHub-Release und im „Details"-Modal des Updaters.
3. **i18n** (nur wenn neue/ geänderte `__()/_e()`-Strings):
   - `wp i18n make-pot … languages/project-prepper.pot --slug=project-prepper`
   - `wp i18n update-po … project-prepper.pot … project-prepper-de_DE.po`
   - Neue Strings auf Deutsch übersetzen (offene `msgstr ""` füllen).
   - **`.mo` per WP-POMO bauen, NICHT `msgfmt`/`make-mo`:** kleines PHP-Skript im
     Plugin-Ordner ablegen, das `PO`/`MO` aus `wp-includes/pomo/` lädt, die `.po`
     importiert und nach `.mo` exportiert; via `wp eval-file` ausführen; Skript löschen.
   - **JS-Strings (admin.js):** `wp i18n make-json … project-prepper-de_DE.po --no-purge`.
     ⚠️ Gotcha: make-json erzeugt eine Datei mit FALSCHEM md5-Pfadnamen. Die von WP
     geladene Datei heißt `project-prepper-de_DE-<md5('admin/js/admin.js')>.json`
     (= `…-b41de59f7288ddce59628fb2f81336ce.json`). Inhalt der frisch erzeugten
     (vollständigen) Datei dorthin kopieren, die überzählige Datei löschen.
4. **Plugin Check** (aus dem Plugin-Ordner):
   `wp plugin check project-prepper --format=csv --fields=line,type,code | grep -i ERROR`
   - **Erlaubte/erwartete ERRORs:** `hidden_files` (= `.wp-env.json`, nicht im ZIP)
     und `plugin_updater_detected` (= der GitHub-Selbst-Updater, by design, docs/06 §12).
   - Jeder ANDERE ERROR blockt das Release → erst beheben.
5. **Build:** `cd wordpress-edition/plugin && ./build.sh` → `dist/project-prepper-X.Y.Z.zip`.
   (build.sh liest die Version aus dem Header — Schritt 1 muss davor laufen.)
6. **PARITY.md** kurz ergänzen: Log-Eintrag mit Version + was im Release steckt.
7. **Commit + Push:** `git add -A && git commit -m "…(vX.Y.Z)" && git push origin wordpress-edition`.
8. **GitHub-Release anlegen** (Tag = `vX.Y.Z`), mit dem gebauten ZIP als Asset:
   ```
   gh release create vX.Y.Z \
     wordpress-edition/plugin/dist/project-prepper-X.Y.Z.zip \
     --title "vX.Y.Z" \
     --notes "<Changelog-Bullets dieser Version>" \
     --target wordpress-edition
   ```
   Das angehängte ZIP ist Pflicht — der Updater bevorzugt das Asset
   `project-prepper-*.zip` (Top-Ordner stimmt) gegenüber dem Quell-Tarball.

## Verifikation (vor dem Abschlussbericht)

- `gh release view vX.Y.Z` zeigt das Release + das Asset `project-prepper-X.Y.Z.zip`.
- Updater-Logik gegen das echte Release prüfen (aus dem Plugin-Ordner):
  `wp eval-file` mit `ProjectPrepper\Updater::latest_release()` (vorher
  `delete_transient('pp_update_release')`), und `version_compare` gegen `PP_VERSION`.
  Erwartung: `is_asset = true`, `package` zeigt aufs ZIP, Version == Release.
- Falls das Repo privat ist: warnen, dass Instanzen das Release nicht ziehen können
  (für den GitHub-Vertrieb muss das Repo bzw. die Releases öffentlich sein).

## Regeln / Gotchas

- **Tag-Format `vX.Y.Z`** — der Updater strippt führendes `v` selbst (egal), aber
  einheitlich bleiben.
- Nur auf `wordpress-edition` arbeiten; nichts auf `main`.
- UI-Texte Deutsch, Code Englisch; keine Icons ungefragt; bestehende Konventionen wahren.
- `wp eval` verschluckt Output → immer `wp eval-file` mit Datei im Plugin-Ordner,
  Skript danach löschen.
- Wenn ein Schritt fehlschlägt (Plugin Check, Build, gh), **abbrechen** und im Bericht
  klar sagen, was offen ist — kein halbes Release pushen.

## Abschlussbericht

- Neue Version + die Changelog-Bullets.
- Plugin-Check-Ergebnis (welche ERRORs erwartet/ignoriert).
- Build-Größe, Commit-Hash, Release-URL (`gh release view --json url`).
- Bestätigung, dass der Updater das Release sieht (Verifikations-Ergebnis).
- Ggf. Warnungen (privates Repo, fehlende Übersetzungen, übersprungene Tests).
