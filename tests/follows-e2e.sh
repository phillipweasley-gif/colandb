#!/usr/bin/env bash
# End-to-end test of follow / unfollow (Community Member Planning 0.12.0,
# with Community Events Calendar 1.28.0): following, the Following feed
# filter, followers and My calendar (0.17.0: who sees each event is chosen
# per event), the calendar label, notifications,
# blocks, export/erase.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/follows-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/fl; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
fnonce(){ php -r 'preg_match("~name=\"action\" value=\"cmp_follow\".*?name=\"_cmp_nonce\" value=\"([^\"]+)\"~s",file_get_contents($argv[1]),$m); echo $m[1]??"";' "$1"; }
act()  { local u=$1 n=$2 d=$3; shift 3; curl -s -b $T/$u.jar -c $T/$u.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" --data-urlencode "action=cmp_follow" --data-urlencode "_cmp_nonce=$n" --data-urlencode "do=$d" "$@"; }
uid()  { $W user get $1 --field=ID 2>/dev/null; }
ev()   { $W eval "$1" 2>&1; }
member(){ ev "\$i=get_user_by('login','$1')->ID; update_user_meta(\$i,'cmp_verified_email','$1@example.com'); update_user_meta(\$i,'cmp_email_verified_at',gmdate('Y-m-d H:i:s')); update_user_meta(\$i,'cmp_birth_date','1980-01-01'); CMP_Access::record_attestation(\$i); update_user_meta(\$i,'cmp_setup_done','later');" >/dev/null; }
q()    { ev "global \$wpdb; echo \$wpdb->get_var(\"$1\");"; }
TBL()  { ev "echo CMP_Install::table('$1');"; }

for p in community-events-calendar community-member-planning; do rm -rf wp-content/plugins/$p && cp -r $REPO/$p wp-content/plugins/; $W plugin activate $p >/dev/null 2>&1; done
ev 'CMP_Install::maybe_upgrade(); CEC_RSVP::create_table();' >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
PAGE_ID=$(ev 'echo (int) CMP_Settings::get("member_page_id");')
PAGE="$H/?page_id=$PAGE_ID"
F_T=$(TBL follows); P_T=$(TBL posts); B_T=$(TBL blocks); N_T=$(TBL notifications); R_T=$(ev 'global $wpdb; echo $wpdb->prefix.CEC_TABLE_RSVP;')
ev "global \$wpdb; \$wpdb->query(\"DELETE FROM \".CMP_Install::table('calendar')); foreach(array('$F_T','$P_T','$B_T','$R_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
for u in fan star other; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create fan fan@example.com --role=subscriber --user_pass=fanpass123456 --display_name="Fan Member" >/dev/null
$W user create star star@example.com --role=subscriber --user_pass=starpass12345 --display_name="Star Member" >/dev/null
$W user create other other@example.com --role=subscriber --user_pass=otherpass1234 --display_name="Other Member" >/dev/null
member fan; member star; member other
FAN=$(uid fan); STAR=$(uid star); OTHER=$(uid other)
# Events: upcoming, past, upcoming draft.
EV1=$($W post create --post_type=cec_event --post_status=publish --post_title="ZZ Upcoming Night" --porcelain 2>/dev/null)
EV2=$($W post create --post_type=cec_event --post_status=publish --post_title="ZZ Past Night" --porcelain 2>/dev/null)
EV3=$($W post create --post_type=cec_event --post_status=draft --post_title="ZZ Draft Night" --porcelain 2>/dev/null)
ev "update_post_meta($EV1,'_cec_start',wp_date('Y-m-d\\T19:00',time()+5*DAY_IN_SECONDS)); update_post_meta($EV2,'_cec_start',wp_date('Y-m-d\\T19:00',time()-5*DAY_IN_SECONDS)); update_post_meta($EV3,'_cec_start',wp_date('Y-m-d\\T19:00',time()+6*DAY_IN_SECONDS));" >/dev/null
login fan fan fanpass123456; login star star starpass12345; login other other otherpass1234

echo "== Following"
get fan f0 "$PAGE&cmp_member=$STAR"
FN=$(fnonce $T/f0.html)
ok "profile: Follow button and counts" $([ "$(has $T/f0.html '>Follow</button>')$(has $T/f0.html '0 followers · 0 following')" = 11 ] && echo 1 || echo 0)
ok "Star doesn't share events: no Going to card" $(hasnt $T/f0.html 'cmp-fl-going')
r=$(act fan $FN follow --data-urlencode "member=$STAR")
ok "follow" $(echo "$r" | grep -q 'fl_followed' && [ "$(q "SELECT COUNT(*) FROM $F_T WHERE follower_id=$FAN AND followed_id=$STAR")" = 1 ] && echo 1 || echo 0)
act fan $FN follow --data-urlencode "member=$STAR" >/dev/null
ok "following twice is still one follow" $([ "$(q "SELECT COUNT(*) FROM $F_T")" = 1 ] && echo 1 || echo 0)
get fan f1 "$PAGE&cmp_member=$STAR"
ok "button now says Following; count 1" $([ "$(has $T/f1.html '>Following</button>')$(has $T/f1.html '1 follower · 0 following')" = 11 ] && echo 1 || echo 0)
r=$(act fan $FN follow --data-urlencode "member=$FAN")
ok "can't follow yourself" $(echo "$r" | grep -q 'fl_gone' && echo 1 || echo 0)
r=$(act fan $FN follow --data-urlencode "member=999999")
ok "can't follow a non-member" $(echo "$r" | grep -q 'fl_gone' && echo 1 || echo 0)

echo "== Calendar on profiles (0.17.0)"
get star s0 "$PAGE&cmp_tab=profile"
ok "Profile tab: followers section points to My calendar (no old switch)" $([ "$(has $T/s0.html 'Followers and your events')$(hasnt $T/s0.html 'id="cmp_show_going"')" = 11 ] && echo 1 || echo 0)
ok "Star adds events (a draft can't be added)" $([ "$(ev "echo CMP_Calendar::save($STAR,$EV1,'going','private',false).CMP_Calendar::save($STAR,$EV2,'going','members',false).CMP_Calendar::save($STAR,$EV3,'going','members',false);")" = okokgone ] && echo 1 || echo 0)
ok "private (and past) events: no notification to followers" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$FAN AND category='follow'")" = 0 ] && echo 1 || echo 0)
ok "private: no calendar label for the follower" $([ -z "$(ev "wp_set_current_user($FAN); echo apply_filters('cec_event_social_label','',$EV1);")" ] && echo 1 || echo 0)
ev "CMP_Calendar::save($STAR,$EV1,'going','members',false);" >/dev/null
ok "shown to all members: followers told (once), non-followers not" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$FAN AND category='follow' AND message LIKE '%going to ZZ Upcoming Night%'")$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$OTHER AND category='follow'")" = 10 ] && echo 1 || echo 0)
get fan f2 "$PAGE&cmp_member=$STAR"
ok "profile Calendar shows upcoming published events only" $([ "$(has $T/f2.html 'ZZ Upcoming Night')$(hasnt $T/f2.html 'ZZ Past Night')$(hasnt $T/f2.html 'ZZ Draft Night')" = 111 ] && echo 1 || echo 0)
get other o0 "$PAGE&cmp_member=$STAR"
ok "all-members events need no follow" $(has $T/o0.html 'ZZ Upcoming Night')
get star s1 "$PAGE&cmp_member=$STAR"
ok "Star's own view explains who sees it" $([ "$(has $T/s1.html 'ZZ Upcoming Night')$(has $T/s1.html 'Members see the events you show to all members')" = 11 ] && echo 1 || echo 0)

echo "== Calendar label"
ok "'1 person you follow is going' for the follower" $([ "$(ev "wp_set_current_user($FAN); echo apply_filters('cec_event_social_label','',$EV1);")" = "1 person you follow is going" ] && echo 1 || echo 0)
ok "nothing for a non-follower" $([ -z "$(ev "wp_set_current_user($OTHER); echo apply_filters('cec_event_social_label','',$EV1);")" ] && echo 1 || echo 0)
ok "calendar items carry it (desktop and phone)" $(ev "wp_set_current_user($FAN); \$i=CEC_Month_Grid::item_from_event_data(CEC_Event_Helper::data($EV1)); echo '1 person you follow is going'===\$i['detail']['social']?1:0;")
ev "CMP_Calendar::save($STAR,$EV1,'interested','members',false);" >/dev/null
ok "only Going counts" $([ -z "$(ev "wp_set_current_user($FAN); echo apply_filters('cec_event_social_label','',$EV1);")" ] && echo 1 || echo 0)
ev "CMP_Calendar::save($STAR,$EV1,'going','members',false);" >/dev/null

echo "== Notifications"
get star s2 "$PAGE&cmp_tab=feed"
PN=$(php -r 'preg_match("~name=\"action\" value=\"cmp_feed\".*?name=\"_cmp_nonce\" value=\"([^\"]+)\"~s",file_get_contents($argv[1]),$m); echo $m[1]??"";' $T/s2.html)
curl -s -b $T/star.jar -o /dev/null "$H/wp-admin/admin-post.php" -F "action=cmp_feed" -F "_cmp_nonce=$PN" -F "do=post" -F "body=Star post for everyone" -F "visibility=members"
curl -s -b $T/other.jar -o /dev/null "$H/wp-admin/admin-post.php" -F "action=cmp_feed" -F "_cmp_nonce=$(php -r 'preg_match("~name=\"action\" value=\"cmp_feed\".*?name=\"_cmp_nonce\" value=\"([^\"]+)\"~s",file_get_contents($argv[1]),$m); echo $m[1]??"";' <(curl -s -b $T/other.jar "$PAGE&cmp_tab=feed"))" -F "do=post" -F "body=Other post" -F "visibility=members"
ok "new post notifies followers" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$FAN AND category='follow' AND message LIKE '%shared a new post%'")" = 1 ] && echo 1 || echo 0)

echo "== Following filter"
get fan f3 "$PAGE&cmp_tab=feed"
get fan f4 "$PAGE&cmp_tab=feed&cmp_following=1"
ok "Everyone shows both; Following only the followed member" $([ "$(has $T/f3.html 'Star post for everyone')$(has $T/f3.html 'Other post')$(has $T/f4.html 'Star post for everyone')$(hasnt $T/f4.html 'Other post')$(has $T/f4.html 'aria-current="page" class="is-current">Following')" = 11111 ] && echo 1 || echo 0)
get other o1 "$PAGE&cmp_tab=feed&cmp_following=1"
ok "Following with nobody followed: a hint" $(has $T/o1.html 'No posts from people you follow yet')

echo "== Blocks"
ev "global \$wpdb; \$wpdb->insert('$F_T',array('follower_id'=>$STAR,'followed_id'=>$FAN,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
MN=$(php -r 'preg_match("~name=\"action\" value=\"cmp_msg\".*?name=\"_cmp_nonce\" value=\"([^\"]+)\"~s",file_get_contents($argv[1]),$m); echo $m[1]??"";' $T/o0.html)
curl -s -b $T/other.jar -o /dev/null "$H/wp-admin/admin-post.php" --data-urlencode "action=cmp_msg" --data-urlencode "_cmp_nonce=$MN" --data-urlencode "do=block" --data-urlencode "member=$STAR"
ev "global \$wpdb; \$wpdb->insert('$F_T',array('follower_id'=>$OTHER,'followed_id'=>$STAR,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
SMN=$(php -r 'preg_match("~name=\"action\" value=\"cmp_msg\".*?name=\"_cmp_nonce\" value=\"([^\"]+)\"~s",file_get_contents($argv[1]),$m); echo $m[1]??"";' <(curl -s -b $T/star.jar "$PAGE&cmp_member=$FAN"))
curl -s -b $T/star.jar -o /dev/null "$H/wp-admin/admin-post.php" --data-urlencode "action=cmp_msg" --data-urlencode "_cmp_nonce=$SMN" --data-urlencode "do=block" --data-urlencode "member=$FAN"
ok "blocking removes follows both ways" $([ "$(q "SELECT COUNT(*) FROM $F_T WHERE (follower_id=$FAN AND followed_id=$STAR) OR (follower_id=$STAR AND followed_id=$FAN)")" = 0 ] && echo 1 || echo 0)
r=$(act fan $FN follow --data-urlencode "member=$STAR")
ok "...and stops following again" $(echo "$r" | grep -q 'fl_gone' && echo 1 || echo 0)
ok "no calendar label across a block" $([ -z "$(ev "wp_set_current_user($OTHER); echo apply_filters('cec_event_social_label','',$EV1);")" ] && echo 1 || echo 0)

echo "== Privacy"
ok "export lists who you follow and the calendar default" $(ev "global \$wpdb; \$wpdb->query(\"DELETE FROM $B_T\"); \$wpdb->insert('$F_T',array('follower_id'=>$FAN,'followed_id'=>$OTHER,'created_at'=>gmdate('Y-m-d H:i:s'))); \$j=wp_json_encode(CMP_Account::export('fan@example.com'),JSON_UNESCAPED_SLASHES); echo false!==strpos(\$j,'Other Member') && false!==strpos(\$j,'Who sees new calendar events by default')?1:0;")
ok "erase removes follows both ways and the setting" $(ev "CMP_Account::erase('star@example.com'); global \$wpdb; echo 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $F_T WHERE follower_id=$STAR OR followed_id=$STAR\") && ''===get_user_meta($STAR,'cmp_show_going',true)?1:0;")

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev "global \$wpdb; \$wpdb->query(\"DELETE FROM \".CMP_Install::table('calendar')); foreach(array('$F_T','$P_T','$B_T','$R_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
$W post delete $EV1 $EV2 $EV3 --force >/dev/null 2>&1
for u in fan star other; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|cec\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
