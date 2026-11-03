#!/usr/bin/env bash
# Calendar place filter and side day list (Community Events Calendar
# 1.49.0): reading country / state / city from real colandb.com address
# styles, the Country / State / City choices, the filtered month (page,
# AJAX, shared link), the list cache, and, in real Chromium (Playwright),
# the dropdowns, month arrows keeping the filter, the URL, and the day
# list beside the grid on wide screens / below it on narrower ones /
# the phone list on phones.
# Creates and deletes its own "ZZ Loc" events and page.
# Needs Node with Playwright (NODE_PATH=/opt/node-tools/node_modules in
# the cloud sandbox).
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/locations-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/loc; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1 (got: $2)"; FAIL=$((FAIL+1)); fi; }
ev()   { $W eval "$1" 2>&1; }
cleanup(){ ev 'foreach ( get_posts( array( "post_type" => array( "cec_event", "page" ), "post_status" => "any", "posts_per_page" => -1, "fields" => "ids", "s" => "ZZ Loc" ) ) as $i ) { wp_delete_post( $i, true ); } CEC_Locations::flush_all();' >/dev/null; }
# event <title> <Y-m-d> <address> [city] [region] [country] [mode]
event(){
	local id; id=$($W post create --post_type=cec_event --post_status=publish --post_title="$1" --porcelain 2>/dev/null)
	ev "update_post_meta($id,'_cec_start','$2T19:00'); update_post_meta($id,'_cec_end','$2T21:00'); update_post_meta($id,'_cec_location_mode','${7:-in_person}'); update_post_meta($id,'_cec_venue_custom_address','$3'); update_post_meta($id,'_cec_venue_custom_city','$4'); update_post_meta($id,'_cec_venue_custom_region','$5'); update_post_meta($id,'_cec_venue_custom_country','$6'); CEC_Locations::flush_all();" >/dev/null
	echo $id
}

rm -rf wp-content/plugins/community-events-calendar && cp -r $REPO/community-events-calendar wp-content/plugins/
$W plugin activate community-events-calendar >/dev/null 2>&1
cleanup

# --- Reading places out of addresses (styles seen on colandb.com) ---
cat > $T/parse.php <<'PHP'
<?php
$cases = array(
	array( '49 Parsons Ave, Columbus, OH 43215', 'United States', 'Ohio', 'Columbus' ),
	array( '744 Frebis Ave Columbus, OH 43206', 'United States', 'Ohio', 'Columbus' ),
	array( '863 South High St. Columbus, Ohio', 'United States', 'Ohio', 'Columbus' ),
	array( 'The leather stallion 2205 Saint Clair Ave., Cleveland, OH', 'United States', 'Ohio', 'Cleveland' ),
	array( 'Chicago, Illinois, USA', 'United States', 'Illinois', 'Chicago' ),
	array( 'Piscataway, New Jersey, USA (host hotel details on official site)', 'United States', 'New Jersey', 'Piscataway' ),
	array( 'Cherry Grove, Fire Island, New York 11782, USA; host hotel: Belvedere Guest House', 'United States', 'New York', 'Fire Island' ),
	array( 'Baltimore Playhouse, 3010 Washington Blvd, Baltimore, Maryland 21230, USA', 'United States', 'Maryland', 'Baltimore' ),
	array( 'Hyatt Regency Washington on Capitol Hill, 400 New Jersey Ave NW, Washington, DC, USA', 'United States', 'District of Columbia', 'Washington' ),
	array( 'St. Louis metro area, Missouri, USA (hotel location shared with ticket holders)', 'United States', 'Missouri', 'St. Louis' ),
	array( 'South Florida, USA (specific venue not yet announced)', 'United States', 'Florida', '' ),
	array( 'Wisconsin, USA (specific venue to be announced)', 'United States', 'Wisconsin', '' ),
	array( 'Seattle / Puget Sound, Washington, USA', 'United States', 'Washington', 'Seattle' ),
	array( 'Vancouver, British Columbia, Canada', 'Canada', 'British Columbia', 'Vancouver' ),
	array( 'Greater Vancouver / Coquitlam, British Columbia, Canada (2027 venue not confirmed)', 'Canada', 'British Columbia', 'Vancouver' ),
	array( 'Cardiff, Wales, United Kingdom', 'United Kingdom', 'Wales', 'Cardiff' ),
	array( 'London, England, United Kingdom', 'United Kingdom', 'England', 'London' ),
	array( 'Zenithhalle, Munich, Germany', 'Germany', '', 'Munich' ),
	array( 'Berlin, Germany', 'Germany', '', 'Berlin' ),
	array( 'Prague, Czech Republic', 'Czech Republic', '', 'Prague' ),
	array( 'TBD', '', '', '' ),
	array( 'The Toolbox Saloon', '', '', '' ),
);
$bad = array();
foreach ( $cases as $c ) {
	$got = CEC_Locations::for_event( array( 'location_mode' => 'in_person', 'address' => $c[0], 'city' => '', 'region' => '', 'country' => '' ) );
	if ( array( $got['country'], $got['region'], $got['city'] ) !== array( $c[1], $c[2], $c[3] ) ) {
		$bad[] = $c[0] . ' => ' . implode( ' | ', $got );
	}
}
// Filled-in fields win, and are normalised ("OH" / "USA").
$f = CEC_Locations::for_event( array( 'location_mode' => 'in_person', 'address' => '3900 Wildlife way', 'city' => 'Cleveland', 'region' => 'OH', 'country' => 'USA' ) );
if ( array( 'United States', 'Ohio', 'Cleveland' ) !== array_values( $f ) ) { $bad[] = 'fields: ' . implode( ' | ', $f ); }
$f = CEC_Locations::for_event( array( 'location_mode' => 'in_person', 'address' => '2205 St. Clair Ave NE, Cleveland, OH 44144', 'city' => 'Cleveland', 'region' => 'Ohio', 'country' => '' ) );
if ( array( 'United States', 'Ohio', 'Cleveland' ) !== array_values( $f ) ) { $bad[] = 'no country field: ' . implode( ' | ', $f ); }
$f = CEC_Locations::for_event( array( 'location_mode' => 'online', 'address' => 'Chicago, Illinois, USA', 'city' => '', 'region' => '', 'country' => '' ) );
if ( '' !== $f['country'] ) { $bad[] = 'online event has a place'; }
echo $bad ? implode( "\n", $bad ) : 'ok';
PHP
R=$($W eval-file $T/parse.php 2>&1)
ok "addresses read as country / state / city (25 real styles)" $([ "$R" = "ok" ] && echo 1 || echo "$R")

# --- Events this month and next ---
M=$(ev "echo wp_date('Y-m');"); N=$(ev "echo wp_date('Y-m', strtotime('first day of next month'));")
MO=$(ev "echo (int) wp_date('n');"); YR=$(ev "echo (int) wp_date('Y');")
E1=$(event "ZZ Loc Columbus" "$M-10" "49 Parsons Ave, Columbus, OH 43215")
E2=$(event "ZZ Loc Cleveland" "$M-10" "3900 Wildlife way" "Cleveland" "OH" "USA")
E3=$(event "ZZ Loc Chicago" "$M-12" "Chicago, Illinois, USA")
E4=$(event "ZZ Loc Berlin" "$M-14" "Berlin, Germany")
E5=$(event "ZZ Loc Online" "$M-14" "" "" "" "" "online")
E6=$(event "ZZ Loc Toronto" "$N-05" "Toronto, Ontario, Canada")
PAGE=$($W post create --post_type=page --post_status=publish --post_title="ZZ Loc Calendar" --post_content="[cec_calendar]" --porcelain 2>/dev/null)

R=$(ev 'echo wp_json_encode( CEC_Locations::options() );')
ok "choices list Ohio cities, Illinois, Germany and Canada" $(echo "$R" | php -r '$m=json_decode(stream_get_contents(STDIN),true); echo (isset($m["United States"]["Ohio"]["Columbus"],$m["United States"]["Ohio"]["Cleveland"],$m["United States"]["Illinois"]["Chicago"],$m["Germany"][""]["Berlin"],$m["Canada"]["Ontario"]["Toronto"]))?1:0;')
ok "an online event adds no place" $(echo "$R" | grep -qi online && echo 0 || echo 1)

month(){ ev "echo CEC_Shortcodes::render_month_html( $MO, $YR, 0, array( 'country' => '$1', 'region' => '$2', 'city' => '$3' ) );" > $T/m.html; }
has(){ grep -q -- "$1" $T/m.html && echo 1 || echo 0; }
hasnt(){ grep -q -- "$1" $T/m.html && echo 0 || echo 1; }
month "" "" ""
ok "no filter: every event shows" $([ "$(has 'ZZ Loc Columbus')$(has 'ZZ Loc Berlin')$(has 'ZZ Loc Online')" = "111" ] && echo 1 || echo 0)
month "" "Ohio" ""
ok "state Ohio: Columbus and Cleveland only" $([ "$(has 'ZZ Loc Columbus')$(has 'ZZ Loc Cleveland')$(hasnt 'ZZ Loc Chicago')$(hasnt 'ZZ Loc Berlin')$(hasnt 'ZZ Loc Online')" = "11111" ] && echo 1 || echo 0)
month "United States" "" "Chicago"
ok "city Chicago: Chicago only" $([ "$(has 'ZZ Loc Chicago')$(hasnt 'ZZ Loc Columbus')$(hasnt 'ZZ Loc Cleveland')" = "111" ] && echo 1 || echo 0)
month "germany" "" ""
ok "country match ignores capitals" $([ "$(has 'ZZ Loc Berlin')$(hasnt 'ZZ Loc Chicago')" = "11" ] && echo 1 || echo 0)
ok "day list sits in the layout beside the grid" $(grep -q 'class="cec-cal-layout" data-selected=' $T/m.html && grep -q 'cec-cal-layout-inner' $T/m.html && echo 1 || echo 0)

# --- The page and AJAX ---
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
curl -s "$H/?page_id=$PAGE" -o $T/page.html
ok "page shows Country / State / City dropdowns" $(grep -q 'name="cec_country"' $T/page.html && grep -q 'name="cec_region"' $T/page.html && grep -q 'name="cec_city"' $T/page.html && echo 1 || echo 0)
ok "subscribe links and filter sit outside the part arrows replace" $(php -r '$h=file_get_contents($argv[1]); $a=strpos($h,"cec-loc-filter"); $b=strpos($h,"class=\"cec-cal-month\""); echo ($a!==false && $b!==false && $a<$b)?1:0;' $T/page.html)
curl -s "$H/?page_id=$PAGE&cec_city=Chicago" -o $T/shared.html
ok "shared link ?cec_city=Chicago shows Chicago only" $(grep -q 'ZZ Loc Chicago' $T/shared.html && ! grep -q 'ZZ Loc Columbus' $T/shared.html && grep -q '<option value="Chicago" selected' $T/shared.html && echo 1 || echo 0)
ok "shared link shows the Show all places button" $(grep -q 'class="cec-loc-clear">' $T/shared.html && echo 1 || echo 0)
NONCE=$(php -r 'preg_match("~\"nonce\":\"([^\"]+)\"~",file_get_contents($argv[1]),$m); echo $m[1]??"";' $T/page.html)
curl -s "$H/wp-admin/admin-ajax.php" -d "action=cec_calendar_month&nonce=$NONCE&month=$MO&year=$YR&cec_region=Ohio" -o $T/ajax.json
ok "month arrows (AJAX) keep the filter" $(php -r '$j=json_decode(file_get_contents($argv[1]),true); $h=$j["data"]["html"]??""; echo (strpos($h,"ZZ Loc Columbus")!==false && strpos($h,"ZZ Loc Chicago")===false)?1:0;' $T/ajax.json)

# --- The list updates when events change ---
E7=$(event "ZZ Loc Paris" "$M-16" "Paris, France")
ev 'CEC_Locations::options();' >/dev/null
$W post update $E7 --post_title="ZZ Loc Paris moved" >/dev/null 2>&1
ev "update_post_meta($E7,'_cec_venue_custom_address','Madrid, Spain');" >/dev/null
$W post update $E7 --post_status=publish >/dev/null 2>&1
ok "saving an event refreshes the choices" $(ev 'echo isset( CEC_Locations::options()["Spain"] ) ? 1 : 0;')
$W post delete $E7 --force >/dev/null 2>&1
ok "deleting an event refreshes the choices" $(ev 'echo isset( CEC_Locations::options()["Spain"] ) ? 0 : 1;')

# --- Real browser ---
NODE_PATH=${NODE_PATH:-/opt/node-tools/node_modules} node $REPO/tests/locations-e2e.js "$H/?page_id=$PAGE" "$T" > $T/browser.txt 2>&1
cat $T/browser.txt
PASS=$((PASS+$(grep -c '^PASS' $T/browser.txt))); FAIL=$((FAIL+$(grep -c '^FAIL' $T/browser.txt)))
grep -q '^PASS\|^FAIL' $T/browser.txt || { echo "FAIL browser run"; FAIL=$((FAIL+1)); }

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
cleanup
echo; echo "debug.log (plugin-related):"; grep -i 'cec\|events-calendar' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
[ $FAIL -eq 0 ]
