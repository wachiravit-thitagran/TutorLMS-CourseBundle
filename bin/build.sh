#!/usr/bin/env bash
#
# Build an installable plugin zip.
#
# The archive contains exactly one top-level directory, named after the slug
# WordPress installs this plugin into (tutor-course-bundles, derived from the
# main file name and confirmed by TCB_BASENAME). That is what lets the
# "Replace current with uploaded" prompt overwrite the existing folder instead
# of dropping a second copy next to it.
#
# vendor/ is deliberately never shipped: composer.json declares dev tooling
# only (phpunit, phpcs, mockery, brain/monkey) and the plugin autoloads src/
# with its own PSR-4 loader, requiring vendor/autoload.php only if it happens
# to be readable. There is no runtime dependency to bundle.
#
# Usage: bin/build.sh
# Output: dist/tutor-course-bundles.zip
#
set -euo pipefail

PLUGIN_SLUG="tutor-course-bundles"
MAIN_FILE="${PLUGIN_SLUG}.php"
DIST_DIR="dist"
BUILD_DIR="build/${PLUGIN_SLUG}"

# Runnable from anywhere.
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ ! -f "$MAIN_FILE" ]; then
	echo "Cannot find ${MAIN_FILE} — is this the plugin repository?" >&2
	exit 1
fi

VERSION=$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' "$MAIN_FILE" | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)

if [ -z "$VERSION" ]; then
	echo "Could not read a semver Version header from ${MAIN_FILE}." >&2
	exit 1
fi

echo "Building ${PLUGIN_SLUG} ${VERSION}"

# Only remove what this script owns; build/ also holds PHPUnit result caches.
rm -rf "$BUILD_DIR" "$DIST_DIR"
mkdir -p "$BUILD_DIR" "$DIST_DIR"

echo "Staging files..."
rsync -a \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='.gitignore' \
	--exclude='.gitattributes' \
	--exclude='.editorconfig' \
	--exclude='/tests' \
	--exclude='/bin' \
	--exclude='/build' \
	--exclude='/dist' \
	--exclude='/vendor' \
	--exclude='/node_modules' \
	--exclude='composer.json' \
	--exclude='composer.lock' \
	--exclude='phpunit*.xml.dist' \
	--exclude='phpunit*.xml' \
	--exclude='phpcs.xml.dist' \
	--exclude='phpcs.xml' \
	--exclude='phpcs-report.xml' \
	--exclude='.phpunit*.cache' \
	--exclude='.phpunit.result.cache' \
	--exclude='*.md' \
	--exclude='*.zip' \
	--exclude='*.log' \
	--exclude='.DS_Store' \
	--exclude='Thumbs.db' \
	./ "$BUILD_DIR/"

echo "Creating the archive..."
(
	cd build
	zip -rq "../${DIST_DIR}/${PLUGIN_SLUG}.zip" "$PLUGIN_SLUG" -x '*.DS_Store'
)

ZIP="${DIST_DIR}/${PLUGIN_SLUG}.zip"

echo "Verifying the archive..."

# Listed once: piping into `grep -q` would SIGPIPE unzip and trip pipefail.
entries=$(unzip -Z1 "$ZIP")

# Exactly one top-level directory, and it must be the install slug.
top_level=$(awk -F/ 'NF > 0 { print $1 }' <<<"$entries" | sort -u)
if [ "$top_level" != "$PLUGIN_SLUG" ]; then
	echo "Archive must have exactly one top-level directory named ${PLUGIN_SLUG}, found:" >&2
	printf '%s\n' "$top_level" >&2
	exit 1
fi

# Nothing that belongs to development may leak onto a production site.
if grep -E "^${PLUGIN_SLUG}/(tests|bin|vendor|build|dist|node_modules|\.git|\.github)/|^${PLUGIN_SLUG}/(composer\.(json|lock)|phpunit.*\.xml.*|phpcs.*\.(xml|dist).*|\.git.*)$" <<<"$entries"; then
	echo "The lines above should not be in a production build." >&2
	exit 1
fi

# The things a site does need.
for required in \
	"${PLUGIN_SLUG}/${MAIN_FILE}" \
	"${PLUGIN_SLUG}/uninstall.php" \
	"${PLUGIN_SLUG}/readme.txt" \
	"${PLUGIN_SLUG}/src/Plugin.php" \
	"${PLUGIN_SLUG}/assets/css/frontend.css" \
	"${PLUGIN_SLUG}/templates/single-bundle.php" \
	"${PLUGIN_SLUG}/languages/${PLUGIN_SLUG}.pot"; do
	if ! grep -qxF "$required" <<<"$entries"; then
		echo "Missing from the archive: ${required}" >&2
		exit 1
	fi
done

# The zip must carry the same version the header claims.
zipped_header=$(unzip -p "$ZIP" "${PLUGIN_SLUG}/${MAIN_FILE}")
zipped_version=$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' <<<"$zipped_header" | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)
if [ "$zipped_version" != "$VERSION" ]; then
	echo "Archive reports version '${zipped_version}', expected '${VERSION}'." >&2
	exit 1
fi

echo
echo "Build complete: ${ZIP} ($(du -h "$ZIP" | cut -f1), $(wc -l <<<"$entries" | tr -d ' ') entries, version ${VERSION})"
