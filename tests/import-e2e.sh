#!/usr/bin/env bash
# End-to-end test of importing events for an organizer and "Copy for
# Facebook, FetLife or Telegram" (Community Events Calendar 1.45.0):
# .ics and .csv uploads on the Calendar Admin page, the group and the
# member or email the events belong to, the email to them, the "New event"
# email to a group's subscribers, CSV columns / dates / prices / errors,
# re-uploads that update instead of duplicating, who may import, and the
# plain-text post on the event page.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/import-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/imp; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -L -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
ev()   { $W eval "$1" 2>&1; }
q()    { ev "global \$wpdb; echo \$wpdb->get_var(\"$1\");"; }
dnonce(){ php -r 'preg_match("~name=\"cec_dash_nonce\" value=\"([^\"]+)\"~",file_get_contents($argv[1]),$m); echo $m[1]??"";' "$1"; }
# upload <user> <file> [extra -F args...]: posts the import form, prints the redirect.
upload(){ local u=$1 f=$2; shift 2; curl -s -b $T/$u.jar -c $T/$u.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" -F "action=cec_dashboard_import_ics" -F "cec_dash_nonce=$N" -F "cec_redirect=$DASH" -F "cec_ics_file=@$f" "$@"; }
ids(){ ev "foreach(get_posts(array('post_type'=>'cec_event','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','s'=>'$1')) as \$i){ echo \$i,' '; }"; }
mails(){ if [ -f wp-content/mail.log ]; then wc -l < wp-content/mail.log; else echo 0; fi; }

for p in community-events-calendar community-member-planning; do rm -rf wp-content/plugins/$p && cp -r $REPO/$p wp-content/plugins/; $W plugin activate $p >/dev/null 2>&1; done
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5

# Clean slate.
ev 'foreach ( get_posts( array( "post_type" => "cec_event", "post_status" => "any", "posts_per_page" => -1, "s" => "ZZI " ) ) as $p ) { wp_delete_post( $p->ID, true ); } foreach ( array( "zzi-rope-crew" ) as $s ) { $t = get_term_by( "slug", $s, "cec_partner_org" ); if ( $t ) { wp_delete_term( $t->term_id, "cec_partner_org" ); } } global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}cec_subscribers WHERE email LIKE \"zzi-%\"" );' >/dev/null
for u in zziorg zzinosy; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create zziorg zziorg@example.com --role=subscriber --user_pass=zziorgpass123 --display_name="ZZI Organizer" >/dev/null
$W user create zzinosy zzinosy@example.com --role=subscriber --user_pass=zzinosypass12 >/dev/null
ORGU=$($W user get zziorg --field=ID)
ORG=$(ev '$t = wp_insert_term( "ZZI Rope Crew", "cec_partner_org", array( "slug" => "zzi-rope-crew" ) ); echo $t["term_id"];')
ev "global \$wpdb; \$wpdb->insert(\$wpdb->prefix.'cec_subscribers',array('email'=>'zzi-fan@example.com','target'=>'$ORG','confirmed'=>1,'token'=>'zzitoken','created_at'=>current_time('mysql')));" >/dev/null
DASH_ID=$($W post create --post_type=page --post_status=publish --post_title="ZZI Dashboard" --post_content='[cec_admin_dashboard]' --porcelain)
EV_ID=$($W post create --post_type=page --post_status=publish --post_title="ZZI My Events" --post_content='[cec_my_events]' --porcelain)
DASH="$H/?page_id=$DASH_ID"
Y=$(( $(date +%Y) + 1 ))

login admin admin admin
get admin d0 "$DASH&cec_view=import"
N=$(dnonce $T/d0.html)

echo "== The import form"
ok "takes .ics and .csv, with the group, managed-by and email options and a CSV template" $([ "$(has $T/d0.html 'accept=".ics,text/calendar,.csv,text/csv"')$(has $T/d0.html 'name="cec_import_org"')$(has $T/d0.html 'ZZI Rope Crew</option>')$(has $T/d0.html 'name="cec_import_owner"')$(has $T/d0.html 'name="cec_import_notify" value="1" checked')$(has $T/d0.html 'download="colb-events-template.csv"')" = 111111 ] && echo 1 || echo 0)

echo "== .ics for an organizer (a member)"
cat > $T/a.ics <<ICS
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:zzi-ics-1@example.org
SUMMARY:ZZI Rope Jam
DTSTART:${Y}0115T190000
DTEND:${Y}0115T220000
LOCATION:The Toolbox Saloon\, Columbus
DESCRIPTION:Bring rope.
URL:https://example.org/ropejam
END:VEVENT
END:VCALENDAR
ICS
M0=$(mails)
r=$(upload admin $T/a.ics -F "cec_import_org=$ORG" -F "cec_import_owner=zziorg" -F "cec_import_notify=1")
E1=$(ids "ZZI Rope Jam" | tr -d ' ')
ok "imported and published" $(echo "$r" | grep -q 'cec_msg=imported' && [ -n "$E1" ] && [ "$(ev "echo get_post_status($E1);")" = publish ] && echo 1 || echo 0)
ok "...belongs to the member (author) with their email as submitter" $([ "$(ev "echo get_post_field('post_author',$E1).'|'.get_post_meta($E1,'_cec_submitter_email',true);")" = "$ORGU|zziorg@example.com" ] && echo 1 || echo 0)
ok "...linked to the group, with its name as host" $([ "$(ev "echo has_term($ORG,'cec_partner_org',$E1) ? 1 : 0; echo '|'.get_post_meta($E1,'_cec_host_org_name',true);")" = "1|ZZI Rope Crew" ] && echo 1 || echo 0)
ok "...date, place and link from the file" $([ "$(ev "echo get_post_meta($E1,'_cec_start',true).'|'.get_post_meta($E1,'_cec_venue_custom_address',true).'|'.get_post_meta($E1,'_cec_more_info_url',true);")" = "${Y}-01-15T19:00|The Toolbox Saloon, Columbus|https://example.org/ropejam" ] && echo 1 || echo 0)
NEW=$(tail -n $(( $(mails) - M0 )) wp-content/mail.log)
ok "the member is emailed the list and where to manage them" $(echo "$NEW" | php -r '$ok=0; foreach(explode("\n",stream_get_contents(STDIN)) as $l){ $m=json_decode($l,true); if(is_array($m) && $m["to"]==="zziorg@example.com" && strpos($m["message"],"ZZI Rope Jam")!==false && strpos($m["message"],"My Events")!==false){ $ok=1; } } echo $ok;')
ok "the group's subscriber gets 'New event' with the date and place filled in" $(echo "$NEW" | php -r '$ok=0; foreach(explode("\n",stream_get_contents(STDIN)) as $l){ $m=json_decode($l,true); if(is_array($m) && $m["to"]==="zzi-fan@example.com" && strpos($m["subject"],"New event: ZZI Rope Jam")!==false && strpos($m["message"],"Jan 15")!==false && strpos($m["message"],"Toolbox")!==false){ $ok=1; } } echo $ok;')
login zziorg zziorg zziorgpass123
get zziorg me "$H/?page_id=$EV_ID"
ok "it's in the member's My Events" $(has $T/me.html 'ZZI Rope Jam')
get admin grp "$(ev "echo get_term_link($ORG,'cec_partner_org');")"
ok "it's on the group's page" $(has $T/grp.html 'ZZI Rope Jam')

echo "== .csv for someone without an account"
cat > $T/b.csv <<CSV
Subject,Start Date,Start Time,End Time,All day event,Description,Location,Price
ZZI Munch,01/20/$Y,7:00 PM,9:00 PM,,Casual munch.,"AWOL, 49 Parsons Ave",Free
ZZI Workshop,$Y-01-22 18:30,,,,"Line one.
Line two.",Studio,\$20
ZZI Fest,$Y-02-01,,,,All weekend.,Park,
ZZI Gala,01/25/$Y,8:00 PM,,,,Hall,\$10-\$20
,01/26/$Y,8:00 PM,,,,Hall,
ZZI Bad Date,next someday,,,,,Hall,
CSV
r=$(upload admin $T/b.csv -F "cec_import_owner=zzi-guest@example.com" -F "cec_import_notify=1")
G1=$(ids "ZZI Munch" | tr -d ' '); G2=$(ids "ZZI Workshop" | tr -d ' '); G3=$(ids "ZZI Fest" | tr -d ' '); G4=$(ids "ZZI Gala" | tr -d ' ')
ok "four good rows imported; the row with no title and the unreadable date are skipped" $([ -n "$G1" ] && [ -n "$G2" ] && [ -n "$G3" ] && [ -n "$G4" ] && [ -z "$(ids "ZZI Bad Date" | tr -d ' ')" ] && echo 1 || echo 0)
ok "Outlook-style Start Date + Start Time + End Time" $([ "$(ev "echo get_post_meta($G1,'_cec_start',true).'|'.get_post_meta($G1,'_cec_end',true);")" = "${Y}-01-20T19:00|${Y}-01-20T21:00" ] && echo 1 || echo 0)
price(){ ev "echo get_post_meta($1,'_cec_admission_status',true).'/'.get_post_meta($1,'_cec_price_amount',true);"; }
ok "prices: Free, \$20, an empty price and a range left Not Posted" $([ "$(price $G1) $(price $G2) $(price $G3) $(price $G4)" = "free_confirmed/ paid/20 not_posted/ not_posted/" ] && echo 1 || echo 0)
ok "a date with no time is an all-day event" $([ "$(ev "echo get_post_meta($G3,'_cec_time_mode',true).'|'.get_post_meta($G3,'_cec_start',true).'|'.get_post_meta($G3,'_cec_end',true);")" = "all_day|${Y}-02-01T00:00|${Y}-02-01T23:59" ] && echo 1 || echo 0)
ok "a description over two lines is kept" $([ "$(ev "echo str_replace(\"\\n\",'/',get_post_field('post_content',$G2));")" = "Line one./Line two." ] && echo 1 || echo 0)
ok "owned by the email like a guest submission (no account)" $([ "$(ev "echo get_post_field('post_author',$G1).'|'.get_post_meta($G1,'_cec_submitter_email',true);")" = "0|zzi-guest@example.com" ] && echo 1 || echo 0)
TOK=$(echo "$r" | sed -n 's/.*cec_import_token=\([a-z0-9]*\).*/\1/p')
get admin res "$DASH&cec_msg=imported&cec_import_token=$TOK"
ok "the result lists each skipped row and the price notes, and says the owner was emailed" $([ "$(has $T/res.html '4 events imported')$(has $T/res.html 'Row 6 has no title')$(has $T/res.html 'Row 7 (&quot;ZZI Bad Date&quot;)')$(has $T/res.html 'wasn&#039;t a single amount')$(has $T/res.html 'was emailed a list')" = 11111 ] && echo 1 || echo 0)
ok "the guest is emailed, pointed to Manage My Submission" $(tail -n 30 wp-content/mail.log | php -r '$ok=0; foreach(explode("\n",stream_get_contents(STDIN)) as $l){ $m=json_decode($l,true); if(is_array($m) && $m["to"]==="zzi-guest@example.com" && strpos($m["message"],"ZZI Munch")!==false && (strpos($m["message"],"Manage My Submission")!==false || strpos($m["message"],"Reply to this email")!==false)){ $ok=1; } } echo $ok;')
ok "Manage My Submission finds the guest's events by email" $([ "$(ev "echo count(get_posts(array('post_type'=>'cec_event','post_status'=>'publish','posts_per_page'=>-1,'meta_query'=>array(array('key'=>'_cec_submitter_email','value'=>'zzi-guest@example.com')))));")" = 4 ] && echo 1 || echo 0)

echo "== Re-uploading"
r=$(upload admin $T/b.csv -F "cec_import_owner=zzi-guest@example.com" -F "cec_ics_update_existing=1")
ok "the same sheet with 'update previously imported' updates, no duplicates" $([ "$(ids "ZZI Munch" | wc -w | tr -d ' ')" = 1 ] && [ "$(ids "ZZI Workshop" | wc -w | tr -d ' ')" = 1 ] && echo 1 || echo 0)

echo "== Refusals"
r=$(upload admin $T/a.ics -F "cec_import_owner=nobody-by-this-name")
ok "an unknown username: nothing imported, a clear message" $(echo "$r" | grep -q 'cec_msg=import_owner_unknown' && [ "$(ids "ZZI Rope Jam" | wc -w | tr -d ' ')" = 1 ] && echo 1 || echo 0)
r=$(upload admin $T/a.ics -F "cec_import_org=999999")
ok "a group that doesn't exist: nothing imported" $(echo "$r" | grep -q 'cec_msg=import_org_unknown' && echo 1 || echo 0)
printf 'Name,When\nZZI Nope,soon\n' > $T/c.csv
cp $T/c.csv $T/c2.csv; printf 'just text\n' > $T/c.txt
r=$(upload admin $T/c.txt)
ok "a file that isn't .ics or .csv is refused" $(echo "$r" | grep -q 'cec_msg=import_failed' && echo 1 || echo 0)
printf 'Foo,Bar\n1,2\n' > $T/d.csv
r=$(upload admin $T/d.csv); TOK=$(echo "$r" | sed -n 's/.*cec_import_token=\([a-z0-9]*\).*/\1/p')
get admin res2 "$DASH&cec_msg=imported&cec_import_token=$TOK"
ok "a CSV without Title/Start columns explains the layout" $(has $T/res2.html 'needs column names')
login zzinosy zzinosy zzinosypass12
code=$(curl -s -b $T/zzinosy.jar -o /dev/null -w '%{http_code}' "$H/wp-admin/admin-post.php" -F "action=cec_dashboard_import_ics" -F "cec_dash_nonce=$N" -F "cec_redirect=$DASH" -F "cec_ics_file=@$T/a.ics")
ok "someone who isn't a calendar manager can't import" $([ "$(ids "ZZI Rope Jam" | wc -w | tr -d ' ')" = 1 ] && [ "$code" != 302 ] && echo 1 || echo 0)

echo "== Copy for Facebook, FetLife or Telegram"
get anon ep "$(ev "echo get_permalink($G2);")"
TXT=$(php -r 'preg_match("~<textarea[^>]*class=\"cec-post-copy-text\"[^>]*>(.*?)</textarea>~s",file_get_contents($argv[1]),$m); echo html_entity_decode($m[1]??"",ENT_QUOTES|ENT_HTML5,"UTF-8");' $T/ep.html)
ok "the event page has the copy box and button" $([ "$(has $T/ep.html 'Copy for Facebook, FetLife or Telegram')$(has $T/ep.html 'cec-post-copy-btn')" = 11 ] && echo 1 || echo 0)
ok "...title, When, Where, Cost and the link back" $(echo "$TXT" | php -r '$t=stream_get_contents(STDIN); echo (strpos($t,"ZZI Workshop")===0 && strpos($t,"When: ")!==false && strpos($t,"Where: Studio")!==false && strpos($t,"Cost: \$20")!==false && preg_match("~Details and RSVP: http\S+~",$t)) ? 1 : 0;')
ok "...the description with its line breaks, and no HTML" $(echo "$TXT" | php -r '$t=stream_get_contents(STDIN); echo (strpos($t,"Line one.")!==false && strpos($t,"Line two.")!==false && false===strpos($t,"<")) ? 1 : 0;')
get anon ep1 "$(ev "echo get_permalink($E1);")"
TXT1=$(php -r 'preg_match("~<textarea[^>]*class=\"cec-post-copy-text\"[^>]*>(.*?)</textarea>~s",file_get_contents($argv[1]),$m); echo html_entity_decode($m[1]??"",ENT_QUOTES|ENT_HTML5,"UTF-8");' $T/ep1.html)
ok "...names the hosting group once, and Price Not Posted when there's no price" $(echo "$TXT1" | php -r '$t=stream_get_contents(STDIN); echo (substr_count($t,"ZZI Rope Crew")===1 && strpos($t,"Hosted by: ZZI Rope Crew")!==false && strpos($t,"Cost: Price Not Posted")!==false) ? 1 : 0;')
ev "update_post_meta($G2,'_cec_event_status','cancelled');" >/dev/null
get anon ep2 "$(ev "echo get_permalink($G2);")"
ok "...a cancelled event says so at the top" $(php -r 'preg_match("~<textarea[^>]*class=\"cec-post-copy-text\"[^>]*>(.*?)</textarea>~s",file_get_contents($argv[1]),$m); $t=html_entity_decode($m[1]??"",ENT_QUOTES|ENT_HTML5,"UTF-8"); echo preg_match("~^ZZI Workshop\nCANCELLED~",$t) ? 1 : 0;' $T/ep2.html)

echo "== PHP errors"
ok "no PHP warnings or notices in the pages" $(cat $T/*.html | grep -qi 'Warning:\|Notice:\|Fatal error\|Deprecated:' && echo 0 || echo 1)

# Clean up.
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev 'foreach ( get_posts( array( "post_type" => "cec_event", "post_status" => "any", "posts_per_page" => -1, "s" => "ZZI " ) ) as $p ) { wp_delete_post( $p->ID, true ); } global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}cec_subscribers WHERE email LIKE \"zzi-%\"" );' >/dev/null
ev "wp_delete_term($ORG,'cec_partner_org');" >/dev/null
$W post delete $DASH_ID $EV_ID --force >/dev/null 2>&1
for u in zziorg zzinosy; do $W user delete $u --yes >/dev/null 2>&1; done
echo "== $PASS passed, $FAIL failed"
[ $FAIL = 0 ]
