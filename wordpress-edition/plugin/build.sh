#!/usr/bin/env bash
#
# Baut ein installierbares Plugin-ZIP für WordPress.
#
#   ./build.sh           → dist/project-prepper-{version}.zip
#
# Das ZIP enthält nur Laufzeit-Dateien (keine Dev-Dateien wie .wp-env.json,
# README.md, build.sh) und lässt sich direkt unter
# WP-Admin → Plugins → Installieren → Plugin hochladen einspielen.

set -euo pipefail
cd "$(dirname "$0")"

PLUGIN_DIR="project-prepper"
VERSION=$(LC_ALL=C grep -m1 -E '^\s*\*\s*Version:' "$PLUGIN_DIR/project-prepper.php" | sed -E 's/.*Version:[[:space:]]*//')
DIST="dist"
ZIP="$DIST/project-prepper-$VERSION.zip"

mkdir -p "$DIST"
rm -f "$ZIP"

zip -r "$ZIP" "$PLUGIN_DIR" \
  -x "$PLUGIN_DIR/.wp-env.json" \
  -x "$PLUGIN_DIR/README.md" \
  -x "$PLUGIN_DIR/node_modules/*" \
  -x "*/.DS_Store" \
  > /dev/null

echo "Gebaut: $ZIP ($(du -h "$ZIP" | cut -f1 | tr -d ' '))"
unzip -l "$ZIP" | tail -1
