#!/usr/bin/env bash
# Builds the WP.org release ZIP: dist/flavoursuite-ai-{version}.zip
#
# Stages the tree (minus .distignore entries), runs a fresh
# `composer install --no-dev` so the Jetpack Autoloader classmap is
# regenerated with production packages only, and zips with the
# canonical top-level flavoursuite-ai/ folder.
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="flavoursuite-ai"

VERSION="$(grep -oP '^\s*\*\s*Version:\s*\K[0-9a-z.-]+' "$REPO/$SLUG.php")"
[ -n "$VERSION" ] || { echo "Could not read Version from $SLUG.php" >&2; exit 1; }

STABLE_TAG="$(grep -oP '^Stable tag:\s*\K[0-9a-z.-]+' "$REPO/readme.txt")"
if [ "$VERSION" != "$STABLE_TAG" ]; then
	echo "Version mismatch: plugin header $VERSION vs readme stable tag $STABLE_TAG" >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

rsync -a --exclude-from="$REPO/.distignore" "$REPO"/ "$STAGE/$SLUG/"

composer install --no-dev --optimize-autoloader --quiet --working-dir="$STAGE/$SLUG"

# The autoloader entry point must exist and dev-only packages must not.
[ -f "$STAGE/$SLUG/vendor/autoload_packages.php" ] || { echo "Jetpack autoloader missing from build" >&2; exit 1; }
[ ! -d "$STAGE/$SLUG/vendor/phpcompatibility" ] || { echo "Dev package leaked into build" >&2; exit 1; }

# Every staged PHP file must parse (catches partial edits before release).
find "$STAGE/$SLUG" -name '*.php' -not -path '*/vendor/*' -print0 \
	| xargs -0 -n1 php -l >/dev/null

mkdir -p "$REPO/dist"
ZIP="$REPO/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
if command -v zip >/dev/null; then
	( cd "$STAGE" && zip -qr "$ZIP" "$SLUG" )
else
	( cd "$STAGE" && python3 - "$ZIP" "$SLUG" <<'PY'
import os, sys, zipfile
dest, slug = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(dest, "w", zipfile.ZIP_DEFLATED) as z:
    for root, _, files in os.walk(slug):
        for f in sorted(files):
            z.write(os.path.join(root, f))
PY
	)
fi

echo "Built $ZIP ($(du -h "$ZIP" | cut -f1))"
unzip -l "$ZIP" | tail -1
