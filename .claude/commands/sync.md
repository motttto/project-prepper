# Session-Sync

Synchronisiere den lokalen Stand mit dem Remote-Repository.
Dieser Befehl soll **zu Beginn jeder Session** ausgeführt werden.

## Schritte

1. **Branch-Frage (PFLICHT):** Den User fragen, auf welcher Entwicklungsebene gearbeitet werden soll:
   - `main` — Haupt-App (Next.js/Supabase, deployt auf Vercel)
   - `wordpress-edition` — WordPress-Plugin-Entwicklung (siehe `wordpress-edition/README.md`)
   Danach ggf. `git checkout <branch>` ausführen.
2. `git status` — Prüfen ob lokale uncommittete Änderungen existieren
3. Falls uncommittete Änderungen vorhanden:
   - User warnen und fragen ob stashen oder committen
   - Nicht einfach überschreiben!
4. `git pull origin <branch>` — Neueste Änderungen vom Remote holen (für den gewählten Branch)
5. Bei `wordpress-edition`: gelegentlich `git merge main` anbieten, damit der Branch aktuell bleibt
6. Falls sich `package.json` geändert hat: `npm install` ausführen
7. Kurze Zusammenfassung ausgeben:
   - Aktiver Branch
   - Anzahl neuer Commits
   - Geänderte Dateien
   - Ob Dependencies aktualisiert wurden
   - Aktueller Commit-Hash
