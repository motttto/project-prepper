#!/usr/bin/env bash
#
# Baut ein installierbares Theme-ZIP für WordPress.
#
#   ./build.sh           → dist/prepper-site-{version}.zip
#
# Das ZIP lässt sich direkt unter WP-Admin → Design → Themes →
# Hinzufügen → Theme hochladen einspielen.

set -euo pipefail
cd "$(dirname "$0")"

THEME_DIR="prepper-site"
VERSION=$(LC_ALL=C grep -m1 -E '^Version:' "$THEME_DIR/style.css" | sed -E 's/Version:[[:space:]]*//')
DIST="dist"
ZIP="$DIST/prepper-site-$VERSION.zip"

mkdir -p "$DIST"
rm -f "$ZIP"

zip -r "$ZIP" "$THEME_DIR" \
  -x "*/.DS_Store" \
  > /dev/null

echo "Gebaut: $ZIP ($(du -h "$ZIP" | cut -f1 | tr -d ' '))"
unzip -l "$ZIP" | tail -1
