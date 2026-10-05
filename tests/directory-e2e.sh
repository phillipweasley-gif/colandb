#!/usr/bin/env bash
# End-to-end test of Members (search and filter) and the profile view
# (Community Member Planning 0.13.0): who is listed, that only answers shown
# to all members are matched, each filter, profile items linking to a
# filtered list, the cover image on profiles, the opt-out, export/erase.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/directory-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/dir; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -b $T/$1.jar -c $T/$1.jar -o $T/$2.html "$3"; }
uid()  { $W user get $1 --field=ID 2>/dev/null; }
ev()   { $W eval "$1" 2>&1; }
member(){ ev "\$i=get_user_by('login','$1')->ID; update_user_meta(\$i,'cmp_verified_email','$1@example.com'); update_user_meta(\$i,'cmp_email_verified_at',gmdate('Y-m-d H:i:s')); update_user_meta(\$i,'cmp_birth_date','$2'); CMP_Access::record_attestation(\$i); update_user_meta(\$i,'cmp_setup_done','later');" >/dev/null; }
opt()  { ev "echo array_search('$2', CMP_Profile_Fields::options('$1'));"; }
save() { ev "CMP_Profiles::save_field($1,'$2',$3,'$4');" >/dev/null; }
TBL()  { ev "echo CMP_Install::table('$1');"; }
# Names listed on a results page (from the result cards only).
names(){ php -r 'preg_match_all("~class=\"cmp-dir-card\".*?<b>([^<]+)</b>~s",file_get_contents($argv[1]),$m); echo implode(",",$m[1]);' "$1"; }

rm -rf wp-content/plugins/community-member-planning && cp -r $REPO/community-member-planning wp-content/plugins/
$W plugin activate community-member-planning >/dev/null 2>&1
ev 'CMP_Install::maybe_upgrade();' >/dev/null
# Fresh starter lists (other suites rename or empty some).
ev 'delete_option("cmp_profile_options"); delete_option("cmp_kinks_grouped"); CMP_Profile_Fields::seed_defaults(); CMP_Profile_Fields::upgrade_kinks();' >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
PAGE_ID=$(ev 'echo (int) CMP_Settings::get("member_page_id");')
PAGE="$H/?page_id=$PAGE_ID"
B_T=$(TBL blocks); F_T=$(TBL follows); I_T=$(TBL profile_images)
ev "global \$wpdb; foreach(array('$B_T','$F_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
# Hide every other test member from search so results only show this suite's people.
ev "foreach(get_users(array('fields'=>'ID')) as \$i) update_user_meta(\$i,'cmp_hide_from_search',1);" >/dev/null
for u in seeker alpha bravo charlie delta echo; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create seeker seeker@example.com --role=subscriber --user_pass=seekerpass1234 --display_name="Seeker Sam" >/dev/null
$W user create alpha alpha@example.com --role=subscriber --user_pass=alphapass12345 --display_name="Alpha Leather" >/dev/null
$W user create bravo bravo@example.com --role=subscriber --user_pass=bravopass12345 --display_name="Bravo Quiet" >/dev/null
$W user create charlie charlie@example.com --role=subscriber --user_pass=charliepass123 --display_name="Charlie Hidden" >/dev/null
$W user create delta delta@example.com --role=subscriber --user_pass=deltapass12345 --display_name="Delta Blocked" >/dev/null
$W user create echo echo@example.com --role=subscriber --user_pass=echopass123456 --display_name="Echo Unverified" >/dev/null
member seeker 1985-01-01; member alpha 1980-03-01; member bravo 1990-06-01; member charlie 1980-01-01; member delta 1980-01-01
SEEKER=$(uid seeker); ALPHA=$(uid alpha); BRAVO=$(uid bravo); CHARLIE=$(uid charlie); DELTA=$(uid delta)
ev "foreach(array($SEEKER,$ALPHA,$BRAVO,$DELTA) as \$i) delete_user_meta(\$i,'cmp_hide_from_search'); update_user_meta($CHARLIE,'cmp_hide_from_search',1);" >/dev/null
DOM=$(opt roles Dominant); KH=$(opt looking_for Keyholder); LEATHER=$(opt kinks Leather); ATH=$(opt body_type Athletic)
# Alpha shows everything; Bravo shows a role only to connections and a city to members.
save $ALPHA roles "array('keys'=>array('$DOM'))" members
save $ALPHA looking_for "array('keys'=>array('$KH'))" members
save $ALPHA kinks "array(array('k'=>'$LEATHER','lvl'=>'love','dir'=>''))" members
save $ALPHA location "array('city'=>'Columbus','region'=>'Ohio')" members
save $ALPHA body_type "'$ATH'" members
save $ALPHA age null members
save $BRAVO roles "array('keys'=>array('$DOM'))" connections
save $BRAVO location "array('city'=>'Dayton','region'=>'Ohio')" members
save $DELTA roles "array('keys'=>array('$DOM'))" members
ev "global \$wpdb; \$wpdb->insert('$B_T',array('blocker_id'=>$DELTA,'blocked_id'=>$SEEKER,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
login seeker seeker seekerpass1234

echo "== Who is listed"
get seeker m0 "$PAGE&cmp_tab=members"
ok "Members tab with filters" $([ "$(has $T/m0.html 'cmp_tab=members')$(has $T/m0.html 'name="roles"')$(has $T/m0.html 'name="city"')" = 111 ] && echo 1 || echo 0)
ok "lists Alpha and Bravo only (not self, opted out, blocked or unverified)" $([ "$(names $T/m0.html | tr ',' '\n' | sort | tr '\n' ',')" = "Alpha Leather,Bravo Quiet," ] && echo 1 || echo 0)

echo "== Filters"
get seeker m1 "$PAGE&cmp_tab=members&roles=$DOM"
ok "role Dominant: Alpha, not Bravo (whose role is connections-only)" $([ "$(names $T/m1.html)" = "Alpha Leather" ] && echo 1 || echo 0)
ok "active filter chip with a remove link" $(has $T/m1.html 'Roles: Dominant')
get seeker m2 "$PAGE&cmp_tab=members&city=dayton"
ok "city (any case): Bravo" $([ "$(names $T/m2.html)" = "Bravo Quiet" ] && echo 1 || echo 0)
get seeker m3 "$PAGE&cmp_tab=members&kinks=$LEATHER"
ok "kink: Alpha" $([ "$(names $T/m3.html)" = "Alpha Leather" ] && echo 1 || echo 0)
get seeker m4 "$PAGE&cmp_tab=members&age_min=40&age_max=50"
ok "age range: only members who show their age" $([ "$(names $T/m4.html)" = "Alpha Leather" ] && echo 1 || echo 0)
get seeker m5 "$PAGE&cmp_tab=members&q=bravo"
ok "name search" $([ "$(names $T/m5.html)" = "Bravo Quiet" ] && echo 1 || echo 0)
get seeker m6 "$PAGE&cmp_tab=members&roles=not-a-role"
ok "an unknown option is ignored" $([ "$(names $T/m6.html | tr ',' '\n' | grep -c .)" = 2 ] && echo 1 || echo 0)
get seeker m7 "$PAGE&cmp_tab=members&photo=1"
ok "with a profile photo: none yet" $(has $T/m7.html '0 members')
ev "global \$wpdb; \$wpdb->insert('$F_T',array('follower_id'=>$SEEKER,'followed_id'=>$BRAVO,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
get seeker m8 "$PAGE&cmp_tab=members&following=1"
ok "people I follow" $([ "$(names $T/m8.html)" = "Bravo Quiet" ] && echo 1 || echo 0)
get seeker m9 "$PAGE&cmp_tab=members&sort=name"
ok "sort by name" $([ "$(names $T/m9.html)" = "Alpha Leather,Bravo Quiet" ] && echo 1 || echo 0)

echo "== Profile view"
php -r '$i=imagecreatetruecolor(1500,500); imagefill($i,0,0,imagecolorallocate($i,30,80,160)); imagejpeg($i,$argv[1]);' $T/cover.jpg
ev "global \$wpdb; \$d=file_get_contents('$T/cover.jpg'); \$wpdb->replace('$I_T',array('user_id'=>$ALPHA,'kind'=>'cover','mime'=>'image/jpeg','width'=>1500,'height'=>500,'bytes'=>strlen(\$d),'sha256'=>hash('sha256',\$d),'data'=>\$d,'alt'=>'','decorative'=>1,'created_at'=>gmdate('Y-m-d H:i:s'))); CMP_Profiles::save_field($ALPHA,'cover',null,'members');" >/dev/null
get seeker p1 "$PAGE&cmp_member=$ALPHA"
ok "cover image shown across the top" $(php -r '$h=file_get_contents($argv[1]); exit(preg_match("~class=\"cmp-pv-cover\"><img src=\"[^\"]*cmp_photo=".$argv[2]."-cover~",$h)?0:1);' $T/p1.html $ALPHA && echo 1 || echo 0)
ok "tabs: About and Kinks" $([ "$(has $T/p1.html 'data-cmp-pv-tab="about"')$(has $T/p1.html 'data-cmp-pv-tab="kinks"')" = 11 ] && echo 1 || echo 0)
ok "Follow / Message in the header" $(php -r '$h=file_get_contents($argv[1]); $a=strpos($h,"cmp-pv-actions"); $t=strpos($h,"data-cmp-pv-tabs"); exit($a && $t && $a<$t && false!==strpos(substr($h,$a,$t-$a),">Follow</button>")?0:1);' $T/p1.html && echo 1 || echo 0)
ok "items link to Members filtered by them (role, looking for, kink, body type, city)" $([ "$(has $T/p1.html "cmp_tab=members&#038;roles=$DOM")$(has $T/p1.html "looking_for=$KH")$(has $T/p1.html "kinks=$LEATHER")$(has $T/p1.html "body_type=$ATH")$(has $T/p1.html "city=Columbus")" = 11111 ] && echo 1 || echo 0)
get seeker p2 "$PAGE&cmp_member=$BRAVO"
ok "a connections-only answer isn't shown or linked to a non-connection" $(hasnt $T/p2.html "roles=$DOM")

echo "== Opting out"
login bravo bravo bravopass12345
get bravo b0 "$PAGE&cmp_tab=profile"
ok "Profile tab: 'Hide me from member search'" $(has $T/b0.html 'id="cmp_dir_hide"')
BN=$(php -r 'preg_match("~name=\"action\" value=\"cmp_directory\".*?name=\"_cmp_nonce\" value=\"([^\"]+)\"~s",file_get_contents($argv[1]),$m); echo $m[1]??"";' $T/b0.html)
curl -s -b $T/bravo.jar -o /dev/null "$H/wp-admin/admin-post.php" --data-urlencode "action=cmp_directory" --data-urlencode "_cmp_nonce=$BN" --data-urlencode "hide=1"
get seeker m10 "$PAGE&cmp_tab=members"
ok "opted out: no longer listed" $([ "$(names $T/m10.html)" = "Alpha Leather" ] && echo 1 || echo 0)

echo "== Privacy"
ok "export says whether you're hidden" $(ev "\$j=wp_json_encode(CMP_Account::export('bravo@example.com')); echo false!==strpos(\$j,'Hidden from member search')?1:0;")
ok "erase removes the setting" $(ev "CMP_Account::erase('bravo@example.com'); echo ''===get_user_meta($BRAVO,'cmp_hide_from_search',true)?1:0;")

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev "global \$wpdb; foreach(array('$B_T','$F_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\"); foreach(get_users(array('fields'=>'ID')) as \$i) delete_user_meta(\$i,'cmp_hide_from_search');" >/dev/null
for u in seeker alpha bravo charlie delta echo; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
