#!/usr/bin/env bash
# End-to-end test of repeating events keeping their own dates (Community
# Events Calendar 1.45.1): saving a series in wp-admin (the block editor's
# meta box save, over real HTTP) must not overwrite the other dates with the
# first one, and the one-time repair puts collapsed dates back in place.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/series-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/series; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
ev()   { $W eval "$1" 2>&1; }
field(){ php -r '$h=file_get_contents($argv[1]); preg_match("~name=\"".preg_quote($argv[2],"~")."\" value=\"([^\"]*)\"~",$h,$m) || preg_match("~id=\"".preg_quote($argv[2],"~")."\" name=\"".preg_quote($argv[2],"~")."\" value=\"([^\"]*)\"~",$h,$m); echo $m[1]??"";' "$1" "$2"; }
starts(){ ev "foreach(get_posts(array('post_type'=>'cec_event','post_status'=>'any','posts_per_page'=>-1,'orderby'=>'ID','order'=>'ASC','fields'=>'ids','meta_key'=>'_cec_recurrence_parent_id','meta_value'=>$1)) as \$c){ echo get_post_meta(\$c,'_cec_start',true),' '; }"; }
kids(){ ev "echo implode(' ', get_posts(array('post_type'=>'cec_event','post_status'=>'any','posts_per_page'=>-1,'orderby'=>'ID','order'=>'ASC','fields'=>'ids','meta_key'=>'_cec_recurrence_parent_id','meta_value'=>$1)));"; }

for p in community-events-calendar community-member-planning; do rm -rf wp-content/plugins/$p && cp -r $REPO/$p wp-content/plugins/; $W plugin activate $p >/dev/null 2>&1; done
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5

ev 'foreach ( get_posts( array( "post_type" => "cec_event", "post_status" => "any", "posts_per_page" => -1, "s" => "ZZS " ) ) as $p ) { wp_delete_post( $p->ID, true ); }' >/dev/null

# A series on the 2nd Tuesday of each month, 7 pm to midnight, ending five months out.
ROOT=$(ev '
$tz = wp_timezone();
$m  = new DateTime( "first day of next month", $tz );
$d  = new DateTime( "second tuesday of " . $m->format( "F Y" ), $tz );
$id = wp_insert_post( array( "post_type" => "cec_event", "post_status" => "publish", "post_title" => "ZZS Crate Night", "post_content" => "Upstairs and downstairs." ) );
update_post_meta( $id, "_cec_start", $d->format( "Y-m-d" ) . "T19:00" );
update_post_meta( $id, "_cec_end", $d->format( "Y-m-d" ) . "T23:59" );
update_post_meta( $id, "_cec_recurrence_rule", "monthly_weekday" );
update_post_meta( $id, "_cec_recurrence_ordinals", "2" );
update_post_meta( $id, "_cec_recurrence_weekdays", "2" );
update_post_meta( $id, "_cec_recurrence_until", ( clone $d )->modify( "+5 months" )->format( "Y-m-d" ) );
update_post_meta( $id, "_cec_admission_status", "free_confirmed" );
CEC_Recurrence::update_series( $id );
echo $id;')
RSTART=$(ev "echo get_post_meta($ROOT,'_cec_start',true);")
S0=$(starts $ROOT); K0=$(kids $ROOT)
second_tuesdays(){ php -r '$ok=1; $n=0; foreach(preg_split("/\s+/",trim($argv[1])) as $s){ if($s===""){continue;} $n++; $d=new DateTime(substr($s,0,10)); if($d->format("w")!=="2" || (int)ceil($d->format("j")/7)!==2 || substr($s,11)!=="19:00"){ $ok=0; } } echo ($ok && $n>=4) ? 1 : 0;' "$1"; }

echo "== A new series"
ok "four later dates (to the end date), each the 2nd Tuesday at 7 pm, all different from the first" $([ "$(echo $S0 | wc -w | tr -d ' ')" = 4 ] && [ "$(echo $S0 | tr ' ' '\n' | sort -u | grep -c .)" = 4 ] && ! echo "$S0" | grep -q "$RSTART" && [ "$(second_tuesdays "$S0")" = 1 ] && echo 1 || echo 0)

echo "== Saving the series in wp-admin (block editor meta box save)"
login admin admin admin
curl -s -b $T/admin.jar -c $T/admin.jar -o $T/edit.html "$H/wp-admin/post.php?post=$ROOT&action=edit"
WPN=$(field $T/edit.html _wpnonce); MBN=$(field $T/edit.html cec_event_meta_nonce)
ok "the edit screen has the event fields and their nonce" $([ -n "$WPN" ] && [ -n "$MBN" ] && echo 1 || echo 0)
UNTIL=$(ev "echo get_post_meta($ROOT,'_cec_recurrence_until',true);")
code=$(curl -s -b $T/admin.jar -c $T/admin.jar -o /dev/null -w '%{http_code}' "$H/wp-admin/post.php?post=$ROOT&action=edit&meta-box-loader=1&meta-box-loader-nonce=$(grep -o 'meta-box-loader-nonce=[0-9a-f]*' $T/edit.html | head -1 | cut -d= -f2)" \
	--data-urlencode "action=editpost" --data-urlencode "post_ID=$ROOT" --data-urlencode "post_type=cec_event" --data-urlencode "_wpnonce=$WPN" \
	--data-urlencode "cec_event_meta_nonce=$MBN" --data-urlencode "cec_start=$RSTART" --data-urlencode "cec_end=${RSTART:0:10}T23:59" \
	--data-urlencode "cec_price_note=Changed in wp-admin" --data-urlencode "cec_recurrence_rule=monthly_weekday" --data-urlencode "cec_recurrence_until=$UNTIL" \
	--data-urlencode "cec_recurrence_ordinals[]=2" --data-urlencode "cec_recurrence_weekdays[]=2")
ok "the save went through and reached the series' other dates" $([ "$code" -lt 400 ] && [ "$(ev "echo get_post_meta($ROOT,'_cec_price_note',true);")" = "Changed in wp-admin" ] && [ "$(ev "\$k=get_posts(array('post_type'=>'cec_event','post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','meta_key'=>'_cec_recurrence_parent_id','meta_value'=>$ROOT)); echo get_post_meta(\$k[0],'_cec_price_note',true);")" = "Changed in wp-admin" ] && echo 1 || echo 0)
S1=$(starts $ROOT)
ok "every other date keeps its own day (none moved to the first date)" $([ "$S1" = "$S0" ] && ! echo "$S1" | grep -q "$RSTART" && echo 1 || echo 0)
ok "...the same posts, nothing recreated" $([ "$(kids $ROOT)" = "$K0" ] && echo 1 || echo 0)

echo "== One-time repair of a series that was already collapsed (1.45.1)"
ev "foreach(explode(' ','$K0') as \$c){ update_post_meta((int)\$c,'_cec_start','$RSTART'); update_post_meta((int)\$c,'_cec_end','${RSTART:0:10}T23:59'); }" >/dev/null
ok "(set up: all the other dates moved to the first date, as on colandb.com)" $([ "$(starts $ROOT | tr ' ' '\n' | sort -u | grep -c .)" = 1 ] && echo 1 || echo 0)
FIXED=$(ev "echo implode(',', CEC_Recurrence::repair_collapsed_dates());")
S2=$(starts $ROOT)
ok "the repair reports the series it fixed" $([ "$FIXED" = "$ROOT" ] && echo 1 || echo 0)
ok "...each date is back: the 2nd Tuesdays, in order, same as when created" $([ "$S2" = "$S0" ] && [ "$(second_tuesdays "$S2")" = 1 ] && echo 1 || echo 0)
ok "...same posts (pages, links and RSVPs kept)" $([ "$(kids $ROOT)" = "$K0" ] && echo 1 || echo 0)
ok "...end times follow (7 pm to 11:59 pm)" $([ "$(ev "\$k=explode(' ','$K0'); echo get_post_meta((int)\$k[0],'_cec_end',true);")" = "$(echo $S0 | cut -d' ' -f1 | cut -c1-10)T23:59" ] && echo 1 || echo 0)
ok "a healthy series is left alone (running it again changes nothing)" $([ -z "$(ev "echo implode(',', CEC_Recurrence::repair_collapsed_dates());")" ] && [ "$(starts $ROOT)" = "$S0" ] && echo 1 || echo 0)
ok "the upgrade runs it once and remembers" $([ "$(ev "delete_option('cec_migrated_1451_series_dates'); CEC_Migrations::maybe_run_all(); \$o=get_option('cec_migrated_1451_series_dates'); echo is_array(\$o) && isset(\$o['at']) ? 1 : 0;")" = 1 ] && echo 1 || echo 0)

echo "== PHP errors"
ok "no plugin warnings in debug.log during this run" $(grep -E "Warning|Fatal|Notice|Deprecated" wp-content/debug.log 2>/dev/null | grep -q "plugins/community-events-calendar" && echo 0 || echo 1)

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev 'foreach ( get_posts( array( "post_type" => "cec_event", "post_status" => "any", "posts_per_page" => -1, "s" => "ZZS " ) ) as $p ) { wp_delete_post( $p->ID, true ); }' >/dev/null
echo "== $PASS passed, $FAIL failed"
[ $FAIL = 0 ]
