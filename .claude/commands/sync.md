# Session-Sync

Synchronisiere den lokalen Stand mit dem Remote-Repository.
Dieser Befehl soll **zu Beginn jeder Session** ausgeführt werden.

## Schritte

1. `git status` — Prüfen ob lokale uncommittete Änderungen existieren
2. Falls uncommittete Änderungen vorhanden:
   - User warnen und fragen ob stashen oder committen
   - Nicht einfach überschreiben!
3. `git pull origin main` — Neueste Änderungen vom Remote holen
4. Falls sich `package.json` geändert hat: `npm install` ausführen
5. Kurze Zusammenfassung ausgeben:
   - Anzahl neuer Commits
   - Geänderte Dateien
   - Ob Dependencies aktualisiert wurden
   - Aktueller Commit-Hash
