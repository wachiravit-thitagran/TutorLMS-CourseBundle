#!/usr/bin/env bash
# Download the plugins the integration suite runs against.
#
# Usage:
#   bin/install-test-plugins.sh [tutor-version] [woocommerce-version]
#
# Versions may be "latest" or an exact release such as "3.0.0". Anything already
# present is left alone, so this is cache-friendly in CI.

set -euo pipefail

TUTOR_VERSION=${1-latest}
WC_VERSION=${2-latest}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")

WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}
WP_PLUGIN_DIR=${WP_PLUGIN_DIR-$WP_CORE_DIR/wp-content/plugins}

mkdir -p "$WP_PLUGIN_DIR"

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -sSL -o "$2" "$1"
	else
		wget -nv -O "$2" "$1"
	fi
}

# Install a plugin from the wordpress.org download server.
#
# $1 slug, $2 version, $3 main file relative to the plugin directory
install_plugin() {
	local slug=$1
	local version=$2
	local main_file=$3
	local target="$WP_PLUGIN_DIR/$slug"

	if [[ -f "$target/$main_file" ]]; then
		echo "==> $slug already installed, skipping."
		return
	fi

	local url
	if [[ "$version" == "latest" ]]; then
		url="https://downloads.wordpress.org/plugin/${slug}.latest-stable.zip"
	else
		url="https://downloads.wordpress.org/plugin/${slug}.${version}.zip"
	fi

	echo "==> Downloading $slug ($version)"
	download "$url" "$TMPDIR/${slug}.zip"

	rm -rf "$target"
	unzip -q "$TMPDIR/${slug}.zip" -d "$WP_PLUGIN_DIR"

	if [[ ! -f "$target/$main_file" ]]; then
		echo "Expected $target/$main_file after extracting $slug." >&2
		ls -la "$WP_PLUGIN_DIR" >&2
		exit 1
	fi

	echo "==> $slug installed."
}

install_plugin "tutor" "$TUTOR_VERSION" "tutor.php"

if [[ "${SKIP_WOOCOMMERCE-false}" != "true" ]]; then
	install_plugin "woocommerce" "$WC_VERSION" "woocommerce.php"
else
	echo "==> Skipping WooCommerce (SKIP_WOOCOMMERCE=true)"
	rm -rf "$WP_PLUGIN_DIR/woocommerce"
fi

echo
echo "Plugins directory: $WP_PLUGIN_DIR"
ls -1 "$WP_PLUGIN_DIR"
