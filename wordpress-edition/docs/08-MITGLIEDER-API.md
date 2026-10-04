# 08 — Mitglieder-API (ab v0.149.0)

> Jedes Mitglied liest **sein eigenes Equipment und seine eigenen Verleihe** über die REST-API —
> nur lesend, mit einem App-Passwort, das es im Portal selbst anlegt. Gedacht für eigene Werkzeuge,
> z. B. ein persönliches Dashboard.

## Kurzfassung

| | |
|---|---|
| Adresse | `https://<instanz>/wp-json/project-prepper/v1` |
| Anmeldung | HTTP Basic Auth: Benutzername (Login-Name oder E-Mail) + **API-Passwort** |
| API-Passwort | Portal → Dashboard → Kachel „Mein Profil" → **API-Zugang** |
| Rechte | nur `GET`, nur eigene Daten; fremde Verleihe antworten `404` |
| Betreiber-Schalter | wp-admin → Project Prepper → Einstellungen → Funktionen → **Mitglieder-API erlauben** (Standard: an) |
| Drosselung | wp-admin → Project Prepper → Sicherheit → Mitglieder-API (Standard: 300 Anfragen/Minute je Mitglied) |
| HTTPS | Pflicht (WordPress gibt App-Passwörter nur über HTTPS frei; lokal in wp-env auch ohne) |

## Endpunkte

Alle Routen liegen im Namespace `project-prepper/v1` und geben JSON zurück. Die Feldnamen sind
**dieselben wie in den Betreiber-Routen** (`GET /items`, `GET /rentals`, `GET /rentals/{id}`) — ein
Werkzeug kann beide Formen mit demselben Code lesen.

### `GET /me` — wer bin ich

```json
{ "id": 12, "name": "Mo", "roles": ["pp_member"], "groups": [ { "id": 23, "name": "Kollektiv X", "role": "founder" } ] }
```

`groups[].role` ist `founder` oder `member`.

### `GET /me/items` — eigenes Equipment

Alle Artikel mit `owner_user_id` = angemeldeter Nutzer (auch ausgemusterte), Form wie `GET /items`.
Wichtige Felder: `id`, `inventory_number`, `name`, `category_name`, `quantity`, `condition`,
`location`, `manufacturer`, `model`, `serial_number`, `cost_per_day`, `purchase_price`,
`current_value`, `out_now` (heute unterwegs, inkl. Rüstzeiten und gesperrter Exemplare),
`image_url`, `documents[]`, `tags[]`, `owner_user_id`, `owner_name`.

Optionale Parameter: `?search=` (Volltext), `?category_id=`, `?out_only=1` (nur was heute unterwegs ist).

Mit einem Kollektiv **geteilte** Artikel anderer Mitglieder gehören nicht dazu — nur Eigentum.

### `GET /me/rentals` — eigene Verleihe

Alle externen Verleihe, die der Nutzer **selbst angelegt** hat (`owner_user_id`), auch die, die er
im Namen eines Kollektivs angelegt hat. Form wie `GET /rentals`: Kopfdaten + `item_count`.
Felder u. a. `id`, `rental_number`, `event_name`, `borrower_name`, `borrower_email`,
`borrower_phone`, `borrower_address`, `date_from`, `date_to`, `status`, `rental_fee`,
`deposit_amount`, `vat_rate`, `discount_type`, `discount_value`, `notes`, `owner_user_id`,
`owner_group_id`.

Optional: `?status=reserved|active|returned|cancelled`.

**Bewusst nicht enthalten** (Standard „nur eigene"): Kollektiv-Verleihe, die **andere** Mitglieder
angelegt haben, und fremde Verleihe, in denen eigenes Equipment steckt. Beides sieht das Mitglied
weiterhin im Portal. Soll die API das später auch liefern, wäre das ein eigener, ausdrücklich
gekennzeichneter Parameter — nicht stillschweigend.

### `GET /me/rentals/{id}` — ein eigener Verleih

Form wie `GET /rentals/{id}`: Kopfdaten + `items[]` (Positionen mit `item_name`, `inventory_number`,
`quantity`, `daily_rate`, `approval_status` …) + `billing`:

```json
"billing": { "days": 3, "lines": [ … ], "lines_total": 90, "flat": false, "subtotal": 90,
             "discount_type": null, "discount_value": 0, "discount": 0,
             "gross": 90, "net": 75.63, "vat": 14.37, "vat_rate": 19, "deposit": 0 }
```

Ein Verleih, den jemand anderes angelegt hat, antwortet **`404 pp_not_found`** — genauso wie eine
ID, die es nicht gibt. Wer IDs durchprobiert, erfährt nicht, ob es sie gibt.

### Beispiel

```bash
curl -u "mein-login:abcd efgh ijkl mnop qrst uvwx" \
  https://project-prepper.voxelwarp.com/wp-json/project-prepper/v1/me/items
```

Die Leerzeichen im API-Passwort sind egal (WordPress entfernt sie).

## Antworten und Fehler

| Status | `code` | Bedeutung |
|---|---|---|
| 200 | — | Daten |
| 401 | `rest_not_logged_in` | nicht angemeldet (kein/falsches API-Passwort, falscher Name) |
| 401 | `pp_locked` | IP nach zu vielen Fehlversuchen gesperrt (Meldung nennt die Minuten) |
| 401 | `incorrect_password`, `invalid_username`, `application_passwords_disabled_for_user` | Anmeldung abgelehnt (falsch, API aus) |
| 403 | `rest_forbidden` | Nutzer hat keine Project-Prepper-Rolle |
| 403 | `pp_member_api_off` | Betreiber hat die Mitglieder-API abgeschaltet |
| 403 | `pp_feature_off` | Bereich Inventar (`/me/items`) bzw. Verleih (`/me/rentals…`) ist abgeschaltet |
| 403 | `pp_api_read_only` | App-Passwort eines Mitglieds auf einer anderen Route oder mit Schreib-Methode |
| 404 | `pp_not_found` | Verleih gibt es nicht oder er gehört jemand anderem |
| 404 | `rest_no_route` | Instanz hat noch keine Mitglieder-API (älter als v0.149.0) → Werkzeug kann auf die Betreiber-Routen zurückfallen |
| 429 | `pp_rate_limited` | Drosselung; Header `Retry-After` nennt die Sekunden bis zum nächsten Minutenfenster |

## Sicherheit — was gilt

1. **Nur lesen, nur eigenes.** Jede `/me`-Abfrage filtert serverseitig auf die ID des angemeldeten
   Nutzers; es gibt keinen Parameter, der das ändert.
2. **Nur-Lese-Grenze für App-Passwörter** (`MemberApi::guard_rest`): Wer kein Backend-Recht hat
   (Mitglied, Abonnent) und sich per App-Passwort anmeldet, erreicht ausschließlich `GET`/`HEAD` auf
   `/project-prepper/v1/me…` und `/wp/v2/users/me`. Dasselbe gilt für **jedes im Portal angelegte
   Passwort**, auch das eines Managers oder Admins (Kennung `app_id` = `MemberApi::APP_ID`) — das
   „Nur lesen" im Portal stimmt also für alle, auch nach einer Beförderung. Alles andere — auch das
   Ändern des eigenen Profils oder Passworts — antwortet `403 pp_api_read_only`. Ein entwendetes
   API-Passwort liest höchstens, was der Nutzer ohnehin sieht, und ändert nichts. XML-RPC ist für
   Mitglieder-App-Passwörter gesperrt. Routen werden wie im Core ohne Groß-/Kleinschreibung verglichen.
3. **Verwalten nur im Portal.** Für Nutzer ohne Backend-Recht sind die Core-Rechte
   `create_app_password`, `edit_app_password`, `delete_app_password(s)` gesperrt (`map_meta_cap`) und
   die Core-Routen `/wp/v2/users/*/application-passwords` zum Schreiben zu (`pp_api_manage_in_portal`)
   — so greifen Höchstzahl (10), Namensregel und Betreiber-Schalter auf jedem Weg, auch über
   `wp-admin/authorize-application.php`. Admins verwalten die Passwörter eines Mitglieds im wp-admin
   weiterhin. Nur Nutzer mit Project-Prepper-Rolle bekommen überhaupt API-Passwörter; während
   „Ansehen als" (Impersonation) lässt sich keins anlegen.
4. **Einmal-Anzeige.** Das neue Passwort steht nach dem Anlegen genau einmal auf der Seite. Bis
   dahin liegt es höchstens zwei Minuten **verschlüsselt** (libsodium, Schlüssel aus den Salts in
   `wp-config.php`) in einem Transient; die Anzeige löscht es.
5. **Login-Sperre.** Falsche API-Passwörter zählen in dieselbe Sperre je IP wie das Portal-Login
   (Sicherheit → Login-Drosselung). Eine gesperrte IP wird auch mit richtigem Passwort abgewiesen.
   Abweisungen, weil die API aus ist oder die IP schon gesperrt ist, verlängern die Sperre nicht.
6. **Drosselung** je Mitglied und Minute (`api_rate_limit`, 0 = aus); der erste Treffer je Minute
   landet im Aktivitätsprotokoll.
7. **Protokoll.** Anlegen und Widerrufen eines App-Passworts (egal über welchen Weg, auch im
   wp-admin) erscheinen im Aktivitätsprotokoll — nie das Passwort selbst.
8. **Betreiber und Manager:** Ihre im wp-admin angelegten App-Passwörter und die Betreiber-Routen
   verhalten sich wie vorher. `/me` funktioniert auch für sie; ihre Portal-Passwörter sind nur-lesend.
9. **Abschalten.** Schalter „Mitglieder-API erlauben" aus → `/me…` antwortet `403`, Anlegen ist
   gesperrt und **bereits angelegte** Mitglieder-App-Passwörter werden bei der Anmeldung abgewiesen
   (sie bleiben gespeichert und greifen wieder, wenn der Schalter an geht). Wer noch Passwörter hat,
   sieht die Seite „API-Zugang" weiter und kann sie dort widerrufen.
10. **Deaktivierung und Deinstallation** widerrufen alle Passwörter, die ohne Plugin keine
    Nur-Lese-Grenze mehr hätten: sämtliche Passwörter von Nutzern ohne Backend-Recht und alle im
    Portal angelegten. Plugin-Updates deaktivieren still und lösen das nicht aus.
11. **DSGVO.** „Meine Daten herunterladen (JSON)" enthält `api_passwords` (Name, angelegt, zuletzt
    genutzt, letzte IP — nie Hash oder Passwort). Mit dem Nutzer gelöscht werden seine App-Passwörter
    (User-Meta) und die Transients der API.

## Betrieb / Fehlersuche

- **401, obwohl das Passwort stimmt:** Manche Apache-/FastCGI-Setups reichen den
  `Authorization`-Header nicht an PHP weiter. WordPress' Standard-`.htaccess` enthält dafür
  `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` — fehlt die Zeile, ergänzen.
- **„API-Passwörter brauchen eine verschlüsselte Verbindung":** Die Instanz läuft ohne HTTPS
  (oder `is_ssl()` erkennt es hinter einem Proxy nicht).
- **429:** Werkzeug fragt zu schnell (z. B. jeden Verleih einzeln in einer Schleife) — `Retry-After`
  beachten oder das Limit im Backend anheben.
- **„Zuletzt genutzt"** aktualisiert WordPress höchstens einmal am Tag.

## Prüfen

Regressionstest für die Mandanten-Trennung, Nur-Lese-Grenze, Schalter und Drosselung (97 Prüfungen,
eigene Wegwerf-Nutzer, räumt auf) — nur lokale wp-env:

```bash
CLI=$(docker ps --format '{{.Names}}' | grep -E 'cli-1$' | grep -v tests | head -1)
docker cp wordpress-edition/tools/audit/pp-audit-lib.php "$CLI":/tmp/pp-audit-lib.php
docker cp wordpress-edition/tools/audit/member-api-isolation.php "$CLI":/tmp/api-isolation.php
( cd wordpress-edition/plugin/project-prepper && npx @wordpress/env run cli wp eval-file /tmp/api-isolation.php )
```

Kernaussagen des Tests: Mitglied A sieht weder B's Artikel noch B's Verleihe (Liste und per ID),
auch nicht B's Kollektiv-Verleih mit A's Equipment; Außenstehende sehen nichts; Abgemeldete
bekommen 401; die Betreiber-Routen bleiben für Mitglieder 403; ein Mitglieder-App-Passwort kann
über echte HTTP-Basic-Auth nur `/me…` lesen. Dazu die Funde des Zugriffs-Audits vom 2026-10-04
(Schreibweisen der Core-Routen, Batch, Portal-Passwort eines Managers, Widerruf bei Deaktivierung,
Abonnent, Widerrufen bei abgeschalteter API, Sperr-Meldung).

## Code

| Datei | Rolle |
|---|---|
| `includes/MemberApi.php` | Schalter, Nur-Lese-Grenze, Drosselung, App-Passwörter anlegen/widerrufen, Einmal-Anzeige, Protokoll |
| `includes/Rest/MeController.php` | die vier `/me`-Routen |
| `includes/Security.php` | `api_rate_limit`, App-Passwort-Fehlversuche in der Login-Sperre |
| `includes/Frontend/MemberPortal.php` | View `api` („API-Zugang"), Aktionen `api_password_create` / `api_password_revoke`, Daten-Export |
| `includes/Settings.php` | Feature-Schlüssel `api` |
| `admin/js/admin.js` | Karte „Mitglieder-API" auf der Sicherheits-Seite, Protokoll-Texte |
| `tools/audit/member-api-isolation.php` | Regressionstest |
