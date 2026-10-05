#!/usr/bin/env bash
# End-to-end test of the installable app and event reminders (Community
# Member Planning 0.19.0): manifest, service worker and offline page (served
# outside the host's CDN cache), page tags, the install help in the member
# area and Account, reminder settings, and the reminder run (evening before,
# 2 hours before, Interested, cancelled, all-day, moved events, email, once
# only), privacy.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/app-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/app; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -L -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
rnonce(){ php -r 'preg_match("~name=\"action\" value=\"cmp_reminders\".*?name=\"_cmp_nonce\" value=\"([^\"]+)\"~s",file_get_contents($argv[1]),$m); echo $m[1]??"";' "$1"; }
uid()  { $W user get $1 --field=ID 2>/dev/null; }
ev()   { $W eval "$1" 2>&1; }
q()    { ev "global \$wpdb; echo \$wpdb->get_var(\"$1\");"; }
member(){ ev "\$i=get_user_by('login','$1')->ID; update_user_meta(\$i,'cmp_verified_email','$1@example.com'); update_user_meta(\$i,'cmp_email_verified_at',gmdate('Y-m-d H:i:s')); update_user_meta(\$i,'cmp_birth_date','1980-01-01'); CMP_Access::record_attestation(\$i); update_user_meta(\$i,'cmp_setup_done','later');" >/dev/null; }

for p in community-events-calendar community-member-planning; do rm -rf wp-content/plugins/$p && cp -r $REPO/$p wp-content/plugins/; $W plugin activate $p >/dev/null 2>&1; done
ev 'CMP_Install::maybe_upgrade();' >/dev/null
OLDTZ=$(ev "echo get_option('timezone_string');"); OLDTF=$(ev "echo get_option('time_format');")
ev "update_option('timezone_string','America/New_York'); update_option('time_format','g:i a');" >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
PAGE_ID=$(ev 'echo (int) CMP_Settings::get("member_page_id");')
PAGE="$H/?page_id=$PAGE_ID"
CAL=$(ev 'echo CMP_Install::table("calendar");'); N_T=$(ev 'echo CMP_Install::table("notifications");')
for u in appann appdee; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create appann appann@example.com --role=subscriber --user_pass=appannpass123 --display_name="App Ann" >/dev/null
$W user create appdee appdee@example.com --role=subscriber --user_pass=appdeepass123 --display_name="App Dee" >/dev/null
member appann
ANN=$(uid appann); DEE=$(uid appdee)

echo "== App files (served outside the CDN cache)"
A="$H/wp-admin/admin-post.php"
curl -s -D $T/m.h -o $T/m.json "$A?action=cmp_app_manifest"
ok "manifest: JSON with name, short name, member-area start, standalone, whole-site scope, icons" $(php -r '$m=json_decode(file_get_contents($argv[1]),true); echo ($m && $m["name"] && $m["short_name"] && false!==strpos($m["start_url"],"source=app") && "standalone"===$m["display"] && "/"===$m["scope"] && 2===count($m["icons"]) && "512x512"===$m["icons"][1]["sizes"])?1:0;' $T/m.json)
ok "...sent as a web app manifest, not cached" $(grep -qi 'content-type: application/manifest+json' $T/m.h && grep -qi 'cache-control:.*no-store' $T/m.h && echo 1 || echo 0)
ICON=$(php -r '$m=json_decode(file_get_contents($argv[1]),true); echo $m["icons"][1]["src"];' $T/m.json)
ok "the icons load (bundled when the site has no site icon)" $([ "$(curl -s -o /dev/null -w '%{http_code}' "$ICON")" = 200 ] && echo "$ICON" | grep -q 'app-icon-512.png' && echo 1 || echo 0)
ok "long site names shorten to initials ('Central Ohio Leather & Beyond' → COL&B)" $([ "$(ev "add_filter('pre_option_blogname',function(){return 'Central Ohio Leather & Beyond';}); echo CMP_App::short_name();")" = "COL&B" ] && echo 1 || echo 0)
curl -s -D $T/sw.h -o $T/sw.js "$A?action=cmp_app_sw"
ok "service worker: JavaScript, allowed the whole site, not cached" $(grep -qi 'content-type: application/javascript' $T/sw.h && grep -qi 'service-worker-allowed: /' $T/sw.h && grep -qi 'cache-control:.*no-store' $T/sw.h && echo 1 || echo 0)
ok "...it only handles page loads, keeps just the offline page, and leaves wp-admin alone" $([ "$(has $T/sw.js "'navigate' !== req.mode")$(has $T/sw.js 'action=cmp_app_offline')$(has $T/sw.js 'ADMIN')$(hasnt $T/sw.js 'cache.put')$(has $T/sw.js 'var ICON')" = 11111 ] && echo 1 || echo 0)
ok "offline page" $([ "$(curl -s -o $T/off.html -w '%{http_code}' "$A?action=cmp_app_offline")" = 200 ] && grep -q "You&#039;re offline\|You're offline" $T/off.html && echo 1 || echo 0)

echo "== Pages"
get anon h0 "$H/"
ok "every page links the manifest, sets the theme colour and loads app.js with the worker's address" $([ "$(has $T/h0.html 'rel="manifest"')$(has $T/h0.html 'name="theme-color"')$(has $T/h0.html 'apple-mobile-web-app-capable')$(has $T/h0.html 'assets/js/app.js')$(has $T/h0.html 'action=cmp_app_sw')" = 11111 ] && echo 1 || echo 0)
get anon h1 "$PAGE"
ok "signed out: no install banner" $(hasnt $T/h1.html 'data-cmp-install-banner')
login appann appann appannpass123
get appann a1 "$PAGE"
ok "member area: install banner, hidden until app.js finds it applies, with Not now" $([ "$(has $T/a1.html 'data-cmp-install data-cmp-install-banner hidden')$(has $T/a1.html 'data-cmp-install-later')$(has $T/a1.html 'Add to Home Screen')" = 111 ] && echo 1 || echo 0)
get appann a2 "$PAGE&cmp_tab=account"
ok "Account: App & notifications with install help and reminder settings (evening + 2 hours on, Interested + email off)" $([ "$(has $T/a2.html 'App &amp; notifications')$(has $T/a2.html 'data-cmp-install-other')$(grep -q 'id="cmp_rem_evening" name="cmp_rem_evening" value="1" checked' $T/a2.html && echo 1 || echo 0)$(grep -q 'id="cmp_rem_2h" name="cmp_rem_2h" value="1" checked' $T/a2.html && echo 1 || echo 0)$(grep -q 'id="cmp_rem_email" name="cmp_rem_email" value="1" />' $T/a2.html && echo 1 || echo 0)$(hasnt $T/a2.html 'data-cmp-install-banner')" = 111111 ] && echo 1 || echo 0)
ok "a reminders category in notification preferences" $(has $T/a2.html 'Reminders for events on my calendar')
ok "reminders are checked every 15 minutes" $(ev "echo wp_next_scheduled('cmp_event_reminders') && 'cmp_every_15_minutes'===wp_get_schedule('cmp_event_reminders') ?1:0;")

echo "== Reminders"
mk(){ # mk <title> <local start 'Y-m-d\TH:i'> [time_mode] [status]
	local id; id=$($W post create --post_type=cec_event --post_status=publish --post_title="$1" --porcelain 2>/dev/null)
	ev "update_post_meta($id,'_cec_start','$2'); update_post_meta($id,'_cec_time_mode','${3:-exact}'); ${4:+update_post_meta($id,'_cec_event_status','$4');}" >/dev/null; echo $id; }
TODAY=$(ev "echo wp_date('Y-m-d');"); TMRW=$(ev "echo wp_date('Y-m-d', time()+DAY_IN_SECONDS);")
EA=$(mk "ZZ Tomorrow Night" "${TMRW}T19:00"); EB=$(mk "ZZ Tonight" "${TODAY}T20:00"); EC=$(mk "ZZ Maybe Tomorrow" "${TMRW}T19:00")
ED=$(mk "ZZ Cancelled Tomorrow" "${TMRW}T19:00" exact cancelled); EE=$(mk "ZZ All Day Tomorrow" "${TMRW}T00:00" all_day)
ev "foreach(array($EA,$EB,$ED,$EE) as \$e) CMP_Calendar::save($ANN,\$e,'going','private',false); CMP_Calendar::save($ANN,$EC,'interested','private',false); global \$wpdb; \$wpdb->insert('$CAL',array('user_id'=>$DEE,'event_id'=>$EA,'response'=>'going','audience'=>'private','created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
at(){ ev "\$d=new DateTimeImmutable('$TODAY $1', wp_timezone()); echo \$d->getTimestamp();"; }
run(){ ev "echo wp_json_encode(CMP_Reminders::run($1));"; }
ok "before 6 pm and over 2 hours ahead: nothing yet" $([ "$(run $(at 17:30))" = '{"evening":0,"two_hours":0,"emails":0}' ] && echo 1 || echo 0)
R=$(run $(at 18:30))
ok "at 6:30 pm: evening-before for tomorrow's Going events (timed and all-day) and 2-hours-before for tonight's" $([ "$R" = '{"evening":2,"two_hours":1,"emails":0}' ] && echo 1 || echo 0)
ok "...worded with the member's time: 'Tomorrow at 7:00 pm', 'Tomorrow' (all day), 'Today at 8:00 pm'" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$ANN AND category='event_reminder' AND message IN ('Tomorrow at 7:00 pm: ZZ Tomorrow Night','Tomorrow: ZZ All Day Tomorrow','Today at 8:00 pm: ZZ Tonight')")" = 3 ] && echo 1 || echo 0)
ok "not for Interested (off), cancelled events, or accounts that aren't members" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE category='event_reminder' AND (message LIKE '%Maybe Tomorrow%' OR message LIKE '%Cancelled Tomorrow%' OR user_id=$DEE)")" = 0 ] && echo 1 || echo 0)
ok "reminders arrive straight away (not held for quiet hours) and link to the event" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$ANN AND category='event_reminder' AND deliver_at=created_at AND url<>''")" = 3 ] && echo 1 || echo 0)
ok "each is sent once" $([ "$(run $(at 18:45))" = '{"evening":0,"two_hours":0,"emails":0}' ] && echo 1 || echo 0)
ev "update_post_meta($EA,'_cec_start','${TMRW}T20:00');" >/dev/null
ok "a moved event is reminded again" $([ "$(run $(at 19:00))" = '{"evening":1,"two_hours":0,"emails":0}' ] && echo 1 || echo 0)

echo "== Reminder settings"
RN=$(rnonce $T/a2.html)
r=$(curl -s -b $T/appann.jar -c $T/appann.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" --data-urlencode "action=cmp_reminders" --data-urlencode "_cmp_nonce=$RN" --data-urlencode "cmp_rem_evening=1" --data-urlencode "cmp_rem_interested=1" --data-urlencode "cmp_rem_email=1")
ok "saved: Interested and email on, 2 hours off" $(echo "$r" | grep -q 'rem_saved' && [ "$(ev "echo (int)CMP_Reminders::pref($ANN,'cmp_rem_interested').(int)CMP_Reminders::pref($ANN,'cmp_rem_email').(int)CMP_Reminders::pref($ANN,'cmp_rem_2h');")" = 110 ] && echo 1 || echo 0)
r=$(curl -s -b $T/appann.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" --data-urlencode "action=cmp_reminders" --data-urlencode "_cmp_nonce=bad" --data-urlencode "cmp_rem_evening=0")
ok "bad nonce refused" $(ev "echo CMP_Reminders::pref($ANN,'cmp_rem_evening')?1:0;")
M=$(ev "\$GLOBALS['zz_mail']=array(); add_filter('pre_wp_mail',function(\$r,\$a){ \$GLOBALS['zz_mail'][]=\$a; return true; },10,2); CMP_Reminders::run((new DateTimeImmutable('$TODAY 19:15', wp_timezone()))->getTimestamp()); echo wp_json_encode(array_map(function(\$m){ return array(\$m['to'],\$m['subject'],false!==strpos(\$m['message'],'ZZ Maybe Tomorrow')); }, \$GLOBALS['zz_mail']));")
ok "Interested now reminded, and emailed: subject says only 'reminder' and the time, the event is inside" $(echo "$M" | php -r '$m=json_decode(stream_get_contents(STDIN),true); echo (1===count($m) && "appann@example.com"===$m[0][0] && false!==stripos($m[0][1],"reminder: Tomorrow at 7:00 pm") && false===strpos($m[0][1],"ZZ") && true===$m[0][2])?1:0;')
ok "2 hours before off: no more of those" $(ev "\$e=$(mk "ZZ Soon" "${TODAY}T21:00"); CMP_Calendar::save($ANN,\$e,'going','private',false); \$r=CMP_Reminders::run((new DateTimeImmutable('$TODAY 19:30', wp_timezone()))->getTimestamp()); echo 0===\$r['two_hours']?1:0;")

echo "== Privacy"
ok "export lists reminder settings" $(ev "echo false!==strpos(wp_json_encode(CMP_Account::export('appann@example.com'),JSON_UNESCAPED_SLASHES),'Email reminders')?1:0;")
ok "erase removes them" $(ev "CMP_Account::erase('appann@example.com'); echo ''===get_user_meta($ANN,'cmp_rem_email',true)?1:0;")

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev "update_option('timezone_string','$OLDTZ'); update_option('time_format','$OLDTF'); global \$wpdb; \$wpdb->query(\"DELETE FROM $CAL WHERE user_id IN ($ANN,$DEE)\"); \$wpdb->query(\"DELETE FROM $N_T WHERE user_id IN ($ANN,$DEE)\"); foreach(get_posts(array('post_type'=>'cec_event','post_status'=>'any','numberposts'=>-1,'s'=>'ZZ ')) as \$p) wp_delete_post(\$p->ID,true);" >/dev/null
for u in appann appdee; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
