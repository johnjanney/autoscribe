#!/usr/bin/env bash
#
# Builds the distributable plugin zip.
#
# Section 12 of the brief offers a choice between committing vendor/ and adding
# a build step. Committing it is not workable now that the plugin has both
# production dependencies that must ship (Action Scheduler, cron-expression) and
# development dependencies that must not (PHPCS, PHPUnit, wp-phpunit). A
# committed vendor/ either ships the dev tooling to every install, or is wiped
# by the next composer install.
#
# So: build step. The staging copy gets a --no-dev install, which is what makes
# the zip contain exactly the runtime dependencies and nothing else.
#
# Usage: bin/build.sh

set -euo pipefail

SLUG="autoscribe"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="$ROOT/build"
STAGE_DIR="$BUILD_DIR/$SLUG"

# The plugin header is the single source of truth for the version.
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT/autoscribe.php" | head -n1 | tr -d '[:space:]')"

if [ -z "$VERSION" ]; then
	echo "Could not read the version from the plugin header." >&2
	exit 1
fi

echo "Building ${SLUG} ${VERSION}"

# Only the staging copy is cleared. Previously built zips stay where they are:
# build/ is the local archive of released artefacts, and wiping it on every
# build would mean the only copy of an older release was the one attached to its
# GitHub release, with nothing on disk to compare against.
rm -rf "$STAGE_DIR"
mkdir -p "$STAGE_DIR"

# Copy the plugin, honouring .distignore.
if command -v rsync > /dev/null 2>&1; then
	EXCLUDES=()
	while IFS= read -r line; do
		case "$line" in
			''|'#'*) continue ;;
		esac
		EXCLUDES+=( "--exclude=$line" )
	done < "$ROOT/.distignore"

	rsync -a "${EXCLUDES[@]}" --exclude="vendor" --exclude="build" "$ROOT/" "$STAGE_DIR/"
else
	echo "rsync is required to build." >&2
	exit 1
fi

# Production dependencies only.
composer install \
	--working-dir="$STAGE_DIR" \
	--no-dev \
	--optimize-autoloader \
	--no-interaction \
	--quiet

# composer.json and the lock file are build inputs, not runtime files.
rm -f "$STAGE_DIR/composer.json" "$STAGE_DIR/composer.lock"

ZIP_PATH="$BUILD_DIR/${SLUG}-${VERSION}.zip"

if [ -f "$ZIP_PATH" ]; then
	echo "Note: replacing the existing build of ${VERSION}." >&2
	echo "      Bump the version in autoscribe.php before building a release." >&2

	rm -f "$ZIP_PATH"
fi

# Remove the staging tree from the archive path first, so zip never walks it.
( cd "$BUILD_DIR" && zip -rq "$(basename "$ZIP_PATH")" "$SLUG" )

rm -rf "$STAGE_DIR"

echo "Built: $ZIP_PATH"
echo
echo "Builds on disk:"

ls -1 "$BUILD_DIR"/*.zip 2>/dev/null | while IFS= read -r built; do
	printf '  %s (%s bytes)\n' "$(basename "$built")" "$(stat -c%s "$built" 2>/dev/null || stat -f%z "$built")"
done
