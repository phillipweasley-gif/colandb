#!/usr/bin/env bash
# End-to-end test of the intake REST routes (CEC 1.50.0): /wp-json/cec/v1/intake/*
# over real HTTP with application passwords.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/intake-rest-e2e.sh
# Needs jq.
WPT=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $WPT/wordpress
W="php $WPT/wp-cli.phar --allow-root"
PASS=0; FAIL=0
ok(){ if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
yes1(){ [ "$1" = "true" ] && echo 1 || echo 0; }
mails(){ if [ -f wp-content/mail.log ]; then wc -l < wp-content/mail.log; else echo 0; fi; }

rm -rf wp-content/plugins/community-events-calendar && cp -r $REPO/community-events-calendar wp-content/plugins/
$W plugin activate community-events-calendar >/dev/null 2>&1
printf '<?php add_filter( "wp_is_application_passwords_available", "__return_true" );\n' > wp-content/mu-plugins/intake-rest-test-app-passwords.php
for u in ir-admin ir-editor ir-sub; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create ir-admin ir-admin@example.invalid --role=administrator >/dev/null
$W user create ir-editor ir-editor@example.invalid --role=editor >/dev/null
$W user create ir-sub ir-sub@example.invalid --role=subscriber >/dev/null
ADMIN=$(printf 'ir-admin:%s' "$($W user application-password create ir-admin Claude --porcelain)" | base64 -w0)
EDITOR=$(printf 'ir-editor:%s' "$($W user application-password create ir-editor Claude --porcelain)" | base64 -w0)
SUB=$(printf 'ir-sub:%s' "$($W user application-password create ir-sub Claude --porcelain)" | base64 -w0)
SOCIAL=$($W term create cec_event_type "IR Social" --porcelain 2>/dev/null || $W term list cec_event_type --name="IR Social" --field=term_id)
rm -f wp-content/debug.log

pkill -f "php -S 127.0.0.1:8897" 2>/dev/null; (PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8897 -t . >/dev/null 2>&1 &); sleep 1.5
B='http://127.0.0.1:8897/?rest_route=/cec/v1/intake'
# req AUTH METHOD PATH [JSON] -> body;  st: status code only
req(){ curl -s -X "$2" "$B$3" ${1:+-H "Authorization: Basic $1"} -H 'Content-Type: application/json' ${4:+--data "$4"}; }
st(){ curl -s -o /dev/null -w '%{http_code}' -X "$2" "$B$3" ${1:+-H "Authorization: Basic $1"} -H 'Content-Type: application/json' ${4:+--data "$4"}; }
meta(){ $W post meta get "$1" "$2" 2>/dev/null; }

EV='{"cec_title":"IR Contest","cec_start":"2026-11-21T19:00","cec_end":"2026-11-21T21:00","cec_description":"<p>Hello</p>","cec_excerpt":"Short","cec_location_mode":"in_person","cec_venue_custom_address":"508A Church Street, Toronto, ON M4Y 2C8","cec_venue_custom_city":"Toronto","cec_venue_custom_region":"Ontario","cec_venue_custom_country":"Canada","cec_timezone":"America/Toronto","cec_host_org_name":"Surr","cec_rsvp_url":"https://www.eventbrite.ca/e/x-1","cec_official_website_url":"https://mrlakeontarioleather.com","event_type_ids":['$SOCIAL'],"author_id":1,"status":"publish"}'

echo "== Who may use it"
ok "no credentials -> 401" $([ "$(st '' POST /events "$EV")" = 401 ] && echo 1 || echo 0)
ok "subscriber -> 403" $([ "$(st "$SUB" POST /events "$EV")" = 403 ] && echo 1 || echo 0)
ok "editor -> 403" $([ "$(st "$EDITOR" POST /events "$EV")" = 403 ] && echo 1 || echo 0)
ok "subscriber can't read an event" $([ "$(st "$SUB" GET /events/1)" = 403 ] && echo 1 || echo 0)
ok "subscriber can't list candidates" $([ "$(st "$SUB" GET '/candidates&start=2026-11-21')" = 403 ] && echo 1 || echo 0)
ok "subscriber can't create a venue" $([ "$(st "$SUB" POST /venues '{"name":"X"}')" = 403 ] && echo 1 || echo 0)

echo "== Create"
M0=$(mails)
r=$(req "$ADMIN" POST /events "$EV"); ID=$(echo "$r" | jq -r '.id')
ok "admin -> created, id returned" $([ "$ID" -gt 0 ] 2>/dev/null && echo 1 || echo 0)
ok "pending, even when asked to publish" $([ "$($W post get $ID --field=post_status)" = pending ] && echo 1 || echo 0)
ok "author is the admin, not the author_id sent" $([ "$($W post get $ID --field=post_author)" = "$($W user get ir-admin --field=ID)" ] && echo 1 || echo 0)
ok "start and end saved" $([ "$(meta $ID _cec_start)" = 2026-11-21T19:00 ] && [ "$(meta $ID _cec_end)" = 2026-11-21T21:00 ] && echo 1 || echo 0)
ok "address, city, region, country saved" $([ "$(meta $ID _cec_venue_custom_address)" = "508A Church Street, Toronto, ON M4Y 2C8" ] && [ "$(meta $ID _cec_venue_custom_city)" = Toronto ] && [ "$(meta $ID _cec_venue_custom_country)" = Canada ] && echo 1 || echo 0)
ok "host, ticket link (external RSVP), website saved" $([ "$(meta $ID _cec_host_org_name)" = Surr ] && [ "$(meta $ID _cec_rsvp_url)" = "https://www.eventbrite.ca/e/x-1" ] && [ "$(meta $ID _cec_rsvp_mode)" = external ] && [ "$(meta $ID _cec_official_website_url)" = "https://mrlakeontarioleather.com" ] && echo 1 || echo 0)
ok "timezone saved" $([ "$(meta $ID _cec_timezone)" = America/Toronto ] && echo 1 || echo 0)
ok "event type set" $($W post term list $ID cec_event_type --field=term_id | grep -qx "$SOCIAL" && echo 1 || echo 0)
ok "response has the event's fields" $(yes1 $(echo "$r" | jq '.post_status=="pending" and .cec_start=="2026-11-21T19:00" and .cec_title=="IR Contest"'))
ok "audit log says it came through the REST API" $($W eval "global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}cec_event_log WHERE event_id=$ID AND action='event_rest_created'\");" | grep -qx 1 && echo 1 || echo 0)
ok "no email sent" $([ "$(mails)" = "$M0" ] && echo 1 || echo 0)
ok "missing start -> 400" $([ "$(st "$ADMIN" POST /events '{"cec_title":"x"}')" = 400 ] && echo 1 || echo 0)
ok "bad end -> 400" $([ "$(st "$ADMIN" POST /events '{"cec_title":"x","cec_start":"2026-11-21T19:00","cec_end":"Saturday"}')" = 400 ] && echo 1 || echo 0)
ok "bad link -> 400" $([ "$(st "$ADMIN" POST /events '{"cec_title":"x","cec_start":"2026-11-21T19:00","cec_rsvp_url":"javascript:alert(1)"}')" = 400 ] && echo 1 || echo 0)

echo "== Read and update"
ok "GET returns the event" $(yes1 $(req "$ADMIN" GET /events/$ID | jq '.id=='$ID' and .cec_end=="2026-11-21T21:00"'))
ok "GET missing -> 404" $([ "$(st "$ADMIN" GET /events/999999)" = 404 ] && echo 1 || echo 0)
r=$(req "$ADMIN" POST /events/$ID '{"cec_start":"2026-11-21T19:30","cec_title":"IR Contest 2"}')
ok "update changes only what was sent" $([ "$(meta $ID _cec_start)" = 2026-11-21T19:30 ] && [ "$(meta $ID _cec_end)" = 2026-11-21T21:00 ] && [ "$($W post get $ID --field=post_title)" = "IR Contest 2" ] && echo 1 || echo 0)
ok "update leaves it pending" $([ "$($W post get $ID --field=post_status)" = pending ] && echo 1 || echo 0)
PUB=$($W post create --post_type=cec_event --post_status=publish --post_title="IR Published" --porcelain)
ok "update of a published event -> 400, unchanged" $([ "$(st "$ADMIN" POST /events/$PUB '{"cec_title":"Hacked"}')" = 400 ] && [ "$($W post get $PUB --field=post_title)" = "IR Published" ] && echo 1 || echo 0)
DR=$($W post create --post_type=cec_event --post_status=draft --post_title="IR Draft" --porcelain)
ok "update of a draft -> 400" $([ "$(st "$ADMIN" POST /events/$DR '{"cec_title":"x"}')" = 400 ] && echo 1 || echo 0)
ok "editor can't update" $([ "$(st "$EDITOR" POST /events/$ID '{"cec_title":"x"}')" = 403 ] && echo 1 || echo 0)

echo "== Duplicates"
ok "candidates find it by date" $(yes1 $(req "$ADMIN" GET '/candidates&start=2026-11-20&end=2026-11-22' | jq "map(.id)|index($ID)!=null"))
ok "candidates find it by link" $(yes1 $(req "$ADMIN" GET '/candidates&urls[]=https://www.eventbrite.ca/e/x-1' | jq "map(.id)|index($ID)!=null"))

echo "== Venues"
r=$(req "$ADMIN" POST /venues '{"name":"IR Zone","address":"508 Church Street","city":"Toronto","region":"ON","country":"Canada"}'); V=$(echo "$r" | jq -r .id)
ok "create venue -> id" $([ "$V" -gt 0 ] 2>/dev/null && echo 1 || echo 0)
ok "venue address saved" $([ "$($W term meta get $V cec_address 2>/dev/null)" = "508 Church Street" ] && echo 1 || echo 0)
ok "find venue by name" $(yes1 $(req "$ADMIN" GET '/venues/find&name=The%20IR%20Zone' | jq ".id==$V and .name==\"IR Zone\""))
ok "find venue by street" $(yes1 $(req "$ADMIN" GET '/venues/find&address=508%20Church%20St' | jq ".id==$V"))
ok "unknown venue -> 0" $(yes1 $(req "$ADMIN" GET '/venues/find&name=Nowhere%20Special' | jq '.id==0'))
ok "venue without a name -> 400" $([ "$(st "$ADMIN" POST /venues '{"city":"Toronto"}')" = 400 ] && echo 1 || echo 0)
req "$ADMIN" POST /events/$ID "{\"venue_term_id\":$V}" >/dev/null
ok "event linked to the saved venue, typed address cleared" $($W post term list $ID cec_venue --field=term_id | grep -qx "$V" && [ -z "$(meta $ID _cec_venue_custom_address)" ] && echo 1 || echo 0)

echo "== Organization profiles (1.51.0)"
ORG=$($W term create cec_partner_org "IR Title" --porcelain)
$W term meta update $ORG cec_contact '{"email":"secret@example.invalid"}' --format=json >/dev/null
PNG=$WPT/ir-logo.png; php -r '$i=imagecreatetruecolor(20,20);imagepng($i,$argv[1]);' "$PNG" 2>/dev/null; LOGO=$($W media import "$PNG" --porcelain)
ok "subscriber can't read a profile" $([ "$(st "$SUB" GET /orgs/$ORG)" = 403 ] && echo 1 || echo 0)
ok "editor can't change a profile" $([ "$(st "$EDITOR" POST /orgs/$ORG '{"listed":true}')" = 403 ] && echo 1 || echo 0)
ok "GET leaves out the private contact" $(yes1 $(req "$ADMIN" GET /orgs/$ORG | jq '.name=="IR Title" and (has("contact")|not) and (has("zip")|not)'))
ok "missing org -> 404" $([ "$(st "$ADMIN" GET /orgs/999999)" = 404 ] && echo 1 || echo 0)
r=$(req "$ADMIN" POST /orgs/$ORG "{\"description\":\"A new title.\",\"kind\":\"organization\",\"listed\":true,\"region\":\"Toronto, Ontario\",\"socials\":{\"website\":\"mrlakeontarioleather.com\",\"instagram\":\"https://www.instagram.com/irtitle\"},\"logo_id\":$LOGO,\"mission\":\"Leather, leadership, community.\"}")
ok "profile saved: description, kind, listed, region, mission" $(yes1 $(echo "$r" | jq '.description=="A new title." and .kind=="organization" and .listed==true and .region=="Toronto, Ontario" and .mission=="Leather, leadership, community." and .order>0'))
ok "links cleaned, website kept in cec_url" $(yes1 $(echo "$r" | jq '.socials.website=="https://mrlakeontarioleather.com" and .socials.instagram=="https://www.instagram.com/irtitle"') ) 
ok "cec_url mirrors the website" $([ "$($W term meta get $ORG cec_url)" = "https://mrlakeontarioleather.com" ] && echo 1 || echo 0)
ok "logo set from the media library" $(yes1 $(echo "$r" | jq ".logo_id==$LOGO and (.logo|test(\"ir-logo\"))"))
ok "contact untouched" $($W term meta get $ORG cec_contact --format=json | grep -q secret@ && echo 1 || echo 0)
ok "only what is sent changes" $(yes1 $(req "$ADMIN" POST /orgs/$ORG '{"listed":false}' | jq '.listed==false and .description=="A new title." and .socials.website=="https://mrlakeontarioleather.com"'))
ok "US city + state" $(yes1 $(req "$ADMIN" POST /orgs/$ORG '{"city":"Columbus","state":"OH"}' | jq '.city=="Columbus" and .state=="OH" and .region=="Columbus, OH"'))
ok "bad kind -> 400" $([ "$(st "$ADMIN" POST /orgs/$ORG '{"kind":"club"}')" = 400 ] && echo 1 || echo 0)
ok "bad year -> 400" $([ "$(st "$ADMIN" POST /orgs/$ORG '{"year":"27"}')" = 400 ] && echo 1 || echo 0)
ok "unknown link -> 400" $([ "$(st "$ADMIN" POST /orgs/$ORG '{"socials":{"myspace":"https://myspace.com/x"}}')" = 400 ] && echo 1 || echo 0)
ok "not an image -> 400" $([ "$(st "$ADMIN" POST /orgs/$ORG "{\"logo_id\":$ID}")" = 400 ] && echo 1 || echo 0)
ok "titleholder with title, year, producer" $(yes1 $(req "$ADMIN" POST /orgs/$ORG '{"kind":"titleholder","title":"Mr. IR Leather","year":"2027","producer":"IR Contest"}' | jq '.kind=="titleholder" and .title=="Mr. IR Leather" and .year=="2027" and .producer=="IR Contest"'))
ok "script stripped from the description" $(yes1 $(req "$ADMIN" POST /orgs/$ORG '{"description":"Hi<script>alert(1)</script>"}' | jq '.description|test("script")|not'))
$W term delete cec_partner_org $ORG >/dev/null 2>&1

echo "== Publishing is still a person's job"
ok "admin publishes through wp/v2 as before" $([ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "http://127.0.0.1:8897/?rest_route=/wp/v2/cec_event/$ID" -H "Authorization: Basic $ADMIN" -H 'Content-Type: application/json' --data '{"status":"publish"}')" = 200 ] && [ "$(meta $ID _cec_start)" = 2026-11-21T19:30 ] && echo 1 || echo 0)
ok "start kept after publishing (no meta wiped)" $([ "$(meta $ID _cec_end)" = 2026-11-21T21:00 ] && echo 1 || echo 0)
ok "no PHP warnings" $([ ! -s wp-content/debug.log ] && echo 1 || (cat wp-content/debug.log; echo 0))

pkill -f "php -S 127.0.0.1:8897" 2>/dev/null
for p in $ID $PUB $DR; do $W post delete $p --force >/dev/null 2>&1; done
$W term delete cec_venue $V >/dev/null 2>&1
echo "== $PASS passed, $FAIL failed"
[ $FAIL = 0 ]
