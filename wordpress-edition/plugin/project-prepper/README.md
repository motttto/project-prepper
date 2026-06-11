# Project Prepper — WordPress-Plugin (Entwicklung)

> Plugin-Neuentwicklung (Option A) nach [docs/02-WORDPRESS-PORTIERUNG.md](../../docs/02-WORDPRESS-PORTIERUNG.md).
> MVP-Schnitt v1.0: **Inventar + Verleih** (siehe Portierungs-Doku §4).

## Architektur

| Schicht | Ort | Anmerkung |
|---------|-----|-----------|
| Schema | `includes/Schema.php` | Custom Tables `wp_pp_*` via `dbDelta`, versioniert über Option `pp_schema_version` |
| Zugriff | `includes/Capabilities.php` | Caps `pp_*` = RLS-Ersatz; Rollen `pp_manager`, `pp_member` |
| Geschäftslogik | `includes/Services/` | Numbering (Nummernkreise), Inventory, Availability (Overlap-Query), Rentals (Status-Maschine), ActivityLog |
| API | `includes/Rest/` | Namespace `project-prepper/v1`, jede Route mit `permission_callback` |
| Admin-UI | `includes/Admin/Menu.php` + `admin/js/admin.js` | Vanilla JS gegen die REST-API, **kein Build-Step**. Später durch React (`@wordpress/scripts`) ersetzbar — die REST-API bleibt gleich |

## Tabellen (MVP)

`pp_categories`, `pp_items`, `pp_units`, `pp_rentals`, `pp_rental_items`, `pp_activity_log`

## REST-Endpoints

```
GET/POST       /wp-json/project-prepper/v1/categories
PUT/DELETE     /wp-json/project-prepper/v1/categories/{id}
GET/POST       /wp-json/project-prepper/v1/items          ?search= &category_id=
GET/PUT/DELETE /wp-json/project-prepper/v1/items/{id}
GET            /wp-json/project-prepper/v1/items/{id}/availability?from=Y-m-d&to=Y-m-d
GET/POST       /wp-json/project-prepper/v1/rentals        ?status=
GET/DELETE     /wp-json/project-prepper/v1/rentals/{id}
POST           /wp-json/project-prepper/v1/rentals/{id}/status   {"status": "active"}
```

## Lokal testen (wp-env + Colima)

Docker-Runtime auf diesem Rechner ist **Colima** (kein Docker Desktop):

```bash
colima start                # Docker-Runtime hochfahren
cd wordpress-edition/plugin/project-prepper
npx @wordpress/env start    # nutzt .wp-env.json → Plugin ist vorinstalliert & aktiviert
# Admin: http://localhost:8888/wp-admin (admin / password)

npx @wordpress/env stop     # WordPress stoppen
colima stop                 # Docker-Runtime stoppen (optional)
```

Nützlich beim Entwickeln (Code-Änderungen wirken sofort, der Ordner ist gemountet):

```bash
npx @wordpress/env run cli wp plugin list
npx @wordpress/env run cli wp db query "SHOW TABLES LIKE '%pp\_%'"
npx @wordpress/env destroy  # komplett zurücksetzen (frische DB)
```

## ZIP-Export für echte WordPress-Installationen

```bash
../build.sh                 # → ../dist/project-prepper-{version}.zip
```

Das ZIP unter **WP-Admin → Plugins → Installieren → Plugin hochladen** einspielen und aktivieren — Tabellen werden bei Aktivierung automatisch angelegt. Enthält nur Laufzeit-Dateien (ohne `.wp-env.json`, Dev-README, build-Script).

## Stand v0.2.0 (MVP-Scope aus Doku §4)

- [x] Inventar: alle App-Felder (§8.1 — Seriennummer, Kaufpreis, Maße, Watt, Zubehör, URLs, Tags), Zustands-Enum der App (new/good/fair/poor/broken/retired), Auto-Seed der 10 Default-Kategorien, Volltextsuche über 10 Felder, KPI-Stats (§8.5)
- [x] Foto-Upload (Media Library) + PDF-Dokumente (max. 20 MB) im Detail-Modal
- [x] Einzelstücke-UI (§8.4) — Limit: max. so viele Stücke wie Menge
- [x] CSV-Import/-Export (§8.6): Export 19 Spalten (Semikolon + BOM für deutsches Excel), Import mit Auto-Spalten-Mapping (deutsche Header), Mapping-Editor, Vorschau, Zustands-Mapping („defekt"→broken), Fehlerliste pro Zeile, Kategorien werden auto-angelegt
- [x] Verleih: Adresse, USt-Satz, Abrechnung §9.4 (Brutto/Netto/USt, Fallback Σ Tagessatz × Tage × Menge, Kaution durchlaufend)
- [x] E-Mail-Benachrichtigungen an Leiher (Reservierung/Ausgabe/Rückgabe), editierbare Templates mit `{{vars}}`, abschaltbar
- [x] iCal-Feed der Verleihe (Token-Auth, abonnierbar)
- [x] DSGVO: WP-Core-Exporter/-Eraser für Leiher-Daten (Werkzeuge → Personenbezogene Daten)
- [x] Einstellungs-Seite (E-Mail, Templates, iCal-Token, Daten-Löschung bei Uninstall)
- [x] Admin-UI im Look der Live-App (Design-Tokens aus `globals.css`: Indigo-Palette, Light/Dark, KPI-Karten, Kategorie-Pills, Detail-Modals, Badges, Toasts)

## Noch offen

- [ ] Echtes XLSX statt CSV (SheetJS client-seitig oder PhpSpreadsheet)
- [ ] Verleih bearbeiten (Diff-Logik §9.4) — aktuell nur anlegen/Status/löschen
- [ ] Freigabe-Logik pro Position (§9.2/9.3 — braucht Multi-Owner, kommt mit Gruppen-Modul)
- [ ] Gutenberg-Blöcke / Frontend-Ausgabe (Inventarliste, Anfrage-Formular)
- [ ] i18n: Strings sind de-Default; en_US-Übersetzung für den Verkauf
