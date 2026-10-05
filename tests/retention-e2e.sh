#!/usr/bin/env bash
# Data retention (Community Events Calendar 1.29.0 + Community Member
# Planning 0.15.0): the daily clean-up removes exactly what the privacy
# statement says, on its schedule, and nothing else.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/retention-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
PASS=0; FAIL=0
ok() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
ev() { $W eval "$1" 2>&1; }
q()  { ev "global \$wpdb; echo \$wpdb->get_var(\"$1\");"; }

for p in community-events-calendar community-member-planning; do rm -rf wp-content/plugins/$p && cp -r $REPO/$p wp-content/plugins/; $W plugin activate $p >/dev/null 2>&1; done
ev 'CMP_Install::maybe_upgrade(); delete_option("cec_db_version");' >/dev/null
ev 'echo 1;' >/dev/null  # a normal load runs init: schedules both clean-ups
RSVP=$(ev 'global $wpdb; echo $wpdb->prefix.CEC_TABLE_RSVP;'); WAIT=$(ev 'global $wpdb; echo $wpdb->prefix.CEC_RSVP::TABLE_WAITLIST;')
VOL=$(ev 'global $wpdb; echo $wpdb->prefix.CEC_Volunteers::TABLE;'); ELOG=$(ev 'global $wpdb; echo $wpdb->prefix.CEC_Audit_Log::TABLE;')
AUD=$(ev 'echo CMP_Install::table("audit_log");'); REP=$(ev 'echo CMP_Install::table("message_reports");')

echo "== Scheduled"
ok "both daily clean-ups are scheduled" $(ev 'echo wp_next_scheduled("cec_daily_retention") && wp_next_scheduled("cmp_daily_retention") ?1:0;')

echo "== Events plugin"
OLD=$($W post create --post_type=cec_event --post_status=publish --post_title="ZZ Old Event" --porcelain 2>/dev/null)
NEW=$($W post create --post_type=cec_event --post_status=publish --post_title="ZZ Recent Event" --porcelain 2>/dev/null)
ev "foreach(array($OLD=>400,$NEW=>30) as \$e=>\$d){ update_post_meta(\$e,'_cec_start',wp_date('Y-m-d\\TH:i',time()-\$d*DAY_IN_SECONDS)); update_post_meta(\$e,'_cec_end',wp_date('Y-m-d\\TH:i',time()-\$d*DAY_IN_SECONDS+3*HOUR_IN_SECONDS)); update_post_meta(\$e,'_cec_submitter_email','sub'.\$e.'@example.com'); update_post_meta(\$e,'_cec_edit_token_hash','x'); update_post_meta(\$e,'_cec_edit_token_expires',time()+99999); global \$wpdb; \$wpdb->insert('$RSVP',array('event_id'=>\$e,'user_id'=>0,'name'=>'Guest','email'=>'g@example.com','guests'=>0,'created_at'=>current_time('mysql'))); \$wpdb->insert('$WAIT',array('event_id'=>\$e,'user_id'=>0,'name'=>'Wait','email'=>'w@example.com','guests'=>0,'created_at'=>current_time('mysql'))); }
global \$wpdb; foreach(array(800,100) as \$d){ \$wpdb->insert('$VOL',array('name'=>'V'.\$d,'email'=>'v@example.com','phone'=>'','interests'=>'','availability'=>'','message'=>'','status'=>'new','created_at'=>wp_date('Y-m-d H:i:s',time()-\$d*DAY_IN_SECONDS))); \$wpdb->insert('$ELOG',array('event_id'=>$NEW,'user_id'=>0,'action'=>'zz_test','detail'=>'d'.\$d,'created_at'=>wp_date('Y-m-d H:i:s',time()-\$d*DAY_IN_SECONDS))); }" >/dev/null
ev 'CEC_Retention::run();' >/dev/null
ok "RSVPs and waitlist removed 12 months after an event" $([ "$(q "SELECT COUNT(*) FROM $RSVP WHERE event_id=$OLD")$(q "SELECT COUNT(*) FROM $WAIT WHERE event_id=$OLD")" = 00 ] && echo 1 || echo 0)
ok "...kept for a recent event" $([ "$(q "SELECT COUNT(*) FROM $RSVP WHERE event_id=$NEW")$(q "SELECT COUNT(*) FROM $WAIT WHERE event_id=$NEW")" = 11 ] && echo 1 || echo 0)
ok "old event: submitter email and edit link removed, event still published" $(ev "echo ''===get_post_meta($OLD,'_cec_submitter_email',true) && ''===get_post_meta($OLD,'_cec_edit_token_hash',true) && 'publish'===get_post_status($OLD)?1:0;")
ok "recent event: submitter email kept" $(ev "echo 'sub$NEW@example.com'===get_post_meta($NEW,'_cec_submitter_email',true)?1:0;")
ok "volunteer sign-ups older than 2 years removed, newer kept" $([ "$(q "SELECT COUNT(*) FROM $VOL WHERE name='V800'")$(q "SELECT COUNT(*) FROM $VOL WHERE name='V100'")" = 01 ] && echo 1 || echo 0)
ok "event log older than 2 years removed, newer kept" $([ "$(q "SELECT COUNT(*) FROM $ELOG WHERE detail='d800'")$(q "SELECT COUNT(*) FROM $ELOG WHERE detail='d100'")" = 01 ] && echo 1 || echo 0)
ok "last run's counts recorded" $(ev '$l=get_option("cec_retention_last"); echo $l && 1===$l["removed"]["rsvps"] && 1===$l["removed"]["volunteers"]?1:0;')

echo "== Member plugin"
for u in rt_old rt_recent rt_verified rt_before rt_poster rt_editor rt_rsvp; do $W user delete $u --yes >/dev/null 2>&1; done
for u in rt_old rt_recent rt_verified rt_before rt_poster rt_editor rt_rsvp; do $W user create $u $u@example.com --role=subscriber --user_pass=retentionpass12 >/dev/null; done
ev "update_option('cmp_retention_since',gmdate('Y-m-d H:i:s',time()-200*DAY_IN_SECONDS)); global \$wpdb; \$set=function(\$l,\$d) use(\$wpdb){ \$wpdb->update(\$wpdb->users,array('user_registered'=>gmdate('Y-m-d H:i:s',time()-\$d*DAY_IN_SECONDS)),array('ID'=>get_user_by('login',\$l)->ID)); clean_user_cache(get_user_by('login',\$l)->ID); };
\$set('rt_old',100); \$set('rt_recent',30); \$set('rt_verified',100); \$set('rt_before',300); \$set('rt_poster',100); \$set('rt_editor',100); \$set('rt_rsvp',100);
update_user_meta(get_user_by('login','rt_verified')->ID,'cmp_verified_email','rt_verified@example.com');
(new WP_User(get_user_by('login','rt_editor')->ID))->set_role('editor');
wp_insert_post(array('post_type'=>'cec_event','post_status'=>'pending','post_title'=>'ZZ by poster','post_author'=>get_user_by('login','rt_poster')->ID));
\$wpdb->insert('$RSVP',array('event_id'=>$NEW,'user_id'=>get_user_by('login','rt_rsvp')->ID,'name'=>'R','email'=>'r@example.com','guests'=>0,'created_at'=>current_time('mysql')));
foreach(array(800,100) as \$d){ \$wpdb->insert('$AUD',array('actor_id'=>0,'action'=>'zz_test_'.\$d,'object_type'=>'test','object_id'=>0,'created_at'=>gmdate('Y-m-d H:i:s',time()-\$d*DAY_IN_SECONDS))); }
foreach(array(1200,100) as \$d){ \$wpdb->insert('$REP',array('reporter_id'=>1,'reported_id'=>2,'conversation_id'=>0,'reason'=>'zz'.\$d,'note'=>'','status'=>'closed','created_at'=>gmdate('Y-m-d H:i:s',time()-\$d*DAY_IN_SECONDS))); }" >/dev/null
ev 'CMP_Retention::run();' >/dev/null
exists(){ $W user get $1 --field=ID >/dev/null 2>&1 && echo 1 || echo 0; }
ok "never-verified account older than 60 days removed" $([ "$(exists rt_old)" = 0 ] && echo 1 || echo 0)
ok "kept: newer than 60 days, verified, created before the clean-up existed" $([ "$(exists rt_recent)$(exists rt_verified)$(exists rt_before)" = 111 ] && echo 1 || echo 0)
ok "kept: has an event, has another role, has an RSVP" $([ "$(exists rt_poster)$(exists rt_editor)$(exists rt_rsvp)" = 111 ] && echo 1 || echo 0)
ok "the removal is audit-logged" $([ "$(q "SELECT COUNT(*) FROM $AUD WHERE action='unverified_account_removed'")" -ge 1 ] && echo 1 || echo 0)
ok "audit log older than 2 years removed, newer kept" $([ "$(q "SELECT COUNT(*) FROM $AUD WHERE action='zz_test_800'")$(q "SELECT COUNT(*) FROM $AUD WHERE action='zz_test_100'")" = 01 ] && echo 1 || echo 0)
ok "member reports older than 3 years removed, newer kept" $([ "$(q "SELECT COUNT(*) FROM $REP WHERE reason='zz1200'")$(q "SELECT COUNT(*) FROM $REP WHERE reason='zz100'")" = 01 ] && echo 1 || echo 0)

echo "== Overdue deletion requests"
ok "none waiting: no reminder" $([ "$(ev 'echo CMP_Retention::overdue_erasures();')" = 0 ] && echo 1 || echo 0)
ev "\$id=wp_create_user_request('rt_recent@example.com','remove_personal_data'); wp_update_post(array('ID'=>\$id,'post_status'=>'request-confirmed')); global \$wpdb; \$wpdb->update(\$wpdb->posts,array('post_modified_gmt'=>gmdate('Y-m-d H:i:s',time()-25*DAY_IN_SECONDS)),array('ID'=>\$id));" >/dev/null
ok "a confirmed request waiting 25 days triggers the admin reminder" $([ "$(ev 'echo CMP_Retention::overdue_erasures();')" = 1 ] && echo 1 || echo 0)
ok "the reminder shows to administrators" $(ev 'wp_set_current_user(1); ob_start(); CMP_Retention::erase_notice(); echo false!==strpos(ob_get_clean(),"promises to complete them within 30 days")?1:0;')

ev "global \$wpdb; \$wpdb->query(\"DELETE FROM {\$wpdb->posts} WHERE post_type='user_request'\"); \$wpdb->query(\"DELETE FROM $AUD WHERE action LIKE 'zz_test_%'\"); \$wpdb->query(\"DELETE FROM $REP WHERE reason LIKE 'zz%'\"); \$wpdb->query(\"DELETE FROM $ELOG WHERE action='zz_test'\"); \$wpdb->query(\"DELETE FROM $VOL WHERE name IN ('V800','V100')\"); \$wpdb->query(\"DELETE FROM $RSVP WHERE event_id=$NEW\"); \$wpdb->query(\"DELETE FROM $WAIT WHERE event_id=$NEW\"); foreach(get_posts(array('post_type'=>'cec_event','post_status'=>'any','title'=>'ZZ by poster','numberposts'=>5)) as \$p) wp_delete_post(\$p->ID,true);" >/dev/null
$W post delete $OLD $NEW --force >/dev/null 2>&1
for u in rt_old rt_recent rt_verified rt_before rt_poster rt_editor rt_rsvp; do $W user delete $u --yes >/dev/null 2>&1; done
echo; echo "debug.log (plugin-related):"; grep -i 'cmp\|cec' wp-content/debug.log 2>/dev/null | grep -v 'Upgrad\|upgraded' | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
