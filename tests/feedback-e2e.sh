#!/usr/bin/env bash
# End-to-end test of "Need help?" (Community Member Planning 0.34.0): the link
# under the account box, the [cmp_feedback] form (signed out and signed in),
# spam limits, emails, Users → Help requests, replying, privacy and clean-up.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/feedback-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/fb; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
ev()   { $W eval "$1" 2>&1; }
q()    { ev "global \$wpdb; echo \$wpdb->get_var(\"$1\");"; }
mails(){ if [ -f wp-content/mail.log ]; then wc -l < wp-content/mail.log; else echo 0; fi; }

rm -rf wp-content/plugins/community-member-planning && cp -r $REPO/community-member-planning wp-content/plugins/
$W plugin activate community-member-planning >/dev/null 2>&1
ev 'CMP_Install::maybe_upgrade();' >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
F_T=$(ev "echo CMP_Install::table('feedback');")
ev "global \$wpdb; \$wpdb->query(\"DELETE FROM $F_T\"); delete_option('cmp_feedback_page_id');" >/dev/null
FID=$($W post create --post_type=page --post_status=publish --post_title="Need help" --post_name=need-help-test --post_content="[cmp_feedback]" --porcelain)
BID=$($W post create --post_type=page --post_status=publish --post_title="Bar test" --post_content="[cmp_account_bar]" --porcelain)
URL="$H/?page_id=$FID"
rm -f wp-content/mail.log
$W user delete fbmember --yes >/dev/null 2>&1
$W user create fbmember fbmember@example.com --role=subscriber --user_pass=fbpass1234567 --display_name="Feedback Member" >/dev/null

echo "== The link under the account box"
curl -s -o $T/bar.html "$H/?page_id=$BID&cmp_token=SECRET&x=1"
ok "signed out: Need help? under Log In, back-link to this page without tokens" $([ "$(has $T/bar.html 'class="cmp-account-wrap"')$(has $T/bar.html 'class="cmp-bar-help"')$(has $T/bar.html "from=%2F%3Fpage_id%3D$BID")$(hasnt $T/bar.html 'SECRET')" = 1111 ] && echo 1 || echo 0)
login fbmember fbmember fbpass1234567
curl -s -b $T/fbmember.jar -o $T/bar2.html "$H/?page_id=$BID"
ok "signed in: Need help? under Hello, <name>" $([ "$(has $T/bar2.html 'Hello, Feedback Member')$(has $T/bar2.html 'Need help?')" = 11 ] && echo 1 || echo 0)
ok "the form page links to itself without a from=" $(curl -s "$URL" | grep -o 'class="cmp-bar-help"[^>]*' | grep -q 'from=' && echo 0 || echo 1)

echo "== Form (signed out)"
fresh(){ curl -s -b "${1:-/dev/null}" -D $T/form.h -o $T/form.html "$URL&from=%2Fevents%2F"; grep -o 'name="t" value="[^"]*"' $T/form.html | cut -d'"' -f4; }
post(){ local jar=$1 t=$2; shift 2; curl -s -b "$jar" -A "TestBrowser/1.0" -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" -d "action=cmp_feedback" --data-urlencode "back=$URL" -d "t=$t" "$@"; }
TS=$(fresh)
ok "form: what it's about, message, page prefilled from the link, email for visitors" $([ "$(has $T/form.html 'name="kind" value="problem"')$(has $T/form.html 'name="message"')$(has $T/form.html 'value="/events/"')$(has $T/form.html 'name="email"')" = 1111 ] && echo 1 || echo 0)
ok "the form page is never cached" $(grep -qi '^cache-control: no-store' $T/form.h && echo 1 || echo 0)
r=$(post /dev/null "$TS" -d kind=problem -d "message=The calendar is blank" -d email=visitor@example.com)
ok "sent too fast: refused" $(echo "$r" | grep -q 'cmp_fb=invalid' && [ "$(q "SELECT COUNT(*) FROM $F_T")" = 0 ] && echo 1 || echo 0)
sleep 3
r=$(post /dev/null "$TS" -d kind=problem -d "message=The calendar is blank" -d email=visitor@example.com -d website=spam)
ok "honeypot: refused" $(echo "$r" | grep -q 'cmp_fb=invalid' && [ "$(q "SELECT COUNT(*) FROM $F_T")" = 0 ] && echo 1 || echo 0)
r=$(post /dev/null "$TS" -d kind=problem -d "message=The calendar is blank" -d email=not-an-email)
ok "no valid email: refused" $(echo "$r" | grep -q 'cmp_fb=invalid' && echo 1 || echo 0)
M0=$(mails)
r=$(post /dev/null "$TS" -d kind=problem -d "message=The calendar is blank on my phone" -d "page=/events/" -d email=visitor@example.com -d "name=Vee")
FB1=$(q "SELECT MAX(id) FROM $F_T")
ok "sent: stored with kind, page, email, name and browser (problems only)" $(echo "$r" | grep -q 'cmp_fb=sent' && [ "$(q "SELECT CONCAT(kind,'|',page,'|',email,'|',name,'|',user_agent,'|',user_id,'|',status) FROM $F_T WHERE id=$FB1")" = "problem|/events/|visitor@example.com|Vee|TestBrowser/1.0|0|new" ] && echo 1 || echo 0)
ok "...the team is emailed (Reply-To: the sender) and the sender gets a copy" $([ $(( $(mails) - M0 )) = 2 ] && grep -q "Help request $FB1" wp-content/mail.log && grep -q 'Reply-To: visitor@example.com' wp-content/mail.log && grep -q 'We got your message' wp-content/mail.log && echo 1 || echo 0)
curl -s -o $T/sent.html "$URL&cmp_fb=sent"
ok "thank-you message" $(has $T/sent.html 'Your message was sent')

echo "== Form (signed in)"
TS=$(fresh $T/fbmember.jar)
ok "a member isn't asked for an email; the account email is shown" $([ "$(hasnt $T/form.html 'name="email"')$(has $T/form.html 'fbmember@example.com')" = 11 ] && echo 1 || echo 0)
sleep 3
r=$(post $T/fbmember.jar "$TS" -d kind=feedback -d "message=Love the new photo privacy" -d email=spoof@example.com)
FB2=$(q "SELECT MAX(id) FROM $F_T")
ok "a member's message uses their account email (not a posted one), no browser for feedback" $(echo "$r" | grep -q 'cmp_fb=sent' && [ "$(ev "global \$wpdb; \$r=\$wpdb->get_row('SELECT * FROM $F_T WHERE id=$FB2'); echo \$r->email,'|',\$r->name,'|',\$r->user_agent,'|',(int)(\$r->user_id>0);")" = "fbmember@example.com|Feedback Member||1" ] && echo 1 || echo 0)
ev "global \$wpdb; for(\$i=0;\$i<4;\$i++) \$wpdb->insert('$F_T',array('created_at'=>gmdate('Y-m-d H:i:s'),'email'=>'visitor@example.com','kind'=>'question','message'=>'x'));" >/dev/null
TS=$(fresh); sleep 3
r=$(post /dev/null "$TS" -d kind=question -d "message=Another one" -d email=visitor@example.com)
ok "6th message from the same email in a day: limit" $(echo "$r" | grep -q 'cmp_fb=limit' && echo 1 || echo 0)

echo "== Admin"
login admin admin admin
curl -s -b $T/admin.jar -o $T/adm.html "$H/wp-admin/users.php?page=cmp-help-requests"
ok "Users → Help requests lists them with page, sender and browser" $([ "$(has $T/adm.html 'The calendar is blank on my phone')$(has $T/adm.html 'visitor@example.com')$(has $T/adm.html 'TestBrowser/1.0')$(has $T/adm.html 'Mark done')" = 1111 ] && echo 1 || echo 0)
AN=$(grep -o 'name="_wpnonce" value="[^"]*"' $T/adm.html | head -1 | cut -d'"' -f4)
curl -s -b $T/fbmember.jar -o /dev/null "$H/wp-admin/admin-post.php" -d "action=cmp_feedback_admin&_wpnonce=$AN&id=$FB1"
ok "a non-admin can't mark one done" $([ "$(q "SELECT status FROM $F_T WHERE id=$FB1")" = new ] && echo 1 || echo 0)
M0=$(mails)
curl -s -b $T/admin.jar -o /dev/null "$H/wp-admin/admin-post.php" -d "action=cmp_feedback_admin&_wpnonce=$AN&_wp_http_referer=/wp-admin/users.php&id=$FB1" --data-urlencode "reply=Fixed, please reload the page."
ok "marked done with a reply; the reply is emailed to the sender" $([ "$(q "SELECT CONCAT(status,'|',note) FROM $F_T WHERE id=$FB1")" = "done|Fixed, please reload the page." ] && [ $(( $(mails) - M0 )) = 1 ] && tail -1 wp-content/mail.log | grep -q 'visitor@example.com' && tail -1 wp-content/mail.log | grep -q 'Fixed, please reload' && echo 1 || echo 0)

echo "== Privacy and clean-up"
ok "export by email lists their messages" $(ev "\$j=wp_json_encode(CMP_Account::export('visitor@example.com'),JSON_UNESCAPED_SLASHES); echo false!==strpos(\$j,'Help request you sent') && false!==strpos(\$j,'calendar is blank')?1:0;")
ok "erase by email removes them (no account needed)" $(ev "CMP_Account::erase('visitor@example.com'); global \$wpdb; echo 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $F_T WHERE email='visitor@example.com'\")?1:0;")
ev "global \$wpdb; \$wpdb->update('$F_T',array('created_at'=>gmdate('Y-m-d H:i:s',time()-731*DAY_IN_SECONDS)),array('id'=>$FB2));" >/dev/null
ev 'CMP_Retention::run();' >/dev/null
ok "messages older than 2 years are cleared" $([ "$(q "SELECT COUNT(*) FROM $F_T WHERE id=$FB2")" = 0 ] && echo 1 || echo 0)

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev "global \$wpdb; \$wpdb->query(\"DELETE FROM $F_T\"); delete_option('cmp_feedback_page_id');" >/dev/null
$W post delete $FID $BID --force >/dev/null 2>&1; $W user delete fbmember --yes >/dev/null 2>&1
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
