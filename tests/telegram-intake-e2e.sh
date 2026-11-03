#!/usr/bin/env bash
# End-to-end test of the Telegram event intake (colandb-telegram-intake 0.1.0
# + Community Events Calendar 1.47.0 intake API), against a fake Telegram
# and a fake Claude API (tests/mock-intake.php). Covers: the webhook secret,
# the allowlist, idempotency, text and flyer posts, field rules (no guessed
# free, no made-up links, multi-day kept whole), missing dates, duplicates
# (link, title + date, other edition, cancelled, two at once), edits before
# and after review, API and calendar failures with retry, dry run, image
# checks, notifications, redaction, and that nothing is ever published.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/telegram-intake-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
T=$S/intake; rm -rf $T; mkdir -p $T/files
export COLANDB_TG_BOT_TOKEN='123456:TESTTOKENaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
export COLANDB_TG_WEBHOOK_SECRET='test_secret_0123456789abcdefghijklmnopqrstuv'
export COLANDB_INTAKE_ANTHROPIC_KEY='sk-ant-test-key-0123456789'
export COLANDB_TG_API_BASE='http://127.0.0.1:8898'
export COLANDB_INTAKE_ANTHROPIC_BASE='http://127.0.0.1:8898'
export MOCK_DIR=$T
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
ev()   { $W eval "$1" 2>&1; }
q()    { ev "global \$wpdb; echo \$wpdb->get_var(\"$1\");"; }
P=$(ev 'global $wpdb; echo $wpdb->prefix;')
run()  { ev 'CTI_Processor::run(50);' >/dev/null; }
mails(){ if [ -f wp-content/mail.log ]; then wc -l < wp-content/mail.log; else echo 0; fi; }
UID_N=1000
send() { # send <update-json> [secret]; prints the HTTP code and body
	curl -s -o $T/resp -w '%{http_code}' -X POST -H 'Content-Type: application/json' ${2:+-H "X-Telegram-Bot-Api-Secret-Token: $2"} --data "$1" "$H/?rest_route=/colandb-intake/v1/telegram"; echo " $(cat $T/resp)"
}
post() { # post <kind> <chat_id> <username> <message_id> <text> [extra-json]
	UID_N=$(( $(cat $T/uid 2>/dev/null || echo 1000) + 1 )); echo $UID_N > $T/uid
	php -r '$m=array("message_id"=>(int)$argv[4],"date"=>time(),"chat"=>array("id"=>(int)$argv[2],"type"=>"channel","title"=>"Chan ".$argv[2])); if($argv[3]!=="-") $m["chat"]["username"]=$argv[3]; if($argv[5]!=="") $m[$argv[7]?"caption":"text"]=$argv[5]; $x=json_decode($argv[6],true); if(is_array($x)) $m=array_merge($m,$x); if($argv[1]==="edited_channel_post") $m["edit_date"]=time(); echo json_encode(array("update_id"=>(int)$argv[8],$argv[1]=>$m));' "$1" "$2" "$3" "$4" "$5" "${6:-}" "$( [ -n "${6:-}" ] && echo 1 || echo 0 )" "$UID_N"
}
deliver() { send "$(post "$@")" "$COLANDB_TG_WEBHOOK_SECRET"; }
gmsg() { # gmsg <kind> <chat_id> <message_id> <topic|0> <from_id|0> <text> [extra-json]
	UID_N=$(( $(cat $T/uid 2>/dev/null || echo 1000) + 1 )); echo $UID_N > $T/uid
	php -r '$m=array("message_id"=>(int)$argv[3],"date"=>time(),"chat"=>array("id"=>(int)$argv[2],"type"=>"supergroup","title"=>"Group ".$argv[2],"is_forum"=>true)); if((int)$argv[5]) $m["from"]=array("id"=>(int)$argv[5],"is_bot"=>false,"first_name"=>"Pat","last_name"=>"Member","username"=>"patmember"); if((int)$argv[4]){ $m["message_thread_id"]=(int)$argv[4]; $m["is_topic_message"]=true; } if($argv[6]!=="") $m["text"]=$argv[6]; $x=json_decode($argv[7],true); if(is_array($x)) $m=array_merge($m,$x); if($argv[1]==="edited_message") $m["edit_date"]=time(); echo json_encode(array("update_id"=>(int)$argv[8],$argv[1]=>$m));' "$1" "$2" "$3" "$4" "$5" "$6" "${7:-}" "$UID_N"
}
gdeliver() { send "$(gmsg "$@")" "$COLANDB_TG_WEBHOOK_SECRET"; }
entry() { q "SELECT $1 FROM ${P}cti_entries e JOIN ${P}cti_posts p ON p.id=e.post_id WHERE p.chat_id='$2' AND p.message_id=$3 ORDER BY e.idx LIMIT 1"; }
pstat() { q "SELECT status FROM ${P}cti_posts WHERE chat_id='$1' AND message_id=$2"; }
meta()  { ev "echo get_post_meta($1, '$2', true);"; }

# Plugins and settings.
rm -rf wp-content/plugins/community-events-calendar wp-content/plugins/colandb-telegram-intake
cp -r $REPO/community-events-calendar $REPO/colandb-telegram-intake wp-content/plugins/
$W plugin activate community-events-calendar colandb-telegram-intake >/dev/null 2>&1
mkdir -p wp-content/mu-plugins
cat > wp-content/mu-plugins/cti-test.php <<'PHP'
<?php
add_filter( 'cti_album_wait', function () { return 0; } );
// The test runs the queue itself; WP-Cron firing on page loads would race it.
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }
PHP
for t in Social Themed Educational; do $W term create cec_event_type $t >/dev/null 2>&1; done
ev 'CTI_Install::create_tables(); global $wpdb; foreach (array("posts","entries","updates","log") as $t) $wpdb->query("DELETE FROM ".CTI_Install::table($t)); delete_option("cti_unknown_chats"); $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"cti_lock_%\" OR option_name LIKE \"_transient_cti_alert_%\"");' >/dev/null
ev 'foreach (get_posts(array("post_type"=>"cec_event","post_status"=>"any","numberposts"=>-1,"fields"=>"ids","meta_key"=>"_cec_intake_source_url")) as $id) wp_delete_post($id,true);' >/dev/null
ev 'update_option("cti_settings", array_merge(CTI_Config::defaults(), array("channels"=>CTI_Config::parse_channels("-1001111111111 | no | Test Channel\n@flyerchan | | Flyer Channel\n-1002222222222 | no\n-1005555555555 | yes | Test Group | topics: 275, 573\n-1006666666666 | no | Admin Group | admins"), "dry_run"=>false, "notify_emails"=>"editor@example.com", "max_attempts"=>3)));' >/dev/null
ev '$s=get_option("cec_settings",array()); $s["auto_publish"]=1; update_option("cec_settings",$s);' >/dev/null
ok "test setup: the calendar's auto-publish setting is on (intake must ignore it)" $(ev 'echo CEC_Admin_Settings::get("auto_publish") ? 1 : 0;')

# Test images.
php -r '$i=imagecreatetruecolor(400,300); imagefill($i,0,0,imagecolorallocate($i,200,30,90)); imagejpeg($i,$argv[1]."/files/flyer1.jpg",85); imagejpeg($i,$argv[1]."/files/flyer2.jpg",60);' $T
echo "this is not an image" > $T/files/notimage.jpg

pkill -f "php -S 127.0.0.1:8898" 2>/dev/null; pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(php -S 127.0.0.1:8898 $REPO/tests/mock-intake.php >/dev/null 2>&1 &)
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
rm -f wp-content/mail.log
C1=-1001111111111; C2=-1002222222222; C3=-1003333333333

echo "== Webhook security and allowlist"
U=$(post channel_post $C1 - 1 "hello FX:basic")
r=$(send "$U"); ok "no secret header: refused (401)" $([ "${r%% *}" = 401 ] && echo 1 || echo 0)
r=$(send "$U" "wrong-secret-wrong-secret-wrong-secret-x"); ok "wrong secret: refused (401)" $([ "${r%% *}" = 401 ] && echo 1 || echo 0)
ok "nothing stored from refused requests" $([ "$(q "SELECT COUNT(*) FROM ${P}cti_updates")" = 0 ] && echo 1 || echo 0)
r=$(deliver channel_post $C3 - 5 "Party FX:basic"); ok "channel not on the allowlist: ignored, nothing stored" $(echo "$r" | grep -q not_allowed && [ "$(q "SELECT COUNT(*) FROM ${P}cti_posts")" = 0 ] && echo 1 || echo 0)
ok "the ignored channel's ID is listed for the administrator" $(ev 'echo isset(get_option("cti_unknown_chats")["-1003333333333"]) ? 1 : 0;')
r=$(send "$(php -r 'echo json_encode(array("update_id"=>5000,"message"=>array("message_id"=>1,"date"=>time(),"chat"=>array("id"=>42,"type"=>"private"),"from"=>array("id"=>42,"first_name"=>"Someone"),"text"=>"hi")));')" "$COLANDB_TG_WEBHOOK_SECRET")
ok "a private message to the bot: ignored, no user data stored" $(echo "$r" | grep -q '"ignored"' && [ "$(q "SELECT COUNT(*) FROM ${P}cti_posts")" = 0 ] && echo 1 || echo 0)

echo "== A text post becomes a pending event"
BASIC_BODY='Leather Social Night! $15 at the door, 744 N High St. Tickets https://tickets.example.com/leather-social?utm_source=tg'
BASIC_TEXT="$BASIC_BODY FX:basic"
U=$(post channel_post $C1 - 10 "$BASIC_TEXT")
r1=$(send "$U" "$COLANDB_TG_WEBHOOK_SECRET"); r2=$(send "$U" "$COLANDB_TG_WEBHOOK_SECRET")
ok "stored once; the same update delivered again is a duplicate" $(echo "$r1" | grep -q stored && echo "$r2" | grep -q duplicate && [ "$(q "SELECT COUNT(*) FROM ${P}cti_posts")" = 1 ] && echo 1 || echo 0)
M0=$(mails); run
E1=$(entry event_id $C1 10)
ok "entry submitted and linked to an event" $([ "$(entry e.status $C1 10)" = submitted ] && [ "$E1" -gt 0 ] && echo 1 || echo 0)
ok "the event is pending (auto-publish is on and still ignored)" $([ "$(ev "echo get_post_status($E1);")" = pending ] && echo 1 || echo 0)
ok "start, end and time mode" $([ "$(meta $E1 _cec_start)|$(meta $E1 _cec_end)|$(meta $E1 _cec_time_mode)" = "$(date -u -d '+30 days' +%F)T20:00|$(date -u -d '+30 days' +%F)T23:00|exact" ] && echo 1 || echo 0)
ok "paid \$15, in person, venue text, organizer, ticket link (external RSVP)" $([ "$(meta $E1 _cec_admission_status)|$(meta $E1 _cec_price_amount)|$(meta $E1 _cec_location_mode)|$(meta $E1 _cec_venue_custom_city)|$(meta $E1 _cec_host_org_name)|$(meta $E1 _cec_rsvp_mode)" = "paid|15|in_person|Columbus|Columbus Leather Collective|external" ] && echo 1 || echo 0)
ok "event type set from an existing term only" $(ev "echo implode(',', wp_get_post_terms($E1,'cec_event_type',array('fields'=>'names')));" | grep -qx Social && echo 1 || echo 0)
ok "source post kept: _cec_source_url and the description's Source link" $([ "$(meta $E1 _cec_source_url)" = "https://t.me/c/1111111111/10" ] && ev "echo get_post_field('post_content',$E1);" | grep -q 'Source: <a href="https://t.me/c/1111111111/10">Telegram: Test Channel</a>' && echo 1 || echo 0)
ok "editor notes stored and shown in the event preview" $(ev "echo CEC_Event_Helper::render_editor_preview($E1);" | grep -q 'Imported automatically from a Telegram post' && echo 1 || echo 0)
ok "the calendar's audit log records the intake" $([ "$(q "SELECT COUNT(*) FROM ${P}cec_event_log WHERE event_id=$E1 AND action='event_intake_created'")" = 1 ] && echo 1 || echo 0)
ok "one email to the configured editor, with the event link" $([ $(( $(mails) - M0 )) = 1 ] && tail -1 wp-content/mail.log | grep -q 'editor@example.com' && tail -1 wp-content/mail.log | grep -q "post=$E1" && echo 1 || echo 0)
A=$(tail -1 $T/anthropic.log)
ok "Claude request: key header, API version, structured output, fallbacks, effort set" $(echo "$A" | grep -q '"HTTP_X_API_KEY":"sk-ant-test-key-0123456789"' && echo "$A" | grep -q '"HTTP_ANTHROPIC_VERSION":"2026-10-21"' && echo "$A" | grep -q '"format":"json_schema"' && echo "$A" | grep -q '"fallbacks":"default"' && echo "$A" | grep -q '"effort":"medium"' && echo 1 || echo 0)
ok "the post text is wrapped as untrusted data" $(echo "$A" | grep -q 'telegram_post' && echo 1 || echo 0)

echo "== A flyer (image only) is read; image reuse follows the channel setting"
r=$(deliver channel_post -1004444444444 flyerchan 20 "" '{"photo":[{"file_id":"small","file_unique_id":"s1","file_size":100,"width":90,"height":60},{"file_id":"flyer1","file_unique_id":"f1","file_size":5000,"width":400,"height":300}]}')
run; E2=$(entry event_id -1004444444444 20)
A=$(tail -1 $T/anthropic.log)
ok "the largest photo was sent to the reader as image/jpeg, bytes intact" $(echo "$A" | grep -q '"images":1' && echo "$A" | grep -qF '"media_type":"image\/jpeg"' && echo "$A" | grep -q "\"image_md5\":\"$(md5sum $T/files/flyer1.jpg | cut -d' ' -f1)\"" && echo 1 || echo 0)
ok "flyer event pending, free (FREE ENTRY on the flyer)" $([ "$(ev "echo get_post_status($E2);")" = pending ] && [ "$(meta $E2 _cec_admission_status)" = free_confirmed ] && echo 1 || echo 0)
ok "public link from the channel username" $([ "$(meta $E2 _cec_source_url)" = "https://t.me/flyerchan/20" ] && echo 1 || echo 0)
ok "link read from the image kept but flagged; year assumed is flagged" $([ "$(meta $E2 _cec_official_website_url)" = "https://pupmosh.example.org" ] && ev "echo implode('|',get_post_meta($E2,'_cec_intake_notes',true));" | grep -q 'read from the flyer image' && ev "echo implode('|',get_post_meta($E2,'_cec_intake_notes',true));" | grep -q 'does not give the year' && echo 1 || echo 0)
ok "channel with no flag (the default): flyer used as the event image" $([ "$(ev "echo (int) get_post_thumbnail_id($E2);")" -gt 0 ] && echo 1 || echo 0)
r=$(deliver channel_post $C1 - 21 "Pup Mosh again but private FX:flyer" '{"photo":[{"file_id":"flyer2","file_unique_id":"f2","file_size":4000,"width":400,"height":300}]}')
run; E3=$(entry event_id $C1 21); S3=$(entry e.status $C1 21)
ok "same event from another channel: flagged as a duplicate (same title and date)" $([ "$S3" = possible_duplicate ] && echo 1 || echo 0)
ok "a channel marked no: settings keep it, flyers not used" $(ev 'echo CTI_Config::channel_for(array("id"=>"-1001111111111"))["reuse_images"] ? 0 : 1;')
SE=$(entry e.id $C1 21)
ev "CTI_Processor::editor_create($SE, array(), 1);" >/dev/null; E21=$(entry event_id $C1 21)
ok "editor confirms it is separate: pending event, no flyer copied for a 'no' channel" $([ "$(ev "echo get_post_status($E21);")" = pending ] && [ "$(ev "echo (int) get_post_thumbnail_id($E21);")" = 0 ] && ev "echo implode('|',get_post_meta($E21,'_cec_intake_notes',true));" | grep -q 'set not to share' && echo 1 || echo 0)

echo "== Field rules"
deliver channel_post $C1 - 30 "Bootblack clinic, \$0 FX:free_noword" >/dev/null; run; E=$(entry event_id $C1 30)
ok "\$0 is not free: Price not posted" $([ "$(meta $E _cec_admission_status)" = not_posted ] && echo 1 || echo 0)
ok "a link not in the post is left out" $([ -z "$(meta $E _cec_official_website_url)" ] && ev "echo implode('|',get_post_meta($E,'_cec_intake_notes',true));" | grep -q 'does not appear in the post' && echo 1 || echo 0)
ok "an event type the site doesn't have is not created" $([ -z "$(ev "echo implode(',', wp_get_post_terms($E,'cec_event_type',array('fields'=>'names')));")" ] && [ "$(ev 'echo term_exists("Workshops","cec_event_type") ? 1 : 0;')" = 0 ] && echo 1 || echo 0)
deliver channel_post $C1 - 31 "Midwest Leather Weekend FX:multi" >/dev/null; run; E=$(entry event_id $C1 31)
ok "multi-day: one event, inclusive dates, timezone kept, sessions as a note" $([ "$(q "SELECT COUNT(*) FROM ${P}cti_entries e JOIN ${P}cti_posts p ON p.id=e.post_id WHERE p.message_id=31")" = 1 ] && [ "$(meta $E _cec_start)|$(meta $E _cec_end)|$(meta $E _cec_timezone)|$(meta $E _cec_admission_status)" = "$(date -u -d '+40 days' +%F)T00:00|$(date -u -d '+42 days' +%F)T23:59|America/New_York|price_varies" ] && ev "echo implode('|',get_post_meta($E,'_cec_intake_notes',true));" | grep -q 'Sessions mentioned' && echo 1 || echo 0)
deliver channel_post $C1 - 32 "Roundup FX:twoevents https://meet.example.com/one" >/dev/null; run
ok "a roundup post gives one entry per event" $([ "$(q "SELECT COUNT(*) FROM ${P}cti_entries e JOIN ${P}cti_posts p ON p.id=e.post_id WHERE p.message_id=32 AND e.status='submitted'")" = 2 ] && echo 1 || echo 0)

echo "== Not submitted: missing or uncertain information, not an event"
deliver channel_post $C1 - 40 "Gear swap soon! FX:nodate" >/dev/null; run
ok "no date: kept in the intake queue (needs info), no event" $([ "$(entry e.status $C1 40)" = needs_info ] && [ "$(entry event_id $C1 40)" = 0 ] && entry e.reason $C1 40 | grep -q 'No event date' && echo 1 || echo 0)
deliver channel_post $C1 - 41 "Blurry FX:lowconf" >/dev/null; run
ok "uncertain title: needs info" $([ "$(entry e.status $C1 41)" = needs_info ] && echo 1 || echo 0)
deliver channel_post $C1 - 42 "Great time last week! FX:notevent" >/dev/null; run
ok "not an event announcement: no entry" $([ "$(pstat $C1 42)" = not_event ] && [ "$(q "SELECT COUNT(*) FROM ${P}cti_entries e JOIN ${P}cti_posts p ON p.id=e.post_id WHERE p.message_id=42")" = 0 ] && echo 1 || echo 0)
deliver channel_post $C1 - 43 "FX:refusal" >/dev/null; run
ok "the reader declines: an editor gets a needs-info item" $([ "$(entry e.status $C1 43)" = needs_info ] && echo 1 || echo 0)
NE=$(entry e.id $C1 40)
ev "var_export( CTI_Processor::editor_create($NE, array('title'=>'Gear Swap','start_date'=>'$(date -u -d '+55 days' +%F)','start_time'=>'14:00','end_date'=>'','end_time'=>''), 1) );" >/dev/null
E=$(entry event_id $C1 40)
ok "editor fills in the date: pending event created" $([ "$E" -gt 0 ] && [ "$(ev "echo get_post_status($E);")" = pending ] && [ "$(meta $E _cec_start)" = "$(date -u -d '+55 days' +%F)T14:00" ] && echo 1 || echo 0)

echo "== Duplicates"
deliver channel_post $C2 - 50 "Leather social at Toolbox https://www.tickets.example.com/leather-social/ FX:basic_repost" >/dev/null; run
R=$(entry e.candidates $C2 50)
ok "same ticket link (tracking removed, www and slash ignored): possible duplicate, no new event" $([ "$(entry e.status $C2 50)" = possible_duplicate ] && [ "$(entry event_id $C2 50)" = 0 ] && echo "$R" | grep -q "same event\\\\/ticket link" && echo "$R" | grep -q "\"event_id\":$E1" && echo 1 || echo 0)
deliver channel_post $C2 - 51 "Leather Social Night at the Toolbox FX:basic_title_only" >/dev/null; run
ok "same title, date and venue (no link): possible duplicate with reasons" $([ "$(entry e.status $C2 51)" = possible_duplicate ] && entry e.candidates $C2 51 | grep -q 'same start date' && echo 1 || echo 0)
deliver channel_post $C1 - 52 "Next month: Leather Social Night FX:basic_next_month" >/dev/null; run
ok "same title and venue on another date: a new event (another edition)" $([ "$(entry e.status $C1 52)" = submitted ] && echo 1 || echo 0)
DE=$(entry e.id $C2 50)
ev "var_export( CTI_Processor::editor_link($DE, $E1, 1) );" >/dev/null
ok "editor links it: both sources on the event, details unchanged" $([ "$(entry e.status $C2 50)" = linked ] && [ "$(ev "echo count(get_post_meta($E1,'_cec_intake_source_url'));")" = 2 ] && [ "$(ev "echo get_the_title($E1);")" = "Leather Social Night" ] && echo 1 || echo 0)
# Cancelled and published events still match.
ev "wp_update_post(array('ID'=>$E2,'post_status'=>'publish')); update_post_meta($E2,'_cec_event_status','cancelled');" >/dev/null
ev "wp_delete_post($E21, true);" >/dev/null
ev 'global $wpdb; $wpdb->query("DELETE FROM '${P}'cti_entries WHERE post_id IN (SELECT id FROM '${P}'cti_posts WHERE message_id=21)");' >/dev/null
deliver channel_post $C2 - 53 "Pup Mosh is back FX:flyer" '{"photo":[{"file_id":"flyer1","file_unique_id":"f1x","file_size":5000,"width":400,"height":300}]}' >/dev/null; run
R=$(entry e.candidates $C2 53)
ok "repost of a published, cancelled event: possible duplicate (same flyer), shows it is cancelled" $([ "$(entry e.status $C2 53)" = possible_duplicate ] && echo "$R" | grep -q 'same flyer image' && echo "$R" | grep -q 'published, cancelled' && echo 1 || echo 0)
# Two copies at once: the submit lock lets only one through.
deliver channel_post $C1 - 60 "Race night https://tickets.example.com/race FX:race" >/dev/null
deliver channel_post $C2 - 61 "Race night! https://tickets.example.com/race FX:race" >/dev/null
P60=$(q "SELECT id FROM ${P}cti_posts WHERE message_id=60"); P61=$(q "SELECT id FROM ${P}cti_posts WHERE message_id=61")
( ev "CTI_Processor::process_post(CTI_Processor::get_post($P60));" >/dev/null & ev "CTI_Processor::process_post(CTI_Processor::get_post($P61));" >/dev/null & wait )
ok "two posts of one event processed at the same time: one event, one possible duplicate" $([ "$(q "SELECT COUNT(*) FROM ${P}cti_entries WHERE post_id IN ($P60,$P61) AND status='submitted'")" = 1 ] && [ "$(q "SELECT COUNT(*) FROM ${P}cti_entries WHERE post_id IN ($P60,$P61) AND status='possible_duplicate'")" = 1 ] && echo 1 || echo 0)

echo "== Edited source posts"
ev "wp_update_post(array('ID'=>$E1,'post_title'=>'Leather Social Night (edited by editor)'));" >/dev/null
r=$(deliver edited_channel_post $C1 - 10 "$BASIC_BODY 9pm now FX:basic_edited")
run
ok "edit before approval: the time is updated, the editor's title kept, still pending" $([ "$(meta $E1 _cec_start)" = "$(date -u -d '+30 days' +%F)T21:00" ] && [ "$(ev "echo get_the_title($E1);")" = "Leather Social Night (edited by editor)" ] && [ "$(ev "echo get_post_status($E1);")" = pending ] && [ "$(entry e.status $C1 10)" = updated ] && echo 1 || echo 0)
ok "the change and the conflict are noted for the editor" $(ev "echo implode('|',get_post_meta($E1,'_cec_intake_notes',true));" | grep -q 'Source post edited' && ev "echo implode('|',get_post_meta($E1,'_cec_intake_notes',true));" | grep -q "editor's value was kept" && echo 1 || echo 0)
ok "still one event for the post" $([ "$(q "SELECT COUNT(*) FROM ${P}posts WHERE post_type='cec_event' AND post_status<>'trash' AND ID=$E1")" = 1 ] && [ "$(q "SELECT COUNT(*) FROM ${P}cti_entries e JOIN ${P}cti_posts p ON p.id=e.post_id WHERE p.chat_id='$C1' AND p.message_id=10")" = 1 ] && echo 1 || echo 0)
r=$(deliver edited_channel_post $C1 - 10 "$BASIC_BODY 9pm now FX:basic_edited")
ok "the same edit again changes nothing" $(echo "$r" | grep -q unchanged && echo 1 || echo 0)
ev "wp_update_post(array('ID'=>$E1,'post_status'=>'publish'));" >/dev/null
BEFORE=$(meta $E1 _cec_start)
deliver edited_channel_post $C1 - 10 "$BASIC_BODY 9:30pm, \$20 FX:basic_edited2" >/dev/null; run
ok "edit after publishing: the public event is not changed; review item with the differences" $([ "$(meta $E1 _cec_start)" = "$BEFORE" ] && [ "$(meta $E1 _cec_price_amount)" = 15 ] && [ "$(entry e.status $C1 10)" = update_review ] && entry e.candidates $C1 10 | grep -q '"after":"20"' && echo 1 || echo 0)

echo "== Failures are kept and retried"
deliver channel_post $C1 - 70 "Overloaded FX:http529" >/dev/null; run
ok "Claude overloaded (529): post kept for retry with a later attempt time" $([ "$(pstat $C1 70)" = retry ] && [ "$(q "SELECT attempts FROM ${P}cti_posts WHERE message_id=70")" = 1 ] && [ -n "$(q "SELECT next_attempt FROM ${P}cti_posts WHERE message_id=70")" ] && echo 1 || echo 0)
M0=$(mails)
for i in 1 2; do ev 'global $wpdb; $wpdb->query("UPDATE '${P}'cti_posts SET next_attempt=\"2000-01-01 00:00:00\" WHERE message_id=70");' >/dev/null; run; done
ok "after the last try: failed, the administrator is alerted once" $([ "$(pstat $C1 70)" = failed ] && [ $(( $(mails) - M0 )) = 1 ] && tail -1 wp-content/mail.log | grep -q 'needs attention' && echo 1 || echo 0)
ev 'CTI_Processor::editor_retry((int) $GLOBALS["wpdb"]->get_var("SELECT id FROM '${P}'cti_posts WHERE message_id=70"));' >/dev/null
ok "retry from the review screen puts it back in the queue" $([ "$(pstat $C1 70)" = retry ] && [ "$(q "SELECT attempts FROM ${P}cti_posts WHERE message_id=70")" = 0 ] && echo 1 || echo 0)
deliver channel_post $C1 - 71 "Bad key FX:http401" >/dev/null; M0=$(mails); run
ok "bad API key: kept for retry, administrator alerted" $([ "$(pstat $C1 71)" = retry ] && [ $(( $(mails) - M0 )) -ge 1 ] && echo 1 || echo 0)
$W plugin deactivate community-events-calendar >/dev/null 2>&1
deliver channel_post $C1 - 72 "Calendar down FX:race2" >/dev/null; run
ok "calendar plugin inactive: post kept for retry, not lost" $([ "$(pstat $C1 72)" = retry ] && q "SELECT reason FROM ${P}cti_posts WHERE message_id=72" | grep -q 'not active' && echo 1 || echo 0)
$W plugin activate community-events-calendar >/dev/null 2>&1
ev 'global $wpdb; $wpdb->query("UPDATE '${P}'cti_posts SET next_attempt=\"2000-01-01 00:00:00\" WHERE message_id=72");' >/dev/null; run
ok "calendar back: the kept post is processed" $([ "$(pstat $C1 72)" = done ] && [ "$(entry e.status $C1 72)" = submitted ] && echo 1 || echo 0)

echo "== Images are checked"
deliver channel_post $C1 - 80 "Not a picture FX:race3" '{"photo":[{"file_id":"notimage","file_unique_id":"n1","file_size":30,"width":10,"height":10}]}' >/dev/null; run
ok "a file that is not really an image is not read or copied (text still used)" $([ "$(entry e.status $C1 80)" = submitted ] && q "SELECT detail FROM ${P}cti_log WHERE action='image_skipped' ORDER BY id DESC LIMIT 1" | grep -q 'not an accepted image' && echo 1 || echo 0)
deliver channel_post $C1 - 81 "Huge FX:race4" '{"photo":[{"file_id":"big","file_unique_id":"b1","file_size":50000000,"width":9000,"height":9000}]}' >/dev/null; run
ok "an image over the size limit is skipped before download" $(q "SELECT detail FROM ${P}cti_log WHERE action='image_skipped' ORDER BY id DESC LIMIT 1" | grep -q 'limit' && ! grep -q 'getFile {"file_id":"big"' $T/telegram.log && echo 1 || echo 0)
deliver channel_post $C1 - 82 "" '{"document":{"file_id":"doc1","file_unique_id":"d1","file_size":100,"mime_type":"application/pdf"}}' >/dev/null
ok "a PDF document is not treated as a flyer" $([ "$(q "SELECT photos FROM ${P}cti_posts WHERE message_id=82")" = "[]" ] && echo 1 || echo 0)

echo "== Albums"
deliver channel_post $C1 - 90 "" '{"media_group_id":"alb1","photo":[{"file_id":"flyer1","file_unique_id":"a1","file_size":5000,"width":400,"height":300}]}' >/dev/null
deliver channel_post $C1 - 91 "Album caption FX:race5" '{"media_group_id":"alb1","photo":[{"file_id":"flyer2","file_unique_id":"a2","file_size":4000,"width":400,"height":300}]}' >/dev/null
run
ok "an album is processed once, with its caption and both photos" $([ "$(pstat $C1 91)" = merged ] && [ "$(pstat $C1 90)" = done ] && [ "$(q "SELECT COUNT(*) FROM ${P}cti_entries e JOIN ${P}cti_posts p ON p.id=e.post_id WHERE p.message_id IN (90,91)")" = 1 ] && tail -1 $T/anthropic.log | grep -q '"images":2' && echo 1 || echo 0)

echo "== Dry run"
ev '$s=get_option("cti_settings"); $s["dry_run"]=true; update_option("cti_settings",$s);' >/dev/null
N0=$(q "SELECT COUNT(*) FROM ${P}posts WHERE post_type='cec_event'"); M0=$(mails)
deliver channel_post $C1 - 100 "Dry FX:race6" >/dev/null; run
ok "dry run: proposed event logged, nothing created, no email" $([ "$(entry e.status $C1 100)" = dry_run ] && [ "$(q "SELECT COUNT(*) FROM ${P}posts WHERE post_type='cec_event'")" = "$N0" ] && [ "$(mails)" = "$M0" ] && q "SELECT detail FROM ${P}cti_log WHERE action='dry_run' ORDER BY id DESC LIMIT 1" | grep -q 'cec_title' && echo 1 || echo 0)
ev '$s=get_option("cti_settings"); $s["dry_run"]=false; update_option("cti_settings",$s);' >/dev/null

echo "== Never published, nothing secret logged"
ok "every event the intake created is pending (or set by the test)" $([ "$(q "SELECT COUNT(*) FROM ${P}posts p JOIN ${P}postmeta m ON m.post_id=p.ID AND m.meta_key='_cec_intake_source_url' WHERE p.post_status NOT IN ('pending') AND p.ID NOT IN ($E1,$E2)")" = 0 ] && echo 1 || echo 0)
ok "no token, secret or API key in the log, entries or emails" $( (q "SELECT GROUP_CONCAT(detail) FROM ${P}cti_log"; q "SELECT GROUP_CONCAT(reason) FROM ${P}cti_posts"; cat wp-content/mail.log) | grep -q -e 'TESTTOKEN' -e 'test_secret_0123' -e 'sk-ant-test' && echo 0 || echo 1)
ok "the review screen lists what needs a decision" $(ev 'wp_set_current_user(1); ob_start(); CTI_Admin::render(); echo ob_get_clean();' | grep -q 'Possible duplicate' && echo 1 || echo 0)
ok "WP-CLI dry-run extraction prints proposed fields" $($W colandb-intake extract --text="Test FX:basic https://tickets.example.com/leather-social?utm_source=tg" 2>/dev/null | grep -q '"cec_admission_status": "paid"' && echo 1 || echo 0)

echo "== Groups and topics (0.3.0)"
G=-1005555555555; GA=-1006666666666
r=$(gdeliver message $G 300 275 42 "Munch next month FX:grpone")
ok "a member's message in an event topic is kept" $(echo "$r" | grep -q stored && echo 1 || echo 0)
ok "its link points at the topic message" $([ "$(q "SELECT post_url FROM ${P}cti_posts WHERE chat_id='$G' AND message_id=300")" = "https://t.me/c/5555555555/275/300" ] && echo 1 || echo 0)
run
ok "it becomes a pending event" $([ "$(entry e.status $G 300)" = submitted ] && [ "$(ev "echo get_post_status($(entry event_id $G 300));")" = pending ] && echo 1 || echo 0)
ok "the member's name and account are not stored anywhere" $( (q "SELECT GROUP_CONCAT(CONCAT(body,channel,post_url,photos,links)) FROM ${P}cti_posts"; q "SELECT GROUP_CONCAT(detail) FROM ${P}cti_log"; q "SELECT GROUP_CONCAT(proposed) FROM ${P}cti_entries") | grep -q -e 'patmember' -e 'Pat Member' && echo 0 || echo 1)
r=$(gdeliver message $G 301 999 42 "Anyone going to the munch? FX:grptwo")
ok "a message in another topic is skipped and not stored" $(echo "$r" | grep -q filtered_topic && [ -z "$(pstat $G 301)" ] && echo 1 || echo 0)
ok "the skipped topic number is listed for the administrator (no message text)" $(ev 'echo wp_json_encode(get_option("cti_seen_topics"));' | grep -q '"topic":999' && ! ev 'echo wp_json_encode(get_option("cti_seen_topics"));' | grep -q 'munch' && echo 1 || echo 0)
r=$(gdeliver message $G 302 0 42 "General chat FX:grpthree")
ok "a message in General (no topic) is skipped" $(echo "$r" | grep -q filtered_topic && echo 1 || echo 0)
r=$(gdeliver message $G 303 275 42 "" '{"new_chat_members":[{"id":43,"is_bot":false,"first_name":"New"}]}')
ok "service messages (someone joined) are skipped" $(echo "$r" | grep -q filtered_service && echo 1 || echo 0)
r=$(gdeliver message $G 304 275 0 "Bot says hi" '{"from":{"id":99,"is_bot":true,"first_name":"OtherBot"}}')
ok "other bots' messages are skipped" $(echo "$r" | grep -q filtered_bot && echo 1 || echo 0)
r=$(gdeliver edited_message $G 300 275 42 "Munch next month, new time FX:grpone_b")
ok "an edited message in an event topic is handled as an edit" $(echo "$r" | grep -q edited && echo 1 || echo 0)
r=$(send "$(gmsg message $G 305 275 42 'x FX:grpfour' | sed 's/"message"/"channel_post"/')" "$COLANDB_TG_WEBHOOK_SECRET")
ok "a group message labelled as a channel post is ignored" $(echo "$r" | grep -q '"ignored"' && echo 1 || echo 0)
r=$(gdeliver message $GA 310 0 42 "Member event FX:grpfive")
ok "admins-only group: a member's message is skipped" $(echo "$r" | grep -q filtered_not_admin && echo 1 || echo 0)
r=$(gdeliver message $GA 311 0 777 "Admin event FX:grpsix")
ok "admins-only group: an admin's message is kept" $(echo "$r" | grep -q stored && echo 1 || echo 0)
r=$(gdeliver message $GA 312 0 0 "Anonymous admin FX:grpseven" '{"sender_chat":{"id":-1006666666666,"type":"supergroup","title":"Admin Group"}}')
ok "admins-only group: a message sent as the group is kept" $(echo "$r" | grep -q stored && echo 1 || echo 0)
r=$(gdeliver message -1007777777777 320 0 42 "Not listed FX:grpeight")
ok "a group not on the list is ignored; its ID and type are listed" $(echo "$r" | grep -q not_allowed && ev 'echo wp_json_encode(get_option("cti_unknown_chats"));' | grep -q '"type":"supergroup"' && echo 1 || echo 0)
ok "the allowlist line with topics and admins reads back the same" $([ "$(ev 'echo CTI_Config::channels_text(CTI_Config::parse_channels("-1003923571403 | yes | COL&B group | topics: 129, 275, 573\n-1000000001 | no | x | admins"));')" = "$(printf -- '-1003923571403 | yes | COL&B group | topics: 129, 275, 573\n-1000000001 | no | x | admins')" ] && echo 1 || echo 0)
ok "the webhook asks Telegram for group messages too" $(ev 'echo implode(",", CTI_Receiver::UPDATE_TYPES);' | grep -q 'channel_post,edited_channel_post,message,edited_message' && echo 1 || echo 0)

echo "== Secrets in Settings (0.2.0) and diagnostics"
NE="env -u COLANDB_TG_BOT_TOKEN -u COLANDB_TG_WEBHOOK_SECRET -u COLANDB_INTAKE_ANTHROPIC_KEY"
nev() { $NE php $S/wp-cli.phar --allow-root eval "$1" 2>&1; }
nev 'delete_option("cti_secrets");' >/dev/null
ok "with nothing configured, secrets are missing" $([ "$(nev 'echo CTI_Config::bot_token()==="" && CTI_Config::secret_source("COLANDB_TG_BOT_TOKEN")==="" ? 1 : 0;')" = 1 ] && echo 1 || echo 0)
ok "the webhook secret is made automatically (64 characters, not autoloaded)" $([ "$(nev 'echo strlen(CTI_Config::ensure_webhook_secret());')" = 64 ] && [ "$(q "SELECT autoload FROM ${P}options WHERE option_name='cti_secrets'")" != yes ] && [ "$(q "SELECT autoload FROM ${P}options WHERE option_name='cti_secrets'")" != on ] && echo 1 || echo 0)
W1=$(nev 'echo CTI_Config::webhook_secret();'); nev 'CTI_Config::ensure_webhook_secret();' >/dev/null
ok "made once: asking again keeps the same secret" $([ "$(nev 'echo CTI_Config::webhook_secret();')" = "$W1" ] && echo 1 || echo 0)
nev 'wp_set_current_user(1); $_POST=array("secret"=>array("COLANDB_TG_BOT_TOKEN"=>"not-a-token","COLANDB_INTAKE_ANTHROPIC_KEY"=>"sk-ant-pastedkey0123456789"),"channels"=>CTI_Config::channels_text(CTI_Config::get("channels"))); $p=CTI_Admin::save_settings(); echo implode(" ",$p);' > $T/save1
ok "a wrong-looking bot token is refused with a clear message; a good Claude key is saved" $(grep -q 'does not look right' $T/save1 && [ "$(nev 'echo CTI_Config::bot_token()==="" ? 1 : 0;')" = 1 ] && [ "$(nev 'echo CTI_Config::anthropic_key();')" = sk-ant-pastedkey0123456789 ] && [ "$(nev 'echo CTI_Config::secret_source("COLANDB_INTAKE_ANTHROPIC_KEY");')" = settings ] && echo 1 || echo 0)
nev 'wp_set_current_user(1); $_POST=array("secret"=>array("COLANDB_TG_BOT_TOKEN"=>"9876543210:AAtesttokenvaluefromsettings0123456789"),"channels"=>CTI_Config::channels_text(CTI_Config::get("channels"))); CTI_Admin::save_settings();' >/dev/null
ok "a pasted bot token is saved and used" $([ "$(nev 'echo CTI_Config::bot_token();')" = "9876543210:AAtesttokenvaluefromsettings0123456789" ] && echo 1 || echo 0)
nev 'wp_set_current_user(1); $_POST=array("secret"=>array("COLANDB_TG_BOT_TOKEN"=>""),"channels"=>CTI_Config::channels_text(CTI_Config::get("channels"))); CTI_Admin::save_settings();' >/dev/null
ok "saving with an empty box keeps the saved value" $([ "$(nev 'echo CTI_Config::bot_token();')" = "9876543210:AAtesttokenvaluefromsettings0123456789" ] && echo 1 || echo 0)
nev 'wp_set_current_user(1); $_GET["tab"]="settings"; ob_start(); CTI_Admin::render(); echo ob_get_clean();' > $T/settings.html
ok "the Settings page shows 'Saved' but never the values" $(grep -q 'Saved ✓' $T/settings.html && ! grep -q -e 'AAtesttokenvalue' -e 'sk-ant-pastedkey' -e "$W1" $T/settings.html && echo 1 || echo 0)
nev 'echo wp_json_encode(cti_diagnostics(false));' > $T/diag.json
ok "diagnostics report sources and queue counts, never secret values" $(grep -q '"bot_token":"settings"' $T/diag.json && grep -q '"posts":{' $T/diag.json && ! grep -q -e 'AAtesttokenvalue' -e 'sk-ant-pastedkey' -e "$W1" $T/diag.json && echo 1 || echo 0)
ok "wp-config or environment values win over Settings" $([ "$(ev 'echo CTI_Config::secret_source("COLANDB_TG_BOT_TOKEN");')" = environment ] && echo 1 || echo 0)
nev 'wp_set_current_user(1); $_POST=array("clear_secret"=>array("COLANDB_TG_BOT_TOKEN"=>"1"),"channels"=>CTI_Config::channels_text(CTI_Config::get("channels"))); CTI_Admin::save_settings();' >/dev/null
ok "Remove clears a saved secret" $([ "$(nev 'echo CTI_Config::bot_token()==="" ? 1 : 0;')" = 1 ] && echo 1 || echo 0)
nev 'delete_option("cti_secrets");' >/dev/null
ev 'foreach (get_posts(array("post_type"=>"cec_event","post_status"=>"any","numberposts"=>-1,"fields"=>"ids","meta_key"=>"_cec_intake_source_url")) as $id) wp_delete_post($id,true);' >/dev/null
rm -f wp-content/mu-plugins/cti-test.php
ev '$s=get_option("cec_settings",array()); $s["auto_publish"]=0; update_option("cec_settings",$s);' >/dev/null
pkill -f "php -S 127.0.0.1:8898" 2>/dev/null
echo "== $PASS passed, $FAIL failed"
[ -f wp-content/debug.log ] && grep -i 'telegram-intake\|class-cti\|class-cec-intake' wp-content/debug.log | tail -5
[ $FAIL = 0 ]
