# Community Member Planning — Technical Brief

**Version documented:** 0.1.0 (Phase 2 increment 2.0)
**Read first:** `docs/phase-2-integration-plan.md` (repo root) for scope, boundaries and the owner decisions, and the project brief (`Community Calendar and Member Planning Project Brief`, owned by the site owner) for the full Phase 2/3 specification.

## 1. Boundaries (non-negotiable, from the project brief)

- This plugin owns **all** member data: profiles, personal calendars, responses, connections, permission grants, tasks, private files, notifications, audit log. The Community Events Calendar (CEC) owns public events only.
- Read events **only** through `cec_get_public_event()` / `cec_get_public_events()` (CEC ≥ 1.26.0) and store the event ID, never a copy of title/date/location. Render calendars with `CEC_Month_Grid::render()` and this plugin's own permission-checked items. Never send member data through CEC's queries, REST responses, feeds or caches.
- Identity is the WordPress user ID. No second login store.
- Every page, REST route, feed and file route must call `CMP_Access::is_member()` (or a stricter check) on the server, then check record-level ownership/grants. Never rely on hidden UI.

## 2. Layout

```
community-member-planning/
├── community-member-planning.php   bootstrap, constants, CMP_Plugin (hooks), CEC dependency notice
├── uninstall.php                   deletes data only if the admin opted in
├── includes/
│   ├── class-cmp-install.php       tables via dbDelta, CMP_DB_VERSION upgrades
│   ├── class-cmp-settings.php      Settings → Member Planning (cmp_settings option)
│   ├── class-cmp-audit.php         append-only audit log
│   ├── class-cmp-access.php        the gate: logged_out → unverified → unattested (no 18+ date of birth, or locked) → member
│   ├── class-cmp-dynamics.php      dynamics between members: propose/accept/end, lead_can_direct(), connections, Dynamics tab
│   ├── class-cmp-homework.php      homework programs: tasks, daily entries, proof photos, lead review, Homework tab
│   ├── class-cmp-chastity.php      chastity locks: keyholder controls, verification codes/photos, hygiene, emergency unlock, Chastity tab
│   ├── class-cmp-feed.php          member feed: text/photo posts, event tags, likes, reports, admin hide, Feed tab
│   ├── class-cmp-messages.php      member messages: requests, blocks, reports (Users → Member reports), Messages tab
│   ├── class-cmp-follows.php       follow / unfollow, opt-in "Going to", Following feed filter, calendar label (cec_event_social_label)
│   ├── class-cmp-directory.php     Members tab: search and filters (shown answers only), opt-out, profile item links
│   ├── class-cmp-onboarding.php    step-by-step profile setup (6 steps over the normal profile save; home opens it for new members)
│   ├── class-cmp-birth-date.php    date of birth: sign-up fields via CEC hooks, one-time step, under-18 lock, age, wp-admin correction
│   ├── class-cmp-email-verification.php
│   ├── class-cmp-notifications.php in-app inbox, quiet hours, category opt-out
│   ├── class-cmp-rest.php          cmp/v1 routes + no-store headers
│   ├── class-cmp-member-area.php   [cmp_member_area] page, tabs, gate form handlers, page protection
│   └── class-cmp-account.php       Account tab (details, email change, password, devices, privacy requests),
│   │                               dashboard lockout, preferences, WordPress privacy exporter/eraser
│   ├── class-cmp-profile-fields.php  field dictionary + admin option lists (stable keys, never deleted)
│   ├── class-cmp-profiles.php      profile values, visibility rule can_view(), Profile tab, member view
│   ├── class-cmp-profile-images.php  photo upload/re-encode, DB storage, permission-checked serving
│   ├── class-cmp-profile-options-admin.php  Users → Member Profile Options
│   ├── class-cmp-site-menu.php     menus follow sign-in state (Log In → Sign Out)
│   └── class-cmp-account-bar.php   [cmp_account_bar] header shortcode
└── assets/{css/member.css, js/member.js}
```

## 3. Data

| Storage | Contents |
|---|---|
| `{prefix}cmp_audit_log` | id, actor_id, action, object_type, object_id, old_value (JSON), new_value (JSON), reason, created_at (UTC). Append-only. |
| `{prefix}cmp_notifications` | id, user_id, category, message, url, created_at, deliver_at (quiet hours), read_at. All UTC. |
| user meta `cmp_verified_email`, `cmp_email_verified_at` | The address that was verified. Verified ⇔ equals the current `user_email` (case-insensitive). |
| user meta `cmp_verify_token_hash` / `_expires` / `_email` | Outstanding verification link (SHA-256 of the token, expiry, address sent to). Cleared on use, expiry or email change. |
| user meta `cmp_email_change_hash` / `_expires` / `_email` | Pending email change (SHA-256 of the token, 24-hour expiry, new address). The address changes only when the link is opened; that also verifies it. Cleared on use, expiry or cancel. |
| `{prefix}cmp_profile_values` | user_id + field_key (PK), value (JSON; NULL for display name / photos / member since), visibility (private / connections / members), searchable (0 until 2.4), updated_at. No row = empty, Private, not searchable. |
| `{prefix}cmp_profile_images` | user_id + kind (avatar / cover) (PK), mime (always image/jpeg), width, height, bytes, sha256, data (MEDIUMBLOB, re-encoded JPEG), alt, decorative, created_at. |
| option `cmp_profile_options` | The five pick-lists: list => [ { key, label, active, order } ]. Keys are stable; options are retired, never deleted. |
| user meta `cmp_age_attested_at`, `cmp_age_attestation_version` | When the member passed the age step (UTC timestamp) and which wording version applied. |
| user meta `cmp_birth_date` | Date of birth, `Y-m-d` (0.4.0; owner decision 2026-10-04 overriding the project brief's "store the attestation flag and timestamp, not date of birth"). Required at sign-up and asked once of existing members. Never shown to anyone, never searchable, never written to the audit log; members can't edit it (the site team corrects it on the wp-admin user screen). Included in the export, removed by the eraser. `CMP_Birth_Date`. |
| user meta `cmp_age_blocked_at` | Set when a signed-in account gives a date under 18: the member area stays closed until the site team unlocks it. Kept by the eraser on purpose. |
| user meta `cmp_notify_disabled_categories`, `cmp_timezone` | Notification opt-outs; member timezone for quiet hours (UI arrives with profiles). |
| option `cmp_settings` | member_page_id, privacy_notice_url, attestation_text, attestation_version, delete_data_on_delete, lock_dashboard (default on) |
| option `cmp_db_version` | Schema version. Don't delete on a live site. |

Adding a table: add it to `CMP_Install::schema()` and `table_names()`, add it to `uninstall.php`, bump `CMP_DB_VERSION`.

## 4. Conventions

- **Audit:** call `CMP_Audit::log()` for every invitation/confirmation, permission change, profile privacy change, task status/review change, report edit and file upload/removal (brief §5). Updates pass old and new; deletes pass old and a reason.
- **Notifications:** `CMP_Notifications::add( $user_id, $category, $message, $url, $respect_quiet_hours = true )`. Pass `false` only for an immediate response to the member's own action.
- **REST:** new routes go under `cmp/v1` with `permission_callback => array( 'CMP_Rest', 'require_member' )` plus an ownership check in the handler. Return 404 (not 403) for records the user may not see, so IDs can't be probed. `no_store_headers()` already covers the whole namespace.
- **Forms:** `admin-post.php` + `_cmp_nonce` + `check_nonce()`, redirect back with `cmp_notice` (add the message to `notice_html()`, or to `CMP_Account::notices()` for Account-tab forms). Anything that changes sign-in details asks for the current password (5 wrong tries per 15 minutes per account, then locked).
- **Profile visibility:** every read of another member's profile data or photo goes through `CMP_Profiles::can_view( $field, $owner, $viewer )`. Owner always; otherwise both must be full members, and Members visibility → yes, Connections → the `cmp_are_connected` filter (2.3 implements it), Private → no (administrators included). Refuse with the same response as "doesn't exist".
- **Photos** are only ever served by `CMP_Profile_Images::maybe_serve()` (`?cmp_photo=`). Never put a photo in the media library or a public URL.
- **No wp-admin for members:** members never get a link to wp-admin. Account changes belong on the Account tab (`CMP_Account::url()`); `CMP_Account::is_kept_out()` says who is kept out (accounts without `edit_posts`, `cec_manage_events` or `manage_options`; filter `cmp_keep_out_of_dashboard`). With the events plugin active, it already routes non-administrators out of wp-admin except `profile.php`, so this plugin takes over only `profile.php` (and sign-ins aimed at it).
- **CSS:** scope under `.cmp-member-area`, `!important` on the visual properties of every interactive element (same reason as CEC `TECHNICAL_BRIEF.md` §7). Icon + text for every status, never colour alone. Test at 320/375/768/1440 px.
- **Timestamps:** store UTC; display with `wp_date()` in the member's timezone (`CMP_Notifications::user_timezone()`).

## 5. Testing

No live PHP environment is needed: the repo's `tests/` folder has the local WordPress set-up and the end-to-end script used for every release (see `tests/README.md`). Re-run it, plus the CEC month-grid snapshot comparison, before each release.

## 6. Not built yet (by increment)

- 2.1 shipped in 0.2.0 (Account tab) and 0.3.0 (profiles). Viewer-owned private notes on profiles move to 2.4, with the directory that leads to other profiles.
- 2.2 My Calendars: calendars, linked public events, responses + history, private ICS feeds.
- 2.3 Connections, two-sided confirmation, calendar sharing, block and report.
- 2.4 Member directory, own-data export, accessibility/permission test pass.
- Open owner decisions: D3–D8 in the integration plan.
