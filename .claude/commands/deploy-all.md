# Deploy All — Supabase + GitHub + Vercel

Führe nach jeder Änderung den kompletten Deploy-Workflow aus:
Supabase-Migration → Git Commit & Push → Vercel deployed automatisch.

## Anweisung

### 1. Prüfe ob es neue Migrationen gibt
```bash
cd /Users/mo/Documents/Claude_Files/project-prepper
git diff --name-only HEAD~1 HEAD -- supabase/migrations/
```
Falls neue `.sql`-Dateien vorhanden sind → Schritt 2. Sonst → Schritt 3.

### 2. Supabase-Migration ausführen
```bash
cd /Users/mo/Documents/Claude_Files/project-prepper
SUPABASE_ACCESS_TOKEN=$(security find-generic-password -s "supabase-deploy-token" -w 2>/dev/null) \
  npx supabase db push
```

Falls der Token fehlt oder `db push` fehlschlägt, nutze den Fallback via Management API:
```bash
TOKEN="<aus Keychain oder User fragen>"
SQL=$(cat <MIGRATION_FILE>)
node -e "
  fetch('https://api.supabase.com/v1/projects/wiywvuurxzkctvpwkncj/database/query', {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + process.env.TOKEN, 'Content-Type': 'application/json' },
    body: JSON.stringify({ query: process.env.SQL })
  }).then(r => r.text().then(t => console.log('HTTP', r.status, t)))
"
```

Falls auch das fehlschlägt → User fragen ob er einen neuen Token auf https://supabase.com/dashboard/account/tokens erstellen kann.

### 3. Git Commit & Push
```bash
cd /Users/mo/Documents/Claude_Files/project-prepper
git add -A
git status
```
- Zeige dem User die Änderungen und schlage eine Commit-Message vor
- Nach Bestätigung:
```bash
git commit -m "<message>"
git push origin main
```

### 4. Vercel-Deployment verifizieren
- Vercel deployed automatisch bei Push auf `main`
- Warte 30 Sekunden, dann prüfe ob das Deployment erfolgreich war:
```bash
# Prüfe den letzten Commit auf GitHub
git log --oneline -1
```
- Informiere den User dass das Deployment läuft und er es auf Vercel prüfen kann

### 5. Zusammenfassung
Gib dem User eine kurze Zusammenfassung:
- Welche Migrationen ausgeführt wurden (falls vorhanden)
- Welche Dateien committed wurden
- Commit-Hash
- Hinweis dass Vercel automatisch deployed

## Projekt-Referenzen
- Supabase Project: `wiywvuurxzkctvpwkncj`
- GitHub: `motttto/project-prepper`
- Vercel: Auto-Deploy auf Push zu `main`
