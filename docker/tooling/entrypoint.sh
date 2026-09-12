#!/usr/bin/env bash
# Prepares the WordPress PHPUnit test suite against the compose DB, then execs
# the requested command (phpunit / phpcs / ...).
#
# Mirrors the classic install-wp-tests.sh (which is no longer served from
# wordpress-develop trunk): export core + tests lib via SVN, write config,
# create the test database.
set -euo pipefail

export WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
export WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress/}"
# Trunk (7.x-dev) restructured the phpunit library; pin a tagged release whose
# layout matches the classic install (includes/bootstrap.php + config sample).
# CI adds a separate trunk job once the new layout settles.
WP_VERSION="${WP_VERSION:-6.8}"

: "${DB_NAME:=wordpress_test}" "${DB_USER:=root}" "${DB_PASS:=root}" "${DB_HOST:=db}"

# Wait for the database.
for i in $(seq 1 30); do
    if mysqladmin ping --skip-ssl --host="$DB_HOST" --user="$DB_USER" --password="$DB_PASS" --silent 2>/dev/null; then
        break
    fi
    sleep 1
done

case "$WP_VERSION" in
    trunk) SVN_URL="https://develop.svn.wordpress.org/trunk" ;;
    *)     SVN_URL="https://develop.svn.wordpress.org/tags/$WP_VERSION" ;;
esac

if [ ! -f "$WP_TESTS_DIR/includes/functions.php" ]; then
    mkdir -p "$WP_TESTS_DIR"
    svn export --force -q "$SVN_URL/tests/phpunit" "$WP_TESTS_DIR"
fi

# The config sample ships at the repository root, not inside tests/phpunit.
if [ ! -f "$WP_TESTS_DIR/wp-tests-config-sample.php" ]; then
    svn export --force -q "$SVN_URL/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config-sample.php"
fi

if [ ! -f "$WP_CORE_DIR/wp-load.php" ]; then
    mkdir -p "$WP_CORE_DIR"
    if [ "$WP_VERSION" = "trunk" ]; then
        svn export --force -q "$SVN_URL/src" "$WP_CORE_DIR"
    else
        # The release tarball ships the built wp-includes/assets/* bundles the
        # SVN src tree generates at build time.
        curl -sSL "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" \
            | tar xz --strip-components=1 -C "$WP_CORE_DIR"
    fi
fi

if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
    # The 6.x sample defines ABSPATH as dirname(__FILE__).'/src/' assuming a
    # develop-checkout layout; point it at the exported core instead.
    sed -e "s/youremptytestdbnamehere/$DB_NAME/" \
        -e "s/yourusernamehere/$DB_USER/" \
        -e "s/yourpasswordhere/$DB_PASS/" \
        -e "s|localhost|$DB_HOST|" \
        -e "s|dirname( __FILE__ ) . '/src/'|'$WP_CORE_DIR'|" \
        "$WP_TESTS_DIR/wp-tests-config-sample.php" > "$WP_TESTS_DIR/wp-tests-config.php"
fi

mysql --skip-ssl --host="$DB_HOST" --user="$DB_USER" --password="$DB_PASS" \
    -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`"

exec "$@"
