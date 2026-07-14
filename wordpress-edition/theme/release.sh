#!/usr/bin/env bash
#
# Veröffentlicht eine neue Theme-Version.
#
#   ./release.sh 0.4.0                       # Notes interaktiv eingeben
#   ./release.sh 0.4.0 --notes "Zeile 1
#   Zeile 2"                                 # Notes direkt übergeben
#   ./release.sh 0.4.0 --dry-run             # nur zeigen, nichts schreiben
#
# Schritte:
#   1. Version in style.css setzen
#   2. Changelog-Abschnitt in readme.txt einfügen
#   3. ZIP bauen (build.sh) — landet in dist/ (gitignored, geht nur als Release-Asset raus)
#   4. Root-Manifest theme-update.json schreiben  ← DAS liest der Updater
#   5. Commit + Push auf wordpress-edition
#   6. GitHub-Release `theme-v{version}` mit angehängtem ZIP
#
# WICHTIG: Ohne Schritt 4 sehen Installationen das Update NICHT — das Manifest ist
# die einzige Quelle, aus der der Theme-Updater liest.

set -euo pipefail
cd "$(dirname "$0")"

THEME_DIR="prepper-site"
REPO_ROOT="$(cd ../.. && pwd)"
MANIFEST="$REPO_ROOT/theme-update.json"
BRANCH="wordpress-edition"
REPO_SLUG="motttto/project-prepper"
TAG_PREFIX="theme-v"

VERSION=""
NOTES=""
DRY_RUN=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --notes)   NOTES="${2:-}"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    *)         VERSION="$1"; shift ;;
  esac
done

if [[ -z "$VERSION" ]]; then
  echo "Usage: ./release.sh <version> [--notes \"...\"] [--dry-run]" >&2
  exit 1
fi
if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Version muss X.Y.Z sein (bekam: $VERSION)" >&2
  exit 1
fi

TAG="$TAG_PREFIX$VERSION"
CURRENT=$(LC_ALL=C grep -m1 -E '^Version:' "$THEME_DIR/style.css" | sed -E 's/Version:[[:space:]]*//')
echo "Theme-Release: $CURRENT → $VERSION"

if git rev-parse "$TAG" >/dev/null 2>&1 || gh release view "$TAG" >/dev/null 2>&1; then
  echo "Release/Tag $TAG existiert bereits — abgebrochen." >&2
  exit 1
fi

# --- Release-Notes einsammeln -----------------------------------------------
if [[ -z "$NOTES" ]]; then
  echo "Release-Notes (eine Änderung pro Zeile, leere Zeile beendet):"
  while IFS= read -r line; do
    [[ -z "$line" ]] && break
    NOTES+="${line}"$'\n'
  done
fi
NOTES="$(printf '%s' "$NOTES" | sed '/^[[:space:]]*$/d')"
if [[ -z "$NOTES" ]]; then
  echo "Keine Release-Notes angegeben — abgebrochen." >&2
  exit 1
fi

if [[ $DRY_RUN -eq 1 ]]; then
  echo "--- DRY RUN — es wird nichts geschrieben ---"
  echo "Tag:      $TAG"
  echo "Paket:    https://github.com/$REPO_SLUG/releases/download/$TAG/prepper-site-$VERSION.zip"
  echo "Manifest: $MANIFEST"
  echo "Notes:"
  printf '%s\n' "$NOTES" | sed 's/^/  - /'
  exit 0
fi

# --- 1.+2. style.css + readme.txt + 4. Manifest ------------------------------
python3 - "$THEME_DIR" "$MANIFEST" "$VERSION" "$REPO_SLUG" "$TAG" "$NOTES" <<'PY'
import json, re, sys, datetime

theme_dir, manifest, version, repo, tag, notes = sys.argv[1:7]
bullets = [l.strip() for l in notes.splitlines() if l.strip()]

# 1. style.css — Version im Theme-Header
style = f"{theme_dir}/style.css"
src = open(style, encoding="utf-8").read()
src, n = re.subn(r"(?m)^(Version:\s*).*$", lambda m: m.group(1) + version, src, count=1)
if n != 1:
    sys.exit(f"Version-Header in {style} nicht gefunden")
open(style, "w", encoding="utf-8").write(src)

# 2. readme.txt — neuen Changelog-Abschnitt oben einfügen
readme = f"{theme_dir}/readme.txt"
src = open(readme, encoding="utf-8").read()
entry = f"= {version} =\n" + "".join(f"* {b}\n" for b in bullets) + "\n"
src, n = re.subn(r"(?m)^== Changelog ==\n\n", "== Changelog ==\n\n" + entry, src, count=1)
if n != 1:
    sys.exit(f"'== Changelog ==' in {readme} nicht gefunden")
open(readme, "w", encoding="utf-8").write(src)

# 4. Manifest — die einzige Quelle, aus der der Updater liest
data = {
    "version": version,
    "package": f"https://github.com/{repo}/releases/download/{tag}/prepper-site-{version}.zip",
    "url": f"https://github.com/{repo}/releases/tag/{tag}",
    "published": datetime.date.today().isoformat(),
    "requires": "6.6",
    "requires_php": "8.0",
    "changelog": " ".join(bullets),
}
with open(manifest, "w", encoding="utf-8") as f:
    json.dump(data, f, indent=2, ensure_ascii=False)
    f.write("\n")

print(f"style.css → {version}, readme.txt ergänzt, Manifest geschrieben.")
PY

# --- 3. ZIP bauen (dist/ ist gitignored → nur Release-Asset) -----------------
./build.sh
ZIP="dist/$THEME_DIR-$VERSION.zip"
[[ -f "$ZIP" ]] || { echo "ZIP nicht gebaut: $ZIP" >&2; exit 1; }

# --- 5. Commit + Push -------------------------------------------------------
git -C "$REPO_ROOT" add \
  "wordpress-edition/theme/$THEME_DIR/style.css" \
  "wordpress-edition/theme/$THEME_DIR/readme.txt" \
  "theme-update.json"
git -C "$REPO_ROOT" commit -m "$(printf 'Theme %s\n\n%s' "$VERSION" "$NOTES")"
git -C "$REPO_ROOT" push origin "$BRANCH"

# --- 6. GitHub-Release ------------------------------------------------------
gh release create "$TAG" "$ZIP" \
  --title "Prepper Site $VERSION" \
  --notes "$(printf '%s\n' "$NOTES" | sed 's/^/- /')" \
  --target "$BRANCH"

echo
echo "✅ Theme $VERSION veröffentlicht."
echo "   Installationen sehen das Update unter Design → Themes."
echo "   (Eigener Cache 6 h — sofort via Dashboard → Aktualisierungen → „Erneut prüfen“.)"
