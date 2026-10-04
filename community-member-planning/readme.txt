=== Community Member Planning ===
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.1.2

Private member area for the Community Events Calendar site.

== Description ==

Adds a signed-in member area. In this release (0.1.0):

* Members must verify their email address and confirm they are 18 or older before entering.
* An in-app notification inbox.
* An append-only audit log of account and access changes.

Profiles, personal calendars (My Calendars), connections and calendar sharing, and the member directory come in later releases.

Member information never appears on public pages, public feeds, the public REST API, the sitemap or site search.

== Requirements ==

* Community Events Calendar 1.26.0 or newer (for calendar features in later releases; the member area works without it, and wp-admin shows a notice if it's missing).
* Working outgoing email. Use the "Send Test Email to Me" button under Events → Settings → Email Deliverability first.

== Setup ==

1. Upload and activate the plugin. It creates its own database tables and changes no existing data.
2. Create a page (for example "Member Area") containing the shortcode `[cmp_member_area]`. With Elementor, use the Shortcode widget.
3. Go to Settings → Member Planning and:
   * choose that page as the Member area page (this is what keeps it out of caches, search engines and the sitemap);
   * replace the placeholder 18+ wording once it's approved;
   * add the member privacy notice link once it's published.
4. Link to the page from your menu. Visitors are asked to sign in using the Community Events Calendar's login page.

== What a member sees ==

1. Signed out: a prompt to sign in or create an account.
2. Signed in: "Confirm your email". The member sends themselves a link (valid once, for 7 days).
3. Verified: "Confirm you are 18 or older" (a checkbox with your wording).
4. Member home: their notifications.

If a member changes their email address, they confirm the new one before re-entering. They aren't asked the 18+ question again.

== Removing the plugin ==

Deactivating hides the member area and deletes nothing. Deleting the plugin also keeps all member data unless "Permanently delete all member data…" was ticked under Settings → Member Planning first.

== Changelog ==

See CHANGELOG.md.
