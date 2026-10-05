#!/usr/bin/env bash
# End-to-end test of member messages (Community Member Planning 0.11.0):
# requests vs. inbox, the request limit, accepting, connected members,
# privacy of conversations, turning requests off, the daily limit, deleting,
# blocking (messages, profiles, feed, dynamics), reporting (admin screen),
# export/erase.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/messages-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/msg; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
mnonce(){ php -r 'preg_match("~name=\"action\" value=\"cmp_msg\".*?name=\"_cmp_nonce\" value=\"([^\"]+)\"~s",file_get_contents($argv[1]),$m); echo $m[1]??"";' "$1"; }
# act <user> <nonce> <do> [--data-urlencode args...]
act()  { local u=$1 n=$2 d=$3; shift 3; curl -s -b $T/$u.jar -c $T/$u.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" --data-urlencode "action=cmp_msg" --data-urlencode "_cmp_nonce=$n" --data-urlencode "do=$d" "$@"; }
uid()  { $W user get $1 --field=ID 2>/dev/null; }
ev()   { $W eval "$1" 2>&1; }
member(){ ev "\$i=get_user_by('login','$1')->ID; update_user_meta(\$i,'cmp_verified_email','$1@example.com'); update_user_meta(\$i,'cmp_email_verified_at',gmdate('Y-m-d H:i:s')); update_user_meta(\$i,'cmp_birth_date','1980-01-01'); CMP_Access::record_attestation(\$i); update_user_meta(\$i,'cmp_setup_done','later');" >/dev/null; }
q()    { ev "global \$wpdb; echo \$wpdb->get_var(\"$1\");"; }
TBL()  { ev "echo CMP_Install::table('$1');"; }

rm -rf wp-content/plugins/community-member-planning && cp -r $REPO/community-member-planning wp-content/plugins/
$W plugin activate community-member-planning >/dev/null 2>&1
ev 'CMP_Install::maybe_upgrade();' >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
PAGE_ID=$(ev 'echo (int) CMP_Settings::get("member_page_id");')
PAGE="$H/?page_id=$PAGE_ID"
C_T=$(TBL conversations); M_T=$(TBL messages); B_T=$(TBL blocks); R_T=$(TBL message_reports); D_T=$(TBL dynamics); P_T=$(TBL posts); N_T=$(TBL notifications)
ev "global \$wpdb; foreach(array('$C_T','$M_T','$B_T','$R_T','$D_T','$P_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
for u in carl dana erin nosy mod; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create carl carl@example.com --role=subscriber --user_pass=carlpass12345 --display_name="Carl Sender" >/dev/null
$W user create dana dana@example.com --role=subscriber --user_pass=danapass12345 --display_name="Dana Receiver" >/dev/null
$W user create erin erin@example.com --role=subscriber --user_pass=erinpass12345 --display_name="Erin Partner" >/dev/null
$W user create nosy nosy@example.com --role=subscriber --user_pass=nosypass12345 --display_name="Nosy Member" >/dev/null
$W user create mod mod@example.com --role=administrator --user_pass=modpass123456 --display_name="Site Mod" >/dev/null
member carl; member dana; member erin; member nosy; member mod
CARL=$(uid carl); DANA=$(uid dana); ERIN=$(uid erin); NOSY=$(uid nosy)
rm -f wp-content/mail.log
login carl carl carlpass12345; login dana dana danapass12345; login erin erin erinpass12345; login nosy nosy nosypass12345

echo "== Starting a conversation"
get carl c0 "$PAGE&cmp_tab=messages"
ok "Messages tab, empty inbox" $([ "$(has $T/c0.html 'cmp_tab=messages')$(has $T/c0.html 'No messages yet')" = 11 ] && echo 1 || echo 0)
CN=$(mnonce $T/c0.html)
get carl c1 "$PAGE&cmp_member=$DANA"
ok "profile has Message, Block, Report" $([ "$(has $T/c1.html "cmp_tab=messages&#038;to=$DANA")$(has $T/c1.html 'value="block"')$(has $T/c1.html 'Report Dana Receiver')" = 111 ] && echo 1 || echo 0)
get carl c2 "$PAGE&cmp_tab=messages&to=$DANA"
ok "not connected: told it goes as a request" $(has $T/c2.html 'goes as a request')
r=$(act carl $CN send --data-urlencode "to=$DANA" --data-urlencode "body=   ")
ok "empty message refused" $(echo "$r" | grep -q 'msg_empty' && echo 1 || echo 0)
r=$(act carl $CN send --data-urlencode "to=$DANA" --data-urlencode "body=$(head -c 2100 /dev/zero | tr '\0' 'x')")
ok "over 2,000 characters refused" $(echo "$r" | grep -q 'msg_long' && echo 1 || echo 0)
r=$(act carl $CN send --data-urlencode "to=$DANA" --data-urlencode "body=Hi Dana <script>alert(1)</script> saw you at Leather Night")
CID=$(q "SELECT MAX(id) FROM $C_T")
ok "sent as a request" $(echo "$r" | grep -q 'msg_requested' && [ "$(q "SELECT status FROM $C_T WHERE id=$CID")" = request ] && echo 1 || echo 0)
ok "Dana notified (message request)" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$DANA AND category='message'")" -ge 1 ] && echo 1 || echo 0)
get dana d0 "$PAGE&cmp_tab=messages"
get dana d1 "$PAGE&cmp_tab=messages&box=requests"
ok "Dana: not in Inbox, under Requests (badge, tab count)" $([ "$(hasnt $T/d0.html 'saw you at Leather Night')$(has $T/d1.html 'saw you at Leather Night')$(has $T/d0.html 'cmp-msg-badge')$(has $T/d0.html 'Messages (1)')" = 1111 ] && echo 1 || echo 0)
get carl c3 "$PAGE&cmp_tab=messages&box=sent"
ok "Carl: under Sent, waiting for Dana to accept (0.15.1)" $([ "$(has $T/c3.html 'Dana Receiver')$(has $T/c3.html 'waiting for them to accept')" = 11 ] && echo 1 || echo 0)
get carl c3b "$PAGE&cmp_tab=messages"
ok "...not mixed into his Inbox" $(hasnt $T/c3b.html 'Dana Receiver')
act carl $CN send --data-urlencode "conversation=$CID" --data-urlencode "body=second" >/dev/null
act carl $CN send --data-urlencode "conversation=$CID" --data-urlencode "body=third" >/dev/null
r=$(act carl $CN send --data-urlencode "conversation=$CID" --data-urlencode "body=fourth")
ok "at most 3 messages until accepted" $(echo "$r" | grep -q 'msg_wait' && [ "$(q "SELECT COUNT(*) FROM $M_T WHERE conversation_id=$CID")" = 3 ] && echo 1 || echo 0)
r=$(act carl $CN send --data-urlencode "to=$DANA" --data-urlencode "body=sneaky new thread")
ok "a second 'new' message joins the same conversation (still limited)" $(echo "$r" | grep -q 'msg_wait' && [ "$(q "SELECT COUNT(*) FROM $C_T")" = 1 ] && echo 1 || echo 0)

echo "== Reading and accepting"
get dana d2 "$PAGE&cmp_tab=messages&c=$CID"
DN=$(mnonce $T/d2.html)
ok "Dana opens the request: messages, Accept / Delete / Block" $([ "$(has $T/d2.html 'saw you at Leather Night')$(has $T/d2.html 'value="accept"')$(has $T/d2.html 'Also block them')" = 111 ] && echo 1 || echo 0)
ok "HTML in a message never reaches the page" $([ "$(hasnt $T/d2.html '<script>alert(1)')$(hasnt $T/d2.html 'alert(1)</script>')" = 11 ] && echo 1 || echo 0)
r=$(act dana $DN accept --data-urlencode "conversation=$CID")
ok "accepted -> open, in Dana's inbox" $(echo "$r" | grep -q 'msg_accepted' && [ "$(q "SELECT status FROM $C_T WHERE id=$CID")" = open ] && echo 1 || echo 0)
r=$(act carl $CN send --data-urlencode "conversation=$CID" --data-urlencode "body=fourth now allowed")
ok "after accepting, no limit" $(echo "$r" | grep -q 'msg_sent' && echo 1 || echo 0)
r=$(act dana $DN send --data-urlencode "conversation=$CID" --data-urlencode "body=Sure, see you there")
get carl c4 "$PAGE&cmp_tab=messages"
ok "Carl sees Dana's reply as unread" $([ "$(has $T/c4.html 'is-unread')" = 1 ] && echo 1 || echo 0)
get carl c5 "$PAGE&cmp_tab=messages&c=$CID"
get carl c6 "$PAGE&cmp_tab=messages"
ok "opening marks it read" $(hasnt $T/c6.html 'is-unread')

echo "== Privacy of a conversation"
get nosy n0 "$PAGE&cmp_tab=messages"; NN=$(mnonce $T/n0.html)
get nosy n1 "$PAGE&cmp_tab=messages&c=$CID"
ok "another member can't open it" $([ "$(hasnt $T/n1.html 'saw you at Leather Night')$(has $T/n1.html 'available')" = 11 ] && echo 1 || echo 0)
r=$(act nosy $NN send --data-urlencode "conversation=$CID" --data-urlencode "body=butting in")
ok "...or post into it" $(echo "$r" | grep -q 'msg_gone' && [ "$(q "SELECT COUNT(*) FROM $M_T WHERE body='butting in'")" = 0 ] && echo 1 || echo 0)

echo "== Connected members, requests off, daily limit"
ev "global \$wpdb; \$wpdb->insert('$D_T',array('type'=>'partners','proposer_id'=>$CARL,'partner_id'=>$ERIN,'proposer_side'=>'','status'=>'active','proposer_show'=>1,'partner_show'=>1,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
r=$(act carl $CN send --data-urlencode "to=$ERIN" --data-urlencode "body=hey partner")
ok "connected (active dynamic): straight to the inbox" $(echo "$r" | grep -q 'msg_sent' && [ "$(ev "echo CMP_Messages::between($CARL,$ERIN)->status;")" = open ] && echo 1 || echo 0)
get nosy n2 "$PAGE&cmp_tab=messages"
r=$(act nosy $NN settings)
ok "requests switched off" $([ "$(ev "echo get_user_meta($NOSY,'cmp_msg_requests_off',true);")" = 1 ] && echo 1 || echo 0)
r=$(act carl $CN send --data-urlencode "to=$NOSY" --data-urlencode "body=hello?")
ok "can't start with someone who turned requests off" $(echo "$r" | grep -q 'msg_closed' && echo 1 || echo 0)
get carl c7 "$PAGE&cmp_member=$NOSY"
ok "their profile says so instead of Message" $([ "$(has $T/c7.html 'Not accepting message requests')$(hasnt $T/c7.html "to=$NOSY")" = 11 ] && echo 1 || echo 0)
act nosy $NN settings --data-urlencode "requests=1" >/dev/null
ev "global \$wpdb; for(\$i=0;\$i<20;\$i++) \$wpdb->insert('$C_T',array('user_a'=>900000+\$i,'user_b'=>900100+\$i,'started_by'=>$CARL,'status'=>'request','last_message_at'=>gmdate('Y-m-d H:i:s'),'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
r=$(act carl $CN send --data-urlencode "to=$NOSY" --data-urlencode "body=hello?")
ok "at most 20 new conversations a day" $(echo "$r" | grep -q 'msg_limit' && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->query(\"DELETE FROM $C_T WHERE user_a>=900000\");" >/dev/null

echo "== Deleting"
r=$(act dana $DN delete --data-urlencode "conversation=$CID")
get dana d3 "$PAGE&cmp_tab=messages"
get carl c8 "$PAGE&cmp_tab=messages"
ok "deleted from Dana's side only" $([ "$(hasnt $T/d3.html 'Carl Sender')$(has $T/c8.html 'Dana Receiver')" = 11 ] && echo 1 || echo 0)
act carl $CN send --data-urlencode "conversation=$CID" --data-urlencode "body=still there?" >/dev/null
get dana d4 "$PAGE&cmp_tab=messages&c=$CID"
ok "a new message brings it back with only the new message" $([ "$(has $T/d4.html 'still there?')$(hasnt $T/d4.html 'saw you at Leather Night')" = 11 ] && echo 1 || echo 0)

echo "== Blocking"
ev "global \$wpdb; \$wpdb->insert('$D_T',array('type'=>'friends','proposer_id'=>$DANA,'partner_id'=>$CARL,'proposer_side'=>'','status'=>'active','proposer_show'=>1,'partner_show'=>1,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
DYN=$(q "SELECT MAX(id) FROM $D_T")
ev "global \$wpdb; \$wpdb->insert('$P_T',array('author_id'=>$DANA,'body'=>'Dana feed post','event_id'=>0,'visibility'=>'members','status'=>'published','reports'=>0,'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
r=$(act dana $DN block --data-urlencode "member=$CARL")
ok "Dana blocks Carl" $(echo "$r" | grep -q 'msg_blocked' && [ "$(q "SELECT COUNT(*) FROM $B_T WHERE blocker_id=$DANA AND blocked_id=$CARL")" = 1 ] && echo 1 || echo 0)
ok "their dynamic ended" $([ "$(q "SELECT status FROM $D_T WHERE id=$DYN")" = ended ] && echo 1 || echo 0)
r=$(act carl $CN send --data-urlencode "conversation=$CID" --data-urlencode "body=why?")
ok "Carl can't message Dana" $(echo "$r" | grep -q 'msg_gone' && echo 1 || echo 0)
r=$(act carl $CN send --data-urlencode "to=$DANA" --data-urlencode "body=new thread?")
ok "...or start again" $(echo "$r" | grep -q 'msg_gone' && echo 1 || echo 0)
get carl c9 "$PAGE&cmp_member=$DANA"
ok "Carl can't see Dana's profile" $([ "$(has $T/c9.html 'profile isn')$(hasnt $T/c9.html 'Report Dana')" = 11 ] && echo 1 || echo 0)
get dana d5 "$PAGE&cmp_member=$CARL"
ok "...nor Dana Carl's" $(has $T/d5.html 'profile isn')
get carl c10 "$PAGE&cmp_tab=feed"
ok "Dana's posts hidden from Carl" $(hasnt $T/c10.html 'Dana feed post')
get carl c11 "$PAGE&cmp_tab=messages"
ok "conversation gone from Carl's list" $(hasnt $T/c11.html 'Dana Receiver')
get dana d6 "$PAGE&cmp_tab=messages&box=blocked"
ok "Dana's Blocked list shows Carl with Unblock" $([ "$(has $T/d6.html 'Carl Sender')$(has $T/d6.html 'value="unblock"')" = 11 ] && echo 1 || echo 0)
r=$(act dana $DN unblock --data-urlencode "member=$CARL")
ok "unblock" $([ "$(q "SELECT COUNT(*) FROM $B_T")" = 0 ] && echo 1 || echo 0)

echo "== Reporting"
r=$(act nosy $NN report --data-urlencode "member=$CARL" --data-urlencode "reason=nonsense")
ok "a reason is required" $(echo "$r" | grep -q 'msg_gone' && [ "$(q "SELECT COUNT(*) FROM $R_T")" = 0 ] && echo 1 || echo 0)
act carl $CN send --data-urlencode "to=$NOSY" --data-urlencode "body=creepy message for nosy" >/dev/null
r=$(act nosy $NN report --data-urlencode "member=$CARL" --data-urlencode "reason=harassment" --data-urlencode "note=Keeps messaging" --data-urlencode "also_block=1")
RID=$(q "SELECT MAX(id) FROM $R_T")
ok "report saved, admin emailed, and blocked" $([ "$(q "SELECT reason FROM $R_T WHERE id=$RID")" = harassment ] && grep -q 'was reported' wp-content/mail.log && grep -q 'cmp-message-reports' wp-content/mail.log && [ "$(q "SELECT COUNT(*) FROM $B_T WHERE blocker_id=$NOSY AND blocked_id=$CARL")" = 1 ] && echo 1 || echo 0)
ok "the email holds no message content" $(grep -q 'creepy message' wp-content/mail.log && echo 0 || echo 1)
curl -s -b $T/carl.jar -o $T/c13.html "$H/wp-admin/users.php?page=cmp-message-reports&report=$RID"
ok "members can't open the reports screen" $(hasnt $T/c13.html 'creepy message')
login mod mod modpass123456
get mod m1 "$H/wp-admin/users.php?page=cmp-message-reports&report=$RID"
ok "admin reads the whole reported conversation" $([ "$(has $T/m1.html 'creepy message for nosy')$(has $T/m1.html 'Keeps messaging')$(has $T/m1.html 'Harassment or threats')" = 111 ] && echo 1 || echo 0)
ok "opening it is audited" $([ "$(q "SELECT COUNT(*) FROM $(TBL audit_log) WHERE action='member_report_viewed' AND object_id=$RID")" -ge 1 ] && echo 1 || echo 0)
WPN=$(grep -o 'name="_wpnonce" value="[^"]*"' $T/m1.html | head -1 | cut -d'"' -f4)
curl -s -b $T/mod.jar -o /dev/null "$H/wp-admin/admin-post.php" --data-urlencode "action=cmp_msg_report_close" --data-urlencode "_wpnonce=$WPN" --data-urlencode "report=$RID"
ok "mark as reviewed" $([ "$(q "SELECT status FROM $R_T WHERE id=$RID")" = closed ] && echo 1 || echo 0)

echo "== Admin AI tools left out of member pages (0.15.2)"
mkdir -p wp-content/mu-plugins
cat > wp-content/mu-plugins/zz-fake-angie.php <<'PHP'
<?php
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_script( 'angie-app', 'https://example.invalid/angie.umd.cjs', array(), '1', true );
	wp_add_inline_script( 'angie-app', 'window.zzAngieBefore=1;', 'before' );
	wp_enqueue_script( 'mcp-to-angie-connector', 'https://example.invalid/angie-mcp-init.js', array(), '1', true );
	wp_enqueue_style( 'angie-sidebar-css', 'https://example.invalid/sidebar.css', array(), '1' );
	wp_enqueue_script( 'zz-other-tool', 'https://example.invalid/other.js', array(), '1', true );
} );
PHP
get dana ang1 "$PAGE&cmp_tab=messages"
ok "member page: Angie scripts, inline setup and styles are not loaded" $([ "$(hasnt $T/ang1.html 'angie.umd.cjs')$(hasnt $T/ang1.html 'zzAngieBefore')$(hasnt $T/ang1.html 'angie-mcp-init.js')$(hasnt $T/ang1.html 'sidebar.css')" = 1111 ] && echo 1 || echo 0)
ok "member page: other scripts still load" $(has $T/ang1.html 'example.invalid/other.js')
get dana ang2 "$H/"
ok "other pages: Angie still loads" $(has $T/ang2.html 'angie.umd.cjs')
rm -f wp-content/mu-plugins/zz-fake-angie.php
ok "dynamic \"Your side\" radios aren't stretched by the text-input rule" $(grep -q 'cmp-field input:not(\[type="radio"\]):not(\[type="checkbox"\])' $REPO/community-member-planning/assets/css/member.css && echo 1 || echo 0)

echo "== Privacy"
ok "export lists messages sent and blocks" $(ev "\$j=wp_json_encode(CMP_Account::export('nosy@example.com'),JSON_UNESCAPED_SLASHES); echo false!==strpos(\$j,'Member you blocked') ?1:0;")
ok "erase removes conversations, messages and blocks" $(ev "CMP_Account::erase('carl@example.com'); global \$wpdb; echo 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $C_T WHERE user_a=$CARL OR user_b=$CARL\") && 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $M_T WHERE sender_id=$CARL\") && 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $B_T WHERE blocked_id=$CARL\") ?1:0;")
ok "a report about the erased member is kept" $([ "$(q "SELECT COUNT(*) FROM $R_T WHERE id=$RID")" = 1 ] && echo 1 || echo 0)

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev "global \$wpdb; foreach(array('$C_T','$M_T','$B_T','$R_T','$D_T','$P_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
for u in carl dana erin nosy mod; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
