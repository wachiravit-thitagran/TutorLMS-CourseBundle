#!/usr/bin/env bash
# Install the WordPress test library and a WordPress core checkout.
#
# Usage:
#   bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]
#
# Based on the wp-cli scaffold script, trimmed and made idempotent so it can be
# re-run inside a CI cache without redownloading everything.

set -euo pipefail

DB_NAME=${1-}
DB_USER=${2-}
DB_PASS=${3-}
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

if [[ -z "$DB_NAME" || -z "$DB_USER" ]]; then
	echo "Usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]"
	exit 1
fi

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")

WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -sSL -o "$2" "$1"
	elif command -v wget >/dev/null 2>&1; then
		wget -nv -O "$2" "$1"
	else
		echo "Neither curl nor wget is available." >&2
		exit 1
	fi
}

# ---------------------------------------------------------------------------
# Resolve the WordPress version to install.
# ---------------------------------------------------------------------------

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\-(beta|RC)[0-9]+$ ]]; then
	WP_BRANCH=${WP_VERSION%\-*}
	WP_TESTS_TAG="branches/$WP_BRANCH"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
		WP_TESTS_TAG="tags/${WP_VERSION%??}"
	else
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	download http://api.wordpress.org/core/version-check/1.7/ "$TMPDIR/wp-latest.json"
	LATEST_VERSION=$(grep -o '"version":"[^"]*' "$TMPDIR/wp-latest.json" | sed 's/"version":"//' | head -1)

	if [[ -z "$LATEST_VERSION" ]]; then
		echo "Could not determine the latest WordPress version." >&2
		exit 1
	fi

	WP_TESTS_TAG="tags/$LATEST_VERSION"
	WP_VERSION=$LATEST_VERSION
fi

# ---------------------------------------------------------------------------
# WordPress core.
# ---------------------------------------------------------------------------

install_wp() {
	if [[ -d "$WP_CORE_DIR/wp-includes" ]]; then
		echo "WordPress core already present at $WP_CORE_DIR"
		return
	fi

	mkdir -p "$WP_CORE_DIR"

	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		mkdir -p "$TMPDIR/wordpress-trunk"
		rm -rf "$TMPDIR/wordpress-trunk/*"
		download https://wordpress.org/nightly-builds/wordpress-latest.zip "$TMPDIR/wordpress-nightly.zip"
		unzip -q "$TMPDIR/wordpress-nightly.zip" -d "$TMPDIR/wordpress-trunk"
		mv "$TMPDIR/wordpress-trunk/wordpress"/* "$WP_CORE_DIR"
	else
		if [[ $WP_VERSION == 'latest' ]]; then
			ARCHIVE_NAME='latest'
		else
			ARCHIVE_NAME="wordpress-$WP_VERSION"
		fi

		download "https://wordpress.org/${ARCHIVE_NAME}.tar.gz" "$TMPDIR/wordpress.tar.gz"
		tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
	fi

	download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

# ---------------------------------------------------------------------------
# Test library.
# ---------------------------------------------------------------------------

install_test_suite() {
	if [[ $(uname -s) == 'Darwin' ]]; then
		local ioption='-i.bak'
	else
		local ioption='-i'
	fi

	if [[ ! -d "$WP_TESTS_DIR/includes" ]]; then
		mkdir -p "$WP_TESTS_DIR"
		rm -rf "$WP_TESTS_DIR/includes" "$WP_TESTS_DIR/data"
		svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
		svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
	fi

	if [[ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]]; then
		download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"

		local WP_CORE_DIR_ESC
		WP_CORE_DIR_ESC=$(echo "$WP_CORE_DIR" | sed 's/\//\\\//g')

		sed $ioption "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR_ESC/':" "$WP_TESTS_DIR/wp-tests-config.php"
		sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed $ioption "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed $ioption "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed $ioption "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
	fi
}

# ---------------------------------------------------------------------------
# Database.
# ---------------------------------------------------------------------------

create_db() {
	if [[ $SKIP_DB_CREATE == 'true' ]]; then
		return
	fi

	local PARTS EXTRA DB_HOSTNAME DB_SOCK_OR_PORT
	PARTS=(${DB_HOST//\:/ })
	DB_HOSTNAME=${PARTS[0]-}
	DB_SOCK_OR_PORT=${PARTS[1]-}
	EXTRA=""

	if [[ -n "$DB_HOSTNAME" ]]; then
		if [[ "$DB_SOCK_OR_PORT" =~ ^[0-9]+$ ]]; then
			EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
		elif [[ -n "$DB_SOCK_OR_PORT" ]]; then
			EXTRA=" --socket=$DB_SOCK_OR_PORT"
		else
			EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
		fi
	fi

	# shellcheck disable=SC2086
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS"$EXTRA 2>/dev/null || \
		echo "Database $DB_NAME already exists, continuing."
}

install_wp
install_test_suite
create_db

echo "WordPress $WP_VERSION test environment ready."
echo "  core:  $WP_CORE_DIR"
echo "  tests: $WP_TESTS_DIR"
