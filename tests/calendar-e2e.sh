#!/usr/bin/env bash
# End-to-end test of My calendar (Community Member Planning 0.17.0 +
# Community Events Calendar 1.30.0): "Add to my calendar" on event pages,
# Going = RSVP (capacity respected), Interested / Remove, who sees what
# (all members / dynamic partners / only me, blocks), the profile's
# Calendar tab, the My calendar tab, followers' notifications and the
# calendar marker, an RSVP made with the event's form, the one-time
# upgrade, retention, export and erase.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/calendar-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/cal; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -L -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
cnonce(){ php -r 'preg_match("~name=\"action\" value=\"cmp_cal\".*?name=\"_cmp_nonce\" value=\"([^\"]+)\"~s",file_get_contents($argv[1]),$m); echo $m[1]??"";' "$1"; }
post() { curl -s -b $T/$1.jar -c $T/$1.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" "${@:2}"; }
uid()  { $W user get $1 --field=ID 2>/dev/null; }
ev()   { $W eval "$1" 2>&1; }
member(){ ev "\$i=get_user_by('login','$1')->ID; update_user_meta(\$i,'cmp_verified_email','$1@example.com'); update_user_meta(\$i,'cmp_email_verified_at',gmdate('Y-m-d H:i:s')); update_user_meta(\$i,'cmp_birth_date','1980-01-01'); CMP_Access::record_attestation(\$i); update_user_meta(\$i,'cmp_setup_done','later');" >/dev/null; }
q()    { ev "global \$wpdb; echo \$wpdb->get_var(\"$1\");"; }
event(){ # event <title> <days from now> [rsvp mode] [capacity]
	local id; id=$($W post create --post_type=cec_event --post_status=publish --post_title="$1" --porcelain 2>/dev/null)
	ev "update_post_meta($id,'_cec_start',wp_date('Y-m-d\\T19:00',time()+($2)*DAY_IN_SECONDS)); update_post_meta($id,'_cec_end',wp_date('Y-m-d\\T21:00',time()+($2)*DAY_IN_SECONDS)); update_post_meta($id,'_cec_rsvp_mode','${3:-none}'); update_post_meta($id,'_cec_rsvp_capacity',${4:-0});" >/dev/null
	echo $id
}
save(){ # save <user> <nonce> <event> <response> <audience> [back]
	post $1 --data-urlencode "action=cmp_cal" --data-urlencode "_cmp_nonce=$2" --data-urlencode "do=save" --data-urlencode "event=$3" --data-urlencode "response=$4" --data-urlencode "audience=$5" --data-urlencode "back=${6:-event}"
}

for p in community-events-calendar community-member-planning; do rm -rf wp-content/plugins/$p && cp -r $REPO/$p wp-content/plugins/; $W plugin activate $p >/dev/null 2>&1; done
ev 'CMP_Install::maybe_upgrade();' >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
PAGE_ID=$(ev 'echo (int) CMP_Settings::get("member_page_id");')
PAGE="$H/?page_id=$PAGE_ID"
CAL=$(ev 'echo CMP_Install::table("calendar");'); RSVP=$(ev 'global $wpdb; echo $wpdb->prefix.CEC_TABLE_RSVP;'); N_T=$(ev 'echo CMP_Install::table("notifications");')
for u in calann calbo calcy caldee; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create calann calann@example.com --role=subscriber --user_pass=calannpass123 --display_name="Cal Ann" >/dev/null
$W user create calbo calbo@example.com --role=subscriber --user_pass=calbopass1234 --display_name="Cal Bo" >/dev/null
$W user create calcy calcy@example.com --role=subscriber --user_pass=calcypass1234 --display_name="Cal Cy" >/dev/null
$W user create caldee caldee@example.com --role=subscriber --user_pass=caldeepass123 --display_name="Cal Dee" >/dev/null
member calann; member calbo; member calcy
ANN=$(uid calann); BO=$(uid calbo); CY=$(uid calcy); DEE=$(uid caldee)
E1=$(event "ZZ Kink 101" 4 internal 0)
E2=$(event "ZZ Leather Night" 12)
E3=$(event "ZZ Rope Jam" 20)
E4=$(event "ZZ Tiny Workshop" 6 internal 1)
# Bo is Ann's dynamic partner; Cy follows Ann.
ev "global \$wpdb; \$wpdb->insert(CMP_Install::table('dynamics'),array('type'=>'partners','proposer_id'=>$ANN,'partner_id'=>$BO,'proposer_side'=>'','status'=>'active','proposer_show'=>1,'partner_show'=>1,'created_at'=>gmdate('Y-m-d H:i:s'))); \$wpdb->insert(CMP_Install::table('follows'),array('follower_id'=>$CY,'followed_id'=>$ANN,'created_at'=>gmdate('Y-m-d H:i:s'))); \$wpdb->insert(CMP_Install::table('follows'),array('follower_id'=>$BO,'followed_id'=>$ANN,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null

echo "== Event page"
get anon a1 "$H/?p=$E1"
ok "signed out: a sign-in link instead of the form" $([ "$(has $T/a1.html 'Sign in to add to my calendar')$(hasnt $T/a1.html 'name="action" value="cmp_cal"')" = 11 ] && echo 1 || echo 0)
login caldee caldee caldeepass123
get caldee d1 "$H/?p=$E1"
ok "not yet a member: told to finish setting up, no form" $([ "$(has $T/d1.html 'Finish setting up your member account')$(hasnt $T/d1.html 'name="action" value="cmp_cal"')" = 11 ] && echo 1 || echo 0)
login calann calann calannpass123
get calann e1 "$H/?p=$E1"
AN=$(cnonce $T/e1.html)
ok "member: Add to my calendar with Going / Interested and who can see it (default: dynamic partners)" $([ "$(has $T/e1.html 'Add to my calendar')$(has $T/e1.html 'value="interested"')$(grep -q 'value="partners" checked' $T/e1.html && echo 1 || echo 0)$(has $T/e1.html 'Going also counts as your RSVP')" = 1111 ] && echo 1 || echo 0)
get calann e2 "$H/?p=$E2"
ok "no RSVP note on an event that doesn't take RSVPs here" $(hasnt $T/e2.html 'Going also counts as your RSVP')
r=$(save calann bad $E1 going members)
ok "bad nonce refused" $([ "$(q "SELECT COUNT(*) FROM $CAL WHERE user_id=$ANN")" = 0 ] && echo 1 || echo 0)
r=$(save calann $AN $E1 going members)
ok "Going: on the calendar, back on the event with a confirmation" $(echo "$r" | grep -q "cmp_cal=cal_saved" && [ "$(q "SELECT CONCAT(response,'/',audience) FROM $CAL WHERE user_id=$ANN AND event_id=$E1")" = going/members ] && echo 1 || echo 0)
ok "...and counts as the event's RSVP" $([ "$(q "SELECT COUNT(*) FROM $RSVP WHERE event_id=$E1 AND user_id=$ANN")" = 1 ] && echo 1 || echo 0)
get calann e1b "$H/?p=$E1&cmp_cal=cal_saved"
ok "event page then shows On your calendar, Change and Remove" $([ "$(has $T/e1b.html 'On your calendar')$(has $T/e1b.html 'visible to all members')$(has $T/e1b.html '>Change<')$(has $T/e1b.html '>Remove<')$(has $T/e1b.html 'Saved to your calendar')" = 11111 ] && echo 1 || echo 0)
save calann $AN $E1 going members >/dev/null
ok "saving again doesn't RSVP twice" $([ "$(q "SELECT COUNT(*) FROM $RSVP WHERE event_id=$E1 AND user_id=$ANN")" = 1 ] && echo 1 || echo 0)
r=$(save calann $AN $E1 interested members)
ok "Interested takes the RSVP away, keeps it on the calendar" $([ "$(q "SELECT COUNT(*) FROM $RSVP WHERE event_id=$E1 AND user_id=$ANN")" = 0 ] && [ "$(q "SELECT response FROM $CAL WHERE user_id=$ANN AND event_id=$E1")" = interested ] && echo 1 || echo 0)
save calann $AN $E1 going members >/dev/null
r=$(save calann $AN $E2 going partners)
ok "an event without RSVPs here just goes on the calendar" $([ "$(q "SELECT audience FROM $CAL WHERE user_id=$ANN AND event_id=$E2")" = partners ] && [ "$(q "SELECT COUNT(*) FROM $RSVP WHERE event_id=$E2")" = 0 ] && echo 1 || echo 0)
save calann $AN $E3 going private >/dev/null
ev "global \$wpdb; \$wpdb->insert('$RSVP',array('event_id'=>$E4,'user_id'=>0,'name'=>'Guest','email'=>'g@example.com','guests'=>0,'created_at'=>current_time('mysql')));" >/dev/null
r=$(save calann $AN $E4 going members)
ok "a full event refuses Going (nothing saved)" $(echo "$r" | grep -q 'cal_full' && [ "$(q "SELECT COUNT(*) FROM $CAL WHERE user_id=$ANN AND event_id=$E4")" = 0 ] && echo 1 || echo 0)
r=$(save calann $AN $E4 interested members)
ok "...but Interested is fine" $(echo "$r" | grep -q 'cal_saved' && echo 1 || echo 0)
r=$(save calann $AN $E1 going everyone)
ok "unknown audience refused" $(echo "$r" | grep -q 'cal_gone' && echo 1 || echo 0)
r=$(save calann $AN 999999 going members)
ok "unknown event refused" $(echo "$r" | grep -q 'cal_gone' && echo 1 || echo 0)

echo "== Public .ics outside the host's CDN cache; whole day box clickable (Events Calendar 1.32.1)"
code(){ curl -s -o $T/ics.out -D $T/ics.h -w '%{http_code}' "$1"; }
ok "site feed at admin-post.php: a calendar with upcoming events" $([ "$(code "$H/wp-admin/admin-post.php?action=cec_ical&feed=all")" = 200 ] && grep -q 'BEGIN:VCALENDAR' $T/ics.out && grep -q 'SUMMARY:ZZ Rope Jam' $T/ics.out && echo 1 || echo 0)
ok "old ?cec_ical=all redirects there (301), so subscriptions keep working" $([ "$(code "$H/?cec_ical=all")" = 301 ] && grep -qi 'location:.*admin-post.php?action=cec_ical&feed=all' $T/ics.h && echo 1 || echo 0)
ok "one event's .ics at admin-post.php, and its old link redirects there" $([ "$(code "$H/wp-admin/admin-post.php?action=cec_ical&event=$E3")" = 200 ] && grep -q 'SUMMARY:ZZ Rope Jam' $T/ics.out && [ "$(curl -s -L -o /dev/null -w '%{http_code} %{url_effective}' "$H/?p=$E3&cec_ical=1" | grep -c "200 .*admin-post.php?action=cec_ical&event=$E3")" = 1 ] && echo 1 || echo 0)
ok "unknown or unpublished events: not found" $([ "$(code "$H/wp-admin/admin-post.php?action=cec_ical&event=999999")$(code "$H/wp-admin/admin-post.php?action=cec_ical")" = 404404 ] && echo 1 || echo 0)
ok "event page links use the new address" $(has $T/e2.html "admin-post.php?action=cec_ical&#038;event=$E2")
ok "calendar day boxes carry their date (whole box opens the day)" $(ev "echo preg_match('~class=\"cec-cal-cell[^\"]*\" data-date=\"\d{4}-\d{2}-\d{2}\"~', do_shortcode('[cec_calendar]')) && preg_match('~class=\"cec-cal-daycontent\" data-date=\"\d{4}-~', do_shortcode('[cec_calendar]')) ?1:0;")

echo "== Who sees what"
login calbo calbo calbopass1234; login calcy calcy calcypass1234
get calbo pb "$PAGE&cmp_member=$ANN"
get calcy pc "$PAGE&cmp_member=$ANN"
get calann pa "$PAGE&cmp_member=$ANN"
ok "a dynamic partner sees all-members and partners events, not private" $([ "$(has $T/pb.html 'ZZ Kink 101')$(has $T/pb.html 'ZZ Leather Night')$(hasnt $T/pb.html 'ZZ Rope Jam')$(has $T/pb.html '>Calendar<')" = 1111 ] && echo 1 || echo 0)
ok "another member sees only all-members events" $([ "$(has $T/pc.html 'ZZ Kink 101')$(hasnt $T/pc.html 'ZZ Leather Night')$(hasnt $T/pc.html 'ZZ Rope Jam')" = 111 ] && echo 1 || echo 0)
ok "...and nothing tells them hidden events exist" $(hasnt $T/pc.html 'not shared')
ok "the member's own 'as members see it' view shows only all-members events" $([ "$(has $T/pa.html 'ZZ Kink 101')$(hasnt $T/pa.html 'ZZ Rope Jam')" = 11 ] && echo 1 || echo 0)
ok "signed out / non-members can't see a calendar" $(ev "echo CMP_Calendar::can_see('members',$ANN,0)||CMP_Calendar::can_see('members',$ANN,$DEE)?0:1;")
ev "global \$wpdb; \$wpdb->insert(CMP_Install::table('blocks'),array('blocker_id'=>$ANN,'blocked_id'=>$CY,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
ok "a block hides everything both ways" $(ev "echo CMP_Calendar::can_see('members',$ANN,$CY)||CMP_Calendar::can_see('members',$CY,$ANN)?0:1;")
ev "global \$wpdb; \$wpdb->delete(CMP_Install::table('blocks'),array('blocker_id'=>$ANN,'blocked_id'=>$CY));" >/dev/null
ok "ending the dynamic stops partners-only sharing" $(ev "global \$wpdb; \$wpdb->update(CMP_Install::table('dynamics'),array('status'=>'ended'),array('proposer_id'=>$ANN,'partner_id'=>$BO)); \$r=CMP_Calendar::can_see('partners',$ANN,$BO); \$wpdb->update(CMP_Install::table('dynamics'),array('status'=>'active'),array('proposer_id'=>$ANN,'partner_id'=>$BO)); echo \$r?0:1;")

echo "== Followers and the calendar marker"
ok "Going told followers allowed to see it (Cy and Bo for all-members Kink 101)" $([ "$(q "SELECT COUNT(DISTINCT user_id) FROM $N_T WHERE user_id IN ($CY,$BO) AND message LIKE 'Cal Ann is going to ZZ Kink 101%'")" = 2 ] && echo 1 || echo 0)
ok "partners-only Leather Night told Bo, not Cy" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$BO AND message LIKE '%ZZ Leather Night%'")$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$CY AND message LIKE '%ZZ Leather Night%'")" = 10 ] && echo 1 || echo 0)
ok "private Rope Jam told nobody" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id IN ($BO,$CY,$DEE) AND message LIKE '%ZZ Rope Jam%'")" = 0 ] && echo 1 || echo 0)
save calann $AN $E2 going members >/dev/null
ok "widening Leather Night to all members tells Cy now (and doesn't repeat for Bo)" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$CY AND message LIKE '%ZZ Leather Night%'")$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$BO AND message LIKE '%ZZ Leather Night%'")" = 11 ] && echo 1 || echo 0)
save calann $AN $E2 going partners >/dev/null
ok "calendar marker counts only what the viewer may see" $(ev "wp_set_current_user($CY); \$a=CMP_Calendar::calendar_label('',$E1); \$b=CMP_Calendar::calendar_label('',$E3); wp_set_current_user($BO); \$c=CMP_Calendar::calendar_label('',$E2); echo (false!==strpos(\$a,'1 person you follow'))&&''===\$b&&(false!==strpos(\$c,'1 person'))?1:0;")

echo "== My calendar tab"
get calann m1 "$PAGE&cmp_tab=calendar"
ok "tab lists upcoming events by month with who can see each, and the default setting" $([ "$(has $T/m1.html 'My calendar')$(has $T/m1.html 'ZZ Rope Jam')$(has $T/m1.html 'Who sees new events by default')$(has $T/m1.html 'class="cmp-cal-month"')$(has $T/m1.html 'data-cmp-autosave')" = 11111 ] && echo 1 || echo 0)
ok "the member area has a Calendar tab" $(has $T/m1.html '>Calendar</a>')
MN=$(cnonce $T/m1.html)
r=$(post calann --data-urlencode "action=cmp_cal" --data-urlencode "_cmp_nonce=$MN" --data-urlencode "do=default" --data-urlencode "audience=members")
ok "default saved" $(echo "$r" | grep -q 'cal_default' && [ "$(ev "echo CMP_Calendar::default_audience($ANN);")" = members ] && echo 1 || echo 0)
r=$(save calann $MN $E3 going partners tab)
ok "change who sees one event from the tab (back to the tab)" $(echo "$r" | grep -q 'cmp_tab=calendar' && [ "$(q "SELECT audience FROM $CAL WHERE user_id=$ANN AND event_id=$E3")" = partners ] && echo 1 || echo 0)
r=$(post calann --data-urlencode "action=cmp_cal" --data-urlencode "_cmp_nonce=$MN" --data-urlencode "do=remove" --data-urlencode "event=$E1" --data-urlencode "back=tab")
ok "Remove takes it off the calendar and cancels the RSVP" $(echo "$r" | grep -q 'cal_removed' && [ "$(q "SELECT COUNT(*) FROM $CAL WHERE user_id=$ANN AND event_id=$E1")$(q "SELECT COUNT(*) FROM $RSVP WHERE event_id=$E1 AND user_id=$ANN")" = 00 ] && echo 1 || echo 0)
ok "Profile tab points to My calendar instead of the old followers switch" $(get calann pr "$PAGE&cmp_tab=profile"; [ "$(has $T/pr.html 'Followers and your events')$(hasnt $T/pr.html 'Show events I&#039;m going to')" = 11 ] && echo 1 || echo 0)

HOME_ID=$($W post create --post_type=page --post_status=publish --post_title="ZZ Home" --post_content="[cec_upcoming]" --porcelain 2>/dev/null)
CALP=$($W post create --post_type=page --post_status=publish --post_title="ZZ Events Page" --post_content="[cec_login] [cec_calendar] [cec_events]" --menu_order=-1 --porcelain 2>/dev/null)
OLDFRONT=$(ev "echo (int) get_option('page_on_front');"); ev "update_option('page_on_front',$HOME_ID);" >/dev/null
ok "Browse events goes to the calendar page, not the home page with upcoming events (0.17.1)" $([ "$(ev "echo CMP_Calendar::events_url();")" = "$(ev "echo get_permalink($CALP);")" ] && echo 1 || echo 0)
ev "update_option('page_on_front',$OLDFRONT);" >/dev/null; $W post delete $HOME_ID $CALP --force >/dev/null 2>&1

echo "== Private calendar feed (Member Planning 0.18.0, Events Calendar 1.32.0)"
feed(){ curl -s -D $T/feed.h -o $T/feed.ics -w '%{http_code}' "$H/wp-admin/admin-post.php?action=cmp_cal_feed&key=$1"; }
ok "off until turned on: the tab offers it, a made-up link is not found" $([ "$(has $T/m1.html 'Get my calendar link')$(feed AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA)" = 1404 ] && echo 1 || echo 0)
r=$(post calann --data-urlencode "action=cmp_cal" --data-urlencode "_cmp_nonce=$MN" --data-urlencode "do=feed_on")
TOK=$(ev "echo CMP_Calendar::feed_token($ANN);")
get calann m2 "$PAGE&cmp_tab=calendar"
ok "turned on: a 40-character private link, Subscribe (webcal) and Copy link on the tab" $(echo "$r" | grep -q 'cal_feed_on' && [ ${#TOK} = 40 ] && [ "$(has $T/m2.html "webcal://")$(has $T/m2.html "admin-post.php?action=cmp_cal_feed&amp;key=$TOK")$(has $T/m2.html 'Copy link')$(has $T/m2.html 'Get a new link')" = 1111 ] && echo 1 || echo 0)
ok "the feed (under wp-admin, which the host's CDN never caches) is a calendar file, private and never cached or indexed" $([ "$(feed $TOK)" = 200 ] && grep -qi 'content-type: text/calendar' $T/feed.h && grep -qi 'cache-control:.*no-store' $T/feed.h && grep -qi 'x-robots-tag: noindex' $T/feed.h && grep -q 'BEGIN:VCALENDAR' $T/feed.ics && grep -q 'REFRESH-INTERVAL' $T/feed.ics && echo 1 || echo 0)
ok "the old front-end address (cacheable by the host's CDN) no longer serves a calendar" $(curl -s "$H/?cmp_cal_feed=$TOK" | grep -q 'BEGIN:VCALENDAR' && echo 0 || echo 1)
ok "it holds everything on Ann's calendar, whoever it's shown to (partners-only Rope Jam too)" $([ "$(grep -c 'SUMMARY:ZZ Rope Jam' $T/feed.ics)$(grep -c 'SUMMARY:ZZ Leather Night' $T/feed.ics)" = 11 ] && echo 1 || echo 0)
ok "...and nothing else (Kink 101 was removed)" $(grep -q 'SUMMARY:ZZ Kink 101' $T/feed.ics && echo 0 || echo 1)
ok "Interested is marked tentative, Going confirmed" $(php -r '$t=file_get_contents($argv[1]); preg_match_all("~BEGIN:VEVENT.*?END:VEVENT~s",$t,$m); $ok=0; foreach($m[0] as $e){ if(false!==strpos($e,"ZZ Tiny Workshop")&&false!==strpos($e,"STATUS:TENTATIVE")) $ok++; if(false!==strpos($e,"ZZ Rope Jam")&&false!==strpos($e,"STATUS:CONFIRMED")) $ok++; } echo 2===$ok?1:0;' $T/feed.ics)
post calann --data-urlencode "action=cmp_cal" --data-urlencode "_cmp_nonce=$MN" --data-urlencode "do=feed_new" >/dev/null
TOK2=$(ev "echo CMP_Calendar::feed_token($ANN);")
ok "Get a new link: the old one stops working, the new one works" $([ "$TOK2" != "$TOK" ] && [ "$(feed $TOK)$(feed $TOK2)" = 404200 ] && echo 1 || echo 0)
ev "update_user_meta($DEE,'cmp_cal_feed_token','DEEdeeDEEdeeDEEdeeDEEdeeDEEdeeDEEdee1234');" >/dev/null
ok "an account that isn't a member gets nothing" $([ "$(feed DEEdeeDEEdeeDEEdeeDEEdeeDEEdeeDEEdee1234)" = 404 ] && echo 1 || echo 0)
ok "export says whether the link is on (never the link itself)" $(ev "\$j=wp_json_encode(CMP_Account::export('calann@example.com'),JSON_UNESCAPED_SLASHES); echo false!==strpos(\$j,'Private calendar link')&&false===strpos(\$j,'$TOK2')?1:0;")
post calann --data-urlencode "action=cmp_cal" --data-urlencode "_cmp_nonce=$MN" --data-urlencode "do=feed_off" >/dev/null
ok "Turn off: the link is gone" $([ "$(feed $TOK2)" = 404 ] && [ -z "$(ev "echo CMP_Calendar::feed_token($ANN);")" ] && echo 1 || echo 0)
ok "turning it on, off and resetting is audit-logged" $([ "$(q "SELECT COUNT(*) FROM $(ev 'echo CMP_Install::table("audit_log");') WHERE object_id=$ANN AND action LIKE 'calendar_feed_%'")" = 3 ] && echo 1 || echo 0)
post calann --data-urlencode "action=cmp_cal" --data-urlencode "_cmp_nonce=$MN" --data-urlencode "do=feed_on" >/dev/null

echo "== Signed-in quick link (Events Calendar 1.31.1, Member Planning 0.17.3)"
LP=$($W post create --post_type=page --post_status=publish --post_title="ZZ Log In" --post_content='[cec_login note="Log in or register to keep track of your events and RSVPs."]' --porcelain 2>/dev/null)
RP=$($W post create --post_type=page --post_status=publish --post_title="ZZ Register" --post_content='[cec_register]' --porcelain 2>/dev/null)
get anon q0 "$H/?page_id=$LP"
ok "signed out: the note and the login form" $([ "$(has $T/q0.html 'Log in or register to keep track of your events and RSVPs.')$(has $T/q0.html 'name="cec_identifier"')$(hasnt $T/q0.html 'Signed in as')" = 111 ] && echo 1 || echo 0)
get calann q1 "$H/?page_id=$LP"
ok "member signed in: just a My calendar button (nothing the menu or page already has)" $([ "$(hasnt $T/q1.html 'already logged in')$(grep -q 'class="cec-btn cec-btn-small" href="[^"]*cmp_tab=calendar[^"]*">My calendar</a>' $T/q1.html && echo 1 || echo 0)$(grep -o '<nav class="cec-auth-form cec-account-links".*</nav>' $T/q1.html | grep -c '<a ')$(hasnt $T/q1.html 'Signed in as')$(hasnt $T/q1.html 'Log in or register to keep track')" = 11111 ] && echo 1 || echo 0)
get calann q2 "$H/?page_id=$RP"
ok "the register box shows the same when signed in" $([ "$(hasnt $T/q2.html 'already logged in')$(has $T/q2.html '>My calendar</a>')" = 11 ] && echo 1 || echo 0)
get caldee q3 "$H/?page_id=$LP"
ok "not yet a member: nothing shown (the account menu already says finish joining)" $([ "$(hasnt $T/q3.html 'cec-account-links')$(hasnt $T/q3.html 'already logged in')$(hasnt $T/q3.html 'name="cec_identifier"')" = 111 ] && echo 1 || echo 0)
ok "the account menu has My Calendar for members" $(ev "\$m=CMP_Account_Bar::items(get_userdata($ANN)); echo in_array('My Calendar',wp_list_pluck(\$m,0),true)?1:0;")
$W post delete $LP $RP --force >/dev/null 2>&1

echo "== RSVP with the event's form, upgrade, retention, privacy"
ev "do_action('cec_rsvp_created',$E1,$BO);" >/dev/null
ok "an RSVP made with the event form while signed in lands on the calendar as Going (their default)" $([ "$(q "SELECT CONCAT(response,'/',audience) FROM $CAL WHERE user_id=$BO AND event_id=$E1")" = going/partners ] && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->query(\"DELETE FROM $CAL WHERE event_id IN ($E1,$E2)\"); \$wpdb->insert('$RSVP',array('event_id'=>$E2,'user_id'=>$CY,'name'=>'Cy','email'=>'c@example.com','guests'=>0,'created_at'=>current_time('mysql'))); \$wpdb->insert('$RSVP',array('event_id'=>$E2,'user_id'=>$BO,'name'=>'Bo','email'=>'b@example.com','guests'=>0,'created_at'=>current_time('mysql'))); update_user_meta($CY,'cmp_show_going',1); delete_user_meta($CY,'cmp_cal_default'); delete_option(CMP_Calendar::MIGRATED); CMP_Calendar::migrate();" >/dev/null
ok "upgrade: signed-in RSVPs become Going; shared ones all members, others only me" $([ "$(q "SELECT audience FROM $CAL WHERE user_id=$CY AND event_id=$E2")/$(q "SELECT audience FROM $CAL WHERE user_id=$BO AND event_id=$E2")" = members/private ] && echo 1 || echo 0)
ok "...and members who shared keep all members as their default" $([ "$(ev "echo CMP_Calendar::default_audience($CY);")" = members ] && echo 1 || echo 0)
OLD=$(event "ZZ Long Ago" -400)
ev "global \$wpdb; \$wpdb->insert('$CAL',array('user_id'=>$ANN,'event_id'=>$OLD,'response'=>'going','audience'=>'members','created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'))); CMP_Retention::run();" >/dev/null
ok "retention: entries for events over 12 months ago removed, upcoming kept" $([ "$(q "SELECT COUNT(*) FROM $CAL WHERE event_id=$OLD")" = 0 ] && [ "$(q "SELECT COUNT(*) FROM $CAL WHERE user_id=$ANN AND event_id=$E3")" = 1 ] && echo 1 || echo 0)
ok "export lists calendar entries and the default" $(ev "\$j=wp_json_encode(CMP_Account::export('calann@example.com'),JSON_UNESCAPED_SLASHES); echo false!==strpos(\$j,'ZZ Rope Jam')&&false!==strpos(\$j,'Who sees new calendar events by default')?1:0;")
ok "erase removes them and the private link" $(ev "CMP_Account::erase('calann@example.com'); global \$wpdb; echo 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $CAL WHERE user_id=$ANN\") && ''===CMP_Calendar::feed_token($ANN)?1:0;")

echo "== Next-event link for a Partner Organization or Titleholder (Events Calendar 1.33.0)"
ORG=$(ev '$t=term_exists("ZZ Next Org","cec_partner_org"); if(!$t){$t=wp_insert_term("ZZ Next Org","cec_partner_org",array("slug"=>"zz-next-org"));} echo (int)$t["term_id"];')
EMPTY=$(ev '$t=term_exists("ZZ Empty Org","cec_partner_org"); if(!$t){$t=wp_insert_term("ZZ Empty Org","cec_partner_org",array("slug"=>"zz-empty-org"));} echo (int)$t["term_id"];')
NPAST=$(event "ZZ Org Past" -10); NCANC=$(event "ZZ Org Cancelled" 5); NNEXT=$(event "ZZ Org Next" 8); NLATER=$(event "ZZ Org Later" 20)
for e in $NPAST $NCANC $NNEXT $NLATER; do ev "wp_set_object_terms($e,array($ORG),'cec_partner_org');" >/dev/null; done
ev "update_post_meta($NCANC,'_cec_event_status','cancelled');" >/dev/null
NX="$H/wp-admin/admin-post.php?action=cec_next_event&org="
link(){ curl -s -o /dev/null -D $T/nx.h -w '%{http_code} %{redirect_url}' "$NX$1"; }
plink(){ ev "echo get_permalink($1);"; }
ok "helper builds the admin-post link" $([ "$(ev 'echo cec_next_event_url("ZZ Next Org");')" = "$H/wp-admin/admin-post.php?action=cec_next_event&org=zz-next-org" ] && echo 1 || echo 0)
r=$(link zz-next-org)
ok "goes to the next event, skipping a cancelled one" $([ "$r" = "302 $(plink $NNEXT)" ] && echo 1 || echo 0)
ok "redirect is never cached (no-store) and not indexed" $(grep -qi '^cache-control:.*no-store' $T/nx.h && grep -qi '^x-robots-tag: noindex' $T/nx.h && echo 1 || echo 0)
r=$(curl -s -o /dev/null -b $T/ann.jar -w '%{http_code} %{redirect_url}' "${NX}zz-next-org")
ok "same for a signed-in visitor" $([ "$r" = "302 $(plink $NNEXT)" ] && echo 1 || echo 0)
NNOW=$($W post create --post_type=cec_event --post_status=publish --post_title="ZZ Org In Progress" --porcelain 2>/dev/null)
ev "update_post_meta($NNOW,'_cec_start',wp_date('Y-m-d\\TH:i',time()-HOUR_IN_SECONDS)); update_post_meta($NNOW,'_cec_end',wp_date('Y-m-d\\TH:i',time()+HOUR_IN_SECONDS)); wp_set_object_terms($NNOW,array($ORG),'cec_partner_org');" >/dev/null
ok "an event in progress counts as next" $([ "$(link zz-next-org)" = "302 $(plink $NNOW)" ] && echo 1 || echo 0)
ev "update_post_meta($NNOW,'_cec_end',wp_date('Y-m-d\\TH:i',time()-30*MINUTE_IN_SECONDS));" >/dev/null
ok "...but not once it has ended" $([ "$(link zz-next-org)" = "302 $(plink $NNEXT)" ] && echo 1 || echo 0)
$W post update $NNEXT --post_status=draft >/dev/null 2>&1
ok "unpublished events are skipped" $([ "$(link zz-next-org)" = "302 $(plink $NLATER)" ] && echo 1 || echo 0)
$W post delete $NLATER $NCANC --force >/dev/null 2>&1
ok "nothing scheduled: the most recent event" $([ "$(link zz-next-org)" = "302 $(plink $NNOW)" ] && echo 1 || echo 0)
ok "no events at all: the organization's calendar page" $([ "$(link zz-empty-org)" = "302 $(ev "echo get_term_link($EMPTY,'cec_partner_org');")" ] && echo 1 || echo 0)
ok "unknown organization: the home page" $([ "$(link no-such-org)" = "302 $H/" ] && [ "$(link '')" = "302 $H/" ] && echo 1 || echo 0)
$W post delete $NPAST $NNEXT $NNOW --force >/dev/null 2>&1
ev "wp_delete_term($ORG,'cec_partner_org'); wp_delete_term($EMPTY,'cec_partner_org');" >/dev/null

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev "global \$wpdb; \$wpdb->query(\"DELETE FROM $CAL\"); \$wpdb->query(\"DELETE FROM $RSVP WHERE event_id IN ($E1,$E2,$E3,$E4)\"); \$wpdb->query(\"DELETE FROM \".CMP_Install::table('dynamics').\" WHERE proposer_id=$ANN\"); \$wpdb->query(\"DELETE FROM \".CMP_Install::table('follows').\" WHERE followed_id=$ANN\");" >/dev/null
$W post delete $E1 $E2 $E3 $E4 $OLD --force >/dev/null 2>&1
for u in calann calbo calcy caldee; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|cec\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
