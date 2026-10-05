#!/usr/bin/env bash
# End-to-end test of the member feed (Community Member Planning 0.9.0):
# posting text and photos, event tags, who sees what (members /
# connections / signed out / not yet members), photo privacy, likes,
# reports, admin hide, delete, posts on profiles, export/erase.
# Test photos are made with PHP's GD (no ImageMagick needed).
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/feed-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/fd; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
hnonce(){ grep -o 'name="_cmp_nonce" value="[^"]*"' $1 | head -1 | cut -d'"' -f4; }
# act <user> <nonce> <do> [curl -F args...]: post to the feed handler, print the redirect.
act()  { local u=$1 n=$2 d=$3; shift 3; curl -s -b $T/$u.jar -c $T/$u.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" -F "action=cmp_feed" -F "_cmp_nonce=$n" -F "do=$d" "$@"; }
uid()  { $W user get $1 --field=ID 2>/dev/null; }
ev()   { $W eval "$1" 2>&1; }
member(){ ev "\$i=get_user_by('login','$1')->ID; update_user_meta(\$i,'cmp_verified_email','$1@example.com'); update_user_meta(\$i,'cmp_email_verified_at',gmdate('Y-m-d H:i:s')); update_user_meta(\$i,'cmp_birth_date','1980-01-01'); CMP_Access::record_attestation(\$i); update_user_meta(\$i,'cmp_setup_done','later');" >/dev/null; }
q()    { ev "global \$wpdb; echo \$wpdb->get_var(\"$1\");"; }
TBL()  { ev "echo CMP_Install::table('$1');"; }

rm -rf wp-content/plugins/community-member-planning && cp -r $REPO/community-member-planning wp-content/plugins/
$W plugin activate community-member-planning >/dev/null 2>&1
ev 'CMP_Install::maybe_upgrade();' >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
PAGE_ID=$(ev 'echo (int) CMP_Settings::get("member_page_id");')
PAGE="$H/?page_id=$PAGE_ID"
P_T=$(TBL posts); PH_T=$(TBL post_photos); LK_T=$(TBL post_likes); D_T=$(TBL dynamics)
ev "global \$wpdb; foreach(array('$P_T','$PH_T','$LK_T','$D_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
for u in ann ben nosy newbie mod; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create ann ann@example.com --role=subscriber --user_pass=annpass123456 --display_name="Ann Author" >/dev/null
$W user create ben ben@example.com --role=subscriber --user_pass=benpass123456 --display_name="Ben Connected" >/dev/null
$W user create nosy nosy@example.com --role=subscriber --user_pass=nosypass12345 --display_name="Nosy Member" >/dev/null
$W user create newbie newbie@example.com --role=subscriber --user_pass=newbiepass1234 >/dev/null
$W user create mod mod@example.com --role=administrator --user_pass=modpass123456 --display_name="Site Mod" >/dev/null
member ann; member ben; member nosy; member mod
ANN=$(uid ann); BEN=$(uid ben); NOSY=$(uid nosy)
ev "global \$wpdb; \$wpdb->insert('$D_T',array('type'=>'partners','proposer_id'=>$ANN,'partner_id'=>$BEN,'proposer_side'=>'','status'=>'active','proposer_show'=>1,'partner_show'=>1,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
# Events: one a week ago (taggable), one 100 days ago (not).
EV1=$($W post create --post_type=cec_event --post_status=publish --post_title="ZZ Leather Night" --porcelain 2>/dev/null)
EV2=$($W post create --post_type=cec_event --post_status=publish --post_title="ZZ Old Event" --porcelain 2>/dev/null)
ev "update_post_meta($EV1,'_cec_start',wp_date('Y-m-d\\TH:i',time()-7*DAY_IN_SECONDS)); update_post_meta($EV2,'_cec_start',wp_date('Y-m-d\\TH:i',time()-100*DAY_IN_SECONDS));" >/dev/null
php -r '$i=imagecreatetruecolor(1600,1200); imagefill($i,0,0,imagecolorallocate($i,160,40,90)); imagejpeg($i,$argv[1],90); $d=file_get_contents($argv[1]); $exif="Exif\0\0MM\0*\0\0\0\x08GPSLatitude SECRET-GPS-MARKER"; file_put_contents($argv[1],substr($d,0,2)."\xFF\xE1".pack("n",strlen($exif)+2).$exif.substr($d,2));' $T/a.jpg
php -r '$i=imagecreatetruecolor(2400,1800); imagefill($i,0,0,imagecolorallocate($i,40,90,160)); imagepng($i,$argv[1]);' $T/b.png
echo "not an image" > $T/fake.jpg
rm -f wp-content/mail.log

echo "== Access"
get anon a0 "$PAGE&cmp_tab=feed"
ok "signed out: no feed" $(hasnt $T/a0.html 'cmp_fd_body')
login newbie newbie newbiepass1234
get newbie w0 "$PAGE&cmp_tab=feed"
ok "not yet a full member: no feed" $(hasnt $T/w0.html 'cmp_fd_body')
login ann ann annpass123456
get ann a1 "$PAGE&cmp_tab=feed"
ok "Feed tab: compose form, empty feed, tag list" $([ "$(has $T/a1.html 'cmp_fd_body')$(has $T/a1.html 'No posts yet')$(has $T/a1.html 'ZZ Leather Night')$(hasnt $T/a1.html 'ZZ Old Event')" = 1111 ] && echo 1 || echo 0)
ok "Feed is a member-area tab" $(has $T/a1.html 'cmp_tab=feed')
AN=$(hnonce $T/a1.html)

echo "== Posting"
r=$(act ann $AN post -F "body=" -F "visibility=members")
ok "empty post refused" $(echo "$r" | grep -q 'fd_empty' && echo 1 || echo 0)
r=$(act ann $AN post -F "body=hi" -F "visibility=public")
ok "unknown audience refused" $(echo "$r" | grep -q 'fd_invalid' && echo 1 || echo 0)
r=$(act ann $AN post -F "body=$(head -c 2100 /dev/zero | tr '\0' 'x')" -F "visibility=members")
ok "over 2,000 characters refused" $(echo "$r" | grep -q 'fd_invalid' && echo 1 || echo 0)
r=$(act ann $AN post -F "body=old" -F "visibility=members" -F "event=$EV2")
ok "can't tag an event outside the window" $(echo "$r" | grep -q 'fd_invalid' && echo 1 || echo 0)
r=$(act ann $AN post -F "body=x" -F "visibility=members" -F "photos[]=@$T/fake.jpg;type=image/jpeg")
ok "non-image refused" $(echo "$r" | grep -q 'fd_photo' && [ "$(q "SELECT COUNT(*) FROM $P_T")" = 0 ] && echo 1 || echo 0)
r=$(act ann $AN post -F "body=x" -F "visibility=members" -F "photos[]=@$T/a.jpg;type=image/jpeg" -F "photos[]=@$T/a.jpg;type=image/jpeg" -F "photos[]=@$T/a.jpg;type=image/jpeg" -F "photos[]=@$T/a.jpg;type=image/jpeg" -F "photos[]=@$T/a.jpg;type=image/jpeg")
ok "more than 4 photos refused" $(echo "$r" | grep -q 'fd_photo' && [ "$(q "SELECT COUNT(*) FROM $P_T")" = 0 ] && echo 1 || echo 0)
r=$(act ann $AN post -F "body=Great turnout at Leather Night! <script>alert(1)</script>" -F "visibility=members" -F "event=$EV1" -F "photos[]=@$T/a.jpg;type=image/jpeg" -F "photos[]=@$T/b.png;type=image/png")
P1=$(q "SELECT MAX(id) FROM $P_T")
ok "text + 2 photos + event tag posted" $(echo "$r" | grep -q 'fd_posted' && [ "$(q "SELECT COUNT(*) FROM $PH_T WHERE post_id=$P1")" = 2 ] && [ "$(q "SELECT event_id FROM $P_T WHERE id=$P1")" = "$EV1" ] && echo 1 || echo 0)
r=$(act ann $AN post -F "body=Just for my partner" -F "visibility=connections")
P2=$(q "SELECT MAX(id) FROM $P_T")
ok "connections-only post" $([ "$(q "SELECT visibility FROM $P_T WHERE id=$P2")" = connections ] && echo 1 || echo 0)
PH1=$(q "SELECT id FROM $PH_T WHERE post_id=$P1 ORDER BY position LIMIT 1")
PH2=$(q "SELECT id FROM $PH_T WHERE post_id=$P1 ORDER BY position DESC LIMIT 1")

echo "== Who sees what"
login ben ben benpass123456
login nosy nosy nosypass12345
get ben b1 "$PAGE&cmp_tab=feed"
get nosy n1 "$PAGE&cmp_tab=feed"
ok "connection sees both posts" $([ "$(has $T/b1.html 'Great turnout')$(has $T/b1.html 'Just for my partner')" = 11 ] && echo 1 || echo 0)
ok "other member sees only the members post" $([ "$(has $T/n1.html 'Great turnout')$(hasnt $T/n1.html 'Just for my partner')" = 11 ] && echo 1 || echo 0)
ok "HTML in a post never reaches the page" $([ "$(hasnt $T/n1.html '<script>alert(1)')$(hasnt $T/n1.html 'alert(1)</script>')" = 11 ] && echo 1 || echo 0)
ok "event tag links to the event filter" $(has $T/n1.html "cmp_event=$EV1")
get nosy n2 "$PAGE&cmp_tab=feed&cmp_event=$EV1"
ok "event filter shows its posts" $([ "$(has $T/n2.html 'Posts tagged with ZZ Leather Night')$(has $T/n2.html 'Great turnout')" = 11 ] && echo 1 || echo 0)
get nosy n2b "$PAGE&cmp_tab=feed&cmp_post=$P2"
ok "single connections post not available to others" $([ "$(hasnt $T/n2b.html 'Just for my partner')$(has $T/n2b.html 'available')" = 11 ] && echo 1 || echo 0)

echo "== Photos"
code_of(){ curl -s -b $T/$1.jar -o $T/pic.$1 -w '%{http_code}' "$H/?cmp_postpic=$2"; }
ok "member sees the photo" $([ "$(code_of nosy $PH1)" = 200 ] && echo 1 || echo 0)
ok "GPS marker gone, JPEG" $(php -r '$d=file_get_contents($argv[1]); exit("\xFF\xD8\xFF"===substr($d,0,3) && false===strpos($d,"SECRET-GPS-MARKER")?0:1);' $T/pic.nosy && echo 1 || echo 0)
code_of nosy $PH2 >/dev/null
ok "PNG re-encoded as JPEG, resized to 1600px" $(php -r '$s=getimagesize($argv[1]); exit(IMAGETYPE_JPEG===$s[2] && max($s[0],$s[1])<=1600?0:1);' $T/pic.nosy && echo 1 || echo 0)
ok "signed out: 404" $([ "$(curl -s -o /dev/null -w '%{http_code}' "$H/?cmp_postpic=$PH1")" = 404 ] && echo 1 || echo 0)
ok "not yet a member: 404" $([ "$(code_of newbie $PH1)" = 404 ] && echo 1 || echo 0)
ok "private, no-cache, noindex headers" $(curl -s -D - -o /dev/null -b $T/nosy.jar "$H/?cmp_postpic=$PH1" | tr -d '\r' | grep -ci 'cache-control: private, no-cache\|x-robots-tag: noindex' | grep -q 2 && echo 1 || echo 0)

echo "== Likes, reports"
NN=$(hnonce $T/n1.html); BN=$(hnonce $T/b1.html)
r=$(act nosy $NN like -F "post=$P1")
ok "like" $([ "$(q "SELECT COUNT(*) FROM $LK_T WHERE post_id=$P1")" = 1 ] && echo 1 || echo 0)
r=$(act nosy $NN like -F "post=$P1")
ok "unlike" $([ "$(q "SELECT COUNT(*) FROM $LK_T WHERE post_id=$P1")" = 0 ] && echo 1 || echo 0)
r=$(act nosy $NN like -F "post=$P2")
ok "can't like a post you can't see" $(echo "$r" | grep -q 'fd_gone' && [ "$(q "SELECT COUNT(*) FROM $LK_T WHERE post_id=$P2")" = 0 ] && echo 1 || echo 0)
r=$(act ben $BN like -F "post=$P1")
r=$(act nosy $NN report -F "post=$P1" -F "reason=Not appropriate")
ok "report counted, admin emailed" $([ "$(q "SELECT reports FROM $P_T WHERE id=$P1")" = 1 ] && grep -q 'admin@example.com' wp-content/mail.log 2>/dev/null && grep -q 'was reported' wp-content/mail.log && echo 1 || echo 0)
r=$(act nosy $NN report -F "post=$P1")
ok "one report per member" $([ "$(q "SELECT reports FROM $P_T WHERE id=$P1")" = 1 ] && echo 1 || echo 0)
r=$(act ann $AN report -F "post=$P1")
ok "can't report your own post" $(echo "$r" | grep -q 'fd_gone' && echo 1 || echo 0)
r=$(act nosy $NN hide -F "post=$P1")
ok "members can't hide posts" $(echo "$r" | grep -q 'fd_gone' && [ "$(q "SELECT status FROM $P_T WHERE id=$P1")" = published ] && echo 1 || echo 0)
r=$(act nosy $NN delete -F "post=$P1")
ok "...or delete others' posts" $(echo "$r" | grep -q 'fd_gone' && [ "$(q "SELECT COUNT(*) FROM $P_T WHERE id=$P1")" = 1 ] && echo 1 || echo 0)

echo "== Profiles"
get nosy n3 "$PAGE&cmp_member=$ANN"
ok "profile shows posts the viewer may see" $([ "$(has $T/n3.html 'Great turnout')$(hasnt $T/n3.html 'Just for my partner')" = 11 ] && echo 1 || echo 0)

echo "== Admin hide, delete"
login mod mod modpass123456
get mod m1 "$PAGE&cmp_tab=feed&cmp_post=$P1"
MN=$(hnonce $T/m1.html)
ok "admin sees Hide with the report count" $(has $T/m1.html 'Hide (admin) · 1 reports')
r=$(act mod $MN hide -F "post=$P1")
ok "admin hides it" $([ "$(q "SELECT status FROM $P_T WHERE id=$P1")" = hidden ] && echo 1 || echo 0)
get nosy n4 "$PAGE&cmp_tab=feed"
ok "hidden post gone for members, photo 404" $([ "$(hasnt $T/n4.html 'Great turnout')" = 1 ] && [ "$(code_of nosy $PH1)" = 404 ] && echo 1 || echo 0)
get ann a2 "$PAGE&cmp_tab=feed"
ok "author still sees it, marked hidden" $([ "$(has $T/a2.html 'Great turnout')$(has $T/a2.html 'hidden by the site team')" = 11 ] && echo 1 || echo 0)
r=$(act ann $AN delete -F "post=$P1")
ok "author deletes: post, photos and likes gone" $(echo "$r" | grep -q 'fd_deleted' && [ "$(q "SELECT COUNT(*) FROM $P_T WHERE id=$P1")$(q "SELECT COUNT(*) FROM $PH_T WHERE post_id=$P1")$(q "SELECT COUNT(*) FROM $LK_T WHERE post_id=$P1")" = 000 ] && echo 1 || echo 0)

echo "== Dynamic ends"
ev "global \$wpdb; \$wpdb->query(\"UPDATE $D_T SET status='ended'\");" >/dev/null
get ben b2 "$PAGE&cmp_tab=feed"
ok "connections post disappears for the former connection" $(hasnt $T/b2.html 'Just for my partner')

echo "== Privacy"
ok "export lists posts" $(ev "\$j=wp_json_encode(CMP_Account::export('ann@example.com'),JSON_UNESCAPED_SLASHES); echo false!==strpos(\$j,'Just for my partner')?1:0;")
r=$(act ben $BN like -F "post=$P2")
ok "erase removes posts and likes" $(ev "CMP_Account::erase('ann@example.com'); global \$wpdb; echo 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $P_T WHERE author_id=$ANN\") && 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $LK_T WHERE post_id=$P2\")?1:0;")

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev "global \$wpdb; foreach(array('$P_T','$PH_T','$LK_T','$D_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
$W post delete $EV1 $EV2 --force >/dev/null 2>&1
for u in ann ben nosy newbie mod; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
