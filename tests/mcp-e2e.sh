#!/usr/bin/env bash
# End-to-end test of colandb-mcp (COL&B Claude Connector): the MCP server at
# /wp-json/colandb/mcp over real HTTP with application passwords.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/mcp-e2e.sh
# Needs the sample events from tests/seed.php, and jq.
WPT=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $WPT/wordpress
W="php $WPT/wp-cli.phar --allow-root"
PASS=0; FAIL=0
ok(){ if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
ev(){ $W eval "$1" 2>&1; }
yes1(){ [ "$1" = "true" ] && echo 1 || echo 0; }

rm -rf wp-content/plugins/colandb-mcp && cp -r $REPO/colandb-mcp wp-content/plugins/
$W plugin activate colandb-mcp >/dev/null 2>&1
# Application passwords need HTTPS (or a "local" site); the test server is plain HTTP.
printf '<?php add_filter( "wp_is_application_passwords_available", "__return_true" );\n' > wp-content/mu-plugins/mcp-test-app-passwords.php
$W option delete colandb_mcp_log >/dev/null 2>&1
ev 'update_option("colandb_updater",array("token"=>"github_pat_SECRET123","channel"=>"staging","last_check"=>time()),false);' >/dev/null
for u in claude-agent mcp-sub mcp-admin; do $W user delete $u --yes >/dev/null 2>&1; done
$W user create claude-agent mcp-agent@example.invalid --role=colandb_agent >/dev/null
$W user create mcp-sub mcp-sub@example.invalid --role=subscriber >/dev/null
$W user create mcp-admin mcp-admin@example.invalid --role=administrator >/dev/null
AGENT=$(printf 'claude-agent:%s' "$($W user application-password create claude-agent Claude --porcelain)" | base64 -w0)
SUB=$(printf 'mcp-sub:%s' "$($W user application-password create mcp-sub Claude --porcelain)" | base64 -w0)
ADMIN=$(printf 'mcp-admin:%s' "$($W user application-password create mcp-admin Claude --porcelain)" | base64 -w0)
PUB=$($W post list --post_type=cec_event --post_status=publish --field=ID --posts_per_page=1)
PENDING=$($W post list --post_type=cec_event --post_status=pending --field=ID --posts_per_page=1)
DRAFT=$($W post create --post_type=cec_event --post_status=draft --post_title="MCP Draft Event" --porcelain)
rm -f wp-content/debug.log

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; (PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
URL='http://127.0.0.1:8899/?rest_route=/colandb/mcp'
# rpc AUTH JSON -> body; rpcs: status code only
rpc(){ curl -s -X POST "$URL" -H "Authorization: Basic $1" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' --data "$2"; }
code(){ curl -s -o /dev/null -w '%{http_code}' -X POST "$URL" ${1:+-H "Authorization: Basic $1"} -H 'Content-Type: application/json' --data "$2"; }
call(){ rpc "$AGENT" "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}"; }
INIT='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}'

echo "== Who may connect"
ok "no credentials -> 401" $([ "$(code '' "$INIT")" = 401 ] && echo 1 || echo 0)
ok "wrong application password -> 401" $([ "$(code "$(printf 'claude-agent:nope nope nope nope' | base64 -w0)" "$INIT")" = 401 ] && echo 1 || echo 0)
ok "subscriber -> 403" $([ "$(code "$SUB" "$INIT")" = 403 ] && echo 1 || echo 0)
ok "Claude Agent -> 200" $([ "$(code "$AGENT" "$INIT")" = 200 ] && echo 1 || echo 0)
ok "administrator -> 200" $([ "$(code "$ADMIN" "$INIT")" = 200 ] && echo 1 || echo 0)
ok "GET -> 405" $([ "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Basic $AGENT" "$URL")" = 405 ] && echo 1 || echo 0)

echo "== Claude Agent role is read-only elsewhere"
ok "role has only read + connector caps" $(ev '$r=get_role("colandb_agent"); $c=array_keys(array_filter($r->capabilities)); sort($c); echo $c===array("colandb_mcp_connect","colandb_mcp_view_pending_events","colandb_mcp_view_site_health","read")?1:0;')
ok "agent can't create a post via REST" $(c=$(curl -s -o /dev/null -w '%{http_code}' -X POST 'http://127.0.0.1:8899/?rest_route=/wp/v2/posts' -H "Authorization: Basic $AGENT" -H 'Content-Type: application/json' --data '{"title":"x","status":"publish"}'); [ "$c" = 403 ] && echo 1 || echo 0)
ok "agent can't read the pending event via core REST" $(c=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8899/?rest_route=/wp/v2/cec_event/$PENDING" -H "Authorization: Basic $AGENT"); [ "$c" = 401 ] || [ "$c" = 403 ] || [ "$c" = 404 ] && echo 1 || echo 0)
ok "agent can't list users with emails (context=edit)" $(c=$(curl -s -o /dev/null -w '%{http_code}' 'http://127.0.0.1:8899/?rest_route=/wp/v2/users&context=edit' -H "Authorization: Basic $AGENT"); [ "$c" = 403 ] && echo 1 || echo 0)

echo "== Protocol"
r=$(rpc "$AGENT" "$INIT")
ok "initialize echoes a supported version" $(yes1 $(echo "$r" | jq '.result.protocolVersion=="2025-06-18"'))
ok "initialize names the server and version" $(yes1 $(echo "$r" | jq --arg v "$(sed -n 's/^ \* Version: //p' $REPO/colandb-mcp/colandb-mcp.php)" '.result.serverInfo.name=="colandb" and .result.serverInfo.version==$v and .result.capabilities.tools!=null'))
ok "unknown version -> newest" $(yes1 $(rpc "$AGENT" '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"1999-01-01"}}' | jq '.result.protocolVersion=="2025-11-25"'))
ok "notification -> 202" $([ "$(code "$AGENT" '{"jsonrpc":"2.0","method":"notifications/initialized"}')" = 202 ] && echo 1 || echo 0)
ok "ping -> {}" $(yes1 $(rpc "$AGENT" '{"jsonrpc":"2.0","id":"p","method":"ping"}' | jq '.id=="p" and .result=={}'))
ok "unknown method -> -32601" $(yes1 $(rpc "$AGENT" '{"jsonrpc":"2.0","id":2,"method":"resources/list"}' | jq '.error.code==-32601'))
ok "batch -> -32600" $(yes1 $(rpc "$AGENT" '[{"jsonrpc":"2.0","id":2,"method":"ping"}]' | jq '.error.code==-32600'))
# WordPress itself refuses malformed JSON before the server sees it.
ok "bad JSON -> 400" $([ "$(code "$AGENT" '{nope')" = 400 ] && echo 1 || echo 0)
ok "response not cacheable" $(curl -s -D - -o /dev/null -X POST "$URL" -H "Authorization: Basic $AGENT" -H 'Content-Type: application/json' --data "$INIT" | grep -qi '^cache-control:.*no-store' && echo 1 || echo 0)

echo "== tools/list"
r=$(rpc "$AGENT" '{"jsonrpc":"2.0","id":3,"method":"tools/list"}')
ok "four tools" $(yes1 $(echo "$r" | jq '[.result.tools[].name]==["list_events","get_event","list_pending_events","site_health"]'))
ok "all read-only, not destructive" $(yes1 $(echo "$r" | jq '[.result.tools[].annotations | .readOnlyHint and (.destructiveHint|not)] | all'))
ok "every inputSchema is an object with properties {}" $(yes1 $(echo "$r" | jq '[.result.tools[].inputSchema | .type=="object" and (.properties|type)=="object"] | all'))
ok "every tool has an outputSchema" $(yes1 $(echo "$r" | jq '[.result.tools[].outputSchema.type=="object"] | all'))
ok "abilities not in the core abilities REST list" $(curl -s 'http://127.0.0.1:8899/?rest_route=/wp-abilities/v1/abilities&per_page=100' -H "Authorization: Basic $ADMIN" | jq -e '[.[]?.name] | map(startswith("colandb/")) | any | not' >/dev/null && echo 1 || echo 0)

echo "== list_events"
r=$(call list_events '{"from":"2026-01-01","to":"2026-12-31","limit":50}')
n=$(echo "$r" | jq '.result.structuredContent.events|length')
ok "returns published events ($n)" $([ "$n" -gt 0 ] && echo 1 || echo 0)
ok "never the pending event" $(yes1 $(echo "$r" | jq --argjson p $PENDING '[.result.structuredContent.events[].id] | index($p) == null'))
ok "every one is published" $(ids=$(echo "$r" | jq -c '[.result.structuredContent.events[].id]'); ev "\$ids=json_decode('$ids'); foreach(\$ids as \$i){ if('publish'!==get_post_status(\$i)){echo 0; return;} } echo 1;")
ok "soonest first" $(yes1 $(echo "$r" | jq '[.result.structuredContent.events[].start] as $s | $s == ($s|sort)'))
ok "no editorial or member fields" $(yes1 $(echo "$r" | jq '[.result.structuredContent.events[] | keys[]] | unique | (index("source_url")==null and index("rsvp")==null and index("email")==null)'))
ok "text content is the same JSON" $(yes1 $(echo "$r" | jq '(.result.content[0].text|fromjson)==.result.structuredContent'))
r2=$(call list_events '{"from":"2026-01-01","to":"2026-12-31","limit":2}')
ok "limit 2 -> 2 events, total counts all" $(yes1 $(echo "$r2" | jq --argjson n $n '(.result.structuredContent.events|length)==2 and .result.structuredContent.total==$n'))
ok "search narrows" $(yes1 $(call list_events '{"from":"2026-01-01","to":"2026-12-31","search":"Festival"}' | jq '[.result.structuredContent.events[].title] | length>0 and all(test("Festival"))'))
ok "default range from today" $(yes1 $(call list_events '{}' | jq --arg t "$(TZ=America/New_York date +%F)" '.result.structuredContent.from==$t'))
ok "\"to\" before \"from\" -> tool error" $(yes1 $(call list_events '{"from":"2026-05-01","to":"2026-04-01"}' | jq '.result.isError==true'))
ok "range over 366 days -> tool error" $(yes1 $(call list_events '{"from":"2026-01-01","to":"2027-06-01"}' | jq '.result.isError==true'))
ok "bad date -> tool error" $(yes1 $(call list_events '{"from":"2026-02-30"}' | jq '.result.isError==true'))
ok "unknown argument -> tool error" $(yes1 $(call list_events '{"status":"draft"}' | jq '.result.isError==true'))
ok "limit over 50 -> tool error" $(yes1 $(call list_events '{"limit":500}' | jq '.result.isError==true'))

echo "== get_event"
r=$(call get_event "{\"id\":$PUB}")
ok "published event with description" $(yes1 $(echo "$r" | jq --argjson p $PUB '.result.structuredContent.id==$p and (.result.structuredContent.description|type)=="string" and (.result.structuredContent.url|length)>0'))
ok "pending event -> not found" $(yes1 $(call get_event "{\"id\":$PENDING}" | jq '.result.isError==true and (.result.content[0].text|test("No published event"))'))
ok "draft event -> not found" $(yes1 $(call get_event "{\"id\":$DRAFT}" | jq '.result.isError==true'))
ev "wp_update_post(array('ID'=>$PUB,'post_password'=>'pw'));" >/dev/null
ok "password-protected text withheld" $(yes1 $(call get_event "{\"id\":$PUB}" | jq '.result.structuredContent.description==""'))
ev "wp_update_post(array('ID'=>$PUB,'post_password'=>''));" >/dev/null
ok "missing id -> tool error" $(yes1 $(call get_event '{}' | jq '.result.isError==true'))

echo "== list_pending_events"
r=$(call list_pending_events '{}')
ok "lists the pending event" $(yes1 $(echo "$r" | jq --argjson p $PENDING '[.result.structuredContent.events[].id] | index($p) != null'))
ok "drafts only when asked" $(yes1 $(echo "$r" | jq --argjson d $DRAFT '[.result.structuredContent.events[].id] | index($d) == null'))
ok "include_drafts adds the draft" $(yes1 $(call list_pending_events '{"include_drafts":true}' | jq --argjson d $DRAFT '[.result.structuredContent.events[].id] | index($d) != null'))
ok "no contact details" $(echo "$r" | grep -qiE '@|email' && echo 0 || echo 1)
ok "review link to wp-admin" $(yes1 $(echo "$r" | jq '[.result.structuredContent.events[].review_url | test("wp-admin/post.php")] | all'))

echo "== site_health"
r=$(call site_health '{}')
ok "reports colandb-mcp active" $(yes1 $(echo "$r" | jq '[.result.structuredContent.plugins[] | select(.slug=="colandb-mcp")][0] | .active and .installed'))
ok "reports the events plugin version" $(yes1 $(echo "$r" | jq --arg v "$(sed -n 's/^ \* Version: //p' $REPO/community-events-calendar/community-events-calendar.php)" '[.result.structuredContent.plugins[] | select(.slug=="community-events-calendar")][0].version==$v'))
ok "updater state without the token" $(yes1 $(echo "$r" | jq '.result.structuredContent.updater.connected==true and (tostring|test("SECRET")|not)'))
ok "no arguments works too" $(yes1 $(rpc "$AGENT" '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"site_health"}}' | jq '.result.isError!=true and .result.structuredContent.wordpress_version!=null'))
ok "unknown tool -> -32602" $(yes1 $(call delete_everything '{}' | jq '.error.code==-32602'))

echo "== Capabilities per tool"
ev 'get_role("colandb_agent")->remove_cap("colandb_mcp_view_pending_events");' >/dev/null
ok "without the pending cap: not listed" $(yes1 $(rpc "$AGENT" '{"jsonrpc":"2.0","id":3,"method":"tools/list"}' | jq '[.result.tools[].name] | index("list_pending_events")==null and length==3'))
ok "without the pending cap: refused" $(yes1 $(call list_pending_events '{}' | jq '.result.isError==true'))
ev 'COLANDB_MCP_Role::install();' >/dev/null
ok "install() restores the role" $(yes1 $(rpc "$AGENT" '{"jsonrpc":"2.0","id":3,"method":"tools/list"}' | jq '.result.tools|length==4'))

echo "== Log and updater"
ok "calls are logged with user and outcome" $(ev '$e=COLANDB_MCP_Log::entries(); $ok=0; $err=0; foreach($e as $x){ if("claude-agent"===$x["user"]&&"site_health"===$x["tool"]&&"ok"===$x["outcome"])$ok=1; if("ability_invalid_permissions"===$x["outcome"])$err=1; } echo $ok&&$err?1:0;')
ok "log option not autoloaded" $(ev 'global $wpdb; $a=$wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name=\"colandb_mcp_log\""); echo in_array($a,array("no","off"),true)?1:0;')
ok "log keeps at most 200" $(ev 'for($i=0;$i<230;$i++){COLANDB_MCP_Log::add("t",array(),"ok",0);} echo 200===count(COLANDB_MCP_Log::entries())?1:0;')
ok "updater manages colandb-mcp" $(ev 'echo in_array("colandb-mcp",apply_filters("colandb_updater_plugins",array()),true)?1:0;')
ok "admin page renders" $(ev 'wp_set_current_user(1); ob_start(); COLANDB_MCP_Admin::render(); $h=ob_get_clean(); echo false!==strpos($h,"colandb/mcp") && false!==strpos($h,"claude-agent")?1:0;')

# Clean up.
$W post delete $DRAFT --force >/dev/null
for u in claude-agent mcp-sub mcp-admin; do $W user delete $u --yes >/dev/null 2>&1; done
$W option delete colandb_updater colandb_mcp_log >/dev/null 2>&1
rm -f wp-content/mu-plugins/mcp-test-app-passwords.php
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null

echo "RESULT: $PASS passed, $FAIL failed"
[ -f wp-content/debug.log ] && grep -i "colandb-mcp\|colandb_mcp\|COLANDB_MCP" wp-content/debug.log | head -20
[ "$FAIL" = 0 ]
