#!/usr/bin/env bash
# End-to-end test of date of birth at sign-up, the one-time step for existing
# members, the under-18 lock, the site-team correction, privacy export/erase,
# and "a filled-in field starts shown" (Community Member Planning 0.4.0 with
# Community Events Calendar 1.27.1's sign-up hooks).
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/dob-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/dob; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
nonce(){ grep -o 'name="_cmp_nonce" value="[^"]*"' $1 | head -1 | cut -d'"' -f4; }
pnonce(){ php -r 'preg_match("~value=\"".$argv[2]."\".*?name=\"_cmp_nonce\" value=\"([^\"]+)\"~s",file_get_contents($argv[1]),$m); echo $m[1]??"";' "$1" "${2:-cmp_profile_save}"; }
post() { curl -s -b $T/$1.jar -c $T/$1.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" "${@:2}"; }
uid()  { $W user get $1 --field=ID 2>/dev/null; }
ev()   { $W eval "$1" 2>&1; }
verify(){ ev "\$i=get_user_by('login','$1')->ID; update_user_meta(\$i,'cmp_verified_email','$1@example.com'); update_user_meta(\$i,'cmp_email_verified_at',gmdate('Y-m-d H:i:s'));" >/dev/null; }

for p in community-events-calendar community-member-planning; do
	rm -rf wp-content/plugins/$p && cp -r $REPO/$p wp-content/plugins/
	$W plugin activate $p >/dev/null 2>&1
done
ev 'CMP_Install::maybe_upgrade();' >/dev/null
$W option update users_can_register 1 >/dev/null
REG_ID=$($W post list --post_type=page --name=zz-register --field=ID 2>/dev/null)
[ -z "$REG_ID" ] && REG_ID=$($W post create --post_type=page --post_status=publish --post_title="ZZ Register" --post_name=zz-register --post_content='[cec_register]' --porcelain)
REG="$H/?page_id=$REG_ID"
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
PAGE_ID=$(ev 'echo (int) CMP_Settings::get("member_page_id");')
PAGE="$H/?page_id=$PAGE_ID"
PROF="$PAGE&cmp_tab=profile"
for u in newbie kid oldtimer minor2; do $W user delete $u --yes >/dev/null 2>&1; done
Y=$(date +%Y); M=$(date +%-m); D=$(date +%-d)
UNDER=$((Y-17)); ADULT=1990

echo "== Sign-up form ([cec_register])"
get anon r0 "$REG"
ok "date of birth fields on the sign-up form" $([ "$(has $T/r0.html 'name="cmp_dob\[m\]"')$(has $T/r0.html 'name="cmp_dob\[d\]"')$(has $T/r0.html 'name="cmp_dob\[y\]"')$(has $T/r0.html 'Never shown to anyone')" = 1111 ] && echo 1 || echo 0)
RN=$(grep -o 'name="cec_register_nonce" value="[^"]*"' $T/r0.html | cut -d'"' -f4)
reg(){ curl -s -b $T/anon.jar -c $T/anon.jar -o /dev/null -w '%{redirect_url}' -H "Referer: $REG" "$H/wp-admin/admin-post.php" -d "action=cec_register&cec_register_nonce=$RN&cec_redirect=$H/&cec_username=$1&cec_email=$1@example.com&cec_password=longpassword1&cec_password_confirm=longpassword1$2"; }
r=$(reg kid "")
ok "no date of birth -> refused, no account" $([ "$(echo "$r" | grep -c 'cec_register_error=dob_missing')$(uid kid | wc -c | awk '{print ($1<=1)}')" = 11 ] && echo 1 || echo 0)
r=$(reg kid "&cmp_dob[m]=$M&cmp_dob[d]=$D&cmp_dob[y]=$UNDER")
ok "17 years old -> refused, no account" $([ "$(echo "$r" | grep -c 'cec_register_error=dob_under_18')$(uid kid | wc -c | awk '{print ($1<=1)}')" = 11 ] && echo 1 || echo 0)
curl -s "$REG&cec_register_error=dob_under_18" -o $T/r1.html
ok "refusal message shown" $(has $T/r1.html 'You must be 18 or older to join')
r=$(reg kid "&cmp_dob[m]=2&cmp_dob[d]=30&cmp_dob[y]=$ADULT")
ok "impossible date refused" $(echo "$r" | grep -q 'cec_register_error=dob_invalid' && echo 1 || echo 0)
r=$(reg newbie "&cmp_dob[m]=$M&cmp_dob[d]=$D&cmp_dob[y]=$((Y-18))")
NEW=$(uid newbie)
ok "turning 18 today -> account created" $([ -n "$NEW" ] && echo 1 || echo 0)
ok "date stored; 18+ step recorded" $(ev "echo sprintf('%04d-%02d-%02d',$((Y-18)),$M,$D)===get_user_meta($NEW,'cmp_birth_date',true) && get_user_meta($NEW,'cmp_age_attested_at',true) ?1:0;")
ok "age is 18" $(ev "echo 18===CMP_Birth_Date::age($NEW)?1:0;")
ok "new member: name, age, member-since start shown" $(ev "\$r=CMP_Profiles::rows($NEW); echo 'members'===\$r['display_name']['visibility'] && 'members'===\$r['age']['visibility'] && 'members'===\$r['member_since']['visibility'] && 'private'===\$r['bio']['visibility'] ?1:0;")
ok "next step is email verification (not the age step)" $([ "$(ev "echo CMP_Access::state($NEW);")" = unverified ] && echo 1 || echo 0)
ok "events plugin stored no birth data itself" $(ev "global \$wpdb; echo 0===(int)\$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->usermeta} WHERE user_id=$NEW AND meta_key LIKE 'cec%birth%'\")?1:0;")

echo "== Existing member asked once"
$W user create oldtimer oldtimer@example.com --role=subscriber --user_pass=oldtimer1234 >/dev/null
OLD=$(uid oldtimer); verify oldtimer
ev "update_user_meta($OLD,'cmp_age_attested_at',gmdate('Y-m-d H:i:s'));" >/dev/null
ok "ticked the old 18+ box only -> back to the age step" $([ "$(ev "echo CMP_Access::state($OLD);")" = unattested ] && echo 1 || echo 0)
login oldtimer oldtimer oldtimer1234
get oldtimer o1 "$PAGE"
ok "shows the one-time date-of-birth step" $([ "$(has $T/o1.html 'One more step')$(has $T/o1.html "only be asked this once")" = 11 ] && echo 1 || echo 0)
r=$(post oldtimer -d "action=cmp_attest&_cmp_nonce=$(nonce $T/o1.html)&cmp_dob[m]=7&cmp_dob[d]=4&cmp_dob[y]=$ADULT")
ok "adult date -> member" $([ "$(echo "$r" | grep -c 'cmp_notice=welcome')$(ev "echo CMP_Access::state($OLD);")" = 1member ] && echo 1 || echo 0)
ok "existing member's fields NOT switched on automatically" $(ev "\$r=CMP_Profiles::rows($OLD); echo 'private'===\$r['display_name']['visibility'] && 'private'===\$r['age']['visibility'] ?1:0;")
get oldtimer o2 "$PAGE"
r=$(post oldtimer -d "action=cmp_attest&_cmp_nonce=$(nonce $T/o1.html)&cmp_dob[m]=1&cmp_dob[d]=1&cmp_dob[y]=$UNDER")
ok "can't change the date afterwards from the member area" $(ev "echo sprintf('%04d-07-04',$ADULT)===get_user_meta($OLD,'cmp_birth_date',true)?1:0;")
ok "a typed age from before 0.4.0 is cleared" $(ev "CMP_Profiles::save_field($OLD,'age',array('mode'=>'exact','value'=>99),'members'); CMP_Birth_Date::store($OLD,'$ADULT-07-04','test'); \$r=CMP_Profiles::rows($OLD); echo (''===\$r['age']['value']||null===\$r['age']['value']) && 'members'===\$r['age']['visibility'] && CMP_Birth_Date::age($OLD)<99 ?1:0;")

echo "== Under 18 from a signed-in account: locked"
$W user create minor2 minor2@example.com --role=subscriber --user_pass=minor21234 >/dev/null
MIN=$(uid minor2); verify minor2
login minor2 minor2 minor21234
get minor2 m1 "$PAGE"
r=$(post minor2 -d "action=cmp_attest&_cmp_nonce=$(nonce $T/m1.html)&cmp_dob[m]=$M&cmp_dob[d]=$D&cmp_dob[y]=$UNDER")
ok "under-18 date locks the account, stores no date" $(ev "echo CMP_Birth_Date::is_blocked($MIN) && ''===get_user_meta($MIN,'cmp_birth_date',true) && 'unattested'===CMP_Access::state($MIN) ?1:0;")
get minor2 m2 "$PAGE"
ok "locked page says contact the site team, no form" $([ "$(has $T/m2.html 'contact the site team')$(hasnt $T/m2.html 'name="cmp_dob')" = 11 ] && echo 1 || echo 0)
r=$(post minor2 -d "action=cmp_attest&_cmp_nonce=$(nonce $T/m1.html)&cmp_dob[m]=1&cmp_dob[d]=1&cmp_dob[y]=$ADULT")
ok "retrying with an adult year doesn't unlock" $(ev "echo CMP_Birth_Date::is_blocked($MIN) && 'member'!==CMP_Access::state($MIN) ?1:0;")
ok "REST still closed" $(code=$(curl -s -b $T/minor2.jar -o /dev/null -w '%{http_code}' "$H/?rest_route=/cmp/v1/notifications"); [ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)

echo "== Site team correction (wp-admin user screen)"
ok "admin sees the date field + unlock box" $(ev "wp_set_current_user(1); ob_start(); CMP_Birth_Date::admin_field(get_userdata($MIN)); \$h=ob_get_clean(); echo false!==strpos(\$h,'cmp_dob[y]') && false!==strpos(\$h,'cmp_unblock')?1:0;")
ok "a member can't see or save it" $(ev "wp_set_current_user($OLD); ob_start(); CMP_Birth_Date::admin_field(get_userdata($OLD)); echo ''===ob_get_clean()?1:0;")
ok "admin corrects date + unlocks -> member" $(ev "wp_set_current_user(1); \$_POST=array('_cmp_dob_nonce'=>wp_create_nonce('cmp_admin_dob_$MIN'),'cmp_unblock'=>'1','cmp_dob'=>array('m'=>'3','d'=>'9','y'=>'$ADULT')); CMP_Birth_Date::admin_save($MIN); update_user_meta($MIN,'cmp_age_attested_at',gmdate('Y-m-d H:i:s')); echo ! CMP_Birth_Date::is_blocked($MIN) && '$ADULT-03-09'===get_user_meta($MIN,'cmp_birth_date',true) && 'member'===CMP_Access::state($MIN) ?1:0;")
ok "admin save with a bad nonce does nothing" $(ev "wp_set_current_user(1); \$_POST=array('_cmp_dob_nonce'=>'bad','cmp_dob'=>array('m'=>'1','d'=>'1','y'=>'1950')); CMP_Birth_Date::admin_save($MIN); echo '$ADULT-03-09'===get_user_meta($MIN,'cmp_birth_date',true)?1:0;")

echo "== Profile: filled-in field starts shown"
verify newbie
login newbie newbie longpassword1
get newbie n1 "$PROF"
N=$(pnonce $T/n1.html)
# What a browser without JavaScript sends: the hidden switch of an empty field posts "on".
r=$(post newbie --data-urlencode "action=cmp_profile_save" --data-urlencode "_cmp_nonce=$N" --data-urlencode "f[pronouns]=he/him" --data-urlencode "sp[pronouns]=1" --data-urlencode "s[pronouns]=1" --data-urlencode "sp[bio]=1" --data-urlencode "s[bio]=1")
ok "newly filled field saved as shown" $(ev "\$r=CMP_Profiles::rows($NEW); echo 'members'===\$r['pronouns']['visibility']?1:0;")
ok "field left empty keeps its choice" $(ev "\$r=CMP_Profiles::rows($NEW); echo 'private'===\$r['bio']['visibility']?1:0;")
r=$(post newbie --data-urlencode "action=cmp_profile_save" --data-urlencode "_cmp_nonce=$N" --data-urlencode "f[pronouns]=he/him" --data-urlencode "sp[pronouns]=1")
ok "switch off -> Only me" $(ev "\$r=CMP_Profiles::rows($NEW); echo 'private'===\$r['pronouns']['visibility']?1:0;")
get newbie n2 "$PROF"
ok "age shows 18 with its switch on" $([ "$(has $T/n2.html 'cmp-age-value">18<')$(has $T/n2.html 'id="cmp_s_age" name="s\[age\]" value="1" checked')" = 11 ] && echo 1 || echo 0)
ok "photo panel: Show switch, on for a new photo" $(has $T/n2.html 'id="cmp-photo-avatar-show" name="show" value="1"  checked')
ok "birth date never on the profile page" $(hasnt $T/n2.html "$((Y-18))-")

echo "== Privacy"
ok "export includes the date of birth" $(ev "\$e=CMP_Account::export('newbie@example.com'); echo false!==strpos(wp_json_encode(\$e),sprintf('%04d-%02d-%02d',$((Y-18)),$M,$D))?1:0;")
ok "erase removes the date, keeps an under-18 lock" $(ev "CMP_Birth_Date::block($NEW); CMP_Account::erase('newbie@example.com'); echo ''===get_user_meta($NEW,'cmp_birth_date',true) && CMP_Birth_Date::is_blocked($NEW) ?1:0;")

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
for u in newbie kid oldtimer minor2; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning\|cec' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
