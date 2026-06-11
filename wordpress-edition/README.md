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
- [ ] Entscheidung Produktform (Plugin-Rewrite vs. SaaS-Vorlage — siehe 02-WORDPRESS-PORTIERUNG.md)
- [ ] Plugin-Grundgerüst
- [ ] MVP-Module
