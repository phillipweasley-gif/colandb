# Changelog: COL&B Silent Auction

## 0.1.0

**First release: silent auctions on WooCommerce** (owner, 2026-11-11: "I need to build out a fundraising section that allows for a silent auction using the wordpress/WooCommerce integration". Chose our own plugin with WooCommerce for checkout; bidders see the top bid; anyone with a registered account may bid; payments through the Weasley Studios Square account, which must capture sales tax and processing fees).

- Auctions and items in wp-admin (Silent Auction menu), a never-cached Auction page (`[csa_auction]`, created as a draft), bidding for signed-in accounts with minimum raises, Buy It Now, last-minute extension and safe handling of simultaneous bids.
- Bidder names are never public. The top bid and bid count are.
- At close, one WooCommerce order per winner: winning bids plus sales tax, no other fees. Paid orders add the winning bids, less the auction's card-costs percentage, to the linked fundraiser (needs Community Events Calendar 1.52.0).
- **Compliance changes after research** (owner, 2026-11-11: "make sure we're following all local, state, and federal rules"; report in the project files, "Silent auction compliance in Ohio"):
  - No card fee is added on top. A required surcharge breaks Visa/Mastercard rules (none on debit cards, 3% cap) and Square supports none online. The owner chose to have bids include card costs: the page says "Bids include card processing costs (about 3%), which are paid from the winning bid. The rest goes to: …", and that percentage is taken off before the fundraiser total.
  - Bids, starting bids, minimum raises and Buy It Now are whole dollars (owner: "The bid should be in round numbers").
  - Per auction: who collects the payments (shown: "Payments are collected for COL&B by …"), days to pay (default 7; the winner email gives the date), a not-tax-deductible notice (default: "COL&B is not a registered 501(c)(3) nonprofit yet, so winning bids and donated items are not tax-deductible.") shown on the page and in the winner email, and written auction terms (default 8 points) shown on the page, linked from each bid form and the winner email.
  - "Value" is now "Est. value $… (from the donor)".
  - The research also found an open question the plugin can't settle: Ohio's auctioneer licensing law (R.C. 4707) covers online silent auctions and its charity exemption needs a tax-exempt sponsor. The owner has been asked to check with the Ohio Department of Agriculture before launch.
- Four plain-text emails: outbid, bought with Buy It Now, winner, and a summary to the site admin. **The owner must approve their exact wording before this is promoted to live** (`/mnt/project-files/silent-auction/auction-emails.txt` in the project, and the pull request).
- WooCommerce's Order attribution tracking is kept off (no analytics on the site).

**Verified** in the local WordPress kit with WooCommerce 11.2.2 on SQLite: new `tests/auction-e2e.sh` 67/67. It covers wp-admin entry over real HTTP, the page (prices, pickup, payment terms, who collects, not-tax-deductible notice, auction terms, no-store header, no tracking scripts), the bid rules and messages, the outbid email, signed-out and expired-form bids, two equal bids at the same moment (exactly one wins), Buy It Now, not-yet-open auctions, draft items, the last-minute extension, whole-dollar bids, closing once (orders with 8% tax and no fee, emails), the pay page adding no fee, the winner's pay page (and nobody else's), paying (fundraiser total up once, by the winning bid less 3%), member access to wp-admin and no PHP warnings. Also `tests/fundraisers-e2e.sh` 21/21 and `tests/form-cache-e2e.sh` 36/36 with this plugin and WooCommerce active. Screenshots of the page (desktop and phone) were taken in the kit.

**Not tested:** a real Square payment (the kit has no Square account; the paid step is simulated by setting the order to Processing, which is what a successful Square payment does), and how the page looks inside the live site's Elementor theme.
