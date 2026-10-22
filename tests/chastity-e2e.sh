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
ev "global \$wpdb; foreach(array('$L_T','$E_T','$D_T',CMP_Install::table('photo_shares')) as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
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
ok "Chastity tab with the start form; no keyholder yet" $([ "$(has $T/p0.html 'Start a lock')$(has $T/p0.html 'No one (self-lock)')$(has $T/p0.html 'agree chastity with them')" = 111 ] && echo 1 || echo 0)
PN=$(hnonce $T/p0.html)
r=$(act pup $PN start -F "keyholder=$KH" -F "option=a" -F "length=10080")
ok "can't pick a keyholder without a dynamic" $(echo "$r" | grep -q 'cl_invalid' && [ "$(q "SELECT COUNT(*) FROM $L_T")" = 0 ] && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->insert('$D_T',array('type'=>'keyholder','proposer_id'=>$KH,'partner_id'=>$PUP,'proposer_side'=>'a','status'=>'active','proposer_show'=>1,'partner_show'=>1,'created_at'=>gmdate('Y-m-d H:i:s'))); CMP_Dynamics::grant('chastity',$KH,$PUP);" >/dev/null
DYN=$(q "SELECT MAX(id) FROM $D_T")
get pup p1 "$PAGE&cmp_tab=chastity"
ok "the lead in a dynamic is offered as keyholder" $(has $T/p1.html "<option value=\"$KH\">Sample Keyholder")
ev "global \$wpdb; \$wpdb->insert('$D_T',array('type'=>'mentor','proposer_id'=>$PUP,'partner_id'=>$NOSY,'proposer_side'=>'a','status'=>'active','proposer_show'=>1,'partner_show'=>1,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
get pup p1b "$PAGE&cmp_tab=chastity"
ok "someone the wearer leads is not offered" $(hasnt $T/p1b.html "<option value=\"$NOSY\"")
r=$(act pup $PN start -F "keyholder=0" -F "option=custom" -F "option_name=" -F "policy=none")
ok "custom option needs a name" $(echo "$r" | grep -q 'cl_invalid' && echo 1 || echo 0)
ok "start form shows the photo consent tick box (0.32.0)" $(has $T/p1b.html 'name="photo_consent"')
r=$(act pup $PN start -F "keyholder=$KH" -F "option=b" -F "length=10080" -F "photo=@$T/v.jpg;type=image/jpeg")
ok "...a photo without consent is refused and nothing starts" $(echo "$r" | grep -q 'cl_consent' && [ "$(q "SELECT COUNT(*) FROM $L_T WHERE wearer_id=$PUP")" = 0 ] && echo 1 || echo 0)
r=$(act pup $PN start -F "keyholder=$KH" -F "keyholder_name=Ignored Name" -F "option=b" -F "length=10080" -F "photo_consent=1" -F "photo=@$T/v.jpg;type=image/jpeg")
LID=$(q "SELECT MAX(id) FROM $L_T")
ok "locked with a keyholder: option B, 1 week" $(echo "$r" | grep -q 'cl_started' && [ "$(lk $LID keyholder_id option_key release_policy status)" = "$KH|b|permission|locked" ] && echo 1 || echo 0)
ok "keyholder notified (lock)" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$KH AND category='lock'")" -ge 1 ] && echo 1 || echo 0)
r=$(act pup $PN start -F "keyholder=0" -F "option=a")
ok "only one active lock" $(echo "$r" | grep -q 'cl_busy' && echo 1 || echo 0)
get pup p2 "$PAGE&cmp_tab=chastity"
ok "wearer sees timer, rule, today's code, emergency unlock" $([ "$(has $T/p2.html 'data-cmp-since')$(has $T/p2.html 'One release a week')$(has $T/p2.html 'data-cmp-code')$(has $T/p2.html 'Emergency unlock')" = 1111 ] && echo 1 || echo 0)
ok "time left shown while not hidden" $(has $T/p2.html 'left · ends')

ok "with a member keyholder, a typed name is ignored" $([ -z "$(q "SELECT keyholder_name FROM $L_T WHERE wearer_id=$PUP AND status='locked'")" ] && echo 1 || echo 0)

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

echo "== Encryption at rest and private links (CMP 0.31.0)"
S_T=$(TBL photo_shares)
ok "stored photo is encrypted (CMPv1, no JPEG bytes), sha of the JPEG kept" $(ev "global \$wpdb; \$b=\$wpdb->get_var(\"SELECT photo FROM $E_T WHERE id=$VID\"); echo 0===strpos(\$b,'CMPv1:') && false===strpos(\$b,\"\xFF\xD8\xFF\") && 64===strlen(\$wpdb->get_var(\"SELECT photo_sha FROM $E_T WHERE id=$VID\"))?1:0;")
ok "bound to its lock and wearer: opening it as another wearer or lock fails" $(ev "\$e=CMP_Chastity::event($VID); echo null===CMP_Photo_Vault::open(\$e->photo,$LID,$NOSY) && null===CMP_Photo_Vault::open(\$e->photo,$LID+1,$PUP) && \"\xFF\xD8\xFF\"===substr(CMP_Photo_Vault::open(\$e->photo,$LID,$PUP),0,3)?1:0;")
ok "adding CMP_PHOTO_KEY later: older photos still open, new ones use the new key" $(ev "\$e=CMP_Chastity::event($VID); define('CMP_PHOTO_KEY',str_repeat('k',40)); \$n=CMP_Photo_Vault::seal('x',$LID,$PUP); echo \"\xFF\xD8\xFF\"===substr((string)CMP_Photo_Vault::open(\$e->photo,$LID,$PUP),0,3) && 'x'===CMP_Photo_Vault::open(\$n,$LID,$PUP) && substr(\$n,6,8)!==substr(\$e->photo,6,8)?1:0;")
ok "a tampered photo doesn't open" $(ev "\$e=CMP_Chastity::event($VID); \$b=\$e->photo; \$b[60]=chr(ord(\$b[60])^1); echo null===CMP_Photo_Vault::open(\$b,$LID,$PUP)?1:0;")
# A photo stored before 0.31.0 (plain JPEG) is encrypted by the upgrade/clean-up and still shows.
LEG=$(ev "global \$wpdb; \$wpdb->insert('$E_T',array('lock_id'=>$LID,'actor_id'=>$PUP,'type'=>'verification','photo'=>file_get_contents('$T/pic.pup'),'photo_sha'=>hash('sha256',file_get_contents('$T/pic.pup')),'code'=>'OLD','review'=>'accepted','created_at'=>gmdate('Y-m-d H:i:s',time()-60))); echo \$wpdb->insert_id;")
ok "older plain photo: still served before conversion" $([ "$(curl -s -b $T/pup.jar -o /dev/null -w '%{http_code}' "$H/?cmp_lockpic=$LEG")" = 200 ] && echo 1 || echo 0)
ok "seal_existing encrypts it, and it's still served the same" $([ "$(ev 'echo CMP_Photo_Vault::seal_existing();')" -ge 1 ] && [ "$(q "SELECT LEFT(photo,6) FROM $E_T WHERE id=$LEG")" = "CMPv1:" ] && curl -s -b $T/pup.jar -o $T/leg.jpg "$H/?cmp_lockpic=$LEG" && cmp -s $T/leg.jpg $T/pic.pup && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->delete('$E_T',array('id'=>$LEG));" >/dev/null
get pup ph0 "$PAGE&cmp_tab=chastity"
ok "wearer sees 'Your photos' (closed), deletion date and the share form" $([ "$(has $T/ph0.html 'Your photos (')$(has $T/ph0.html '<details><summary class="cmp-panel-title">Your photos')$(has $T/ph0.html 'Deleted ')$(has $T/ph0.html 'value="share"')$(has $T/ph0.html "name=\"event\" value=\"$VID\"")$(has $T/ph0.html 'doesn&#039;t open anything')" = 111111 ] && echo 1 || echo 0)
get kh khp "$PAGE&cmp_tab=chastity"
ok "the keyholder isn't offered share links on their own tab" $(hasnt $T/khp.html "name=\"event\" value=\"$VID\"")
r=$(act nosy $NN share -F "event=$VID" -F "expires=3600" -F "once=1")
ok "another member can't share the wearer's photo" $(echo "$r" | grep -q 'cl_gone' && [ "$(q "SELECT COUNT(*) FROM $S_T")" = 0 ] && echo 1 || echo 0)
r=$(act kh $KN share -F "event=$VID" -F "expires=3600" -F "once=1")
ok "...nor can the keyholder" $(echo "$r" | grep -q 'cl_gone' && [ "$(q "SELECT COUNT(*) FROM $S_T")" = 0 ] && echo 1 || echo 0)
r=$(act pup $PN share -F "event=$VID" -F "expires=999" -F "once=1")
ok "only the offered expiry lengths" $(echo "$r" | grep -q 'cl_invalid' && [ "$(q "SELECT COUNT(*) FROM $S_T")" = 0 ] && echo 1 || echo 0)
r=$(act pup $PN share -F "event=$VID" -F "expires=3600" -F "once=1")
SH1=$(q "SELECT MAX(id) FROM $S_T")
ok "wearer makes a one-time link for 1 hour" $(echo "$r" | grep -q 'cl_shared' && [ "$(ev "global \$wpdb; \$s=\$wpdb->get_row(\"SELECT * FROM $S_T WHERE id=$SH1\"); echo \$s->wearer_id,'|',\$s->event_id,'|',\$s->max_views,'|',(strtotime(\$s->expires_at)-strtotime(\$s->created_at))/60;")" = "$PUP|$VID|1|60" ] && echo 1 || echo 0)
ok "no token stored in the database" $(ev "global \$wpdb; \$s=\$wpdb->get_row(\"SELECT * FROM $S_T WHERE id=$SH1\"); \$u=CMP_Photo_Shares::url(\$s); parse_str(parse_url(\$u,PHP_URL_QUERY),\$a); \$tok=explode('.',\$a['cmp_share'])[1]; echo strlen(\$tok)>=40 && false===strpos(wp_json_encode(\$s),\$tok)?1:0;")
SURL=$(ev "global \$wpdb; echo CMP_Photo_Shares::url(\$wpdb->get_row(\"SELECT * FROM $S_T WHERE id=$SH1\"));")
get pup ph1 "$PAGE&cmp_tab=chastity&photos=1&shared=$SH1"
ok "wearer sees the link to copy, views and Turn off" $([ "$(has $T/ph1.html "${SURL#*cmp_share=}")$(has $T/ph1.html 'cmp-cl-share is-new')$(has $T/ph1.html 'viewed 0 times')$(has $T/ph1.html 'Turn off link')$(has $T/ph1.html '<details open>')" = 11111 ] && echo 1 || echo 0)
curl -s -D $T/sh0.h -o $T/sh0.html "$SURL"; curl -s -o $T/sh0b.html "$SURL"
ok "opening the link (no account) shows a View button, not the photo" $([ "$(has $T/sh0.html 'View photo')$(hasnt $T/sh0.html 'data:image')$(has $T/sh0.html '18 or over')$(has $T/sh0.html 'works once')" = 1111 ] && echo 1 || echo 0)
ok "...noindex, no-store, no-referrer, strict CSP, no framing" $([ "$(grep -ci 'x-robots-tag: noindex' $T/sh0.h)$(grep -ci 'cache-control: no-store' $T/sh0.h)$(grep -ci 'referrer-policy: no-referrer' $T/sh0.h)$(grep -ci "content-security-policy: default-src 'none'" $T/sh0.h)$(grep -ci 'x-frame-options: deny' $T/sh0.h)" = 11111 ] && echo 1 || echo 0)
ok "link previews (GETs) don't use up a one-time link" $([ "$(q "SELECT views FROM $S_T WHERE id=$SH1")" = 0 ] && grep -q 'View photo' $T/sh0b.html && echo 1 || echo 0)
ok "a wrong token: not available (404)" $([ "$(curl -s -o /dev/null -w '%{http_code}' "${SURL%?????}AAAAA")" = 404 ] && [ "$(curl -s -o /dev/null -w '%{http_code}' "$H/?cmp_share=$SH1")" = 404 ] && echo 1 || echo 0)
N1=$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$PUP AND message LIKE '%photo link you shared was just opened%'")
curl -s -o $T/sh1.html -X POST -d "cmp_view=1" "$SURL"
ok "View photo shows it inside the page (data URL), the same JPEG" $(php -r '$h=file_get_contents($argv[1]); preg_match("~data:image/jpeg;base64,([A-Za-z0-9+/=]+)~",$h,$m); exit(isset($m[1]) && base64_decode($m[1])===file_get_contents($argv[2])?0:1);' $T/sh1.html $T/pic.pup && echo 1 || echo 0)
ok "view counted; the wearer is told it was opened" $([ "$(q "SELECT views FROM $S_T WHERE id=$SH1")" = 1 ] && [ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$PUP AND message LIKE '%photo link you shared was just opened%'")" = $((N1+1)) ] && echo 1 || echo 0)
ok "a one-time link works only once" $([ "$(curl -s -o $T/sh2.html -w '%{http_code}' -X POST -d 'cmp_view=1' "$SURL")" = 404 ] && grep -q "isn&#039;t available\|isn't available" $T/sh2.html && ! grep -q 'data:image' $T/sh2.html && echo 1 || echo 0)
r=$(act pup $PN share -F "event=$VID" -F "expires=86400")
SH2=$(q "SELECT MAX(id) FROM $S_T"); SURL2=$(ev "global \$wpdb; echo CMP_Photo_Shares::url(\$wpdb->get_row(\"SELECT * FROM $S_T WHERE id=$SH2\"));")
c1=$(curl -s -o /dev/null -w '%{http_code}' -X POST -d 'cmp_view=1' "$SURL2"); c2=$(curl -s -o /dev/null -w '%{http_code}' -X POST -d 'cmp_view=1' "$SURL2")
ok "a 1-day link without 'once' can be viewed again" $([ "$c1$c2" = 200200 ] && [ "$(ev "global \$wpdb; \$s=\$wpdb->get_row(\"SELECT * FROM $S_T WHERE id=$SH2\"); echo \$s->max_views,'|',\$s->views,'|',(strtotime(\$s->expires_at)-strtotime(\$s->created_at))/3600;")" = "0|2|24" ] && echo 1 || echo 0)
r=$(act nosy $NN unshare -F "share=$SH2")
ok "another member can't turn it off" $([ "$(curl -s -o /dev/null -w '%{http_code}' "$SURL2")" = 200 ] && echo 1 || echo 0)
r=$(act pup $PN unshare -F "share=$SH2")
ok "the wearer turns it off: 404 at once" $(echo "$r" | grep -q 'cl_unshared' && [ "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d 'cmp_view=1' "$SURL2")" = 404 ] && echo 1 || echo 0)
r=$(act pup $PN share -F "event=$VID" -F "expires=604800")
SH3=$(q "SELECT MAX(id) FROM $S_T"); SURL3=$(ev "global \$wpdb; echo CMP_Photo_Shares::url(\$wpdb->get_row(\"SELECT * FROM $S_T WHERE id=$SH3\"));")
ok "a 7-day link works..." $([ "$(curl -s -o /dev/null -w '%{http_code}' "$SURL3")" = 200 ] && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->update('$S_T',array('expires_at'=>gmdate('Y-m-d H:i:s',time()-60)),array('id'=>$SH3));" >/dev/null
ok "...and not once it has expired" $([ "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d 'cmp_view=1' "$SURL3")" = 404 ] && echo 1 || echo 0)
get pup ph2 "$PAGE&cmp_tab=chastity"
ok "used, turned-off and expired links leave the wearer's list" $([ "$(hasnt $T/ph2.html 'Turn off link')" = 1 ] && echo 1 || echo 0)
r=$(act pup $PN share -F "event=$VID" -F "expires=86400")
SH4=$(q "SELECT MAX(id) FROM $S_T"); SURL4=$(ev "global \$wpdb; echo CMP_Photo_Shares::url(\$wpdb->get_row(\"SELECT * FROM $S_T WHERE id=$SH4\"));")
ok "export lists share links (no link itself)" $(ev "\$j=wp_json_encode(CMP_Account::export('pup@example.com'),JSON_UNESCAPED_SLASHES); echo false!==strpos(\$j,'Photo share link') && false===strpos(\$j,'cmp_share=') && false!==strpos(\$j,'(photo kept, encrypted, until')?1:0;")
# Like the live host's CDN, which adds a "cache publicly" hint to every page (CMP 0.31.1).
mkdir -p wp-content/mu-plugins && echo "<?php add_action('send_headers', function () { header('ec-cdn-cache-control: public, max-age=300, s-maxage=604800'); });" > wp-content/mu-plugins/zz-cdn-hint.php
ec(){ curl -s -D - -o /dev/null "$@" | grep -ci '^ec-cdn-cache-control'; }
ok "private photo responses drop the host's public-cache hint (share page, view, wearer photo)" $([ "$(ec "$H/?page_id=$PAGE_ID")$(ec "$SURL4")$(ec -X POST -d cmp_view=1 "$SURL4")$(ec -b $T/pup.jar "$VURL")" = 1000 ] && echo 1 || echo 0)
rm -f wp-content/mu-plugins/zz-cdn-hint.php
ok "Download sends the photo as an attachment" $(curl -s -b $T/pup.jar -D - -o /dev/null "$VURL&dl=1" | grep -qi 'content-disposition: attachment; filename="colandb-lock-' && echo 1 || echo 0)
ok "Your photos has Download and Delete photo" $([ "$(has $T/ph2.html '&#038;dl=1\|&dl=1')$(has $T/ph2.html 'value="unphoto"')" = 11 ] && echo 1 || echo 0)
r=$(act pup $PN share -F "event=$VID" -F "expires=86400"); DSH=$(q "SELECT MAX(id) FROM $S_T")
DEL=$(ev "global \$wpdb; \$j=file_get_contents('$T/pic.pup'); \$wpdb->insert('$E_T',array('lock_id'=>$LID,'actor_id'=>$PUP,'type'=>'verification','photo'=>CMP_Photo_Vault::seal(\$j,$LID,$PUP),'photo_sha'=>hash('sha256',\$j),'code'=>'DEL','created_at'=>gmdate('Y-m-d H:i:s'))); echo \$wpdb->insert_id;")
r=$(act pup $PN share -F "event=$DEL" -F "expires=86400"); DSH2=$(q "SELECT MAX(id) FROM $S_T")
r=$(act kh $KN unphoto -F "event=$DEL")
ok "the keyholder can't delete the wearer's photo" $(echo "$r" | grep -q 'cl_gone' && [ "$(curl -s -b $T/pup.jar -o /dev/null -w '%{http_code}' "$H/?cmp_lockpic=$DEL")" = 200 ] && echo 1 || echo 0)
r=$(act pup $PN unphoto -F "event=$DEL")
ok "the wearer deletes a photo: 404, its links gone, history says 'photo deleted', other links kept" $(echo "$r" | grep -q 'cl_photo_deleted' && [ "$(curl -s -b $T/pup.jar -o /dev/null -w '%{http_code}' "$H/?cmp_lockpic=$DEL")" = 404 ] && [ "$(q "SELECT COUNT(*) FROM $S_T WHERE event_id=$DEL")" = 0 ] && [ "$(q "SELECT COUNT(*) FROM $S_T WHERE id=$DSH")" = 1 ] && ev "\$e=CMP_Chastity::events($LID,100); foreach(\$e as \$x) if(\$x->id==$DEL) echo (new ReflectionMethod('CMP_Chastity','event_text'))->invoke(null,\$x);" | grep -q 'photo deleted' && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->delete('$E_T',array('id'=>$DEL));" >/dev/null # the extra photo would make today count as verified below
ok "the daily code isn't an access code" $([ "$(curl -s -o /dev/null -w '%{http_code}' "$H/?cmp_share=$CODE")$(curl -s -o /dev/null -w '%{http_code}' "$H/?cmp_lockpic=$VID&code=$CODE")" = 404404 ] && echo 1 || echo 0)

echo "== Verified elsewhere (CMP 0.28.0)"
ok "keyholder sees 'Mark a day verified' with a date picker from the lock's first day to today (0.35.0)" $([ "$(has $T/k2.html 'name="do" value="verified_else"')$(has $T/k2.html "type=\"date\" id=\"cmp_cl_vday\" name=\"day\" value=\"$(ev "echo wp_date('Y-m-d');")\" min=\"$(ev "echo wp_date('Y-m-d');")\"")" = 11 ] && echo 1 || echo 0)
r=$(act pup $PN verified_else -F "lock=$LID" -F "day=$(ev "echo wp_date('Y-m-d');")")
ok "the wearer can't mark their own day verified" $([ "$(q "SELECT COUNT(*) FROM $E_T WHERE lock_id=$LID AND type='verified_else'")" = 0 ] && echo 1 || echo 0)
TODAY=$(ev "echo wp_date('Y-m-d');")
N0=$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$PUP")
r=$(act kh $KN verified_else -F "lock=$LID" -F "day=$TODAY" -F "note=Telegram")
ok "keyholder marks today verified elsewhere: recorded with the note, no photo" $(echo "$r" | grep -q 'cl_saved' && [ "$(q "SELECT CONCAT(note,'|',photo_sha,'|',actor_id) FROM $E_T WHERE lock_id=$LID AND type='verified_else' ORDER BY id DESC LIMIT 1")" = "Telegram||$KH" ] && echo 1 || echo 0)
ok "...the wearer is told" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$PUP AND message LIKE '%today as verified (sent outside%'")" = 1 ] && [ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$PUP")" -gt "$N0" ] && echo 1 || echo 0)
r=$(act kh $KN verified_else -F "lock=$LID" -F "day=$TODAY")
ok "...the same day can't be marked twice" $(echo "$r" | grep -q 'cl_verified_day' && [ "$(q "SELECT COUNT(*) FROM $E_T WHERE lock_id=$LID AND type='verified_else'")" = 1 ] && echo 1 || echo 0)
get pup p5 "$PAGE&cmp_tab=chastity"
ok "wearer sees today marked, and it in the history with the note" $([ "$(has $T/p5.html 'Your keyholder marked today as verified')$(has $T/p5.html 'Verified elsewhere (marked by the keyholder; no photo kept)')$(has $T/p5.html 'Telegram')" = 111 ] && echo 1 || echo 0)
OLDSTART=$(q "SELECT started_at FROM $L_T WHERE id=$LID")
ev "global \$wpdb; \$wpdb->update(CMP_Install::table('locks'),array('started_at'=>gmdate('Y-m-d H:i:s',time()-3*DAY_IN_SECONDS)),array('id'=>$LID));" >/dev/null
ok "a lock started 3 days ago offers today and the 3 days before, no earlier" $([ "$(ev "echo count(CMP_Chastity::elsewhere_days(CMP_Chastity::lock($LID)));")" = 4 ] && echo 1 || echo 0)
YDAY=$(ev "echo (new DateTime('yesterday', wp_timezone()))->format('Y-m-d');")
r=$(act kh $KN verified_else -F "lock=$LID" -F "day=$YDAY")
ok "back-dated: yesterday is recorded on yesterday (site time)" $(echo "$r" | grep -q 'cl_saved' && [ "$(ev "global \$wpdb; echo wp_date('Y-m-d', strtotime(\$wpdb->get_var(\"SELECT created_at FROM $E_T WHERE lock_id=$LID AND type='verified_else' ORDER BY id DESC LIMIT 1\").' UTC'));")" = "$YDAY" ] && echo 1 || echo 0)
get pup p6 "$PAGE&cmp_tab=chastity"
YLAB=$(ev "echo wp_date('l, F j', strtotime('$YDAY 12:00:00 '.wp_timezone_string()));")
ok "...and gets a gold star on the wearer's calendar beside Verification (0.35.0)" $([ "${YDAY:0:7}" != "${TODAY:0:7}" ] || grep -q "class=\"is-done[^\"]*\" title=\"$YLAB: verified\"" $T/p6.html && echo 1 || echo 0)
D2=$(ev "echo (new DateTime('-2 days', wp_timezone()))->format('Y-m-d');")
ev "global \$wpdb; \$wpdb->insert(CMP_Install::table('lock_events'),array('lock_id'=>$LID,'actor_id'=>$PUP,'type'=>'verification','code'=>'TEST22','review'=>'','created_at'=>(new DateTime('$D2 10:00:00', wp_timezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')));" >/dev/null
r=$(act kh $KN verified_else -F "lock=$LID" -F "day=$D2")
ok "a day with a photo waiting for review can't be marked (accept the photo instead)" $(echo "$r" | grep -q 'cl_verified_day' && echo 1 || echo 0)
D8=$(ev "echo (new DateTime('-8 days', wp_timezone()))->format('Y-m-d');")
r=$(act kh $KN verified_else -F "lock=$LID" -F "day=$D8")
ok "a day before the lock started is refused" $(echo "$r" | grep -q 'cl_invalid' && echo 1 || echo 0)
r=$(act kh $KN review -F "lock=$LID" -F "event=$(q "SELECT MAX(id) FROM $E_T WHERE lock_id=$LID AND type='verified_else'")" -F "verdict=accepted")
ok "a 'verified elsewhere' mark isn't a photo to review" $(echo "$r" | grep -q 'cl_invalid' && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->delete(CMP_Install::table('lock_events'),array('lock_id'=>$LID,'code'=>'TEST22')); \$wpdb->update(CMP_Install::table('locks'),array('started_at'=>'$OLDSTART'),array('id'=>$LID));" >/dev/null

echo "== Correcting any day, the star calendar, milestone stars (CMP 0.35.0)"
ev "global \$wpdb; \$wpdb->update(CMP_Install::table('locks'),array('started_at'=>gmdate('Y-m-d H:i:s',time()-20*DAY_IN_SECONDS-3600)),array('id'=>$LID));" >/dev/null
dago(){ ev "echo (new DateTime('-$1 days', wp_timezone()))->format('Y-m-d');"; }
D14=$(dago 14); D10=$(dago 10); D25=$(dago 25); TOMORROW=$(ev "echo (new DateTime('tomorrow', wp_timezone()))->format('Y-m-d');")
get kh kc1 "$PAGE&cmp_tab=chastity&lock=$LID"
ok "keyholder: calendar beside Verification, missed days link to the correction (two weeks back too)" $([ "$(has $T/kc1.html 'class="cmp-cl-verify-cal"')$(has $T/kc1.html "vday=$D14")$(has $T/kc1.html "min=\"$(dago 20)\"")" = 111 ] && echo 1 || echo 0)
get kh kc2 "$PAGE&cmp_tab=chastity&lock=$LID&vday=$D10"
ok "tapping a day opens 'Mark a day verified' with that date filled in" $([ "$(has $T/kc2.html 'id="cmp-cl-elsewhere" open')$(has $T/kc2.html "name=\"day\" value=\"$D10\"")" = 11 ] && echo 1 || echo 0)
r=$(act kh $KN verified_else -F "lock=$LID" -F "day=$D14" -F "note=Signal")
ok "a day two weeks back can be marked verified, recorded on that day" $(echo "$r" | grep -q 'cl_saved' && [ "$(ev "global \$wpdb; echo wp_date('Y-m-d', strtotime(\$wpdb->get_var(\"SELECT created_at FROM $E_T WHERE lock_id=$LID AND type='verified_else' AND note='Signal'\").' UTC'));")" = "$D14" ] && echo 1 || echo 0)
r=$(act kh $KN verified_else -F "lock=$LID" -F "day=$D25")
ok "before the lock started: refused" $(echo "$r" | grep -q 'cl_invalid' && echo 1 || echo 0)
r=$(act kh $KN verified_else -F "lock=$LID" -F "day=$TOMORROW")
ok "a future day: refused" $(echo "$r" | grep -q 'cl_invalid' && echo 1 || echo 0)
r=$(act pup $PN verified_else -F "lock=$LID" -F "day=$D10")
ok "the wearer can't mark days" $(echo "$r" | grep -q 'cl_gone' && [ "$(q "SELECT COUNT(*) FROM $E_T WHERE lock_id=$LID AND type='verified_else' AND created_at < '$(ev "echo gmdate('Y-m-d H:i:s', time()-9*DAY_IN_SECONDS);")' AND created_at > '$(ev "echo gmdate('Y-m-d H:i:s', time()-11*DAY_IN_SECONDS);")'")" = 0 ] && echo 1 || echo 0)
ok "history is in date order: the back-dated correction sits below newer days, not on top" $(ev "\$e=CMP_Chastity::events($LID,100); \$c=array_map(function(\$x){return \$x->created_at;},\$e); \$s=\$c; rsort(\$s); echo \$c===\$s && 'Signal'!==\$e[0]->note?1:0;")
D14L=$(ev "echo wp_date('l, F j', strtotime('$D14 12:00:00 '.wp_timezone_string()));")
get kh kc3 "$PAGE&cmp_tab=chastity&lock=$LID&cmp_cm=${D14:0:7}"
ok "the corrected day now has a gold star; the month shows how many days count" $([ "$(grep -c "title=\"$D14L: verified\"" $T/kc3.html)$(has $T/kc3.html 'days verified')" = 11 ] && echo 1 || echo 0)
get kh kc4 "$PAGE&cmp_tab=chastity&lock=$LID&cmp_cm=1999-01"
ok "months outside the lock aren't offered (clamped to the lock)" $(hasnt $T/kc4.html '1999')
get pup kc5 "$PAGE&cmp_tab=chastity"
ok "the wearer sees the star calendar but can't mark days from it" $([ "$(has $T/kc5.html 'class="cmp-cl-verify-cal"')$(hasnt $T/kc5.html 'vday=')" = 11 ] && echo 1 || echo 0)
ms(){ ev "\$l=(object)array('started_at'=>gmdate('Y-m-d H:i:s',time()-$1),'status'=>'ended','ended_at'=>gmdate('Y-m-d H:i:s')); echo implode(',',CMP_Chastity::milestones(\$l)),'|',CMP_Chastity::milestone_label(CMP_Chastity::milestones(\$l));"; }
ok "stars: 5 days none; week 1 bronze, week 2 emerald, week 3 ruby (one star each)" $([ "$(ms '5*DAY_IN_SECONDS')/$(ms '8*DAY_IN_SECONDS')/$(ms '15*DAY_IN_SECONDS')/$(ms '22*DAY_IN_SECONDS')" = "0,0,0,0,0,0,0|/0,0,0,0,0,0,1|1 week/0,0,0,0,0,1,0|2 weeks/0,0,0,0,1,0,0|3 weeks" ] && echo 1 || echo 0)
ok "stars: 35 days 1 gold; 75 days 2 gold; 6 months a sapphire; 7 months sapphire + gold" $([ "$(ms '35*DAY_IN_SECONDS')/$(ms '75*DAY_IN_SECONDS')/$(ms '186*DAY_IN_SECONDS')/$(ms '216*DAY_IN_SECONDS')" = "0,0,0,1,0,0,0|1 month/0,0,0,2,0,0,0|2 months/0,0,1,0,0,0,0|6 months/0,0,1,1,0,0,0|7 months" ] && echo 1 || echo 0)
ok "stars: 400 days 1 platinum + 1 gold; 3 years 40 days 1 diamond + 1 gold; 7 years 2 diamonds + 1 platinum" $([ "$(ms '400*DAY_IN_SECONDS')/$(ms '(3*365+1+40)*DAY_IN_SECONDS')/$(ms '(7*365+2+5)*DAY_IN_SECONDS')" = "0,1,0,1,0,0,0|1 year, 1 month/1,0,0,1,0,0,0|3 years, 1 month/2,1,0,0,0,0,0|7 years" ] && echo 1 || echo 0)
ok "the timer shows an emerald star at 20 days and when the next comes (a ruby at 3 weeks)" $([ "$(grep -o 'cmp-star is-emerald' $T/kc1.html | wc -l | tr -d ' ')" -ge 1 ] && grep -q 'Next: a ruby star at 3 weeks' $T/kc1.html && grep -q 'aria-label="Stars: 2 weeks locked"' $T/kc1.html && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->delete(CMP_Install::table('lock_events'),array('lock_id'=>$LID,'note'=>'Signal')); \$wpdb->update(CMP_Install::table('locks'),array('started_at'=>'$OLDSTART'),array('id'=>$LID));" >/dev/null

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
LKV=$(lk $LID option_key release_policy hygiene_minutes); IFS='|' read OPT POL HYG <<< "$LKV"
r=$(act kh $KN rules -F "lock=$LID" -F "option=$OPT" -F "policy=$POL" -F "hygiene_minutes=$HYG" -F "kh_show=1")
get nosy n2 "$PAGE&cmp_member=$PUP"
ok "badge shown once the wearer turns it on, naming the keyholder (0.25.0)" $([ "$(has $T/n2.html 'Locked by <a href=')$(has $T/n2.html "cmp_member=$KH")$(has $T/n2.html 'Sample Keyholder</a> · ')" = 111 ] && echo 1 || echo 0)
get nosy n2k "$PAGE&cmp_member=$KH"
ok "keyholder's profile: Holds the key for the wearer, linked" $([ "$(has $T/n2k.html 'Holds the key for <a href=')$(has $T/n2k.html "cmp_member=$PUP")$(has $T/n2k.html 'cmp-cl-badge is-key')" = 111 ] && echo 1 || echo 0)
echo "== Last verified on the badges (CMP 0.29.0)"
BADGE(){ ev "wp_set_current_user($NOSY); echo CMP_Chastity::profile_badge_html($1);"; }
ok "wearer's badge: last verified today, elsewhere, with where it was sent" $(BADGE $PUP | grep -q 'last verified today · elsewhere (Telegram)' && echo 1 || echo 0)
ok "keyholder's badge shows the same" $(BADGE $KH | grep -q 'Holds the key for .*last verified today · elsewhere (Telegram)' && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->insert(CMP_Install::table('lock_events'),array('lock_id'=>$LID,'actor_id'=>$PUP,'type'=>'verification','code'=>'TEST29','review'=>'','created_at'=>gmdate('Y-m-d H:i:s',time()+60)));" >/dev/null
ok "a photo the keyholder hasn't accepted isn't proof yet" $(BADGE $PUP | grep -q 'elsewhere (Telegram)' && echo 1 || echo 0)
ok "...but on a self-lock any photo sent counts" $([ "$(ev "\$l=CMP_Chastity::lock($LID); \$l->keyholder_id=0; echo CMP_Chastity::last_verified(\$l)->type;")" = verification ] && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->update(CMP_Install::table('lock_events'),array('review'=>'accepted'),array('code'=>'TEST29'));" >/dev/null
ok "once accepted, the badge says photo" $(BADGE $PUP | grep -q 'last verified today · photo' && echo 1 || echo 0)
ok "the photo itself is never on the badge" $(BADGE $PUP | grep -q 'cmp_lockpic' && echo 0 || echo 1)
ok "never verified: no 'last verified' text" $([ -z "$(ev "\$l=CMP_Chastity::lock($LID); \$l->id=987654; echo CMP_Chastity::last_verified(\$l) ? 'x' : '';")" ] && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->delete(CMP_Install::table('lock_events'),array('code'=>'TEST29'));" >/dev/null
get kh k2b "$PAGE&cmp_tab=chastity&lock=$LID"
ok "keyholder has a switch for their name" $(has $T/k2b.html 'name="kh_show"')
r=$(act kh $KN rules -F "lock=$LID" -F "option=$OPT" -F "policy=$POL" -F "hygiene_minutes=$HYG")
get nosy n2c "$PAGE&cmp_member=$PUP"; get nosy n2d "$PAGE&cmp_member=$KH"
ok "keyholder switches it off: wearer badge without a name, nothing on the keyholder's profile" $([ "$(lk $LID kh_show)" = 0 ] && [ "$(has $T/n2c.html 'Locked · ')$(hasnt $T/n2c.html 'Locked by')$(hasnt $T/n2d.html 'Holds the key')" = 111 ] && echo 1 || echo 0)
r=$(act kh $KN rules -F "lock=$LID" -F "option=$OPT" -F "policy=$POL" -F "hygiene_minutes=$HYG" -F "kh_show=1")
ev "global \$wpdb; \$wpdb->insert(CMP_Install::table('blocks'),array('blocker_id'=>$KH,'blocked_id'=>$NOSY,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
get nosy n2e "$PAGE&cmp_member=$PUP"
ok "a member in a block with the keyholder doesn't see their name" $([ "$(has $T/n2e.html 'Locked · ')$(hasnt $T/n2e.html 'Locked by')" = 11 ] && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->delete(CMP_Install::table('blocks'),array('blocker_id'=>$KH,'blocked_id'=>$NOSY));" >/dev/null

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

echo "== Keyholder without an account (0.19.2)"
get solo n0 "$PAGE&cmp_tab=chastity"
ok "start form offers 'Someone without an account' and a name field" $([ "$(has $T/n0.html 'value="named"')$(has $T/n0.html 'name="keyholder_name"')" = 11 ] && echo 1 || echo 0)
NN=$(hnonce $T/n0.html)
r=$(act solo $NN start -F "keyholder=named" -F "keyholder_name=" -F "option=a")
ok "no name typed -> asked for one, no lock" $(echo "$r" | grep -q 'cl_kh_name' && [ -z "$(q "SELECT id FROM $L_T WHERE wearer_id=$SOLO AND status='locked'")" ] && echo 1 || echo 0)
before=$(q "SELECT COUNT(*) FROM $N_T")
r=$(act solo $NN start -F "keyholder=named" -F "keyholder_name=Alex <b>R.</b>" -F "option=a" -F "length=10080")
NID=$(q "SELECT MAX(id) FROM $L_T WHERE wearer_id=$SOLO")
ok "lock started with the typed name, no member keyholder" $(echo "$r" | grep -q 'cl_started' && [ "$(lk $NID keyholder_id keyholder_name)" = "0|Alex R." ] && echo 1 || echo 0)
ok "nobody is notified (they have no account)" $([ "$(q "SELECT COUNT(*) FROM $N_T")" = "$before" ] && echo 1 || echo 0)
get solo n1 "$PAGE&cmp_tab=chastity"
ok "My lock shows 'Keyholder: Alex R. (no account)'" $(has $T/n1.html 'Keyholder: Alex R. (no account)')
ok "wearer keeps the self-lock controls (verify reminder, name field)" $([ "$(has $T/n1.html 'cmp_cl_vd')$(has $T/n1.html 'value="Alex R."')" = 11 ] && echo 1 || echo 0)
ok "the name isn't copied into the audit log" $(ev "global \$wpdb; echo false===strpos((string)\$wpdb->get_var(\"SELECT new_value FROM \".CMP_Install::table('audit_log').\" WHERE action='lock_started' AND object_id=$NID\"),'Alex')?1:0;")
r=$(act solo $NN wearer_settings -F "lock=$NID" -F "keyholder_name=Alex Rivera")
ok "wearer can change the name" $([ "$(lk $NID keyholder_name)" = "Alex Rivera" ] && echo 1 || echo 0)
ok "export includes it" $(ev "echo false!==strpos(wp_json_encode(CMP_Account::export('solo@example.com')),'Alex Rivera')?1:0;")
r=$(act solo $NN wearer_settings -F "lock=$NID" -F "keyholder_name=")
get solo n2 "$PAGE&cmp_tab=chastity"
ok "clearing the name makes it a plain self-lock" $([ "$(lk $NID keyholder_name)" = "" ] && [ "$(has $T/n2.html 'Self-lock')" = 1 ] && echo 1 || echo 0)
r=$(act solo $NN emergency -F "lock=$NID")
ok "wearer can end it" $([ "$(lk $NID status)" = "ended" ] && echo 1 || echo 0)
r=$(act nosy $(hnonce $T/n0.html) wearer_settings -F "lock=$NID" -F "keyholder_name=Hacked")
ok "another member can't change someone's lock" $([ "$(lk $NID keyholder_name)" = "" ] && echo 1 || echo 0)

echo "== Keyholder sets up a lock (0.24.0)"
get kh s0 "$PAGE&cmp_tab=chastity"
ok "no Set up form without a chastity dynamic" $(hasnt $T/s0.html 'Set up a lock for someone')
SN=$(hnonce $T/s0.html)
r=$(act kh $SN propose -F "wearer=$SOLO" -F "option=a" -F "length=1440" -F "hygiene_minutes=15")
ok "can't set up a lock for someone whose key you don't hold" $(echo "$r" | grep -q 'cl_invalid' && [ "$(q "SELECT COUNT(*) FROM $L_T WHERE wearer_id=$SOLO AND status='proposed'")" = 0 ] && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->insert('$D_T',array('type'=>'keyholder','proposer_id'=>$KH,'partner_id'=>$SOLO,'proposer_side'=>'a','status'=>'active','proposer_show'=>1,'partner_show'=>1,'created_at'=>gmdate('Y-m-d H:i:s'))); CMP_Dynamics::grant('chastity',$KH,$SOLO);" >/dev/null
DYN2=$(q "SELECT MAX(id) FROM $D_T")
get kh s1 "$PAGE&cmp_tab=chastity"
ok "keyholder sees Set up a lock with the wearer offered" $([ "$(has $T/s1.html 'Set up a lock for someone')$(grep -o 'id="cmp_cl_pw"[^/]*' $T/s1.html | grep -q "value=\"$SOLO\"" && echo 1 || echo 0)" = 11 ] && echo 1 || echo 0)
r=$(act kh $SN propose -F "wearer=$SOLO" -F "option=custom" -F "option_name=" -F "length=1440" -F "hygiene_minutes=15")
ok "custom without a name is refused" $(echo "$r" | grep -q 'cl_invalid' && echo 1 || echo 0)
r=$(act kh $SN propose -F "wearer=$SOLO" -F "option=b" -F "length=4320" -F "hygiene_minutes=30" -F "hide_timer=1" -F "verify_daily=1" -F "ask_badge=1" -F "kh_show=1" -F "note=Starting tonight")
PID=$(q "SELECT MAX(id) FROM $L_T WHERE wearer_id=$SOLO AND status='proposed'")
ok "proposal saved, nothing locked yet" $(echo "$r" | grep -q 'cl_proposed' && [ -n "$PID" ] && [ "$(lk $PID keyholder_id hide_timer verify_daily hygiene_minutes)" = "$KH|1|1|30" ] && [ -z "$(q "SELECT id FROM $L_T WHERE wearer_id=$SOLO AND status='locked'")" ] && echo 1 || echo 0)
ok "wearer told" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$SOLO AND message LIKE '%set up a lock for you%'")" -ge 1 ] && echo 1 || echo 0)
r=$(act kh $SN propose -F "wearer=$SOLO" -F "option=a" -F "length=60" -F "hygiene_minutes=15")
ok "only one waiting proposal per wearer" $(echo "$r" | grep -q 'cl_pending' && [ "$(q "SELECT COUNT(*) FROM $L_T WHERE wearer_id=$SOLO AND status='proposed'")" = 1 ] && echo 1 || echo 0)
get kh s2 "$PAGE&cmp_tab=chastity"
ok "keyholder sees it waiting, with Withdraw" $([ "$(has $T/s2.html 'waiting for them to accept')$(has $T/s2.html 'value="withdraw"')" = 11 ] && echo 1 || echo 0)
ok "keyholder has no controls before acceptance" $(r=$(act kh $SN time -F "lock=$PID" -F "minutes=60" -F "direction=add"); echo "$r" | grep -q 'cl_gone' && [ -z "$(lk $PID planned_end)" ] && echo 1 || echo 0)
get solo w0 "$PAGE&cmp_tab=chastity"
ok "wearer sees the proposal with its terms, length hidden" $([ "$(has $T/w0.html 'Sample Keyholder set up a lock for you')$(has $T/w0.html 'One release a week')$(has $T/w0.html 'Hidden by your keyholder')$(has $T/w0.html 'Starting tonight')$(has $T/w0.html 'Accept and lock')$(has $T/w0.html 'Emergency unlock is always available')" = 111111 ] && echo 1 || echo 0)
ok "badge request pre-ticks the wearer's switch, naming the keyholder" $([ "$(has $T/w0.html 'would like the badge shown')$(grep -o '<input type="checkbox" id="cmp_cl_ashow"[^>]*' $T/w0.html | grep -c checked)$(has $T/w0.html 'Locked by Sample Keyholder')" = 111 ] && echo 1 || echo 0)
ok "wearer doesn't see the length (3 days) in the proposal" $(perl -0ne 'print $1 if /cmp-cl-proposal(.*?)<\/section>/s' $T/w0.html | grep -q '3 days' && echo 0 || echo 1)
WN=$(hnonce $T/w0.html)
r=$(act nosy $(hnonce $T/n0.html) accept -F "lock=$PID")
ok "another member can't accept it" $([ "$(lk $PID status)" = "proposed" ] && echo 1 || echo 0)
r=$(act solo $WN accept -F "lock=$PID" -F "show_profile=1")
ok "accepting starts the lock with the keyholder's settings" $(echo "$r" | grep -q 'cl_accepted' && [ "$(lk $PID status keyholder_id option_key hide_timer show_profile)" = "locked|$KH|b|1|1" ] && echo 1 || echo 0)
ok "planned end is 3 days from acceptance" $(ev "\$l=CMP_Chastity::lock($PID); \$d=strtotime(\$l->planned_end.' UTC')-time(); echo (\$d>4320*60-120 && \$d<=4320*60)?1:0;")
ok "keyholder told" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$KH AND message LIKE '%accepted your lock%'")" -ge 1 ] && echo 1 || echo 0)
get kh s3 "$PAGE&cmp_tab=chastity&lock=$PID"
ok "keyholder now manages it" $([ "$(has $T/s3.html "Solo Wearer&#039;s lock")$(has $T/s3.html 'Add time')" = 11 ] && echo 1 || echo 0)
get solo w1 "$PAGE&cmp_tab=chastity"
ok "wearer: My lock, timer hidden, emergency unlock there" $([ "$(has $T/w1.html 'Keyholder: Sample Keyholder')$(has $T/w1.html 'hidden by your keyholder')$(has $T/w1.html 'value="emergency"')" = 111 ] && echo 1 || echo 0)
r=$(act solo $WN emergency -F "lock=$PID")
ok "emergency unlock still works" $([ "$(lk $PID status end_reason)" = "ended|emergency" ] && echo 1 || echo 0)

r=$(act kh $SN propose -F "wearer=$SOLO" -F "option=a" -F "length=60" -F "hygiene_minutes=0")
P2=$(q "SELECT MAX(id) FROM $L_T WHERE wearer_id=$SOLO AND status='proposed'")
r=$(act solo $WN decline -F "lock=$P2")
ok "decline removes it and tells the keyholder" $(echo "$r" | grep -q 'cl_declined' && [ "$(q "SELECT COUNT(*) FROM $L_T WHERE id=$P2")" = 0 ] && [ "$(q "SELECT COUNT(*) FROM $E_T WHERE lock_id=$P2")" = 0 ] && [ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$KH AND message LIKE '%declined the lock%'")" -ge 1 ] && echo 1 || echo 0)
r=$(act kh $SN propose -F "wearer=$SOLO" -F "option=a" -F "length=60" -F "hygiene_minutes=0")
P3=$(q "SELECT MAX(id) FROM $L_T WHERE wearer_id=$SOLO AND status='proposed'")
r=$(act solo $WN withdraw -F "lock=$P3")
ok "the wearer can't withdraw" $([ "$(lk $P3 status)" = "proposed" ] && echo 1 || echo 0)
r=$(act kh $SN withdraw -F "lock=$P3")
ok "keyholder can withdraw; wearer told" $(echo "$r" | grep -q 'cl_withdrawn' && [ "$(q "SELECT COUNT(*) FROM $L_T WHERE id=$P3")" = 0 ] && [ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$SOLO AND message LIKE '%withdrew the lock%'")" -ge 1 ] && echo 1 || echo 0)
r=$(act solo $WN start -F "keyholder=0" -F "option=a" -F "length=60")
OWN=$(q "SELECT MAX(id) FROM $L_T WHERE wearer_id=$SOLO AND status='locked'")
r=$(act kh $SN propose -F "wearer=$SOLO" -F "option=a" -F "length=60" -F "hygiene_minutes=0")
ok "no proposal while they're already locked (says they can hand it over, 0.30.0)" $(echo "$r" | grep -q 'cl_pending_lock' && echo 1 || echo 0)
r=$(act solo $WN emergency -F "lock=$OWN")
r=$(act kh $SN propose -F "wearer=$SOLO" -F "option=a" -F "length=60" -F "hygiene_minutes=0")
P4=$(q "SELECT MAX(id) FROM $L_T WHERE wearer_id=$SOLO AND status='proposed'")
ev "global \$wpdb; \$d=CMP_Dynamics::get($DYN2); \$wpdb->update('$D_T',array('status'=>'ended'),array('id'=>$DYN2)); do_action('cmp_dynamic_ended',\$d,$SOLO);" >/dev/null
ok "dynamic ending drops a waiting proposal" $([ "$(q "SELECT COUNT(*) FROM $L_T WHERE id=$P4")" = 0 ] && echo 1 || echo 0)

echo "== Handing a running lock to a keyholder (0.30.0)"
get solo h0 "$PAGE&cmp_tab=chastity"
HN=$(hnonce $T/h0.html)
r=$(act solo $HN start -F "keyholder=named" -F "keyholder_name=Alex" -F "option=a" -F "length=10080")
HID=$(q "SELECT MAX(id) FROM $L_T WHERE wearer_id=$SOLO AND status='locked'")
ev "global \$wpdb; \$wpdb->update('$L_T',array('started_at'=>gmdate('Y-m-d H:i:s',time()-3*86400)),array('id'=>$HID));" >/dev/null
HSTART=$(lk $HID started_at)
get solo h1 "$PAGE&cmp_tab=chastity"
ok "self-lock without a keyholder in a dynamic: explains how, no form" $([ "$(has $T/h1.html 'Give your keyholder this lock')$(hasnt $T/h1.html 'value="assign_keyholder"')" = 11 ] && echo 1 || echo 0)
r=$(act solo $HN assign_keyholder -F "lock=$HID" -F "keyholder=$KH")
ok "can't hand it to someone who doesn't hold your key" $(echo "$r" | grep -q 'cl_invalid' && [ "$(lk $HID keyholder_id)" = 0 ] && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->insert('$D_T',array('type'=>'keyholder','proposer_id'=>$KH,'partner_id'=>$SOLO,'proposer_side'=>'a','status'=>'active','proposer_show'=>1,'partner_show'=>1,'created_at'=>gmdate('Y-m-d H:i:s'))); CMP_Dynamics::grant('chastity',$KH,$SOLO);" >/dev/null
DYN3=$(q "SELECT MAX(id) FROM $D_T")
r=$(act kh $SN assign_keyholder -F "lock=$HID" -F "keyholder=$KH")
ok "the keyholder can't take the lock themselves" $(echo "$r" | grep -q 'cl_gone' && [ "$(lk $HID keyholder_id)" = 0 ] && echo 1 || echo 0)
get nosy hx "$PAGE&cmp_tab=chastity"
r=$(act nosy $(hnonce $T/hx.html) assign_keyholder -F "lock=$HID" -F "keyholder=$KH")
ok "...nor can another member hand it over" $(echo "$r" | grep -q 'cl_gone' && [ "$(lk $HID keyholder_id)" = 0 ] && echo 1 || echo 0)
get solo h2 "$PAGE&cmp_tab=chastity"
ok "wearer is offered their keyholder" $([ "$(has $T/h2.html 'value="assign_keyholder"')$(grep -o 'id="cmp_cl_assign"[^/]*' $T/h2.html | grep -q "value=\"$KH\"" && echo 1 || echo 0)" = 11 ] && echo 1 || echo 0)
r=$(act solo $HN assign_keyholder -F "lock=$HID" -F "keyholder=$KH")
ok "wearer hands the lock over: same lock, start kept, typed name cleared" $(echo "$r" | grep -q 'cl_kh_set' && [ "$(lk $HID status keyholder_id keyholder_name started_at)" = "locked|$KH||$HSTART" ] && echo 1 || echo 0)
ok "keyholder told, history and audit recorded" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$KH AND message LIKE '%handed you the key%'")" -ge 1 ] && [ "$(q "SELECT COUNT(*) FROM $E_T WHERE lock_id=$HID AND type='keyholder_set'")" = 1 ] && ev "global \$wpdb; echo false===strpos((string)\$wpdb->get_var(\"SELECT CONCAT(old_value,new_value) FROM \".CMP_Install::table('audit_log').\" WHERE action='lock_keyholder_set' AND object_id=$HID\"),'Alex')?1:0;" | grep -q 1 && echo 1 || echo 0)
r=$(act solo $HN assign_keyholder -F "lock=$HID" -F "keyholder=$KH")
ok "can't hand over a lock that already has a keyholder" $(echo "$r" | grep -q 'cl_invalid' && echo 1 || echo 0)
get solo h3 "$PAGE&cmp_tab=chastity"
ok "wearer now sees a keyholder lock (ask to unlock, emergency, no hand-over)" $([ "$(has $T/h3.html 'Keyholder: Sample Keyholder')$(has $T/h3.html 'value="ask_unlock"')$(has $T/h3.html 'value="emergency"')$(hasnt $T/h3.html 'Give your keyholder this lock')" = 1111 ] && echo 1 || echo 0)
get kh h4 "$PAGE&cmp_tab=chastity&lock=$HID"
ok "keyholder manages it, with Set the end date" $([ "$(has $T/h4.html 'Add time')$(has $T/h4.html 'Set the end date')$(has $T/h4.html 'name="end_at"')" = 111 ] && echo 1 || echo 0)

echo "== Keyholder sets the end date (0.30.0)"
NEWEND=$(ev 'echo wp_date("Y-m-d\TH:i", time()+10*86400);')
WANT=$(ev "echo gmdate('Y-m-d H:i:s', DateTime::createFromFormat('!Y-m-d\TH:i','$NEWEND',wp_timezone())->getTimestamp());")
r=$(act kh $SN end_date -F "lock=$HID" -F "end_at=$NEWEND")
ok "end date set (site time)" $(echo "$r" | grep -q 'cl_saved' && [ "$(lk $HID planned_end)" = "$WANT" ] && echo 1 || echo 0)
ok "wearer told the new end" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$SOLO AND message LIKE '%set your lock to end%'")" -ge 1 ] && echo 1 || echo 0)
get solo h5 "$PAGE&cmp_tab=chastity"
ok "wearer's history shows it" $(has $T/h5.html 'Keyholder set the end to')
PAST=$(ev 'echo wp_date("Y-m-d\TH:i", time()-3600);')
r=$(act kh $SN end_date -F "lock=$HID" -F "end_at=$PAST")
ok "a past date is refused" $(echo "$r" | grep -q 'cl_invalid' && [ "$(lk $HID planned_end)" = "$WANT" ] && echo 1 || echo 0)
r=$(act kh $SN end_date -F "lock=$HID" -F "end_at=2026-02-31T10:00")
ok "an impossible date is refused" $(echo "$r" | grep -q 'cl_invalid' && [ "$(lk $HID planned_end)" = "$WANT" ] && echo 1 || echo 0)
r=$(act solo $HN end_date -F "lock=$HID" -F "end_at=$NEWEND" -F "open_ended=1")
ok "the wearer can't change the end of a keyholder lock" $(echo "$r" | grep -q 'cl_gone' && [ "$(lk $HID planned_end)" = "$WANT" ] && echo 1 || echo 0)
r=$(act kh $SN end_date -F "lock=$HID" -F "open_ended=1")
ok "keyholder makes it open-ended" $([ -z "$(lk $HID planned_end)" ] && [ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$SOLO AND message LIKE '%made your lock open-ended%'")" -ge 1 ] && echo 1 || echo 0)
r=$(act kh $SN rules -F "lock=$HID" -F "option=a" -F "policy=none" -F "hygiene_minutes=15" -F "hide_timer=1")
before=$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$SOLO")
r=$(act kh $SN end_date -F "lock=$HID" -F "end_at=$NEWEND")
get solo h6 "$PAGE&cmp_tab=chastity"
ok "hidden timer: wearer not told, and the change isn't in their history" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$SOLO")" = "$before" ] && [ "$(hasnt $T/h6.html 'Keyholder set the end')$(hasnt $T/h6.html 'open-ended')" = 11 ] && echo 1 || echo 0)
r=$(act solo $HN emergency -F "lock=$HID")
ok "emergency unlock still ends it" $([ "$(lk $HID status end_reason)" = "ended|emergency" ] && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->update('$D_T',array('status'=>'ended'),array('id'=>$DYN3));" >/dev/null

echo "== 90-day photo purge (CMP 0.31.0)"
ok "SH4 still works before the purge" $([ "$(curl -s -o /dev/null -w '%{http_code}' "$SURL4")" = 200 ] && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->update('$E_T',array('created_at'=>gmdate('Y-m-d H:i:s',time()-91*DAY_IN_SECONDS)),array('id'=>$VID));" >/dev/null
KEEP=$(q "SELECT COUNT(*) FROM $E_T WHERE photo IS NOT NULL AND id<>$VID")
OUT=$(ev 'print_r(CMP_Retention::run());')
ok "daily clean-up removes the photo over 90 days old, keeps newer ones" $([ "$(q "SELECT CONCAT(IFNULL(photo,'NULL'),'|',photo_sha,'|',type) FROM $E_T WHERE id=$VID")" = "NULL|purged|verification" ] && [ "$(q "SELECT COUNT(*) FROM $E_T WHERE photo IS NOT NULL")" = "$KEEP" ] && echo "$OUT" | grep -q '\[lock_photos\] => 1' && echo 1 || echo 0)
ok "...it's gone for the wearer too (404)" $([ "$(curl -s -b $T/pup.jar -o /dev/null -w '%{http_code}' "$VURL")" = 404 ] && echo 1 || echo 0)
ok "...links to it stop and are cleared" $([ "$(curl -s -o /dev/null -w '%{http_code}' "$SURL4")" = 404 ] && [ "$(q "SELECT COUNT(*) FROM $S_T WHERE event_id=$VID")" = 0 ] && echo 1 || echo 0)
get kh pg1 "$PAGE&cmp_tab=chastity&lock=$HID"
ok "...the history entry stays, marked 'photo removed after 90 days'" $([ "$(q "SELECT COUNT(*) FROM $E_T WHERE id=$VID")" = 1 ] && ev "\$e=CMP_Chastity::events($LID,100); foreach(\$e as \$x) if(\$x->id==$VID) echo (new ReflectionMethod('CMP_Chastity','event_text'))->invoke(null,\$x);" | grep -q 'photo removed after 90 days' && echo 1 || echo 0)
ok "the period can be changed (cmp_retention_periods)" $(ev "add_filter('cmp_retention_periods',function(\$p){\$p['lock_photos']=30; return \$p;}); echo 30===CMP_Chastity::photo_days()?1:0;")

echo "== Privacy"
ok "export lists the lock and its history" $(ev "\$j=wp_json_encode(CMP_Account::export('pup@example.com'),JSON_UNESCAPED_SLASHES); echo false!==strpos(\$j,'One release a week') && false!==strpos(\$j,'Verification photo') && false!==strpos(\$j,'photo removed after 90 days')?1:0;")
ok "erase removes the wearer's locks and history" $(ev "CMP_Account::erase('pup@example.com'); global \$wpdb; echo 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $L_T WHERE wearer_id=$PUP\") && 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $E_T WHERE lock_id=$LID\") && 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM $S_T WHERE wearer_id=$PUP\")?1:0;")

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev "global \$wpdb; foreach(array('$L_T','$E_T','$D_T',CMP_Install::table('photo_shares')) as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
for u in kh pup nosy solo; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
