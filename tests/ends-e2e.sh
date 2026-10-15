#!/usr/bin/env bash
# End-to-end test of event end times (Community Events Calendar 1.45.3):
# an end earlier the same day is past midnight (saved as the next day), an
# end on an earlier date isn't kept, a repeating series' dates each end after
# their own start, and the one-time repair of events already saved with an
# end before their start (shaped like the live data it was written for).
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/ends-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/ends; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
ev()   { $W eval "$1" 2>&1; }
field(){ php -r '$h=file_get_contents($argv[1]); preg_match("~name=\"".preg_quote($argv[2],"~")."\" value=\"([^\"]*)\"~",$h,$m); echo $m[1]??"";' "$1" "$2"; }
endof(){ ev "echo get_post_meta($1,'_cec_end',true);"; }
mk(){ ev "\$id=wp_insert_post(array('post_type'=>'cec_event','post_status'=>'publish','post_title'=>'$1')); update_post_meta(\$id,'_cec_start','$2'); update_post_meta(\$id,'_cec_end','$3'); echo \$id;"; }
raw(){ ev "global \$wpdb; \$wpdb->update(\$wpdb->postmeta,array('meta_value'=>'$3'),array('post_id'=>$1,'meta_key'=>'$2')); wp_cache_delete($1,'post_meta');" >/dev/null; }

for p in community-events-calendar community-member-planning; do rm -rf wp-content/plugins/$p && cp -r $REPO/$p wp-content/plugins/; $W plugin activate $p >/dev/null 2>&1; done
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
ev 'foreach ( get_posts( array( "post_type" => "cec_event", "post_status" => "any", "posts_per_page" => -1, "s" => "ZZE " ) ) as $p ) { wp_delete_post( $p->ID, true ); }' >/dev/null
: > wp-content/debug.log

echo "== The rule"
ok "same date, earlier time: the next day (7 PM to 12 AM ends at midnight)" $([ "$(ev "echo CEC_Event_Helper::normalize_end('2026-11-10T19:00','2026-11-10T00:00');")" = 2026-11-11T00:00 ] && echo 1 || echo 0)
ok "an earlier date isn't kept" $([ -z "$(ev "echo CEC_Event_Helper::normalize_end('2026-10-24T10:30','2006-10-24T16:00');")" ] && echo 1 || echo 0)
ok "a normal end, an equal end and no end are left as they are" $([ "$(ev "echo CEC_Event_Helper::normalize_end('2026-11-10T19:00','2026-11-10T23:00'),'|',CEC_Event_Helper::normalize_end('2026-11-10T19:00','2026-11-10T19:00'),'|',CEC_Event_Helper::normalize_end('2026-11-10T19:00','');")" = "2026-11-10T23:00|2026-11-10T19:00|" ] && echo 1 || echo 0)

echo "== Every save path (the meta filter)"
E1=$(mk "ZZE Overnight" 2026-11-10T19:00 2026-11-10T00:00)
ok "saving 7 PM to 12 AM stores midnight that night" $([ "$(endof $E1)" = 2026-11-11T00:00 ] && echo 1 || echo 0)
E2=$(mk "ZZE Typo" 2026-10-24T10:30 2006-10-24T16:00)
ok "saving an end in 2006 for a 2026 event keeps no end" $([ -z "$(endof $E2)" ] && echo 1 || echo 0)
ev "update_post_meta($E2,'_cec_end','2026-10-24T16:00');" >/dev/null
ok "...and a correct end saves normally" $([ "$(endof $E2)" = 2026-10-24T16:00 ] && echo 1 || echo 0)

echo "== Saving in wp-admin (block editor meta box save, over HTTP)"
E3=$(mk "ZZE Admin Save" 2026-12-01T20:00 2026-12-01T23:00)
login admin admin admin
curl -s -b $T/admin.jar -c $T/admin.jar -o $T/edit.html "$H/wp-admin/post.php?post=$E3&action=edit"
code=$(curl -s -b $T/admin.jar -c $T/admin.jar -o /dev/null -w '%{http_code}' "$H/wp-admin/post.php?post=$E3&action=edit&meta-box-loader=1&meta-box-loader-nonce=$(grep -o 'meta-box-loader-nonce=[0-9a-f]*' $T/edit.html | head -1 | cut -d= -f2)" \
	--data-urlencode "action=editpost" --data-urlencode "post_ID=$E3" --data-urlencode "post_type=cec_event" --data-urlencode "_wpnonce=$(field $T/edit.html _wpnonce)" \
	--data-urlencode "cec_event_meta_nonce=$(field $T/edit.html cec_event_meta_nonce)" --data-urlencode "cec_start=2026-12-01T20:00" --data-urlencode "cec_end=2026-12-01T02:00" --data-urlencode "cec_recurrence_rule=none")
ok "8 PM to 2 AM entered on one date is saved as 2 AM the next day" $([ "$code" -lt 400 ] && [ "$(endof $E3)" = 2026-12-02T02:00 ] && echo 1 || echo 0)
get_page(){ curl -s -L "$1"; }
ok "the event page shows it ending the next morning, not before it starts" $(get_page "$(ev "echo get_permalink($E3);")" | php -r '$h=stream_get_contents(STDIN); preg_match("~<textarea[^>]*cec-post-copy-text[^>]*>(.*?)</textarea>~s",$h,$m); $t=html_entity_decode($m[1]??"",ENT_QUOTES|ENT_HTML5,"UTF-8"); echo preg_match("~When: Tue, Dec 1, 2026 8:00 pm . (Wed, Dec 2, 2026 )?2:00 am~u",$t) ? 1 : 0;')

echo "== A repeating series (2nd Tuesday, 7 PM to 12 AM)"
ROOT=$(ev '
$id = wp_insert_post( array( "post_type" => "cec_event", "post_status" => "publish", "post_title" => "ZZE Crate Night" ) );
update_post_meta( $id, "_cec_start", "2026-11-10T19:00" );
update_post_meta( $id, "_cec_end", "2026-11-10T00:00" );
update_post_meta( $id, "_cec_recurrence_rule", "monthly_weekday" );
update_post_meta( $id, "_cec_recurrence_ordinals", "2" );
update_post_meta( $id, "_cec_recurrence_weekdays", "2" );
update_post_meta( $id, "_cec_recurrence_until", "2027-04-13" );
CEC_Recurrence::update_series( $id );
echo $id;')
KIDS=$(ev "echo implode(' ', get_posts(array('post_type'=>'cec_event','post_status'=>'any','posts_per_page'=>-1,'orderby'=>'ID','order'=>'ASC','fields'=>'ids','meta_key'=>'_cec_recurrence_parent_id','meta_value'=>$ROOT)));")
PAIRS=$(for k in $KIDS; do ev "echo get_post_meta($k,'_cec_start',true),'>',get_post_meta($k,'_cec_end',true);"; echo; done)
ok "the first date ends at midnight that night" $([ "$(endof $ROOT)" = 2026-11-11T00:00 ] && echo 1 || echo 0)
ok "each later date ends at midnight after its own Tuesday" $([ "$(echo "$PAIRS" | grep -c .)" = 5 ] && echo "$PAIRS" | php -r '$ok=1; foreach(array_filter(explode("\n",stream_get_contents(STDIN))) as $l){ list($s,$e)=explode(">",$l); $n=(new DateTime(substr($s,0,10)))->modify("+1 day")->format("Y-m-d")."T00:00"; if($e!==$n){ $ok=0; } } echo $ok;' | grep -q 1 && echo 1 || echo 0)

echo "== One-time repair (data shaped like colandb.com on 2026-10-15)"
# Bypass the new rule to recreate the old, broken data.
raw $ROOT _cec_end 2026-11-10T00:00
for k in $KIDS; do raw $k _cec_end 2026-11-10T00:00; done
E4=$(mk "ZZE Zoo Adventure" 2026-10-24T10:30 2026-10-24T16:00); raw $E4 _cec_end 2006-10-24T16:00
E5=$(mk "ZZE Fine Event" 2026-10-30T19:00 2026-10-30T22:00)
R=$(ev "\$r=CEC_Migrations::fix_event_ends(); echo implode(',',\$r['fixed']),'|',implode(',',\$r['left']);")
FIXED=${R%|*}; LEFT=${R#*|}
PAIRS2=$(for k in $KIDS; do ev "echo get_post_meta($k,'_cec_start',true),'>',get_post_meta($k,'_cec_end',true);"; echo; done)
ok "the series' first date: midnight that night" $([ "$(endof $ROOT)" = 2026-11-11T00:00 ] && echo 1 || echo 0)
ok "...each later date: midnight after its own Tuesday (no more 'Dec 8 7 pm – Nov 10 12 am')" $([ "$PAIRS2" = "$PAIRS" ] && echo 1 || echo 0)
ok "...all six reported fixed" $([ "$(echo $FIXED | tr ',' '\n' | grep -c .)" = 6 ] && echo ",$FIXED," | grep -q ",$ROOT," && echo 1 || echo 0)
ok "the 2006 typo is left for a person and listed" $([ "$(endof $E4)" = 2006-10-24T16:00 ] && echo ",$LEFT," | grep -q ",$E4," && echo 1 || echo 0)
ok "a correct event isn't touched" $([ "$(endof $E5)" = 2026-10-30T22:00 ] && ! echo ",$FIXED,$LEFT," | grep -q ",$E5," && echo 1 || echo 0)
ok "the upgrade runs it once and remembers what it did" $([ "$(ev "delete_option('cec_migrated_1453_event_ends'); CEC_Migrations::maybe_run_all(); \$o=get_option('cec_migrated_1453_event_ends'); echo (is_array(\$o) && isset(\$o['at'],\$o['fixed'],\$o['left']) && in_array($E4,\$o['left'])) ? 1 : 0;")" = 1 ] && echo 1 || echo 0)
ok "the calendar feed has no event ending before it starts" $(curl -s "$H/wp-admin/admin-post.php?action=cec_ical&feed=all" | php -r '$s=str_replace(array("\r\n ","\r"),array("",""),stream_get_contents(STDIN)); $bad=0; foreach(array_slice(explode("BEGIN:VEVENT",$s),1) as $e){ if(strpos($e,"ZZE ")===false || strpos($e,"Zoo")!==false){continue;} preg_match("~\nDTSTART[^:]*:(\S+)~",$e,$a); preg_match("~\nDTEND[^:]*:(\S+)~",$e,$b); if($a && $b && $b[1] < $a[1]){ $bad++; } } echo $bad ? 0 : 1;')

echo "== PHP errors"
ok "no plugin warnings in debug.log" $(grep -E "Warning|Fatal|Notice|Deprecated" wp-content/debug.log 2>/dev/null | grep -q "plugins/community-events-calendar" && echo 0 || echo 1)

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev 'foreach ( get_posts( array( "post_type" => "cec_event", "post_status" => "any", "posts_per_page" => -1, "s" => "ZZE " ) ) as $p ) { wp_delete_post( $p->ID, true ); }' >/dev/null
echo "== $PASS passed, $FAIL failed"
[ $FAIL = 0 ]
