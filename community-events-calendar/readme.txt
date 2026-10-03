=== Community Events Calendar ===
A custom plugin for member/admin-managed community event listings, built to work
standalone or be dropped into Elementor as widgets.

== Install ==
1. Zip the `community-events-calendar` folder (already done if you received a .zip).
2. WordPress Admin > Plugins > Add New > Upload Plugin > choose the zip > Install > Activate.
3. A new "Events" menu appears in wp-admin.

== First-time setup ==
1. Events > Settings — set your brand colors (these theme the whole calendar via CSS
   variables), decide whether login is required to submit, and whether submissions
   need approval (leave "Auto-publish" OFF so admins review everything first).
2. Events > Partner Organizations — add each partner org. Each term has a "website"
   field, which is what makes the org's name clickable everywhere it appears.
3. Events > Venues — add your common locations. Each venue stores an address and/or
   a Google Maps link, generated automatically from the address if you don't paste
   one. Editors can still type a one-off custom address per event instead of picking
   a saved venue.
4. Events > Event Types — the "Social / Educational / Themed / Advocacy" style
   categories are seeded on activation; add/rename/remove as needed.

== Approval workflow ==
Events submitted from the front-end form land as "Pending" posts (WordPress's native
review status) — they are not visible to the public until an admin/editor opens the
event in wp-admin and clicks Publish. The Events list screen highlights how many are
awaiting approval. wp-admin users with the Editor/Administrator role can publish;
regular member accounts cannot.

== Shortcodes ==
[cec_calendar]                     Month-grid calendar — multiday events as a spanning bar, single-day events as a pill, click any date for its full event list
[cec_events view="grid"]           Filterable grid (view="list" for a list layout)
[cec_upcoming count="8"]           Horizontally-scrolling "upcoming events" widget
[cec_submit_event]                 Front-end submission form (requires login by default)
[cec_admin_dashboard]              Front-end approve/edit/reject dashboard for Calendar Managers
[cec_my_events]                    Lets a submitter view/edit/postpone/cancel/withdraw their own events
[cec_login]                        Styled login form
[cec_register]                     Styled account registration form (name, email, password)
[cec_manage_submission]            Guest (no-account) email-link editing — see below
[cec_subscribe]                    Email subscription form — all events and/or specific orgs
[cec_volunteer_form]               Volunteer inquiry form — see below

Matching Elementor widgets are registered under a "Community Calendar" category, so
these can be dragged onto any page instead of using shortcodes directly.

== Accounts without touching wp-login.php ==
[cec_login] and [cec_register] are styled equivalents of WordPress's default login/
registration screens. The pages for these (plus Submit an Event, Calendar Admin,
Manage My Submission, and Subscribe to Events) are created automatically the first
time the plugin runs, with their URLs already filled in under Events > Settings —
nothing to configure by hand. A new member is sent straight to the Submit Event
page right after registering or logging in, and can never end up stuck in
wp-admin (only real Administrators can access it; everyone else is redirected
back to the front end automatically, however they got there).
Registration still honors Settings > General > Membership ("Anyone can register") —
turn that on for the button to actually create accounts. New accounts get the site's
configured default new-user role (normally Subscriber), which is already enough to
submit and manage events.

If you updated to 1.14.0 specifically, a bug in that version's first release could
create several duplicate copies of these pages (fixed in 1.14.1 — see CHANGELOG.md).
If Events > Settings shows a "Duplicate Pages" section, click "Clean Up Duplicate
Pages" — it moves every extra copy to the Trash (not permanent, restorable) and
keeps the one your settings already point to. Outside of that one-click tool, this
plugin never deletes pages on its own — it only ever creates one when a setting is
still blank.

== Guest submission (no account) with emailed edit links ==
Turn on Events > Settings > "Guest submissions" to let anyone submit without creating
an account — they just add their email to the form. To edit later, they visit the
[cec_manage_submission] page, enter that email, and get a link to manage each event
(edit details, postpone/cancel, or withdraw) with no login. This REQUIRES the "Manage
Submission Page URL" setting to be filled in — without it, guest submissions still
work but no edit-link email can be sent, so treat that setting as required once guest
submissions are on. Notes on this system:
- The link is a bearer token good for 7 days; only its hash is stored in the database,
  and requesting a new link is free/instant from the same page.
- Editing details sends the event back to Pending, same as the logged-in flow;
  postponing/cancelling does not.
- It only manages standalone events and recurring series roots (not one specific date
  within a series) — that finer control still needs a login via My Events "Manage
  Dates". A guest can still edit a whole series' details, which regenerates every
  upcoming date.
- This depends entirely on outgoing email actually arriving. Test it before relying on
  it — many hosts' default PHP mail() gets marked as spam or silently dropped. If
  emails aren't showing up, install an SMTP plugin (e.g. WP Mail SMTP) configured with
  a real mail provider.

== Recurring events ==
Any event (via wp-admin, the submission form, the manager dashboard, or a submitter's
own My Events edit form) can repeat Weekly, Monthly (same date), or Monthly (specific
weekday) until a chosen end date, capped at ~2 years/104 dates as a safety limit.
"Monthly (specific weekday)" lets you check multiple "which week" boxes (1st/2nd/3rd/
4th/Last) and multiple "which day" boxes (Sun-Sat) — e.g. check "2nd" + "4th" and
"Wednesday" to repeat on the 2nd AND 4th Wednesday of every month as a single series,
instead of needing two separate ones. Leaving both unchecked falls back to whatever
the start date itself implies (e.g. starting on a 2nd Tuesday repeats every 2nd
Tuesday) — this is also what keeps series created before this option existed working
unchanged. Each date is generated as its own real event post ("occurrence") so every
existing calendar/list/RSVP feature works on it unmodified. Approving, rejecting, or
deleting the series root cascades to every occurrence automatically — nobody has to
approve 52 weekly dates one at a time. Editing the series root's schedule (rule, end
date, or which-week/which-day selections) regenerates all *upcoming* dates (past
dates are left alone) and clears any one-off postpone/cancel changes made to those
upcoming dates — editing just the event's content (description, photo, etc.) does
NOT regenerate dates or clear those changes. Make per-date changes via "Manage Dates"
(in the dashboard or My Events) *after* the series schedule is finalized, not before.

== Postponed / cancelled events ==
Every event (a standalone one, a series root, or a single occurrence) has an
"Event Status": Scheduled, Postponed, or Cancelled, with an optional note (new date,
reason). This shows as a badge everywhere the event appears and a banner on its single
page, and pauses RSVP/registration while active — the event stays visible (not
unpublished) so people who already saw it don't just find it vanished. Setting this
does NOT require admin re-approval — it's meant to be fast for urgent updates. Both
Calendar Managers and the event's own submitter can set it (via the dashboard or My
Events); only Calendar Managers/Administrators can approve or reject an event.

== Submitters editing their own events ==
[cec_my_events] lists everything a logged-in user has submitted (grouped by series
for recurring events) with Edit, Postpone/Cancel, and Withdraw actions. Editing any
detail sends the event back to Pending for re-approval — postponing/cancelling does
not. This uses the same underlying capability check as the dashboard, just scoped to
"is this your own event," so it works for a plain Subscriber account with no special
role needed.

== Delegating approval without giving wp-admin access ==
A "Calendar Manager" role is created automatically (visible under Users > Add New >
Role). Anyone with that role can log in and use a page containing
[cec_admin_dashboard] to see the pending queue, approve/reject/edit events, and
manage Partner Organizations and Venues — all without ever opening wp-admin. If they
try to visit wp-admin directly, they're redirected back to the dashboard page
automatically. Set the dashboard page's URL under Events > Settings so that redirect
knows where to send them. Administrators keep full wp-admin access as before; this
is purely additive. See Events > Settings for the exact setup steps.

The dashboard's "Import Events" tab lets an Administrator or Calendar Manager upload
a .ics calendar file (exported from Google Calendar, Outlook, Apple Calendar,
Eventbrite, or similar) to create events here automatically, instead of re-typing
each one by hand. Imported events publish immediately and are fully editable
afterward like any other event. A couple of real limits to know: an event's location
comes in as plain text (not matched to an existing Venue — link it manually
afterward if you want that), a repeating event in the source file only has its first
date imported (recurrence isn't auto-detected — set it here manually if it should
repeat). By default uploading the same file twice creates duplicates rather than
updating what was already imported — check "Update previously-imported events
instead of creating duplicates" on the import form to re-sync a subscribed calendar
instead: it matches each event against one already imported before (using the
file's own event ID) and refreshes its date/time, location, organizer, and More Info
link, while leaving anything you've set manually since (Cost, a linked Venue,
postponed/cancelled status) untouched. If the source file sets a website link for the
event, it's picked up automatically as that event's More Info link (see below).
Calendar files don't carry pricing information, so every imported event is marked
Paid with a "pricing not provided" note rather than guessed as Free — update the
Cost field on each one afterward with the real price (or mark it Free yourself).

Every event also has an optional **More Info Link** field — a plain "click here for
details" link shown on the event page no matter what else is set, separate from the
Host Organization Website (which only shows once a Host Organization Name is also
filled in) and the external RSVP link (shown only when RSVP mode is set to external
registration). Useful for linking to a flyer, a news article, or a full listing
elsewhere.

== RSVP ==
Per event you choose: No RSVP, "Collect RSVPs on this site" (a lightweight built-in
form with optional capacity limits, stored in its own `wp_cec_rsvps` table), or
"Link to external registration" (Eventbrite, Google Form, etc.). When an event with
a capacity limit fills up, visitors are offered a waitlist instead (`wp_cec_rsvp_waitlist`)
— there's no automatic "a spot opened up" email yet, so check the list manually.
Administrators can see and CSV-export every RSVP and waitlist signup, across all
events, under Events > RSVP Attendees. Everyone who RSVP'd gets an automatic
reminder email about 24 hours before the event starts — no setup needed.

Every single event page also shows "Add to Google Calendar" and "Download .ics"
links (the .ics also works for Apple Calendar/Outlook), and the whole calendar is
subscribable as one .ics feed via the "Subscribe (iCal)" link next to "Subscribe
(RSS)". Event pages also emit schema.org/JSON-LD structured data, which is what
lets Google show event details directly in search results — no setup needed.

== Subscribing to the calendar (RSS and email) ==
RSS needs no setup — WordPress already generates a feed for any public archive:
- Whole calendar: [your site]/events/feed/
- One organization: its archive page + /feed/ (e.g. /partner/some-org/feed/) — every
  Partner Organization automatically has one, including ones added by a submitter via
  the "Don't see your organization? Add it" field on the submission form (see below).
Both feeds are ordered soonest-first, upcoming-only, and each item's description
includes the date/venue/free-or-paid line instead of just the raw post content.
"Subscribe (RSS)" links are shown automatically on [cec_calendar], [cec_events], and
every organization's archive page.

For email, set up a page with [cec_subscribe] and paste its URL into Events > Settings
> Subscriptions. Visitors pick "All community events" and/or specific organizations
(the checkbox list is pulled live from Partner Organizations, so it's always current),
confirm via an emailed link (double opt-in — nothing sends until they click confirm),
and every notification email includes a one-click unsubscribe link. Notifications go
out when: a new event's series root is approved/published (not once per date for a
recurring series — one email per new series, not 52), and when a live event's status
changes to/from Postponed or Cancelled. Routine edits to an event's details do NOT
trigger a notification — only those two things do. This depends on outgoing email
working — see the guest-submission note above about testing it / using an SMTP plugin.

== Sharing an event ==
Single event pages have a "Share" button. On phones/tablets it opens the device's
own native share sheet — this is what lists WhatsApp, Telegram, Messenger,
Snapchat, Messages, Mail, or whatever else is installed, automatically, with no
setup on your end. On desktop it opens a small menu of direct-share icons instead
(WhatsApp, Telegram, Text/SMS, Facebook, X, Email, Copy Link) — desktop's own
native share integrations proved unreliable in testing (the target app opens but
sometimes doesn't actually receive anything), so desktop intentionally skips that
and goes straight to links that work every time. Event cards in the grid/list
views get a compact Share button (native share on phones/tablets, or copies the
link on desktop).

== Reusable organization and venue details ==
Under Events > Partner Organizations, each organization can carry saved defaults —
Code of Conduct, RSVP mode, RSVP link — so you set them once instead of retyping on
every event. Checking that organization while adding/editing an event (in wp-admin
or the Calendar Manager dashboard) fills in those fields automatically, but only
the ones still empty; it never overwrites something you've already typed.

Venues already worked this way for addresses (pick a saved venue, its address
fills in automatically). Now a typed custom address can be saved as a reusable
Venue the same way a submitter can add a new Partner Organization inline — look
for "Don't see your venue? Add it" next to the Custom Address field.

== Tracking partner organizations submitters add ==
Partner Organizations are a real, first-class list (Events > Partner Organizations),
not just free text — that's what makes them filterable, RSS-feedable, and
subscribable per-org. Submitters normally check off existing ones, but every
submission form (the main form, My Events, and guest email-link editing) also has a
"Don't see your organization? Add it" field: if filled, that organization is created
immediately (or reused if it already exists by that name) and tagged on the event.
The event itself still goes through normal approval, so a newly-named org shows up for
an admin to review before anything goes public, but the org record itself is live
right away for filtering/RSS/subscriptions.

== Email deliverability, GDPR, and moderation history ==
Events > Settings has a "Send Test Email to Me" button — use it to confirm outgoing
email actually works on this host before relying on guest edit links, subscription
confirmations, approval alerts, or volunteer notifications.

If a data-privacy request comes in (via Tools > Export/Erase Personal Data), this
plugin's RSVPs, RSVP waitlist, email subscribers, volunteer inquiries, and
guest-submitter emails are all reachable from that flow automatically — no manual
lookup needed.

Every approve/reject/delete/postpone/cancel action on an event is logged with who
did it and when, shown as "Recent Activity" in that event's Approval box in
wp-admin — useful once more than one Calendar Manager has approval power.

== Volunteer inquiries ==
[cec_volunteer_form] is separate from the events system — it's a simple, private
contact form for people interested in volunteering. Unlike events, submissions here
are NEVER made public; they're only reviewed under Events > Volunteer Inquiries
(Administrator-only, since entries include email/phone). Each submission emails your
configured "Notify Email" (Events > Settings > Volunteer Inquiries; defaults to the
site admin email if left blank). From that admin screen you can filter by status
(New / Contacted / Archived), mark an inquiry's status with one click, and export
everything to CSV. The "Interest Areas" checkboxes shown on the form come from a
comma-separated list you set in Events > Settings — edit that list anytime to match
what your org actually needs help with.

== Notes / things to verify on your site before launch ==
- Test the submission form end-to-end with a non-admin account.
- If your host has an object cache or page cache, make sure admin-ajax.php requests
  (calendar navigation, filters, RSVP) are not cached.
- The photo upload accepts any image type WordPress normally allows; add file-size
  guidance in the form copy if you want to steer submitters.
- Front-end submission currently requires being logged in (any role) — if you want
  a more granular "Member" role distinct from Subscriber, that's a small addition.
