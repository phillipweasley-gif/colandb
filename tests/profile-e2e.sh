#!/usr/bin/env bash
# End-to-end test of member profiles, photos, preferences, profile options
# admin and the sign-in-aware site menu (Community Member Planning 0.3.0).
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/profile-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/prof; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
fnonce(){ grep -o "value=\"$2\" />[^>]*name=\"_cmp_nonce\" value=\"[^\"]*\"" $1 | head -1 | sed 's/.*value="\([^"]*\)"$/\1/'; }
# The _cmp_nonce of the form whose action is $2 (default: the profile form).
pnonce(){ php -r 'preg_match("~value=\"".$argv[2]."\".*?name=\"_cmp_nonce\" value=\"([^\"]+)\"~s",file_get_contents($argv[1]),$m); echo $m[1]??"";' "$1" "${2:-cmp_profile_save}"; }
post() { curl -s -b $T/$1.jar -c $T/$1.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" "${@:2}"; }
uid()  { $W user get $1 --field=ID; }
ev()   { $W eval "$1" 2>&1; }

rm -rf wp-content/plugins/community-member-planning && cp -r $REPO/community-member-planning wp-content/plugins/
$W plugin activate community-member-planning >/dev/null 2>&1
ev 'CMP_Install::maybe_upgrade();' >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
# Upload limits like a real host (PHP's built-in 2 MB default would reject photos first).
(PHP_CLI_SERVER_WORKERS=4 php -d upload_max_filesize=12M -d post_max_size=16M -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
PAGE_ID=$(ev 'echo (int) CMP_Settings::get("member_page_id");')
PAGE="$H/?page_id=$PAGE_ID"
PROF="$PAGE&cmp_tab=profile"
for u in pat quinn rex; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create pat pat@example.com --role=subscriber --user_pass=patpass1234 --display_name="Pat Smith" >/dev/null
$W user create quinn quinn@example.com --role=subscriber --user_pass=quinnpass12 >/dev/null
$W user create rex rex@example.com --role=subscriber --user_pass=rexpass1234 >/dev/null
for u in pat quinn; do ev "\$i=get_user_by('login','$u')->ID; update_user_meta(\$i,'cmp_verified_email','$u@example.com'); update_user_meta(\$i,'cmp_birth_date','1983-02-14'); CMP_Access::record_attestation(\$i);" >/dev/null; done
AGE=$(ev 'echo CMP_Birth_Date::age_from("1983-02-14");')
ev 'global $wpdb; $wpdb->query("DELETE FROM ".CMP_Install::table("profile_values")); $wpdb->query("DELETE FROM ".CMP_Install::table("profile_images")); delete_option("cmp_profile_options");' >/dev/null
ev 'foreach(array("interests"=>array("Leatherwork","Hiking","Board games"),"roles"=>array("Volunteer","Organizer"),"identity"=>array("Gay","Bi"),"availability"=>array("Weekends","Evenings"),"looking_for"=>array("Friends","Event buddies")) as $l=>$labels){ $rows=array(); foreach($labels as $i=>$x){ $rows[]=array("label"=>$x,"active"=>true,"order"=>$i); } CMP_Profile_Fields::save_list($l,$rows); }' >/dev/null
# Test photos: a large JPEG carrying a comment and an Exif block with a marker that must not survive, a small one, a PNG, a non-image, an over-size file.
convert -size 2400x1600 gradient:red-blue -set comment "SECRET-GPS-MARKER" $T/big.jpg
php -r '$d=file_get_contents($argv[1]); $exif="Exif\0\0"."MM\0*\0\0\0\x08"."GPSLatitude SECRET-GPS-MARKER"; $seg="\xFF\xE1".pack("n",strlen($exif)+2).$exif; file_put_contents($argv[1],substr($d,0,2).$seg.substr($d,2));' $T/big.jpg
convert -size 120x120 xc:green $T/small.jpg
convert -size 800x800 xc:orange $T/sq.png
echo "not a photo" > $T/fake.jpg
head -c 6000000 /dev/urandom > $T/huge.jpg

echo "== Profile options admin (Users → Member Profile Options)"
login admin admin admin
code=$(curl -s -b $T/admin.jar -o $T/opt.html -w '%{http_code}' "$H/wp-admin/users.php?page=cmp-profile-options&list=interests")
ok "admin opens the options screen" $([ "$code" = 200 ] && [ "$(has $T/opt.html 'Leatherwork')" = 1 ] && echo 1 || echo 0)
N=$(grep -o 'name="_wpnonce" value="[^"]*"' $T/opt.html | head -1 | cut -d'"' -f4)
K=$(ev 'echo CMP_Profile_Fields::raw_options("interests")[0]["key"];')
r=$(curl -s -b $T/admin.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" --data-urlencode "action=cmp_profile_options" --data-urlencode "_wpnonce=$N" --data-urlencode "list=interests" --data-urlencode "rows[0][key]=$K" --data-urlencode "rows[0][label]=Leathercraft" --data-urlencode "rows[0][active]=1" --data-urlencode "rows[0][order]=0" --data-urlencode $'bulk=Camping\nCooking')
ok "rename + add several at once" $(echo "$r" | grep -q 'cmp_notice=saved' && [ "$(ev "\$o=CMP_Profile_Fields::options('interests'); echo isset(\$o['$K']) && 'Leathercraft'===\$o['$K'] && in_array('Camping',\$o,true) && in_array('Cooking',\$o,true) ? 1:0;")" = 1 ] && echo 1 || echo 0)
ok "renamed choice keeps its key" $([ "$(ev "echo CMP_Profile_Fields::raw_options('interests')[0]['key'];")" = "$K" ] && echo 1 || echo 0)
r=$(curl -s -b $T/admin.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" --data-urlencode "action=cmp_profile_options" --data-urlencode "_wpnonce=$N" --data-urlencode "list=interests" --data-urlencode "rows[0][key]=$K" --data-urlencode "rows[0][label]=Leathercraft" --data-urlencode "rows[0][active]=1" --data-urlencode "bulk=camping")
ok "duplicate name refused (any capitals), nothing saved" $(echo "$r" | grep -q 'cmp_notice=error' && [ "$(ev 'echo count(CMP_Profile_Fields::raw_options("interests"));')" = 5 ] && echo 1 || echo 0)
ok "options change audited" $(ev 'global $wpdb; echo (int)$wpdb->get_var("SELECT COUNT(*) FROM ".CMP_Install::table("audit_log")." WHERE action=\"profile_options_changed\"")>0?1:0;')
login pat pat patpass1234
code=$(curl -s -b $T/pat.jar -o /dev/null -w '%{http_code}' -d "action=cmp_profile_options&list=interests&bulk=Hacked" "$H/wp-admin/admin-post.php")
ok "member cannot change options" $([ "$code" = 403 ] && [ "$(ev 'echo in_array("Hacked",CMP_Profile_Fields::options("interests",true),true)?0:1;')" = 1 ] && echo 1 || echo 0)

echo "== Profile tab"
get pat p1 "$PROF"
ok "member sees Home | Profile | Account" $([ "$(has $T/p1.html '>Home<')$(has $T/p1.html '>Profile<')$(has $T/p1.html '>Account<')" = 111 ] && echo 1 || echo 0)
ok "every field rendered with a Show switch" $([ "$(has $T/p1.html 'id="cmp-row-bio"')$(has $T/p1.html 'id="cmp-row-age"')$(has $T/p1.html 'id="cmp-row-member_since"')$(grep -o 'name="sp\[' $T/p1.html | wc -l | awk '{print ($1>=11)}')" = 1111 ] && echo 1 || echo 0)
ok "empty fields: switch hidden (and on, ready for when filled)" $(grep -o '<span class="cmp-show" data-cmp-show hidden><input type="hidden" name="sp\[bio\]" value="1" /><input type="checkbox" class="cmp-switch-input" id="cmp_s_bio" name="s\[bio\]" value="1" checked' $T/p1.html | wc -l | awk '{print ($1==1)}')
ok "existing member's filled fields keep Only me (switch off)" $(grep -o 'id="cmp_s_display_name" name="s\[display_name\]" value="1" />' $T/p1.html | wc -l | awk '{print ($1==1)}')
ok "age calculated from date of birth, not typed" $([ "$(has $T/p1.html "cmp-age-value\">$AGE<")$(hasnt $T/p1.html 'name="f[age]')" = 11 ] && echo 1 || echo 0)
ok "options list shows renamed choice" $(has $T/p1.html 'Leathercraft')
ok "display name read-only, links to Account" $([ "$(has $T/p1.html 'Pat Smith')$(has $T/p1.html 'cmp_tab=account#cmp-details')" = 11 ] && echo 1 || echo 0)
ok "Profile page is no-store" $(grep -qi '^cache-control:.*no-store' $T/p1.h && echo 1 || echo 0)
login rex rex rexpass1234
get rex r1 "$PROF"
ok "non-member gets the gate, not the profile" $([ "$(has $T/r1.html 'Confirm your email')$(hasnt $T/r1.html 'cmp_profile_save')" = 11 ] && echo 1 || echo 0)

N=$(pnonce $T/p1.html)
LONG=$(head -c 1001 /dev/zero | tr '\0' 'a')
r=$(post pat --data-urlencode "action=cmp_profile_save" --data-urlencode "_cmp_nonce=$N" --data-urlencode "f[bio]=$LONG" --data-urlencode "f[pronouns]=they/them" --data-urlencode "sp[pronouns]=1" --data-urlencode "s[pronouns]=1")
ok "bio over 1000 refused" $(echo "$r" | grep -q 'profile_invalid' && echo 1 || echo 0)
ok "nothing saved on error" $([ "$(ev "global \$wpdb; echo (int)\$wpdb->get_var('SELECT COUNT(*) FROM '.CMP_Install::table('profile_values').' WHERE user_id = '. $(uid pat));")" = 0 ] && echo 1 || echo 0)
get pat p2 "$PROF"
ok "errors listed, typed answers kept" $([ "$(has $T/p2.html 'id="cmp-profile-errors"')$(has $T/p2.html 'value="they/them"')$(has $T/p2.html 'aria-invalid="true"')" = 111 ] && echo 1 || echo 0)
KEYS=$(ev 'echo implode(" ",array_keys(CMP_Profile_Fields::options("interests")));')
args=(--data-urlencode "action=cmp_profile_save" --data-urlencode "_cmp_nonce=$N")
for k in $KEYS; do args+=(--data-urlencode "f[interests][keys][]=$k"); done
r=$(post pat "${args[@]}" --data-urlencode "f[interests][keys][]=not-a-real-option")
ok "unknown choices ignored (not stored)" $(echo "$r" | grep -q 'profile_saved' && [ "$(ev "echo in_array('not-a-real-option',CMP_Profiles::rows($(uid pat))['interests']['value']['keys'],true)?0:1;")" = 1 ] && echo 1 || echo 0)
R1=$(ev 'echo CMP_Profile_Fields::raw_options("roles")[0]["key"];'); I1=$(ev 'echo CMP_Profile_Fields::raw_options("identity")[0]["key"];'); A1=$(ev 'echo CMP_Profile_Fields::raw_options("availability")[1]["key"];')
# Location was set to My connections before 0.4.0's switches; switched off it must stay Connections.
ev "CMP_Profiles::save_field($(uid pat),'location',null,'connections');" >/dev/null
SP=(); for k in display_name bio pronouns location interests roles identity availability looking_for age member_since; do SP+=(--data-urlencode "sp[$k]=1"); done
FULL=(--data-urlencode "action=cmp_profile_save" "${SP[@]}" \
  --data-urlencode "f[bio]=Leather & <b>laughs</b>" --data-urlencode "s[bio]=1" \
  --data-urlencode "f[pronouns]=they/them" --data-urlencode "s[pronouns]=1" \
  --data-urlencode "f[location][city]=Columbus" --data-urlencode "f[location][region]=Ohio" --data-urlencode "f[location][country]=USA" \
  --data-urlencode "f[interests][keys][]=$K" --data-urlencode "s[interests]=1" \
  --data-urlencode "f[roles][keys][]=$R1" \
  --data-urlencode "f[identity][keys][]=$I1" --data-urlencode "f[identity][other]=Leather family" \
  --data-urlencode "f[availability]=$A1" \
  --data-urlencode "s[age]=1" --data-urlencode "s[display_name]=1" --data-urlencode "s[member_since]=1")
r=$(post pat "${FULL[@]}" --data-urlencode "_cmp_nonce=$N")
ok "valid profile saved" $(echo "$r" | grep -q 'profile_saved' && echo 1 || echo 0)
ok "values stored cleanly" $(ev "\$r=CMP_Profiles::rows($(uid pat)); echo 'Leather & laughs'===\$r['bio']['value'] && 'Columbus'===\$r['location']['value']['city'] && 'Leather family'===\$r['identity']['value']['other'] ?1:0;")
ok "switches stored: on = members, off = Only me, off keeps an old Connections" $(ev "\$r=CMP_Profiles::rows($(uid pat)); echo 'members'===\$r['bio']['visibility'] && 'members'===\$r['age']['visibility'] && 'connections'===\$r['location']['visibility'] && 'private'===\$r['roles']['visibility'] && 'private'===\$r['identity']['visibility'] ?1:0;")
ok "an empty field's choice is left alone" $(ev "\$r=CMP_Profiles::rows($(uid pat)); echo 'private'===\$r['looking_for']['visibility']?1:0;")
ok "nothing is searchable (directory not open yet)" $(ev 'global $wpdb; echo 0===(int)$wpdb->get_var("SELECT COUNT(*) FROM ".CMP_Install::table("profile_values")." WHERE searchable=1")?1:0;')
ok "audit says which fields changed, not their contents" $(ev "global \$wpdb; \$rows=\$wpdb->get_col(\"SELECT new_value FROM \".CMP_Install::table('audit_log').\" WHERE action='profile_updated' AND object_id=$(uid pat)\"); \$all=implode(' ',\$rows); echo \$rows && false!==strpos(\$all,'bio') && false===strpos(\$all,'Columbus') && false===strpos(\$all,'laughs')?1:0;")
ok "visibility change audited old -> new" $(ev "global \$wpdb; \$v=\$wpdb->get_var(\"SELECT new_value FROM \".CMP_Install::table('audit_log').\" WHERE action='profile_visibility_changed' AND object_id=$(uid pat) ORDER BY id DESC\"); echo false!==strpos(\$v,'\"bio\":\"members\"')?1:0;")
ev "CMP_Profile_Fields::save_list('roles',array(array('key'=>'$R1','label'=>'Volunteer','active'=>false,'order'=>0)));" >/dev/null
get pat p3 "$PROF"
r=$(post pat "${FULL[@]}" --data-urlencode "_cmp_nonce=$(pnonce $T/p3.html)")
ok "a retired choice the member already has is kept" $([ "$(has $T/p3.html "value=\"$R1\" checked")" = 1 ] && [ "$(ev "echo in_array('$R1',CMP_Profiles::rows($(uid pat))['roles']['value']['keys'],true)?1:0;")" = 1 ] && echo 1 || echo 0)
get quinn q0 "$PROF" >/dev/null 2>&1; login quinn quinn quinnpass12; get quinn q0 "$PROF"
ok "retired choice not offered to others" $(hasnt $T/q0.html "value=\"$R1\"")
r=$(post pat -d "action=cmp_profile_save&_cmp_nonce=bad&f[bio]=x")
ok "bad nonce refused" $(echo "$r" | grep -q 'cmp_notice=expired' && echo 1 || echo 0)

echo "== Who sees what"
get quinn q1 "$PAGE&cmp_member=$(uid pat)"
ok "another member sees fields switched on (age calculated)" $([ "$(has $T/q1.html 'Pat Smith')$(has $T/q1.html 'they/them')$(has $T/q1.html 'Leathercraft')$(has $T/q1.html "<dd>$AGE</dd>")$(hasnt $T/q1.html '1983')" = 11111 ] && echo 1 || echo 0)
ok "...but not Only me or Connections fields" $([ "$(hasnt $T/q1.html 'Columbus')$(hasnt $T/q1.html 'Leather family')" = 11 ] && echo 1 || echo 0)
ok "connections rule: not connected = not shown" $(ev "echo CMP_Profiles::can_view('location',$(uid pat),$(uid quinn))?0:1;")
ok "connections rule: connected (2.3 filter) = shown" $(ev "add_filter('cmp_are_connected','__return_true'); echo CMP_Profiles::can_view('location',$(uid pat),$(uid quinn))?1:0;")
get rex r2 "$PAGE&cmp_member=$(uid pat)"
ok "non-member can't view profiles" $(hasnt $T/r2.html 'they/them')
get anon a1 "$PAGE&cmp_member=$(uid pat)"
ok "signed-out visitor can't view profiles" $(hasnt $T/a1.html 'they/them')
get quinn q2 "$PAGE&cmp_member=$(uid rex)"
ok "profile of a non-member looks unavailable" $([ "$(has $T/q2.html "available")$(hasnt $T/q2.html 'rex@')" = 11 ] && echo 1 || echo 0)
get pat p4 "$PROF"
ok "preview shows only All-members items" $(sed -n '/id="cmp-preview"/,/<\/section>/p' $T/p4.html | grep -q 'they/them' && ! sed -n '/id="cmp-preview"/,/<\/section>/p' $T/p4.html | grep -q 'Columbus' && echo 1 || echo 0)
ok "admin (not a member) can't see private fields" $(ev "echo CMP_Profiles::can_view('location',$(uid pat),1) || CMP_Profiles::can_view('bio',$(uid pat),1)?0:1;")

echo "== Photos"
PN=$(pnonce $T/p4.html cmp_profile_image)
up(){ curl -s -b $T/$1.jar -c $T/$1.jar -o $T/up.out -w '%{redirect_url}' "$H/wp-admin/admin-post.php" -F "action=cmp_profile_image" -F "_cmp_nonce=$PN" "${@:2}"; }
r=$(up pat -F kind=avatar -F "photo=@$T/big.jpg;type=image/jpeg")
ok "photo without description refused" $(echo "$r" | grep -q 'photo_error' && [ "$(ev "echo CMP_Profile_Images::get($(uid pat),'avatar')?0:1;")" = 1 ] && echo 1 || echo 0)
get pat p5 "$PROF"
ok "the reason is shown next to the photo" $(has $T/p5.html 'or tick &quot;Decorative image&quot;\|or tick "Decorative image"')
r=$(up pat -F kind=avatar -F "photo=@$T/fake.jpg;type=image/jpeg" -F "alt=Me")
ok "non-image named .jpg refused" $(echo "$r" | grep -q 'photo_error' && echo 1 || echo 0)
r=$(up pat -F kind=avatar -F "photo=@$T/huge.jpg;type=image/jpeg" -F "alt=Me")
ok "over 5 MB refused" $(echo "$r" | grep -q 'photo_error' && echo 1 || echo 0)
r=$(up pat -F kind=avatar -F "photo=@$T/small.jpg;type=image/jpeg" -F "alt=Me")
ok "too-small photo refused" $(echo "$r" | grep -q 'photo_error' && echo 1 || echo 0)
r=$(up pat -F kind=avatar -F "photo=@$T/big.jpg;type=image/jpeg" -F "alt=Pat at the market" -F show_present=1)
ok "avatar uploaded" $(echo "$r" | grep -q 'photo_saved' && echo 1 || echo 0)
ok "stored as a 512×512 JPEG (centre-cropped)" $(ev "\$i=CMP_Profile_Images::get($(uid pat),'avatar'); echo \$i && 512==\$i->width && 512==\$i->height && 'image/jpeg'===\$i->mime?1:0;")
ev "echo CMP_Profile_Images::get($(uid pat),'avatar',true)->data;" > $T/stored.jpg
ok "camera metadata and comments stripped" $([ "$(grep -c 'SECRET-GPS-MARKER' $T/stored.jpg)" = 0 ] && grep -q 'SECRET-GPS-MARKER' $T/big.jpg && echo 1 || echo 0)
r=$(up pat -F kind=cover -F "photo=@$T/sq.png;type=image/png" -F "decorative=1" -F show_present=1 -F show=1)
ok "PNG cover saved as decorative 3:1 JPEG" $(echo "$r" | grep -q 'photo_saved' && [ "$(ev "\$i=CMP_Profile_Images::get($(uid pat),'cover'); echo \$i && \$i->decorative && 3*\$i->height==\$i->width && 'image/jpeg'===\$i->mime?1:0;")" = 1 ] && echo 1 || echo 0)
r=$(curl -s -b $T/pat.jar -o $T/ajax.json -w '%{http_code}' "$H/wp-admin/admin-post.php" -F "action=cmp_profile_image" -F "_cmp_nonce=$PN" -F kind=avatar -F cmp_ajax=1 -F "photo=@$T/big.jpg;type=image/jpeg" -F "alt=Pat again" -F show_present=1)
ok "JavaScript upload path answers JSON" $([ "$r" = 200 ] && php -r '$j=json_decode(file_get_contents($argv[1]),true); exit($j["ok"]&&false!==strpos($j["redirect"],"cmp_tab=profile")?0:1);' $T/ajax.json && echo 1 || echo 0)
r=$(curl -s -b $T/pat.jar -o $T/ajax2.json -w '%{http_code}' "$H/wp-admin/admin-post.php" -F "action=cmp_profile_image" -F "_cmp_nonce=$PN" -F kind=avatar -F cmp_ajax=1 -F "photo=@$T/fake.jpg;type=image/jpeg" -F "alt=x")
ok "JavaScript upload errors answer JSON with the reason" $([ "$r" = 400 ] && grep -q "PNG, WebP or HEIC" $T/ajax2.json && echo 1 || echo 0)
ok "photo changes audited with fingerprints" $(ev "global \$wpdb; echo (int)\$wpdb->get_var(\"SELECT COUNT(*) FROM \".CMP_Install::table('audit_log').\" WHERE object_id=$(uid pat) AND action IN ('profile_photo_added','profile_photo_replaced')\")>=3?1:0;")

AV="$H/?cmp_photo=$(uid pat)-avatar&v=x"; CV="$H/?cmp_photo=$(uid pat)-cover&v=x"
c=$(curl -s -b $T/pat.jar -D $T/img.h -o $T/img.out -w '%{http_code} %{content_type}' "$AV")
ok "owner loads own private photo" $([ "$c" = "200 image/jpeg" ] && echo 1 || echo 0)
ok "photo headers: private, no-cache, ETag, nosniff, noindex" $([ "$(grep -ci '^cache-control: private, no-cache' $T/img.h)$(grep -ci '^etag:' $T/img.h)$(grep -ci '^x-content-type-options: nosniff' $T/img.h)$(grep -ci '^x-robots-tag: noindex' $T/img.h)" = 1111 ] && echo 1 || echo 0)
E=$(grep -i '^etag:' $T/img.h | cut -d' ' -f2 | tr -d '\r')
ok "unchanged photo -> 304" $([ "$(curl -s -b $T/pat.jar -o /dev/null -w '%{http_code}' -H "If-None-Match: $E" "$AV")" = 304 ] && echo 1 || echo 0)
ok "another member: private photo -> 404" $([ "$(curl -s -b $T/quinn.jar -o /dev/null -w '%{http_code}' "$AV")" = 404 ] && echo 1 || echo 0)
ok "...even revalidating with the right ETag -> 404" $([ "$(curl -s -b $T/quinn.jar -o /dev/null -w '%{http_code}' -H "If-None-Match: $E" "$AV")" = 404 ] && echo 1 || echo 0)
ok "another member: All-members cover -> 200" $([ "$(curl -s -b $T/quinn.jar -o /dev/null -w '%{http_code}' "$CV")" = 200 ] && echo 1 || echo 0)
ok "non-member -> 404" $([ "$(curl -s -b $T/rex.jar -o /dev/null -w '%{http_code}' "$CV")" = 404 ] && echo 1 || echo 0)
c=$(curl -s -D $T/anon.h -o /dev/null -w '%{http_code}' "$CV")
ok "signed out -> 404, no-store" $([ "$c" = 404 ] && grep -qi '^cache-control:.*no-store' $T/anon.h && echo 1 || echo 0)
ok "malformed address -> 404" $([ "$(curl -s -b $T/pat.jar -o /dev/null -w '%{http_code}' "$H/?cmp_photo=../../wp-config.php")" = 404 ] && echo 1 || echo 0)
ok "photos are not in the media library" $(ev "echo 0===(int)(new WP_Query(array('post_type'=>'attachment','post_status'=>'any','author'=>$(uid pat),'fields'=>'ids')))->found_posts?1:0;")
get quinn q3 "$PAGE&cmp_member=$(uid pat)"
ok "member view: cover shown (empty alt, decorative), private avatar not" $([ "$(has $T/q3.html "cmp_photo=$(uid pat)-cover")$(has $T/q3.html 'alt=""')$(hasnt $T/q3.html "cmp_photo=$(uid pat)-avatar")" = 111 ] && echo 1 || echo 0)
get pat p6 "$PROF"
PN=$(pnonce $T/p6.html cmp_profile_image)
r=$(up pat -F kind=cover -F "decorative=1" -F show_present=1)
ok "hiding the cover takes effect at once" $(echo "$r" | grep -q 'photo_updated' && [ "$(curl -s -b $T/quinn.jar -o /dev/null -w '%{http_code}' "$CV")" = 404 ] && echo 1 || echo 0)
r=$(up pat -F kind=cover -F "remove=1")
ok "remove photo" $(echo "$r" | grep -q 'photo_removed' && [ "$(curl -s -b $T/pat.jar -o /dev/null -w '%{http_code}' "$CV")" = 404 ] && echo 1 || echo 0)

echo "== Preferences (Account tab)"
get pat a2 "$PAGE&cmp_tab=account"
AN=$(pnonce $T/a2.html cmp_account_preferences)
ok "time zone list has places, not UTC offsets" $([ "$(has $T/a2.html 'value="America/New_York"')$(hasnt $T/a2.html 'value="UTC+5"')" = 11 ] && echo 1 || echo 0)
r=$(post pat -d "action=cmp_account_preferences&_cmp_nonce=$AN&timezone=Mars/Base")
ok "made-up time zone refused" $(echo "$r" | grep -q 'timezone_invalid' && echo 1 || echo 0)
r=$(post pat --data-urlencode "action=cmp_account_preferences" --data-urlencode "_cmp_nonce=$AN" --data-urlencode "timezone=America/Chicago" --data-urlencode "notify[]=invitation" --data-urlencode "notify[]=assignment")
ok "preferences saved" $(echo "$r" | grep -q 'preferences_saved' && [ "$(ev "echo get_user_meta($(uid pat),'cmp_timezone',true);")" = America/Chicago ] && echo 1 || echo 0)
ok "switched-off category isn't delivered; kept-on one is" $(ev "\$p=$(uid pat); echo false===CMP_Notifications::add(\$p,'due_reminder','x','',false) && false!==CMP_Notifications::add(\$p,'invitation','y','',false)?1:0;")
ok "account notices can't be switched off" $(ev "echo false!==CMP_Notifications::add($(uid pat),'account','z','',false)?1:0;")

echo "== Personal data export / erasure"
ok "export includes the profile with visibility" $(ev '$r=CMP_Account::export("pat@example.com"); foreach($r["data"] as $g){ if("cmp-profile"===$g["group_id"]){ $t=wp_json_encode($g["data"]); echo false!==strpos($t,"they\/them") && false!==strpos($t,"All members")?1:0; exit; } } echo 0;')
ok "erasure removes profile values and photos" $(ev "\$p=$(uid pat); CMP_Account::erase('pat@example.com'); global \$wpdb; echo 0===(int)\$wpdb->get_var('SELECT COUNT(*) FROM '.CMP_Install::table('profile_values').' WHERE user_id='.\$p) && !CMP_Profile_Images::get(\$p,'avatar')?1:0;")

echo "== Site menu follows sign-in state"
ev '$m=wp_get_nav_menu_object("CMP Test Menu"); if($m) wp_delete_nav_menu($m->term_id); $id=wp_create_nav_menu("CMP Test Menu"); $o=get_option("cec_settings",array());
 foreach(array("Log In"=>"login_page_url","Create an Account"=>"register_page_url","Admin Login"=>"dashboard_page_url") as $t=>$k){ wp_update_nav_menu_item($id,0,array("menu-item-title"=>$t,"menu-item-url"=>CEC_Admin_Settings::get($k),"menu-item-status"=>"publish")); }
 wp_update_nav_menu_item($id,0,array("menu-item-title"=>"Member Area","menu-item-url"=>CMP_Settings::member_page_url(),"menu-item-status"=>"publish"));
 wp_update_nav_menu_item($id,0,array("menu-item-title"=>"Members Only Link","menu-item-url"=>home_url("/x/"),"menu-item-classes"=>"cmp-signed-in-only","menu-item-status"=>"publish"));' >/dev/null
menu(){ ev "wp_set_current_user($1); echo wp_nav_menu(array('menu'=>'CMP Test Menu','echo'=>false,'fallback_cb'=>false));"; }
menu 0 > $T/m0.html; menu $(uid quinn) > $T/m1.html; menu 1 > $T/m2.html
ok "signed out: Log In, Create an Account, Admin Login" $([ "$(has $T/m0.html '>Log In<')$(has $T/m0.html '>Create an Account<')$(has $T/m0.html '>Admin Login<')$(hasnt $T/m0.html 'Sign Out')$(hasnt $T/m0.html 'Members Only Link')" = 11111 ] && echo 1 || echo 0)
ok "signed-in member: Sign Out instead of Log In, no sign-up/admin links" $([ "$(has $T/m1.html '>Sign Out<')$(has $T/m1.html 'action=logout')$(hasnt $T/m1.html '>Log In<')$(hasnt $T/m1.html 'Create an Account')$(hasnt $T/m1.html 'Admin Login')$(has $T/m1.html 'Member Area')$(has $T/m1.html 'Members Only Link')" = 1111111 ] && echo 1 || echo 0)
ok "administrator keeps Admin Login, gets Sign Out" $([ "$(has $T/m2.html '>Sign Out<')$(has $T/m2.html 'Admin Login')" = 11 ] && echo 1 || echo 0)
LOGOUT=$(grep -o 'href="[^"]*action=logout[^"]*"' $T/m1.html | head -1 | cut -d'"' -f2 | sed 's/&#038;/\&/g; s/&amp;/\&/g')
ev '$m=wp_get_nav_menu_object("CMP Test Menu"); if($m) wp_delete_nav_menu($m->term_id);' >/dev/null

echo "== Account bar [cmp_account_bar]"
bar(){ ev "wp_set_current_user($1); echo do_shortcode('[cmp_account_bar]');"; }
bar 0 > $T/b0.html; bar $(uid quinn) > $T/b1.html; bar $(uid rex) > $T/b2.html; bar 1 > $T/b3.html
ok "signed out: Log In + Create an Account, no name" $([ "$(has $T/b0.html '>Log In<')$(has $T/b0.html '>Create an Account<')$(hasnt $T/b0.html 'Hello')" = 111 ] && echo 1 || echo 0)
ok "member: Hello + Member Area, Profile, Account, Sign Out" $([ "$(has $T/b1.html 'Hello, quinn')$(has $T/b1.html '>Member Area')$(has $T/b1.html '>My Profile<')$(has $T/b1.html '>Account Settings<')$(has $T/b1.html 'action=logout')$(hasnt $T/b1.html 'Calendar Admin')$(hasnt $T/b1.html 'WordPress Dashboard')" = 1111111 ] && echo 1 || echo 0)
ok "member: unread notifications counted" $(ev "CMP_Notifications::add($(uid quinn),'account','hi','',false); wp_set_current_user($(uid quinn)); echo false!==strpos(do_shortcode('[cmp_account_bar]'),'unread notification')?1:0;")
ok "not yet a member: finish joining, no Profile" $([ "$(has $T/b2.html 'Finish joining')$(hasnt $T/b2.html 'My Profile')$(has $T/b2.html 'Sign Out')" = 111 ] && echo 1 || echo 0)
ok "administrator: Calendar Admin + WordPress Dashboard" $([ "$(has $T/b3.html 'Calendar Admin')$(has $T/b3.html 'WordPress Dashboard')" = 11 ] && echo 1 || echo 0)
ok "name is escaped" $(ev "\$u=get_user_by('login','quinn'); wp_update_user(array('ID'=>\$u->ID,'first_name'=>'<script>x</script>')); wp_set_current_user(\$u->ID); \$h=do_shortcode('[cmp_account_bar]'); wp_update_user(array('ID'=>\$u->ID,'first_name'=>'')); echo false===strpos(\$h,'<script>')?1:0;")
curl -s "$H/" -o $T/home.html
ok "bar styles load site-wide" $(has $T/home.html 'account-bar.css')

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded\|fatal errors' | head -5
echo "RESULT: $PASS passed, $FAIL failed"
