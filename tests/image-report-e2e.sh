#!/usr/bin/env bash
# End-to-end test of intimate-image reports (Community Member Planning 0.32.0):
# the public [cmp_image_report] form, spam limits, emails, the admin screen
# (Users → Image reports), closing a report, "Remove a private photo" by link,
# privacy export and the 3-year clean-up.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/image-report-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/ir; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
ev()   { $W eval "$1" 2>&1; }
q()    { ev "global \$wpdb; echo \$wpdb->get_var(\"$1\");"; }
mails(){ if [ -f wp-content/mail.log ]; then wc -l < wp-content/mail.log; else echo 0; fi; }

rm -rf wp-content/plugins/community-member-planning && cp -r $REPO/community-member-planning wp-content/plugins/
$W plugin activate community-member-planning >/dev/null 2>&1
ev 'CMP_Install::maybe_upgrade();' >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
R_T=$(ev "echo CMP_Install::table('image_reports');")
ev "global \$wpdb; \$wpdb->query(\"DELETE FROM $R_T\");" >/dev/null
PID=$($W post create --post_type=page --post_status=publish --post_title="Report an image" --post_content="[cmp_image_report]" --porcelain)
URL="$H/?page_id=$PID"
rm -f wp-content/mail.log
$W user delete irmember --yes >/dev/null 2>&1
$W user create irmember irmember@example.com --role=subscriber --user_pass=irpass1234567 >/dev/null

# send <file> [curl -d args]: get a fresh form, wait, post it; print the redirect.
fresh(){ curl -s -o $T/form.html "$URL"; grep -o 'name="t" value="[^"]*"' $T/form.html | cut -d'"' -f4; }
post(){ local t=$1; shift; curl -s -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" -d "action=cmp_image_report" --data-urlencode "back=$URL" -d "t=$t" "$@"; }
GOOD=(-d "relation=self" --data-urlencode "location=https://colandb.com/?cmp_postpic=999 in the feed" -d "details=Posted by my ex" -d "email=victim@example.com" -d "name=Vic" -d "statement=1" --data-urlencode "signature=Victoria Example")

echo "== Public form"
TS=$(fresh)
ok "form shows the relation choices, statement, signature, no account needed" $([ "$(has $T/form.html 'name="relation" value="minor"')$(has $T/form.html 'name="statement"')$(has $T/form.html 'name="signature"')$(has $T/form.html 'name="website"')$(has $T/form.html 'report.cybertip.org')" = 11111 ] && echo 1 || echo 0)
r=$(post "$TS" "${GOOD[@]}")
ok "sent too fast (under 3 seconds): refused, nothing stored" $(echo "$r" | grep -q 'cmp_ir=invalid' && [ "$(q "SELECT COUNT(*) FROM $R_T")" = 0 ] && echo 1 || echo 0)
sleep 3
r=$(post "$TS" "${GOOD[@]}" -d "website=http://spam.example")
ok "honeypot filled: refused" $(echo "$r" | grep -q 'cmp_ir=invalid' && [ "$(q "SELECT COUNT(*) FROM $R_T")" = 0 ] && echo 1 || echo 0)
r=$(post "${TS%?}x" "${GOOD[@]}")
ok "tampered timestamp: refused" $(echo "$r" | grep -q 'cmp_ir=invalid' && [ "$(q "SELECT COUNT(*) FROM $R_T")" = 0 ] && echo 1 || echo 0)
r=$(post "$TS" -d "relation=self" -d "location=x" -d "email=victim@example.com" -d "statement=1" -d "signature=")
ok "no signature: refused" $(echo "$r" | grep -q 'cmp_ir=invalid' && echo 1 || echo 0)
r=$(post "$TS" -d "relation=self" -d "location=x" -d "email=victim@example.com" -d "signature=Victoria Example")
ok "no good-faith statement: refused" $(echo "$r" | grep -q 'cmp_ir=invalid' && [ "$(q "SELECT COUNT(*) FROM $R_T")" = 0 ] && echo 1 || echo 0)
M0=$(mails)
r=$(post "$TS" "${GOOD[@]}")
RID=$(q "SELECT MAX(id) FROM $R_T")
ok "valid report stored with everything" $(echo "$r" | grep -q 'cmp_ir=sent' && [ "$(q "SELECT CONCAT(relation,'|',email,'|',name,'|',signature,'|',status) FROM $R_T WHERE id=$RID")" = "self|victim@example.com|Vic|Victoria Example|new" ] && [ "$(q "SELECT LENGTH(ip_hash) FROM $R_T WHERE id=$RID")" = 64 ] && echo 1 || echo 0)
ok "...team emailed (48-hour deadline) and reporter got a confirmation" $([ $(( $(mails) - M0 )) = 2 ] && grep -q 'Urgent: intimate image report' wp-content/mail.log && grep -q 'within 48 hours' wp-content/mail.log && grep -q 'We received your image report' wp-content/mail.log && echo 1 || echo 0)
curl -s -o $T/sent.html "$URL&cmp_ir=sent"
ok "thank-you message shown" $(has $T/sent.html 'Your report was sent')
TS=$(fresh); sleep 3
r=$(post "$TS" -d "relation=minor" -d "location=member Foo" -d "email=worried@example.com" -d "statement=1" -d "signature=Worried Person")
ok "possible minor: flagged subject and don't-open guidance" $(echo "$r" | grep -q 'cmp_ir=sent' && grep -q 'may show someone under 18' wp-content/mail.log && grep -q "DON'T open, download" wp-content/mail.log && echo 1 || echo 0)
ev "global \$wpdb; for(\$i=0;\$i<3;\$i++) \$wpdb->insert('$R_T',array('created_at'=>gmdate('Y-m-d H:i:s'),'email'=>'victim@example.com','relation'=>'self','location'=>'x','status'=>'declined'));" >/dev/null
TS=$(fresh); sleep 3
r=$(post "$TS" "${GOOD[@]}")
ok "6th report from the same email in a day: limit" $(echo "$r" | grep -q 'cmp_ir=limit' && echo 1 || echo 0)

mkdir -p wp-content/mu-plugins && echo "<?php add_action('send_headers', function () { header('ec-cdn-cache-control: public, max-age=300, s-maxage=604800'); });" > wp-content/mu-plugins/zz-cdn-hint.php
H1=$(curl -s -D - -o /dev/null "$URL"); H2=$(curl -s -D - -o /dev/null "$H/?page_id=$($W post list --post_type=page --field=ID --posts_per_page=1 --post__not_in=$PID 2>/dev/null | head -1)")
rm -f wp-content/mu-plugins/zz-cdn-hint.php
ok "the report page is never cached (no-store, no CDN hint); other pages keep the hint (0.32.1)" $(echo "$H1" | grep -qi '^cache-control: no-store' && ! echo "$H1" | grep -qi '^ec-cdn-cache-control' && echo "$H2" | grep -qi '^ec-cdn-cache-control' && echo 1 || echo 0)
OLDT=$(ev "\$t=time()-3*DAY_IN_SECONDS; echo \$t.'.'.substr(hash_hmac('sha256','cmp-ir|'.\$t,wp_salt('nonce')),0,16);")
r=$(post "$OLDT" -d "relation=behalf" -d "location=old form" -d "email=old@example.com" -d "statement=1" -d "signature=Old Form")
ok "a form opened 3 days ago still sends (0.32.1)" $(echo "$r" | grep -q 'cmp_ir=sent' && echo 1 || echo 0)

echo "== Admin"
login admin admin admin
curl -s -b $T/admin.jar -o $T/adm.html "$H/wp-admin/users.php?page=cmp-image-reports"
ok "admin list: the report, time left, the minor warning, Remove a private photo" $([ "$(has $T/adm.html 'Victoria Example')$(has $T/adm.html ' left')$(has $T/adm.html 'do not open or download')$(has $T/adm.html 'Remove a private photo')" = 1111 ] && echo 1 || echo 0)
curl -s -b $T/admin.jar -o $T/dash.html "$H/wp-admin/index.php"
ok "admin notice about waiting reports" $(has $T/dash.html 'intimate image reports are waiting')
AN=$(grep -o 'name="_wpnonce" value="[^"]*"' $T/adm.html | head -1 | cut -d'"' -f4)
login irmember irmember irpass1234567
c=$(curl -s -b $T/irmember.jar -o /dev/null -w '%{http_code}' "$H/wp-admin/admin-post.php" -d "action=cmp_image_report_admin&_wpnonce=$AN&do=close&report=$RID&status=removed")
ok "a non-admin can't close a report" $([ "$(q "SELECT status FROM $R_T WHERE id=$RID")" = new ] && echo 1 || echo 0)
M0=$(mails)
curl -s -b $T/admin.jar -o /dev/null "$H/wp-admin/admin-post.php" -d "action=cmp_image_report_admin&_wpnonce=$AN&_wp_http_referer=/wp-admin/users.php&do=close&report=$RID&status=removed&tell=1" --data-urlencode "note=Removed the feed photo and suspended the account."
ok "admin marks it Removed with a note; reporter emailed with the note" $([ "$(q "SELECT status FROM $R_T WHERE id=$RID")" = removed ] && [ $(( $(mails) - M0 )) = 1 ] && grep -q 'We have removed the image you reported' wp-content/mail.log && grep -q 'suspended the account' wp-content/mail.log && echo 1 || echo 0)

echo "== Remove a private photo by link"
JPG=$T/p.jpg; php -r '$i=imagecreatetruecolor(50,50); imagejpeg($i,$argv[1]);' $JPG
ev "global \$wpdb; \$wpdb->query('DELETE FROM '.CMP_Install::table('task_entries').' WHERE task_id = 0');" >/dev/null # an earlier run's row would block the insert (unique task + day)
SETUP=$(ev "global \$wpdb; \$j=file_get_contents('$JPG'); \$u=get_user_by('login','irmember')->ID;
\$wpdb->insert(CMP_Install::table('locks'),array('wearer_id'=>\$u,'keyholder_id'=>0,'status'=>'locked','option_key'=>'a','option_name'=>'x','release_policy'=>'none','started_at'=>gmdate('Y-m-d H:i:s'),'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'))); \$l=\$wpdb->insert_id;
\$wpdb->insert(CMP_Install::table('lock_events'),array('lock_id'=>\$l,'actor_id'=>\$u,'type'=>'verification','photo'=>CMP_Photo_Vault::seal(\$j,\$l,\$u),'photo_sha'=>hash('sha256',\$j),'created_at'=>gmdate('Y-m-d H:i:s'))); \$e=\$wpdb->insert_id;
\$wpdb->insert(CMP_Install::table('photo_shares'),array('wearer_id'=>\$u,'lock_id'=>\$l,'event_id'=>\$e,'salt'=>str_repeat('a',32),'created_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600))); \$s=\$wpdb->insert_id;
\$wpdb->insert(CMP_Install::table('task_entries'),array('task_id'=>0,'program_id'=>0,'member_id'=>\$u,'day'=>gmdate('Y-m-d'),'status'=>'done','photo'=>CMP_Photo_Vault::seal(\$j,0,\$u,'hwproof'),'photo_sha'=>hash('sha256',\$j),'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'))); \$h=\$wpdb->insert_id;
\$wpdb->insert(CMP_Install::table('post_photos'),array('post_id'=>0,'position'=>0,'photo'=>\$j,'photo_sha'=>'x')); \$f=\$wpdb->insert_id;
echo \"\$l \$e \$s \$h \$f\";")
read LK EV SH HW FP <<< "$SETUP"
rm_url(){ curl -s -b $T/admin.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" -d "action=cmp_image_report_admin&_wpnonce=$AN&_wp_http_referer=/wp-admin/users.php&do=remove&report=$RID" --data-urlencode "photo_url=$1"; }
r=$(rm_url "https://colandb.com/?cmp_share=$SH.whatever")
ok "a share link removes its chastity photo (history kept as 'deleted', links gone)" $(echo "$r" | grep -q 'Chastity+photo+deleted\|Chastity%20photo%20deleted' && [ "$(q "SELECT CONCAT(IFNULL(photo,'NULL'),'|',photo_sha) FROM $(ev "echo CMP_Install::table('lock_events');") WHERE id=$EV")" = "NULL|deleted" ] && [ "$(q "SELECT COUNT(*) FROM $(ev "echo CMP_Install::table('photo_shares');") WHERE id=$SH")" = 0 ] && echo 1 || echo 0)
r=$(rm_url "https://colandb.com/?cmp_lockpic=$EV")
ok "the same photo again: nothing found, nothing deleted" $(echo "$r" | grep -q 'No+stored+photo\|No%20stored%20photo' && echo 1 || echo 0)
r=$(rm_url "https://colandb.com/?cmp_proof=$HW&v=abc")
ok "a homework photo link" $([ "$(q "SELECT photo_sha FROM $(ev "echo CMP_Install::table('task_entries');") WHERE id=$HW")" = deleted ] && echo 1 || echo 0)
r=$(rm_url "https://colandb.com/?cmp_postpic=$FP")
ok "a feed photo link" $([ "$(q "SELECT COUNT(*) FROM $(ev "echo CMP_Install::table('post_photos');") WHERE id=$FP")" = 0 ] && echo 1 || echo 0)
ok "removals are audit-logged and noted on the report" $([ "$(q "SELECT COUNT(*) FROM $(ev "echo CMP_Install::table('audit_log');") WHERE action IN ('lock_photo_deleted','homework_photo_deleted','feed_photo_deleted')")" -ge 3 ] && [ "$(q "SELECT note LIKE '%Feed photo deleted%' FROM $R_T WHERE id=$RID")" = 1 ] && echo 1 || echo 0)

echo "== Privacy and clean-up"
ok "export by email lists reports that address made (no account needed)" $(ev "\$j=wp_json_encode(CMP_Account::export('victim@example.com'),JSON_UNESCAPED_SLASHES); echo false!==strpos(\$j,'Image report you made') && false!==strpos(\$j,'cmp_postpic=999')?1:0;")
ev "global \$wpdb; \$wpdb->update('$R_T',array('created_at'=>gmdate('Y-m-d H:i:s',time()-1100*DAY_IN_SECONDS)),array('id'=>$RID));" >/dev/null
ev 'CMP_Retention::run();' >/dev/null
ok "reports older than 3 years are cleared; newer kept" $([ "$(q "SELECT COUNT(*) FROM $R_T WHERE id=$RID")" = 0 ] && [ "$(q "SELECT COUNT(*) FROM $R_T")" -ge 1 ] && echo 1 || echo 0)

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev "global \$wpdb; \$wpdb->query(\"DELETE FROM $R_T\"); \$wpdb->query('DELETE FROM '.CMP_Install::table('task_entries').' WHERE task_id = 0');" >/dev/null
$W post delete $PID --force >/dev/null 2>&1; $W user delete irmember --yes >/dev/null 2>&1
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
