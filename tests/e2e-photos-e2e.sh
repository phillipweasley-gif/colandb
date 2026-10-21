#!/usr/bin/env bash
# End-to-end encrypted chastity photos (Community Member Planning 0.33.0),
# driven in real Chromium (Playwright): key setup for wearer and keyholder,
# encrypting and uploading in the browser, decrypting for the wearer and
# keyholder, a member who isn't a recipient, unlock / wrong passphrase /
# forget on this device, share links with the key in the #fragment,
# download, passphrase change, and the server refusing unencrypted uploads.
# Needs Node with Playwright (NODE_PATH=/opt/node-tools/node_modules in the
# cloud sandbox) and PHP GD.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/e2e-photos-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/e2eph; rm -rf $T; mkdir -p $T
ev()   { $W eval "$1" 2>&1; }
member(){ ev "\$i=get_user_by('login','$1')->ID; update_user_meta(\$i,'cmp_verified_email','$1@example.com'); update_user_meta(\$i,'cmp_email_verified_at',gmdate('Y-m-d H:i:s')); update_user_meta(\$i,'cmp_birth_date','1980-01-01'); CMP_Access::record_attestation(\$i); update_user_meta(\$i,'cmp_setup_done','later'); update_user_meta(\$i,'cmp_photo_consent_at',gmdate('Y-m-d H:i:s'));" >/dev/null; }

rm -rf wp-content/plugins/community-member-planning && cp -r $REPO/community-member-planning wp-content/plugins/
$W plugin activate community-member-planning >/dev/null 2>&1
ev 'CMP_Install::maybe_upgrade();' >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
for t in locks lock_events dynamics photo_shares; do ev "global \$wpdb; \$wpdb->query('DELETE FROM '.CMP_Install::table('$t'));" >/dev/null; done
for u in ekh epup enosy; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create ekh ekh@example.com --role=subscriber --user_pass=ekhpass123456 --display_name="E2E Keyholder" >/dev/null
$W user create epup epup@example.com --role=subscriber --user_pass=epuppass12345 --display_name="E2E Pup" >/dev/null
$W user create enosy enosy@example.com --role=subscriber --user_pass=enosypass1234 >/dev/null
member ekh; member epup; member enosy
KH=$($W user get ekh --field=ID); PUP=$($W user get epup --field=ID)
ev "global \$wpdb; \$wpdb->insert(CMP_Install::table('dynamics'),array('type'=>'keyholder','proposer_id'=>$KH,'partner_id'=>$PUP,'proposer_side'=>'a','status'=>'active','proposer_show'=>1,'partner_show'=>1,'created_at'=>gmdate('Y-m-d H:i:s'))); CMP_Dynamics::grant('chastity',$KH,$PUP);
\$wpdb->insert(CMP_Install::table('locks'),array('wearer_id'=>$PUP,'keyholder_id'=>$KH,'status'=>'locked','option_key'=>'a','option_name'=>'Full lockdown','release_policy'=>'none','verify_daily'=>1,'started_at'=>gmdate('Y-m-d H:i:s'),'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
LID=$(ev "global \$wpdb; echo \$wpdb->get_var('SELECT MAX(id) FROM '.CMP_Install::table('locks'));")
php -r '$i=imagecreatetruecolor(2000,1500); imagefill($i,0,0,imagecolorallocate($i,200,40,90)); imagejpeg($i,$argv[1],90); $d=file_get_contents($argv[1]); $exif="Exif\0\0MM\0*\0\0\0\x08GPSLatitude SECRET-GPS-MARKER"; file_put_contents($argv[1],substr($d,0,2)."\xFF\xE1".pack("n",strlen($exif)+2).$exif.substr($d,2));' $T/photo.jpg
PAGE="$H/?page_id=$(ev 'echo (int) CMP_Settings::get("member_page_id");')"

NODE_PATH=${NODE_PATH:-/opt/node-tools/node_modules} node $REPO/tests/e2e-photos.js "$H" "$PAGE" "$LID" "$T" "$S" "$PUP" "$KH"
RC=$?

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
for t in locks lock_events dynamics photo_shares; do ev "global \$wpdb; \$wpdb->query('DELETE FROM '.CMP_Install::table('$t'));" >/dev/null; done
for u in ekh epup enosy; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
exit $RC
