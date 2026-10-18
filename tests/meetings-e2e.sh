#!/usr/bin/env bash
# End-to-end test of meeting notes (Community Events Calendar 1.46.0): the
# Secretary role and its front-end portal ([cec_meeting_admin]), drafts and
# approval, attendance, motions, file attachments (allowed types only),
# the public Meeting Notes page ([cec_meeting_notes]), deleting, who may do
# what, the signed-in menu link, and the portal never being cached.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/meetings-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/meet; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -L -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
ev()   { $W eval "$1" 2>&1; }
nonce(){ php -r 'preg_match("~name=\"_cec_nonce\" value=\"([^\"]+)\"~",file_get_contents($argv[1]),$m); echo $m[1]??"";' "$1"; }
# save <user> <nonce> <extra -F args...>: posts the notes form, prints the redirect.
save(){ local u=$1 n=$2; shift 2; curl -s -b $T/$u.jar -c $T/$u.jar -o $T/save.out -w '%{http_code} %{redirect_url}' "$H/wp-admin/admin-post.php" -F "action=cec_meeting_save" -F "_cec_nonce=$n" "$@"; }
mids(){ ev "echo implode(' ', get_posts(array('post_type'=>'cec_meeting','post_status'=>array('publish','draft'),'posts_per_page'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC')));"; }

for p in community-events-calendar community-member-planning; do rm -rf wp-content/plugins/$p && cp -r $REPO/$p wp-content/plugins/; $W plugin activate $p >/dev/null 2>&1; done
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
ev 'foreach ( get_posts( array( "post_type" => "cec_meeting", "post_status" => "any", "posts_per_page" => -1 ) ) as $p ) { wp_delete_post( $p->ID, true ); } foreach ( array( "zzm-rope-crew", "zzm-pup-pack", "zzm-titleholder" ) as $s ) { $t = get_term_by( "slug", $s, "cec_partner_org" ); if ( $t ) { wp_delete_term( $t->term_id, "cec_partner_org" ); } }' >/dev/null
for u in zzmsec zzmmember; do $W user delete $u --yes >/dev/null 2>&1; done
ev 'CEC_Meetings::install_role();' >/dev/null
$W user create zzmsec zzmsec@example.com --role=cec_secretary --user_pass=zzmsecpass1234 >/dev/null
$W user create zzmmember zzmmember@example.com --role=subscriber --user_pass=zzmmemberpass1 >/dev/null
SEC=$($W user get zzmsec --field=ID)
O1=$(ev '$t = wp_insert_term( "ZZM Rope Crew", "cec_partner_org", array( "slug" => "zzm-rope-crew" ) ); update_term_meta( $t["term_id"], "cec_kind", "organization" ); echo $t["term_id"];')
O2=$(ev '$t = wp_insert_term( "ZZM Pup Pack", "cec_partner_org", array( "slug" => "zzm-pup-pack" ) ); update_term_meta( $t["term_id"], "cec_kind", "organization" ); echo $t["term_id"];')
O3=$(ev '$t = wp_insert_term( "ZZM Titleholder", "cec_partner_org", array( "slug" => "zzm-titleholder" ) ); update_term_meta( $t["term_id"], "cec_kind", "titleholder" ); echo $t["term_id"];')
ev 'CEC_Admin_Settings::maybe_create_pages();' >/dev/null
PORTAL=$(ev "echo CEC_Admin_Settings::get('meeting_admin_page_url');")
PUBLIC=$(ev "echo CEC_Admin_Settings::get('meeting_notes_page_url');")
: > wp-content/debug.log
printf '%%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%%%EOF\n' > $T/agenda.pdf
printf '<?php echo "x"; ?>' > $T/evil.pdf.php
cp $T/evil.pdf.php $T/script.exe

echo "== Pages and access"
ok "Meeting Notes and Meeting Notes Admin pages are created and linked in Settings" $([ -n "$PORTAL" ] && [ -n "$PUBLIC" ] && [ "$(ev "echo get_post_field('post_content',url_to_postid('$PUBLIC')).'|'.get_post_field('post_content',url_to_postid('$PORTAL'));")" = "[cec_meeting_notes]|[cec_meeting_admin]" ] && echo 1 || echo 0)
get anon p0 "$PORTAL"
ok "signed out: the portal asks to sign in, and is never cached" $([ "$(has $T/p0.html 'Sign in to manage meeting notes')$(grep -qi 'cache-control:.*no-cache' $T/p0.h && echo 1 || echo 0)" = 11 ] && echo 1 || echo 0)
login zzmmember zzmmember zzmmemberpass1
get zzmmember p1 "$PORTAL"
ok "a member: only the Secretary and administrators" $([ "$(has $T/p1.html 'Only the Secretary and site administrators')$(hasnt $T/p1.html 'Add meeting notes')" = 11 ] && echo 1 || echo 0)
login zzmsec zzmsec zzmsecpass1234
get zzmsec p2 "$PORTAL"
ok "the Secretary sees the portal with Add meeting notes" $([ "$(has $T/p2.html 'Add meeting notes')$(has $T/p2.html 'No meeting notes yet')" = 11 ] && echo 1 || echo 0)
ok "the Secretary has a 'Meeting Notes Admin' link in the signed-in menu; a member doesn't" $([ "$(ev "\$i=apply_filters('cmp_account_bar_items',array(),get_user_by('login','zzmsec')); echo wp_json_encode(wp_list_pluck(\$i,0));" | grep -c 'Meeting Notes Admin')$(ev "\$i=apply_filters('cmp_account_bar_items',array(),get_user_by('login','zzmmember')); echo count(\$i);")" = 10 ] && echo 1 || echo 0)
loc=$(curl -s -b $T/zzmsec.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/")
ok "the Secretary is sent from wp-admin to the portal" $([ "$loc" = "$PORTAL" ] && echo 1 || echo 0)

echo "== Writing notes (draft)"
get zzmsec new "$PORTAL$( [[ $PORTAL == *\?* ]] && echo '&' || echo '?' )cec_meeting=new"
ok "the form: date, type, title, notes editor, organizations (not titleholders), motions, files, status" $([ "$(has $T/new.html 'name="date"')$(has $T/new.html 'name="type"')$(has $T/new.html 'name="title"')$(has $T/new.html 'name="notes"')$(has $T/new.html "name=\"orgs\[\]\" value=\"$O1\"")$(hasnt $T/new.html "value=\"$O3\"")$(has $T/new.html 'name="motions\[0\]\[text\]"')$(has $T/new.html 'name="files\[\]"')$(has $T/new.html 'name="status" value="approved"')" = 111111111 ] && echo 1 || echo 0)
N=$(nonce $T/new.html)
r=$(save zzmsec $N -F "meeting=0" -F "date=2026-10-14" -F "type=general" -F "title=" --form-string "notes=<p>Budget <strong>approved</strong>.</p><script>alert(1)</script>" \
	-F "orgs[]=$O1" -F "orgs[]=$O2" -F "orgs[]=$O3" -F "others=3 community members" \
	-F "motions[0][text]=Buy a bootblack stand" -F "motions[0][moved]=Foxxy" -F "motions[0][seconded]=Neo" -F "motions[0][result]=passed" -F "motions[0][for]=5" -F "motions[0][against]=1" -F "motions[0][abstain]=1" \
	-F "motions[1][text]=" -F "motions[1][moved]=Nobody" \
	-F "motions[2][text]=Move the meeting to Thursdays" -F "motions[2][result]=tabled" \
	-F "files[]=@$T/agenda.pdf;type=application/pdf" -F "files[]=@$T/script.exe;type=application/octet-stream" -F "status=draft")
M1=$(mids | awk '{print $NF}')
ok "saved as a draft, back on the portal" $(echo "$r" | grep -q 'cec_msg=mt_draft' && [ "$(ev "echo get_post_status($M1);")" = draft ] && echo 1 || echo 0)
ok "...an empty title becomes 'General Meeting, Oct 14, 2026'; the script is stripped from the notes" $([ "$(ev "echo get_the_title($M1);")" = "General Meeting, Oct 14, 2026" ] && [ "$(ev "echo strpos(get_post_field('post_content',$M1),'<script')===false && strpos(get_post_field('post_content',$M1),'<strong>approved</strong>')!==false ? 1 : 0;")" = 1 ] && echo 1 || echo 0)
ok "...two organizations present (the titleholder ignored) and the others" $([ "$(ev "echo implode(',',get_post_meta($M1,'_cec_mt_orgs',true)).'|'.get_post_meta($M1,'_cec_mt_others',true);")" = "$O1,$O2|3 community members" ] && echo 1 || echo 0)
ok "...two motions (an empty one dropped) with mover, seconder, result and votes" $([ "$(ev "\$m=get_post_meta($M1,'_cec_mt_motions',true); echo count(\$m).'|'.\$m[0]['moved'].'|'.\$m[0]['seconded'].'|'.\$m[0]['for'].'-'.\$m[0]['against'].'-'.\$m[0]['abstain'].'|'.\$m[1]['result'];")" = "2|Foxxy|Neo|5-1-1|tabled" ] && echo 1 || echo 0)
ok "...the PDF attached; the .exe refused and named in the notice" $([ "$(ev "echo count(get_post_meta($M1,'_cec_mt_files',true));")" = 1 ] && echo "$r" | grep -q 'cec_files=script.exe' && echo 1 || echo 0)
get anon pub0 "$PUBLIC"
ok "a draft isn't on the public page" $([ "$(hasnt $T/pub0.html 'Oct 14, 2026')$(has $T/pub0.html 'No meeting notes have been posted yet')" = 11 ] && echo 1 || echo 0)
get zzmsec p3 "$PORTAL"
ok "the portal lists it as Draft with Edit and Delete" $([ "$(has $T/p3.html 'General Meeting, Oct 14, 2026')$(has $T/p3.html 'is-draft')$(has $T/p3.html "cec_meeting=$M1")$(has $T/p3.html 'cec_meeting_delete')" = 1111 ] && echo 1 || echo 0)

echo "== Approving, and the public page"
get zzmsec ed "$PORTAL$( [[ $PORTAL == *\?* ]] && echo '&' || echo '?' )cec_meeting=$M1"
ok "editing shows what was saved, with the file and a Remove box" $([ "$(has $T/ed.html 'value="2026-10-14"')$(has $T/ed.html 'Buy a bootblack stand')$(has $T/ed.html 'agenda')$(has $T/ed.html 'name="remove_files\[\]"')" = 1111 ] && echo 1 || echo 0)
N=$(nonce $T/ed.html)
r=$(save zzmsec $N -F "meeting=$M1" -F "date=2026-10-14" -F "type=general" -F "title=October General Meeting" --form-string "notes=<p>Budget <strong>approved</strong>.</p>" -F "orgs[]=$O1" -F "orgs[]=$O2" -F "others=3 community members" \
	-F "motions[0][text]=Buy a bootblack stand" -F "motions[0][moved]=Foxxy" -F "motions[0][seconded]=Neo" -F "motions[0][result]=passed" -F "motions[0][for]=5" -F "motions[0][against]=1" -F "motions[0][abstain]=1" \
	-F "motions[1][text]=Move the meeting to Thursdays" -F "motions[1][result]=tabled" -F "status=approved")
ok "approved and published" $(echo "$r" | grep -q 'cec_msg=mt_approved' && [ "$(ev "echo get_post_status($M1);")" = publish ] && echo 1 || echo 0)
M2=$(ev "\$id=wp_insert_post(array('post_type'=>'cec_meeting','post_status'=>'publish','post_title'=>'December 2025 Board Meeting','post_content'=>'Older notes.')); update_post_meta(\$id,'_cec_mt_date','2025-12-09'); update_post_meta(\$id,'_cec_mt_type','board'); echo \$id;")
get anon pub "$PUBLIC"
ok "the public page: year headings, newest first, the newest open" $(php -r '$h=file_get_contents($argv[1]); $a=strpos($h,">2026<"); $b=strpos($h,">2025<"); $c=strpos($h,"October General Meeting"); $d=strpos($h,"December 2025 Board Meeting"); echo ($a!==false && $b!==false && $a<$c && $c<$b && $b<$d && preg_match("~<details class=\"cec-mt-item\" id=\"meeting-\d+\" open><summary>[^<]*<span class=\"cec-mt-date\">Wed, Oct 14, 2026~",$h)) ? 1 : 0;' $T/pub.html)
ok "...type, organizations present and others" $([ "$(has $T/pub.html 'General Meeting</span>')$(has $T/pub.html 'Board Meeting</span>')$(has $T/pub.html 'ZZM Rope Crew, ZZM Pup Pack; 3 community members')" = 111 ] && echo 1 || echo 0)
ok "...the notes with their formatting" $(has $T/pub.html 'Budget <strong>approved</strong>.')
ok "...motions: mover, seconder, result and the vote" $([ "$(has $T/pub.html 'Moved by Foxxy, seconded by Neo. <strong class="cec-mt-result is-passed">Passed</strong> (5 for, 1 against, 1 abstaining)')$(has $T/pub.html 'is-tabled">Tabled</strong>')" = 11 ] && echo 1 || echo 0)
ok "...the attached PDF, labelled" $([ "$(has $T/pub.html 'agenda')$(grep -q '\.pdf" target="_blank"' $T/pub.html && echo 1 || echo 0)$(has $T/pub.html '>PDF<')" = 111 ] && echo 1 || echo 0)

echo "== Removing a file, deleting notes, refusals"
get zzmsec ed2 "$PORTAL$( [[ $PORTAL == *\?* ]] && echo '&' || echo '?' )cec_meeting=$M1"
FID=$(ev "echo get_post_meta($M1,'_cec_mt_files',true)[0];")
r=$(save zzmsec $(nonce $T/ed2.html) -F "meeting=$M1" -F "date=2026-10-14" -F "type=general" -F "title=October General Meeting" --form-string "notes=<p>Budget.</p>" -F "remove_files[]=$FID" -F "status=approved")
ok "removing the file deletes it" $([ "$(ev "echo count(get_post_meta($M1,'_cec_mt_files',true)).'|'.(get_post($FID) ? 'still' : 'gone');")" = "0|gone" ] && echo 1 || echo 0)
r=$(save zzmsec $(nonce $T/ed2.html) -F "meeting=0" -F "date=not-a-date" -F "title=Bad")
ok "a missing or invalid date: nothing saved, a clear message" $(echo "$r" | grep -q 'cec_msg=mt_invalid' && ! ev "echo implode(',',wp_list_pluck(get_posts(array('post_type'=>'cec_meeting','post_status'=>'any','posts_per_page'=>-1)),'post_title'));" | grep -q 'Bad' && echo 1 || echo 0)
r=$(save zzmmember "$(nonce $T/ed2.html)" -F "meeting=0" -F "date=2026-10-20" -F "title=Member try" -F "status=approved")
ok "a member can't save notes (403), even with a nonce from the page" $(echo "$r" | grep -q '^403' && ! ev "echo implode(',',wp_list_pluck(get_posts(array('post_type'=>'cec_meeting','post_status'=>'any','posts_per_page'=>-1)),'post_title'));" | grep -q 'Member try' && echo 1 || echo 0)
get zzmsec p4 "$PORTAL"
DN=$(php -r 'preg_match("~name=\"meeting\" value=\"'$M2'\"><input type=\"hidden\" id=\"_cec_nonce\" name=\"_cec_nonce\" value=\"([^\"]+)\"~",file_get_contents($argv[1]),$m); echo $m[1]??"";' $T/p4.html)
r=$(curl -s -b $T/zzmsec.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" -F "action=cec_meeting_delete" -F "meeting=$M2" -F "_cec_nonce=$DN")
get anon pub2 "$PUBLIC"
ok "deleting moves the notes to the trash and off the public page" $(echo "$r" | grep -q 'cec_msg=mt_deleted' && [ "$(ev "echo get_post_status($M2);")" = trash ] && [ "$(hasnt $T/pub2.html 'December 2025 Board Meeting')" = 1 ] && echo 1 || echo 0)

echo "== PHP errors"
ok "no PHP warnings in the pages" $(cat $T/*.html | grep -qi '<b>Warning</b>\|<b>Notice</b>\|<b>Fatal error</b>\|<b>Deprecated</b>' && echo 0 || echo 1)
ok "no plugin warnings in debug.log" $(grep -E "Warning|Fatal|Notice|Deprecated" wp-content/debug.log 2>/dev/null | grep -q "plugins/community-events-calendar" && echo 0 || echo 1)

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev 'foreach ( get_posts( array( "post_type" => "cec_meeting", "post_status" => "any", "posts_per_page" => -1 ) ) as $p ) { foreach ( (array) get_post_meta( $p->ID, "_cec_mt_files", true ) as $f ) { wp_delete_attachment( (int) $f, true ); } wp_delete_post( $p->ID, true ); }' >/dev/null
for o in $O1 $O2 $O3; do ev "wp_delete_term($o,'cec_partner_org');" >/dev/null; done
for u in zzmsec zzmmember; do $W user delete $u --yes >/dev/null 2>&1; done
echo "== $PASS passed, $FAIL failed"
[ $FAIL = 0 ]
