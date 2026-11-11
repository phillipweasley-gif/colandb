# COL&B Silent Auction

Silent auctions for COL&B fundraising, built on WooCommerce (owner request, 2026-11-11).

## How it works

- **wp-admin → Silent Auction → Auctions.** An auction has a name, description, photo, the times bidding opens and closes (site time), the fundraiser its money goes to, sales tax on/off, an optional processing fee (percent + fixed), "how winners collect items", and last-minute protection (a bid in the last 2 minutes gives that item 2 more minutes).
- **Items** (Silent Auction → Items, or "Add an item to this auction" on the auction): title, description, photo (Featured image), donated by, value, starting bid, minimum raise, optional Buy It Now price. Only published items are shown.
- **The Auction page** (`[csa_auction]`, created as a draft "Silent Auction" page on activation; publish it when ready). It shows open and upcoming auctions, then results of auctions closed in the last 30 days. `[csa_auction id="123"]` shows one. Never cached.
- **Bidding:** anyone signed in to a colandb.com account. Each bid must be at least the top bid plus the minimum raise (or the starting bid). The top bid and number of bids are public; **bidders' names never are** (only the site team sees them, on the item and the auction in wp-admin). The top bidder can't bid against themselves. Bids above $50,000 are refused as likely typos. A bid at or above the Buy It Now price buys the item.
- **Two bids at once:** the top bid is changed with a compare-and-swap on one meta row (`_csa_high` = "cents|user"), so only one of two simultaneous bids wins; the other is checked again against the new top bid.
- **Emails** (plain text through `wp_mail()`, so FluentSMTP's "COL&B" sender): outbid, item bought with Buy It Now, winner (once per winner, all their items, tax, fee, total and a Pay now link), and a summary to the site admin email.
- **Closing:** once every item's bidding has ended, the auction is closed once (by the 5-minute WP-Cron check, the Auction page, or opening it in wp-admin): one pending WooCommerce order per winner (`created_via = csa`, so WooCommerce never cancels it as abandoned), with sales tax (WooCommerce tax rates) and a "Card processing fee" line if set.
- **Paying:** the winner pays on WooCommerce's "pay for order" page with whatever payment method is set up (Square on colandb.com). When an order becomes Processing or Completed (including marked Completed by hand for cash), its items are marked paid and the **winning bids only** (not tax or fee) are added to the linked fundraiser through `cec_fundraiser_add_donation()` (Community Events Calendar 1.52.0+), once.
- **No tracking:** WooCommerce's Order attribution feature (sbjs_* cookies on every page) is kept off while this plugin is active, because the Privacy Policy promises no analytics.

## Setting up on the live site (owner)

1. Back up the site. Install and activate **WooCommerce** (Plugins → Add New). Skip its setup wizard's optional extras and marketing.
2. WooCommerce → Settings → **Site visibility: Live** (a new WooCommerce shows "coming soon" instead of the pay page until then).
3. Install **WooCommerce Square** and connect the Weasley Studios Square account (WooCommerce → Settings → Payments).
4. WooCommerce → Settings → General: shop address (where items are collected) and US dollar. Tick **Enable tax rates**, then Settings → Tax → Standard rates: the sales tax rate(s) your accountant gives you, with "Calculate tax based on: Shop base address".
5. Update the COL&B plugins (Community Events Calendar 1.52.0, then this one through the updater).
6. Silent Auction → Auctions → Add New; add items; publish the Silent Auction page and add it to the menu.

## Files

- `includes/class-csa-data.php` post types, meta, money (cents), status
- `includes/class-csa-bids.php` the bid rules, compare-and-swap, form handler
- `includes/class-csa-close.php` closing, orders, payments → fundraiser
- `includes/class-csa-emails.php` the four emails
- `includes/class-csa-admin.php` wp-admin boxes, lists, results
- `includes/class-csa-public.php` the Auction page
- `includes/class-csa-install.php` bids table `{prefix}csa_bids`, cron, draft page

Tests: `tests/auction-e2e.sh` (see `tests/README.md`).
