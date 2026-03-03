# Build prüfen und deployen

Prüfe den Build, fixe Fehler und bereite einen Commit vor.

## Anweisung

1. **Build prüfen:** `npm run build`
2. **Bei Fehlern:**
   - TypeScript-Fehler fixen
   - ESLint-Warnungen beheben (nur echte Probleme, keine stylistic)
   - Unused Imports entfernen
   - Missing Dependencies in useEffect ergänzen
3. **Build erneut laufen lassen** bis erfolgreich
4. **Git-Status zeigen:** `git status` + `git diff`
5. **Commit vorschlagen** mit passendem Message (deutsch oder englisch, je nach bisherigen Commits)
6. Frage den User ob committed werden soll

## Wichtig
- Keine funktionalen Änderungen beim Fixen — nur Type-/Lint-Fehler beheben
- Bei unklaren Fehlern den User fragen statt zu raten
- Nicht automatisch pushen — nur committen wenn der User zustimmt
