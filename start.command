#!/bin/bash
cd "$(dirname "$0")"

echo "🔧 Projektplanner – Startskript"
echo "================================"

# 1. Cache löschen
echo "🗑  Cache löschen..."
rm -rf .next
echo "   ✓ .next gelöscht"

# 2. Port 3000 prüfen und ggf. freigeben
PORT=3000
PID=$(lsof -ti :$PORT 2>/dev/null)
if [ -n "$PID" ]; then
  echo "⚠️  Port $PORT ist belegt (PID: $PID)"
  echo "   → Prozess wird beendet..."
  kill -9 $PID 2>/dev/null
  sleep 1
  echo "   ✓ Port $PORT ist jetzt frei"
else
  echo "✅ Port $PORT ist frei"
fi

# 3. Dev-Server starten
echo ""
echo "🚀 Starte Dev-Server..."
echo "   → http://localhost:$PORT"
echo "================================"
echo ""
npm run dev
