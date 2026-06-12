# WordPress-Edition — Zweite Entwicklungsebene

> Dieser Ordner ist die **zweite Entwicklungsebene** von Project Prepper:
> die Vorbereitung, das Tool als **WordPress-Vorlage / -Produkt** anzubieten und zu verkaufen.
> Die Haupt-App (Next.js + Supabase) bleibt davon unberührt und läuft weiter auf `main`.

## Struktur

| Datei | Inhalt |
|-------|--------|
| [docs/01-FUNKTIONSMAPPING.md](docs/01-FUNKTIONSMAPPING.md) | **Komplettes, tiefes Funktionsmapping** der gesamten App — jedes Modul, jede Funktion, DB-Tabellen, Rechte, Business-Regeln |
| [docs/02-WORDPRESS-PORTIERUNG.md](docs/02-WORDPRESS-PORTIERUNG.md) | **Portierungs-Strategie**: Was wird in WordPress woraus? Konzept-Mapping, Komplexität pro Modul, MVP-Schnitt, Verkaufsmodell |

## Git-Workflow (zwei Ebenen)

- **`main`** — Produktiv-App (Next.js/Supabase), wie bisher. Deployt automatisch auf Vercel.
- **`wordpress-edition`** — Entwicklungs-Branch für alles, was zur WordPress-Version gehört (Plugin-Code, Theme, Experimente). Wird **nicht** deployt.

### Wechseln zwischen den Ebenen

```bash
# Auf die WordPress-Ebene wechseln:
git checkout wordpress-edition

# Zurück zur Haupt-App:
git checkout main
```

### Regeln

1. **App-Änderungen** (Bugfixes, Features der Live-App) → immer auf `main`, wie gewohnt.
2. **WordPress-Entwicklung** (Plugin-Code, PHP, Theme-Dateien) → auf `wordpress-edition`.
3. Die Doku in diesem Ordner (`wordpress-edition/docs/`) liegt auf `main`, damit sie immer sichtbar ist. Aktualisierungen der Doku gehen auf `main`.
4. Wenn `main` weiterläuft, den Branch gelegentlich aktualisieren:
   ```bash
   git checkout wordpress-edition
   git merge main
   git push origin wordpress-edition
   ```

## Status

- [x] Funktionsmapping erstellt (2026-06-11, Stand Migration 105, Commit e9fe5b8)
- [x] Portierungs-Analyse erstellt
- [x] **Entscheidung Produktform: Option A — WordPress-Plugin-Neuentwicklung** (2026-06-11)
- [x] Branch `wordpress-edition` für die WordPress-Entwicklung angelegt (GitHub)
- [x] Plugin-Grundgerüst (2026-06-11, `wordpress-edition/plugin/project-prepper/`): Schema (6 `pp_*`-Tabellen), Capabilities/Rollen, Services (Nummernkreise, Verfügbarkeit, Verleih-Status-Maschine, Activity-Log), REST-API (`project-prepper/v1`), Admin-UI (Inventar/Verleih/Kategorien, Vanilla JS ohne Build-Step)
- [x] Test in echter WordPress-Instanz (2026-06-11, wp-env via Colima): Aktivierung, Tabellen, Vertical Slice (Kategorie → Artikel LIC-0001 → Verleih V-2026-0001 → Verfügbarkeit/Überbuchung/Status-Flow), REST-Auth (401 ohne Login), Admin-UI-Auslieferung — alles grün
- [x] ZIP-Export für echte WP-Installationen (`plugin/build.sh` → `plugin/dist/project-prepper-{version}.zip`)
- [x] MVP-Module ausgebaut (v0.2.0, 2026-06-11): alle Inventar-Felder der App, Foto/PDF-Upload, Einzelstücke-UI, CSV-Import/-Export mit Mapping-Editor, Abrechnung (USt §9.4), E-Mail-Benachrichtigungen, iCal-Feed, DSGVO-Hooks, Einstellungen — Admin-UI im Design der Live-App (Indigo-Palette, Light/Dark)
- [x] Frontend v0.3.0 (2026-06-11): Shortcodes + Gutenberg-Blöcke (Equipment-Liste, Verfügbarkeits-Check, Anfrage-Formular) + Anfragen-Backend mit Admin-Pipeline — Back- und Frontend zusammen
- [x] Parity-Läufe 1–4 (v0.4.0–v0.7.0, 2026-06-11/12): XLSX-Import/-Export, Verleih bearbeiten, Anfrage→Verleih, Anfragen-Pipeline, Artikel-Detailseite, Kategorien-Merge, Eigentum & Abschreibung — MVP-Parität erreicht (siehe `PARITY.md`)
- [x] Internationalisierung für wordpress.org (v0.8.0, 2026-06-12): Quell-Strings englisch (PHP + JS via wp.i18n), vollständige de_DE-Übersetzung mitgeliefert (.po/.mo/JSON — deutsche Installationen sehen die bisherigen Texte), readme.txt englisch
- [ ] Demo-Site + Lizenzmodell (Freemius/EDD)
