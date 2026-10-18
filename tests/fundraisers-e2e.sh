#!/usr/bin/env bash
# End-to-end test of the public fundraising tracker (Community Events
# Calendar 1.46.0): adding a fundraiser and its donations in wp-admin (the
# classic edit screen, over real HTTP), the totals, the public cards
# ([cec_fundraisers], [cec_fundraiser id]), that donation details stay
# private, current / upcoming / completed, goal reached, the Fundraising
# page, and who may edit.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/fundraisers-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/fund; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -L -b $T/$1.jar -c $T/$1.jar -o $T/$2.html "$3"; }
ev()   { $W eval "$1" 2>&1; }
field(){ php -r '$h=file_get_contents($argv[1]); preg_match("~name=[\"\x27]".preg_quote($argv[2],"~")."[\"\x27] value=[\"\x27]([^\"\x27]*)[\"\x27]~",$h,$m) || preg_match("~id=[\"\x27]".preg_quote($argv[2],"~")."[\"\x27] name=[\"\x27]".preg_quote($argv[2],"~")."[\"\x27] value=[\"\x27]([^\"\x27]*)[\"\x27]~",$h,$m); echo $m[1]??"";' "$1" "$2"; }
meta(){ ev "echo get_post_meta($1,'$2',true);"; }
page(){ ev "\$id=wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'ZZF $1','post_content'=>'$2')); echo \$id;"; }
# save <user> <post id> <extra --data-urlencode args...>: the classic edit form's Update.
save(){ local u=$1 id=$2; shift 2
	curl -s -b $T/$u.jar -c $T/$u.jar -o $T/edit.html "$H/wp-admin/post.php?post=$id&action=edit"
	curl -s -b $T/$u.jar -c $T/$u.jar -o /dev/null -w '%{http_code}' "$H/wp-admin/post.php" --data-urlencode "action=editpost" --data-urlencode "post_ID=$id" --data-urlencode "post_type=cec_fundraiser" \
		--data-urlencode "_wpnonce=$(field $T/edit.html _wpnonce)" --data-urlencode "cec_fr_nonce=$(field $T/edit.html cec_fr_nonce)" --data-urlencode "original_post_status=$(field $T/edit.html original_post_status)" \
		--data-urlencode "post_status=publish" --data-urlencode "publish=Publish" "$@"; }

for p in community-events-calendar community-member-planning; do rm -rf wp-content/plugins/$p && cp -r $REPO/$p wp-content/plugins/; $W plugin activate $p >/dev/null 2>&1; done
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
ev 'foreach ( get_posts( array( "post_type" => array( "cec_fundraiser", "page" ), "post_status" => "any", "posts_per_page" => -1, "s" => "ZZF " ) ) as $p ) { wp_delete_post( $p->ID, true ); } $t = get_term_by( "slug", "zzf-pup-crew", "cec_partner_org" ); if ( $t ) { wp_delete_term( $t->term_id, "cec_partner_org" ); }' >/dev/null
$W user delete zzfmember --yes >/dev/null 2>&1
$W user create zzfmember zzfmember@example.com --role=subscriber --user_pass=zzfmemberpass1 >/dev/null
: > wp-content/debug.log
ORG=$(ev '$t = wp_insert_term( "ZZF Pup Crew", "cec_partner_org", array( "slug" => "zzf-pup-crew" ) ); echo $t["term_id"];')
TODAY=$(ev "echo wp_date('Y-m-d');")
PAST=$(ev "echo wp_date('Y-m-d', time()-10*DAY_IN_SECONDS);")
SOON=$(ev "echo wp_date('Y-m-d', time()+20*DAY_IN_SECONDS);")
LATER=$(ev "echo wp_date('Y-m-d', time()+40*DAY_IN_SECONDS);")

echo "== The Fundraising page"
ev 'CEC_Admin_Settings::maybe_create_pages();' >/dev/null
FPAGE=$(ev "\$u=CEC_Admin_Settings::get('fundraising_page_url'); echo url_to_postid(\$u);")
ok "a published Fundraising page with the tracker is created and linked in Settings" $([ -n "$FPAGE" ] && [ "$FPAGE" != 0 ] && [ "$(ev "echo get_post_status($FPAGE).'|'.get_post_field('post_content',$FPAGE);")" = "publish|[cec_fundraisers]" ] && echo 1 || echo 0)

echo "== Adding a fundraiser in wp-admin"
login admin admin admin
curl -s -b $T/admin.jar -c $T/admin.jar -o $T/new.html "$H/wp-admin/post-new.php?post_type=cec_fundraiser"
F1=$(field $T/new.html post_ID)
ok "Events → Fundraisers → Add New shows the goal, giving and private donation boxes" $([ -n "$F1" ] && [ "$(has $T/new.html 'name="cec_fr_goal"')$(has $T/new.html 'name="cec_fr_url"')$(has $T/new.html 'name="cec_fr_entries\[0\]\[amount\]"')$(has $T/new.html 'Donations (private)')" = 1111 ] && echo 1 || echo 0)
code=$(save admin $F1 --data-urlencode "post_title=ZZF Bootblack Stand" --data-urlencode "content=Help us buy a bootblack stand for Leather Invasion." \
	--data-urlencode "cec_fr_goal=\$1,000" --data-urlencode "cec_fr_start=$PAST" --data-urlencode "cec_fr_end=$SOON" --data-urlencode "cec_fr_org=$ORG" \
	--data-urlencode "cec_fr_url=https://example.org/donate" --data-urlencode "cec_fr_button=Chip in" --data-urlencode "cec_fr_other=Cash at the door at Monthly Leather Invasion." \
	--data-urlencode "cec_fr_entries[0][amount]=200" --data-urlencode "cec_fr_entries[0][date]=$PAST" --data-urlencode "cec_fr_entries[0][method]=venmo" --data-urlencode "cec_fr_entries[0][note]=ZZSECRET Jordan gave" \
	--data-urlencode "cec_fr_entries[1][amount]=\$150.50" --data-urlencode "cec_fr_entries[1][method]=cash" --data-urlencode "cec_fr_entries[1][note]=door" \
	--data-urlencode "cec_fr_entries[2][amount]=99.5" --data-urlencode "cec_fr_entries[2][date]=$TODAY" --data-urlencode "cec_fr_entries[2][method]=paypal" \
	--data-urlencode "cec_fr_entries[3][amount]=abc" --data-urlencode "cec_fr_entries[4][amount]=" --data-urlencode "cec_fr_entries[5][amount]=0")
ok "published, with the goal, dates, group, link, button and other ways to give" $([ "$code" = 302 ] && [ "$(ev "echo get_post_status($F1);")" = publish ] && [ "$(meta $F1 _cec_fr_goal)|$(meta $F1 _cec_fr_end)|$(meta $F1 _cec_fr_org)|$(meta $F1 _cec_fr_url)|$(meta $F1 _cec_fr_button)" = "1000|$SOON|$ORG|https://example.org/donate|Chip in" ] && echo 1 || echo 0)
ok "three donations recorded (an amount that isn't a number, an empty one and 0 are skipped), total \$450" $([ "$(meta $F1 _cec_fr_count)|$(meta $F1 _cec_fr_raised)" = "3|450" ] && echo 1 || echo 0)
ok "a donation with no date gets today's, and the list is in date order" $([ "$(ev "echo implode(',', wp_list_pluck(CEC_Fundraisers::entries($F1),'date'));")" = "$PAST,$TODAY,$TODAY" ] && echo 1 || echo 0)

echo "== The public page"
get anon pub "$(ev "echo get_permalink($FPAGE);")"
ok "the card: title, group link, through date and description" $([ "$(has $T/pub.html 'ZZF Bootblack Stand')$(has $T/pub.html 'zzf-pup-crew')$(has $T/pub.html 'Through ')$(has $T/pub.html 'bootblack stand for Leather Invasion')" = 1111 ] && echo 1 || echo 0)
ok "...\$450 raised of \$1,000 goal, 45%, 3 donations, with an accessible progress bar" $([ "$(has $T/pub.html '<strong>\$450</strong> raised of \$1,000 goal · 45%')$(has $T/pub.html '3 donations')$(has $T/pub.html 'role="progressbar"')$(has $T/pub.html 'aria-valuetext="\$450 raised of \$1,000 goal"')$(has $T/pub.html 'width:45%')" = 11111 ] && echo 1 || echo 0)
ok "...the donate button (its own text, new tab) and other ways to give" $([ "$(has $T/pub.html 'class="cec-fr-btn" href="https://example.org/donate" target="_blank" rel="noopener">Chip in')$(has $T/pub.html 'Cash at the door')" = 11 ] && echo 1 || echo 0)
ok "donation details stay private: no notes, methods or individual amounts on the page" $([ "$(hasnt $T/pub.html 'ZZSECRET')$(hasnt $T/pub.html 'Jordan')$(hasnt $T/pub.html 'Venmo')$(hasnt $T/pub.html '150.50')$(hasnt $T/pub.html '99.50')" = 11111 ] && echo 1 || echo 0)

echo "== Removing a donation, and a goal reached"
curl -s -b $T/admin.jar -c $T/admin.jar -o $T/e2.html "$H/wp-admin/post.php?post=$F1&action=edit"
ok "the edit screen lists the donations with their private notes" $([ "$(has $T/e2.html 'ZZSECRET Jordan gave')$(has $T/e2.html '450 raised from 3 donations')" = 11 ] && echo 1 || echo 0)
code=$(save admin $F1 --data-urlencode "post_title=ZZF Bootblack Stand" --data-urlencode "content=Help us buy a bootblack stand for Leather Invasion." \
	--data-urlencode "cec_fr_goal=1000" --data-urlencode "cec_fr_start=$PAST" --data-urlencode "cec_fr_end=$SOON" --data-urlencode "cec_fr_org=$ORG" --data-urlencode "cec_fr_url=https://example.org/donate" \
	--data-urlencode "cec_fr_entries[0][amount]=200" --data-urlencode "cec_fr_entries[0][date]=$PAST" --data-urlencode "cec_fr_entries[0][method]=venmo" --data-urlencode "cec_fr_entries[0][remove]=1" \
	--data-urlencode "cec_fr_entries[1][amount]=150.50" --data-urlencode "cec_fr_entries[1][date]=$TODAY" --data-urlencode "cec_fr_entries[1][method]=cash" \
	--data-urlencode "cec_fr_entries[2][amount]=99.50" --data-urlencode "cec_fr_entries[2][date]=$TODAY" --data-urlencode "cec_fr_entries[2][method]=paypal" \
	--data-urlencode "cec_fr_entries[3][amount]=1000" --data-urlencode "cec_fr_entries[3][method]=online")
ok "removing one and adding \$1,000: 3 donations, \$1,250" $([ "$(meta $F1 _cec_fr_count)|$(meta $F1 _cec_fr_raised)" = "3|1250" ] && echo 1 || echo 0)
get anon pub2 "$(ev "echo get_permalink($FPAGE);")"
ok "over the goal: 125%, the bar full (not past the end), 'Goal reached. Thank you!', default 'Donate' button" $([ "$(has $T/pub2.html '125%')$(has $T/pub2.html 'width:100%')$(has $T/pub2.html 'Goal reached. Thank you!')$(has $T/pub2.html '>Donate<span')" = 1111 ] && echo 1 || echo 0)

echo "== Upcoming and completed"
F2=$(ev "\$id=wp_insert_post(array('post_type'=>'cec_fundraiser','post_status'=>'publish','post_title'=>'ZZF Spring Drive')); update_post_meta(\$id,'_cec_fr_goal',500); update_post_meta(\$id,'_cec_fr_start','$SOON'); update_post_meta(\$id,'_cec_fr_end','$LATER'); update_post_meta(\$id,'_cec_fr_url','https://example.org/spring'); echo \$id;")
F3=$(ev "\$id=wp_insert_post(array('post_type'=>'cec_fundraiser','post_status'=>'publish','post_title'=>'ZZF Last Pride Booth')); update_post_meta(\$id,'_cec_fr_goal',300); update_post_meta(\$id,'_cec_fr_raised',320); update_post_meta(\$id,'_cec_fr_count',9); update_post_meta(\$id,'_cec_fr_end','$PAST'); update_post_meta(\$id,'_cec_fr_url','https://example.org/old'); echo \$id;")
F4=$(ev "\$id=wp_insert_post(array('post_type'=>'cec_fundraiser','post_status'=>'draft','post_title'=>'ZZF Draft Idea')); update_post_meta(\$id,'_cec_fr_goal',100); echo \$id;")
get anon pub3 "$(ev "echo get_permalink($FPAGE);")"
ok "an upcoming fundraiser says 'Starts …' and keeps its button" $(php -r '$h=file_get_contents($argv[1]); preg_match("~<article[^>]*is-upcoming.*?</article>~s",$h,$m); echo (isset($m[0]) && strpos($m[0],"ZZF Spring Drive")!==false && strpos($m[0],"Starts ")!==false && strpos($m[0],"example.org/spring")!==false) ? 1 : 0;' $T/pub3.html)
ok "a completed one is under 'Completed fundraisers', 'Ended …', with no donate button" $(php -r '$h=file_get_contents($argv[1]); $i=strpos($h,"Completed fundraisers"); $tail=$i===false?"":substr($h,$i); echo ($i!==false && strpos($tail,"ZZF Last Pride Booth")!==false && strpos($tail,"Ended ")!==false && strpos($h,"example.org/old")===false && strpos(substr($h,0,$i),"ZZF Last Pride Booth")===false) ? 1 : 0;' $T/pub3.html)
ok "a draft isn't shown" $(hasnt $T/pub3.html 'ZZF Draft Idea')
ok "current fundraisers ending soonest come first" $(php -r '$h=file_get_contents($argv[1]); echo (strpos($h,"ZZF Bootblack Stand") < strpos($h,"ZZF Spring Drive")) ? 1 : 0;' $T/pub3.html)

echo "== Single card and options"
P1=$(page "One Card" "[cec_fundraiser id=\"$F2\"]"); P2=$(page "Draft Card" "[cec_fundraiser id=\"$F4\"]"); P3=$(page "Current Only" '[cec_fundraisers show="current"]')
get anon one "$(ev "echo get_permalink($P1);")"; get anon dr "$(ev "echo get_permalink($P2);")"; get anon cur "$(ev "echo get_permalink($P3);")"
ok "[cec_fundraiser id] shows that one card; a draft's id shows nothing" $([ "$(has $T/one.html 'ZZF Spring Drive')$(hasnt $T/one.html 'ZZF Bootblack Stand')$(hasnt $T/dr.html 'ZZF Draft Idea')$(hasnt $T/dr.html 'cec-fr-card')" = 1111 ] && echo 1 || echo 0)
ok "show=\"current\" leaves out the completed ones" $([ "$(has $T/cur.html 'ZZF Bootblack Stand')$(hasnt $T/cur.html 'Completed fundraisers')" = 11 ] && echo 1 || echo 0)

echo "== Who can edit"
login zzfmember zzfmember zzfmemberpass1
curl -s -b $T/zzfmember.jar -c $T/zzfmember.jar -o /dev/null "$H/wp-admin/post.php" --data-urlencode "action=editpost" --data-urlencode "post_ID=$F1" --data-urlencode "post_type=cec_fundraiser" --data-urlencode "cec_fr_nonce=x" --data-urlencode "cec_fr_goal=1" --data-urlencode "post_title=Hacked"
ok "a member can't change a fundraiser" $([ "$(meta $F1 _cec_fr_goal)" = 1000 ] && [ "$(ev "echo get_the_title($F1);")" = "ZZF Bootblack Stand" ] && echo 1 || echo 0)

echo "== PHP errors"
ok "no PHP warnings in the pages" $(cat $T/*.html | grep -qi '<b>Warning</b>\|<b>Notice</b>\|<b>Fatal error</b>\|<b>Deprecated</b>' && echo 0 || echo 1)
ok "no plugin warnings in debug.log" $(grep -E "Warning|Fatal|Notice|Deprecated" wp-content/debug.log 2>/dev/null | grep -q "plugins/community-events-calendar" && echo 0 || echo 1)

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev 'foreach ( get_posts( array( "post_type" => array( "cec_fundraiser", "page" ), "post_status" => "any", "posts_per_page" => -1, "s" => "ZZF " ) ) as $p ) { wp_delete_post( $p->ID, true ); }' >/dev/null
ev "wp_delete_term($ORG,'cec_partner_org');" >/dev/null
$W user delete zzfmember --yes >/dev/null 2>&1
echo "== $PASS passed, $FAIL failed"
[ $FAIL = 0 ]
