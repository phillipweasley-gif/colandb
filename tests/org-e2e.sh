#!/usr/bin/env bash
# End-to-end test of organization & titleholder listings (Community Events
# Calendar 1.34.0) and member ↔ group links (Community Member Planning
# 0.20.0): the [cec_submit_org] form (validation, kept answers, honeypot,
# Telegram invite links, image checks, rate limit), the review screen
# (approve / decline / existing group), the /partner/<slug>/ page, the
# [cec_partner_orgs] grid, old page redirects, emails, member links (from a
# submission and from the Profile tab, who sees them), export and erase.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/org-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/org; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -L -b $T/$1.jar -c $T/$1.jar -D $T/$2.h -o $T/$2.html "$3"; }
uid()  { $W user get $1 --field=ID 2>/dev/null; }
ev()   { $W eval "$1" 2>&1; }
member(){ ev "\$i=get_user_by('login','$1')->ID; update_user_meta(\$i,'cmp_verified_email','$1@example.com'); update_user_meta(\$i,'cmp_email_verified_at',gmdate('Y-m-d H:i:s')); update_user_meta(\$i,'cmp_birth_date','1980-01-01'); CMP_Access::record_attestation(\$i); update_user_meta(\$i,'cmp_setup_done','later');" >/dev/null; }
q()    { ev "global \$wpdb; echo \$wpdb->get_var(\"$1\");"; }
# submit <ip> <extra curl args...>: posts the public form, prints the redirect.
submit(){ local ip=$1; shift; curl -s -o /dev/null -w '%{redirect_url}' -H "X-Test-IP: $ip" "$H/wp-admin/admin-post.php" -F "action=cec_submit_org" -F "cec_back=$FORM" "$@"; }
mails(){ if [ -f wp-content/mail.log ]; then wc -l < wp-content/mail.log; else echo 0; fi; }

for p in community-events-calendar community-member-planning; do rm -rf wp-content/plugins/$p && cp -r $REPO/$p wp-content/plugins/; $W plugin activate $p >/dev/null 2>&1; done
# The built-in server gives every request 127.0.0.1; let the test pick a
# client address so the per-address rate limit can be checked.
cat > wp-content/mu-plugins/test-client-ip.php <<'PHP'
<?php
if ( ! empty( $_SERVER['HTTP_X_TEST_IP'] ) ) { $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_TEST_IP']; }
PHP
ev 'CMP_Install::maybe_upgrade();' >/dev/null
OLD_PERMA=$($W option get permalink_structure 2>/dev/null)
$W rewrite structure '/%postname%/' >/dev/null 2>&1; $W rewrite flush >/dev/null 2>&1
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
GL=$(ev 'echo CMP_Install::table("group_links");')
PAGE_ID=$(ev 'echo (int) CMP_Settings::get("member_page_id");')

# Clean slate.
ev 'foreach ( get_posts( array( "post_type" => "cec_org_submission", "post_status" => "any", "numberposts" => -1 ) ) as $p ) { wp_delete_post( $p->ID, true ); } foreach ( array( "zz-rope-collective", "zz-foxxy-test", "zz-existing-group", "zz-declined-group" ) as $s ) { $t = get_term_by( "slug", $s, "cec_partner_org" ); if ( $t ) { wp_delete_term( $t->term_id, "cec_partner_org" ); } } foreach ( array( "zz-old-page" ) as $s ) { $p = get_page_by_path( $s ); if ( $p ) { wp_delete_post( $p->ID, true ); } } global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \"%cec_org_rate_%\" OR option_name LIKE \"%cec_org_form_%\"" );' >/dev/null
for u in organn orgbo orgcy orgdee; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create organn organn@example.com --role=subscriber --user_pass=organnpass123 --display_name="Org Ann" >/dev/null
$W user create orgbo orgbo@example.com --role=subscriber --user_pass=orgbopass1234 --display_name="Org Bo" >/dev/null
$W user create orgcy orgcy@example.com --role=subscriber --user_pass=orgcypass1234 --display_name="Org Cy" >/dev/null
$W user create orgdee orgdee@example.com --role=subscriber --user_pass=orgdeepass123 --display_name="Org Dee" >/dev/null
member organn; member orgbo; member orgcy
ANN=$(uid organn); BO=$(uid orgbo); CY=$(uid orgcy); DEE=$(uid orgdee)
FORM_ID=$($W post create --post_type=page --post_status=publish --post_title="ZZ Submit" --post_content='[cec_submit_org]' --porcelain)
GRID_ID=$($W post create --post_type=page --post_status=publish --post_title="ZZ Grid" --post_content='[cec_partner_orgs]' --porcelain)
FORM=$(ev "echo get_permalink($FORM_ID);")
EXIST=$(ev 'echo wp_insert_term( "ZZ Existing Group", "cec_partner_org", array( "slug" => "zz-existing-group" ) )["term_id"];')
OLDP=$($W post create --post_type=page --post_status=draft --post_name=zz-existing-group --post_title="Old hand-built page" --porcelain)
php -r '$i=imagecreatetruecolor(400,400); imagefill($i,0,0,imagecolorallocate($i,255,80,215)); imagepng($i,$argv[1]);' $T/logo.png
printf 'not an image' > $T/fake.png
rm -f wp-content/mail.log

echo "== The form"
get anon f1 "$FORM"
ok "form shows both kinds, the contact block, the honeypot and the member field" $([ "$(has $T/f1.html 'value="titleholder"')$(has $T/f1.html 'name="contact_email"')$(has $T/f1.html 'cec_org_website_confirm')$(has $T/f1.html 'name="member"')$(has $T/f1.html 'enctype="multipart/form-data"')" = 11111 ] && echo 1 || echo 0)
ok "form uses the pages' stylesheet" $(has $T/f1.html 'css/orgs.css')

r=$(submit 10.0.0.1 -F "kind=organization" -F "name=ZZ Rope Collective" -F "description=" -F "contact_name=Pat" -F "contact_email=nope" -F "social[telegram]=https://t.me/c/3923571403/1" -F "social[instagram]=https://evil.example.com/x")
ok "missing answers send you back with an error key" $(echo "$r" | grep -q "cec_org=error" && echo 1 || echo 0)
get anon f2 "$r"
ok "...errors listed: description, email, Telegram invite, Instagram, authorization" $([ "$(has $T/f2.html 'short description')$(has $T/f2.html 'contact email')$(has $T/f2.html 'invite link')$(has $T/f2.html 'Instagram')$(has $T/f2.html 'allowed to list')" = 11111 ] && echo 1 || echo 0)
ok "...and your answers are kept" $(has $T/f2.html 'value="ZZ Rope Collective"')
ok "nothing was stored" $([ "$(q "SELECT COUNT(*) FROM wp_posts WHERE post_type='cec_org_submission'")" = 0 ] && echo 1 || echo 0)

r=$(submit 10.0.0.1 -F "kind=organization" -F "name=ZZ Bot" -F "description=x" -F "contact_name=B" -F "contact_email=b@example.com" -F "authorized=1" -F "cec_org_website_confirm=http://spam")
ok "honeypot: looks like it worked, nothing stored" $(echo "$r" | grep -q "cec_org=thanks" && [ "$(q "SELECT COUNT(*) FROM wp_posts WHERE post_type='cec_org_submission'")" = 0 ] && echo 1 || echo 0)

r=$(submit 10.0.0.1 -F "kind=organization" -F "name=ZZ Rope Collective" -F "description=Rope" -F "contact_name=Pat" -F "contact_email=pat@example.com" -F "authorized=1" -F "logo=@$T/fake.png;type=image/png")
ok "a file that isn't an image is refused" $(echo "$r" | grep -q "cec_org=error" && echo 1 || echo 0)

M0=$(mails)
r=$(submit 10.0.0.2 -F "kind=organization" -F "name=ZZ Rope Collective" -F "description=Columbus rope education and social nights." -F $'highlights=Rope Education\nSocial Nights' -F "mission=Teach safe rope." -F "contact_name=Pat Doe" -F "contact_email=pat@example.com" -F "contact_phone=614-555-0100" -F "social[telegram]=https://t.me/+AbCdEf123" -F "social[website]=example.org/rope" -F "member=organn" -F "authorized=1" -F "logo=@$T/logo.png;type=image/png")
ok "a good organization submission: thanks" $(echo "$r" | grep -q "cec_org=thanks" && echo 1 || echo 0)
SUB=$(q "SELECT ID FROM wp_posts WHERE post_type='cec_org_submission' AND post_title='ZZ Rope Collective'")
ok "...stored as pending for review, with its contact kept private" $([ "$(ev "echo get_post_status($SUB).'/'.get_post_meta($SUB,'_cec_org_review',true).'/'.get_post_meta($SUB,'_cec_org_contact_email',true);")" = "pending/pending/pat@example.com" ] && echo 1 || echo 0)
ok "...website gets https://, Telegram invite link kept" $([ "$(ev "\$c=get_post_meta($SUB,'_cec_org',true); echo \$c['socials']['website'].' '.\$c['socials']['telegram'];")" = "https://example.org/rope https://t.me/+AbCdEf123" ] && echo 1 || echo 0)
LOGO=$(ev "echo (int) get_post_meta($SUB,'_cec_org_logo_id',true);")
ok "...logo saved to the media library under a new name" $([ "$LOGO" -gt 0 ] && ev "echo basename(get_attached_file($LOGO));" | grep -q '^zz-rope-collective-.*\.png$' && echo 1 || echo 0)
ok "...the site admin and the contact were emailed" $([ $(( $(mails) - M0 )) = 2 ] && grep -q 'admin@example.com' wp-content/mail.log && grep -q 'We received your listing' wp-content/mail.log && echo 1 || echo 0)
ok "...nothing is public yet" $([ "$(ev 'echo term_exists("ZZ Rope Collective","cec_partner_org") ? 1 : 0;')" = 0 ] && echo 1 || echo 0)

for i in 1 2 3 4 5; do submit 10.0.0.3 -F "kind=organization" -F "name=ZZ Flood $i" -F "description=x" -F "contact_name=F" -F "contact_email=f@example.com" -F "authorized=1" >/dev/null; done
r=$(submit 10.0.0.3 -F "kind=organization" -F "name=ZZ Flood 6" -F "description=x" -F "contact_name=F" -F "contact_email=f@example.com" -F "authorized=1")
ok "rate limit: the 6th from one address in a day is refused" $(echo "$r" | grep -q "cec_org=error" && [ "$(q "SELECT COUNT(*) FROM wp_posts WHERE post_type='cec_org_submission' AND post_title LIKE 'ZZ Flood%'")" = 5 ] && echo 1 || echo 0)

r=$(submit 10.0.0.4 -F "kind=titleholder" -F "name=ZZ Foxxy Test" -F "title=Great Lakes Handler" -F "year=2026" -F "producer=Great Lakes Leather" -F "description=Handler and educator." -F "mission=Pup and handler education." -F "contact_name=Fox" -F "contact_email=fox@example.com" -F "social[instagram]=https://www.instagram.com/foxxyforce13" -F "member=orgbo@example.com" -F "authorized=1")
TSUB=$(q "SELECT ID FROM wp_posts WHERE post_type='cec_org_submission' AND post_title='ZZ Foxxy Test'")
ok "a titleholder submission keeps title, year and producer" $([ "$(ev "\$c=get_post_meta($TSUB,'_cec_org',true); echo \$c['kind'].'|'.\$c['title'].'|'.\$c['year'].'|'.\$c['producer'];")" = "titleholder|Great Lakes Handler|2026|Great Lakes Leather" ] && echo 1 || echo 0)
r=$(submit 10.0.0.5 -F "kind=titleholder" -F "name=ZZ Bad Year" -F "year=26" -F "description=x" -F "contact_name=F" -F "contact_email=f@example.com" -F "authorized=1")
ok "a two-digit year is refused" $(echo "$r" | grep -q "cec_org=error" && echo 1 || echo 0)

echo "== Review"
login admin admin admin
get admin rv "$H/wp-admin/post.php?post=$SUB&action=edit"
ok "review screen: fields, member match, approve and decline, the separate form" $([ "$(has $T/rv.html 'Approve and publish')$(has $T/rv.html 'value="decline"')$(has $T/rv.html 'Matches member: Org Ann')$(has $T/rv.html 'id="cec-org-review-form"')$(has $T/rv.html 'form="cec-org-review-form"')" = 11111 ] && echo 1 || echo 0)
get admin list "$H/wp-admin/edit.php?post_type=cec_event"
ok "events list says listings are waiting" $(has $T/list.html 'waiting for review')
DN=$(php -r 'preg_match("~name=\"_wpnonce\" value=\"([^\"]+)\" form=\"cec-org-review-form\"~",file_get_contents($argv[1]),$m); echo $m[1]??"";' $T/rv.html)
decide(){ # decide <submission> <nonce> <decision> <extra -d args...>
	local id=$1 n=$2 d=$3; shift 3
	curl -s -b $T/admin.jar -c $T/admin.jar -o /dev/null -w '%{redirect_url}' -e "$H/wp-admin/post.php?post=$id&action=edit" "$H/wp-admin/admin-post.php" --data-urlencode "action=cec_org_decide" --data-urlencode "submission=$id" --data-urlencode "_wpnonce=$n" --data-urlencode "decision=$d" --data-urlencode "authorized=1" "$@"
}
login orgdee orgdee orgdeepass123
r=$(curl -s -b $T/orgdee.jar -o /dev/null -w '%{http_code}' "$H/wp-admin/admin-post.php" --data-urlencode "action=cec_org_decide" --data-urlencode "submission=$SUB" --data-urlencode "_wpnonce=$DN" --data-urlencode "decision=approve")
ok "a non-admin can't approve" $([ "$r" = 403 ] && [ "$(ev "echo get_post_meta($SUB,'_cec_org_review',true);")" = pending ] && echo 1 || echo 0)
M0=$(mails)
FIELDS=(--data-urlencode "kind=organization" --data-urlencode "name=ZZ Rope Collective" --data-urlencode "description=Columbus rope education and social nights." --data-urlencode $'highlights=Rope Education\nSocial Nights' --data-urlencode "mission=Teach safe rope." --data-urlencode "contact_name=Pat Doe" --data-urlencode "contact_email=pat@example.com" --data-urlencode "social[telegram]=https://t.me/+AbCdEf123" --data-urlencode "social[website]=https://example.org/rope" --data-urlencode "member=organn")
r=$(decide $SUB $DN approve "${FIELDS[@]}")
ok "approve: back on the review screen" $(echo "$r" | grep -q "cec_org_done=approved" && echo 1 || echo 0)
TID=$(ev 'echo (int) term_exists("ZZ Rope Collective","cec_partner_org")["term_id"];')
P=$(ev "\$p=CEC_Orgs::profile($TID); echo \$p['kind'].'|'.\$p['listed'].'|'.implode(',',\$p['highlights']).'|'.\$p['mission'].'|'.\$p['socials']['telegram'].'|'.get_term_meta($TID,'cec_url',true).'|'.(\$p['logo_id']==$LOGO?1:0).'|'.\$p['contact']['email'];")
ok "...creates the calendar group with its profile, logo, website and private contact" $([ "$P" = "organization|1|Rope Education,Social Nights|Teach safe rope.|https://t.me/+AbCdEf123|https://example.org/rope|1|pat@example.com" ] && echo 1 || echo 0)
ok "...the submission is marked approved and linked to the group" $([ "$(ev "echo get_post_meta($SUB,'_cec_org_review',true).'/'.get_post_meta($SUB,'_cec_org_term_id',true);")" = "approved/$TID" ] && echo 1 || echo 0)
ok "...the contact is told it's listed" $(tail -n $(( $(mails) - M0 )) wp-content/mail.log | grep -q 'is now listed' && echo 1 || echo 0)
ok "...the named member is linked as Organizer and notified" $([ "$(q "SELECT role FROM $GL WHERE user_id=$ANN AND term_id=$TID")" = organizer ] && [ "$(q "SELECT COUNT(*) FROM wp_cmp_notifications WHERE user_id=$ANN AND message LIKE '%ZZ Rope Collective%'")" -ge 1 ] && echo 1 || echo 0)
M1=$(mails)
decide $SUB $DN approve "${FIELDS[@]}" >/dev/null
ok "saving an approved listing again doesn't email the contact again or duplicate the link" $([ "$(mails)" = "$M1" ] && [ "$(q "SELECT COUNT(*) FROM $GL WHERE term_id=$TID")" = 1 ] && echo 1 || echo 0)

get admin rv2 "$H/wp-admin/post.php?post=$TSUB&action=edit"
TN=$(php -r 'preg_match("~name=\"_wpnonce\" value=\"([^\"]+)\" form=\"cec-org-review-form\"~",file_get_contents($argv[1]),$m); echo $m[1]??"";' $T/rv2.html)
decide $TSUB $TN approve --data-urlencode "kind=titleholder" --data-urlencode "name=ZZ Foxxy Test" --data-urlencode "title=Great Lakes Handler" --data-urlencode "year=2026" --data-urlencode "producer=Great Lakes Leather" --data-urlencode "description=Handler and educator." --data-urlencode "mission=Pup and handler education." --data-urlencode "contact_name=Fox" --data-urlencode "contact_email=fox@example.com" --data-urlencode "social[instagram]=https://www.instagram.com/foxxyforce13" --data-urlencode "member=orgbo@example.com" >/dev/null
FID=$(ev 'echo (int) term_exists("ZZ Foxxy Test","cec_partner_org")["term_id"];')
ok "titleholder approved; member found by email linked as Titleholder" $([ "$FID" -gt 0 ] && [ "$(q "SELECT role FROM $GL WHERE user_id=$BO AND term_id=$FID")" = titleholder ] && echo 1 || echo 0)

# A submission for a group that already exists fills it in instead of duplicating it.
r=$(submit 10.0.0.6 -F "kind=organization" -F "name=ZZ Existing Group" -F "description=Already on the calendar." -F "contact_name=E" -F "contact_email=e@example.com" -F "authorized=1")
ESUB=$(q "SELECT ID FROM wp_posts WHERE post_type='cec_org_submission' AND post_title='ZZ Existing Group'")
get admin rv3 "$H/wp-admin/post.php?post=$ESUB&action=edit"
ok "review warns the group already exists" $(has $T/rv3.html 'already exists as a calendar group')
EN=$(php -r 'preg_match("~name=\"_wpnonce\" value=\"([^\"]+)\" form=\"cec-org-review-form\"~",file_get_contents($argv[1]),$m); echo $m[1]??"";' $T/rv3.html)
decide $ESUB $EN approve --data-urlencode "kind=organization" --data-urlencode "name=ZZ Existing Group" --data-urlencode "description=Already on the calendar." --data-urlencode "contact_name=E" --data-urlencode "contact_email=e@example.com" >/dev/null
ok "...approving fills in the existing group (no second one)" $([ "$(q "SELECT COUNT(*) FROM wp_terms t JOIN wp_term_taxonomy x ON x.term_id=t.term_id WHERE x.taxonomy='cec_partner_org' AND t.name LIKE 'ZZ Existing Group%'")" = 1 ] && [ "$(ev "echo get_term_meta($EXIST,'cec_listed',true);")" = 1 ] && echo 1 || echo 0)

r=$(submit 10.0.0.7 -F "kind=organization" -F "name=ZZ Declined Group" -F "description=No." -F "contact_name=D" -F "contact_email=d@example.com" -F "authorized=1")
DSUB=$(q "SELECT ID FROM wp_posts WHERE post_type='cec_org_submission' AND post_title='ZZ Declined Group'")
get admin rv4 "$H/wp-admin/post.php?post=$DSUB&action=edit"
DDN=$(php -r 'preg_match("~name=\"_wpnonce\" value=\"([^\"]+)\" form=\"cec-org-review-form\"~",file_get_contents($argv[1]),$m); echo $m[1]??"";' $T/rv4.html)
M0=$(mails)
r=$(decide $DSUB $DDN decline)
ok "decline: no group, submission set aside, contact emailed" $(echo "$r" | grep -q "cec_org_done=declined" && [ "$(ev 'echo term_exists("ZZ Declined Group","cec_partner_org") ? 1 : 0;')" = 0 ] && [ "$(ev "echo get_post_status($DSUB).'/'.get_post_meta($DSUB,'_cec_org_review',true);")" = "draft/declined" ] && tail -n $(( $(mails) - M0 )) wp-content/mail.log | grep -q 'About your listing' && echo 1 || echo 0)

echo "== Pages"
URL=$(ev "echo get_term_link($TID,'cec_partner_org');")
ok "the group's page is at /partner/<slug>/" $(echo "$URL" | grep -q '/partner/zz-rope-collective/$' && echo 1 || echo 0)
get anon pg "$URL"
ok "page: name, description, highlights, mission, Telegram button first, website, logo" $([ "$(has $T/pg.html 'class="cec-org-name"')$(has $T/pg.html 'Columbus rope education')$(has $T/pg.html '<li>Social Nights</li>')$(has $T/pg.html 'Teach safe rope.')$(grep -o 'cec-org-btn is-primary[^>]*>[^<]*' $T/pg.html | grep -q 'Join our Telegram' && echo 1 || echo 0)$(has $T/pg.html 'https://example.org/rope')$(has $T/pg.html 'cec-org-image')" = 1111111 ] && echo 1 || echo 0)
ok "page: the private contact is never shown" $([ "$(hasnt $T/pg.html 'pat@example.com')$(hasnt $T/pg.html 'Pat Doe')$(hasnt $T/pg.html '614-555')" = 111 ] && echo 1 || echo 0)
ok "page: signed out, no member list" $(hasnt $T/pg.html 'Members on COL&amp;B')
ok "page: upcoming events section with subscribe links" $([ "$(has $T/pg.html 'Upcoming Events')$(has $T/pg.html 'cec-events-wrap')" = 11 ] && echo 1 || echo 0)
get anon fx "$(ev "echo get_term_link($FID,'cec_partner_org');")"
ok "titleholder page: title line, Areas of Focus" $([ "$(has $T/fx.html 'Great Lakes Handler 2026 · Produced by Great Lakes Leather')$(has $T/fx.html 'Areas of Focus')" = 11 ] && echo 1 || echo 0)

get anon gr "$H/?page_id=$GRID_ID"
ok "grid: listed groups with a Learn More link to their page" $([ "$(has $T/gr.html 'ZZ Rope Collective')$(has $T/gr.html 'ZZ Foxxy Test')$(has $T/gr.html '/partner/zz-rope-collective/')$(has $T/gr.html 'Learn More')" = 1111 ] && echo 1 || echo 0)
ok "grid: declined and unlisted groups aren't there" $(hasnt $T/gr.html 'ZZ Declined')
ev "update_term_meta($FID,'cec_listed','0');" >/dev/null
get anon gr2 "$H/?page_id=$GRID_ID"
ok "grid: unticking Listed removes it" $(hasnt $T/gr2.html 'ZZ Foxxy Test')
ev "update_term_meta($FID,'cec_listed','1');" >/dev/null

code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$H/zz-existing-group/")
ok "an old /slug/ address that isn't a page any more redirects (301) to /partner/slug/" $(echo "$code" | grep -q '^301 .*/partner/zz-existing-group/$' && echo 1 || echo 0)
code=$(curl -s -o /dev/null -w '%{http_code}' "$H/zz-no-such-thing/")
ok "an unknown address is still a 404" $([ "$code" = 404 ] && echo 1 || echo 0)

echo "== Member links"
login organn organn organnpass123
get organn pga "$URL"
ok "signed-in member sees linked members with role and profile link" $([ "$(has $T/pga.html 'Members on COL&amp;B')$(has $T/pga.html 'Org Ann')$(has $T/pga.html 'Organizer')$(has $T/pga.html "cmp_member=$ANN")" = 1111 ] && echo 1 || echo 0)
ok "...your own card first, marked (you), with your role and Manage my groups" $([ "$(grep -o 'cec-org-member-name">[^<]*' $T/pga.html | head -1 | grep -c 'Org Ann (you)')$(has $T/pga.html 'listed here as Organizer')$(has $T/pga.html 'Manage my groups')" = 111 ] && echo 1 || echo 0)
ok "...and the page is never kept by the browser when signed in" $(grep -qi '^cache-control:.*no-cache' $T/pga.h && echo 1 || echo 0)
ok "signed out, the page may still be cached" $(grep -qi '^cache-control:.*no-cache' $T/pg.h && echo 0 || echo 1)
login orgdee orgdee orgdeepass123
get orgdee pgd "$URL"
ok "signed in but not a full member: no member list" $(hasnt $T/pgd.html 'Org Ann')
get organn prof "$H/?page_id=$PAGE_ID&cmp_tab=profile"
ok "Profile tab: Groups and titles lists the link with Remove, and an Add select" $([ "$(has $T/prof.html 'Groups and titles')$(has $T/prof.html 'ZZ Rope Collective')$(has $T/prof.html 'value="remove"')$(has $T/prof.html 'id="cmp-group-pick"')" = 1111 ] && echo 1 || echo 0)
GN=$(php -r 'preg_match("~name=\"action\" value=\"cmp_group\".*?name=\"_cmp_nonce\" value=\"([^\"]+)\"~s",file_get_contents($argv[1]),$m); echo $m[1]??"";' $T/prof.html)
gpost(){ curl -s -b $T/$1.jar -c $T/$1.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" --data-urlencode "action=cmp_group" "${@:2}"; }
r=$(gpost organn --data-urlencode "_cmp_nonce=bad" --data-urlencode "do=add" --data-urlencode "group=$FID")
ok "bad nonce refused" $([ "$(q "SELECT COUNT(*) FROM $GL WHERE user_id=$ANN AND term_id=$FID")" = 0 ] && echo 1 || echo 0)
r=$(gpost organn --data-urlencode "_cmp_nonce=$GN" --data-urlencode "do=add" --data-urlencode "group=$FID")
ok "a member links themselves to a group, always as Member" $(echo "$r" | grep -q "gr_added" && [ "$(q "SELECT role FROM $GL WHERE user_id=$ANN AND term_id=$FID")" = member ] && echo 1 || echo 0)
r=$(gpost organn --data-urlencode "_cmp_nonce=$GN" --data-urlencode "do=add" --data-urlencode "group=$TID")
ok "...adding again never downgrades an approved Organizer" $([ "$(q "SELECT role FROM $GL WHERE user_id=$ANN AND term_id=$TID")" = organizer ] && echo 1 || echo 0)
DG=$(ev 'echo (int) term_exists("ZZ Existing Group","cec_partner_org")["term_id"];')
ev "update_term_meta($DG,'cec_listed','0');" >/dev/null
r=$(gpost organn --data-urlencode "_cmp_nonce=$GN" --data-urlencode "do=add" --data-urlencode "group=$DG")
ok "...an unlisted group can't be linked" $(echo "$r" | grep -q "gr_gone" && [ "$(q "SELECT COUNT(*) FROM $GL WHERE user_id=$ANN AND term_id=$DG")" = 0 ] && echo 1 || echo 0)
ev "update_term_meta($DG,'cec_listed','1');" >/dev/null
r=$(curl -s -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" --data-urlencode "action=cmp_group" --data-urlencode "do=add" --data-urlencode "group=$FID")
ok "signed out: sent to sign in" $(echo "$r" | grep -q "cmp_in" && echo 1 || echo 0)
get organn mp "$H/?page_id=$PAGE_ID&cmp_member=$ANN"
ok "member profile shows Groups with links" $([ "$(has $T/mp.html 'cmp-pv-groups')$(has $T/mp.html 'ZZ Rope Collective (Organizer)')" = 11 ] && echo 1 || echo 0)
login orgcy orgcy orgcypass1234
ev "global \$wpdb; \$wpdb->insert(CMP_Install::table('blocks'),array('blocker_id'=>$ANN,'blocked_id'=>$CY,'created_at'=>gmdate('Y-m-d H:i:s')));" >/dev/null
get orgcy pgc "$URL"
ok "a member who isn't linked is told so and offered Link my profile" $([ "$(has $T/pgc.html 'linked to this group')$(has $T/pgc.html '>Link my profile<')" = 11 ] && echo 1 || echo 0)
ok "someone Ann blocked doesn't see her on the group page" $([ "$(has $T/pgc.html 'Members on COL&amp;B')$(hasnt $T/pgc.html 'Org Ann')" = 11 ] && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->delete(CMP_Install::table('blocks'),array('blocker_id'=>$ANN,'blocked_id'=>$CY));" >/dev/null
r=$(gpost organn --data-urlencode "_cmp_nonce=$GN" --data-urlencode "do=remove" --data-urlencode "group=$TID")
ok "a member removes a link (even an approved Organizer one)" $(echo "$r" | grep -q "gr_removed" && [ "$(q "SELECT COUNT(*) FROM $GL WHERE user_id=$ANN AND term_id=$TID")" = 0 ] && echo 1 || echo 0)
get orgcy pgc2 "$URL"
ok "...and is gone from the group page" $(hasnt $T/pgc2.html 'Org Ann')

echo "== Privacy"
X=$(ev "\$r=apply_filters('wp_privacy_personal_data_exporters',array()); \$o=''; foreach(\$r as \$e){ \$d=call_user_func(\$e['callback'],'organn@example.com',1); foreach(\$d['data'] as \$i){ foreach(\$i['data'] as \$f){ \$o.=\$f['name'].'='.\$f['value'].';'; } } } echo \$o;")
ok "member export lists linked groups" $(echo "$X" | grep -q "Linked group=ZZ Foxxy Test (Member)" && echo 1 || echo 0)
X=$(ev "\$r=apply_filters('wp_privacy_personal_data_exporters',array()); \$o=''; foreach(\$r as \$e){ \$d=call_user_func(\$e['callback'],'pat@example.com',1); foreach(\$d['data'] as \$i){ foreach(\$i['data'] as \$f){ \$o.=\$f['name'].'='.\$f['value'].';'; } } } echo \$o;")
ok "a submission contact's export includes their submission" $(echo "$X" | grep -q "ZZ Rope Collective" && echo 1 || echo 0)
ev "\$r=apply_filters('wp_privacy_personal_data_erasers',array()); foreach(\$r as \$e){ call_user_func(\$e['callback'],'pat@example.com',1); } foreach(\$r as \$e){ call_user_func(\$e['callback'],'organn@example.com',1); }" >/dev/null
ok "erase: the contact's details are removed from the submission and the group" $([ "$(ev "\$c=get_post_meta($SUB,'_cec_org',true); \$g=get_term_meta($TID,'cec_contact',true); echo (\$c['contact_email']??'').'|'.(is_array(\$g)?implode('',\$g):\$g);")" = "|" ] && echo 1 || echo 0)
ok "erase: the group itself stays listed" $([ "$(ev "echo get_term_meta($TID,'cec_listed',true);")" = 1 ] && echo 1 || echo 0)
ok "erase: the member's links are removed" $([ "$(q "SELECT COUNT(*) FROM $GL WHERE user_id=$ANN")" = 0 ] && echo 1 || echo 0)
ev "wp_delete_term($FID,'cec_partner_org');" >/dev/null
ok "deleting a group removes its member links" $([ "$(q "SELECT COUNT(*) FROM $GL WHERE term_id=$FID")" = 0 ] && echo 1 || echo 0)

echo "== PHP errors"
ok "no PHP warnings or notices in the pages" $(cat $T/*.html | grep -qi 'Warning:\|Notice:\|Fatal error\|Deprecated:' && echo 0 || echo 1)

# Clean up.
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
rm -f wp-content/mu-plugins/test-client-ip.php
$W post delete $FORM_ID $GRID_ID $OLDP --force >/dev/null 2>&1
$W rewrite structure "$OLD_PERMA" >/dev/null 2>&1; $W rewrite flush >/dev/null 2>&1
echo "== $PASS passed, $FAIL failed"
[ $FAIL = 0 ]
