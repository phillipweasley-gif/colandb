#!/usr/bin/env bash
# End-to-end test of nods (Community Member Planning 0.14.0): sending and
# taking back, the notification and Nods list, mutual nods (both told, and
# messages go straight to the inbox), pronoun-free wording, limits, blocks,
# export/erase.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/nods-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/nod; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -b $T/$1.jar -c $T/$1.jar -o $T/$2.html "$3"; }
nonce(){ php -r 'preg_match("~name=\"action\" value=\"".$argv[2]."\".*?name=\"_cmp_nonce\" value=\"([^\"]+)\"~s",file_get_contents($argv[1]),$m); echo $m[1]??"";' "$1" "$2"; }
nod()  { local u=$1 n=$2 d=$3 m=$4; curl -s -b $T/$u.jar -c $T/$u.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" --data-urlencode "action=cmp_nod" --data-urlencode "_cmp_nonce=$n" --data-urlencode "do=$d" --data-urlencode "member=$m"; }
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
ND_T=$(TBL nods); N_T=$(TBL notifications); B_T=$(TBL blocks); C_T=$(TBL conversations); M_T=$(TBL messages)
ev "global \$wpdb; foreach(array('$ND_T','$B_T','$C_T','$M_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
for u in nora otto pia; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create nora nora@example.com --role=subscriber --user_pass=norapass123456 --display_name="Nora Nodder" >/dev/null
$W user create otto otto@example.com --role=subscriber --user_pass=ottopass123456 --display_name="Otto Other" >/dev/null
$W user create pia pia@example.com --role=subscriber --user_pass=piapass1234567 --display_name="Pia Third" >/dev/null
member nora; member otto; member pia
NORA=$(uid nora); OTTO=$(uid otto); PIA=$(uid pia)
login nora nora norapass123456; login otto otto ottopass123456; login pia pia piapass1234567

echo "== Nodding"
get nora n0 "$PAGE&cmp_member=$OTTO"
NN=$(nonce $T/n0.html cmp_nod)
ok "profile header has a Nod button" $(has $T/n0.html '>Nod</button>')
r=$(nod nora $NN nod $OTTO)
ok "nod sent" $(echo "$r" | grep -q 'nod_sent' && [ "$(q "SELECT COUNT(*) FROM $ND_T WHERE from_id=$NORA AND to_id=$OTTO")" = 1 ] && echo 1 || echo 0)
ok "Otto notified by name: 'Nora Nodder nodded at you.'" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$OTTO AND category='nod' AND message='Nora Nodder nodded at you.'")" = 1 ] && echo 1 || echo 0)
r=$(nod nora $NN nod $OTTO)
ok "again within 24 hours: refused, still one nod and one notification (0.15.1)" $(echo "$r" | grep -q 'nod_wait' && [ "$(q "SELECT COUNT(*) FROM $ND_T")" = 1 ] && [ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$OTTO AND category='nod'")" = 1 ] && echo 1 || echo 0)
get nora n1b "$PAGE&cmp_tab=messages&box=nods"
ok "Nora's Nods: 'You nodded at' lists Otto" $([ "$(has $T/n1b.html 'You nodded at')$(php -r '$h=file_get_contents($argv[1]); $i=strpos($h,"You nodded at"); exit($i!==false && false!==strpos(substr($h,$i),"Otto Other")?0:1);' $T/n1b.html && echo 1 || echo 0)" = 11 ] && echo 1 || echo 0)
get nora n1 "$PAGE&cmp_member=$OTTO"
ok "button now says Nodded" $(has $T/n1.html 'Nodded</button>')
ev "global \$wpdb; \$wpdb->update('$ND_T',array('created_at'=>gmdate('Y-m-d H:i:s',time()-25*HOUR_IN_SECONDS)),array('from_id'=>$NORA,'to_id'=>$OTTO));" >/dev/null
get nora n1c "$PAGE&cmp_member=$OTTO"
ok "after 24 hours the button says Nod again" $(has $T/n1c.html 'Nod again</button>')
r=$(nod nora $NN nod $OTTO)
ok "nodding again after 24 hours works and tells them again" $(echo "$r" | grep -q 'nod_sent' && [ "$(q "SELECT COUNT(*) FROM $ND_T WHERE from_id=$NORA AND to_id=$OTTO")" = 1 ] && [ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$OTTO AND category='nod'")" = 2 ] && echo 1 || echo 0)
r=$(nod nora $NN nod $NORA)
ok "can't nod at yourself" $(echo "$r" | grep -q 'nod_gone' && echo 1 || echo 0)

echo "== Otto's side"
get otto o0 "$PAGE&cmp_tab=messages"
ok "Messages tab counts the new nod; Nods box with a badge" $([ "$(has $T/o0.html 'Messages (1)')$(has $T/o0.html 'cmp_tab=messages&#038;box=nods')" = 11 ] && echo 1 || echo 0)
get otto o1 "$PAGE&cmp_tab=messages&box=nods"
ok "Nods list: Nora, marked new, with Nod back and Message" $([ "$(has $T/o1.html 'Nora Nodder')$(has $T/o1.html 'is-new')$(has $T/o1.html 'Nod back')$(has $T/o1.html "to=$NORA")" = 1111 ] && echo 1 || echo 0)
get otto o2 "$PAGE&cmp_tab=messages&box=nods"
ok "seen once opened" $(hasnt $T/o2.html 'is-new')

echo "== Mutual"
ON=$(nonce $T/o1.html cmp_nod)
r=$(nod otto $ON nod $NORA)
ok "nod back -> mutual" $(echo "$r" | grep -q 'nod_mutual' && echo 1 || echo 0)
ok "both told 'You and <name> both nodded. Say hello?'" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$NORA AND message='You and Otto Other both nodded. Say hello?'")$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$OTTO AND message='You and Nora Nodder both nodded. Say hello?'")" = 11 ] && echo 1 || echo 0)
ok "no pronouns in any nod wording" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE category='nod' AND (message LIKE '% his %' OR message LIKE '% her %' OR message LIKE '% he %' OR message LIKE '% she %' OR message LIKE '% him %')")" = 0 ] && echo 1 || echo 0)
get nora n2 "$PAGE&cmp_member=$OTTO"
ok "button says 'Nodded both ways'" $(has $T/n2.html 'Nodded both ways')
MN=$(nonce $T/n2.html cmp_msg)
curl -s -b $T/nora.jar -o /dev/null "$H/wp-admin/admin-post.php" --data-urlencode "action=cmp_msg" --data-urlencode "_cmp_nonce=$MN" --data-urlencode "do=send" --data-urlencode "to=$OTTO" --data-urlencode "body=hello"
ok "after mutual nods, a message goes straight to the inbox" $([ "$(ev "echo CMP_Messages::between($NORA,$OTTO)->status;")" = open ] && echo 1 || echo 0)

echo "== Taking back, limits, blocks"
r=$(nod nora $NN undo $OTTO)
ok "take a nod back" $(echo "$r" | grep -q 'nod_undone' && [ "$(q "SELECT COUNT(*) FROM $ND_T WHERE from_id=$NORA AND to_id=$OTTO")" = 0 ] && echo 1 || echo 0)
ev "global \$wpdb; for(\$i=0;\$i<30;\$i++) \$wpdb->insert('$ND_T',array('from_id'=>$NORA,'to_id'=>800000+\$i,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
r=$(nod nora $NN nod $PIA)
ok "at most 30 nods a day" $(echo "$r" | grep -q 'nod_limit' && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->query(\"DELETE FROM $ND_T WHERE to_id>=800000\");" >/dev/null
nod nora $NN nod $PIA >/dev/null
get pia p0 "$PAGE&cmp_member=$NORA"
PM=$(nonce $T/p0.html cmp_msg)
curl -s -b $T/pia.jar -o /dev/null "$H/wp-admin/admin-post.php" --data-urlencode "action=cmp_msg" --data-urlencode "_cmp_nonce=$PM" --data-urlencode "do=block" --data-urlencode "member=$NORA"
ok "blocking removes nods both ways" $([ "$(q "SELECT COUNT(*) FROM $ND_T WHERE (from_id=$NORA AND to_id=$PIA) OR (from_id=$PIA AND to_id=$NORA)")" = 0 ] && echo 1 || echo 0)
r=$(nod nora $NN nod $PIA)
ok "...and stops new ones" $(echo "$r" | grep -q 'nod_gone' && echo 1 || echo 0)

echo "== Privacy"
nod otto $ON nod $PIA >/dev/null
ok "export lists nods sent" $(ev "\$j=wp_json_encode(CMP_Account::export('otto@example.com')); echo false!==strpos(\$j,'Nod you sent')?1:0;")
ok "erase removes nods both ways" $(ev "CMP_Account::erase('otto@example.com'); global \$wpdb; echo 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $ND_T WHERE from_id=$OTTO OR to_id=$OTTO\")?1:0;")

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev "global \$wpdb; foreach(array('$ND_T','$B_T','$C_T','$M_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
for u in nora otto pia; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
