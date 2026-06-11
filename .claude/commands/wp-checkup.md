# WP-Checkup — WordPress-Plugin im Browser prüfen (MCP)

Visueller + funktionaler Checkup des WordPress-Plugins über die Chrome-MCP-Anbindung.
Voraussetzung: wp-env läuft (sonst zuerst `wordpress-edition/wp-project-prepper-start.command` bzw. `colima start` + `npx @wordpress/env start` im Plugin-Ordner).

## Werkzeug-Wahl

1. **Bevorzugt:** `Claude in Chrome`-MCP (`list_connected_browsers` prüfen) — kann Screenshots + Konsole lesen.
2. **Fallback (funktioniert ohne Extension):** `Control Chrome`-MCP (AppleScript):
   - `open_url` → Seiten ansteuern
   - `execute_javascript` → DOM-Checks (KEINE Promises zurückgeben — AppleScript liefert sonst "missing value"; nur synchrone Ausdrücke/JSON.stringify)

## Ablauf

1. **Login:** `open_url http://localhost:8888/wp-login.php`, dann per JS:
   `document.getElementById('user_login').value='admin'; document.getElementById('user_pass').value='password'; document.getElementById('loginform').submit();`
2. **Seiten durchgehen** (jeweils `open_url`, dann JS-Check):
   - `admin.php?page=project-prepper` (Inventar): `#pp-admin[data-page]` vorhanden, 4 `.pp-kpi`, `.pp-pill`-Anzahl, `.pp-table tbody tr`-Anzahl, Primary-Button = `rgb(99, 102, 241)` (App-Indigo)
   - Detail-Modal: erste `.pp-clickable`-Zeile klicken → `.pp-modal` mit Sektionen Texte/Foto/PDF-Dokumente/Einzelstücke
   - `admin.php?page=pp-rentals`: Formularfelder (Leiher/Adresse/USt), Status-Badges, Modal mit `.pp-billing` (Netto+USt=Brutto prüfen!)
   - `admin.php?page=pp-categories`: Inline-Edit (3 Inputs pro Zeile)
   - `admin.php?page=pp-settings`: Karten E-Mail/iCal/Daten, `.pp-ical-url` gefüllt
3. **Fehler-Check:** Bei Claude-in-Chrome `read_console_messages` mit `onlyErrors`; bei Control Chrome auf JS-Exceptions in den Checks achten (App rendert nicht = Crash).
4. **Ergebnis** kompakt berichten: Was grün ist, was abweicht.

## REST-Smoke (ergänzend, per Bash)

```bash
APP_PASS=$(npx @wordpress/env run cli wp user application-password create admin check-$RANDOM --porcelain 2>/dev/null | tr -d '[:space:]')
curl -s -u "admin:$APP_PASS" http://localhost:8888/wp-json/project-prepper/v1/stats
curl -s -o /dev/null -w "%{http_code}" http://localhost:8888/wp-json/project-prepper/v1/items   # erwartet: 401
```
