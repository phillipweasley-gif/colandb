#!/usr/bin/env bash
# End-to-end test of Users -> Member Dynamics & Locks (Community Member
# Planning 0.26.0): who can use it, removing a stuck waiting lock, recording
# a lock that never started, correcting start / days / planned end / end,
# hidden-timer privacy, ending a lock, lock settings and keyholder (0.27.0),
# dynamic profile switches, ending a dynamic, turning off an add-on,
# reasons, notifications (only when ticked) and the audit log.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/member-admin-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/ma; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -b $T/$1.jar -c $T/$1.jar -o $T/$2.html "$3"; }
wpn()  { grep -o 'name="_wpnonce" value="[^"]*"' $1 | head -1 | cut -d'"' -f4; }
cmpn() { grep -o 'name="_cmp_nonce" value="[^"]*"' $1 | head -1 | cut -d'"' -f4; }
# adm <user> <do> [curl -F args...]: post to the admin handler, print "<code> <redirect>".
adm()  { local u=$1 d=$2; shift 2; curl -s -b $T/$u.jar -c $T/$u.jar -o /dev/null -w '%{http_code} %{redirect_url}' "$H/wp-admin/admin-post.php" -F "action=cmp_member_admin" -F "_wpnonce=$AN" -F "_wp_http_referer=/wp-admin/users.php" -F "do=$d" "$@"; }
act()  { local u=$1 n=$2 d=$3; shift 3; curl -s -b $T/$u.jar -c $T/$u.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" -F "action=cmp_chastity" -F "_cmp_nonce=$n" -F "do=$d" "$@"; }
uid()  { $W user get $1 --field=ID 2>/dev/null; }
ev()   { $W eval "$1" 2>&1; }
member(){ ev "\$i=get_user_by('login','$1')->ID; update_user_meta(\$i,'cmp_verified_email','$1@example.com'); update_user_meta(\$i,'cmp_email_verified_at',gmdate('Y-m-d H:i:s')); update_user_meta(\$i,'cmp_birth_date','1980-01-01'); CMP_Access::record_attestation(\$i); update_user_meta(\$i,'cmp_setup_done','later');" >/dev/null; }
q()    { ev "global \$wpdb; echo \$wpdb->get_var(\"$1\");"; }
TBL()  { ev "echo CMP_Install::table('$1');"; }
lk()   { local id=$1; shift; local f=""; for x in "$@"; do f="$f.'|'.\$l->$x"; done; ev "\$l=CMP_Chastity::lock($id); echo \$l ? substr(''$f,1) : 'none';"; }
secs() { ev "echo CMP_Chastity::locked_seconds(CMP_Chastity::lock($1));"; }
near() { [ $(( $1 - $2 )) -le 120 ] && [ $(( $2 - $1 )) -le 120 ] && echo 1 || echo 0; }

rm -rf wp-content/plugins/community-member-planning && cp -r $REPO/community-member-planning wp-content/plugins/
$W plugin activate community-member-planning >/dev/null 2>&1
ev 'CMP_Install::maybe_upgrade();' >/dev/null
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
PAGE="$H/?page_id=$(ev 'echo (int) CMP_Settings::get("member_page_id");')"
ADMIN="$H/wp-admin/users.php?page=cmp-member-dynamics"
L_T=$(TBL locks); E_T=$(TBL lock_events); D_T=$(TBL dynamics); A_T=$(TBL dynamic_addons); N_T=$(TBL notifications); AU_T=$(TBL audit_log)
ev "global \$wpdb; foreach(array('$L_T','$E_T','$D_T','$A_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
for u in makh masub maother; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create makh makh@example.com --role=subscriber --user_pass=makhpass123456 --display_name="Admin Test Keyholder" >/dev/null
$W user create masub masub@example.com --role=subscriber --user_pass=masubpass12345 --display_name="Admin Test Sub" >/dev/null
$W user create maother maother@example.com --role=subscriber --user_pass=maotherpass123 >/dev/null
member makh; member masub; member maother
KH=$(uid makh); SUB=$(uid masub); OTHER=$(uid maother)
ev "global \$wpdb; \$wpdb->insert('$D_T',array('type'=>'keyholder','proposer_id'=>$KH,'partner_id'=>$SUB,'proposer_side'=>'a','status'=>'active','proposer_show'=>1,'partner_show'=>1,'created_at'=>gmdate('Y-m-d H:i:s'))); CMP_Dynamics::grant('chastity',$KH,$SUB);" >/dev/null
DYN=$(q "SELECT MAX(id) FROM $D_T")

echo "== Who can use it"
login masub masub masubpass12345
get masub s0 "$ADMIN"
ok "a member can't open the screen" $(hasnt $T/s0.html 'Member Dynamics &amp; Locks')
login admin admin admin
get admin a0 "$ADMIN"
ok "an administrator can" $([ "$(has $T/a0.html 'Member Dynamics &amp; Locks')$(has $T/a0.html 'Record a lock')" = 11 ] && echo 1 || echo 0)
AN=$(wpn $T/a0.html)
r=$(curl -s -b $T/masub.jar -o /dev/null -w '%{http_code}' "$H/wp-admin/admin-post.php" -F "action=cmp_member_admin" -F "_wpnonce=$AN" -F "do=end_dynamic" -F "dynamic=$DYN" -F "reason=x")
ok "a member can't post to it" $([ "$r" = 403 ] && [ "$(q "SELECT status FROM $D_T WHERE id=$DYN")" = active ] && echo 1 || echo 0)

echo "== A stuck waiting lock"
login makh makh makhpass123456
get makh k0 "$PAGE&cmp_tab=chastity"
act makh $(cmpn $T/k0.html) propose -F "wearer=$SUB" -F "option=a" -F "length=10080" -F "hygiene_minutes=15" -F "kh_show=1" >/dev/null
PID=$(q "SELECT id FROM $L_T WHERE wearer_id=$SUB AND status='proposed'")
get admin a1 "$ADMIN"
ok "waiting lock listed with Manage" $([ -n "$PID" ] && [ "$(has $T/a1.html 'Waiting for the wearer to accept')$(has $T/a1.html "lock=$PID")" = 11 ] && echo 1 || echo 0)
get admin a2 "$ADMIN&lock=$PID"
ok "its page offers Remove and Record, nothing to accept for them" $([ "$(has $T/a2.html 'Remove waiting lock')$(has $T/a2.html 'Record lock')$(hasnt $T/a2.html 'value="accept"')" = 111 ] && echo 1 || echo 0)
r=$(adm admin remove_lock -F "lock=$PID")
ok "no reason, no change" $(echo "$r" | grep -q 'msg=reason' && [ "$(lk $PID status)" = proposed ] && echo 1 || echo 0)
r=$(adm admin remove_lock -F "lock=$PID" -F "reason=Accept failed on his phone" -F "notify=1")
ok "removed with a reason; both told" $(echo "$r" | grep -q 'msg=removed' && [ "$(lk $PID status)" = none ] && [ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id IN ($SUB,$KH) AND message LIKE '%site team removed a lock that was waiting%'")" = 2 ] && echo 1 || echo 0)
ok "audit log keeps it with the reason" $([ "$(q "SELECT COUNT(*) FROM $AU_T WHERE action='lock_admin_removed' AND object_id=$PID AND reason='Accept failed on his phone'")" = 1 ] && echo 1 || echo 0)

echo "== Record a lock that never started"
r=$(adm admin record -F "wearer=masub" -F "keyholder=maother" -F "option=a" -F "days=5" -F "reason=test")
ok "a keyholder who doesn't hold their key is refused for a running lock" $(echo "$r" | grep -q 'msg=keyholder' && [ -z "$(q "SELECT id FROM $L_T WHERE wearer_id=$SUB")" ] && echo 1 || echo 0)
FUT=$(ev "echo wp_date('Y-m-d\TH:i', time()+86400);")
r=$(adm admin record -F "wearer=masub" -F "keyholder=makh" -F "option=a" -F "started_at=$FUT" -F "reason=test")
ok "a start in the future is refused" $(echo "$r" | grep -q 'msg=times' && echo 1 || echo 0)
r=$(adm admin record -F "wearer=masub" -F "keyholder=makh" -F "option=b" -F "days=5" -F "hours=3" -F "reason=Accept failed; locked since Thursday per both" -F "notify=1")
RID=$(q "SELECT id FROM $L_T WHERE wearer_id=$SUB AND status='locked'")
ok "recorded as running with the keyholder" $(echo "$r" | grep -q 'msg=recorded' && [ "$(lk $RID status keyholder_id option_key)" = "locked|$KH|b" ] && echo 1 || echo 0)
ok "exactly 5 days 3 hours locked" $(near $(secs $RID) $(( 5*86400 + 3*3600 )))
ok "both told, with the start" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id IN ($SUB,$KH) AND message LIKE '%a lock was recorded from%'")" = 2 ] && echo 1 || echo 0)
ok "the keyholder manages it as usual" $(ev "echo CMP_Chastity::is_keyholder(CMP_Chastity::lock($RID),$KH)?1:0;")
r=$(adm admin record -F "wearer=masub" -F "option=a" -F "days=1" -F "reason=test")
ok "can't record a second running lock" $(echo "$r" | grep -q 'msg=busy' && echo 1 || echo 0)
get masub w0 "$PAGE&cmp_tab=chastity"
ok "wearer sees the timer and the site-team line in history" $([ "$(has $T/w0.html '5d 3h')$(has $T/w0.html 'Corrected by the site team: a lock was recorded')" = 11 ] && echo 1 || echo 0)

echo "== Correct days, start and planned end"
r=$(adm admin times -F "lock=$RID" -F "days=10" -F "reason=Started earlier than recorded" -F "notify=1")
ok "set days locked: exactly 10 days" $(echo "$r" | grep -q 'msg=saved' && near $(secs $RID) 864000)
ok "both told old and new start and the new total" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id IN ($SUB,$KH) AND message LIKE '%start changed from%now 10d 0h%'")" = 2 ] && echo 1 || echo 0)
ok "audit has old and new start" $([ "$(q "SELECT COUNT(*) FROM $AU_T WHERE action='lock_admin_fix' AND object_id=$RID AND old_value LIKE '%started_at%' AND new_value LIKE '%started_at%'")" -ge 1 ] && echo 1 || echo 0)
ST=$(ev "echo wp_date('Y-m-d\TH:i', time()-2*86400);")
r=$(adm admin times -F "lock=$RID" -F "started_at=$ST" -F "reason=Exact time from them")
ok "set the start by date and time" $(echo "$r" | grep -q 'msg=saved' && near $(secs $RID) $(( 2*86400 )))
r=$(adm admin times -F "lock=$RID" -F "started_at=$ST" -F "reason=again")
ok "nothing changed -> told so" $(echo "$r" | grep -q 'msg=same' && echo 1 || echo 0)
ev "global \$wpdb; \$wpdb->update('$L_T',array('hide_timer'=>1),array('id'=>$RID));" >/dev/null
before=$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$SUB AND message LIKE '%planned end%'")
PE=$(ev "echo wp_date('Y-m-d\TH:i', time()+30*86400);")
r=$(adm admin times -F "lock=$RID" -F "planned_end=$PE" -F "reason=Keyholder asked" -F "notify=1")
ok "planned end corrected" $(echo "$r" | grep -q 'msg=saved' && [ -n "$(lk $RID planned_end)" ] && echo 1 || echo 0)
ok "timer hidden: wearer told only that something was corrected" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$SUB AND message LIKE '%planned end%'")" = "$before" ] && [ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$SUB AND message='The site team made a correction to your lock.'")" -ge 1 ] && echo 1 || echo 0)
get masub w1 "$PAGE&cmp_tab=chastity"
get makh k1 "$PAGE&cmp_tab=chastity&lock=$RID"
ok "wearer's history hides it; keyholder's shows it" $([ "$(hasnt $T/w1.html 'planned end changed')$(has $T/k1.html 'planned end changed')" = 11 ] && echo 1 || echo 0)
r=$(adm admin times -F "lock=$RID" -F "open_ended=1" -F "reason=Open-ended after all")
ok "planned end cleared to open-ended" $([ -z "$(lk $RID planned_end)" ] && echo 1 || echo 0)

echo "== End a lock, correct a past one"
r=$(adm admin end_lock -F "lock=$RID" -F "reason=Both asked to stop")
ok "ended by the site team" $(echo "$r" | grep -q 'msg=ended' && [ "$(lk $RID status end_reason)" = "ended|admin" ] && echo 1 || echo 0)
get masub w2 "$PAGE&cmp_tab=chastity"
ok "past locks say ended by the site team" $(has $T/w2.html 'ended by the site team')
EN=$(ev "echo wp_date('Y-m-d\TH:i', time()-3600);")
r=$(adm admin times -F "lock=$RID" -F "ended_at=$EN" -F "days=7" -F "reason=Real end was an hour ago, 7 days")
ok "end and days corrected on a past lock" $(echo "$r" | grep -q 'msg=saved' && near $(secs $RID) 604800)
r=$(adm admin times -F "lock=$RID" -F "ended_at=$FUT" -F "reason=bad")
ok "an end in the future is refused" $(echo "$r" | grep -q 'msg=times' && echo 1 || echo 0)

echo "== Settings, quietly (0.27.0)"
ev "global \$wpdb; \$wpdb->update('$L_T',array('status'=>'locked','ended_at'=>null),array('id'=>$RID));" >/dev/null
nbefore=$(q "SELECT COUNT(*) FROM $N_T WHERE user_id IN ($SUB,$KH)")
r=$(adm admin settings -F "lock=$RID" -F "show_profile=1" -F "kh_show=1" -F "verify_daily=1" -F "hygiene_minutes=30" -F "option=custom" -F "option_name=Weekday cage" -F "release_policy=permission" -F "keyholder=makh" -F "reason=He couldn't find the switch")
ok "badge, keyholder name, verification, hygiene, option, release all set" $(echo "$r" | grep -q 'msg=saved' && [ "$(lk $RID show_profile kh_show hide_timer verify_daily hygiene_minutes option_key option_name release_policy keyholder_id)" = "1|1|0|1|30|custom|Weekday cage|permission|$KH" ] && echo 1 || echo 0)
ok "no notifications sent without the Notify box" $([ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id IN ($SUB,$KH)")" = "$nbefore" ] && echo 1 || echo 0)
ok "history line and audit row still kept" $([ "$(q "SELECT COUNT(*) FROM $E_T WHERE lock_id=$RID AND type='admin_fix' AND note LIKE '%profile badge on%'")" = 1 ] && [ "$(q "SELECT COUNT(*) FROM $AU_T WHERE action='lock_admin_settings' AND object_id=$RID AND reason LIKE 'He couldn%'")" = 1 ] && echo 1 || echo 0)
login maother maother maotherpass123; get maother o1 "$PAGE&cmp_member=$SUB"
ok "the badge now shows on his profile, naming the keyholder" $(has $T/o1.html 'Locked by <a href=')
r=$(adm admin settings -F "lock=$RID" -F "option=custom" -F "option_name=" -F "release_policy=none" -F "hygiene_minutes=15" -F "keyholder=makh" -F "reason=x")
ok "Custom without a name is refused, nothing changed" $(echo "$r" | grep -q 'msg=invalid_settings' && [ "$(lk $RID option_name)" = "Weekday cage" ] && echo 1 || echo 0)
r=$(adm admin settings -F "lock=$RID" -F "option=a" -F "release_policy=none" -F "hygiene_minutes=15" -F "keyholder=maother" -F "reason=x")
ok "can't hand the key to someone who doesn't hold it" $(echo "$r" | grep -q 'msg=keyholder' && [ "$(lk $RID keyholder_id)" = "$KH" ] && echo 1 || echo 0)
r=$(adm admin settings -F "lock=$RID" -F "show_profile=1" -F "option=a" -F "release_policy=none" -F "hygiene_minutes=15" -F "keyholder=" -F "reason=Keyholder stepped away")
ok "empty keyholder makes it a self-lock" $([ "$(lk $RID keyholder_id hide_timer)" = "0|0" ] && echo 1 || echo 0)
r=$(adm admin settings -F "lock=$RID" -F "show_profile=1" -F "option=a" -F "release_policy=none" -F "hygiene_minutes=15" -F "keyholder=makh" -F "reason=Back with keyholder")
ok "and back to the keyholder" $([ "$(lk $RID keyholder_id)" = "$KH" ] && echo 1 || echo 0)
r=$(adm admin dyn_show -F "dynamic=$DYN" -F "user=$SUB" -F "show=" -F "member=$SUB" -F "reason=He wants it private")
ok "dynamic 'show on profile' switched off for one member, quietly" $(echo "$r" | grep -q 'msg=saved' && [ "$(q "SELECT partner_show FROM $D_T WHERE id=$DYN")" = 0 ] && [ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id=$SUB AND message LIKE '%Show on my profile%'")" = 0 ] && echo 1 || echo 0)
r=$(adm admin end_lock -F "lock=$RID" -F "reason=Back to test flow")

echo "== Dynamics"
get admin a3 "$ADMIN&member=$SUB"
ok "member page lists the dynamic, the chastity add-on and locks" $([ "$(has $T/a3.html 'Keyholder / chastity wearer')$(has $T/a3.html 'Turn off')$(has $T/a3.html "lock=$RID")" = 111 ] && echo 1 || echo 0)
ok "no photos, rules or notes on the admin screen" $([ "$(hasnt $T/a3.html 'cmp_lockpic')$(hasnt $T/a3.html 'Wear it 24/7')" = 11 ] && echo 1 || echo 0)
AD=$(q "SELECT id FROM $A_T WHERE kind='chastity' AND status='active'")
r=$(adm admin addon_off -F "addon=$AD" -F "member=$SUB" -F "reason=They asked" -F "notify=1")
ok "turn off chastity: both told" $(echo "$r" | grep -q 'msg=saved' && [ "$(q "SELECT status FROM $A_T WHERE id=$AD")" = ended ] && [ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id IN ($SUB,$KH) AND message LIKE '%site team turned off chastity%'")" = 2 ] && echo 1 || echo 0)
r=$(adm admin end_dynamic -F "dynamic=$DYN" -F "member=$SUB" -F "reason=They asked" -F "notify=1")
ok "end the dynamic: both told" $(echo "$r" | grep -q 'msg=ended' && [ "$(q "SELECT status FROM $D_T WHERE id=$DYN")" = ended ] && [ "$(q "SELECT COUNT(*) FROM $N_T WHERE user_id IN ($SUB,$KH) AND message LIKE '%site team ended your dynamic%'")" = 2 ] && echo 1 || echo 0)

echo "== Remove a record"
r=$(adm admin remove_lock -F "lock=$RID" -F "reason=Duplicate")
ok "lock and its history deleted" $([ "$(lk $RID status)" = none ] && [ "$(q "SELECT COUNT(*) FROM $E_T WHERE lock_id=$RID")" = 0 ] && echo 1 || echo 0)

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
ev "global \$wpdb; foreach(array('$L_T','$E_T','$D_T','$A_T') as \$t) \$wpdb->query(\"DELETE FROM \$t\");" >/dev/null
for u in makh masub maother; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|member-planning' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
