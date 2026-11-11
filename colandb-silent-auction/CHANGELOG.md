# Changelog: COL&B Silent Auction

## 0.1.0

**First release: silent auctions on WooCommerce** (owner, 2026-11-11: "I need to build out a fundraising section that allows for a silent auction using the wordpress/WooCommerce integration". Chose our own plugin with WooCommerce for checkout; bidders see the top bid; anyone with a registered account may bid; payments through the Weasley Studios Square account, which must capture sales tax and processing fees).

- Auctions and items in wp-admin (Silent Auction menu), a never-cached Auction page (`[csa_auction]`, created as a draft), bidding for signed-in accounts with minimum raises, Buy It Now, last-minute extension and safe handling of simultaneous bids.
- Bidder names are never public. The top bid and bid count are.
- At close, one WooCommerce order per winner with sales tax and an optional card processing fee (both disclosed on the Auction page before bidding). Paid orders add the winning bids to the linked fundraiser (needs Community Events Calendar 1.52.0).
- Four plain-text emails: outbid, bought with Buy It Now, winner, and a summary to the site admin. **The owner must approve their exact wording before this is promoted to live** (`/mnt/project-files/silent-auction/auction-emails.txt` in the project, and the pull request).
- WooCommerce's Order attribution tracking is kept off (no analytics on the site).

**Verified** in the local WordPress kit with WooCommerce 11.2.2 on SQLite: new `tests/auction-e2e.sh` 63/63. It covers wp-admin entry over real HTTP, the page (prices, pickup, tax and fee notice, no-store header, no tracking scripts), the bid rules and messages, the outbid email, signed-out and expired-form bids, two equal bids at the same moment (exactly one wins), Buy It Now, not-yet-open auctions, draft items, the last-minute extension, closing once (orders, tax 7.5%, fee 2.9% + $0.30, emails), the winner's pay page (and nobody else's), paying (fundraiser total up once, winning bid only), member access to wp-admin and no PHP warnings. Also `tests/fundraisers-e2e.sh` 21/21 and `tests/form-cache-e2e.sh` 36/36 with this plugin and WooCommerce active. Screenshots of the page (desktop and phone) were taken in the kit.

**Not tested:** a real Square payment (the kit has no Square account; the paid step is simulated by setting the order to Processing, which is what a successful Square payment does), and how the page looks inside the live site's Elementor theme.
