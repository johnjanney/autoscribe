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

# A release archive has to be a build of the commit it is published against, and
# this is where that went wrong once: the 1.7.0 zip was built, the changelog was
# edited afterwards, and the tag then pointed at a tree the archive did not
# contain. Nothing about the archive itself showed it — the runtime files
# matched, only the changelog differed.
#
# So a build refuses to run against a working copy with uncommitted changes to
# anything it would package. Set AUTOSCRIBE_ALLOW_DIRTY=1 for a local build that
# is not going to be published.
if git -C "$ROOT" rev-parse --git-dir > /dev/null 2>&1; then
	DIRTY="$(git -C "$ROOT" status --porcelain -- . ':(exclude)build' ':(exclude)tests' ':(exclude)docs' ':(exclude)bin' ':(exclude).github')"

	if [ -n "$DIRTY" ] && [ "${AUTOSCRIBE_ALLOW_DIRTY:-0}" != "1" ]; then
		echo "Refusing to build: the working copy has uncommitted changes to packaged files." >&2
		echo "$DIRTY" >&2
		echo >&2
		echo "Commit them first, so the archive matches the commit it will be tagged as." >&2
		echo "For a throwaway local build, set AUTOSCRIBE_ALLOW_DIRTY=1." >&2
		exit 1
	fi
fi

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

# Nothing that is not the plugin. .distignore is the list, and this is the check
# that the list did its job: a stray archive in the working copy once shipped
# inside the plugin and doubled the download, and it was invisible because
# .gitignore hid it from git status. A build that has staged something it should
# not is a build worth stopping.
STRAY="$(find "$STAGE_DIR" \( -name '*.zip' -o -name '*.tar.gz' \) -print -quit)"

if [ -n "$STRAY" ]; then
	echo "Refusing to build: an archive was staged into the plugin." >&2
	echo "  $STRAY" >&2
	echo "Remove it, or add it to .distignore." >&2
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
