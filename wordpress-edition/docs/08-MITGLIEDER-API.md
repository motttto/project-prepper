# 08 — Mitglieder-API (ab v0.149.0, Schreiben ab v0.150.0)

> Jedes Mitglied liest **sein eigenes Equipment und seine eigenen Verleihe** über die REST-API —
> mit einem App-Passwort, das es im Portal selbst anlegt. Gedacht für eigene Werkzeuge,
> z. B. ein persönliches Dashboard. Standard ist **nur lesen**; wer beim Anlegen „Lesen + mein
> Equipment bearbeiten" wählt, kann damit zusätzlich eigene Artikel anlegen und ändern (ab v0.150.0).

## Kurzfassung

| | |
|---|---|
| Adresse | `https://<instanz>/wp-json/project-prepper/v1` |
| Anmeldung | HTTP Basic Auth: Benutzername (Login-Name oder E-Mail) + **API-Passwort** |
| API-Passwort | Portal → Dashboard → Kachel „Mein Profil" → **API-Zugang** |
| Rechte | nur eigene Daten; fremde Verleihe/Artikel antworten `404`. Passwort „Nur lesen": nur `GET`. Passwort „Lesen + mein Equipment bearbeiten": zusätzlich `POST /me/items`, `PUT/PATCH /me/items/{id}` |
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

### Schreiben: eigenes Equipment (ab v0.150.0)

Nur mit einem API-Passwort, das im Portal mit **„Lesen + mein Equipment bearbeiten"** angelegt wurde
(Kennung `app_id` = `MemberApi::APP_ID_WRITE`) — oder angemeldet im Portal. Geschrieben wird über
dieselben Wege wie „Mein Inventar" (`MemberInventory::create`/`update`): **Besitzer ist immer der
angemeldete Nutzer** und nicht änderbar. Bild, Dokumente, Freigaben, Stücklisten und **Löschen**
bleiben im Portal.

- **`GET /me/categories`** — eigene Kategorien `[{id, name, icon, prefix}]` (Vorlagen-Kategorien des
  Betreibers erst nach „Übernehmen" im Portal).
- **`POST /me/items`** — Artikel anlegen, JSON-Body, `name` Pflicht → `201` + Artikel (Form wie
  `GET /items/{id}`, mit `owner_name`). Feldnamen wie beim Lesen: `inventory_number` (leer = nächste
  freie Nummer der Kategorie; sonst höchstens 40 Zeichen und am Ende höchstens 9 Ziffern), `name`, `category_id` (nur eigene), `quantity`, `is_consumable`,
  `condition` (`new`, `good`, `fair`, `poor`, `maintenance`, `broken`, `lost`, `retired`), `location`,
  `manufacturer`, `model`, `serial_number`, `cost_per_day`, `purchase_price`, `current_value`,
  `purchase_date` (`JJJJ-MM-TT`), `dimensions`, `power_watts`, `accessories`, `tags[]`, `description`,
  `notes`, `manufacturer_url`, `manual_url`, `ownership_type`, `funding_source`, Abschreibungsfelder.
  `owner_user_id`, `image_id` und `document_ids` im Body werden ignoriert.
- **`PUT` (oder `PATCH`) `/me/items/{id}`** — ändert **nur die mitgeschickten Felder**. Mit
  `"expect": "<updated_at wie zuletzt gelesen>"` antwortet eine zwischenzeitliche Änderung (Portal,
  anderes Programm) mit `409 pp_stale`, statt überschrieben zu werden. Leere Zahl/Datum = `null`.
  Menge unter 1 wird bei Geräten 1 (wie im Portal). Fremder oder unbekannter Artikel → `404`.
  Die **Inventarnummer steht ab dem Anlegen fest** (wie im Portal — Etiketten/QR-Codes zeigen auf sie):
  eine andere Nummer → `400 pp_number_fixed`, dieselbe mitschicken ist erlaubt. Werte, die keine
  einfachen Zahlen/Texte sind (Arrays in Textfeldern), werden ignoriert.

### Beispiel

```bash
curl -u "mein-login:abcd efgh ijkl mnop qrst uvwx" \
  https://project-prepper.voxelwarp.com/wp-json/project-prepper/v1/me/items

# nur mit „Lesen + mein Equipment bearbeiten":
curl -u "mein-login:…" -X PUT -H "Content-Type: application/json" \
  -d '{"location":"Lager","expect":"2026-10-04 12:00:00"}' \
  https://project-prepper.voxelwarp.com/wp-json/project-prepper/v1/me/items/42
```

Die Leerzeichen im API-Passwort sind egal (WordPress entfernt sie).

## Antworten und Fehler

| Status | `code` | Bedeutung |
|---|---|---|
| 200 | — | Daten |
| 201 | — | Artikel angelegt (`POST /me/items`) |
| 400 | `pp_bad_request`, `pp_missing_name`, `pp_bad_category`, `pp_bad_condition`, `pp_bad_date`, `pp_bad_number`, `pp_number_fixed` | Schreiben: kein JSON, Name fehlt/leer, Kategorie nicht eigene, unbekannter Zustand, Kaufdatum nicht `JJJJ-MM-TT`, Inventarnummer zu lang / mehr als 9 Endziffern, Inventarnummer nachträglich geändert |
| 401 | `rest_not_logged_in` | **keine** Anmeldedaten angekommen — Programm schickt keine, oder der Webserver reicht den `Authorization`-Header nicht weiter (siehe Fehlersuche) |
| 401 | `pp_bad_credentials` | Anmeldedaten kamen an, aber Benutzername oder API-Passwort stimmt nicht (oder das Konto darf die API nicht nutzen) — welcher Teil, wird bewusst nicht verraten |
| 401 | `pp_locked` | IP nach zu vielen Fehlversuchen gesperrt (Meldung nennt die Minuten) |
| 403 | `rest_forbidden` | Nutzer hat keine Project-Prepper-Rolle |
| 403 | `pp_member_api_off` | Betreiber hat die Mitglieder-API abgeschaltet |
| 403 | `pp_feature_off` | Bereich Inventar (`/me/items`) bzw. Verleih (`/me/rentals…`) ist abgeschaltet |
| 403 | `pp_api_read_only` | App-Passwort auf einer anderen Route oder mit Schreib-Methode (Passwort „Nur lesen", oder Schreiben außerhalb von `POST /me/items` / `PUT /me/items/{id}`) |
| 404 | `pp_not_found` | Verleih bzw. Artikel gibt es nicht oder er gehört jemand anderem |
| 409 | `pp_number_taken` | Inventarnummer schon vergeben |
| 409 | `pp_stale` | `expect` passt nicht mehr: der Artikel wurde inzwischen geändert — neu lesen |
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
   **Ausnahme nach Wahl des Mitglieds (v0.150.0):** Ein Portal-Passwort „Lesen + mein Equipment
   bearbeiten" (`MemberApi::APP_ID_WRITE`, `write_scope_request` + `write_route_allowed`) darf
   zusätzlich genau `POST /me/items` und `PUT|PATCH /me/items/{id}` — kein Löschen, keine Bilder,
   kein Profil, keine Verleihe, keine Betreiber-Routen, kein Batch. Ein entwendetes Passwort dieser
   Art kann also eigene Artikel anlegen und ändern, aber nichts löschen und nichts Fremdes anfassen.
   Die Art steht im Passwort (nicht in der Rolle), in der Portal-Liste sichtbar, und wird beim
   Anlegen protokolliert (`api_password_created`, `write`).
   **XML-RPC:** Im Portal angelegte Passwörter (beide Arten, jede Rolle) werden bei XML-RPC abgewiesen
   (`wp_authenticate_application_password_errors` → `pp_api_no_xmlrpc`) — XML-RPC kennt die REST-Grenze
   nicht (Zugriffs-Audit ACC-W-02). Im wp-admin angelegte Passwörter von Betreibern/Managern unverändert.
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

- **401 `pp_bad_credentials`:** Login-Name prüfen (wp-admin → Benutzer, Spalte „Benutzername" — nicht
  der Anzeigename) und ob das API-Passwort unter genau diesem Konto angelegt wurde.
- **401 `rest_not_logged_in`, obwohl das Programm Zugangsdaten schickt:** Apache mit FastCGI/FPM
  reicht den `Authorization`-Header manchmal nicht an PHP weiter — WordPress sieht dann gar keine
  Anmeldung. WordPress' eigener `.htaccess`-Block enthält dafür eine RewriteRule; zusätzlich
  schreibt das Plugin seit v0.149.1 in seinen eigenen `.htaccess`-Block
  (`# BEGIN Project Prepper Performance`, nach jedem Update neu) die Zeile
  `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`. Ist die `.htaccess` nicht beschreibbar,
  die Zeile von Hand eintragen. Test ohne gültige Zugangsdaten:
  `curl -u "gibt-es-nicht:x" https://<instanz>/wp-json/project-prepper/v1/me` muss
  `pp_bad_credentials` liefern; `rest_not_logged_in` heißt: Header kommt nicht an.
- **„API-Passwörter brauchen eine verschlüsselte Verbindung":** Die Instanz läuft ohne HTTPS
  (oder `is_ssl()` erkennt es hinter einem Proxy nicht).
- **429:** Werkzeug fragt zu schnell (z. B. jeden Verleih einzeln in einer Schleife) — `Retry-After`
  beachten oder das Limit im Backend anheben.
- **„Zuletzt genutzt"** aktualisiert WordPress höchstens einmal am Tag.

## Prüfen

Regressionstest für die Mandanten-Trennung, Nur-Lese-Grenze, Schreib-Passwörter, Schalter und Drosselung (164 Prüfungen,
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
Abonnent, Widerrufen bei abgeschalteter API, Sperr-Meldung). Ab v0.150.0 zusätzlich: Nur-Lese-Passwörter
(Mitglied und Manager) können nicht schreiben; ein Schreib-Passwort legt nur Eigenes an (Besitzer,
Bild und fremde Kategorie im Body wirkungslos), ändert nur mitgeschickte Felder, respektiert `expect`,
bekommt für fremde Artikel 404 und für Löschen, Bild, Profil, Verleihe, Betreiber-Routen und Batch 403
— auch über echte HTTP-Basic-Auth. Dazu die Funde des Zugriffs-Audits vom 2026-10-04 zu v0.150.0: Nummernkreis
überspringt überlange Endziffern (W-01), Portal-Passwörter über XML-RPC abgewiesen (W-02, echte HTTP-Anfrage,
Gegenprobe wp-admin-Passwort), fremde Kategorie auch über Portal und Service abgelehnt, Vorlagen weiter erlaubt
(W-03), Inventarnummer per PUT fest (C1), negative Kategorie (C3), Arrays in Textfeldern (C6).

## Code

| Datei | Rolle |
|---|---|
| `includes/MemberApi.php` | Schalter, Nur-Lese-Grenze, Drosselung, App-Passwörter anlegen/widerrufen, Einmal-Anzeige, Protokoll |
| `includes/Rest/MeController.php` | die `/me`-Routen (lesen; ab v0.150.0 `GET /me/categories`, `POST /me/items`, `PUT/PATCH /me/items/{id}`) |
| `includes/Rest/ItemsController.php` | `item_payload()` — gemeinsame Feld-Bereinigung für Admin- und Mitglieder-Route |
| `includes/Security.php` | `api_rate_limit`, App-Passwort-Fehlversuche in der Login-Sperre |
| `includes/Frontend/MemberPortal.php` | View `api` („API-Zugang"), Aktionen `api_password_create` / `api_password_revoke`, Daten-Export |
| `includes/Settings.php` | Feature-Schlüssel `api` |
| `admin/js/admin.js` | Karte „Mitglieder-API" auf der Sicherheits-Seite, Protokoll-Texte |
| `tools/audit/member-api-isolation.php` | Regressionstest |
