# Community Events Calendar — Technical Handoff Brief

**Prepared for:** incoming developer/maintainer
**Plugin version documented:** 1.25.0, plus 1.25.1 (timezone-export fix, see the `_cec_timezone` row in Appendix A1), 1.25.2 (display fixes found on staging, see CHANGELOG), 1.26.0 (integration API and `CEC_Month_Grid`, see §2a) and 1.26.1 (merge of 1.25.2 and 1.26.0)
**Codebase reviewed:** full source, read directly from the files in this repository
**This revision prepared:** October 2026 (supersedes the version of this document dated August 2026 / written against 1.6.2)

> **Read this note before anything else in this document.**
> This plugin was developed over many sessions by Claude (an AI coding assistant), working directly with the site owner, Phil Weasley (RA Marketing). From version 1.15.0 onward (the versions this revision covers in detail), **no live WordPress or PHP execution environment was available during development.** Every change was checked by: (a) counting matching braces/parentheses in the edited PHP files, (b) re-implementing the relevant logic in Python and running it against sample data, and (c) rendering the real HTML/CSS/JS in a browser against hand-built sample markup that imitates what WordPress would actually output. **No change from 1.15.0 through 1.25.0 has been run inside an actual WordPress installation.** Appendix B, at the end of this document, explains exactly what was and was not checked for each phase. Before you treat anything in this version range as production-ready, do a full manual pass on a real WordPress install: create a test event through every one of the five entry points listed in Appendix A, and confirm it looks right on the front end. This is not a formality — it is the one thing that has not yet been done.

---

## 1. What this plugin is

Community Events Calendar (text domain `cec`, prefix `CEC_`) is a custom WordPress plugin, built by RA Marketing, that turns WordPress into a member/admin-managed community events calendar. It is designed to run either standalone (via shortcodes) or dropped into Elementor page layouts (via matching widgets). There is no external API dependency, no build step, and no bundled third-party PHP libraries — it's plain WordPress-API PHP plus jQuery, meant to be installed as a normal plugin zip.

Core capabilities, as of 1.25.0:

- A custom `cec_event` post type with three taxonomies (`cec_event_type`, `cec_partner_org`, `cec_venue`) representing categories, partner organizations, and venues.
- Front-end and back-end event submission with an admin approval workflow (`pending` → `publish`, plus a newer `cec_in_review` status — see Appendix A, item 9).
- Recurring events implemented as real child posts ("occurrences"), not virtual/computed dates.
- A delegated "Calendar Manager" role that can approve/edit/reject events and manage partner orgs/venues from a front-end dashboard shortcode, without ever touching wp-admin.
- Self-service management for submitters (My Events) and for guests who submitted without an account (emailed bearer-token edit links).
- RSVP collection (internal, stored in a custom table, with its own wp-admin attendee list and CSV export) or linking out to external registration.
- RSS feeds (free, via WordPress's native feed support) and double-opt-in email subscriptions, both scoped to "all events" or a specific partner org.
- A separate, private volunteer-inquiry contact form, reviewed only by Administrators.
- Elementor widget wrappers around every shortcode.
- **New as of 1.22.0–1.25.0 (Phase 1 of a separate, larger project brief — see §1a below):** a four-state admission model (Free/Paid/Price Varies/Price Not Posted), a four-mode location model (In Person/Online/Hybrid/Not Posted), per-event timezone and time-of-day display modes, a redesigned filterable events list, a small-screen calendar agenda view, URL-encoded list filters, duplicate-event detection, and an in-editor preview of how an unpublished event will look. All of this is described in full in Appendix A.

### 1a. Why Phase 1a–1d exist, and what Phase 2/3 are

In addition to the ordinary feature requests and bug fixes that make up most of this plugin's version history, the client supplied a document titled **"Community Calendar and Member Planning Project Brief"** (a `.docx` file, not part of this repository — it lives in the site owner's own files). That brief describes three phases:

- **Phase 1** — rebuild this plugin's own event data model (admission, location, time/timezone) and public-facing display (list view, calendar, filters) to a more detailed specification. **This is the Phase 1a/1b/1c/1d work described in this document, and it is complete as of 1.25.0.**
- **Phase 2** — member accounts, profiles, a personal "My Calendars" view, a member directory, and social connections between members.
- **Phase 3** — shared planning between connected members: recurring tasks, shared to-do lists, evidence/proof-of-completion uploads.

**Phase 2 and Phase 3 are NOT built, not started, and are not part of this plugin.** The brief itself states, and the site owner has confirmed, that Phase 2/3 must be built as a **separate WordPress plugin**, connected to this one only through each public event's own post ID (read-only), never by copying event data into member records and never by letting this plugin's own code touch member/profile/task data. If you are asked to build Phase 2 or Phase 3, start a new plugin — do not add member/profile/task code to this codebase.

If you need the exact Phase 2/3 specification text, ask the site owner for the project brief document; it is not included here because it is a source document the site owner owns, not plugin source code.

## 2. Architecture at a glance

**Bootstrap:** `community-events-calendar.php` defines constants (`CEC_VERSION`, `CEC_DIR`, `CEC_URL`, `CEC_TABLE_RSVP`), `require_once`s every one of the 28 `.php` files directly inside `includes/` (as of 1.25.0 — confirm the current count yourself with `find includes -maxdepth 1 -name "*.php" | wc -l` before relying on this number, since it will drift as the plugin grows), and wires up all hooks inside one singleton class, `CEC_Plugin`. The 5 files inside `includes/elementor/` are loaded differently: `includes/class-cec-elementor.php` (one of the 28 files above) `require_once`s all 5 of them itself, but only inside a function hooked to the `elementor/widgets/register` action — so those 5 files only load on a request where Elementor itself is active and registering widgets, not on every request the way the 28 top-level files do. There is no autoloader and no namespacing — every class is a global `CEC_*` class loaded unconditionally (for the 28 top-level files) on every request, admin or front-end, event-related or not.

**Directory layout:**

```
community-events-calendar/
├── community-events-calendar.php   # bootstrap, hook registration, activation/upgrade
├── readme.txt                      # user-facing setup + feature documentation
├── CHANGELOG.md                    # the full, version-by-version history — read this for "why"
├── TECHNICAL_BRIEF.md              # this document
├── includes/                       # one class per concern
│   └── elementor/                  # thin widget wrappers, one per shortcode
├── templates/                      # theme template overrides (single event, taxonomy archive)
│   └── parts/                      # event-card.php, event-row.php, rsvp-form.php (shared partials)
└── assets/{css,js}/
```

**Data model, high level:**

| Storage | What lives there |
|---|---|
| `cec_event` post type | One row per event *or* per occurrence of a recurring series. Title/excerpt/content/thumbnail map to standard WP fields; everything else is post meta — see **Appendix A** for the complete, current field list. |
| `cec_event_type`, `cec_partner_org`, `cec_venue` taxonomies | Categories, partner orgs, and venues. `cec_partner_org` and `cec_venue` also carry term meta — see Appendix A, item 3, for the full list (this grew substantially in 1.22.0: city/region/country/timezone were added to `cec_venue`). |
| `wp_cec_rsvps` (custom table) | One row per RSVP (event_id, user_id, name, email, guests, created_at). Has its own wp-admin list screen and CSV export (Events → RSVPs). |
| `wp_cec_subscribers` (custom table) | Email subscriptions: email + target (`all` or a partner-org term ID), confirmed flag, token. Unique on (email, target). |
| `wp_cec_volunteer_inquiries` (custom table) | Volunteer sign-up form submissions — private, admin-only, with its own wp-admin list screen and CSV export. |
| `cec_settings` option | Single serialized array holding all plugin settings (colors, toggles, page URLs, volunteer config, and as of 1.24.0, `first_weekday`). |
| `cec_calendar_manager` role + `cec_manage_events` capability | Added on activation; granted to Administrators automatically. |
| `cec_migrated_phase1a_admission_location` option | A boolean flag (not a setting shown in any UI). Set to `1` once the Phase 1a one-time data migration has run. See Appendix A, item 8. Do not delete this option on a live site — deleting it would cause the migration to run again and could re-flag already-reviewed events. |

`CEC_Event_Helper::data( $post_id )` is the single place that assembles nearly all of the above into one associative array — nearly every template and dashboard view calls this instead of reading post meta directly. **If you add a new event field that should show up anywhere in the plugin, add it to this method first**, then add it to the relevant form(s) — see the "five duplicated forms" note immediately below.

**The five duplicated forms — the single most important architectural fact to understand before editing any event field.** Every field an event has is independently re-implemented, with its own HTML and its own `$_POST` handling, in five separate places:

1. `includes/class-cec-meta-boxes.php` — the wp-admin "Event Details" meta box.
2. `includes/class-cec-submission-form.php` — the public `[cec_submit_event]` form.
3. `includes/class-cec-frontend-dashboard.php` — the front-end Calendar Manager dashboard's edit form.
4. `includes/class-cec-my-events.php` — a logged-in submitter's own `[cec_my_events]` edit form.
5. `includes/class-cec-guest-edit.php` — the guest (no-account) email-link edit form.

There is no shared form-rendering code between these five files. **If you add or change a field, you must update all five files, or decide explicitly that a given field does not belong in one of them and say so in your own commit message.** (As one real example: Phase 1a's new "Source Link" field was deliberately added only to #1 and #3 — the two editor-facing surfaces — and deliberately left out of #2, #4, and #5, because it is an editorial/provenance field, not something a public submitter would have a reason to fill in. That was a judgment call, documented in the 1.22.0 CHANGELOG entry, not an oversight.)

As of 1.22.0, the *logic* for saving and displaying the newer Phase 1a fields (admission, location, time/timezone) is centralized in `CEC_Event_Helper::save_phase1a_fields()` (called by all five save handlers) and in read-side helpers on the same class (`admission_badge()`, `location_display()`, `format_price()`, `date_block()`, `timezone_select_html()`). Only the HTML markup is still duplicated five times — the underlying save/read logic is not. If you add a new Phase-1a-style field, follow this same pattern: write the save logic once in `CEC_Event_Helper`, call it from all five places that need it.

**Request flow pattern:** almost every form in this plugin follows the same shape: a shortcode renders a plain HTML `<form>` posting to `admin-post.php` with an `action` hidden field and a `wp_nonce_field()`; a matching `admin_post_{action}` (and often `admin_post_nopriv_{action}`) hook handles it, validates the nonce, sanitizes `$_POST`, does the write, and `wp_safe_redirect()`s back with a query-arg status flag the shortcode reads to show a notice. Quick "row action" buttons (approve/reject/delete/postpone) instead go through two small AJAX endpoints (`CEC_Ajax`, `CEC_Dashboard_Ajax`) using one shared nonce (`cec_frontend`, localized to the page as the `CEC` JS object). Once you recognize this pattern, every one of the shortcode classes reads the same way.

**Recurring events** (`CEC_Recurrence`) are the most intricate piece of the codebase. A repeating event is a "series root" post; each future date is generated as its own real `cec_event` post ("occurrence") tagged with `_cec_recurrence_parent_id`, so every existing query, template, RSVP, and feed works on occurrences unmodified — no special-casing needed elsewhere. Consequences worth internalizing before you touch this file:
- Editing a series root's *schedule* (rule/until/ordinals/weekdays/start) regenerates every *upcoming* occurrence from scratch (past ones are left alone), which also wipes any per-date postpone/cancel set via "Manage Dates." A signature hash (`_cec_recurrence_signature`) is used to detect whether the schedule actually changed vs. just the content, so a pure content edit (fixing a typo) instead patches existing occurrences in place (`sync_content_to_occurrences`) and preserves per-date state.
- Approving/rejecting/trashing the root cascades to every occurrence (`cascade_status`, hooked to `transition_post_status`).
- Occurrences are excluded from the wp-admin Events list table (`exclude_occurrences_from_admin_list`) — they're only reachable via the root's "Manage Dates" view.
- Hard safety cap of 104 generated dates (`MAX_OCCURRENCES`) regardless of rule — roughly 2 years of weekly events.
- **As of 1.22.0:** when a new occurrence post is generated, `update_series()` copies *every* post meta field from the parent except an explicit skip-list (`$skip_meta` inside `CEC_Recurrence::update_series()`). This means every Phase 1a field (admission status, location mode, timezone, etc.) is copied to new occurrences automatically — you do not need to add anything to this method when you add a new event field, **unless** that new field is something that must never be copied (like the recurrence rule itself, which is already in the skip-list). If in doubt, read the skip-list in that method directly before assuming your new field will or won't be copied.

## 2a. Integration API for other plugins (1.26.0)

The separate member-planning plugin (Phase 2/3) reads events only through these, and this plugin never reads member data:

- `cec_get_public_event( $id )` / `cec_get_public_events( $ids )` (`CEC_Public_API`): allow-listed public fields for a **published** event, `null` otherwise. Do not add editorial or RSVP fields to `CEC_Public_API::FIELDS`. Bump `CEC_PUBLIC_API_VERSION` if a key is removed or changes meaning.
- `CEC_Month_Grid::render( $month, $year, $items, $options )`: the month-view layout with no query of its own. The public calendar feeds it from `CEC_Shortcodes::render_month_html()`. Any change to the month view's markup now goes in `CEC_Month_Grid`, and affects the member plugin's calendars too.

## 3. Full feature / shortcode reference

| Shortcode | Handled by | Notes |
|---|---|---|
| `[cec_calendar]` | `CEC_Shortcodes::calendar` | Month grid, AJAX month navigation via `CEC_Ajax::calendar_month`. As of 1.24.0, respects the "First Day of Week" setting (Events → Settings → Calendar Display) and shows a different, non-grid "agenda" layout below 700px screen width instead of a shrunk grid. |
| `[cec_events view="grid\|list"]` | `CEC_Shortcodes::list_grid` | As of 1.24.1, **the default is `view="list"`** (it was `view="grid"` before 1.24.1 — if you see old documentation or an old saved Elementor layout assuming grid is the default, it is not, as of this version). List view is the redesigned "date-range-first" card layout from 1.23.0; Grid view is the older photo-forward card grid and is unchanged. Filterable by event type, venue, and a date-range preset (This Week/This Month/Next 3 Months/All Upcoming); a separate Soonest-First/Latest-First sort toggle. As of 1.24.0, the current filter/sort/view state round-trips through the page URL's own query string (`cec_event_type`, `cec_venue`, `cec_date_range`, `cec_sort`, `cec_view`) — copying the URL reproduces the same filtered view. AJAX-refreshed via `CEC_Ajax::filter_events`. |
| `[cec_upcoming count="8"]` | `CEC_Shortcodes::upcoming` | Horizontal scroll widget; default count from Settings. Not changed by Phase 1a–1d beyond picking up the new admission-badge labels automatically. |
| `[cec_submit_event]` | `CEC_Submission_Form` | Main front-end submission form; login/guest gating per Settings. One of the five duplicated forms — see §2. |
| `[cec_admin_dashboard]` | `CEC_Frontend_Dashboard` | Requires `cec_manage_events` capability. Approve/reject/edit/delete events, manage partner orgs & venues, manage series dates, import `.ics` calendar files. As of 1.25.0, an event's edit screen here also shows the Phase 1d editor preview (see Appendix A, item 10). |
| `[cec_my_events]` | `CEC_My_Events` | A logged-in submitter's own events: edit (→ back to `pending`), postpone/cancel, withdraw, manage series dates. |
| `[cec_login]` / `[cec_register]` | `CEC_Auth` | Styled equivalents of wp-login.php; register has an email/password path plus a honeypot field. |
| `[cec_manage_submission]` | `CEC_Guest_Edit` | Guest (no-account) editing via emailed bearer-token link. See §5. |
| `[cec_subscribe]` | `CEC_Subscribers` | Double opt-in email subscription (all events and/or specific orgs), with confirm/unsubscribe token links. |
| `[cec_volunteer_form]` | `CEC_Volunteers` | Separate, private inquiry form — never public, reviewed only under Events → Volunteer Inquiries (Administrator-only). |

Every shortcode above also has a matching Elementor widget (`includes/elementor/class-widget-*.php`). All widgets are thin wrappers whose `render()` just calls `do_shortcode()` with the shortcode's attributes filled in from Elementor's own settings panel. There is no Elementor-specific business logic anywhere — a change to a shortcode's behavior applies to its widget automatically. **One specific thing to know:** the Elementor "Community Events (Grid/List)" widget's own Layout control default was changed to `list` in 1.24.1 to match the shortcode default — but this only affects *new* widget instances someone drags onto a page from now on. Any copy of this widget already placed on an existing page before 1.24.1 keeps whatever value Elementor already saved for it; it will not silently change.

## 4. Roles, capabilities, and approval workflow

- A brand-new `cec_calendar_manager` role and a `cec_manage_events` capability are created on activation (`CEC_Roles::install`, also re-run on every `init` so a fresh WP core update or role plugin doesn't lose it). Administrators are granted the capability automatically, additively — nothing about core wp-admin access changes for them.
- Anyone with `cec_manage_events` but *not* `manage_options` is treated as a delegated, front-end-only manager: `CEC_Roles::maybe_redirect_from_wp_admin` bounces them out of wp-admin (except a short allowlist of AJAX/media/profile endpoints) to the configured dashboard page, and `hide_admin_bar` hides the admin bar for them.
- Regular submitters need no special role — a plain Subscriber account (WordPress's default new-user role) is enough to submit and manage their own events via `[cec_my_events]`, because `CEC_Dashboard_Ajax`'s `delete`/`set_status` actions and `CEC_My_Events`'s edit form both check *ownership* (`post_author === current user`) rather than a capability.
- Approval workflow rides on WordPress's native post statuses: `pending` → `publish`, or `draft` (rejected/hidden). As of 1.22.0 there is also a fourth, custom status, `cec_in_review`, registered via `register_post_status()` in `CEC_Post_Types::register()` — meant for "an editor is actively checking this one," distinct from `pending` ("just submitted, nobody has looked yet"). It is injected into wp-admin's native Publish box via a small JavaScript snippet (`CEC_Post_Types::inject_in_review_status_js()`, hooked to `admin_footer-post.php` and `admin_footer-post-new.php`) — this is the standard technique other plugins (WooCommerce, Easy Digital Downloads) use to add a custom status to that same native dropdown, since WordPress's own Publish box only lists built-in statuses otherwise. **This specific piece (the JS-injected option in wp-admin's native status dropdown) has not been checked in a live wp-admin screen — see Appendix B.** `auto_publish` in Settings can skip the whole approval step entirely (off by default, and the settings UI actively discourages turning it on).

## 5. Guest submission & token-based editing (read this before touching `CEC_Tokens`)

This is the one subsystem in the codebase doing something security-sensitive enough to deserve its own section. When "Guest submissions" is enabled, an unauthenticated visitor can submit an event with just an email address. To edit it later, they request an edit link from `[cec_manage_submission]`; `CEC_Tokens::issue_for_event()` generates a 20-byte random token, and **only its SHA-256 hash is stored** in post meta (`_cec_edit_token_hash`) — the raw token exists only in the emailed URL. Verification (`CEC_Tokens::verify`) uses `hash_equals()` for a timing-safe comparison and checks a 7-day expiry (`_cec_edit_token_expires`). Requesting a fresh link re-issues a new token (invalidating the old one) and is rate-limited to once per 5 minutes per email via a transient.

Two things to know if you extend it:
- A token only grants access to *one specific event ID* — there's no "list all my events" session concept. `send_edit_links()` emails one link per event when multiple exist.
- It only manages standalone events and series roots, never one specific occurrence inside a series (that still requires a real login via My Events → Manage Dates) — this is a deliberate scope limit, not an oversight.

## 6. Front-end/back-end split & the shared AJAX action pattern

`CEC_Dashboard_Ajax::handle()` is the one AJAX endpoint used by *both* the manager dashboard and My Events for quick row actions (approve, reject, delete, set_status), branching internally on `$is_manager` vs. `$is_owner`. If you add a new quick action here, remember both permission paths need to be considered.

The other AJAX surface, `CEC_Ajax`, is unauthenticated-friendly by design (calendar navigation and filtering need to work for anonymous visitors) and only reads data — no capability checks needed there.

**As of 1.25.0, there is a third place `frontend.js` runs: wp-admin's own `cec_event` edit screen.** `CEC_Plugin::enqueue_admin()` now additionally enqueues the public `assets/css/frontend.css` and `assets/js/frontend.js` (with their own localized `CEC` JavaScript object, pointed at the normal `admin-ajax.php` URL) whenever the current screen's post type is `cec_event`. This was done specifically so the Phase 1d "Event Preview" meta box's embedded month calendar has working Prev/Next/Today/click-a-date buttons in wp-admin, without copying that JavaScript a second time into `assets/js/admin.js`. **Known, accepted overlap:** `admin.js` already has its own copy of the admission-status/location-mode field-toggle click handlers (added in 1.22.0, before this file was also loaded in wp-admin), targeting the same CSS classes (`.cec-admission-status-select`, `.cec-location-mode-select`, etc.) that `frontend.js` also binds to. With both files loaded on the same wp-admin screen, both sets of handlers now fire on every change event on those elements. This is harmless — both handlers do the exact same thing (toggle an element's `hidden` attribute), and doing that twice in a row has no different effect than doing it once — but it is worth knowing about if you ever change one of those two handlers without changing the other, since right now they are expected to always do the identical thing.

## 7. Front-end styling, and a mandatory rule for any new interactive element

`assets/css/frontend.css` implements a dark/neon visual theme (near-black surfaces, hot-pink borders, lime CTAs) using CSS custom properties (`--cec-primary`, `--cec-secondary`, `--cec-accent`, `--cec-free-badge`, `--cec-text`, `--cec-bg`) set inline per-site from Events → Settings → Color Scheme. A second group of custom properties (`--cec-radius`, `--cec-pill`, `--cec-card`, `--cec-card-alt`, `--cec-muted`, `--cec-border-soft`) is defined locally, not at `:root`, on one specific comma-separated list of wrapper class selectors near the top of `frontend.css`. **If you add a new top-level wrapper element (a new shortcode's outer `<div>`, for example), you must add its class name to that same selector list, or every child element inside it that uses `var(--cec-card)`, `var(--cec-radius)`, etc. will silently render with no value for that property.** This exact mistake was made and caught during Phase 1d (the `.cec-editor-preview` wrapper was initially left out of that list) — see Appendix B.

**Mandatory rule, in effect since 1.24.1, for every future interactive element (button, link, or `<select>`) you add to this plugin's front-end CSS:** scope the selector under its containing wrapper class, and add `!important` to every property that defines its visual identity (background, color, border, text-decoration). Do not write a bare, unscoped selector like `.my-new-button { background: pink; }` and assume it is safe because it is new. **Why this is mandatory, not a style preference:** a real client WordPress theme's own button/link CSS commonly uses a compound selector (for example `button.some-theme-class`) together with its own `!important`. A plain single-class selector, even with `!important` on your side too, will lose to that theme rule, because among two rules that both use `!important`, normal CSS specificity still decides the tie, and a compound class-plus-element selector is more specific than a single class. This has already happened twice on this exact plugin: once on the calendar's event bars/pills (fixed in 1.21.0), and once on the events-list filter bar's buttons (fixed in 1.24.1, after it had already reached the live site and the site owner reported it with a screenshot). The fix pattern both times was identical: change `.cec-view-btn { ... }` to `.cec-events-wrap .cec-view-btn { ... !important; ... }` (scope under the real wrapper class actually present in that markup, with `!important` on every visual property). Follow this pattern from the start for every new element; do not wait for a live bug report to add it.

## 8. Version history

**`CHANGELOG.md`**, in this same repository, is the complete, authoritative, version-by-version history from 1.0.0 through the current 1.25.0. Every entry from 1.15.0 onward was written by the same developer (Claude) who made the corresponding change, in the same work session, and describes not just what changed but why, and (where relevant) what was verified and how. Read it in full before making your first change — it is long, but it is the fastest way to understand decisions you will otherwise have to reverse-engineer from the code alone.

Going forward: bump `CEC_VERSION` in `community-events-calendar.php`'s header comment and its `define()` statement, and add a dated entry to the top of `CHANGELOG.md`, for every release, no matter how small. This has been done consistently for the entire history of this plugin; do not be the first person to break that pattern.

**Also note:** this plugin is version-controlled with `git` inside this `community-events-calendar/` directory (confirm with `git log` from inside this folder). A `community-events-calendar.zip` file in the *parent* directory (one level up from this repository) is a build artifact, not source — it must be manually rebuilt (`zip -rq community-events-calendar.zip community-events-calendar -x "community-events-calendar/.git/*"`, run from the parent directory) after every commit you want to actually deploy, since nothing does this automatically. Check whether that zip file's modification date matches your latest commit before assuming it is current.

## 9. Known issues, gaps, and things to verify

This section replaces the equivalent section from the August 2026 revision of this document. Several items listed there have since been fixed; they are not repeated here. Specifically, as of 1.25.0, the following are **already fixed** and do not need re-doing: an RSVP attendee list and CSV export exist (Events → RSVPs); the RSVP capacity race condition is fixed with a MySQL `GET_LOCK`/`RELEASE_LOCK` mutex; both the RSVP and Volunteer Inquiry CSV exports escape formula-injection characters (`=`, `+`, `-`, `@`); and both of this plugin's AJAX endpoints send `nocache_headers()`.

Ranked roughly by how much they would affect an incoming maintainer:

1. **Nothing from 1.15.0 through 1.25.0 has been run inside a real WordPress installation.** This is the single most important item in this list. See the note at the very top of this document, and read Appendix B before trusting any specific piece of Phase 1 behavior without checking it yourself first.
2. **No distinct "Member" role exists.** Front-end submission still requires only a plain Subscriber account. This was flagged as a possible future addition as far back as 1.6.2 and still has not been built. Not a defect — just still not done.
3. **No rate limiting on registration, login, or the subscribe/volunteer forms.** Only the guest edit-link request (§5) has one. Registration and subscribe forms have honeypot fields (stops simple bots, not a targeted script).
4. **Outgoing email is a hard dependency with no fallback or delivery logging.** Guest edit links, subscription confirms/notifications, approval notices, and volunteer-inquiry alerts all go through plain `wp_mail()`. There is a "Send Test Email to Me" button on the Settings page (Events → Settings → Email Deliverability) — use it on the real production host before launch, every time, regardless of how confident you are that email is configured correctly.
5. **No automated tests.** There is no `tests/` directory, no PHPUnit config, no CI config anywhere in this repository. Any change to `CEC_Recurrence` in particular should be manually verified against the edge cases called out in its own code comments, since there is no test suite to catch a regression.
6. **Everything loads on every request.** No conditional loading by post type or page — all 28 files in `includes/` are `require_once`'d unconditionally on every page load. Not a bug; the obvious first lever if this plugin ever needs a performance pass.
7. **The admission-migration "Needs Price Review" admin filter has not been checked against real, pre-1.22.0 production data.** If this plugin is updated on a live site that had events created before 1.22.0, every event that previously had its "This event is free" checkbox checked will be migrated to "Price not posted" and flagged with this filter (Events list screen, a "Needs Price Review" view link appears automatically only if at least one event is flagged) — this is intentional (see CHANGELOG 1.22.0 for why), but someone needs to actually go through that filtered list on the real site after the update and re-confirm each one's real price. This is a one-time, post-update task, not a recurring one.
8. **The "first weekday" setting (Events → Settings → Calendar Display) and its effect on the month grid's column order has only been checked with reconstructed sample HTML/CSS, never a real WordPress month calendar.** The underlying date-offset math was separately verified in Python and is very likely correct, but "very likely correct based on a math check" and "confirmed correct by looking at the real calendar" are different claims — do the second one before relying on the first.
9. **The session/sub-record model for multi-day events with per-day admission differences does not exist.** The project brief mentions this as a possible need ("Add a linked session record only when it has its own start/end time, admission status, or booking URL"); it was explicitly deferred, with the note "confirm genuinely needed before building" — it has not been confirmed as needed, and has not been built. If a client event needs, for example, a 3-day festival where Day 2 costs a different amount than Day 1 and Day 3, that cannot currently be represented — the whole event has one admission status.
10. **Keyword search and an admission-status filter are not in the `[cec_events]` quick-filter bar.** The project brief's filter list (`date-range, location/region, event-type, admission-status, and keyword filters`) was only partially built in Phase 1b: event-type, venue (as a proxy for "location"), and date-range made it into the filter bar; admission-status and a free-text keyword search did not, and were not separately tracked as a "Phase 1c/1d" item either — this is a real, unflagged gap in what the brief asked for, found while writing this document, not previously caught.

None of the above are "this is broken, stop what you're doing," with the possible exception of item 1 if you are about to deploy this to a live site without first doing the manual check that item describes.

## 10. Suggested next steps / roadmap

In priority order:

1. **Do the manual, real-WordPress verification pass described at the top of this document and in Appendix B, before anything else.** This is not optional if this code is going to run on a real site that real members and the public will use.
2. **Decide what to do about item 10 in §9** (admission-status and keyword filters missing from the quick-filter bar) — either confirm with the site owner that this gap is acceptable, or build the two missing filter controls. This is a small, contained addition to `CEC_Shortcodes::list_grid()` / `render_events_html()` and the matching AJAX handler, following the exact same pattern as the three filters already there.
3. **If the site owner wants to proceed with Phase 2 (member accounts/profiles/My Calendars) or Phase 3 (shared planning)**, start a new, separate plugin. Do not add that code here. Re-read §1a of this document, and get the full project brief document from the site owner before starting — this document intentionally does not restate that specification.
4. Everything in §9 that isn't already covered above (no Member role, no rate limiting on register/login, no automated tests) remains a legitimate, lower-priority improvement — same status as in the August 2026 revision of this document, just carried forward since nothing has changed about them.

## 11. Quick orientation checklist for a new maintainer

1. Read `readme.txt` first — it describes intended behavior from a site-owner's point of view. Read `CHANGELOG.md` right after, in full — it is long, but it is the complete record of every decision made in this codebase, including ones this document only summarizes.
2. Read `CEC_Event_Helper::data()` and `CEC_Event_Helper::save_phase1a_fields()` — nearly everything else in the plugin either consumes the first or calls the second.
3. Read §2 of this document (the "five duplicated forms" note) until it is completely clear which five files you need to touch for any event-field change. This is the single most common mistake an incoming developer on this codebase would make: changing one of the five forms and assuming the other four are unaffected or already consistent.
4. Read `CEC_Recurrence` in full before changing anything related to repeating events.
5. Read §7 of this document (the mandatory interactive-element hardening rule) before writing any new CSS for a button, link, or select element.
6. Read Appendix B of this document in full before assuming any specific piece of Phase 1 (1.22.0–1.25.0) behavior is correct without checking it yourself.
7. Set up a local WordPress install. Submit one real test event through each of the five entry points listed in §2 (wp-admin, public submission form, Calendar Manager dashboard, My Events, guest email-link edit), and confirm each one saves and displays correctly — this is the single highest-value thing you can do before making any further changes, given item 1 in §9.

---

## Appendix A — Complete Phase 1 (1.22.0–1.25.0) field and setting reference

This appendix exists because the main body of this document describes architecture and decisions, not an exhaustive field list — and a field list is exactly the kind of thing worth having in one place, stated exactly, rather than requiring you to grep five files and compare them.

### A1. Event post meta added or changed in Phase 1 (all keys are exact, including the leading underscore and `cec_` prefix — e.g. the real meta key is `_cec_time_mode`, not `cec_time_mode`)

**Date/time (Group A, 1.22.0):**

| Meta key | Type | Allowed values | Notes |
|---|---|---|---|
| `_cec_time_mode` | string enum | `exact`, `start_only`, `all_day`, `varies` | Default for a new event: `exact`. Controls whether a time is shown at all on public pages. |
| `_cec_timezone` | string | A valid IANA timezone name (e.g. `America/Chicago`), or empty string | Empty means "use the venue's timezone, or the site's own timezone if the venue has none." Validated against PHP's `timezone_identifiers_list()` before being saved — see `CEC_Event_Helper::is_valid_timezone()`. **As of 1.25.1:** anything that emits an absolute time (ICS, the Google Calendar link, schema.org) must convert the stored wall-clock `_cec_start`/`_cec_end` through `CEC_Event_Helper::local_datetime( $raw, $data['timezone'] )` — never `get_gmt_from_date()` or `date_i18n( 'c' )`, which assume the site's timezone. |

**Admission (Group B, 1.22.0) — `_cec_admission_status` is the single source of truth; `_cec_is_free` still exists but is now a *derived*, read-only value computed from it, kept only so old code reading `_cec_is_free` directly does not break:**

| Meta key | Type | Allowed values | Notes |
|---|---|---|---|
| `_cec_admission_status` | string enum | `free_confirmed`, `paid`, `price_varies`, `not_posted` | Default for a new event, and the migration target for ambiguous legacy data: `not_posted`. **Never** write `_cec_is_free` directly in new code — write this field, and read `is_free` back out of `CEC_Event_Helper::data()`'s return array if you need the old boolean. |
| `_cec_price_amount` | string (numeric) | A decimal number as a string, e.g. `"10.00"`, or empty | Only meaningful when `_cec_admission_status` is `paid`. |
| `_cec_price_min` / `_cec_price_max` | string (numeric) | Same as above | Only meaningful when `_cec_admission_status` is `price_varies`. Either one may be set without the other. |
| `_cec_price_currency` | string | A 3-letter currency code, e.g. `USD` | Defaults to `USD` if empty. |
| `_cec_price_note` | string (free text) | Any text, or empty | Pre-existing field, kept as a supplementary note shown alongside the structured price (e.g. "suggested donation"). |
| `_cec_price_source_url` | string (URL) | A URL, or empty | Where to verify the price — shown as a "Verify this price" link on the single event page when present. |
| `_cec_price_needs_review` | string | `"1"` (present) or absent entirely | Set only by the one-time Phase 1a migration, on events that had the old `is_free` checkbox checked. Cleared automatically the next time anyone saves that event's admission fields through any of the five forms. Drives the "Needs Price Review" admin-list filter link (see §9, item 7). |

**Location (Group C, 1.22.0):**

| Meta key | Type | Allowed values | Notes |
|---|---|---|---|
| `_cec_location_mode` | string enum | `in_person`, `online`, `hybrid`, `not_posted` | Default for a new event: `in_person`. Migration default for existing events: `in_person` if they have a venue term or a custom address, `not_posted` otherwise. |
| `_cec_online_url` | string (URL) | A URL, or empty | Only meaningful when `_cec_location_mode` is `online` or `hybrid`. |
| `_cec_venue_custom_city` / `_cec_venue_custom_region` / `_cec_venue_custom_country` | string (free text) | Any text, or empty | Only used when the event has a free-text custom address instead of a saved Venue taxonomy term — mirrors the existing `_cec_venue_custom_address` field's pattern. If the event uses a saved Venue term instead, the equivalent values come from that term's own term meta (see A3) and these three meta keys are not used. |

**Bundled field-hygiene additions (1.22.0):**

| Meta key | Type | Allowed values | Notes |
|---|---|---|---|
| `_cec_photo_alt` | string (free text) | Any text, or empty | Alt text for the event's featured image. Synced onto the actual attachment's own `_wp_attachment_image_alt` meta whenever a photo is uploaded or this field is changed. |
| `_cec_official_website_url` | string (URL) | A URL, or empty | Distinct from `_cec_host_org_url` (the host organization's own site) — this is the event's own permanent website, if it has one. |
| `_cec_source_url` | string (URL) | A URL, or empty | Editorial-use only, where this listing's information was originally copied from. Only present in the wp-admin meta box and the front-end dashboard edit form — not in the public submission form, My Events, or guest edit, by deliberate choice (see §2). |

**Other:**

| Meta key | Type | Allowed values | Notes |
|---|---|---|---|
| `_cec_ics_import_uid` / `_cec_ics_imported_at` | string | Pre-existing, unchanged by Phase 1 | Used by the `.ics` calendar-file importer's "update existing instead of duplicate" feature. |

### A2. Post status

| Status key | Registered where | Meaning |
|---|---|---|
| `cec_in_review` | `CEC_Post_Types::register()`, via `register_post_status()` | A fourth approval state, distinct from `pending` (just submitted) and `draft` (rejected/hidden). Selectable from the front-end Calendar Manager dashboard's own Status dropdown, and injected into wp-admin's native Publish box via JavaScript (see §4). Included in every `post_status` array in a `WP_Query`/`get_posts()` call throughout the codebase that already included `pending`/`draft` before 1.22.0 — if you write a new query against `cec_event` posts and want it to include events currently in this status, you must include `'cec_in_review'` explicitly in that query's own `post_status` array; it is not automatically included by any WordPress default. |

### A3. Taxonomy term meta added in Phase 1 (all on the `cec_venue` taxonomy only)

| Term meta key | Type | Notes |
|---|---|---|
| `cec_city` | string (free text) | |
| `cec_region` | string (free text) | State/province/region. |
| `cec_country` | string (free text) | |
| `cec_timezone` | string | A valid IANA timezone name, or empty. If empty, an event at this venue falls back to the site's own timezone. |

(Pre-existing term meta on `cec_venue`, unchanged: `cec_url`, `cec_address`. Pre-existing term meta on `cec_partner_org`, unchanged: `cec_url`, `cec_logo`, `cec_default_coc`, `cec_default_rsvp_mode`, `cec_default_rsvp_url`.)

### A4. Plugin settings added in Phase 1 (stored inside the single `cec_settings` option, under `CEC_Admin_Settings`)

| Settings array key | Type | Allowed values | UI location |
|---|---|---|---|
| `first_weekday` | string | `"0"` through `"6"` (0 = Sunday, matching PHP's own `date('w', ...)` convention) | Events → Settings → Calendar Display → First Day of Week |

### A5. New/changed template files

| File | Purpose |
|---|---|
| `templates/parts/event-row.php` | New in 1.23.0. The "date-range-first" row card used only by `[cec_events view="list"]`. Expects `$data` (from `CEC_Event_Helper::data()`) in scope. Do not confuse with `templates/parts/event-card.php` (the older, still-in-use Grid-view photo card) — they are two different templates for two different views, not a replacement of one by the other. |

### A6. Where to find the duplicate-detection and editor-preview code (1.25.0)

- `CEC_Event_Helper::find_possible_duplicates( $post_id )` — the detection logic itself.
- `CEC_Event_Helper::render_editor_preview( $post_id, $context = 'admin', $dashboard_url = '' )` — the shared renderer for both the wp-admin "Event Preview" meta box (`CEC_Meta_Boxes::render_preview()`, called with `$context = 'admin'`) and the front-end dashboard edit form (`CEC_Frontend_Dashboard::render_edit_form()`, called with `$context = 'dashboard'` and the current dashboard page's own URL as `$dashboard_url`).
- `CEC_Shortcodes::render_month_html( $month, $year, $preview_post_id = 0 )` — the third parameter is new in 1.25.0. When non-zero, that one specific post is included in the rendered month's results regardless of its own post status, and is visually marked with a dashed outline (CSS class `cec-cal-preview-highlight`). This parameter must only ever be passed by code that has already confirmed the current user is allowed to see an unpublished post — it performs no capability check of its own.

---

## Appendix B — Verification methodology actually used during 1.15.0–1.25.0 development

This section exists so you do not have to guess what "verified" meant in a given `CHANGELOG.md` entry. Across all of this version range, no live WordPress or PHP installation was available. Every verification claim in `CHANGELOG.md` means one or more of the following four things, and never more than that:

1. **Brace/parenthesis counting.** After editing a PHP file, the number of `{` characters was counted and compared to the number of `}` characters (and the same for `(`/`)`), to catch an unclosed block or an unbalanced edit. This catches certain classes of syntax error. It does not catch a logic error, an incorrect function name, a wrong variable name, or any error that doesn't change the brace/paren count.
2. **Python re-implementation.** For anything involving non-trivial logic (date math, string matching, a migration's decision rules), the same logic was re-written in Python and run against hand-constructed sample data, to check the *logic* independent of PHP syntax. This is a reasonable check of an algorithm's correctness. It is not a check that the PHP code is a faithful translation of that algorithm — a transcription error from the verified Python version into the actual PHP code would not be caught this way.
3. **Browser rendering of reconstructed markup.** For CSS and JavaScript changes, a plain HTML file was hand-written to imitate what the real PHP template would output, the actual (not reconstructed) `frontend.css` and `frontend.js` files were loaded into it, and the result was viewed in a browser, including resizing it to simulate a phone screen. This is a real, meaningful check of the actual shipped CSS/JS files — they are not re-implementations — but the HTML they were tested against was hand-written to *resemble* real WordPress output, not generated by WordPress itself. A mismatch between the hand-written test markup and what the real PHP template actually outputs would not be caught this way.
4. **Hostile mock-theme testing**, used specifically for the two theme-collision bugs described in §7: a small CSS file was written containing rules deliberately designed to beat this plugin's own CSS (matching the real, reported symptom), loaded in the same test page as item 3, and the fix was confirmed to win against that specific adversarial CSS. This is a good check of specificity-war bugs of the *same shape* as the one being fixed. It is not a check against the actual theme running on the real site, which was never available to test against directly.

**What none of the above can catch:** a WordPress hook that doesn't fire the way the code assumes it does; a WordPress function called with the wrong arguments; a database query that is syntactically valid PHP but produces the wrong SQL; any interaction with the real wp-admin UI (meta boxes, the block editor, admin notices) that was not separately checked by careful code-reading; anything involving actual email delivery; anything involving the real behavior of a real, currently-installed theme or other plugin on the live site.

If you are deciding how much to trust a specific piece of this plugin's 1.15.0–1.25.0 behavior, the honest answer is: as much as you trust the four methods above to have caught a problem with that specific piece, and no more. For anything load-bearing, check it yourself on a real WordPress install before relying on it.
