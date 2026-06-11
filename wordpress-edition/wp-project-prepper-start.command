#!/bin/bash
#
# WordPress-Testumgebung für Project Prepper starten (Doppelklick im Finder).
#
# Fährt die Docker-Runtime (Colima) hoch, startet WordPress mit dem Plugin
# und öffnet den Admin im Browser.
#
# Stoppen: im Plugin-Ordner `npx @wordpress/env stop` und danach `colima stop`.

set -e
export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"

PLUGIN_DIR="$(cd "$(dirname "$0")/plugin/project-prepper" && pwd)"

echo "════════════════════════════════════════════════"
echo "  Project Prepper — WordPress-Testumgebung"
echo "════════════════════════════════════════════════"
echo ""

# 1) Docker-Runtime (Colima)
if docker info > /dev/null 2>&1; then
	echo "✓ Docker läuft bereits."
else
	echo "→ Starte Docker-Runtime (Colima) — dauert ca. 30 Sekunden …"
	colima start
	echo "✓ Docker läuft."
fi
echo ""

# 2) WordPress (wp-env)
echo "→ Starte WordPress … (beim ersten Mal dauert das einige Minuten)"
cd "$PLUGIN_DIR"
npx --yes @wordpress/env start
echo ""

# 3) Browser öffnen
echo "════════════════════════════════════════════════"
echo "  WordPress läuft!"
echo ""
echo "  Admin:    http://localhost:8888/wp-admin"
echo "  Benutzer: admin"
echo "  Passwort: password"
echo "════════════════════════════════════════════════"
open "http://localhost:8888/wp-admin"

echo ""
echo "Dieses Fenster kann geschlossen werden."
