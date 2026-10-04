#!/usr/bin/env bash
# End-to-end test of colandb-updater against tests/mock-github.php.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/updater-e2e.sh
WPT=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
M=$WPT/mockgh; rm -rf $M; mkdir -p $M/zips $M/build
# Test packages: a good 0.2.0 build of the member plugin, and a zip with the wrong folder.
cp -r $REPO/community-member-planning $M/build/ && sed -i "s/^ \* Version: [0-9.]*/ * Version: 0.2.0/" $M/build/community-member-planning/community-member-planning.php && (cd $M/build && zip -rq $M/zips/2.zip community-member-planning)
mkdir -p $M/build/evil && echo '<?php // not the plugin' > $M/build/evil/evil.php && (cd $M/build && zip -rq $M/zips/3.zip evil)
cd $WPT/wordpress
W="php $WPT/wp-cli.phar --allow-root"
PASS=0; FAIL=0
ok(){ if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
ev(){ $W eval "$1" 2>&1; }
pkill -f "php -S 127.0.0.1:8900" 2>/dev/null; sleep 0.5
(MOCKGH_DIR=$M php -S 127.0.0.1:8900 $REPO/tests/mock-github.php >/dev/null 2>&1 &)
# The site itself must be up: WordPress 6.6+ checks the site after a background update and rolls back if it can't reach it.
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; (PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
rm -f $M/requests.log; echo '{}' > $M/state.json

# Fresh state: member plugin 0.1.0 from the repo, updater from the repo.
for p in community-member-planning colandb-updater; do rm -rf wp-content/plugins/$p; cp -r $REPO/$p wp-content/plugins/; done
$W plugin activate colandb-updater community-member-planning >/dev/null 2>&1
$W config set COLANDB_UPDATER_API http://127.0.0.1:8900 --type=constant >/dev/null
# Keep WordPress's background jobs from updating the plugin mid-test; the background update is triggered explicitly later.
$W config set DISABLE_WP_CRON true --raw --type=constant >/dev/null
$W option delete colandb_updater >/dev/null 2>&1; $W transient delete --all --network >/dev/null 2>&1; $W transient delete --all >/dev/null 2>&1
rm -f wp-content/debug.log

echo "== No token"
ok "no token -> clear error, no update offered" $(ev 'echo is_wp_error(COLANDB_Updater::releases(true)) && "no_token"===COLANDB_Updater::releases()->get_error_code() ? 1:0;')

echo "== Wrong token"
ev 'update_option("colandb_updater",array("token"=>"bad-token"),false);' >/dev/null
ok "bad token -> 401 explained" $(ev '$r=COLANDB_Updater::releases(true); echo is_wp_error($r) && false!==strpos($r->get_error_message(),"401") ? 1:0;')
ok "token option not autoloaded" $(ev 'global $wpdb; $a=$wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name=\"colandb_updater\""); echo in_array($a,array("no","off"),true)?1:0;')

echo "== Live (stable) channel"
ev 'update_option("colandb_updater",array("token"=>"good-token","channel"=>"stable"),false); delete_site_transient("colandb_updater_releases");' >/dev/null
ok "releases parsed (draft and untagged skipped)" $(ev '$r=COLANDB_Updater::releases(true); echo (!is_wp_error($r) && 3===count($r) && !in_array("0.9.0",wp_list_pluck($r,"version"),true))?1:0;')
ok "stable sees 0.1.0 only" $(ev '$l=COLANDB_Updater::latest("community-member-planning"); echo $l && "0.1.0"===$l["version"]?1:0;')
$W plugin list --name=community-member-planning --fields=name,version,update,update_version --skip-update-check >/dev/null 2>&1
ok "live: no update offered for member plugin" $([ "$($W plugin list --name=community-member-planning --field=update 2>/dev/null)" = none ] && echo 1 || echo 0)
ok "live: auto-update not forced" $(ev '$i=(object)array("slug"=>"community-member-planning"); echo true!==COLANDB_Updater::auto_update(null,$i)?1:0;')

echo "== Staging channel"
ev '$o=get_option("colandb_updater"); $o["channel"]="staging"; update_option("colandb_updater",$o,false); delete_site_transient("update_plugins");' >/dev/null
ok "staging sees pre-release 0.2.0" $(ev '$l=COLANDB_Updater::latest("community-member-planning"); echo $l && "0.2.0"===$l["version"] && $l["prerelease"]?1:0;')
ok "WordPress lists update 0.2.0" $([ "$($W plugin list --name=community-member-planning --field=update_version 2>/dev/null)" = 0.2.0 ] && echo 1 || echo 0)
ok "staging: auto-update forced on" $(ev '$i=(object)array("slug"=>"community-member-planning"); echo true===COLANDB_Updater::auto_update(null,$i)?1:0;')
ok "other plugins' auto-update untouched" $(ev '$i=(object)array("slug"=>"akismet"); echo null===COLANDB_Updater::auto_update(null,$i)?1:0;')
ok "View details shows release notes" $(ev '$r=COLANDB_Updater::plugin_info(false,"plugin_information",(object)array("slug"=>"community-member-planning")); echo is_object($r) && "0.2.0"===$r->version && false!==strpos($r->sections["changelog"],"avatar upload")?1:0;')

echo "== Install the update"
: > $M/requests.log
out=$($W plugin update community-member-planning 2>&1)
echo "$out" | sed 's/^/   /' | tail -4
ok "member plugin now 0.2.0" $([ "$($W plugin get community-member-planning --field=version 2>/dev/null)" = 0.2.0 ] && echo 1 || echo 0)
ok "still active after update" $([ "$($W plugin get community-member-planning --field=status 2>/dev/null)" = active ] && echo 1 || echo 0)
ok "asset request carried the token" $(grep -q 'GET /repos/phillipweasley-gif/colandb/releases/assets/2 auth=Bearer good-token accept=application/octet-stream' $M/requests.log && echo 1 || echo 0)
ok "token NOT sent to storage server" $(grep '/storage/' $M/requests.log | grep -q 'auth=-' && ! grep '/storage/' $M/requests.log | grep -q 'good-token' && echo 1 || echo 0)
ok "no temp zip left behind" $([ -z "$(find /tmp -maxdepth 1 -name 'community-member-planning*.zip*' -mmin -5 2>/dev/null)" ] && echo 1 || echo 0)

echo "== A release whose zip has the wrong contents"
echo '{"bad_release":true}' > $M/state.json
ev 'delete_site_transient("colandb_updater_releases"); delete_site_transient("update_plugins");' >/dev/null
ok "0.3.0 offered" $([ "$($W plugin list --name=community-member-planning --field=update_version 2>/dev/null)" = 0.3.0 ] && echo 1 || echo 0)
out=$($W plugin update community-member-planning 2>&1)
ok "bad zip refused with clear message" $(echo "$out" | grep -q 'does not contain the community-member-planning plugin folder' && echo 1 || echo 0)
ok "site keeps working 0.2.0" $([ "$($W plugin get community-member-planning --field=version 2>/dev/null)" = 0.2.0 ] && [ "$($W plugin get community-member-planning --field=status)" = active ] && echo 1 || echo 0)
ok "no 'evil' plugin installed" $([ ! -e wp-content/plugins/evil ] && echo 1 || echo 0)
echo '{}' > $M/state.json

echo "== Packages from elsewhere are ignored"
ok "non-GitHub package passes through untouched" $(ev 'echo false===COLANDB_Updater::download(false,"https://downloads.wordpress.org/plugin/akismet.zip",null,array())?1:0;')
ok "asset for an unmanaged plugin refused" $(ev '$r=COLANDB_Updater::download(false,COLANDB_Updater::api_base()."/repos/phillipweasley-gif/colandb/releases/assets/2",null,array("plugin"=>"akismet/akismet.php")); echo is_wp_error($r)?1:0;')

echo "== Background auto-update on staging (WordPress's own updater)"
ev '$o=get_option("colandb_updater"); delete_site_transient("colandb_updater_releases");' >/dev/null
rm -rf wp-content/plugins/community-member-planning && cp -r $REPO/community-member-planning wp-content/plugins/ && $W plugin activate community-member-planning >/dev/null 2>&1
ev 'require_once ABSPATH."wp-admin/includes/admin.php"; require_once ABSPATH."wp-admin/includes/class-wp-upgrader.php"; delete_site_transient("update_plugins"); wp_update_plugins(); delete_option("auto_updater.lock"); $u=new WP_Automatic_Updater(); echo $u->is_disabled()?"disabled\n":"enabled\n"; $u->run();' | sed 's/^/   auto-updater: /' | head -3
ok "auto-updated 0.1.0 -> 0.2.0 with no click" $([ "$($W plugin get community-member-planning --field=version 2>/dev/null)" = 0.2.0 ] && echo 1 || echo 0)

echo "== Settings screen never shows the token"
$W user get admin >/dev/null 2>&1
J=$M/jar; rm -f $J; curl -s -c $J -b $J -o /dev/null http://localhost:8899/wp-login.php; curl -s -c $J -b $J -o /dev/null -d "log=admin&pwd=admin&wp-submit=Log+In&testcookie=1" http://localhost:8899/wp-login.php
curl -s -b $J "http://localhost:8899/wp-admin/options-general.php?page=colandb-updater" -o $M/settings.html
ok "settings page renders, connected" $(grep -q 'Connected to GitHub' $M/settings.html && echo 1 || echo 0)
ok "token not in page HTML" $(grep -q 'good-token' $M/settings.html && echo 0 || echo 1)
ok "status table lists member plugin" $(grep -q 'Community Member Planning' $M/settings.html && echo 1 || echo 0)
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null

pkill -f "php -S 127.0.0.1:8900" 2>/dev/null
echo; echo "debug.log (plugin-related):"; grep -v 'header.php\|footer.php' wp-content/debug.log 2>/dev/null | sed 's|/tmp/claude-0[^ ]*/wordpress/||g' | sort | uniq -c | head
echo "RESULT: $PASS passed, $FAIL failed"
$W config delete DISABLE_WP_CRON --type=constant >/dev/null 2>&1
