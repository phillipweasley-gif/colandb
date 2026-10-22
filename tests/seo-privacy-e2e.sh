#!/usr/bin/env bash
# End-to-end test of username privacy and SEO exclusions (Community Member
# Planning 0.38.1): no users sitemap, author pages and ?author= give "not
# found" without naming the account, the users API is closed to visitors and
# members, oEmbed carries no author, and the member page stays out of the
# sitemap and search. Runs once with WordPress's own sitemap and, if
# YOAST_ZIP points to a Yoast SEO zip, again with Yoast SEO active.
# Usage: WPTEST=<folder from tests/setup.sh> [YOAST_ZIP=wordpress-seo.zip] bash tests/seo-privacy-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/seo; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
ev()   { $W eval "$1" 2>&1; }
code() { curl -s -o $T/body -D $T/head -w '%{http_code}' "$@"; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
nonce(){ curl -s -b $T/$1.jar "$H/wp-admin/admin-ajax.php?action=rest-nonce"; }

rm -rf wp-content/plugins/community-member-planning && cp -r $REPO/community-member-planning wp-content/plugins/
$W plugin activate community-member-planning >/dev/null 2>&1
$W plugin deactivate wordpress-seo >/dev/null 2>&1
ev 'CMP_Install::maybe_upgrade();' >/dev/null
$W option update blog_public 1 >/dev/null
$W option update permalink_structure '/%postname%/' >/dev/null 2>&1; $W rewrite flush >/dev/null 2>&1
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
# A router, so addresses with a dot in them (sitemap .xml files) reach WordPress.
cat > $T/router.php <<'PHP'
<?php
$f = __DIR__ . '/../wordpress' . parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( is_file( $f ) ) { return false; }
$_SERVER['SCRIPT_NAME'] = '/index.php';
require 'index.php';
PHP
sed -i "s#__DIR__ . '/../wordpress'#'$S/wordpress'#" $T/router.php
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . $T/router.php >/dev/null 2>&1 &); sleep 1.5

$W user delete seoauthor seomember --yes >/dev/null 2>&1
AID=$($W user create seoauthor seoauthor@example.com --role=author --user_pass=seopass1234567 --display_name="Seo Author" --porcelain)
$W user create seomember seomember@example.com --role=subscriber --user_pass=seopass1234567 >/dev/null
POST=$($W post create --post_type=post --post_status=publish --post_author=$AID --post_title="Seo test post" --porcelain)
PAGE_ID=$(ev 'echo (int) CMP_Settings::get("member_page_id");')
PURL=$(ev "echo get_permalink($POST);")
login member seomember seopass1234567
login admin admin admin

checks() {
	echo "== Usernames ($1)"
	c=$(code "$H/?author=$AID")
	ok "?author=<id>: not found, no redirect to the username" $([ "$c" = 404 ] && ! grep -qi 'seoauthor' $T/head && echo 1 || echo 0)
	c=$(code "$H/author/seoauthor/")
	ok "/author/<name>/: not found" $([ "$c" = 404 ] && echo 1 || echo 0)
	c=$(code -b $T/admin.jar "$H/author/seoauthor/")
	ok "an administrator still sees author pages" $([ "$c" = 200 ] && echo 1 || echo 0)
	curl -s "$PURL" > $T/post.html
	ok "the post page doesn't link to the author page" $(grep -q '/author/seoauthor' $T/post.html && echo 0 || echo 1)
	c=$(code "$H/wp-json/wp/v2/users")
	ok "users API refused to visitors" $([ "$c" = 401 ] && ! grep -q seoauthor $T/body && echo 1 || echo 0)
	c=$(code "$H/wp-json/wp/v2/users/$AID")
	ok "single user refused to visitors" $([ "$c" = 401 ] && echo 1 || echo 0)
	c=$(code "$H/wp-json/wp/v2/posts?_embed=author")
	ok "embedded author in the posts API carries no username" $([ "$c" = 200 ] && ! grep -q '"slug":"seoauthor"' $T/body && echo 1 || echo 0)
	N=$(nonce member)
	c=$(code -b $T/member.jar -H "X-WP-Nonce: $N" "$H/wp-json/wp/v2/users")
	ok "users API refused to a member" $([ "$c" = 403 ] && echo 1 || echo 0)
	c=$(code -b $T/member.jar -H "X-WP-Nonce: $N" "$H/wp-json/wp/v2/users/me")
	ok "a member still gets their own /users/me" $([ "$c" = 200 ] && grep -q '"slug":"seomember"' $T/body && echo 1 || echo 0)
	N=$(nonce admin)
	c=$(code -b $T/admin.jar -H "X-WP-Nonce: $N" "$H/wp-json/wp/v2/users?who=authors")
	ok "the editor's author list still works for an administrator" $([ "$c" = 200 ] && grep -q '"slug":"seoauthor"' $T/body && echo 1 || echo 0)
	c=$(code "$H/wp-json/oembed/1.0/embed?url=$(php -r 'echo rawurlencode($argv[1]);' "$PURL")")
	ok "oEmbed: the site's name and home page instead of the author" $([ "$c" = 200 ] && [ "$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["author_name"]===$argv[2] && $d["author_url"]===$argv[3] ? 1 : 0;' $T/body "$(ev 'echo get_bloginfo("name");')" "$H/")" = 1 ] && echo 1 || echo 0)
}

checks "WordPress sitemap"
echo "== WordPress sitemap"
curl -s "$H/wp-sitemap.xml" > $T/sm.xml
ok "sitemap index has posts but no users sitemap" $(grep -q 'wp-sitemap-posts-post-1' $T/sm.xml && ! grep -q 'wp-sitemap-users' $T/sm.xml && echo 1 || echo 0)
c=$(code "$H/wp-sitemap-users-1.xml")
ok "users sitemap itself is gone" $([ "$c" = 404 ] && echo 1 || echo 0)
curl -s "$H/wp-sitemap-posts-page-1.xml" > $T/pages.xml
ok "member page not in the page sitemap" $([ "$PAGE_ID" -gt 0 ] && ! grep -q "page_id=$PAGE_ID\|$(ev "echo get_permalink($PAGE_ID);")<" $T/pages.xml && echo 1 || echo 0)

if [ -n "$YOAST_ZIP" ]; then
	$W plugin install "$YOAST_ZIP" --activate --force >/dev/null 2>&1
	$W option patch update wpseo disable-author true >/dev/null 2>&1
	ev 'delete_transient("wpseo_sitemap_cache_validator_global"); WPSEO_Sitemaps_Cache::clear();' >/dev/null
	$W rewrite flush >/dev/null 2>&1
	checks "Yoast SEO $( $W plugin get wordpress-seo --field=version )"
	echo "== Yoast SEO sitemap and robots"
	curl -s "$H/sitemap_index.xml" > $T/ysm.xml
	ok "Yoast sitemap index has pages but no author sitemap" $(grep -q 'page-sitemap.xml' $T/ysm.xml && ! grep -q 'author-sitemap' $T/ysm.xml && echo 1 || echo 0)
	$W option patch update wpseo disable-author false >/dev/null 2>&1
	ev 'WPSEO_Sitemaps_Cache::clear();' >/dev/null
	curl -s "$H/author-sitemap.xml" > $T/asm.xml
	ok "even with author archives switched back on, no usernames in the author sitemap" $(grep -q 'seoauthor\|/author/' $T/asm.xml && echo 0 || echo 1)
	$W option patch update wpseo disable-author true >/dev/null 2>&1
	curl -s "$H/page-sitemap.xml" > $T/ypages.xml
	ok "member page not in Yoast's page sitemap" $(grep -q "$(ev "echo get_permalink($PAGE_ID);")<" $T/ypages.xml && echo 0 || echo 1)
	curl -s "$(ev "echo get_permalink($PAGE_ID);")" > $T/mp.html
	ok "member page: robots tag says noindex, nofollow" $(grep -oiE '<meta name=.robots.[^>]*>' $T/mp.html | grep -qi 'noindex, nofollow' && echo 1 || echo 0)
	ok "member page: only one robots tag" $([ "$(grep -oiE '<meta name=.robots.' $T/mp.html | wc -l)" = 1 ] && echo 1 || echo 0)
	$W plugin deactivate wordpress-seo >/dev/null 2>&1
fi

$W post delete $POST --force >/dev/null 2>&1
$W user delete seoauthor seomember --yes >/dev/null 2>&1
echo "== $PASS passed, $FAIL failed"
[ $FAIL = 0 ]
