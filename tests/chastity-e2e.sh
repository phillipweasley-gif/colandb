#!/usr/bin/env bash
# End-to-end test of chastity tracking (Community Member Planning 0.8.0):
# starting a lock (self / with a keyholder), keyholder controls, hidden
# timer, verification codes and photo privacy, hygiene openings, releases,
# emergency unlock, the dynamic ending, the profile badge, privacy.
# Test photos are made with PHP's GD (no ImageMagick needed).
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/chastity-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/cl; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
hnonce(){ grep -o 'name="_cmp_nonce" value="[^"]*"' $1 | head -1 | cut -d'"' -f4; }
# act <user> <nonce> <do> [curl -F args...]: post to the chastity handler, print the redirect.
act()  { local u=$1 n=$2 d=$3; shift 3; curl -s -b $T/$u.jar -c $T/$u.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" -F "action=cmp_chastity" -F "_cmp_nonce=$n" -F "do=$d" "$@"; }
uid()  { $W user get $1 --field=ID 2>/dev/null; }
ev()   { $W eval "$1" 2>&1; }
member(){ ev "\$i=get_user_by('login','$1')->ID; update_user_meta(\$i,'cmp_verified_email','$1@example.com'); update_user_meta(\$i,'cmp_email_verified_at',gmdate('Y-m-d H:i:s')); update_user_meta(\$i,'cmp_birth_date','1980-01-01'); CMP_Access::record_attestation(\$i); update_user_meta(\$i,'cmp_setup_done','later');" >/dev/null; }
q()    { ev "global \$wpdb; echo \$wpdb->get_var(\"$1\");"; }
TBL()  { ev "echo CMP_Install::table('$1');"; }
# lk <id> <field> [field...]: lock fields joined by |
lk()   { local id=$1; shift; local f=""; for x in "$@"; do f="$f.'|'.\$l->$x"; done; ev "\$l=CMP_Chastity::lock($id); echo substr(''$f,1);"; }

rm -rf wp-content/plugins/community-member-planning && cp -r $REPO/community-member-planning wp-content/plugins/
$W plugin activate community-member-planning >/dev/null 2>&1
ev 'CMP_Install::maybe_upgrade();' >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
PAGE_ID=$(ev 'echo (int) CMP_Settings::get("member_page_id");')
PAGE="$H/?page_id=$PAGE_ID"
L_T=$(TBL locks); E_T=$(TBL lock_events); D_T=$(TBL dynamics); N_T=$(TBL notifications)
ev "global \$wpdb; foreach(array('$L_T','$E_T','$D_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
for u in kh pup nosy solo; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create kh kh@example.com --role=subscriber --user_pass=khpass1234567 --display_name="Sample Keyholder" >/dev/null
$W user create pup pup@example.com --role=subscriber --user_pass=puppass123456 --display_name="Sample Pup" >/dev/null
$W user create nosy nosy@example.com --role=subscriber --user_pass=nosypass12345 >/dev/null
$W user create solo solo@example.com --role=subscriber --user_pass=solopass12345 --display_name="Solo Wearer" >/dev/null
member kh; member pup; member nosy; member solo
KH=$(uid kh); PUP=$(uid pup); NOSY=$(uid nosy); SOLO=$(uid solo)
php -r '$i=imagecreatetruecolor(1200,900); imagefill($i,0,0,imagecolorallocate($i,40,40,160)); imagejpeg($i,$argv[1],90); $d=file_get_contents($argv[1]); $exif="Exif\0\0MM\0*\0\0\0\x08GPSLatitude SECRET-GPS-MARKER"; file_put_contents($argv[1],substr($d,0,2)."\xFF\xE1".pack("n",strlen($exif)+2).$exif.substr($d,2));' $T/v.jpg
echo "not an image" > $T/fake.jpg

echo "== Starting a lock"
login pup pup puppass123456
get pup p0 "$PAGE&cmp_tab=chastity"
ok "Chastity tab with the start form; no keyholder yet" $([ "$(has $T/p0.html 'Start a lock')$(has $T/p0.html 'No one (self-lock)')$(has $T/p0.html 'start a dynamic')" = 111 ] && echo 1 || echo 0)
PN=$(hnonce $T/p0.html)
r=$(act pup $PN start -F "keyholder=$KH" -F "option=a" -F "length=10080")
ok "can't pick a keyholder without a dynamic" $(echo "$r" | grep -q 'cl_invalid' && [ "$(q "SELECT COUNT(*) FROM $L_T")" = 0 ] && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->insert('$D_T',array('type'=>'keyholder','proposer_id'=>$KH,'partner_id'=>$PUP,'proposer_side'=>'a','status'=>'active','proposer_show'=>1,'partner_show'=>1,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
DYN=$(q "SELECT MAX(id) FROM $D_T")
get pup p1 "$PAGE&cmp_tab=chastity"
ok "the lead in a dynamic is offered as keyholder" $(has $T/p1.html "<option value=\"$KH\">Sample Keyholder")
ev "global \$wpdb; \$wpdb->insert('$D_T',array('type'=>'mentor','proposer_id'=>$PUP,'partner_id'=>$NOSY,'proposer_side'=>'a','status'=>'active','proposer_show'=>1,'partner_show'=>1,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
get pup p1b "$PAGE&cmp_tab=chastity"
ok "someone the wearer leads is not offered" $(hasnt $T/p1b.html "<option value=\"$NOSY\"")
r=$(act pup $PN start -F "keyholder=0" -F "option=custom" -F "option_name=" -F "policy=none")
ok "custom option needs a name" $(echo "$r" | grep -q 'cl_invalid' && echo 1 || echo 0)
r=$(act pup $PN start -F "keyholder=$KH" -F "option=b" -F "length=10080" -F "photo=@$T/v.jpg;type=image/jpeg")
LID=$(q "SELECT MAX(id) FROM $L_T")
ok "locked with a keyholder: option B, 1 week" $(echo "$r" | grep -q 'cl_started' && [ "$(lk $LID keyholder_id option_key release_policy status)" = "$KH|b|permission|locked" ] && echo 1 || echo 0)
ok "keyholder notified (lock)" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$KH AND category='lock'")" -ge 1 ] && echo 1 || echo 0)
r=$(act pup $PN start -F "keyholder=0" -F "option=a")
ok "only one active lock" $(echo "$r" | grep -q 'cl_busy' && echo 1 || echo 0)
get pup p2 "$PAGE&cmp_tab=chastity"
ok "wearer sees timer, rule, today's code, emergency unlock" $([ "$(has $T/p2.html 'data-cmp-since')$(has $T/p2.html 'One release a week')$(has $T/p2.html 'data-cmp-code')$(has $T/p2.html 'Emergency unlock')" = 1111 ] && echo 1 || echo 0)
ok "time left shown while not hidden" $(has $T/p2.html 'left · ends')

echo "== Keyholder controls"
login kh kh khpass1234567
get kh k0 "$PAGE&cmp_tab=chastity"
ok "keyholder lists the lock they hold" $(has $T/k0.html "lock=$LID")
KN=$(hnonce $T/k0.html)
get kh k1 "$PAGE&cmp_tab=chastity&lock=$LID"
ok "keyholder page: time, rules, unlock" $([ "$(has $T/k1.html 'Add time')$(has $T/k1.html 'Save rules')$(has $T/k1.html 'Unlock now')" = 111 ] && echo 1 || echo 0)
END0=$(ev "echo strtotime(CMP_Chastity::lock($LID)->planned_end.' UTC');")
r=$(act kh $KN time -F "lock=$LID" -F "minutes=1440" -F "direction=add")
END1=$(ev "echo strtotime(CMP_Chastity::lock($LID)->planned_end.' UTC');")
ok "add 1 day" $([ $((END1-END0)) -ge 86390 ] && [ $((END1-END0)) -le 86410 ] && echo 1 || echo 0)
r=$(act kh $KN time -F "lock=$LID" -F "minutes=999" -F "direction=add")
ok "only offered amounts" $(echo "$r" | grep -q 'cl_invalid' && echo 1 || echo 0)
r=$(act kh $KN time -F "lock=$LID" -F "minutes=43200" -F "direction=remove")
END2=$(ev "echo strtotime(CMP_Chastity::lock($LID)->planned_end.' UTC');")
ok "removing more than is left stops at now" $([ $((END2 - $(date +%s))) -le 5 ] && echo 1 || echo 0)
r=$(act kh $KN time -F "lock=$LID" -F "minutes=10080" -F "direction=add")
r=$(act kh $KN rules -F "lock=$LID" -F "option=b" -F "policy=permission" -F "hygiene_minutes=15" -F "hide_timer=1" -F "verify_daily=1" -F "rule=Wear it 24/7.")
ok "rules saved: hidden timer, daily verification" $([ "$(lk $LID hide_timer verify_daily hygiene_minutes)" = "1|1|15" ] && echo 1 || echo 0)
r=$(act kh $KN rules -F "lock=$LID" -F "option=b" -F "policy=sometimes" -F "hygiene_minutes=15")
ok "unknown release policy refused" $(echo "$r" | grep -q 'cl_invalid' && echo 1 || echo 0)
r=$(act kh $KN time -F "lock=$LID" -F "minutes=60" -F "direction=add")
login pup pup puppass123456
get pup p3 "$PAGE&cmp_tab=chastity"
PN=$(hnonce $T/p3.html)
PEND=$(ev "echo wp_date('M j, g:i A', strtotime(CMP_Chastity::lock($LID)->planned_end.' UTC'));")
ok "hidden: wearer sees no end date or time-left" $([ "$(has $T/p3.html 'hidden by your keyholder')$(hasnt $T/p3.html "$PEND")$(hasnt $T/p3.html 'left · ends')" = 111 ] && echo 1 || echo 0)
ok "hidden: time changes and the planned length left out of the wearer's history" $([ "$(hasnt $T/p3.html 'Keyholder added')$(hasnt $T/p3.html 'One release a week · 1 week')" = 11 ] && echo 1 || echo 0)
r=$(act pup $PN time -F "lock=$LID" -F "minutes=60" -F "direction=remove")
ok "wearer can't change time" $(echo "$r" | grep -q 'cl_gone' && echo 1 || echo 0)
login nosy nosy nosypass12345
get nosy n0 "$PAGE&cmp_tab=chastity&lock=$LID"
get nosy n00 "$PAGE&cmp_tab=chastity"
NN=$(hnonce $T/n00.html)
ok "another member can't open the lock" $([ "$(has $T/n0.html 'isn&#039;t available\|isn.t available')$(hasnt $T/n0.html 'Save rules')" = 11 ] && echo 1 || echo 0)
r=$(act nosy $NN kh_unlock -F "lock=$LID")
ok "...or unlock it" $(echo "$r" | grep -q 'cl_gone' && [ "$(q "SELECT status FROM $L_T WHERE id=$LID")" = locked ] && echo 1 || echo 0)

echo "== Verification"
r=$(act pup $PN verify -F "lock=$LID")
ok "verification needs a photo" $(echo "$r" | grep -q 'cl_photo' && echo 1 || echo 0)
r=$(act pup $PN verify -F "lock=$LID" -F "photo=@$T/fake.jpg;type=image/jpeg")
ok "non-image refused" $(echo "$r" | grep -q 'cl_photo' && echo 1 || echo 0)
r=$(act pup $PN verify -F "lock=$LID" -F "photo=@$T/v.jpg;type=image/jpeg")
VID=$(q "SELECT MAX(id) FROM $E_T WHERE type='verification'")
CODE=$(ev "echo CMP_Chastity::code($LID);")
ok "verification stored with today's code" $([ "$(q "SELECT code FROM $E_T WHERE id=$VID")" = "$CODE" ] && echo 1 || echo 0)
ok "code: 6 characters, differs per lock and per day" $(ev "\$a=CMP_Chastity::code($LID); echo 1===preg_match('/^[A-HJ-NP-Z2-9]{6}\$/',\$a) && \$a!==CMP_Chastity::code($LID+1) && \$a!==CMP_Chastity::code($LID,'2001-01-01')?1:0;")
VURL="$H/?cmp_lockpic=$VID"
code_of(){ curl -s -b $T/$1.jar -o $T/pic.$1 -w '%{http_code}' "$VURL"; }
ok "wearer sees the photo" $([ "$(code_of pup)" = 200 ] && echo 1 || echo 0)
ok "keyholder sees it" $([ "$(code_of kh)" = 200 ] && echo 1 || echo 0)
ok "GPS marker gone, resized" $(php -r '$d=file_get_contents($argv[1]); $s=getimagesize($argv[1]); exit("\xFF\xD8\xFF"===substr($d,0,3) && false===strpos($d,"SECRET-GPS-MARKER") && max($s[0],$s[1])<=1600?0:1);' $T/pic.pup && echo 1 || echo 0)
ok "another member: 404" $([ "$(code_of nosy)" = 404 ] && echo 1 || echo 0)
ok "signed out: 404" $([ "$(curl -s -o /dev/null -w '%{http_code}' "$VURL")" = 404 ] && echo 1 || echo 0)
get kh k2 "$PAGE&cmp_tab=chastity&lock=$LID"
ok "keyholder sees the photo and code to review" $([ "$(has $T/k2.html "cmp_lockpic=$VID")$(has $T/k2.html "code $CODE")" = 11 ] && echo 1 || echo 0)
r=$(act kh $KN review -F "lock=$LID" -F "event=$VID" -F "verdict=again")
ok "ask for another -> wearer told" $([ "$(q "SELECT review FROM $E_T WHERE id=$VID")" = again ] && echo 1 || echo 0)
get pup p4 "$PAGE&cmp_tab=chastity"
ok "wearer sees the request" $(has $T/p4.html 'asked for another')

echo "== Hygiene and release"
r=$(act pup $PN hygiene_open -F "lock=$LID")
ok "hygiene opening recorded" $([ -n "$(q "SELECT opened_at FROM $L_T WHERE id=$LID")" ] && echo 1 || echo 0)
r=$(act pup $PN hygiene_open -F "lock=$LID")
ok "can't open twice" $(echo "$r" | grep -q 'cl_invalid' && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->update('$L_T',array('opened_at'=>gmdate('Y-m-d H:i:s',time()-20*60)),array('id'=>$LID));" >/dev/null
r=$(act pup $PN hygiene_close -F "lock=$LID")
ok "relocked after 20 min: flagged over the 15 allowed" $([ "$(q "SELECT review FROM $E_T WHERE lock_id=$LID AND type='hygiene_close' ORDER BY id DESC LIMIT 1")" = over ] && [ -z "$(q "SELECT opened_at FROM $L_T WHERE id=$LID")" ] && echo 1 || echo 0)
r=$(act pup $PN release -F "lock=$LID")
ok "release without permission: recorded, flagged" $([ "$(q "SELECT review FROM $E_T WHERE lock_id=$LID AND type='release' ORDER BY id DESC LIMIT 1")" = against ] && echo 1 || echo 0)
r=$(act kh $KN allow_release -F "lock=$LID")
ok "keyholder allows one" $([ "$(q "SELECT release_allowed FROM $L_T WHERE id=$LID")" = 1 ] && echo 1 || echo 0)
r=$(act pup $PN release -F "lock=$LID" -F "note=thank you")
ok "allowed release not flagged; permission used up" $([ -z "$(q "SELECT review FROM $E_T WHERE lock_id=$LID AND type='release' ORDER BY id DESC LIMIT 1")" ] && [ "$(q "SELECT release_allowed FROM $L_T WHERE id=$LID")" = 0 ] && echo 1 || echo 0)
r=$(act pup $PN ask_unlock -F "lock=$LID" -F "note=please")
ok "ask to unlock notifies the keyholder" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$KH AND message LIKE '%asked to be unlocked%'")" -ge 1 ] && echo 1 || echo 0)
r=$(act pup $PN unlock -F "lock=$LID")
ok "wearer can't do an ordinary unlock of a keyholder lock" $(echo "$r" | grep -q 'cl_invalid' && [ "$(q "SELECT status FROM $L_T WHERE id=$LID")" = locked ] && echo 1 || echo 0)

echo "== Profile badge"
get nosy n1 "$PAGE&cmp_member=$PUP"
ok "badge off by default" $(hasnt $T/n1.html 'cmp-cl-badge')
r=$(act pup $PN wearer_settings -F "lock=$LID" -F "show_profile=1")
get nosy n2 "$PAGE&cmp_member=$PUP"
ok "badge shown once the wearer turns it on" $(has $T/n2.html 'Locked · ')

echo "== Dynamic ends -> self-lock"
ev "global \$wpdb; \$d=CMP_Dynamics::get($DYN); \$wpdb->update('$D_T',array('status'=>'ended'),array('id'=>$DYN)); do_action('cmp_dynamic_ended',\$d,$PUP);" >/dev/null
ok "lock carries on as a self-lock, timer no longer hidden" $([ "$(lk $LID status keyholder_id hide_timer)" = "locked|0|0" ] && echo 1 || echo 0)
ok "former keyholder loses the photo" $([ "$(code_of kh)" = 404 ] && echo 1 || echo 0)
r=$(act kh $KN time -F "lock=$LID" -F "minutes=60" -F "direction=add")
ok "...and the controls" $(echo "$r" | grep -q 'cl_gone' && echo 1 || echo 0)
ok "wearer told" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$PUP AND message LIKE '%now a self-lock%'")" -ge 1 ] && echo 1 || echo 0)

echo "== Emergency unlock"
r=$(act pup $PN emergency -F "lock=$LID")
ok "emergency unlock ends it at once" $(echo "$r" | grep -q 'cl_ended' && [ "$(lk $LID status end_reason)" = "ended|emergency" ] && echo 1 || echo 0)
get pup p5 "$PAGE&cmp_tab=chastity"
ok "past locks + start form again + calendar" $([ "$(has $T/p5.html 'Past locks')$(has $T/p5.html 'emergency unlock')$(has $T/p5.html 'Start a lock')$(has $T/p5.html 'cmp-cl-cal')$(has $T/p5.html 'is-locked')" = 11111 ] && echo 1 || echo 0)
get nosy n3 "$PAGE&cmp_member=$PUP"
ok "badge gone after unlocking" $(hasnt $T/n3.html 'cmp-cl-badge')

echo "== Self-lock"
login solo solo solopass12345
get solo o0 "$PAGE&cmp_tab=chastity"
ON=$(hnonce $T/o0.html)
r=$(act solo $ON start -F "keyholder=0" -F "option=custom" -F "option_name=Weekend" -F "rule=Until Monday" -F "policy=free" -F "length=60")
SID=$(q "SELECT MAX(id) FROM $L_T WHERE wearer_id=$SOLO")
r=$(act solo $ON unlock -F "lock=$SID")
ok "self-lock: no ordinary unlock before the time is up" $(echo "$r" | grep -q 'cl_invalid' && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->update('$L_T',array('planned_end'=>gmdate('Y-m-d H:i:s',time()-60)),array('id'=>$SID));" >/dev/null
r=$(act solo $ON unlock -F "lock=$SID")
ok "self-lock: unlock once the time is up" $([ "$(lk $SID status end_reason)" = "ended|completed" ] && echo 1 || echo 0)

echo "== Privacy"
ok "export lists the lock and its history" $(ev "\$j=wp_json_encode(CMP_Account::export('pup@example.com'),JSON_UNESCAPED_SLASHES); echo false!==strpos(\$j,'One release a week') && false!==strpos(\$j,'Verification photo') && false!==strpos(\$j,'(photo kept)')?1:0;")
ok "erase removes the wearer's locks and history" $(ev "CMP_Account::erase('pup@example.com'); global \$wpdb; echo 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $L_T WHERE wearer_id=$PUP\") && 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $E_T WHERE lock_id=$LID\")?1:0;")

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev "global \$wpdb; foreach(array('$L_T','$E_T','$D_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
for u in kh pup nosy solo; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
