#!/usr/bin/env bash
# End-to-end test of the member area's Account tab and dashboard lockout
# (Community Member Planning 0.2.0) over real HTTP.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/account-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/acct; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -D $T/$1.login.h -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1&redirect_to=${4:-}" "$H/wp-login.php"; }
get()  { curl -s -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
loc()  { curl -s -b $T/$1.jar -c $T/$1.jar -o /dev/null -w '%{redirect_url}' "$2"; }
nonce(){ grep -o "value=\"cmp_account_$2\" /><input type=\"hidden\" name=\"_cmp_nonce\" value=\"[^\"]*\"" $1 | head -1 | sed 's/.*value="\([^"]*\)"$/\1/'; }
post() { curl -s -b $T/$1.jar -c $T/$1.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" "${@:2}"; }
state(){ $W eval "echo CMP_Access::state(get_user_by('login','$1')->ID);"; }
mails(){ [ -f wp-content/mail.log ] && wc -l < wp-content/mail.log || echo 0; }
mailto(){ php -r '$n=0; foreach(file($argv[1]) as $l){ $m=json_decode($l,true); $t=is_array($m["to"])?implode(",",$m["to"]):$m["to"]; if(false!==stripos($t,$argv[2])) $n++; } echo $n;' wp-content/mail.log "$1"; }
lastlink(){ php -r '$l=file($argv[1]); $m=json_decode(end($l),true); preg_match("~https?://\S*cmp_email_change=[0-9a-f]+\S*~",$m["message"],$x); echo $x[0]??"";' wp-content/mail.log; }

# This working copy of the plugin, a configured member page, mail captured to a file.
rm -rf wp-content/plugins/community-member-planning && cp -r $REPO/community-member-planning wp-content/plugins/
$W plugin activate community-member-planning >/dev/null 2>&1
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
PAGE_ID=$($W eval 'echo (int) CMP_Settings::get("member_page_id");')
PAGE="$H/?page_id=$PAGE_ID"
ACCT="$PAGE&cmp_tab=account"
$W eval '$o=get_option("cmp_settings",array()); unset($o["lock_dashboard"]); update_option("cmp_settings",$o);' >/dev/null
rm -f wp-content/mail.log
for u in carol dave ed; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create carol carol@example.com --role=subscriber --user_pass=carolpass1 --display_name=Carol >/dev/null
$W user create dave dave@example.com --role=subscriber --user_pass=davepass12 >/dev/null
$W user create ed ed@example.com --role=editor --user_pass=edpass1234 >/dev/null
$W eval '$d=get_user_by("login","dave")->ID; update_user_meta($d,"cmp_verified_email","dave@example.com"); update_user_meta($d,"cmp_email_verified_at",gmdate("Y-m-d H:i:s")); update_user_meta($d,"cmp_birth_date","1980-06-01"); CMP_Access::record_attestation($d); CMP_Notifications::add($d,"account","Hello Dave","",false); global $wpdb; $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type=\"user_request\"");' >/dev/null
$W eval 'foreach(array("carol","dave") as $u){ $id=get_user_by("login",$u)->ID; delete_transient("cmp_pw_fail_$id"); delete_transient("cmp_email_change_wait_$id"); }' >/dev/null

echo "== Signed out"
get anon a0 "$ACCT"
ok "account tab signed out -> sign-in prompt, no forms" $([ "$(has $T/a0.html 'Sign in with your site account')$(hasnt $T/a0.html 'cmp_account_')" = 11 ] && echo 1 || echo 0)
r=$(curl -s -o /dev/null -w '%{redirect_url}' -d 'action=cmp_account_password' "$H/wp-admin/admin-post.php")
ok "signed-out form post -> sign-in page, back to member area" $(echo "$r" | grep -q "redirect_to=.*page_id%3D$PAGE_ID" && echo 1 || echo 0)

echo "== Dashboard lockout (on by default)"
login carol carol carolpass1 "$H/wp-admin/"
ok "sign-in never lands in wp-admin" $(grep -i '^location:' $T/carol.login.h | grep -vq 'wp-admin' && echo 1 || echo 0)
echo "== Where signing in lands (CEC 1.28.1 / CMP 0.12.1)"
login carold carol carolpass1 ""
ok "wp-login with no destination: the member area" $(grep -i '^location:' $T/carold.login.h | grep -q "page_id=$PAGE_ID" && echo 1 || echo 0)
LP=$($W post create --post_type=page --post_status=publish --post_title="ZZ Log In" --post_content='[cec_login]' --porcelain 2>/dev/null)
rm -f $T/lf.jar; curl -s -c $T/lf.jar -b $T/lf.jar -o $T/lf.html "$H/?page_id=$LP"
LN=$(grep -o 'name="cec_login_nonce" value="[^"]*"' $T/lf.html | cut -d'"' -f4)
ok "site Log In form: no destination filled in" $(grep -q 'name="cec_redirect" value=""' $T/lf.html && echo 1 || echo 0)
r=$(curl -s -c $T/lf.jar -b $T/lf.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" --data-urlencode "action=cec_login" --data-urlencode "cec_login_nonce=$LN" --data-urlencode "cec_redirect=" --data-urlencode "cec_identifier=carol" --data-urlencode "cec_password=carolpass1")
ok "site Log In form: a member lands in the member area" $(echo "$r" | grep -q "page_id=$PAGE_ID" && echo 1 || echo 0)
rm -f $T/lf2.jar; curl -s -c $T/lf2.jar -b $T/lf2.jar -o $T/lf2.html "$H/?page_id=$LP&redirect_to=$(php -r 'echo rawurlencode($argv[1]);' "$H/?page_id=$LP&back=1")"
LN2=$(grep -o 'name="cec_login_nonce" value="[^"]*"' $T/lf2.html | cut -d'"' -f4)
r=$(curl -s -c $T/lf2.jar -b $T/lf2.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" --data-urlencode "action=cec_login" --data-urlencode "cec_login_nonce=$LN2" --data-urlencode "cec_redirect=$H/?page_id=$LP&back=1" --data-urlencode "cec_identifier=carol" --data-urlencode "cec_password=carolpass1")
ok "an explicit destination is still respected" $(echo "$r" | grep -q "back=1" && echo 1 || echo 0)
ok "event managers keep their default (not the member area)" $($W eval '$u=new WP_User(0); $u->ID=999999; $u->allcaps=array("cec_manage_events"=>true); $u->caps=array("cec_manage_events"=>true); echo false===strpos(CEC_Auth::default_redirect($u),"page_id='$PAGE_ID'")?1:0;' 2>&1)
$W post delete $LP --force >/dev/null 2>&1
login carolp carol carolpass1 "$H/wp-admin/profile.php"
ok "sign-in aimed at profile.php lands on the Account tab" $(grep -i '^location:' $T/carolp.login.h | grep -q "page_id=$PAGE_ID.*cmp_tab=account" && echo 1 || echo 0)
r=$(loc carol "$H/wp-admin/")
ok "wp-admin -> front end (events plugin's routing kept)" $([ -n "$r" ] && ! echo "$r" | grep -q 'wp-admin' && echo 1 || echo 0)
r=$(loc carol "$H/wp-admin/profile.php")
ok "profile.php -> Account tab" $(echo "$r" | grep -q "page_id=$PAGE_ID.*cmp_tab=account" && echo 1 || echo 0)
code=$(curl -s -b $T/carol.jar -o $T/edit.html -w '%{http_code}' "$H/wp-admin/edit.php")
ok "other wp-admin screens refused (WordPress 403 or redirect)" $([ "$code" = 403 ] || [ "$code" = 302 ] && echo 1 || echo 0)
code=$(curl -s -b $T/carol.jar -o /dev/null -w '%{http_code}' -d 'action=heartbeat' "$H/wp-admin/admin-ajax.php")
ok "admin-ajax still answers (not redirected)" $([ "$code" != 302 ] && echo 1 || echo 0)
get carol c0 "$H/"
ok "no admin bar on the site" $(hasnt $T/c0.html 'id="wpadminbar"')
ok "profile link points to Account tab" $([ "$($W eval 'wp_set_current_user(get_user_by("login","carol")->ID); echo get_edit_profile_url();')" = "$ACCT" ] && echo 1 || echo 0)
login ed ed edpass1234
ok "editor keeps the WordPress profile screen" $([ "$(curl -s -b $T/ed.jar -o /dev/null -w '%{http_code}' "$H/wp-admin/profile.php")" = 200 ] && echo 1 || echo 0)
login admin admin admin
ok "administrator still reaches wp-admin" $([ "$(curl -s -b $T/admin.jar -o /dev/null -w '%{http_code}' "$H/wp-admin/profile.php")" = 200 ] && echo 1 || echo 0)
$W eval '$id=get_user_by("login","ed")->ID; $u=new WP_User($id); $u->set_role("cec_calendar_manager");' >/dev/null
ok "calendar manager still reaches wp-admin" $([ "$(curl -s -b $T/ed.jar -o /dev/null -w '%{http_code}' "$H/wp-admin/profile.php")" = 200 ] && echo 1 || echo 0)

echo "== Account tab while the email is not yet confirmed"
get carol c1 "$PAGE"
ok "verify step links to the Account tab (not wp-admin)" $([ "$(has $T/c1.html 'cmp_tab=account#cmp-email')$(hasnt $T/c1.html 'profile.php')" = 11 ] && echo 1 || echo 0)
ok "tabs: Get started | Account | Sign out" $([ "$(has $T/c1.html '>Get started<')$(has $T/c1.html '>Account<')$(has $T/c1.html 'cmp-tabs-signout')" = 111 ] && echo 1 || echo 0)
get carol c2 "$ACCT"
ok "Account tab renders all sections" $([ "$(has $T/c2.html 'id="cmp-details"')$(has $T/c2.html 'id="cmp-email"')$(has $T/c2.html 'id="cmp-password"')$(has $T/c2.html 'id="cmp-sessions"')$(has $T/c2.html 'id="cmp-privacy"')" = 11111 ] && echo 1 || echo 0)
ok "shows address as not confirmed" $(has $T/c2.html 'Not confirmed yet')
ok "Account tab marked current" $(has $T/c2.html 'aria-current="page" class="is-current">Account')
ok "Account page is no-store" $(grep -qi '^cache-control:.*no-store' $T/c2.h && echo 1 || echo 0)

echo "== Your details"
r=$(post carol -d "action=cmp_account_details&_cmp_nonce=bad&display_name=X")
ok "bad nonce -> expired, nothing saved" $(echo "$r" | grep -q 'cmp_notice=expired' && [ "$($W user get carol --field=display_name)" = Carol ] && echo 1 || echo 0)
N=$(nonce $T/c2.html details)
r=$(post carol -d "action=cmp_account_details&_cmp_nonce=$N&first_name=Carol&last_name=Jones&display_name=")
ok "empty display name refused" $(echo "$r" | grep -q 'details_invalid' && echo 1 || echo 0)
r=$(post carol --data-urlencode "action=cmp_account_details" --data-urlencode "_cmp_nonce=$N" --data-urlencode "first_name=Carol" --data-urlencode "last_name=Jones" --data-urlencode "display_name=CJ <b>Leather</b>")
ok "details saved, tags stripped" $(echo "$r" | grep -q 'details_saved' && [ "$($W user get carol --field=display_name)" = "CJ Leather" ] && [ "$($W user meta get carol last_name)" = Jones ] && echo 1 || echo 0)
ok "details change audited" $($W eval 'echo count(array_filter(CMP_Audit::for_object("user",get_user_by("login","carol")->ID),function($r){return "account_details_changed"===$r->action;}))?1:0;')

echo "== Email change"
N=$(nonce $T/c2.html email)
r=$(post carol -d "action=cmp_account_email&_cmp_nonce=$N&new_email=carol.new@example.com&email_current_password=wrong")
ok "wrong current password refused" $(echo "$r" | grep -q 'password_wrong' && echo 1 || echo 0)
r=$(post carol -d "action=cmp_account_email&_cmp_nonce=$N&new_email=dave@example.com&email_current_password=carolpass1")
ok "address of another account refused" $(echo "$r" | grep -q 'email_taken' && echo 1 || echo 0)
r=$(post carol -d "action=cmp_account_email&_cmp_nonce=$N&new_email=not-an-email&email_current_password=carolpass1")
ok "invalid address refused" $(echo "$r" | grep -q 'email_invalid' && echo 1 || echo 0)
before=$(mails)
r=$(post carol -d "action=cmp_account_email&_cmp_nonce=$N&new_email=Carol.New@Example.com&email_current_password=carolpass1")
ok "valid request -> link sent" $(echo "$r" | grep -q 'email_change_sent' && echo 1 || echo 0)
ok "link emailed to the NEW address only" $([ "$(mailto carol.new@example.com)" = 1 ] && [ $(( $(mails) - before )) = 1 ] && echo 1 || echo 0)
ok "address unchanged until confirmed" $([ "$($W user get carol --field=user_email)" = carol@example.com ] && echo 1 || echo 0)
LINK=$(lastlink)
ok "only the token's hash is stored" $($W eval "echo get_user_meta(get_user_by('login','carol')->ID,'cmp_email_change_hash',true)===hash('sha256','$(echo "$LINK" | grep -o 'cmp_email_change=[0-9a-f]*' | cut -d= -f2)')?1:0;")
get carol c3 "$ACCT"
ok "pending change shown with cancel button" $([ "$(has $T/c3.html 'carol.new@example.com')$(has $T/c3.html 'Cancel the change')" = 11 ] && echo 1 || echo 0)
r=$(post carol -d "action=cmp_account_email&_cmp_nonce=$N&new_email=carol.other@example.com&email_current_password=carolpass1")
ok "second request within 5 minutes rate-limited" $(echo "$r" | grep -q 'rate_limited' && echo 1 || echo 0)
TOK=$(echo "$LINK" | grep -o 'cmp_email_change=[0-9a-f]*' | cut -d= -f2)
r=$(curl -s -o /dev/null -w '%{redirect_url}' "$H/?cmp_email_change=${TOK%?}0&cmp_uid=$($W user get carol --field=ID)")
ok "tampered link fails" $(echo "$r" | grep -q 'email_link_failed' && echo 1 || echo 0)
ok "tampered link leaves the pending change alone" $([ "$($W user meta get carol cmp_email_change_email 2>/dev/null)" = carol.new@example.com ] && echo 1 || echo 0)
r=$(curl -s -o /dev/null -w '%{redirect_url}' "$H/?cmp_email_change=$TOK&cmp_uid=$($W user get dave --field=ID)")
ok "link used for another account fails" $(echo "$r" | grep -q 'email_link_failed' && [ "$($W user get dave --field=user_email)" = dave@example.com ] && echo 1 || echo 0)
r=$(curl -s -o /dev/null -w '%{redirect_url}' "$LINK")
ok "link (opened signed out) changes the address" $(echo "$r" | grep -q 'email_changed' && [ "$($W user get carol --field=user_email)" = carol.new@example.com ] && echo 1 || echo 0)
ok "new address counts as confirmed (next step: 18+)" $([ "$(state carol)" = unattested ] && echo 1 || echo 0)
ok "WordPress told the old address" $([ "$(mailto carol@example.com)" -ge 1 ] && echo 1 || echo 0)
r=$(curl -s -o /dev/null -w '%{redirect_url}' "$LINK")
ok "link is single-use" $(echo "$r" | grep -q 'email_link_failed' && echo 1 || echo 0)
$W eval 'delete_transient("cmp_email_change_wait_".get_user_by("login","carol")->ID);' >/dev/null
get carol c5 "$ACCT"
r=$(post carol -d "action=cmp_account_email&_cmp_nonce=$(nonce $T/c5.html email)&new_email=carol.third@example.com&email_current_password=carolpass1")
LINK3=$(lastlink)
get carol c6 "$ACCT"
r=$(post carol -d "action=cmp_account_email_cancel&_cmp_nonce=$(nonce $T/c6.html email_cancel)")
ok "cancel clears the pending change" $(echo "$r" | grep -q 'email_change_cancelled' && [ -z "$($W user meta get carol cmp_email_change_email 2>/dev/null)" ] && echo 1 || echo 0)
r=$(curl -s -o /dev/null -w '%{redirect_url}' "$LINK3")
ok "a cancelled change's link does nothing" $(echo "$r" | grep -q 'email_link_failed' && [ "$($W user get carol --field=user_email)" = carol.new@example.com ] && echo 1 || echo 0)

echo "== Password"
login carol2 carol carolpass1   # a second device
get carol p0 "$ACCT"
N=$(nonce $T/p0.html password)
r=$(post carol -d "action=cmp_account_password&_cmp_nonce=$N&current_password=nope&new_password=abcdefghijk&confirm_password=abcdefghijk")
ok "wrong current password refused" $(echo "$r" | grep -q 'password_wrong' && echo 1 || echo 0)
r=$(post carol -d "action=cmp_account_password&_cmp_nonce=$N&current_password=carolpass1&new_password=short&confirm_password=short")
ok "short password refused" $(echo "$r" | grep -q 'password_short' && echo 1 || echo 0)
r=$(post carol -d "action=cmp_account_password&_cmp_nonce=$N&current_password=carolpass1&new_password=abcdefghijk&confirm_password=abcdefghijx")
ok "mismatch refused" $(echo "$r" | grep -q 'password_mismatch' && echo 1 || echo 0)
r=$(post carol -d "action=cmp_account_password&_cmp_nonce=$N&current_password=carolpass1&new_password=carol.new@example.com&confirm_password=carol.new@example.com")
ok "email as password refused" $(echo "$r" | grep -q 'password_weak' && echo 1 || echo 0)
r=$(post carol --data-urlencode "action=cmp_account_password" --data-urlencode "_cmp_nonce=$N" --data-urlencode "current_password=carolpass1" --data-urlencode "new_password=saddle stitch oak 7" --data-urlencode "confirm_password=saddle stitch oak 7")
ok "password changed" $(echo "$r" | grep -q 'password_changed' && echo 1 || echo 0)
get carol p1 "$ACCT"
ok "this browser stays signed in" $(has $T/p1.html 'id="cmp-password"')
get carol2 p2 "$ACCT"
ok "other device signed out" $(has $T/p2.html 'Sign in with your site account')
login carol3 carol "saddle stitch oak 7"
get carol3 p3 "$ACCT"
ok "new password works" $(has $T/p3.html 'id="cmp-password"')
login carol4 carol carolpass1
get carol4 p4 "$ACCT"
ok "old password no longer works" $(has $T/p4.html 'Sign in with your site account')
ok "password change audited" $($W eval 'echo count(array_filter(CMP_Audit::for_object("user",get_user_by("login","carol")->ID),function($r){return "password_changed"===$r->action;}))?1:0;')
N=$(nonce $T/p1.html password)
for i in 1 2 3 4 5; do post carol -d "action=cmp_account_password&_cmp_nonce=$N&current_password=bad$i&new_password=abcdefghijk&confirm_password=abcdefghijk" >/dev/null; done
r=$(post carol --data-urlencode "action=cmp_account_password" --data-urlencode "_cmp_nonce=$N" --data-urlencode "current_password=saddle stitch oak 7" --data-urlencode "new_password=abcdefghijk" --data-urlencode "confirm_password=abcdefghijk")
ok "after 5 wrong passwords: locked even with the right one" $(echo "$r" | grep -q 'password_locked' && echo 1 || echo 0)
$W eval 'delete_transient("cmp_pw_fail_".get_user_by("login","carol")->ID);' >/dev/null

echo "== Signed-in devices"
login dave dave davepass12; login dave2 dave davepass12
get dave d1 "$ACCT"
ok "counts the other device" $(has $T/d1.html 'also signed in on 1 other device')
r=$(post dave -d "action=cmp_account_sessions&_cmp_nonce=$(nonce $T/d1.html sessions)")
ok "sign out everywhere else" $(echo "$r" | grep -q 'sessions_ended' && echo 1 || echo 0)
get dave2 d2 "$ACCT"; get dave d3 "$ACCT"
ok "other device signed out, this one not" $([ "$(has $T/d2.html 'Sign in with your site account')$(has $T/d3.html 'only signed in here')" = 11 ] && echo 1 || echo 0)
$W eval 'update_user_meta(get_user_by("login","dave")->ID,"cmp_setup_done","later");' >/dev/null
get dave d4 "$PAGE"
ok "member sees Home | Account tabs" $([ "$(has $T/d4.html '>Home<')$(has $T/d4.html 'Hello Dave')" = 11 ] && echo 1 || echo 0)

echo "== Privacy requests"
before=$(mails)
r=$(post dave -d "action=cmp_account_privacy&_cmp_nonce=$(nonce $T/d3.html privacy)&request=export")
ok "export request created and confirmation emailed" $(echo "$r" | grep -q 'export_requested' && [ $(( $(mails) - before )) = 1 ] && echo 1 || echo 0)
ok "request recorded by WordPress for the admin" $([ "$($W eval 'global $wpdb; echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=\"user_request\" AND post_title=\"dave@example.com\" AND post_name=\"export_personal_data\"");')" = 1 ] && echo 1 || echo 0)
r=$(post dave -d "action=cmp_account_privacy&_cmp_nonce=$(nonce $T/d3.html privacy)&request=export")
ok "duplicate request -> already pending" $(echo "$r" | grep -q 'privacy_pending' && echo 1 || echo 0)
r=$(post dave -d "action=cmp_account_privacy&_cmp_nonce=$(nonce $T/d3.html privacy)&request=erase")
ok "erase request created" $(echo "$r" | grep -q 'erase_requested' && echo 1 || echo 0)
ok "exporter returns access record + notifications" $($W eval '$r=CMP_Account::export("dave@example.com"); $g=wp_list_pluck($r["data"],"group_id"); echo in_array("cmp-access",$g,true) && in_array("cmp-notifications",$g,true)?1:0;')
ok "registered with WordPress's privacy tools" $($W eval 'echo isset(apply_filters("wp_privacy_personal_data_exporters",array())["community-member-planning"], apply_filters("wp_privacy_personal_data_erasers",array())["community-member-planning"])?1:0;')
ok "eraser removes notifications and member data" $($W eval '$id=get_user_by("login","dave")->ID; $r=CMP_Account::erase("dave@example.com"); echo $r["items_removed"] && !CMP_Notifications::for_user($id,10) && !CMP_Access::is_member($id)?1:0;')

echo "== Lockout can be turned off"
$W eval '$o=get_option("cmp_settings",array()); $o["lock_dashboard"]=0; update_option("cmp_settings",$o);' >/dev/null
ok "setting off -> subscriber reaches profile.php" $([ "$(curl -s -b $T/carol.jar -o /dev/null -w '%{http_code}' "$H/wp-admin/profile.php")" = 200 ] && echo 1 || echo 0)
$W eval '$o=get_option("cmp_settings",array()); unset($o["lock_dashboard"]); update_option("cmp_settings",$o);' >/dev/null

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
echo; echo "debug.log:"; grep -i 'cmp\|community-member' wp-content/debug.log 2>/dev/null | head -5
echo "RESULT: $PASS passed, $FAIL failed"
