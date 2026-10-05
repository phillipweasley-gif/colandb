#!/usr/bin/env bash
# End-to-end test of homework programs (Community Member Planning 0.7.0):
# who may create and log, proof rules, photo privacy and metadata removal,
# review / missed, weekly quotas, archiving when the dynamic ends, privacy.
# Test photos are made with PHP's GD (no ImageMagick needed).
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/homework-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/hw; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
hnonce(){ grep -o 'name="_cmp_nonce" value="[^"]*"' $1 | head -1 | cut -d'"' -f4; }
post() { curl -s -b $T/$1.jar -c $T/$1.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" "${@:2}"; }
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
P_T=$(TBL programs); T_T=$(TBL tasks); E_T=$(TBL task_entries); D_T=$(TBL dynamics)
ev "global \$wpdb; foreach(array('$P_T','$T_T','$E_T','$D_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
for u in sir boy nosy anon2; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create sir sir@example.com --role=subscriber --user_pass=sirpass123456 --display_name="Sample Sir" >/dev/null
$W user create boy boy@example.com --role=subscriber --user_pass=boypass123456 --display_name="Sample Boy" >/dev/null
$W user create nosy nosy@example.com --role=subscriber --user_pass=nosypass12345 >/dev/null
member sir; member boy; member nosy
SIR=$(uid sir); BOY=$(uid boy); NOSY=$(uid nosy)
# Test photos: a JPEG carrying a fake GPS marker in its EXIF, and a non-image named .jpg.
php -r '$i=imagecreatetruecolor(1200,900); imagefill($i,0,0,imagecolorallocate($i,200,40,160)); imagejpeg($i,$argv[1],90); $d=file_get_contents($argv[1]); $exif="Exif\0\0MM\0*\0\0\0\x08GPSLatitude SECRET-GPS-MARKER"; file_put_contents($argv[1],substr($d,0,2)."\xFF\xE1".pack("n",strlen($exif)+2).$exif.substr($d,2));' $T/proof.jpg
echo "not an image" > $T/fake.jpg
TODAY=$(ev 'echo wp_date("Y-m-d");'); YEST=$(ev 'echo wp_date("Y-m-d", time()-DAY_IN_SECONDS);'); OLD=$(ev 'echo wp_date("Y-m-d", time()-10*DAY_IN_SECONDS);'); TOMORROW=$(ev 'echo wp_date("Y-m-d", time()+DAY_IN_SECONDS);')

echo "== Who can create"
login sir sir sirpass123456
get sir s0 "$PAGE&cmp_tab=homework"
ok "tab explains how to get started" $(has $T/s0.html 'Nobody has set homework for you')
ev "global \$wpdb; \$wpdb->insert('$D_T',array('type'=>'keyholder','proposer_id'=>$SIR,'partner_id'=>$BOY,'proposer_side'=>'a','status'=>'active','proposer_show'=>1,'partner_show'=>1,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
DYN=$(q "SELECT MAX(id) FROM $D_T")
get sir s1 "$PAGE&cmp_tab=homework"
ok "lead sees the member they lead + New program" $([ "$(has $T/s1.html 'Sample Boy')$(has $T/s1.html 'New program')" = 11 ] && echo 1 || echo 0)
N=$(hnonce $T/s1.html)
r=$(post sir -d "action=cmp_hw_program&_cmp_nonce=$N&member=$NOSY&title=Sneaky")
ok "no dynamic with that member -> can't create a program" $([ "$(q "SELECT COUNT(*) FROM $P_T")" = 0 ] && echo 1 || echo 0)
login boy boy boypass123456
r=$(post sir -d "action=cmp_hw_program&_cmp_nonce=$N&member=$BOY&title=")
ok "a name is required" $(echo "$r" | grep -q 'hw_invalid' && echo 1 || echo 0)
r=$(post sir --data-urlencode "action=cmp_hw_program" --data-urlencode "_cmp_nonce=$N" --data-urlencode "member=$BOY" --data-urlencode "title=Fall routine")
PID=$(q "SELECT MAX(id) FROM $P_T")
ok "lead creates the program; member notified (assignment)" $(echo "$r" | grep -q 'hw_created' && [ "$(q "SELECT COUNT(*) FROM $(TBL notifications) WHERE user_id=$BOY AND category='assignment'")" -ge 1 ] && echo 1 || echo 0)

echo "== Tasks"
add(){ post sir --data-urlencode "action=cmp_hw_task" --data-urlencode "_cmp_nonce=$N" --data-urlencode "program=$PID" --data-urlencode "title=$1" --data-urlencode "category=$2" --data-urlencode "weekly_min=$3" --data-urlencode "proof=$4" --data-urlencode "what_counts=$5" --data-urlencode "standard=$6"; }
r=$(add "Bad min" ritual 9 none "" ""); ok "weekly minimum must be 1-7" $(echo "$r" | grep -q 'hw_invalid' && echo 1 || echo 0)
r=$(add "Morning check-in" ritual 2 photo_text "Morning photo plus check-in text" "Good morning, Sir."); T1=$(q "SELECT MAX(id) FROM $T_T")
r=$(add "Reading" education 1 text "5-10 pages" ""); T2=$(q "SELECT MAX(id) FROM $T_T")
r=$(add "Stretching" fitness 1 none "" ""); T3=$(q "SELECT MAX(id) FROM $T_T")
ok "three tasks added" $([ "$(q "SELECT COUNT(*) FROM $T_T WHERE program_id=$PID AND active=1")" = 3 ] && echo 1 || echo 0)
r=$(post sir --data-urlencode "action=cmp_hw_program" --data-urlencode "_cmp_nonce=$N" --data-urlencode "program=$PID" --data-urlencode "title=Fall routine" --data-urlencode "notes=Good week so far." --data-urlencode "ladder[0][level]=Minor miss" --data-urlencode "ladder[0][examples]=Late photo" --data-urlencode "ladder[0][correction]=Written apology")
ok "notes and consequence ladder saved" $(ev "\$p=CMP_Homework::program($PID); echo 'Good week so far.'===\$p->notes && 'Minor miss'===\$p->consequences[0]['level']?1:0;")

echo "== Logging"
get boy b1 "$PAGE&cmp_tab=homework&program=$PID"
ok "member sees tasks, standard phrase, notes, ladder, log form" $([ "$(has $T/b1.html 'Morning check-in')$(has $T/b1.html 'Say: &quot;Good morning, Sir.&quot;')$(has $T/b1.html 'Good week so far.')$(has $T/b1.html 'Minor miss')$(has $T/b1.html 'cmp_hw_log')" = 11111 ] && echo 1 || echo 0)
BN=$(hnonce $T/b1.html)
r=$(post boy -d "action=cmp_hw_program&_cmp_nonce=$BN&member=$SIR&title=Reverse")
ok "the led side can't create one for the lead" $([ "$(q "SELECT COUNT(*) FROM $P_T")" = 1 ] && echo "$r" | grep -q 'hw_gone' && echo 1 || echo 0)
log(){ curl -s -b $T/boy.jar -c $T/boy.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" -F "action=cmp_hw_log" -F "_cmp_nonce=$BN" "$@"; }
r=$(log -F "task=$T1" -F "day=$TODAY" -F "status=done" -F "note=Good morning, Sir.")
ok "photo task without a photo refused" $(echo "$r" | grep -q 'hw_proof' && echo 1 || echo 0)
r=$(log -F "task=$T1" -F "day=$TODAY" -F "status=done" -F "note=Good morning" -F "photo=@$T/fake.jpg;type=image/jpeg")
ok "non-image refused" $(echo "$r" | grep -q 'hw_photo' && echo 1 || echo 0)
r=$(log -F "task=$T1" -F "day=$TODAY" -F "status=done" -F "note=Good morning, Sir." -F "photo=@$T/proof.jpg;type=image/jpeg")
ok "photo + text logged" $(echo "$r" | grep -q 'hw_logged' && echo 1 || echo 0)
EID=$(q "SELECT id FROM $E_T WHERE task_id=$T1 AND day='$TODAY'")
ok "lead notified (submission)" $([ "$(q "SELECT COUNT(*) FROM $(TBL notifications) WHERE user_id=$SIR AND category='submission'")" -ge 1 ] && echo 1 || echo 0)
r=$(log -F "task=$T2" -F "day=$TODAY" -F "status=done")
ok "text task without text refused" $(echo "$r" | grep -q 'hw_proof' && echo 1 || echo 0)
r=$(log -F "task=$T2" -F "day=$YEST" -F "status=done" -F "note=Read chapter 3")
ok "yesterday can be logged" $(echo "$r" | grep -q 'hw_logged' && echo 1 || echo 0)
r=$(log -F "task=$T3" -F "day=$TOMORROW" -F "status=done")
ok "the future can't" $(echo "$r" | grep -q 'hw_invalid' && echo 1 || echo 0)
r=$(log -F "task=$T3" -F "day=$OLD" -F "status=done")
ok "more than 8 days back can't" $(echo "$r" | grep -q 'hw_invalid' && echo 1 || echo 0)
r=$(log -F "task=$T3" -F "day=$TODAY" -F "status=missed")
ok "member can't mark missed" $(echo "$r" | grep -q 'hw_invalid' && echo 1 || echo 0)
r=$(log -F "task=$T3" -F "day=$TODAY" -F "status=moved")
ok "moved logged" $(echo "$r" | grep -q 'hw_logged' && [ "$(q "SELECT status FROM $E_T WHERE task_id=$T3 AND day='$TODAY'")" = moved ] && echo 1 || echo 0)
r=$(curl -s -b $T/sir.jar -c $T/sir.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" -F "action=cmp_hw_log" -F "_cmp_nonce=$N" -F "task=$T3" -F "day=$TODAY" -F "status=done")
ok "the lead can't log for the member" $(echo "$r" | grep -q 'hw_gone' && echo 1 || echo 0)

echo "== Proof photo privacy"
PURL="$H/?cmp_proof=$EID"
code_of(){ curl -s -b $T/$1.jar -o $T/photo.$1 -w '%{http_code}' "$PURL"; }
ok "member sees own photo" $([ "$(code_of boy)" = 200 ] && echo 1 || echo 0)
ok "lead sees it" $([ "$(code_of sir)" = 200 ] && echo 1 || echo 0)
ok "re-encoded JPEG with the GPS marker gone" $(php -r '$d=file_get_contents($argv[1]); exit("\xFF\xD8\xFF"===substr($d,0,3) && false===strpos($d,"SECRET-GPS-MARKER")?0:1);' $T/photo.boy && echo 1 || echo 0)
ok "resized to at most 1600px" $(php -r '$s=getimagesize($argv[1]); exit(max($s[0],$s[1])<=1600?0:1);' $T/photo.boy && echo 1 || echo 0)
login nosy nosy nosypass12345
ok "another member: 404" $([ "$(code_of nosy)" = 404 ] && echo 1 || echo 0)
ok "signed out: 404" $([ "$(curl -s -o /dev/null -w '%{http_code}' "$PURL")" = 404 ] && echo 1 || echo 0)
get nosy n1 "$PAGE&cmp_tab=homework&program=$PID"
ok "another member can't open the program" $([ "$(has $T/n1.html 'isn&#039;t available\|isn.t available')$(hasnt $T/n1.html 'Morning check-in')" = 11 ] && echo 1 || echo 0)

echo "== Review, missed and quotas"
get sir s2 "$PAGE&cmp_tab=homework&program=$PID"
ok "lead sees the proof photo and note to review" $([ "$(has $T/s2.html "cmp_proof=$EID")$(has $T/s2.html 'Good morning, Sir.')$(has $T/s2.html 'name="verdict" value="accepted"')" = 111 ] && echo 1 || echo 0)
r=$(post sir -d "action=cmp_hw_review&_cmp_nonce=$N&program=$PID&task=$T1&day=$TODAY&verdict=rejected&review_note=Face the camera")
ok "not good enough -> doesn't count; member notified" $([ "$(q "SELECT review FROM $E_T WHERE id=$EID")" = rejected ] && [ "$(ev "\$s=CMP_Homework::summary($PID,CMP_Homework::week_start('$TODAY')); echo \$s['tasks'][$T1]['done'];")" = 0 ] && [ "$(q "SELECT COUNT(*) FROM $(TBL notifications) WHERE user_id=$BOY AND category='review_decision'")" -ge 1 ] && echo 1 || echo 0)
r=$(post sir -d "action=cmp_hw_review&_cmp_nonce=$N&program=$PID&task=$T1&day=$TODAY&verdict=accepted")
ok "accepted -> counts" $([ "$(ev "\$s=CMP_Homework::summary($PID,CMP_Homework::week_start('$TODAY')); echo \$s['tasks'][$T1]['done'];")" = 1 ] && echo 1 || echo 0)
r=$(post sir -d "action=cmp_hw_review&_cmp_nonce=$N&program=$PID&task=$T3&day=$YEST&verdict=missed")
ok "lead marks a day missed" $([ "$(q "SELECT status FROM $E_T WHERE task_id=$T3 AND day='$YEST'")" = missed ] && echo 1 || echo 0)
r=$(post boy -d "action=cmp_hw_review&_cmp_nonce=$BN&program=$PID&task=$T1&day=$TODAY&verdict=accepted")
ok "member can't review" $(echo "$r" | grep -q 'hw_gone' && echo 1 || echo 0)
SUMM=$(ev "\$s=CMP_Homework::summary($PID,CMP_Homework::week_start('$YEST')); \$t=CMP_Homework::summary($PID,CMP_Homework::week_start('$TODAY')); echo \$t['total'].'|'.(\$t['tasks'][$T1]['min']);")
ok "weekly maths: 3 tasks, check-in needs 2 a week" $([ "$SUMM" = "3|2" ] && echo 1 || echo 0)
get boy b2 "$PAGE&cmp_tab=homework&program=$PID"
ok "member page shows quotas, the month view, the lead's note" $([ "$(has $T/b2.html 'Quotas met')$(has $T/b2.html 'cmp-hw-heat')" = 11 ] && echo 1 || echo 0)
r=$(post sir -d "action=cmp_hw_retire&_cmp_nonce=$N&program=$PID&task=$T3")
ok "retired task gone from the week, history kept" $([ "$(q "SELECT active FROM $T_T WHERE id=$T3")" = 0 ] && [ "$(q "SELECT COUNT(*) FROM $E_T WHERE task_id=$T3")" -ge 1 ] && echo 1 || echo 0)

echo "== Dynamic ends -> archived"
ev "global \$wpdb; \$d=CMP_Dynamics::get($DYN); \$wpdb->update('$D_T',array('status'=>'ended'),array('id'=>$DYN)); do_action('cmp_dynamic_ended',\$d,$BOY);" >/dev/null
ok "program archived" $([ "$(q "SELECT status FROM $P_T WHERE id=$PID")" = archived ] && echo 1 || echo 0)
ok "lead loses the photo at once" $([ "$(code_of sir)" = 404 ] && echo 1 || echo 0)
get sir s3 "$PAGE&cmp_tab=homework&program=$PID"
ok "lead loses the program" $(hasnt $T/s3.html 'Morning check-in')
get boy b3 "$PAGE&cmp_tab=homework&program=$PID"
ok "member keeps their history, read-only" $([ "$(has $T/b3.html 'Morning check-in')$(has $T/b3.html 'Archived')$(hasnt $T/b3.html 'name="action" value="cmp_hw_log"')" = 111 ] && echo 1 || echo 0)
ok "member still sees own photo" $([ "$(code_of boy)" = 200 ] && echo 1 || echo 0)
r=$(log -F "task=$T2" -F "day=$TODAY" -F "status=done" -F "note=x")
ok "no logging once archived" $(echo "$r" | grep -q 'hw_gone' && echo 1 || echo 0)

echo "== Privacy"
ok "export lists the program and logs (not photo bytes)" $(ev "\$j=wp_json_encode(CMP_Account::export('boy@example.com'),JSON_UNESCAPED_SLASHES); echo false!==strpos(\$j,'Fall routine') && false!==strpos(\$j,'Read chapter 3') && false!==strpos(\$j,'(photo kept)')?1:0;")
ok "erase removes programs, tasks and logs" $(ev "CMP_Account::erase('boy@example.com'); global \$wpdb; echo 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $P_T WHERE member_id=$BOY\") && 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $E_T WHERE member_id=$BOY\")?1:0;")

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev "global \$wpdb; foreach(array('$P_T','$T_T','$E_T','$D_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
for u in sir boy nosy; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
