#!/usr/bin/env bash
# End-to-end test of "pages with a form are never cached" (Community Events
# Calendar 1.46.1): every page with a nonce-protected public form sends
# no-store and drops the host CDN's ec-cdn-cache-control hint (a test
# must-use plugin adds that header the way Elementor Hosting does), other
# pages keep it, and a signed-out visitor with an expired nonce (a page
# opened or cached days ago) can still sign in, sign up, volunteer,
# subscribe, request an edit link and submit an event, while honeypots and
# signed-in nonces still hold.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/form-cache-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/fc; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
ev()   { $W eval "$1" 2>&1; }
q()    { ev "global \$wpdb; echo \$wpdb->get_var(\"$1\");"; }
mails(){ if [ -f wp-content/mail.log ]; then wc -l < wp-content/mail.log; else echo 0; fi; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
# A signed-out nonce for $1 as WordPress made it 2 days ago (expired: nonces last 12-24 hours).
old_nonce(){ ev "echo substr( wp_hash( ( wp_nonce_tick( '$1' ) - 4 ) . '|$1|0|', 'nonce' ), -12, 10 );"; }
post(){ curl -s -o /dev/null -w '%{redirect_url}' -b $T/${JAR:-anon}.jar -c $T/${JAR:-anon}.jar -H "Referer: $REF" "$H/wp-admin/admin-post.php" "$@"; }

rm -rf wp-content/plugins/community-events-calendar && cp -r $REPO/community-events-calendar wp-content/plugins/
$W plugin activate community-events-calendar >/dev/null 2>&1
rm -f wp-content/debug.log wp-content/mail.log
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5

# Plain permalinks (?page_id=) for the page checks; restored at the end.
START_PERMA=$($W option get permalink_structure 2>/dev/null)
$W option update permalink_structure '' >/dev/null 2>&1; $W rewrite flush >/dev/null 2>&1
OPT=$(ev 'echo CEC_Admin_Settings::OPTION;')
OLDOPT=$($W option get "$OPT" --format=json 2>/dev/null)
OLDREG=$($W option get users_can_register 2>/dev/null)
$W option update users_can_register 1 >/dev/null
ev "\$o = get_option( CEC_Admin_Settings::OPTION, array() ); \$o['allow_guest_submission'] = 1; \$o['require_login'] = 0; \$o['auto_publish'] = 0; update_option( CEC_Admin_Settings::OPTION, \$o );" >/dev/null

# One page per form, an Elementor-only page with the volunteer widget, and two pages without forms.
PAGES=""
mk(){ local id; id=$($W post create --post_type=page --post_status=publish --post_title="ZZ FC $1" --post_content="$2" --porcelain); PAGES="$PAGES $id"; echo $id; }
P_LOGIN=$(mk login '[cec_login]')
P_REG=$(mk register '[cec_register]')
P_SUBMIT=$(mk submit '[cec_submit_event]')
P_MANAGE=$(mk manage '[cec_manage_submission]')
P_SUB=$(mk subscribe '[cec_subscribe]')
P_VOL=$(mk volunteer '[cec_volunteer_form]')
P_ORG=$(mk org '[cec_submit_org]')
P_MEET=$(mk meeting-admin '[cec_meeting_admin]')
P_ELEM=$(mk elementor '')
$W post meta update $P_ELEM _elementor_data '[{"id":"a1","elType":"widget","widgetType":"cec_volunteer_form","settings":[]}]' >/dev/null
P_PLAIN=$(mk plain 'Just some text.')
P_FR=$(mk fundraisers '[cec_fundraisers]')
ev "\$o = get_option( CEC_Admin_Settings::OPTION, array() ); \$o['manage_submission_page_url'] = get_permalink( $P_MANAGE ); update_option( CEC_Admin_Settings::OPTION, \$o );" >/dev/null

echo "== Headers (with the host's CDN hint copied by a test must-use plugin)"
mkdir -p wp-content/mu-plugins && echo "<?php add_action('send_headers', function () { header('ec-cdn-cache-control: public, max-age=300, s-maxage=604800'); });" > wp-content/mu-plugins/zz-cdn-hint.php
nocache(){ local h; h=$(curl -s -D - -o /dev/null "$H/?page_id=$1&probe=$RANDOM"); echo "$h" | head -1 | grep -q ' 200' && echo "$h" | grep -qi '^cache-control:.*no-store' && ! echo "$h" | grep -qi '^ec-cdn-cache-control' && echo 1 || echo 0; }
hint(){ local h; h=$(curl -s -D - -o /dev/null "$H/?page_id=$1"); echo "$h" | head -1 | grep -q ' 200' && echo "$h" | grep -qi '^ec-cdn-cache-control' && echo 1 || echo 0; }
ok "sign-in page [cec_login]: no-store, no CDN hint" $(nocache $P_LOGIN)
ok "sign-up page [cec_register]: no-store, no CDN hint" $(nocache $P_REG)
ok "event submission [cec_submit_event]: no-store, no CDN hint" $(nocache $P_SUBMIT)
ok "guest edit [cec_manage_submission]: no-store, no CDN hint" $(nocache $P_MANAGE)
ok "subscribe [cec_subscribe]: no-store, no CDN hint" $(nocache $P_SUB)
ok "volunteer [cec_volunteer_form]: no-store, no CDN hint" $(nocache $P_VOL)
ok "organization submission [cec_submit_org]: no-store, no CDN hint" $(nocache $P_ORG)
ok "meeting notes portal [cec_meeting_admin]: no-store, no CDN hint" $(nocache $P_MEET)
ok "Elementor page with the volunteer widget: no-store, no CDN hint" $(nocache $P_ELEM)
ok "a page with no form keeps the CDN hint" $(hint $P_PLAIN)
ok "the public fundraising page keeps the CDN hint (no form)" $(hint $P_FR)
ok "the home page keeps the CDN hint" $(curl -s -D - -o /dev/null "$H/" | grep -qi '^ec-cdn-cache-control' && echo 1 || echo 0)
rm -f wp-content/mu-plugins/zz-cdn-hint.php

echo "== Signed out, an expired nonce is accepted where the form has other protection"
curl -s -c $T/anon.jar -o $T/reg.html "$H/?page_id=$P_REG"
ok "sign-up form now has a nonce field" $(has $T/reg.html 'name="cec_register_nonce"')
$W user delete fcold fcold2 --yes >/dev/null 2>&1
Y=$(( $(date +%Y) - 30 ))
REF="$H/?page_id=$P_REG"
r=$(post -d "action=cec_register&cec_register_nonce=$(old_nonce cec_register_action)&cec_redirect=$H/&cec_username=fcold&cec_email=fcold@example.com&cec_password=longpassword1&cec_password_confirm=longpassword1&cmp_dob[m]=1&cmp_dob[d]=2&cmp_dob[y]=$Y")
ok "sign-up with a 2-day-old nonce creates the account" $(! echo "$r" | grep -q cec_register_error && $W user get fcold --field=ID >/dev/null 2>&1 && echo 1 || echo 0)
r=$(post -d "action=cec_register&cec_register_nonce=$(old_nonce cec_register_action)&cec_redirect=$H/&cec_username=fcbot&cec_email=fcbot@example.com&cec_password=longpassword1&cec_password_confirm=longpassword1&cec_website=http://spam.example&cmp_dob[m]=1&cmp_dob[d]=2&cmp_dob[y]=$Y")
ok "...but the honeypot still stops a bot" $(! $W user get fcbot --field=ID >/dev/null 2>&1 && echo 1 || echo 0)
r=$(post -d "action=cec_register&cec_redirect=$H/&cec_username=fcnonce&cec_email=fcnonce@example.com&cec_password=longpassword1&cec_password_confirm=longpassword1&cmp_dob[m]=1&cmp_dob[d]=2&cmp_dob[y]=$Y")
ok "...and a post with no nonce at all is refused" $(echo "$r" | grep -q 'cec_register_error=failed' && ! $W user get fcnonce --field=ID >/dev/null 2>&1 && echo 1 || echo 0)

REF="$H/?page_id=$P_LOGIN"
rm -f $T/anon.jar
r=$(post -d "action=cec_login&cec_login_nonce=$(old_nonce cec_login_action)&cec_redirect=$H/&cec_identifier=fcold&cec_password=longpassword1")
ok "sign-in with a 2-day-old nonce signs in" $(! echo "$r" | grep -q 'cec_login=0' && grep -q 'wordpress_logged_in' $T/anon.jar && echo 1 || echo 0)
rm -f $T/anon.jar
r=$(post -d "action=cec_login&cec_login_nonce=$(old_nonce cec_login_action)&cec_redirect=$H/&cec_identifier=fcold&cec_password=wrong")
ok "...a wrong password is still refused" $(echo "$r" | grep -q 'cec_login=0' && ! grep -q 'wordpress_logged_in' $T/anon.jar && echo 1 || echo 0)
rm -f $T/anon.jar; curl -s -c $T/anon.jar -o $T/login.html "$H/?page_id=$P_LOGIN"
FRESH=$(grep -o 'name="cec_login_nonce" value="[^"]*"' $T/login.html | cut -d'"' -f4)
r=$(post -d "action=cec_login&cec_login_nonce=$FRESH&cec_redirect=$H/&cec_identifier=fcold&cec_password=longpassword1")
ok "...and a fresh nonce works as before" $(! echo "$r" | grep -q 'cec_login=0' && grep -q 'wordpress_logged_in' $T/anon.jar && echo 1 || echo 0)
rm -f $T/anon.jar

V_T=$(ev "global \$wpdb; echo \$wpdb->prefix . CEC_Volunteers::TABLE;")
V0=$(q "SELECT COUNT(*) FROM $V_T"); M0=$(mails)
REF="$H/?page_id=$P_VOL"
NONCE_ACT=$(ev 'echo CEC_Volunteers::NONCE_ACTION;')
r=$(post -d "action=cec_volunteer_submit&cec_volunteer_nonce=$(old_nonce $NONCE_ACT)&cec_redirect=$H/?page_id=$P_VOL&cec_v_name=FC+Volunteer&cec_v_email=fcvol@example.com")
ok "volunteer form with a 2-day-old nonce is received (stored, team emailed)" $(echo "$r" | grep -q 'cec_volunteer_submitted=1' && [ "$(q "SELECT COUNT(*) FROM $V_T WHERE email='fcvol@example.com'")" -ge 1 ] && [ "$(mails)" -gt "$M0" ] && echo 1 || echo 0)
V1=$(q "SELECT COUNT(*) FROM $V_T")
r=$(post -d "action=cec_volunteer_submit&cec_volunteer_nonce=$(old_nonce $NONCE_ACT)&cec_redirect=$H/?page_id=$P_VOL&cec_v_name=Bot&cec_v_email=bot@example.com&cec_website=http://spam.example")
ok "...the honeypot still refuses a bot" $(echo "$r" | grep -q 'cec_volunteer_submitted=0' && [ "$(q "SELECT COUNT(*) FROM $V_T")" = "$V1" ] && echo 1 || echo 0)

S_T=$(ev "global \$wpdb; echo \$wpdb->prefix . CEC_Subscribers::TABLE;")
REF="$H/?page_id=$P_SUB"
r=$(post -d "action=cec_subscribe&cec_subscribe_nonce=$(old_nonce cec_subscribe_action)&cec_redirect=$H/?page_id=$P_SUB&cec_email=fcsub@example.com&cec_sub_all=1")
ok "subscribe with a 2-day-old nonce: unconfirmed until the emailed link is clicked" $(echo "$r" | grep -q 'cec_subscribed=1' && [ "$(q "SELECT confirmed FROM $S_T WHERE email='fcsub@example.com'")" = 0 ] && grep -q 'fcsub@example.com' wp-content/mail.log && echo 1 || echo 0)

echo "== Guest event submission (now with a honeypot) and guest edit links"
curl -s -o $T/submit.html "$H/?page_id=$P_SUBMIT"
ok "the submission form has the honeypot field" $(has $T/submit.html 'name="cec_website"')
SNA=$(ev 'echo CEC_Submission_Form::NONCE_ACTION;')
REF="$H/?page_id=$P_SUBMIT"
EVF=(-d "action=cec_submit_event" -d "cec_redirect=$H/?page_id=$P_SUBMIT" -d "cec_submitter_email=fcguest@example.com" -d "cec_excerpt=Short" -d "cec_description=Longer description" -d "cec_start=$(date -d '+20 days' +%Y-%m-%dT19:00)" -d "cec_organizers=FC Org")
cnt(){ q "SELECT COUNT(*) FROM wp_posts WHERE post_type='cec_event' AND post_title='$1'"; }
r=$(post "${EVF[@]}" -d "cec_submit_nonce=$(old_nonce $SNA)" -d "cec_title=ZZ FC Guest Event")
ok "guest submission with a 2-day-old nonce goes to the approval queue" $(echo "$r" | grep -q 'cec_submitted=guest' && [ "$(q "SELECT post_status FROM wp_posts WHERE post_type='cec_event' AND post_title='ZZ FC Guest Event'")" = pending ] && echo 1 || echo 0)
r=$(post "${EVF[@]}" -d "cec_submit_nonce=$(old_nonce $SNA)" -d "cec_title=ZZ FC Bot Event" -d "cec_website=http://spam.example")
ok "...a filled honeypot looks like success to the bot but stores nothing" $(echo "$r" | grep -q 'cec_submitted=guest' && [ "$(cnt 'ZZ FC Bot Event')" = 0 ] && echo 1 || echo 0)
r=$(post "${EVF[@]}" -d "cec_title=ZZ FC No Nonce")
ok "...no nonce at all: refused" $(echo "$r" | grep -q 'cec_submitted=0' && [ "$(cnt 'ZZ FC No Nonce')" = 0 ] && echo 1 || echo 0)
ev "delete_transient( 'cec_edit_req_' . md5( 'fcguest@example.com' ) );" >/dev/null
M0=$(mails)
REF="$H/?page_id=$P_MANAGE"
r=$(post -d "action=cec_request_edit_link" -d "cec_request_nonce=$(old_nonce cec_request_edit_link)" --data-urlencode "cec_redirect=$H/?page_id=$P_MANAGE" -d "cec_email=fcguest@example.com")
ok "edit-link request with a 2-day-old nonce emails the link" $(echo "$r" | grep -q 'cec_msg=requested' && [ "$(mails)" -gt "$M0" ] && echo 1 || echo 0)
EID=$(q "SELECT ID FROM wp_posts WHERE post_type='cec_event' AND post_title='ZZ FC Guest Event'")
TOK=$(ev "echo CEC_Tokens::issue_for_event( $EID );" 2>/dev/null)
EDIT=$(ev "echo CEC_Tokens::build_edit_url( get_permalink( $P_MANAGE ), $EID, '$TOK' );")
echo "<?php add_action('send_headers', function () { header('ec-cdn-cache-control: public, max-age=300, s-maxage=604800'); });" > wp-content/mu-plugins/zz-cdn-hint.php
curl -s -D $T/edit.h -o $T/edit.html "$EDIT"
rm -f wp-content/mu-plugins/zz-cdn-hint.php
ok "a guest's edit link opens the edit form, not a 404 (1.46.1)" $(head -1 $T/edit.h | grep -q ' 200' && has $T/edit.html 'name="cec_guest_nonce"' && echo 1 || echo 0)
ok "...and is never cached (no-store, no CDN hint)" $(grep -qi '^cache-control:.*no-store' $T/edit.h && ! grep -qi '^ec-cdn-cache-control' $T/edit.h && echo 1 || echo 0)
OLD_PERMA=$($W option get permalink_structure 2>/dev/null)
$W rewrite structure '/%postname%/' >/dev/null 2>&1; $W rewrite flush >/dev/null 2>&1
EDIT2=$(ev "echo CEC_Tokens::build_edit_url( get_permalink( $P_MANAGE ), $EID, '$TOK' );")
curl -s -D $T/edit2.h -o $T/edit2.html "$EDIT2"
ok "...with pretty permalinks too, like colandb.com ($(echo "$EDIT2" | sed "s|$H||; s|cec_token=.*|cec_token=…|"))" $(head -1 $T/edit2.h | grep -q ' 200' && has $T/edit2.html 'name="cec_guest_nonce"' && echo 1 || echo 0)
ok "...while a real event page still opens at its own address" $(curl -s -o /dev/null -w '%{http_code}' "$(ev "echo get_permalink( $(q "SELECT ID FROM wp_posts WHERE post_type='cec_event' AND post_status='publish' LIMIT 1") );")" | grep -q 200 && echo 1 || echo 0)
$W option update permalink_structure "$OLD_PERMA" >/dev/null 2>&1; $W rewrite flush >/dev/null 2>&1
if [ -n "$TOK" ] && ! echo "$TOK" | grep -qi 'error'; then
	r=$(post -d "action=cec_guest_withdraw" -d "cec_event=$EID" -d "cec_token=$TOK" -d "cec_guest_nonce=$(old_nonce cec_guest_event_action)")
	ok "guest withdraw with a valid edit link and a 2-day-old nonce works" $(echo "$r" | grep -q 'cec_msg=withdrawn' && echo 1 || echo 0)
else
	ok "guest withdraw with a valid edit link and a 2-day-old nonce works (no token: $TOK)" 0
fi
r=$(post -d "action=cec_guest_withdraw" -d "cec_event=$EID" -d "cec_token=wrongtoken" -d "cec_guest_nonce=$(old_nonce cec_guest_event_action)")
ok "...a wrong edit link is still refused" $(! echo "$r" | grep -q 'cec_msg=withdrawn' && echo 1 || echo 0)

echo "== Signed in, a nonce must still be valid"
login fcold fcold longpassword1
JAR=fcold REF="$H/?page_id=$P_SUBMIT"
r=$(JAR=fcold post "${EVF[@]}" -d "cec_submit_nonce=$(old_nonce $SNA)" -d "cec_title=ZZ FC Member Old Nonce")
ok "a signed-in member's submission with an expired nonce is refused" $(echo "$r" | grep -q 'cec_submitted=0' && [ "$(cnt 'ZZ FC Member Old Nonce')" = 0 ] && echo 1 || echo 0)
curl -s -b $T/fcold.jar -o $T/msubmit.html "$H/?page_id=$P_SUBMIT"
MN=$(grep -o 'name="cec_submit_nonce" value="[^"]*"' $T/msubmit.html | cut -d'"' -f4)
r=$(JAR=fcold post "${EVF[@]}" -d "cec_submit_nonce=$MN" -d "cec_title=ZZ FC Member Event")
ok "...with their own fresh nonce it goes to the queue" $(echo "$r" | grep -q 'cec_submitted=1' && [ "$(cnt 'ZZ FC Member Event')" = 1 ] && echo 1 || echo 0)
unset JAR

echo "== Clean-up"
for id in $(q "SELECT GROUP_CONCAT(ID) FROM wp_posts WHERE post_type='cec_event' AND post_title LIKE 'ZZ FC %'" | tr ',' ' '); do $W post delete $id --force >/dev/null 2>&1; done
for id in $PAGES; do $W post delete $id --force >/dev/null 2>&1; done
ev "global \$wpdb; \$wpdb->query(\"DELETE FROM $V_T WHERE email IN ('fcvol@example.com','bot@example.com')\"); \$wpdb->query(\"DELETE FROM $S_T WHERE email='fcsub@example.com'\");" >/dev/null
$W user delete fcold --yes >/dev/null 2>&1
if [ -n "$OLDOPT" ]; then $W option update "$OPT" "$OLDOPT" --format=json >/dev/null; else $W option delete "$OPT" >/dev/null; fi
$W option update permalink_structure "$START_PERMA" >/dev/null 2>&1; $W rewrite flush >/dev/null 2>&1
if [ -n "$OLDREG" ]; then $W option update users_can_register "$OLDREG" >/dev/null; fi
ok "no PHP warnings or notices from the plugins" $(grep -E 'community-events-calendar|community-member-planning' wp-content/debug.log 2>/dev/null | grep -qv 'header.php\|footer.php' && echo 0 || echo 1)
grep -E 'community-events-calendar|community-member-planning' wp-content/debug.log 2>/dev/null | head -5
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
