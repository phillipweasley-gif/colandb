#!/usr/bin/env bash
# End-to-end test of dynamics between members (Community Member Planning
# 0.6.0): propose / accept / decline / withdraw / end, consent rules, who may
# direct whom, connections, profile display, limits, privacy.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/dynamics-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/dyn; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
# The dynamics form's nonce (profiles also carry message / block forms, 0.11.0).
dnonce(){ php -r 'preg_match("~name=\"action\" value=\"cmp_dyn_[a-z]+\".*?name=\"_cmp_nonce\" value=\"([^\"]+)\"~s",file_get_contents($argv[1]),$m); echo $m[1]??"";' "$1"; }
post() { curl -s -b $T/$1.jar -c $T/$1.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" "${@:2}"; }
uid()  { $W user get $1 --field=ID 2>/dev/null; }
ev()   { $W eval "$1" 2>&1; }
member(){ ev "\$i=get_user_by('login','$1')->ID; update_user_meta(\$i,'cmp_verified_email','$1@example.com'); update_user_meta(\$i,'cmp_email_verified_at',gmdate('Y-m-d H:i:s')); update_user_meta(\$i,'cmp_birth_date','1980-01-01'); CMP_Access::record_attestation(\$i); update_user_meta(\$i,'cmp_setup_done','later');" >/dev/null; }
status(){ ev "global \$wpdb; echo \$wpdb->get_var(\"SELECT status FROM \".CMP_Install::table('dynamics').\" WHERE id=$1\");"; }
lastid(){ ev "global \$wpdb; echo (int)\$wpdb->get_var(\"SELECT MAX(id) FROM \".CMP_Install::table('dynamics'));"; }

rm -rf wp-content/plugins/community-member-planning && cp -r $REPO/community-member-planning wp-content/plugins/
$W plugin activate community-member-planning >/dev/null 2>&1
ev 'CMP_Install::maybe_upgrade();' >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
PAGE_ID=$(ev 'echo (int) CMP_Settings::get("member_page_id");')
PAGE="$H/?page_id=$PAGE_ID"
for u in kh wearer third outsider; do $W user delete $u --yes >/dev/null 2>&1; done
ev 'global $wpdb; $wpdb->query("DELETE FROM ".CMP_Install::table("dynamics"));' >/dev/null
$W user create kh kh@example.com --role=subscriber --user_pass=khpass123456 --display_name="Key Holder" >/dev/null
$W user create wearer wearer@example.com --role=subscriber --user_pass=wearerpass12 --display_name="The Wearer" >/dev/null
$W user create third third@example.com --role=subscriber --user_pass=thirdpass123 >/dev/null
$W user create outsider outsider@example.com --role=subscriber --user_pass=outsiderpass >/dev/null
member kh; member wearer; member third
KH=$(uid kh); WE=$(uid wearer); TH=$(uid third); OUT=$(uid outsider)

echo "== Proposing"
login kh kh khpass123456
get kh p1 "$PAGE&cmp_member=$WE"
ok "profile offers Propose a dynamic with all types" $([ "$(has $T/p1.html 'Propose a dynamic')$(has $T/p1.html 'value="keyholder"')$(has $T/p1.html 'value="friends"')" = 111 ] && echo 1 || echo 0)
N=$(dnonce $T/p1.html)
r=$(post kh -d "action=cmp_dyn_propose&_cmp_nonce=$N&partner=$WE&type=keyholder")
ok "directed type without a side refused" $(echo "$r" | grep -q 'dyn_invalid' && echo 1 || echo 0)
r=$(post kh -d "action=cmp_dyn_propose&_cmp_nonce=$N&partner=$WE&type=not-a-type&side=a")
ok "unknown type refused" $(echo "$r" | grep -q 'dyn_invalid' && echo 1 || echo 0)
r=$(post kh -d "action=cmp_dyn_propose&_cmp_nonce=$N&partner=$KH&type=friends")
ok "can't propose to yourself" $(echo "$r" | grep -q 'dyn_gone' && echo 1 || echo 0)
r=$(post kh -d "action=cmp_dyn_propose&_cmp_nonce=$N&partner=$OUT&type=friends")
ok "can't propose to a non-member" $(echo "$r" | grep -q 'dyn_gone' && echo 1 || echo 0)
LONG=$(head -c 501 /dev/zero | tr '\0' 'a')
r=$(post kh --data-urlencode "action=cmp_dyn_propose" --data-urlencode "_cmp_nonce=$N" --data-urlencode "partner=$WE" --data-urlencode "type=keyholder" --data-urlencode "side=a" --data-urlencode "message=$LONG")
ok "message over 500 refused" $(echo "$r" | grep -q 'dyn_invalid' && echo 1 || echo 0)
r=$(post kh -d "action=cmp_dyn_propose&_cmp_nonce=bad&partner=$WE&type=keyholder&side=a")
ok "bad nonce refused" $([ "$(lastid)" = 0 ] && echo 1 || echo 0)
r=$(post kh --data-urlencode "action=cmp_dyn_propose" --data-urlencode "_cmp_nonce=$N" --data-urlencode "partner=$WE" --data-urlencode "type=keyholder" --data-urlencode "side=a" --data-urlencode "message=As discussed at the munch.")
D1=$(lastid)
ok "invitation sent, pending" $(echo "$r" | grep -q 'dyn_sent' && [ "$(status $D1)" = pending ] && echo 1 || echo 0)
ok "wearer notified (invitation)" $(ev "global \$wpdb; echo (int)\$wpdb->get_var(\"SELECT COUNT(*) FROM \".CMP_Install::table('notifications').\" WHERE user_id=$WE AND category='invitation' AND message LIKE '%Keyholder%'\")>0?1:0;")
r=$(post kh -d "action=cmp_dyn_propose&_cmp_nonce=$N&partner=$WE&type=keyholder&side=b")
ok "same type twice refused" $(echo "$r" | grep -q 'dyn_duplicate' && echo 1 || echo 0)
ok "pending gives no powers yet" $(ev "echo CMP_Dynamics::lead_can_direct($KH,$WE)?0:1;")

echo "== Answering"
login wearer wearer wearerpass12
get wearer w1 "$PAGE&cmp_tab=dynamics"
ok "tab shows Dynamics (1) and the invitation with its message" $([ "$(has $T/w1.html 'Dynamics (1)')$(has $T/w1.html 'As discussed at the munch.')$(has $T/w1.html '<b>Keyholder</b>')" = 111 ] && echo 1 || echo 0)
WN=$(dnonce $T/w1.html)
r=$(post kh -d "action=cmp_dyn_respond&_cmp_nonce=$N&dynamic=$D1&answer=accept")
ok "proposer can't accept their own invitation" $([ "$(status $D1)" = pending ] && echo 1 || echo 0)
r=$(post wearer -d "action=cmp_dyn_withdraw&_cmp_nonce=$WN&dynamic=$D1")
ok "partner can't withdraw it" $([ "$(status $D1)" = pending ] && echo 1 || echo 0)
r=$(post wearer -d "action=cmp_dyn_respond&_cmp_nonce=$WN&dynamic=$D1&answer=accept")
ok "partner accepts -> active" $(echo "$r" | grep -q 'dyn_accepted' && [ "$(status $D1)" = active ] && echo 1 || echo 0)
ok "proposer notified" $(ev "global \$wpdb; echo (int)\$wpdb->get_var(\"SELECT COUNT(*) FROM \".CMP_Install::table('notifications').\" WHERE user_id=$KH AND message LIKE '%accepted%'\")>0?1:0;")
ok "Keyholder can direct the wearer, not the other way round" $(ev "echo CMP_Dynamics::lead_can_direct($KH,$WE,'keyholder') && ! CMP_Dynamics::lead_can_direct($WE,$KH) ?1:0;")
ok "active partners count as connections" $(ev "CMP_Profiles::save_field($WE,'pronouns','he/him','connections'); echo CMP_Profiles::can_view('pronouns',$WE,$KH) && ! CMP_Profiles::can_view('pronouns',$WE,$TH) ?1:0;")
get third t1 "$PAGE&cmp_member=$WE"; login third third thirdpass123; get third t1 "$PAGE&cmp_member=$WE"
ok "profile shows the dynamic to other members" $(grep -q 'cmp-prof-dyn' $T/t1.html && grep -q 'chastity wearer of' $T/t1.html && echo 1 || echo 0)
r=$(post wearer -d "action=cmp_dyn_show&_cmp_nonce=$WN&dynamic=$D1&show=")
get third t2 "$PAGE&cmp_member=$WE"
ok "hidden by one side -> shown on neither profile" $(hasnt $T/t2.html 'cmp-prof-dyn')
r=$(post wearer -d "action=cmp_dyn_show&_cmp_nonce=$WN&dynamic=$D1&show=1")

echo "== Decline, withdraw, end"
r=$(post kh -d "action=cmp_dyn_propose&_cmp_nonce=$N&partner=$WE&type=friends"); D2=$(lastid)
r=$(post wearer -d "action=cmp_dyn_respond&_cmp_nonce=$WN&dynamic=$D2&answer=decline")
ok "decline -> declined, no powers" $([ "$(status $D2)" = declined ] && echo 1 || echo 0)
r=$(post kh -d "action=cmp_dyn_propose&_cmp_nonce=$N&partner=$TH&type=mentor&side=a"); D3=$(lastid)
r=$(post kh -d "action=cmp_dyn_withdraw&_cmp_nonce=$N&dynamic=$D3")
ok "proposer withdraws -> withdrawn" $([ "$(status $D3)" = withdrawn ] && echo 1 || echo 0)
TN=$(dnonce $T/t1.html)
r=$(post third -d "action=cmp_dyn_end&_cmp_nonce=$TN&dynamic=$D1")
ok "a third member can't end someone else's dynamic" $([ "$(status $D1)" = active ] && echo 1 || echo 0)
r=$(post wearer -d "action=cmp_dyn_end&_cmp_nonce=$WN&dynamic=$D1")
ok "wearer ends it alone, at once" $(echo "$r" | grep -q 'dyn_ended' && [ "$(status $D1)" = ended ] && echo 1 || echo 0)
ok "powers gone immediately" $(ev "echo CMP_Dynamics::lead_can_direct($KH,$WE)?0:1;")
ok "Keyholder told it ended" $(ev "global \$wpdb; echo (int)\$wpdb->get_var(\"SELECT COUNT(*) FROM \".CMP_Install::table('notifications').\" WHERE user_id=$KH AND message LIKE '%ended your dynamic%'\")>0?1:0;")
ok "connection gone too" $(ev "echo CMP_Profiles::can_view('pronouns',$WE,$KH)?0:1;")
ok "audit trail: proposed, accepted, ended" $(ev "\$a=wp_list_pluck(CMP_Audit::for_object('dynamic',$D1),'action'); echo in_array('dynamic_proposed',\$a,true)&&in_array('dynamic_accepted',\$a,true)&&in_array('dynamic_ended',\$a,true)?1:0;")
r=$(post kh -d "action=cmp_dyn_propose&_cmp_nonce=$N&partner=$WE&type=keyholder&side=a")
ok "after ending, a new invitation of the same type is allowed" $(echo "$r" | grep -q 'dyn_sent' && echo 1 || echo 0)

echo "== Limits and access"
ev "global \$wpdb; for(\$i=0;\$i<9;\$i++){ \$wpdb->insert(CMP_Install::table('dynamics'),array('type'=>'friends','proposer_id'=>$KH,'partner_id'=>$TH+1000+\$i,'status'=>'pending','created_at'=>gmdate('Y-m-d H:i:s'))); }" >/dev/null
r=$(post kh -d "action=cmp_dyn_propose&_cmp_nonce=$N&partner=$TH&type=play")
ok "11th pending invitation refused (max 10)" $(echo "$r" | grep -q 'dyn_too_many' && echo 1 || echo 0)
login outsider outsider outsiderpass
r=$(post outsider -d "action=cmp_dyn_propose&_cmp_nonce=$N&partner=$WE&type=friends")
ok "non-member can't propose" $(echo "$r" | grep -q 'dyn_sent' && echo 0 || echo 1)
get anon a1 "$PAGE&cmp_tab=dynamics"
ok "signed out: no dynamics shown" $(hasnt $T/a1.html 'Waiting for you')

echo "== Privacy"
ok "export lists the dynamics" $(ev "echo false!==strpos(wp_json_encode(CMP_Account::export('wearer@example.com'),JSON_UNESCAPED_SLASHES),'Keyholder / chastity wearer')?1:0;")
ok "erase removes them" $(ev "CMP_Account::erase('wearer@example.com'); global \$wpdb; echo 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM \".CMP_Install::table('dynamics').\" WHERE proposer_id=$WE OR partner_id=$WE\")?1:0;")

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev 'global $wpdb; $wpdb->query("DELETE FROM ".CMP_Install::table("dynamics"));' >/dev/null
for u in kh wearer third outsider; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
