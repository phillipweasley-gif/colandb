#!/usr/bin/env bash
# End-to-end test of profiles v2 and the step-by-step profile setup
# (Community Member Planning 0.5.0): starter lists, new field types (height,
# weight, month, rated kinks), health starting hidden, one-step saves, skip /
# later / finish, the home nudge, and the new profile display.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/setup-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/setup; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
pnonce(){ php -r 'preg_match("~value=\"".$argv[2]."\".*?name=\"_cmp_nonce\" value=\"([^\"]+)\"~s",file_get_contents($argv[1]),$m); echo $m[1]??"";' "$1" "${2:-cmp_profile_save}"; }
post() { curl -s -b $T/$1.jar -c $T/$1.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" "${@:2}"; }
uid()  { $W user get $1 --field=ID 2>/dev/null; }
ev()   { $W eval "$1" 2>&1; }
member(){ ev "\$i=get_user_by('login','$1')->ID; update_user_meta(\$i,'cmp_verified_email','$1@example.com'); update_user_meta(\$i,'cmp_email_verified_at',gmdate('Y-m-d H:i:s')); update_user_meta(\$i,'cmp_birth_date','$2'); CMP_Access::record_attestation(\$i);" >/dev/null; }
opt()  { ev "echo array_search('$2', CMP_Profile_Fields::options('$1'));"; }

rm -rf wp-content/plugins/community-member-planning && cp -r $REPO/community-member-planning wp-content/plugins/
$W plugin activate community-member-planning >/dev/null 2>&1
ev 'update_option("cmp_db_version",2); CMP_Install::maybe_upgrade();' >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
PAGE_ID=$(ev 'echo (int) CMP_Settings::get("member_page_id");')
PAGE="$H/?page_id=$PAGE_ID"
for u in newm oldm viewer; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create newm newm@example.com --role=subscriber --user_pass=newmpass1234 --display_name="New Member" >/dev/null
$W user create oldm oldm@example.com --role=subscriber --user_pass=oldmpass1234 --display_name="Old Member" >/dev/null
$W user create viewer viewer@example.com --role=subscriber --user_pass=viewerpass12 >/dev/null
member newm 1985-05-20; member oldm 1970-01-02; member viewer 1990-03-03
NEW=$(uid newm); OLD=$(uid oldm); VIEW=$(uid viewer)
ev "CMP_Profiles::set_new_member_defaults($NEW);" >/dev/null
ev "CMP_Profiles::save_field($OLD,'bio','Here before 0.5.0','members');" >/dev/null

echo "== Starter lists"
ev 'delete_option("cmp_profile_options"); CMP_Profile_Fields::seed_defaults();' >/dev/null
ok "every list seeded (e.g. 36 roles, 7 positions, 30 kinks)" $(ev 'echo count(CMP_Profile_Fields::options("roles"))>=30 && count(CMP_Profile_Fields::options("position"))===7 && count(CMP_Profile_Fields::options("kinks"))>=30 ?1:0;')
ok "a list the site team already set is left alone" $(ev '$a=get_option("cmp_profile_options"); $a["hosting"]=array(array("key"=>"mine","label"=>"Mine","active"=>true,"order"=>0)); update_option("cmp_profile_options",$a); CMP_Profile_Fields::seed_defaults(); echo array("mine"=>"Mine")===CMP_Profile_Fields::options("hosting")?1:0;')
ok "starter kinks: 60+, each in a category (0.10.0)" $(ev '$g=CMP_Profile_Fields::option_groups("kinks"); echo count(CMP_Profile_Fields::options("kinks"))>=60 && "bondage"===$g["rope-bondage"] && "control"===$g["chastity-keyholding"] && "gear"===$g["leather"] ?1:0;')
# A site from before 0.10.0: 30 kinks, no categories, one renamed and one retired by the site team.
ev '$a=get_option("cmp_profile_options"); $old=array(); foreach(array_slice($a["kinks"],0,200) as $o){ if(in_array($o["key"],array("chastity-keyholding","rope-bondage","praise","leather-worship"),true)){ unset($o["group"]); $old[]=$o; } } $old[]=array("key"=>"custom-thing","label"=>"Custom thing","active"=>true,"order"=>99); foreach($old as &$o){ if("praise"===$o["key"]){ $o["label"]="Praise & encouragement"; } if("leather-worship"===$o["key"]){ $o["active"]=false; } } $a["kinks"]=$old; update_option("cmp_profile_options",$a); delete_option("cmp_kinks_grouped"); CMP_Profile_Fields::upgrade_kinks();' >/dev/null
ok "upgrade: categories given, new kinks added, renamed / retired / custom kept" $(ev '$g=CMP_Profile_Fields::option_groups("kinks"); $all=CMP_Profile_Fields::options("kinks",true); $on=CMP_Profile_Fields::options("kinks"); echo "bondage"===$g["rope-bondage"] && "other"===$g["custom-thing"] && "Praise & encouragement"===$all["praise"] && !isset($on["leather-worship"]) && isset($on["flogging"]) && count($on)>=60 ?1:0;')
ok "upgrade runs once" $(ev '$a=get_option("cmp_profile_options"); $a["kinks"][0]["group"]="impact"; update_option("cmp_profile_options",$a); CMP_Profile_Fields::upgrade_kinks(); echo "impact"===get_option("cmp_profile_options")["kinks"][0]["group"]?1:0;')
ok "admin save keeps and changes categories" $(ev '$rows=array(); foreach(CMP_Profile_Fields::raw_options("kinks") as $o){ $rows[]=array("key"=>$o["key"],"label"=>$o["label"],"active"=>$o["active"],"order"=>$o["order"],"group"=>"custom-thing"===$o["key"]?"gear":""); } $r=CMP_Profile_Fields::save_list("kinks",$rows); $g=CMP_Profile_Fields::option_groups("kinks"); echo true===$r && "gear"===$g["custom-thing"] && "control"===$g["chastity-keyholding"]?1:0;')
ev 'delete_option("cmp_profile_options"); delete_option("cmp_kinks_grouped"); CMP_Profile_Fields::seed_defaults(); CMP_Profile_Fields::upgrade_kinks();' >/dev/null
ev '$a=get_option("cmp_profile_options"); unset($a["hosting"]); update_option("cmp_profile_options",$a); CMP_Profile_Fields::seed_defaults();' >/dev/null

echo "== New member goes straight into the steps"
login newm newm newmpass1234
get newm h1 "$PAGE"
ok "home shows step 1 of 6 (photo & bio)" $([ "$(has $T/h1.html 'Step 1 of 6')$(has $T/h1.html 'Say hello')$(has $T/h1.html 'cmp-photo-avatar')$(has $T/h1.html 'name="f\[bio\]"')" = 1111 ] && echo 1 || echo 0)
ok "step only lists its own fields" $([ "$(grep -o 'name="only\[\]"' $T/h1.html | wc -l)" = 2 ] && [ "$(hasnt $T/h1.html 'name="f\[roles\]')" = 1 ] && echo 1 || echo 0)
N=$(pnonce $T/h1.html)
NEXT="$H/?page_id=$PAGE_ID&cmp_tab=setup&cmp_step=2"
r=$(post newm --data-urlencode "action=cmp_profile_save" --data-urlencode "_cmp_nonce=$N" --data-urlencode "cmp_setup_step=1" --data-urlencode "cmp_return=$NEXT" --data-urlencode "only[]=bio" --data-urlencode "only[]=pronouns" --data-urlencode "f[bio]=Hello from step one" --data-urlencode "sp[bio]=1" --data-urlencode "s[bio]=1" --data-urlencode "sp[pronouns]=1" --data-urlencode "s[pronouns]=1")
ok "save -> step 2" $([ "$r" = "$NEXT" ] && echo 1 || echo 0)
ok "bio saved and shown; next step remembered" $(ev "\$r=CMP_Profiles::rows($NEW); echo 'Hello from step one'===\$r['bio']['value'] && 'members'===\$r['bio']['visibility'] && 2===(int)get_user_meta($NEW,'cmp_setup_step',true) ?1:0;")
ok "fields outside the step untouched" $(ev "\$r=CMP_Profiles::rows($NEW); echo 'members'===\$r['display_name']['visibility']?1:0;")
get newm h2 "$PAGE"
ok "home resumes at step 2" $(has $T/h2.html 'Step 2 of 6')

echo "== Identity, stats and kinks"
ROLE=$(opt roles Keyholder); POS=$(opt position 'Vers top'); BT=$(opt body_type Athletic); K1=$(opt kinks 'Chastity / keyholding'); K2=$(opt kinks 'Rope bondage'); G=$(opt gender Man)
get newm s3 "$PAGE&cmp_tab=setup&cmp_step=3"
N=$(pnonce $T/s3.html)
r=$(post newm --data-urlencode "action=cmp_profile_save" --data-urlencode "_cmp_nonce=$N" --data-urlencode "cmp_setup_step=3" --data-urlencode "cmp_return=$H/?page_id=$PAGE_ID&cmp_tab=setup&cmp_step=4" --data-urlencode "only[]=gender" --data-urlencode "only[]=roles" --data-urlencode "only[]=position" --data-urlencode "f[gender][keys][]=$G" --data-urlencode "f[roles][keys][]=$ROLE" --data-urlencode "f[position]=$POS" --data-urlencode "sp[gender]=1" --data-urlencode "s[gender]=1" --data-urlencode "sp[roles]=1" --data-urlencode "s[roles]=1" --data-urlencode "sp[position]=1" --data-urlencode "s[position]=1")
ok "identity step saved" $(ev "\$r=CMP_Profiles::rows($NEW); echo array('$ROLE')===\$r['roles']['value']['keys'] && '$POS'===\$r['position']['value'] ?1:0;")
get newm s4 "$PAGE&cmp_tab=setup&cmp_step=4"
ok "stats step: height list, weight box, age from date of birth" $([ "$(has $T/s4.html 'value="71">5&#039;11&quot;')$(has $T/s4.html 'name="f\[weight\]"')$(has $T/s4.html 'cmp-age-value')" = 111 ] && echo 1 || echo 0)
N=$(pnonce $T/s4.html)
r=$(post newm --data-urlencode "action=cmp_profile_save" --data-urlencode "_cmp_nonce=$N" --data-urlencode "cmp_setup_step=4" --data-urlencode "cmp_return=$H/?page_id=$PAGE_ID&cmp_tab=setup&cmp_step=5" --data-urlencode "only[]=height" --data-urlencode "only[]=weight" --data-urlencode "f[height]=71" --data-urlencode "f[weight]=40")
ok "weight 40 lb refused, back to the step" $(echo "$r" | grep -q 'cmp_notice=profile_invalid' && echo "$r" | grep -q 'cmp_step=5' && echo 1 || echo 0)
ok "nothing saved on error" $(ev "\$r=CMP_Profiles::rows($NEW); echo ''===\$r['height']['value']||null===\$r['height']['value']?1:0;")
r=$(post newm --data-urlencode "action=cmp_profile_save" --data-urlencode "_cmp_nonce=$N" --data-urlencode "cmp_setup_step=4" --data-urlencode "cmp_return=$H/?page_id=$PAGE_ID&cmp_tab=setup&cmp_step=5" --data-urlencode "only[]=height" --data-urlencode "only[]=weight" --data-urlencode "only[]=body_type" --data-urlencode "f[height]=71" --data-urlencode "f[weight]=185" --data-urlencode "f[body_type]=$BT" --data-urlencode "sp[height]=1" --data-urlencode "s[height]=1" --data-urlencode "sp[weight]=1" --data-urlencode "s[weight]=1" --data-urlencode "sp[body_type]=1" --data-urlencode "s[body_type]=1")
ok "stats saved (71 in, 185 lb)" $(ev "\$r=CMP_Profiles::rows($NEW); echo 71===\$r['height']['value'] && 185===\$r['weight']['value'] ?1:0;")
get newm s5 "$PAGE&cmp_tab=setup&cmp_step=5"
N=$(pnonce $T/s5.html)
r=$(post newm --data-urlencode "action=cmp_profile_save" --data-urlencode "_cmp_nonce=$N" --data-urlencode "cmp_setup_step=5" --data-urlencode "cmp_return=$H/?page_id=$PAGE_ID&cmp_tab=setup&cmp_step=6" --data-urlencode "only[]=kinks" --data-urlencode "only[]=hard_limits" --data-urlencode "f[kinks][$K1][lvl]=love" --data-urlencode "f[kinks][$K1][dir]=giving" --data-urlencode "f[kinks][$K2][lvl]=like" --data-urlencode "f[kinks][$K2][dir]=both" --data-urlencode "f[kinks][not-a-kink][lvl]=love" --data-urlencode "f[hard_limits]=No public play" --data-urlencode "sp[kinks]=1" --data-urlencode "s[kinks]=1" --data-urlencode "sp[hard_limits]=1" --data-urlencode "s[hard_limits]=1")
ok "rated kinks saved (unknown ignored)" $(ev "\$r=CMP_Profiles::rows($NEW); echo 2===count(\$r['kinks']['value']) && 'love'===\$r['kinks']['value'][0]['lvl'] && 'giving'===\$r['kinks']['value'][0]['dir'] ?1:0;")
get newm s5b "$PAGE&cmp_tab=setup&cmp_step=5"
ok "picker: picks listed first, the rest under Browse by category, search + categories" $(php -r '$h=file_get_contents($argv[1]); $q=chr(34); $m=strpos($h,"data-cmp-kp-mine"); $b=strpos($h,"data-cmp-kp-browse"); $k1=strpos($h,"data-k=".$q.$argv[2].$q); $k3=strpos($h,"data-k=".$q."flogging".$q); exit($m && $b && $k1>$m && $k1<$b && $k3>$b && false!==strpos($h,"data-cmp-kp-q") && false!==strpos($h,"data-cmp-kp-cat=".$q."impact".$q) && false!==strpos($h,"2 of 40 picked")?0:1);' $T/s5b.html $K1 && echo 1 || echo 0)
ok "picker: saved level and direction are checked" $(php -r '$h=file_get_contents($argv[1]); $q=chr(34); $n="name=".$q."f[kinks][".$argv[2]."]"; exit(false!==strpos($h,$n."[lvl]".$q." value=".$q."love".$q." checked") && false!==strpos($h,$n."[dir]".$q." value=".$q."giving".$q." checked")?0:1);' $T/s5b.html $K1 && echo 1 || echo 0)
r=$(post newm --data-urlencode "action=cmp_profile_save" --data-urlencode "_cmp_nonce=$N" --data-urlencode "cmp_setup_step=5" --data-urlencode "cmp_return=$H/?page_id=$PAGE_ID&cmp_tab=setup&cmp_step=6" --data-urlencode "only[]=kinks" --data-urlencode "f[kinks][$K1][lvl]=love" --data-urlencode "f[kinks][$K1][dir]=giving" --data-urlencode "f[kinks][$K2][lvl]=" --data-urlencode "f[kinks][$K2][dir]=" --data-urlencode "f[kinks][flogging][lvl]=" --data-urlencode "sp[kinks]=1" --data-urlencode "s[kinks]=1")
ok "picker: removing (empty level) drops a kink; untouched browse rows add nothing" $(ev "\$r=CMP_Profiles::rows($NEW); echo 1===count(\$r['kinks']['value']) && '$K1'===\$r['kinks']['value'][0]['k'] ?1:0;")
r=$(post newm --data-urlencode "action=cmp_profile_save" --data-urlencode "_cmp_nonce=$N" --data-urlencode "cmp_setup_step=5" --data-urlencode "cmp_return=$H/?page_id=$PAGE_ID&cmp_tab=setup&cmp_step=6" --data-urlencode "only[]=kinks" --data-urlencode "f[kinks][$K1][lvl]=love" --data-urlencode "f[kinks][$K1][dir]=giving" --data-urlencode "f[kinks][$K2][lvl]=like" --data-urlencode "f[kinks][$K2][dir]=both" --data-urlencode "sp[kinks]=1" --data-urlencode "s[kinks]=1")
ok "kinks displayed as 'Chastity / keyholding (Love it, giving)'" $(ev "echo false!==strpos(CMP_Profile_Fields::display('kinks',CMP_Profiles::rows($NEW)['kinks']['value']),'Chastity / keyholding (Love it, giving)')?1:0;")

echo "== Health starts hidden"
get newm s6 "$PAGE&cmp_tab=setup&cmp_step=6"
ok "health switches start off (and are marked sensitive)" $([ "$(has $T/s6.html 'data-cmp-sensitive hidden><input type="hidden" name="sp\[practices\]" value="1" /><input type="checkbox" class="cmp-switch-input" id="cmp_s_practices" name="s\[practices\]" value="1" />')" = 1 ] && echo 1 || echo 0)
PR=$(opt practices 'On PrEP')
N=$(pnonce $T/s6.html)
# What a browser sends: the switch stayed off.
r=$(post newm --data-urlencode "action=cmp_profile_save" --data-urlencode "_cmp_nonce=$N" --data-urlencode "cmp_setup_step=6" --data-urlencode "cmp_return=$H/?page_id=$PAGE_ID&cmp_tab=profile" --data-urlencode "only[]=practices" --data-urlencode "only[]=last_tested" --data-urlencode "f[practices][keys][]=$PR" --data-urlencode "f[last_tested]=2099-01" --data-urlencode "sp[practices]=1" --data-urlencode "sp[last_tested]=1")
ok "future test date refused" $(echo "$r" | grep -q 'profile_invalid' && echo 1 || echo 0)
LAST=$(date -v-1m +%Y-%m 2>/dev/null || date -d '1 month ago' +%Y-%m)
r=$(post newm --data-urlencode "action=cmp_profile_save" --data-urlencode "_cmp_nonce=$N" --data-urlencode "cmp_setup_step=6" --data-urlencode "cmp_return=$H/?page_id=$PAGE_ID&cmp_tab=profile" --data-urlencode "only[]=practices" --data-urlencode "only[]=last_tested" --data-urlencode "f[practices][keys][]=$PR" --data-urlencode "f[last_tested]=$LAST" --data-urlencode "sp[practices]=1" --data-urlencode "sp[last_tested]=1")
ok "finish -> Profile tab; setup marked finished" $(echo "$r" | grep -q 'cmp_tab=profile' && [ "$(ev "echo get_user_meta($NEW,'cmp_setup_done',true);")" = finished ] && echo 1 || echo 0)
ok "health saved but hidden" $(ev "\$r=CMP_Profiles::rows($NEW); echo 'private'===\$r['practices']['visibility'] && 'private'===\$r['last_tested']['visibility'] ?1:0;")
get newm h3 "$PAGE"
ok "finished: home is the normal home, no nudge" $([ "$(has $T/h3.html 'Welcome, New Member')$(hasnt $T/h3.html 'Finish your profile')$(hasnt $T/h3.html 'Step 1 of 6')" = 111 ] && echo 1 || echo 0)

echo "== Profile display"
login viewer viewer viewerpass12
get viewer v1 "$PAGE&cmp_member=$NEW"
ok "header: name, '36 M Keyholder'-style tag, stat line" $([ "$(has $T/v1.html 'New Member')$(has $T/v1.html 'class="cmp-prof-stat"')$(has $T/v1.html '5&#039;11&quot;')$(has $T/v1.html '185 lb')" = 1111 ] && echo 1 || echo 0)
ok "kinks with level and direction, hard limits, bio" $([ "$(has $T/v1.html 'Chastity / keyholding')$(has $T/v1.html 'cmp-kink-lvl is-love">Love it</h5>')$(has $T/v1.html 'No public play')$(has $T/v1.html 'Hello from step one')" = 1111 ] && echo 1 || echo 0)
ok "hidden health details not shown to others" $(hasnt $T/v1.html 'On PrEP')
ev "CMP_Profiles::save_field($NEW,'practices',null,'members');" >/dev/null
get viewer v2 "$PAGE&cmp_member=$NEW"
ok "...until switched on" $(has $T/v2.html 'On PrEP')
ok "birth date never in the page" $(hasnt $T/v2.html '1985-05-20')

echo "== Existing members, skip and later"
login oldm oldm oldmpass1234
get oldm o1 "$PAGE"
ok "existing member with a profile: normal home + Finish your profile card" $([ "$(has $T/o1.html 'Welcome, Old Member')$(has $T/o1.html 'Finish your profile')$(hasnt $T/o1.html 'Step 1 of 6')" = 111 ] && echo 1 || echo 0)
get oldm o2 "$PAGE&cmp_tab=setup&cmp_step=2"
ok "setup tab works on request" $(has $T/o2.html 'Step 2 of 6')
ok "skip link goes to the next step without saving" $(has $T/o2.html 'cmp_step=3" class\|cmp-setup-skip" href="[^"]*cmp_step=3')
LN=$(pnonce $T/o2.html cmp_setup_later)
r=$(post oldm -d "action=cmp_setup_later&_cmp_nonce=$LN")
ok "Set up later -> home, recorded" $([ "$(ev "echo get_user_meta($OLD,'cmp_setup_done',true);")" = later ] && echo 1 || echo 0)
r=$(post oldm -d "action=cmp_setup_later&_cmp_nonce=$LN&finish=1")
ok "Skip on the last step finishes" $([ "$(ev "echo get_user_meta($OLD,'cmp_setup_done',true);")" = finished ] && echo 1 || echo 0)
r=$(post oldm -d "action=cmp_setup_later&_cmp_nonce=bad")
ok "bad nonce does nothing" $([ "$(ev "echo get_user_meta($OLD,'cmp_setup_done',true);")" = finished ] && echo 1 || echo 0)
get anon a1 "$PAGE&cmp_tab=setup&cmp_step=1"
ok "signed out: no setup form" $(hasnt $T/a1.html 'Step 1 of 6')

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
for u in newm oldm viewer; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
