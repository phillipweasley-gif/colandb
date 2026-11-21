#!/usr/bin/env bash
# Homepage "Upcoming events" strip, [cec_upcoming] (Community Events
# Calendar 1.53.0): only in-person events in the chosen state (Ohio by
# default), online and unreadable places left out, the strip still filled
# to its count from later events, region="" shows everywhere, another
# state by attribute, the wp-admin setting.
# Creates and deletes its own "ZZ Up" events.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/upcoming-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1 (got: $2)"; FAIL=$((FAIL+1)); fi; }
ev()   { $W eval "$1" 2>&1; }
cleanup(){ ev 'foreach ( get_posts( array( "post_type" => "cec_event", "post_status" => "any", "posts_per_page" => -1, "fields" => "ids" ) ) as $i ) { if ( 0 === strpos( get_the_title( $i ), "ZZ Up" ) ) { wp_delete_post( $i, true ); } } $o = get_option( "cec_settings", array() ); unset( $o["upcoming_region"] ); update_option( "cec_settings", $o );' >/dev/null; }
# event <title> <days from now> <address> [region] [country] [mode]
event(){
	local id; id=$($W post create --post_type=cec_event --post_status=publish --post_title="$1" --porcelain 2>/dev/null)
	ev "\$d = date( 'Y-m-d', current_time( 'timestamp' ) + $2 * DAY_IN_SECONDS ); update_post_meta($id,'_cec_start',\$d.'T19:00'); update_post_meta($id,'_cec_end',\$d.'T21:00'); update_post_meta($id,'_cec_location_mode','${6:-in_person}'); update_post_meta($id,'_cec_venue_custom_address','$3'); update_post_meta($id,'_cec_venue_custom_region','$4'); update_post_meta($id,'_cec_venue_custom_country','$5');" >/dev/null
	echo $id
}
# Titles shown by a shortcode, one per line.
strip(){ ev "echo do_shortcode( '$1' );" | grep -o 'cec-upcoming-title">[^<]*' | sed 's/.*">//'; }

rm -rf wp-content/plugins/community-events-calendar && cp -r $REPO/community-events-calendar wp-content/plugins/
$W plugin activate community-events-calendar >/dev/null 2>&1
cleanup
# Move every other event out of the way so only ours are upcoming.
OTHERS=$(ev 'echo implode( " ", get_posts( array( "post_type" => "cec_event", "post_status" => "publish", "posts_per_page" => -1, "fields" => "ids" ) ) );')
for i in $OTHERS; do $W post update $i --post_status=draft >/dev/null 2>&1; done

event "ZZ Up 1 Columbus" 1 "49 Parsons Ave, Columbus, OH 43215" >/dev/null
event "ZZ Up 2 Toronto" 2 "Toronto, Ontario, Canada" >/dev/null
event "ZZ Up 3 Online" 3 "" "" "" online >/dev/null
event "ZZ Up 4 Cleveland" 4 "" "Ohio" "United States" >/dev/null
event "ZZ Up 5 Chicago" 5 "Chicago, Illinois, USA" >/dev/null
event "ZZ Up 6 Nowhere" 6 "The usual place" >/dev/null
event "ZZ Up 7 Cincinnati" 7 "123 Main St, Cincinnati, Ohio 45202" >/dev/null
event "ZZ Up 8 Indy" 8 "" "IN" "" >/dev/null
event "ZZ Up 9 Dayton" 9 "" "OH" "" >/dev/null
for n in 10 11 12 13 14 15 16 17 18 19 20; do event "ZZ Up 2$n Akron" $((n+10)) "Akron, OH" >/dev/null; done

OUT=$(strip '[cec_upcoming count="4"]')
ok "default shows only Ohio events, in date order" $([ "$(echo "$OUT" | tr '\n' '|')" = "ZZ Up 1 Columbus|ZZ Up 4 Cleveland|ZZ Up 7 Cincinnati|ZZ Up 9 Dayton|" ] && echo 1 || echo "$OUT")
ok "Toronto, Chicago, Indiana left out" $(echo "$OUT" | grep -cE 'Toronto|Chicago|Indy' | grep -q '^0$' && echo 1 || echo 0)
ok "online event left out" $(echo "$OUT" | grep -c Online | grep -q '^0$' && echo 1 || echo 0)
ok "event with an unreadable place left out" $(echo "$OUT" | grep -c Nowhere | grep -q '^0$' && echo 1 || echo 0)
ok "strip filled to its count past the skipped events" $([ "$(strip '[cec_upcoming count="8"]' | wc -l)" = "8" ] && echo 1 || echo 0)
ok "default count (8) used without count=" $([ "$(strip '[cec_upcoming]' | wc -l)" = "8" ] && echo 1 || echo 0)
ok "region=\"\" shows every place" $([ "$(strip '[cec_upcoming count="6" region=""]' | tr '\n' '|')" = "ZZ Up 1 Columbus|ZZ Up 2 Toronto|ZZ Up 3 Online|ZZ Up 4 Cleveland|ZZ Up 5 Chicago|ZZ Up 6 Nowhere|" ] && echo 1 || echo 0)
ok "region=\"IN\" shows Indiana only" $([ "$(strip '[cec_upcoming region="IN"]' | tr '\n' '|')" = "ZZ Up 8 Indy|" ] && echo 1 || echo 0)
ok "region=\"Ontario\" shows Ontario only" $([ "$(strip '[cec_upcoming region="Ontario"]' | tr '\n' '|')" = "ZZ Up 2 Toronto|" ] && echo 1 || echo 0)
ok "a state with no events says so" $(ev "echo do_shortcode( '[cec_upcoming region=\"Texas\"]' );" | grep -c 'No upcoming events' | grep -q '^1$' && echo 1 || echo 0)

# The wp-admin setting
ok "setting defaults to Ohio" $([ "$(ev 'echo CEC_Admin_Settings::get( "upcoming_region" );')" = "Ohio" ] && echo 1 || echo 0)
ev '$o = CEC_Admin_Settings::sanitize( array_merge( CEC_Admin_Settings::get(), array( "upcoming_region" => "Illinois" ) ) ); update_option( "cec_settings", $o );' >/dev/null
ok "setting saved as Illinois changes the strip" $([ "$(strip '[cec_upcoming]' | tr '\n' '|')" = "ZZ Up 5 Chicago|" ] && echo 1 || echo 0)
ev '$o = CEC_Admin_Settings::sanitize( array_merge( CEC_Admin_Settings::get(), array( "upcoming_region" => "" ) ) ); update_option( "cec_settings", $o );' >/dev/null
ok "setting saved blank shows everywhere" $([ "$(strip '[cec_upcoming count="3"]' | tr '\n' '|')" = "ZZ Up 1 Columbus|ZZ Up 2 Toronto|ZZ Up 3 Online|" ] && echo 1 || echo 0)
ok "settings page shows the new field" $(ev 'wp_set_current_user( 1 ); ob_start(); CEC_Admin_Settings::render_page(); echo ob_get_clean();' | grep -c 'id="cec_upcoming_region"' | grep -q '^1$' && echo 1 || echo 0)

cleanup
for i in $OTHERS; do $W post update $i --post_status=publish >/dev/null 2>&1; done
WARN=$(grep -c 'community-events-calendar' wp-content/debug.log 2>/dev/null || echo 0)
ok "no plugin warnings in debug.log" $([ "$WARN" = "0" ] && echo 1 || echo "$WARN")
echo "RESULT: $PASS passed, $FAIL failed"
