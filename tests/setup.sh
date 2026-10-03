#!/usr/bin/env bash
# Builds a throwaway local WordPress (SQLite, no MySQL/Docker) with both
# plugins from this repo installed, for tests/e2e.sh and the snapshot check.
# Usage: WPTEST=/some/empty/folder bash tests/setup.sh
set -e
S=${WPTEST:?set WPTEST to an empty folder for the test site}
REPO=$(cd "$(dirname "$0")/.." && pwd)
mkdir -p "$S" && cd "$S"
[ -f wp-cli.phar ] || curl -sSL -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
[ -d wordpress ] || { curl -sSL -o wp.zip https://wordpress.org/latest.zip && unzip -q wp.zip && rm wp.zip; }
cd wordpress
W="php $S/wp-cli.phar --allow-root"
if [ ! -f wp-config.php ]; then
	curl -sSL -o sq.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.zip && unzip -q sq.zip -d wp-content/plugins/ && rm sq.zip
	cp wp-content/plugins/sqlite-database-integration/db.copy wp-content/db.php
	sed -i "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$PWD/wp-content/plugins/sqlite-database-integration#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" wp-content/db.php
	$W config create --dbname=wp --dbuser=x --dbpass=x --skip-check --extra-php <<<"define('WP_DEBUG',true);define('WP_DEBUG_LOG',true);define('WP_DEBUG_DISPLAY',false);" >/dev/null
	$W core install --url=http://localhost:8899 --title=Test --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
	$W option update timezone_string America/New_York
fi
# WP-CLI's install can record the site address as http://localhost:8899/wordpress,
# which scopes login cookies to /wordpress/ and silently breaks every signed-in test.
$W option update home http://localhost:8899 >/dev/null
$W option update siteurl http://localhost:8899 >/dev/null
mkdir -p wp-content/mu-plugins && cp "$REPO/tests/capture-mail.php" wp-content/mu-plugins/
for p in community-events-calendar community-member-planning; do
	rm -rf "wp-content/plugins/$p" && cp -r "$REPO/$p" wp-content/plugins/
	$W plugin activate "$p" >/dev/null
done
PID=$($W eval 'echo (int) CMP_Settings::get("member_page_id");')
if [ "$PID" = 0 ]; then
	PID=$($W post create --post_type=page --post_status=publish --post_title="Member Area" --post_content='[cmp_member_area]' --porcelain)
	$W eval "\$o=get_option('cmp_settings',array()); \$o['member_page_id']=$PID; update_option('cmp_settings',\$o);"
fi
echo "Test site ready in $S/wordpress (member page id $PID)."
echo "Seed sample events once:  (cd $S/wordpress && $W eval-file $REPO/tests/seed.php)"
echo "Run the member tests:     WPTEST=$S bash $REPO/tests/e2e.sh"
