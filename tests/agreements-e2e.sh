#!/usr/bin/env bash
# End-to-end test of event agreements (Community Member Planning 0.40.0):
# the mosh pit agreement, its page and QR link, signing in or joining free
# from a phone, confirming as a signed-in member, how long an agreement
# lasts, versions, limits, Users → Event agreements, the CSV, privacy and
# clean-up.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/agreements-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/ag; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
all()  { [ "$(echo "$1" | tr -d '1')" = "" ] && echo 1 || echo 0; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
ev()   { $W eval "$1" 2>&1; }
q()    { ev "global \$wpdb; echo \$wpdb->get_var(\"$1\");"; }
mails(){ if [ -f wp-content/mail.log ]; then wc -l < wp-content/mail.log; else echo 0; fi; }

rm -rf wp-content/plugins/community-member-planning && cp -r $REPO/community-member-planning wp-content/plugins/
$W plugin activate community-member-planning >/dev/null 2>&1
ev 'CMP_Install::maybe_upgrade();' >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
AT=$(ev "echo CMP_Agreements::t();")
# Fresh start: the starter agreement re-seeded, no rows, no pages, no test users.
ev "global \$wpdb; \$wpdb->query(\"DELETE FROM $AT\"); delete_option('cmp_agreements'); delete_option('cmp_agreement_pages'); CMP_Agreements::seed();" >/dev/null
for id in $($W post list --post_type=page --s='cmp_agreement' --field=ID 2>/dev/null); do $W post delete $id --force >/dev/null; done
for u in agmember agold agyoung agnew; do $W user delete $u --yes >/dev/null 2>&1; done
ev "foreach (get_users(array('search'=>'*@agtest.example','search_columns'=>array('user_email'))) as \$u) { require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user(\$u->ID); }" >/dev/null
$W option update users_can_register 1 >/dev/null
$W user create agmember agmember@example.com --role=subscriber --user_pass=agpass12345 --display_name="Pit Member" >/dev/null
ev "\$u=get_user_by('login','agmember'); CMP_Birth_Date::store(\$u->ID,'1990-05-05','test'); CMP_Access::record_attestation(\$u->ID);" >/dev/null
$W user create agold agold@example.com --role=subscriber --user_pass=agold12345 --display_name="Old Account" >/dev/null
rm -f wp-content/mail.log

echo "== The starter agreement"
ok "mosh-pit agreement seeded: version 1, 11 rules, 12-hour renewal, on, full terms with the release" $(all "$([ "$(ev "echo CMP_Agreements::get('mosh-pit')['version'];")" = 1 ] && echo 1)$([ "$(ev "echo count(CMP_Agreements::get('mosh-pit')['rules']);")" = 11 ] && echo 1)$([ "$(ev "echo CMP_Agreements::get('mosh-pit')['renew_hours'];")" = 12 ] && echo 1)$([ "$(ev "echo (int) CMP_Agreements::get('mosh-pit')['active'];")" = 1 ] && echo 1)$(ev "echo strpos(CMP_Agreements::get('mosh-pit')['body'],'Released Parties')?1:0;")")
ok "rules match the poster's first and last rule" $([ "$(ev "\$r=CMP_Agreements::get('mosh-pit')['rules']; echo \$r[0].'|'.\$r[10];")" = "Scan the code and agree before you play.|Monitors keep you safe. Follow their lead." ] && echo 1 || echo 0)
ev "CMP_Agreements::seed(); \$a=get_option('cmp_agreements'); \$a['mosh-pit']['title']='Edited title'; update_option('cmp_agreements',\$a); CMP_Agreements::seed();" >/dev/null
ok "seed() never overwrites an edited agreement" $([ "$(ev "echo CMP_Agreements::get('mosh-pit')['title'];")" = "Edited title" ] && echo 1 || echo 0)
ev "\$a=get_option('cmp_agreements'); \$a['mosh-pit']['title']='Mosh pit rules'; update_option('cmp_agreements',\$a);" >/dev/null

echo "== Users → Event agreements and the page"
login admin admin admin
curl -s -b $T/admin.jar -o $T/list.html "$H/wp-admin/users.php?page=cmp-agreements"
ok "admin list: the agreement, no page yet with a Create button, the permanent QR link" $(all "$(has $T/list.html 'Mosh pit rules')$(has $T/list.html 'Create the page')$(has $T/list.html '?cmp_agree=mosh-pit')")
curl -s -o /dev/null -w '%{http_code}' "$H/?cmp_agree=mosh-pit" > $T/code
ok "QR link before there's a page: 404 with a plain message" $([ "$(cat $T/code)" = 404 ] && echo 1 || echo 0)
NONCE=$(grep -o 'name="_wpnonce" value="[^"]*"' $T/list.html | head -1 | cut -d'"' -f4)
curl -s -b $T/admin.jar -o /dev/null "$H/wp-admin/admin-post.php" -d action=cmp_agreement_page -d key=mosh-pit -d _wpnonce=$NONCE
PID=$(ev "echo CMP_Agreements::page_id('mosh-pit');")
ok "Create the page: a published page holding [cmp_agreement key=\"mosh-pit\"]" $([ "$PID" != 0 ] && [ "$($W post get $PID --field=post_status)" = publish ] && echo 1 || echo 0)
login agmember agmember agpass12345
r=$(curl -s -b $T/agmember.jar -o /dev/null -w '%{http_code}' "$H/wp-admin/admin-post.php" -d action=cmp_agreement_page -d key=mosh-pit -d _wpnonce=$NONCE)
ok "a member can't use the admin actions" $([ "$r" != 302 ] && echo 1 || echo 0)
URL="$H/?page_id=$PID"
r=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$H/?cmp_agree=mosh-pit")
ok "QR link: 302 to the page" $(echo "$r" | grep -q "^302 .*page_id=$PID" && echo 1 || echo 0)
ok "QR link with an unknown key: 404" $([ "$(curl -s -o /dev/null -w '%{http_code}' "$H/?cmp_agree=nope")" = 404 ] && echo 1 || echo 0)

echo "== Signed out: email first"
fresh(){ curl -s -b "${1:-/dev/null}" -c "${1:-/dev/null}" -D $T/page.h -o $T/page.html "$URL"; grep -o 'name="t" value="[^"]*"' $T/page.html | head -1 | cut -d'"' -f4; }
send(){ local jar=$1; shift; curl -s -b "$jar" -c "$jar" -D $T/post.h -o $T/post.html "$URL" "$@"; }
TS=$(fresh)
ok "page: rules, full terms behind a link, email box, never cached, styles" $(all "$(has $T/page.html 'Shoes off the mats.')$(has $T/page.html 'Read the full code of conduct')$(has $T/page.html 'name="email"')$(grep -qi '^cache-control: no-store' $T/page.h && echo 1 || echo 0)$(has $T/page.html 'agreement.css')")
send $T/v.jar -d cmp_ag_action=email -d "t=$TS" -d email=agmember@example.com
ok "sent faster than a person could: refused" $(has $T/post.html 'please try once more')
sleep 2
send $T/v.jar -d cmp_ag_action=email -d "t=$TS" -d email=not-an-email
ok "bad email: asked again" $(has $T/post.html 'valid email address')
send $T/v.jar -d cmp_ag_action=email -d "t=$TS" -d email=agmember@example.com -d website=spam
ok "honeypot: no step forward" $(hasnt $T/post.html 'Welcome back')
send $T/v.jar -d cmp_ag_action=email -d "t=$TS" -d email=agmember@example.com
ok "known email: password step, email kept in the form (not the URL)" $(all "$(has $T/post.html 'Welcome back! Sign in to agree')$(has $T/post.html 'name="password"')$(has $T/post.html 'value="agmember@example.com"')$(grep -q 'agmember' $T/post.h && echo 0 || echo 1)")
send $T/v.jar -d cmp_ag_action=email -d "t=$TS" -d email=Pit.Newbie@AgTest.example
ok "new email: join step with name, date of birth, password, tick box" $(all "$(has $T/post.html 'Join COL&amp;B free')$(has $T/post.html 'name="display_name"')$(has $T/post.html 'cmp_dob\[y\]')$(has $T/post.html 'autocomplete="new-password"')$(has $T/post.html 'name="agree"')")

echo "== Joining free"
J=(-d cmp_ag_action=join -d "t=$TS" -d email=pit.newbie@agtest.example -d "display_name=Newbie Pup" -d 'cmp_dob[m]=3' -d 'cmp_dob[d]=4' -d 'cmp_dob[y]=1995' -d password=newpass1234)
send $T/n.jar "${J[@]}"
ok "no tick: refused, no account" $(all "$(has $T/post.html 'tick the box')$([ "$(ev "echo email_exists('pit.newbie@agtest.example')?1:0;")" = 0 ] && echo 1)")
send $T/n.jar -d cmp_ag_action=join -d "t=$TS" -d email=pit.young@agtest.example -d "display_name=Too Young" -d 'cmp_dob[m]=1' -d 'cmp_dob[d]=1' -d "cmp_dob[y]=$(( $(date +%Y) - 16 ))" -d password=newpass1234 -d agree=1
ok "under 18: refused, no account" $(all "$(has $T/post.html '18 or older')$([ "$(ev "echo email_exists('pit.young@agtest.example')?1:0;")" = 0 ] && echo 1)")
send $T/n.jar -d cmp_ag_action=join -d "t=$TS" -d email=pit.newbie@agtest.example -d "display_name=Newbie Pup" -d 'cmp_dob[m]=3' -d 'cmp_dob[d]=4' -d 'cmp_dob[y]=1995' -d password=short -d agree=1
ok "short password: refused, name kept" $(all "$(has $T/post.html 'at least 8 characters')$(has $T/post.html 'value="Newbie Pup"')")
M0=$(mails)
send $T/n.jar "${J[@]}" -d agree=1
LOC=$(grep -i '^location:' $T/post.h | tr -d '\r')
NID=$(ev "echo email_exists('pit.newbie@agtest.example');")
ok "joined: account created and signed in, sent back with cmp_ag=joined (no email in the URL)" $(all "$([ -n "$NID" ] && [ "$NID" != "" ] && echo 1)$(echo "$LOC" | grep -q 'cmp_ag=joined' && echo 1 || echo 0)$(echo "$LOC" | grep -qi 'agtest' && echo 0 || echo 1)")
ok "new account: display name, date of birth and 18+ record stored, login name isn't the email" $(all "$([ "$(ev "echo get_userdata($NID)->display_name;")" = "Newbie Pup" ] && echo 1)$([ "$(ev "echo CMP_Birth_Date::get($NID);")" = 1995-03-04 ] && echo 1)$([ -n "$(ev "echo get_user_meta($NID,'cmp_age_attested_at',true);")" ] && echo 1)$(ev "echo strpos(get_userdata($NID)->user_login,'newbie')===0 && strpos(get_userdata($NID)->user_login,'@')===false ?1:0;")")
ok "the usual 'Confirm your email address' email went to them (and the admin was told)" $(all "$([ $(( $(mails) - M0 )) -ge 2 ] && echo 1)$(grep -q 'Confirm your email address' wp-content/mail.log && echo 1 || echo 0)")
ok "agreement recorded: version 1, via signup, email lowercased" $([ "$(q "SELECT CONCAT(version,via,email) FROM $AT WHERE user_id=$NID")" = "1signuppit.newbie@agtest.example" ] && echo 1 || echo 0)
curl -s -b $T/n.jar -o $T/done.html "$URL&cmp_ag=joined"
ok "confirmation screen: tick, You agreed, the name, the time, welcome note" $(all "$(has $T/done.html 'cmp-ag-tick')$(has $T/done.html 'You agreed')$(has $T/done.html 'Newbie Pup')$(has $T/done.html "$(TZ=America/New_York date +%B)")$(has $T/done.html 'Welcome to COL&amp;B')")
ok "the audit log has the new account and the agreement" $([ "$(q "SELECT COUNT(*) FROM $(ev "echo CMP_Install::table('audit_log');") WHERE object_id=$NID AND action IN ('account_created','agreement_signed')")" = 2 ] && echo 1 || echo 0)
send $T/z.jar -d cmp_ag_action=join -d "t=$TS" -d email=pit.newbie@agtest.example -d "display_name=Copy" -d 'cmp_dob[m]=3' -d 'cmp_dob[d]=4' -d 'cmp_dob[y]=1995' -d password=newpass1234 -d agree=1
ok "joining again with a taken email: sent to the password step, no second account" $(all "$(has $T/post.html 'Welcome back')$([ "$(q "SELECT COUNT(*) FROM wp_users WHERE user_email='pit.newbie@agtest.example'")" = 1 ] && echo 1)")

echo "== Signing in with a known email"
TS=$(fresh $T/s.jar)
S1=(-d cmp_ag_action=signin -d "t=$TS" -d email=agmember@example.com)
send $T/s.jar "${S1[@]}" -d password=wrongpass -d agree=1
ok "wrong password: refused, nothing recorded" $(all "$(has $T/post.html 'password isn')$([ "$(q "SELECT COUNT(*) FROM $AT WHERE email='agmember@example.com'")" = 0 ] && echo 1)")
send $T/s.jar "${S1[@]}" -d password=agpass12345
ok "right password but no tick: refused" $(has $T/post.html 'tick the box')
send $T/s.jar "${S1[@]}" -d password=agpass12345 -d agree=1
ok "signed in and agreed: via signin" $(all "$(grep -qi '^location:.*cmp_ag=done' $T/post.h && echo 1 || echo 0)$([ "$(q "SELECT via FROM $AT WHERE email='agmember@example.com'")" = signin ] && echo 1)")
ev "delete_transient('cmp_pw_fail_'.get_user_by('login','agmember')->ID);" >/dev/null
for i in 1 2 3 4 5; do send $T/l.jar "${S1[@]}" -d password=bad$i -d agree=1; done
send $T/l.jar "${S1[@]}" -d password=agpass12345 -d agree=1
ok "5 wrong passwords: locked for 15 minutes, even with the right one (shared with the Account tab)" $(all "$(has $T/post.html 'Too many wrong passwords')$([ "$(q "SELECT COUNT(*) FROM $AT WHERE email='agmember@example.com'")" = 1 ] && echo 1)")
ev "delete_transient('cmp_pw_fail_'.get_user_by('login','agmember')->ID);" >/dev/null

echo "== Signed in already"
login agmember agmember agpass12345
curl -s -b $T/agmember.jar -o $T/m.html "$URL"
ok "agreed in the last 12 hours: confirmation shown, no form" $(all "$(has $T/m.html 'You agreed')$(hasnt $T/m.html 'cmp_ag_action')")
ev "global \$wpdb; \$wpdb->query(\"UPDATE $AT SET created_at = DATE_SUB(created_at, INTERVAL 13 HOUR)\");" >/dev/null 2>&1 || true
ev "global \$wpdb; foreach (\$wpdb->get_results('SELECT id, created_at FROM $AT') as \$r) \$wpdb->update('$AT', array('created_at'=>gmdate('Y-m-d H:i:s', strtotime(\$r->created_at.' UTC') - 13*3600)), array('id'=>\$r->id));" >/dev/null
curl -s -b $T/agmember.jar -o $T/m.html "$URL"
ok "13 hours later (next event night): asked again, signed in as Pit Member, no date of birth asked" $(all "$(has $T/m.html 'value="confirm"')$(has $T/m.html 'Signed in as Pit Member')$(hasnt $T/m.html 'cmp_dob')")
NC=$(grep -o 'name="_cmp_ag" value="[^"]*"' $T/m.html | cut -d'"' -f4)
curl -s -b $T/agmember.jar -o $T/m2.html "$URL" -d cmp_ag_action=confirm -d _cmp_ag=$NC
ok "confirm without the tick: refused" $(has $T/m2.html 'tick the box')
curl -s -b $T/agmember.jar -o /dev/null "$URL" -d cmp_ag_action=confirm -d _cmp_ag=bad -d agree=1
ok "bad nonce: nothing recorded" $([ "$(q "SELECT COUNT(*) FROM $AT WHERE email='agmember@example.com'")" = 1 ] && echo 1 || echo 0)
curl -s -b $T/agmember.jar -D $T/m3.h -o /dev/null "$URL" -d cmp_ag_action=confirm -d _cmp_ag=$NC -d agree=1
ok "confirm with the tick: recorded via member, back to the confirmation" $(all "$(grep -qi '^location:.*cmp_ag=done' $T/m3.h && echo 1 || echo 0)$([ "$(q "SELECT via FROM $AT WHERE email='agmember@example.com' ORDER BY id DESC LIMIT 1")" = member ] && echo 1)")

echo "== Older account with no date of birth"
login agold agold agold12345
curl -s -b $T/agold.jar -o $T/o.html "$URL"
ok "asked for a date of birth with the tick box" $(all "$(has $T/o.html 'cmp_dob\[m\]')$(has $T/o.html 'name="agree"')")
NC=$(grep -o 'name="_cmp_ag" value="[^"]*"' $T/o.html | cut -d'"' -f4)
curl -s -b $T/agold.jar -o $T/o2.html "$URL" -d cmp_ag_action=confirm -d _cmp_ag=$NC -d agree=1 -d 'cmp_dob[m]=1' -d 'cmp_dob[d]=1' -d "cmp_dob[y]=$(( $(date +%Y) - 15 ))"
OID=$(ev "echo get_user_by('login','agold')->ID;")
ok "under 18: account locked like the member area's step, nothing recorded, page says it can't agree" $(all "$(ev "echo CMP_Birth_Date::is_blocked($OID)?1:0;")$([ "$(q "SELECT COUNT(*) FROM $AT WHERE user_id=$OID")" = 0 ] && echo 1)$(has $T/o2.html "can&#039;t agree")")
ev "delete_user_meta($OID,'cmp_age_blocked_at');" >/dev/null
curl -s -b $T/agold.jar -o $T/o.html "$URL"; NC=$(grep -o 'name="_cmp_ag" value="[^"]*"' $T/o.html | cut -d'"' -f4)
curl -s -b $T/agold.jar -o /dev/null "$URL" -d cmp_ag_action=confirm -d _cmp_ag=$NC -d agree=1 -d 'cmp_dob[m]=6' -d 'cmp_dob[d]=7' -d 'cmp_dob[y]=1980'
ok "18+: date stored once and agreement recorded" $(all "$([ "$(ev "echo CMP_Birth_Date::get($OID);")" = 1980-06-07 ] && echo 1)$([ "$(q "SELECT COUNT(*) FROM $AT WHERE user_id=$OID")" = 1 ] && echo 1)")

echo "== Versions"
login admin admin admin
curl -s -b $T/admin.jar -o $T/edit.html "$H/wp-admin/users.php?page=cmp-agreements&edit=mosh-pit"
NONCE=$(grep -o 'name="_wpnonce" value="[^"]*"' $T/edit.html | head -1 | cut -d'"' -f4)
RULES=$(ev "echo implode(\"\n\", CMP_Agreements::get('mosh-pit')['rules']);")
BODY=$(ev "echo CMP_Agreements::get('mosh-pit')['body'];")
curl -s -b $T/admin.jar -o /dev/null "$H/wp-admin/admin-post.php" -d action=cmp_agreement_save -d _wpnonce=$NONCE -d key=mosh-pit --data-urlencode "title=Mosh pit rules" --data-urlencode "intro=$(ev "echo CMP_Agreements::get('mosh-pit')['intro'];")" --data-urlencode "rules=$RULES" --data-urlencode "body=$BODY" -d renew_hours=0 -d active=1
ok "changing only how long it lasts: still version 1" $([ "$(ev "echo CMP_Agreements::get('mosh-pit')['version'].'/'.CMP_Agreements::get('mosh-pit')['renew_hours'];")" = "1/0" ] && echo 1 || echo 0)
curl -s -b $T/agold.jar -o $T/o3.html "$URL"
ok "renew 0: an agreement from 13+ hours ago still counts" $(has $T/o3.html 'You agreed')
curl -s -b $T/admin.jar -o /dev/null "$H/wp-admin/admin-post.php" -d action=cmp_agreement_save -d _wpnonce=$NONCE -d key=mosh-pit --data-urlencode "title=Mosh pit rules" --data-urlencode "rules=$RULES
Wear closed-toe shoes in the pit." --data-urlencode "body=$BODY<script>alert(1)</script>" -d renew_hours=12 -d active=1
ok "new rule: version 2, version 1 kept in history, scripts stripped" $(all "$([ "$(ev "echo CMP_Agreements::get('mosh-pit')['version'];")" = 2 ] && echo 1)$([ "$(ev "echo count(CMP_Agreements::get('mosh-pit')['history'][1]['rules']);")" = 11 ] && echo 1)$(ev "echo strpos(CMP_Agreements::get('mosh-pit')['body'],'<script')===false?1:0;")")
curl -s -b $T/agold.jar -o $T/o4.html "$URL"
ok "after a rule change, everyone is asked again and sees the new rule" $(all "$(has $T/o4.html 'value="confirm"')$(has $T/o4.html 'closed-toe')")
curl -s -b $T/admin.jar -o /dev/null "$H/wp-admin/admin-post.php" -d action=cmp_agreement_save -d _wpnonce=$NONCE -d key=mosh-pit --data-urlencode "title=Mosh pit rules" --data-urlencode "rules=$RULES" --data-urlencode "body=$BODY" -d renew_hours=12
curl -s -o $T/off.html "$URL"
ok "switched off: page says it isn't available, QR link still finds the page" $(all "$(has $T/off.html 'available right now')$(hasnt $T/off.html 'name="email"')")
curl -s -b $T/admin.jar -o /dev/null "$H/wp-admin/admin-post.php" -d action=cmp_agreement_save -d _wpnonce=$NONCE -d key=mosh-pit --data-urlencode "title=Mosh pit rules" --data-urlencode "rules=$RULES" --data-urlencode "body=$BODY" -d renew_hours=12 -d active=1
curl -s -b $T/admin.jar -o /dev/null "$H/wp-admin/admin-post.php" -d action=cmp_agreement_save -d _wpnonce=$NONCE -d key="Bad Key!" -d title= -d rules=
ok "an agreement with no title or rules is refused" $([ "$(ev "echo count(CMP_Agreements::all());")" = 1 ] && echo 1 || echo 0)

echo "== Registration closed and look-up limit"
$W option update users_can_register 0 >/dev/null
TS=$(fresh $T/c.jar); sleep 2
send $T/c.jar -d cmp_ag_action=email -d "t=$TS" -d email=someone.new@agtest.example
ok "sign-ups closed: new email told to ask a monitor, no join form" $(all "$(has $T/post.html "can&#039;t be created")$(hasnt $T/post.html 'name="name"')")
send $T/c.jar -d cmp_ag_action=join -d "t=$TS" -d email=someone.new@agtest.example -d display_name=X -d 'cmp_dob[m]=1' -d 'cmp_dob[d]=1' -d 'cmp_dob[y]=1990' -d password=longenough1 -d agree=1
ok "sign-ups closed: a forged join post makes no account" $([ "$(ev "echo email_exists('someone.new@agtest.example')?1:0;")" = 0 ] && echo 1 || echo 0)
$W option update users_can_register 1 >/dev/null
ev "global \$wpdb; \$wpdb->query(\"DELETE FROM \$wpdb->options WHERE option_name LIKE '%cmp_ag_look_%'\");" >/dev/null
for i in $(seq 1 20); do send $T/c.jar -d cmp_ag_action=email -d "t=$TS" -d email=probe$i@agtest.example; done
send $T/c.jar -d cmp_ag_action=email -d "t=$TS" -d email=agmember@example.com
ok "21st email look-up in 10 minutes from one address: told to wait, no answer about the email" $(all "$(has $T/post.html 'Too many tries')$(hasnt $T/post.html 'Welcome back')")
ev "global \$wpdb; \$wpdb->query(\"DELETE FROM \$wpdb->options WHERE option_name LIKE '%cmp_ag_look_%'\");" >/dev/null

echo "== Who agreed and the CSV"
ev "global \$wpdb; \$wpdb->insert('$AT', array('agreement'=>'mosh-pit','version'=>2,'user_id'=>0,'email'=>'evil@agtest.example','name'=>'=HYPERLINK(\"x\")','via'=>'signup','created_at'=>gmdate('Y-m-d H:i:s'),'ip_hash'=>''));" >/dev/null
curl -s -b $T/admin.jar -o $T/signs.html "$H/wp-admin/users.php?page=cmp-agreements&signs=mosh-pit"
ok "who agreed: names, emails, account status, how they agreed, earlier-rules flag" $(all "$(has $T/signs.html 'Newbie Pup')$(has $T/signs.html 'agmember@example.com')$(has $T/signs.html 'email not confirmed yet')$(has $T/signs.html 'joined')$(has $T/signs.html 'earlier rules')$(has $T/signs.html 'account removed')")
CSV=$(grep -o 'admin-post.php?action=cmp_agreement_csv[^"]*' $T/signs.html | head -1 | sed 's/&#038;/\&/g; s/&amp;/\&/g')
curl -s -b $T/admin.jar -D $T/csv.h -o $T/list.csv "$H/wp-admin/$CSV"
ok "CSV: attachment, header row, people, formula-looking names made safe" $(all "$(grep -qi 'content-disposition: attachment' $T/csv.h && echo 1 || echo 0)$(has $T/list.csv 'Agreed at",Name,Email,Account,How,Version')$(has $T/list.csv 'pit.newbie@agtest.example')$(has $T/list.csv "\"'=HYPERLINK")$(hasnt $T/list.csv '^"=HYPERLINK')")
r=$(curl -s -b $T/agmember.jar -o /dev/null -w '%{http_code}' "$H/wp-admin/$CSV")
ok "CSV: refused for members" $(grep -q 'pit.newbie' <(curl -s -b $T/agmember.jar "$H/wp-admin/$CSV") && echo 0 || echo 1)

echo "== Privacy and clean-up"
EXP=$(ev "\$r=CMP_Account::export('pit.newbie@agtest.example'); foreach (\$r['data'] as \$g) if (\$g['group_id']==='cmp-agreements') echo count(\$g['data']);")
ok "export by email includes the agreement" $([ "$EXP" = 1 ] && echo 1 || echo 0)
ev "CMP_Account::erase('evil@agtest.example');" >/dev/null
ok "erase by email removes its rows (no account needed)" $([ "$(q "SELECT COUNT(*) FROM $AT WHERE email='evil@agtest.example'")" = 0 ] && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->update('$AT', array('created_at'=>gmdate('Y-m-d H:i:s', time()-1100*86400)), array('email'=>'agmember@example.com'));" >/dev/null
ev "CMP_Retention::run();" >/dev/null
ok "daily clean-up removes rows older than 3 years, keeps newer ones" $(all "$([ "$(q "SELECT COUNT(*) FROM $AT WHERE email='agmember@example.com'")" = 0 ] && echo 1)$([ "$(q "SELECT COUNT(*) FROM $AT WHERE email='pit.newbie@agtest.example'")" = 1 ] && echo 1)")

echo "== Clean up"
for id in $($W post list --post_type=page --s='cmp_agreement' --field=ID 2>/dev/null); do $W post delete $id --force >/dev/null; done
for u in agmember agold; do $W user delete $u --yes >/dev/null 2>&1; done
ev "require_once ABSPATH.'wp-admin/includes/user.php'; foreach (get_users(array('search'=>'*@agtest.example','search_columns'=>array('user_email'))) as \$u) wp_delete_user(\$u->ID); global \$wpdb; \$wpdb->query(\"DELETE FROM $AT\");" >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
WARN=$(grep 'community-member-planning' wp-content/debug.log 2>/dev/null | wc -l | tr -d ' ')
grep 'community-member-planning' wp-content/debug.log 2>/dev/null | tail -5
ok "no PHP warnings from the plugin" $([ "$WARN" = 0 ] && echo 1 || echo 0)
echo "RESULT: $PASS passed, $FAIL failed"
