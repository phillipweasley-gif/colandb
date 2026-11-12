#!/usr/bin/env bash
# End-to-end test of COL&B Silent Auction 0.1.0 (silent auctions and fixed-price sales) with WooCommerce: an auction
# and its items added through the real wp-admin edit screens, the Auction page
# (never cached, no bidder names), bidding over real HTTP (minimums, top
# bidder, outbid email, Buy It Now, sign-in, expired form, not open yet,
# two bids at the same moment, last-minute extension), closing (one order per
# winner, winner and summary emails, once only), paying (the fundraiser total
# on the Fundraising page goes up, once), and who may edit.
# Needs WooCommerce in the kit: setup.sh does not install it, this script does.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/auction-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
H=http://localhost:8899
T=$S/auction; rm -rf $T; mkdir -p $T
PASS=0; FAIL=0
ok()   { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has()  { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt(){ grep -q -- "$2" "$1" && echo 0 || echo 1; }
login(){ rm -f $T/$1.jar; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null "$H/wp-login.php"; curl -s -c $T/$1.jar -b $T/$1.jar -o /dev/null -d "log=$2&pwd=$3&wp-submit=Log+In&testcookie=1" "$H/wp-login.php"; }
get()  { curl -s -L -b $T/$1.jar -c $T/$1.jar -o $T/$2.html "$3"; }
ev()   { $W eval "$1" 2>&1 | grep -v '_load_textdomain_just_in_time'; }
field(){ php -r '$h=file_get_contents($argv[1]); preg_match("~name=[\"\x27]".preg_quote($argv[2],"~")."[\"\x27] value=[\"\x27]([^\"\x27]*)[\"\x27]~",$h,$m) || preg_match("~id=[\"\x27]".preg_quote($argv[2],"~")."[\"\x27] name=[\"\x27]".preg_quote($argv[2],"~")."[\"\x27] value=[\"\x27]([^\"\x27]*)[\"\x27]~",$h,$m); echo $m[1]??"";' "$1" "$2"; }
meta() { ev "echo get_post_meta($1,'$2',true);"; }
# save <user> <post id> <post type> <extra --data-urlencode args...>: the classic edit form's Publish/Update.
save(){ local u=$1 id=$2 type=$3; shift 3
	curl -s -b $T/$u.jar -c $T/$u.jar -o $T/edit.html "$H/wp-admin/post.php?post=$id&action=edit"
	curl -s -b $T/$u.jar -c $T/$u.jar -o /dev/null -w '%{http_code}' "$H/wp-admin/post.php" --data-urlencode "action=editpost" --data-urlencode "post_ID=$id" --data-urlencode "post_type=$type" \
		--data-urlencode "_wpnonce=$(field $T/edit.html _wpnonce)" --data-urlencode "csa_nonce=$(field $T/edit.html csa_nonce)" --data-urlencode "original_post_status=$(field $T/edit.html original_post_status)" \
		--data-urlencode "post_status=publish" --data-urlencode "publish=Publish" "$@"; }
newpost(){ curl -s -b $T/admin.jar -c $T/admin.jar -o $T/new.html "$H/wp-admin/post-new.php?post_type=$1$2"; field $T/new.html post_ID; }
# bid <user> <item> <amount|buy> [nonce]: posts the page's bid form, prints the result code.
bid(){ local u=$1 item=$2 amt=$3 nonce=${4:-}
	[ -z "$nonce" ] && { get $u formpage "$H/?page_id=$PAGE"; nonce=$(php -r 'preg_match("~name=\"csa_nonce\" value=\"([^\"]+)\"~",file_get_contents($argv[1]),$m); echo $m[1]??"";' $T/formpage.html); }
	local extra=(--data-urlencode "csa_amount=$amt"); [ "$amt" = buy ] && extra=(--data-urlencode "csa_buy_now=1")
	curl -s -b $T/$u.jar -c $T/$u.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" --data-urlencode "action=csa_bid" --data-urlencode "csa_item=$item" \
		--data-urlencode "csa_back=$H/?page_id=$PAGE" --data-urlencode "csa_nonce=$nonce" "${extra[@]}" | sed -n 's/.*csa_msg=\([a-z_]*\).*/\1/p'; }
mails(){ wc -l < wp-content/mail.log 2>/dev/null || echo 0; }

# WooCommerce (once) and this repo's plugins.
if [ ! -d wp-content/plugins/woocommerce ]; then
	curl -sSL -o $T/wc.zip https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip && unzip -q -o $T/wc.zip -d wp-content/plugins/
fi
$W plugin activate woocommerce >/dev/null 2>&1
for p in community-events-calendar community-member-planning colandb-updater colandb-silent-auction; do rm -rf wp-content/plugins/$p && cp -r $REPO/$p wp-content/plugins/; $W plugin activate $p >/dev/null 2>&1; done
pkill -f "php -S 127.0.0.1:8899" 2>/dev/null; sleep 0.5
(PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . >/dev/null 2>&1 &); sleep 1.5
ev 'foreach ( get_posts( array( "post_type" => array( "csa_auction", "csa_item", "cec_fundraiser" ), "post_status" => "any", "posts_per_page" => -1 ) ) as $p ) { if ( 0 === strpos( $p->post_title, "ZZA " ) ) { wp_delete_post( $p->ID, true ); } } global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}csa_bids" );' >/dev/null
ev "wp_update_post(array('ID'=>(int) get_option('csa_page_id'),'post_status'=>'draft'));" >/dev/null
# A new WooCommerce shows "coming soon" instead of store pages (the pay page too) until set live.
ev 'update_option("woocommerce_coming_soon","no");' >/dev/null
for u in zzabidder1 zzabidder2 zzabidder3; do $W user delete $u --yes >/dev/null 2>&1; $W user create $u $u@example.com --role=subscriber --user_pass=${u}pass1 --first_name=${u^} >/dev/null 2>&1; done
U1=$(ev 'echo get_user_by("login","zzabidder1")->ID;'); U2=$(ev 'echo get_user_by("login","zzabidder2")->ID;'); U3=$(ev 'echo get_user_by("login","zzabidder3")->ID;')
: > wp-content/debug.log; : > wp-content/mail.log
FR=$(ev '$id=wp_insert_post(array("post_type"=>"cec_fundraiser","post_status"=>"publish","post_title"=>"ZZA Registration Fund","post_content"=>"x")); update_post_meta($id,"_cec_fr_goal",1000); echo $id;')
ev "CEC_Fundraisers::add_donation($FR, 100, 'cash', 'opening gift');" >/dev/null
# WooCommerce taxes on, one 8% rate (Franklin County since April 2025) (the test shop's address), as the site team would set up.
ev 'update_option("woocommerce_calc_taxes","yes"); update_option("woocommerce_tax_based_on","base"); update_option("woocommerce_default_country","US:OH"); global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_tax_rates"); WC_Tax::_insert_tax_rate(array("tax_rate_country"=>"US","tax_rate_state"=>"OH","tax_rate"=>"8.0000","tax_rate_name"=>"Sales tax","tax_rate_priority"=>1,"tax_rate_compound"=>0,"tax_rate_shipping"=>0,"tax_rate_order"=>0,"tax_rate_class"=>""));' >/dev/null

echo "== Installed"
PAGE=$(ev 'echo (int) get_option("csa_page_id");')
ok "activation adds a draft Silent Auction page with [csa_auction] and the bids table" $([ "$(ev "echo get_post_status($PAGE).'|'.get_post_field('post_content',$PAGE).'|'.(int) (bool) \$GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM '.CSA_Install::table());")" = "draft|[csa_auction]|0" ] && echo 1 || echo 0)
ok "the closing check runs every 5 minutes" $([ -n "$(ev 'echo wp_next_scheduled("csa_check_closing");')" ] && [ "$(ev 'echo wp_get_schedule("csa_check_closing");')" = csa_five_minutes ] && echo 1 || echo 0)
ok "the updater manages the plugin" $([ "$(ev 'echo in_array("colandb-silent-auction", COLANDB_Updater::managed_slugs(), true) ? 1 : 0;')" = 1 ] && echo 1 || echo 0)

echo "== Adding an auction and items in wp-admin"
login admin admin admin
curl -s -b $T/admin.jar -c $T/admin.jar -o $T/list.html "$H/wp-admin/edit.php?post_type=csa_auction"
ok "the draft-page reminder shows on the Auctions list" $(has $T/list.html 'Silent Auction page is still a draft')
A=$(newpost csa_auction)
ok "a new auction starts as a fixed-price sale with the not-tax-deductible notice, sale terms and 3% card costs" $([ "$(has $T/new.html 'not tax-deductible.</textarea>')$(has $T/new.html 'The first person to buy it gets it')$(has $T/new.html 'name="csa_costs_pct" value="3"')$(hasnt $T/new.html 'csa_fee')$(has $T/new.html 'name="csa_format" value="sale"  checked')" = 11111 ] && echo 1 || echo 0)
ok "Add New Auction shows the dates, last-minute, fundraiser and pickup fields" $([ "$(has $T/new.html 'name="csa_start"')$(has $T/new.html 'name="csa_antisnipe" value="1"  checked')$(has $T/new.html "value=\"$FR\"")$(has $T/new.html 'name="csa_pickup"')" = 1111 ] && echo 1 || echo 0)
START=$(ev 'echo wp_date("Y-m-d\TH:i", time()-3600);'); END=$(ev 'echo wp_date("Y-m-d\TH:i", time()+86400);')
code=$(save admin $A csa_auction --data-urlencode "post_title=ZZA Winter Auction" --data-urlencode "content=Bid on great things." --data-urlencode "csa_start=$START" --data-urlencode "csa_end=$END" \
	--data-urlencode "csa_format=auction" --data-urlencode "csa_antisnipe=1" --data-urlencode "csa_fundraiser=$FR" --data-urlencode "csa_pickup=Pick up at Leather Invasion." \
	--data-urlencode "csa_tax=1" --data-urlencode "csa_costs_pct=3" --data-urlencode "csa_collector=ZZA Studios" --data-urlencode "csa_pay_days=5" \
	--data-urlencode "csa_tax_notice=ZZA is not a 501(c)(3), so nothing is tax-deductible." --data-urlencode "csa_terms=ZZTERMS bids are binding.")
ok "saving the auction keeps its dates (site time), fundraiser and pickup" $([ "$code" = 302 ] && [ "$(ev "echo wp_date('Y-m-d\TH:i', get_post_meta($A,'_csa_end',true)).'|'.get_post_meta($A,'_csa_fundraiser',true).'|'.get_post_meta($A,'_csa_antisnipe',true).'|'.get_post_meta($A,'_csa_pickup',true);")" = "$END|$FR|1|Pick up at Leather Invasion." ] && echo 1 || echo 0)
I1=$(newpost csa_item "&csa_auction=$A")
ok "sales tax on, 3% card costs, collector, 5 days to pay, notice and terms saved" $([ "$(ev "echo get_post_meta($A,'_csa_tax',true).'|'.get_post_meta($A,'_csa_costs_pct',true).'|'.get_post_meta($A,'_csa_collector',true).'|'.get_post_meta($A,'_csa_pay_days',true).'|'.get_post_meta($A,'_csa_terms',true);")" = "1|300|ZZA Studios|5|ZZTERMS bids are binding." ] && echo 1 || echo 0)
ok "Add an item from the auction picks that auction" $(has $T/new.html "value=\"$A\"  selected")
code=$(save admin $I1 csa_item --data-urlencode "post_title=ZZA Leather Harness" --data-urlencode "content=Custom fit." --data-urlencode "csa_auction=$A" --data-urlencode "csa_donor=ZZA Leatherworks" \
	--data-urlencode "csa_value=\$250" --data-urlencode "csa_start_bid=50" --data-urlencode "csa_increment=5" --data-urlencode "csa_buy_now=")
I2=$(newpost csa_item "&csa_auction=$A")
save admin $I2 csa_item --data-urlencode "post_title=ZZA Weekend Getaway" --data-urlencode "csa_auction=$A" --data-urlencode "csa_start_bid=100" --data-urlencode "csa_increment=10" --data-urlencode "csa_buy_now=300" >/dev/null
I3=$(newpost csa_item "&csa_auction=$A")
save admin $I3 csa_item --data-urlencode "post_title=ZZA Gift Basket" --data-urlencode "csa_auction=$A" --data-urlencode "csa_start_bid=20.50" --data-urlencode "csa_increment=0" >/dev/null
ok "item prices are saved in cents, bids rounded up to whole dollars; a missing minimum raise becomes \$1" $([ "$(ev "echo get_post_meta($I1,'_csa_start_bid',true).'|'.get_post_meta($I1,'_csa_value',true).'|'.get_post_meta($I2,'_csa_buy_now',true).'|'.get_post_meta($I3,'_csa_start_bid',true).'|'.get_post_meta($I3,'_csa_increment',true);")" = "5000|25000|30000|2100|100" ] && echo 1 || echo 0)
ev "wp_update_post(array('ID'=>$PAGE,'post_status'=>'publish'));" >/dev/null

echo "== The Auction page"
curl -s -D $T/h.txt -o $T/page.html "$H/?page_id=$PAGE"
ok "shows the auction, its items, prices, donor, value and pickup" $([ "$(has $T/page.html 'ZZA Winter Auction')$(has $T/page.html 'ZZA Leather Harness')$(has $T/page.html 'Starting bid <strong>\$50</strong>')$(has $T/page.html 'Donated by ZZA Leatherworks · Est. value \$250 (from the donor)')$(has $T/page.html 'Pick up at Leather Invasion.')$(has $T/page.html 'Starting bid <strong>\$21</strong>')" = 111111 ] && echo 1 || echo 0)
ok "before bidding: pay within 5 days, tax added, no other fees, who collects" $(has $T/page.html 'Winners pay online by card within 5 days of bidding closing. Sales tax is added; there are no other fees. Payments are collected for COL&amp;B by ZZA Studios.')
ok "says nothing is tax-deductible, and what the fundraiser gets" $([ "$(has $T/page.html 'ZZA is not a 501(c)(3), so nothing is tax-deductible.')$(has $T/page.html 'Bids include card processing costs (about 3%), which are paid from the winning bid. The rest goes to: ZZA Registration Fund')$(hasnt $T/page.html 'Proceeds go to')" = 111 ] && echo 1 || echo 0)
ok "shows the auction terms" $([ "$(has $T/page.html 'id="csa-terms"')$(has $T/page.html 'ZZTERMS bids are binding.')" = 11 ] && echo 1 || echo 0)
ok "signed out: Sign in to bid, no bid form" $([ "$(has $T/page.html 'Sign in to bid')$(hasnt $T/page.html 'name="csa_amount"')" = 11 ] && echo 1 || echo 0)
ok "never cached (no-store, no CDN hint)" $(grep -qi 'cache-control: no-store' $T/h.txt && echo 1 || echo 0)
ok "has a countdown to closing" $(has $T/page.html 'data-csa-end=')
ok "no WooCommerce visitor tracking (order attribution sbjs cookies) on the site" $([ "$(hasnt $T/page.html 'sourcebuster')$(hasnt $T/page.html 'wc_order_attribution')$(ev 'echo get_option("woocommerce_feature_order_attribution_enabled");')" = "11no" ] && echo 1 || echo 0)

echo "== Bidding"
login b1 zzabidder1 zzabidder1pass1; login b2 zzabidder2 zzabidder2pass1; login b3 zzabidder3 zzabidder3pass1
get b1 p1 "$H/?page_id=$PAGE"
ok "signed in: a bid form with the minimum filled in" $([ "$(has $T/p1.html 'name="csa_amount"')$(has $T/p1.html 'value="50" required')$(has $T/p1.html 'Minimum bid \$50')" = 111 ] && echo 1 || echo 0)
ok "a bid under the starting bid is refused" $([ "$(bid b1 $I1 45)" = too_low ] && echo 1 || echo 0)
ok "a bid that isn't an amount is refused" $([ "$(bid b1 $I1 abc)" = bad ] && echo 1 || echo 0)
ok "bids must be whole dollars" $([ "$(bid b1 $I1 50.50)" = whole ] && [ "$(meta $I1 _csa_high)" = "" ] && echo 1 || echo 0)
ok "a bid at the starting bid is placed" $([ "$(bid b1 $I1 50)" = placed ] && [ "$(meta $I1 _csa_high)" = "5000|$U1" ] && echo 1 || echo 0)
ok "the top bidder can't bid against themselves" $([ "$(bid b1 $I1 60)" = already_top ] && echo 1 || echo 0)
ok "the next bid must add the minimum raise" $([ "$(bid b2 $I1 54)" = too_low ] && echo 1 || echo 0)
M0=$(mails)
ok "a higher bid is placed" $([ "$(bid b2 $I1 55)" = placed ] && [ "$(meta $I1 _csa_high)" = "5500|$U2" ] && [ "$(meta $I1 _csa_bid_count)" = 2 ] && echo 1 || echo 0)
ok "the outbid person gets one email with the new top bid and a link" $([ "$(mails)" = $((M0+1)) ] && tail -1 wp-content/mail.log | grep -q 'zzabidder1@example.com' && tail -1 wp-content/mail.log | grep -q "outbid on ZZA Leather Harness" && tail -1 wp-content/mail.log | grep -q 'The top bid is now \$55' && tail -1 wp-content/mail.log | grep -q "csa-item-$I1" && echo 1 || echo 0)
ok "a bid over \$50,000 is refused as a likely typo" $([ "$(bid b1 $I1 60000)" = too_high ] && echo 1 || echo 0)
ok "a bid with an expired form is refused" $([ "$(bid b1 $I1 70 nonsense)" = expired ] && [ "$(meta $I1 _csa_high)" = "5500|$U2" ] && echo 1 || echo 0)
r=$(curl -s -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" -d "action=csa_bid&csa_item=$I1&csa_amount=99&csa_back=$H/?page_id=$PAGE")
ok "signed out, a bid goes to sign-in and isn't placed" $(echo "$r" | grep -q 'wp-login.php' && [ "$(meta $I1 _csa_high)" = "5500|$U2" ] && echo 1 || echo 0)
get b1 p1 "$H/?page_id=$PAGE"; get b2 p2 "$H/?page_id=$PAGE"; curl -s -o $T/pub.html "$H/?page_id=$PAGE"
ok "the page shows the top bid and count; the outbid person sees it" $([ "$(has $T/p1.html 'Top bid <strong>\$55</strong> · 2 bids')$(has $T/p1.html 'been outbid.')$(has $T/p2.html 're the top bidder.')$(has $T/p1.html 'value="60" required')$(hasnt $T/p2.html "csa-amt-$I1\"")" = 11111 ] && echo 1 || echo 0)
ok "bidder names and emails are never on the page" $([ "$(hasnt $T/pub.html zzabidder)$(hasnt $T/p1.html zzabidder2)$(hasnt $T/p2.html zzabidder1)" = 111 ] && echo 1 || echo 0)
curl -s -L -b $T/b1.jar -o $T/msg.html "$H/?page_id=$PAGE&csa_msg=placed&csa_item=$I1"
ok "after a bid the item shows a message" $(has $T/msg.html 'Your bid is in.')

echo "== Two bids at the same moment"
N1=$(php -r 'preg_match("~name=\"csa_nonce\" value=\"([^\"]+)\"~",file_get_contents($argv[1]),$m); echo $m[1];' $T/p1.html)
get b3 p3 "$H/?page_id=$PAGE"; N3=$(php -r 'preg_match("~name=\"csa_nonce\" value=\"([^\"]+)\"~",file_get_contents($argv[1]),$m); echo $m[1];' $T/p3.html)
for i in 1 2 3; do
	( bid b1 $I3 25 $N1 > $T/race1.txt ) & ( bid b3 $I3 25 $N3 > $T/race3.txt ) & wait
	[ "$(sort $T/race1.txt $T/race3.txt | tr '\n' ' ')" = "placed too_low " ] && RACE=1 || RACE=0
	[ $RACE = 1 ] && break
	ev "delete_post_meta($I3,'_csa_high'); add_post_meta($I3,'_csa_high','',true); global \$wpdb; \$wpdb->query('DELETE FROM '.CSA_Install::table().' WHERE item_id=$I3');" >/dev/null
done
ok "exactly one of two equal bids wins; the other is told to bid more" $RACE
ok "only one bid is recorded for it" $([ "$(ev "echo CSA_Bids::count($I3);")" = 1 ] && echo 1 || echo 0)

echo "== Buy It Now"
get b1 p1 "$H/?page_id=$PAGE"
ok "the Buy It Now button shows its price" $(has $T/p1.html 'Buy It Now for \$300')
ok "a bid on that item is placed" $([ "$(bid b1 $I2 150)" = placed ] && echo 1 || echo 0)
M0=$(mails)
ok "Buy It Now buys it at the price and ends bidding on it" $([ "$(bid b2 $I2 buy)" = bought ] && [ "$(meta $I2 _csa_high)" = "30000|$U2" ] && [ -n "$(meta $I2 _csa_sold_at)" ] && echo 1 || echo 0)
ok "the person it was taken from is told it was bought" $([ "$(mails)" = $((M0+1)) ] && tail -1 wp-content/mail.log | grep -q 'ZZA Weekend Getaway has been bought' && echo 1 || echo 0)
ok "no more bids on a bought item" $([ "$(bid b1 $I2 400)" = closed ] && echo 1 || echo 0)
curl -s -o $T/pub.html "$H/?page_id=$PAGE"
ok "the page shows it as bought" $(has $T/pub.html 'Bought with Buy It Now for \$300')

echo "== Not open, drafts, last-minute bids"
A2=$(ev "\$id=wp_insert_post(array('post_type'=>'csa_auction','post_status'=>'publish','post_title'=>'ZZA Spring Auction')); update_post_meta(\$id,'_csa_start',time()+86400); update_post_meta(\$id,'_csa_end',time()+2*86400); echo \$id;")
I4=$(ev "\$id=wp_insert_post(array('post_type'=>'csa_item','post_status'=>'publish','post_title'=>'ZZA Spring Item')); update_post_meta(\$id,'_csa_auction',$A2); update_post_meta(\$id,'_csa_start_bid',1000); echo \$id;")
ok "bids before an auction opens are refused" $([ "$(bid b1 $I4 20)" = not_open ] && echo 1 || echo 0)
curl -s -o $T/pub.html "$H/?page_id=$PAGE"
ok "an upcoming auction shows when bidding opens" $(has $T/pub.html 'Bidding opens')
I5=$(ev "\$id=wp_insert_post(array('post_type'=>'csa_item','post_status'=>'draft','post_title'=>'ZZA Draft Item')); update_post_meta(\$id,'_csa_auction',$A); echo \$id;")
ok "a draft item isn't shown and can't be bid on" $([ "$(hasnt $T/pub.html 'ZZA Draft Item')" = 1 ] && [ "$(bid b1 $I5 20)" = bad ] && echo 1 || echo 0)
ev "update_post_meta($A,'_csa_end',time()+60);" >/dev/null
ok "a bid in the last 2 minutes gives that item 2 more minutes" $([ "$(bid b1 $I1 70)" = placed ] && [ "$(ev "echo (int) (CSA_Data::item_end($I1) - time() > 100);")" = 1 ] && [ "$(ev "echo (int) (CSA_Data::item_end($I3) - time() <= 60);")" = 1 ] && echo 1 || echo 0)
ev "update_post_meta($A,'_csa_end',time()-10);" >/dev/null
ok "other items close on time" $([ "$(bid b3 $I3 40)" = closed ] && echo 1 || echo 0)
ok "the auction waits for the extended item before closing" $([ "$(ev "echo CSA_Data::auction_status($A).'|'.(int) CSA_Close::maybe_close($A);")" = "open|0" ] && echo 1 || echo 0)

echo "== Closing"
ev "update_post_meta($I1,'_csa_extended',time()-5);" >/dev/null
M0=$(mails)
ev 'do_action("csa_check_closing");' >/dev/null
O1=$(meta $I1 _csa_order); O2=$(meta $I2 _csa_order); O3=$(meta $I3 _csa_order)
W3=$(ev "echo explode('|', get_post_meta($I3,'_csa_high',true))[1];")
ok "the 5-minute check closes it once every item has ended" $([ "$(ev "echo CSA_Data::auction_status($A);")" = closed ] && echo 1 || echo 0)
ok "one pending order per winner, holding each won item at its price" $([ -n "$O1" ] && [ "$O1" != "$O2" ] && [ "$(ev "\$o=wc_get_order($O2); echo \$o->get_status().'|'.\$o->get_subtotal().'|'.\$o->get_customer_id().'|'.count(\$o->get_items());")" = "pending|300|$U2|1" ] && [ "$(ev "echo wc_get_order($O1)->get_customer_id();")" = "$U1" ] && echo 1 || echo 0)
ok "the order adds 8% sales tax and no fee (\$300 + \$24 = \$324)" $([ "$(ev "\$o=wc_get_order($O2); echo \$o->get_total_tax().'|'.count(\$o->get_fees()).'|'.\$o->get_total();")" = "24|0|324.00" ] && echo 1 || echo 0)
ok "a winner of two items gets one order for both" $( [ "$W3" = "$U1" ] && [ "$O3" = "$O1" ] && [ "$(ev "echo wc_get_order($O1)->get_subtotal();")" = "95" ] || { [ "$W3" = "$U3" ] && [ "$O3" != "$O1" ]; } && echo 1 || echo 0)
WINNERS=$([ "$O3" = "$O1" ] && echo 2 || echo 3)
ok "each winner gets one email with their items, total and pay link; the admin gets a summary" $([ "$(mails)" = $((M0+WINNERS+1)) ] && grep -q '"to":"zzabidder1@example.com","subject":"You won! Pay for your ZZA Winter Auction items"' wp-content/mail.log && grep 'You won!' wp-content/mail.log | grep 'zzabidder1' | grep -q 'order-pay' && grep -q 'ZZA Winter Auction has closed' wp-content/mail.log && grep -q 'Pick up at Leather Invasion' wp-content/mail.log && echo 1 || echo 0)
ok "the winner email: tax, total, pay-by date, card costs included, who collects, not deductible, terms" $(grep 'You won!' wp-content/mail.log | grep zzabidder2 | grep 'Sales tax: \$24' | grep 'Total: \$324' | grep 'Please pay online by ' | grep 'less card processing costs) go to' | grep 'There are no other fees.' | grep 'collected for COL&B by ZZA Studios' | grep 'nothing is tax-deductible' | grep -q 'csa-terms' && echo 1 || echo 0)
grep 'You won!' wp-content/mail.log | grep zzabidder2 | php -r '$j=json_decode(trim(fgets(STDIN)),true); echo $j["subject"],"\n",$j["message"],"\n";' > $T/winner-email.txt
M1=$(mails)
ev "CSA_Close::maybe_close($A); do_action('csa_check_closing');" >/dev/null
ok "closing again does nothing (no second orders or emails)" $([ "$(mails)" = $M1 ] && [ "$(meta $I1 _csa_order)" = "$O1" ] && echo 1 || echo 0)
curl -s -o $T/pub.html "$H/?page_id=$PAGE"; get b1 p1 "$H/?page_id=$PAGE"
ok "the page shows the winning amounts, still no names" $([ "$(has $T/pub.html 'Won for <strong>\$70</strong>')$(hasnt $T/pub.html zzabidder)$(hasnt $T/pub.html 'name="csa_amount"')" = 111 ] && echo 1 || echo 0)
ok "a winner sees that they won" $(has $T/p1.html 'You won this item.')
PAY=$(ev "echo wc_get_order($O1)->get_checkout_payment_url();")
get b1 pay "$PAY"
ok "the winner can open the pay page for their order" $([ "$(has $T/pay.html 'Silent auction: ZZA Leather Harness')" = 1 ] && echo 1 || echo 0)
get b2 pay2 "$PAY"
ok "someone else can't pay (or see) that order" $(hasnt $T/pay2.html 'Silent auction: ZZA Leather Harness')
PAY2=$(ev "echo wc_get_order($O2)->get_checkout_payment_url();")
get b2 pay2b "$PAY2"
ok "the pay page adds no fee" $([ "$(has $T/pay2b.html 'Silent auction: ZZA Weekend Getaway')$(hasnt $T/pay2b.html 'csa_cover')$(hasnt $T/pay2b.html 'processing fee')" = 111 ] && echo 1 || echo 0)
curl -s -b $T/admin.jar -o $T/res.html "$H/wp-admin/post.php?post=$A&action=edit"
ok "wp-admin shows each item's winner, email and order" $([ "$(has $T/res.html 'zzabidder1@example.com')$(has $T/res.html 'Not paid yet')$(has $T/res.html 'Closed. Winners have been emailed')" = 111 ] && echo 1 || echo 0)
curl -s -b $T/admin.jar -o $T/bids.html "$H/wp-admin/post.php?post=$I1&action=edit"
ok "an item's private bid history lists every bid" $([ "$(has $T/bids.html '\$70')$(has $T/bids.html '\$55')$(has $T/bids.html '\$50')" = 111 ] && echo 1 || echo 0)

echo "== Paying"
BEFORE=$(meta $FR _cec_fr_raised)
ev "wc_get_order($O2)->update_status('processing');" >/dev/null
ok "a paid order adds the winning bid less 3% card costs (\$291 of \$300; no tax) to the fundraiser as a silent-auction donation" $([ "$(meta $FR _cec_fr_raised)" = "$(php -r "echo $BEFORE+291;")" ] && [ "$(ev "\$e=CEC_Fundraisers::entries($FR); \$l=end(\$e); echo \$l['method'].'|'.\$l['amount'];")" = "auction|291" ] && echo 1 || echo 0)
ev "wc_get_order($O2)->update_status('completed');" >/dev/null
ok "completing it later doesn't count it twice" $([ "$(meta $FR _cec_fr_raised)" = "$(php -r "echo $BEFORE+291;")" ] && echo 1 || echo 0)
ok "its items are marked paid" $([ "$(meta $I2 _csa_paid)" = 1 ] && [ "$(meta $I1 _csa_paid)" = "" ] && echo 1 || echo 0)
ev "update_post_meta($FR,'_cec_fr_end',''); CEC_Admin_Settings::maybe_create_pages();" >/dev/null
FPAGE=$(ev "echo url_to_postid(CEC_Admin_Settings::get('fundraising_page_url'));")
curl -s -o $T/fund.html "$H/?page_id=$FPAGE"
ok "the Fundraising page shows the new total and no auction details" $([ "$(has $T/fund.html '\$391</strong> raised')$(hasnt $T/fund.html 'ZZA Winter Auction')" = 11 ] && echo 1 || echo 0)

echo "== Fixed-price sale"
cp wp-content/mail.log $T/mail-before-sale.log
SA=$(newpost csa_auction)
AUCTION_TERMS=$(ev 'echo CSA_Data::default_terms();')
code=$(save admin $SA csa_auction --data-urlencode "post_title=ZZA Holiday Sale" --data-urlencode "content=Great things at fixed prices." --data-urlencode "csa_format=sale" --data-urlencode "csa_start=$START" --data-urlencode "csa_end=$END" \
	--data-urlencode "csa_fundraiser=$FR" --data-urlencode "csa_tax=1" --data-urlencode "csa_costs_pct=3" --data-urlencode "csa_pay_days=3" --data-urlencode "csa_tax_notice=$(ev 'echo CSA_Data::default_tax_notice();')" --data-urlencode "csa_terms=$AUCTION_TERMS")
ok "saving a sale keeps the format, and standard auction terms switch to the sale terms" $([ "$code" = 302 ] && [ "$(meta $SA _csa_format)" = sale ] && [ "$(ev "echo get_post_meta($SA,'_csa_terms',true) === CSA_Data::default_terms(true) ? 1 : 0;")" = 1 ] && echo 1 || echo 0)
mk(){ ev "\$id=wp_insert_post(array('post_type'=>'csa_item','post_status'=>'publish','post_title'=>'$1','menu_order'=>$3)); update_post_meta(\$id,'_csa_auction',$SA); update_post_meta(\$id,'_csa_buy_now',$2); add_post_meta(\$id,'_csa_high','',true); echo \$id;"; }
IS1=$(mk "ZZA Sale Mug" 4000 1); IS2=$(mk "ZZA Sale Print" 2500 2); IS3=$(mk "ZZA Sale Mystery" 0 3)
ev "wp_update_post(array('ID'=>$A,'post_status'=>'draft'));" >/dev/null
curl -s -o $T/sale.html "$H/?page_id=$PAGE"; get b1 sale1 "$H/?page_id=$PAGE"
ok "the sale page shows prices, when it ends, sale terms and no bidding" $([ "$(has $T/sale.html 'The sale ends')$(has $T/sale.html 'Price <strong>\$40</strong>')$(has $T/sale.html 'Price coming soon')$(has $T/sale.html 'Sale terms')$(has $T/sale.html 'Sign in to buy')$(hasnt $T/sale.html 'Top bid')$(hasnt $T/sale.html 'name="csa_amount"')$(has $T/sale.html 'not a 501(c)(3) charity')" = 11111111 ] && echo 1 || echo 0)
ok "a signed-in member sees Buy buttons, none for an item without a price" $([ "$(has $T/sale1.html 'Buy for \$40')$(has $T/sale1.html 'Buy for \$25')$(grep -o 'name="csa_buy_now"' $T/sale1.html | wc -l)" = 112 ] && echo 1 || echo 0)
# buy <user> <item>: posts the Buy form, prints where it leads.
buy(){ get $1 formpage "$H/?page_id=$PAGE"; local nonce=$(php -r 'preg_match("~name=\"csa_nonce\" value=\"([^\"]+)\"~",file_get_contents($argv[1]),$m); echo $m[1]??"";' $T/formpage.html)
	curl -s -b $T/$1.jar -c $T/$1.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" --data-urlencode "action=csa_bid" --data-urlencode "csa_item=$2" \
		--data-urlencode "csa_back=$H/?page_id=$PAGE" --data-urlencode "csa_nonce=$nonce" --data-urlencode "csa_buy_now=1"; }
M0=$(mails)
R=$(buy b1 $IS1)
OS1=$(meta $IS1 _csa_order)
ok "buying goes straight to the pay page for a new order" $(echo "$R" | grep -q "order-pay=$OS1" && [ -n "$OS1" ] && echo 1 || echo 0)
ok "the order: the item at its price plus 8% tax, no fee (\$40 + \$3.20)" $([ "$(ev "\$o=wc_get_order($OS1); echo \$o->get_status().'|'.\$o->get_customer_id().'|'.\$o->get_subtotal().'|'.\$o->get_total_tax().'|'.count(\$o->get_fees()).'|'.\$o->get_total();")" = "pending|$U1|40|3.2|0|43.20" ] && echo 1 || echo 0)
ok "the buyer gets one email with the total and the pay link" $([ "$(mails)" = $((M0+1)) ] && grep '"subject":"Thank you! Pay for ZZA Sale Mug"' wp-content/mail.log | grep zzabidder1 | grep 'Total: \$43.20' | grep 'order-pay' | grep -q 'Sale terms' && echo 1 || echo 0)
grep 'Thank you! Pay for' wp-content/mail.log | head -1 | php -r '$j=json_decode(trim(fgets(STDIN)),true); echo $j["subject"],"\n",$j["message"],"\n";' > $T/purchase-email.txt
R2=$(buy b2 $IS1)
ok "someone else can't buy it now" $(echo "$R2" | grep -q 'csa_msg=no_buy_now' && echo 1 || echo 0)
get b1 sale1 "$H/?page_id=$PAGE"; curl -s -o $T/sale.html "$H/?page_id=$PAGE"
ok "the buyer sees they bought it with a Pay now link; everyone sees Sold, no names" $([ "$(has $T/sale1.html 'You bought this item.')$(has $T/sale1.html 'Pay now')$(has $T/sale.html '<strong>Sold</strong>')$(hasnt $T/sale.html zzabidder)" = 1111 ] && echo 1 || echo 0)
get b2 n2 "$H/?page_id=$PAGE"; get b3 n3 "$H/?page_id=$PAGE"
N2=$(php -r 'preg_match("~name=\"csa_nonce\" value=\"([^\"]+)\"~",file_get_contents($argv[1]),$m); echo $m[1]??"";' $T/n2.html); N3=$(php -r 'preg_match("~name=\"csa_nonce\" value=\"([^\"]+)\"~",file_get_contents($argv[1]),$m); echo $m[1]??"";' $T/n3.html)
for u in b2 b3; do N=$([ $u = b2 ] && echo $N2 || echo $N3); curl -s -b $T/$u.jar -o /dev/null "$H/wp-admin/admin-post.php" --data-urlencode "action=csa_bid" --data-urlencode "csa_item=$IS2" --data-urlencode "csa_back=$H/?page_id=$PAGE" --data-urlencode "csa_nonce=$N" --data-urlencode "csa_buy_now=1" & done; wait
ok "two people buying the same item at the same moment: exactly one gets it and one order" $([ "$(ev "echo CSA_Bids::count($IS2);")" = 1 ] && [ "$(grep -c 'Thank you! Pay for ZZA Sale Print' wp-content/mail.log)" = 1 ] && [ -n "$(meta $IS2 _csa_order)" ] && echo 1 || echo 0)
ev "wc_get_order($OS1)->update_status('cancelled');" >/dev/null
get b3 sale3 "$H/?page_id=$PAGE"
ok "cancelling an unpaid order puts the item back on sale" $([ "$(meta $IS1 _csa_order)" = 0 ] && [ "$(has $T/sale3.html 'Buy for \$40')" = 1 ] && echo 1 || echo 0)
BEFORE=$(meta $FR _cec_fr_raised)
buy b3 $IS1 >/dev/null; OS1B=$(meta $IS1 _csa_order)
ev "wc_get_order($OS1B)->update_status('processing');" >/dev/null
ok "a paid sale adds the price less 3% card costs (\$38.80 of \$40; no tax) to the fundraiser" $([ "$(meta $FR _cec_fr_raised)" = "$(php -r "echo $BEFORE+38.8;")" ] && [ "$(ev "\$e=CEC_Fundraisers::entries($FR); \$l=end(\$e); echo \$l['method'];")" = auction ] && [ "$(meta $IS1 _csa_paid)" = 1 ] && echo 1 || echo 0)
ev "wc_get_order($OS1B)->update_status('cancelled');" >/dev/null
ok "cancelling a paid order doesn't put the item back on sale" $([ "$(meta $IS1 _csa_order)" = "$OS1B" ] && echo 1 || echo 0)
ev "wc_get_order($OS1B)->update_status('processing');" >/dev/null
ev "update_post_meta($IS3,'_csa_buy_now',1000);" >/dev/null
get b2 formpage "$H/?page_id=$PAGE"; N2=$(php -r 'preg_match("~name=\"csa_nonce\" value=\"([^\"]+)\"~",file_get_contents($argv[1]),$m); echo $m[1]??"";' $T/formpage.html)
ev "update_post_meta($SA,'_csa_end',time()-5);" >/dev/null
ok "a closed sale refuses purchases (form opened before it ended)" $(curl -s -b $T/b2.jar -o /dev/null -w '%{redirect_url}' "$H/wp-admin/admin-post.php" --data-urlencode "action=csa_bid" --data-urlencode "csa_item=$IS3" --data-urlencode "csa_back=$H/?page_id=$PAGE" --data-urlencode "csa_nonce=$N2" --data-urlencode "csa_buy_now=1" | grep -q 'csa_msg=closed' && echo 1 || echo 0)
M1=$(mails)
ev 'do_action("csa_check_closing");' >/dev/null
ok "when the sale ends: closed once, no extra orders, only a summary to the admin (\$65 in sales)" $([ "$(ev "echo CSA_Data::auction_status($SA);")" = closed ] && [ "$(mails)" = $((M1+1)) ] && grep -q 'ZZA Holiday Sale has ended: \$65 in sales' wp-content/mail.log && [ "$(grep -c 'You won!' wp-content/mail.log)" = "$(grep -c 'You won!' $T/mail-before-sale.log)" ] && echo 1 || echo 0)
curl -s -o $T/sale.html "$H/?page_id=$PAGE"
ok "the ended sale shows what sold and what didn't" $([ "$(has $T/sale.html 'The sale ended')$(has $T/sale.html '<strong>Sold</strong>')$(hasnt $T/sale.html 'Buy for')" = 111 ] && echo 1 || echo 0)
ev "wp_update_post(array('ID'=>$A,'post_status'=>'publish'));" >/dev/null

echo "== Who may edit"
curl -s -b $T/b1.jar -o $T/deny.html -w '%{http_code}' "$H/wp-admin/post.php?post=$A&action=edit" > $T/code.txt
ok "a member can't open an auction in wp-admin" $([ "$(cat $T/code.txt)" != 200 ] || [ "$(hasnt $T/deny.html 'csa_start')" = 1 ] && echo 1 || echo 0)

echo "== PHP warnings"
grep -v -e '_load_textdomain_just_in_time' -e 'woocommerce' wp-content/debug.log > $T/debug.txt
ok "no PHP warnings or notices from our plugins" $([ ! -s $T/debug.txt ] && echo 1 || { head -5 $T/debug.txt; echo 0; })

pkill -f "php -S 127.0.0.1:8899" 2>/dev/null
echo "auction: $PASS passed, $FAIL failed"
[ $FAIL = 0 ]
