#!/usr/bin/env bash
# End-to-end test of Community Member Planning 0.1.0 over real HTTP.
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
PAGE_ID=$($W eval 'echo (int) CMP_Settings::get("member_page_id");')
PAGE="$H/?page_id=$PAGE_ID"
T=$S/e2e; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$1&pwd=$2&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
nonce(){ grep -o 'name="_cmp_nonce" value="[^"]*"' $1 | head -1 | cut -d'"' -f4; }
restnonce(){ grep -o '"nonce":"[^"]*"' $1 | head -1 | cut -d'"' -f4; }
post() { curl -s -b $T/$1.jar -c $T/$1.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" "${@:2}"; }

(php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &) ; sleep 2
rm -f wp-content/mail.log wp-content/debug.log
# Fresh state on every run.
$W user delete alice bob --yes >/dev/null 2>&1
$W user create alice alice@example.com --role=subscriber --user_pass=alicepass >/dev/null
$W user create bob bob@example.com --role=subscriber --user_pass=bobpass >/dev/null
$W eval 'global $wpdb; $wpdb->query("DELETE FROM ".CMP_Install::table("notifications")); $wpdb->query("DELETE FROM ".CMP_Install::table("audit_log")); delete_user_meta(1,"cmp_timezone");' >/dev/null
# Decodes the JSON line written by the mail-capture mu-plugin and pulls out the verification link.
maillink(){ php -r '$m=json_decode(trim($argv[1]),true); preg_match("~https?://\S*cmp_verify=[0-9a-f]+\S*~",$m["message"],$x); echo $x[0];' "$1"; }

echo "== Logged-out visitor"
get anon anon "$PAGE"
ok "shows sign-in prompt" $(has $T/anon.html 'Sign in with your site account')
ok "no-store cache header" $(grep -qi '^cache-control:.*no-store' $T/anon.h && echo 1 || echo 0)
ok "X-Robots-Tag noindex header" $(grep -qi '^x-robots-tag: noindex' $T/anon.h && echo 1 || echo 0)
ok "robots meta noindex" $(grep -q "<meta name='robots' content='[^']*noindex, nofollow" $T/anon.html && echo 1 || echo 0)
code=$(curl -s -o $T/anon-rest.json -w '%{http_code}' "$H/?rest_route=/cmp/v1/notifications")
ok "REST notifications -> 401" $([ "$code" = 401 ] && echo 1 || echo 0)
ok "REST error has no-store" $(curl -s -D - -o /dev/null "$H/?rest_route=/cmp/v1/notifications" | grep -qi 'cache-control:.*no-store' && echo 1 || echo 0)
curl -s "$H/?sitemap=posts&sitemap-subtype=page&paged=1" -o $T/sitemap.xml
ok "member page not in sitemap" $(hasnt $T/sitemap.xml "page_id=$PAGE_ID")
ok "sitemap still lists other pages" $(has $T/sitemap.xml '<loc>')
curl -s "$H/?s=Member+Area" -o $T/search.html
sed -n '/<main/,/<\/main>/p' $T/search.html > $T/search-main.html
ok "member page not in site search results" $(hasnt $T/search-main.html "page_id=$PAGE_ID")
r=$(curl -s -o /dev/null -w '%{redirect_url}' -d 'action=cmp_attest' "$H/wp-admin/admin-post.php")
ok "logged-out form post -> sent to login, back to member page" $(echo "$r" | grep -q "redirect_to=.*page_id%3D$PAGE_ID" && echo 1 || echo 0)

echo "== Alice: signed in, unverified"
login alice alicepass
get alice a1 "$PAGE"
ok "shows verify-email step" $(has $T/a1.html 'Confirm your email')
ok "shows her address" $(has $T/a1.html 'alice@example.com')
ok "logged-in page still no-store" $(grep -qi '^cache-control:.*no-store' $T/a1.h && echo 1 || echo 0)
code=$(curl -s -b $T/alice.jar -o /dev/null -w '%{http_code}' "$H/?rest_route=/cmp/v1/notifications")
ok "REST blocked while unverified (401/403)" $([ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)
N=$(nonce $T/a1.html)
r=$(post alice -d "action=cmp_attest&_cmp_nonce=$N&cmp_dob[m]=2&cmp_dob[d]=14&cmp_dob[y]=1983")
ok "cannot skip ahead to attestation" $([ "$($W eval 'echo CMP_Access::state(get_user_by("login","alice")->ID);')" = unverified ] && echo 1 || echo 0)
r=$(post alice -d "action=cmp_send_verification&_cmp_nonce=bad")
ok "bad nonce rejected" $(echo "$r" | grep -q 'cmp_notice=expired' && echo 1 || echo 0)
r=$(post alice -d "action=cmp_send_verification&_cmp_nonce=$N")
ok "send verification -> sent" $(echo "$r" | grep -q 'cmp_notice=sent' && echo 1 || echo 0)
ok "one email captured to alice" $([ "$(grep -c 'alice@example.com' wp-content/mail.log)" = 1 ] && echo 1 || echo 0)
r=$(post alice -d "action=cmp_send_verification&_cmp_nonce=$N")
ok "immediate resend rate-limited" $(echo "$r" | grep -q 'cmp_notice=rate_limited' && echo 1 || echo 0)
ok "still only one email" $([ "$(wc -l < wp-content/mail.log)" = 1 ] && echo 1 || echo 0)
LINK=$(maillink "$(head -1 wp-content/mail.log)")
TOKEN=$(echo "$LINK" | grep -o 'cmp_verify=[0-9a-f]*' | cut -d= -f2)
ok "token is 256-bit hex" $([ ${#TOKEN} = 64 ] && echo 1 || echo 0)
ok "raw token not stored" $($W eval "echo get_user_meta(get_user_by('login','alice')->ID,'cmp_verify_token_hash',true)==='$TOKEN'?0:1;")
r=$(curl -s -o /dev/null -w '%{redirect_url}' "$H/?cmp_verify=${TOKEN%?}0&cmp_uid=$($W user get alice --field=ID)")
ok "tampered token fails" $(echo "$r" | grep -q 'verify_failed' && echo 1 || echo 0)
r=$(curl -s -o /dev/null -w '%{redirect_url}' "$LINK")
ok "real link (opened logged out) verifies" $(echo "$r" | grep -q 'cmp_notice=verified' && echo 1 || echo 0)
r=$(curl -s -o /dev/null -w '%{redirect_url}' "$LINK")
ok "link is single-use" $(echo "$r" | grep -q 'verify_failed' && echo 1 || echo 0)

echo "== Alice: verified, not yet 18+ attested"
get alice a2 "$PAGE"
ok "shows the date-of-birth step" $([ "$(has $T/a2.html 'Date of birth')$(has $T/a2.html 'name="cmp_dob\[y\]"')" = 11 ] && echo 1 || echo 0)
ok "step 1 shown as completed" $(has $T/a2.html 'cmp-steps-done')
N=$(nonce $T/a2.html)
r=$(post alice -d "action=cmp_attest&_cmp_nonce=$N")
ok "no date -> asked for it" $(echo "$r" | grep -q 'cmp_notice=dob_missing' && echo 1 || echo 0)
r=$(post alice -d "action=cmp_attest&_cmp_nonce=$N&cmp_dob[m]=2&cmp_dob[d]=30&cmp_dob[y]=1990")
ok "impossible date (30 Feb) refused" $(echo "$r" | grep -q 'cmp_notice=dob_invalid' && echo 1 || echo 0)
r=$(post alice -d "action=cmp_attest&_cmp_nonce=$N&cmp_dob[m]=2&cmp_dob[d]=14&cmp_dob[y]=1983")
ok "valid date -> welcome" $(echo "$r" | grep -q 'cmp_notice=welcome' && echo 1 || echo 0)
ok "stores the date + the 18+ timestamp and version" $($W eval '$u=get_user_by("login","alice")->ID; echo ( "1983-02-14"===get_user_meta($u,"cmp_birth_date",true) && get_user_meta($u,"cmp_age_attested_at",true) && 1===(int)get_user_meta($u,"cmp_age_attestation_version",true) ) ? 1 : 0;')
ok "audit records that a date was set, never the date" $($W eval '$u=get_user_by("login","alice")->ID; global $wpdb; $j=wp_json_encode($wpdb->get_results("SELECT * FROM ".CMP_Install::table("audit_log")." WHERE object_id=$u")); echo false!==strpos($j,"birth_date_recorded") && false===strpos($j,"1983") ? 1 : 0;')

echo "== Alice: member"
get alice a3 "$PAGE"
ok "new member's home starts the profile setup (0.5.0)" $(has $T/a3.html 'Step 1 of 6')
$W eval "update_user_meta(get_user_by('login','alice')->ID,'cmp_setup_done','later');" >/dev/null
get alice a3 "$PAGE"
ok "member home shown" $(has $T/a3.html 'Welcome, alice')
ok "welcome notification listed" $(has $T/a3.html 'Welcome to the member area')
ok "unread count 1" $(has $T/a3.html 'data-cmp-unread aria-label="1 unread">1<')
RN=$(restnonce $T/a3.html)
curl -s -b $T/alice.jar -H "X-WP-Nonce: $RN" "$H/?rest_route=/cmp/v1/notifications" -o $T/a-list.json
ok "REST list returns her notification" $(has $T/a-list.json '"unread":1')
ok "REST list exposes no user ids" $(hasnt $T/a-list.json 'user_id')
AID=$(grep -o '"id":[0-9]*' $T/a-list.json | head -1 | cut -d: -f2)
ok "got Alice's notification id" $([ -n "$AID" ] && echo 1 || echo 0); AID=${AID:-0}
code=$(curl -s -b $T/alice.jar -o /dev/null -w '%{http_code}' -X POST "$H/?rest_route=/cmp/v1/notifications/$AID/read")
ok "REST write without X-WP-Nonce rejected" $([ "$code" != 200 ] && echo 1 || echo 0)

echo "== Bob: another full member"
BOB=$($W user get bob --field=ID)
$W eval "update_user_meta($BOB,'cmp_verified_email','bob@example.com'); update_user_meta($BOB,'cmp_age_attested_at',gmdate('Y-m-d H:i:s')); update_user_meta($BOB,'cmp_birth_date','1979-11-03'); update_user_meta($BOB,'cmp_setup_done','later');" >/dev/null
login bob bobpass
get bob b1 "$PAGE"
BN=$(restnonce $T/b1.html)
code=$(curl -s -b $T/bob.jar -H "X-WP-Nonce: $BN" -o $T/b-read.json -w '%{http_code}' -X POST "$H/?rest_route=/cmp/v1/notifications/$AID/read")
ok "Bob cannot mark Alice's notification (404)" $([ "$code" = 404 ] && echo 1 || echo 0)
curl -s -b $T/bob.jar -H "X-WP-Nonce: $BN" "$H/?rest_route=/cmp/v1/notifications" -o $T/b-list.json
ok "Bob's inbox doesn't contain Alice's" $(hasnt $T/b-list.json 'Welcome to the member area')
ok "Alice's still unread after Bob's attempt" $($W eval "global \$wpdb; echo null===\$wpdb->get_var('SELECT read_at FROM '.CMP_Install::table('notifications').' WHERE id=$AID') ? 1 : 0;")

echo "== Alice marks read"
code=$(curl -s -b $T/alice.jar -H "X-WP-Nonce: $RN" -o $T/a-read.json -w '%{http_code}' -X POST "$H/?rest_route=/cmp/v1/notifications/$AID/read")
ok "mark read -> 200, unread 0" $([ "$code" = 200 ] && grep -q '"unread":0' $T/a-read.json && echo 1 || echo 0)
code=$(curl -s -b $T/alice.jar -H "X-WP-Nonce: $RN" -o /dev/null -w '%{http_code}' -X POST "$H/?rest_route=/cmp/v1/notifications/999999/read")
ok "missing id -> 404" $([ "$code" = 404 ] && echo 1 || echo 0)

echo "== Email change forces re-verification"
$W user update alice --user_email=alice.new@example.com >/dev/null
ok "state back to unverified" $([ "$($W eval 'echo CMP_Access::state(get_user_by("login","alice")->ID);')" = unverified ] && echo 1 || echo 0)
code=$(curl -s -b $T/alice.jar -H "X-WP-Nonce: $RN" -o /dev/null -w '%{http_code}' "$H/?rest_route=/cmp/v1/notifications")
ok "REST blocked again after email change" $([ "$code" = 403 ] && echo 1 || echo 0)
ok "attestation kept (not asked twice)" $($W eval 'echo CMP_Access::has_attested(get_user_by("login","alice")->ID)?1:0;')

echo "== Expired / old-address tokens"
ALICE=$($W user get alice --field=ID)
$W eval "delete_transient('cmp_verify_wait_$ALICE'); CMP_Email_Verification::send($ALICE);" >/dev/null
LINK2=$(maillink "$(tail -1 wp-content/mail.log)")
ok "new link sent to the new address" $(tail -1 wp-content/mail.log | grep -q 'alice.new@example.com' && echo 1 || echo 0)
$W eval "update_user_meta($ALICE,'cmp_verify_token_expires',time()-1);" >/dev/null
r=$(curl -s -o /dev/null -w '%{redirect_url}' "$LINK2")
ok "expired link fails" $(echo "$r" | grep -q 'verify_failed' && echo 1 || echo 0)
$W eval "delete_transient('cmp_verify_wait_$ALICE'); CMP_Email_Verification::send($ALICE);" >/dev/null
LINK3=$(maillink "$(tail -1 wp-content/mail.log)")
$W user update alice --user_email=alice.third@example.com >/dev/null
r=$(curl -s -o /dev/null -w '%{redirect_url}' "$LINK3")
ok "link for a since-changed address fails" $(echo "$r" | grep -q 'verify_failed' && echo 1 || echo 0)

echo "== Audit log"
ok "audit trail for Alice" $($W eval "\$a=wp_list_pluck(CMP_Audit::for_object('user',$ALICE),'action'); foreach(array('email_verification_sent','email_verified','age_attested','email_changed') as \$x) if(!in_array(\$x,\$a,true)){echo 0;return;} echo 1;")
ok "audit class has no update/delete" $($W eval 'echo count(array_diff(get_class_methods("CMP_Audit"),array("log","for_object")))===0?1:0;')

echo "== Quiet hours (site timezone America/New_York)"
ok "23:30 local -> next day 08:00" $($W eval '$n=new DateTimeImmutable("2026-11-03 04:30:00",new DateTimeZone("UTC")); echo CMP_Notifications::deliver_at(1,$n)->format("Y-m-d H:i")==="2026-11-03 13:00"?1:0;')
ok "03:00 local -> same day 08:00" $($W eval '$n=new DateTimeImmutable("2026-11-03 08:00:00",new DateTimeZone("UTC")); echo CMP_Notifications::deliver_at(1,$n)->format("Y-m-d H:i")==="2026-11-03 13:00"?1:0;')
ok "12:00 local -> immediately" $($W eval '$n=new DateTimeImmutable("2026-11-03 17:00:00",new DateTimeZone("UTC")); echo CMP_Notifications::deliver_at(1,$n)==$n?1:0;')
ok "member timezone respected (LA 23:00 -> 08:00 PST)" $($W eval 'update_user_meta(1,"cmp_timezone","America/Los_Angeles"); $n=new DateTimeImmutable("2026-11-04 07:00:00",new DateTimeZone("UTC")); $r=CMP_Notifications::deliver_at(1,$n)->format("Y-m-d H:i"); delete_user_meta(1,"cmp_timezone"); echo $r==="2026-11-04 16:00"?1:0;')
ok "undelivered notification hidden until 8am" $($W eval "global \$wpdb; \$wpdb->insert(CMP_Install::table('notifications'),array('user_id'=>$BOB,'category'=>'account','message'=>'later','url'=>'','created_at'=>gmdate('Y-m-d H:i:s'),'deliver_at'=>gmdate('Y-m-d H:i:s',time()+3600))); echo ( 0===count(wp_list_filter(CMP_Notifications::for_user($BOB),array('message'=>'later'))) && 0===CMP_Notifications::unread_count($BOB) ) ? 1 : 0;")
ok "disabled category not created" $($W eval "update_user_meta($BOB,'cmp_notify_disabled_categories',array('invitation')); echo false===CMP_Notifications::add($BOB,'invitation','x')?1:0;")
ok "unknown category rejected" $($W eval "echo false===CMP_Notifications::add($BOB,'bogus','x')?1:0;")

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
echo
echo "debug.log (excluding test-theme notices):"; grep -v 'header.php\|footer.php' wp-content/debug.log 2>/dev/null | sed 's|/tmp/claude-0[^ ]*/wordpress/||g' | sort | uniq -c
echo "RESULT: $PASS passed, $FAIL failed"
