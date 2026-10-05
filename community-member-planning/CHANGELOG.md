# Changelog — Community Member Planning

Private member area for the Community Events Calendar site (project brief Phases 2–3). Versions follow semver; 0.x releases are the Phase 2 increments from `docs/phase-2-integration-plan.md` (repo root), and 1.0.0 will be Phase 2 complete. Every release: bump `CMP_VERSION` (header + `define`), add an entry here, and bump `CMP_DB_VERSION` if `CMP_Install::schema()` changed.

---

## 0.4.1

**New accounts land in the member area after sign-up**, not the events plugin's Submit an Event page (owner report 2026-10-04, after registering on the live site). There the next steps are confirming the email address and then the profile. Uses CEC 1.27.2's `cec_register_redirect`, and only when a member page is set. The step-by-step profile setup the owner asked for comes in a later release, from a mockup.
- Verified in a local WordPress 7.1.2: `tests/dob-e2e.sh` adds "new account lands in the member area, not Submit an Event". `php -l` clean.

## 0.4.0

**Date of birth at sign-up, and one Show switch per profile field** (owner decisions, 2026-10-04, from an approved mockup). Both override the project brief, which said to store only an 18+ attestation ("not date of birth") and to start every field private.

- **Date of birth is required** (`CMP_Birth_Date`, user meta `cmp_birth_date`).
  - Sign-up: the events plugin's `[cec_register]` form gets Month / Day / Year lists through its new hooks (CEC 1.27.1). No date, an impossible date (30 Feb) or an age under 18 refuses the sign-up before the account exists. The events plugin stores nothing.
  - Existing members: the "Confirm you are 18 or older" checkbox step becomes "One more step: enter your date of birth", asked once. Accounts that only ticked the old box are sent back to it (`CMP_Access::has_attested()` now means "an 18+ date on file and not locked").
  - Under 18 from a signed-in account: the member area is **locked** (`cmp_age_blocked_at`) and says to contact the site team, so a different year can't simply be tried next. Nothing is stored except the lock.
  - Entered once: members can't change it. The site team can correct it, or unlock a locked account, on the wp-admin user screen (administrators only, nonce-checked, audited without the date).
  - Never shown to anyone, never searchable, never in the audit log. Included in "export my data"; "erase my data" removes it but keeps an under-18 lock.
- **Age is calculated** from the date of birth (site timezone) and changes on the member's birthday. The typed age / age range from 0.1–0.3 is gone; any stored typed age is cleared when the date is recorded, keeping its visibility choice.
- **Show switches replace "Who can see this"** on every field and photo.
  - Empty fields have no switch. Filling one in reveals its switch already on (member.js; without JavaScript the hidden switch still posts "on").
  - On = All members (signed-in, verified members only; never the public). Off = Only me. A "My connections" choice made before 0.4.0 is kept while the switch stays off.
  - A field that stays empty keeps its stored choice. New photos start shown.
- **Defaults:** a brand-new account's display name, age and member-since start shown (`set_new_member_defaults()`). Existing members' fields keep exactly what they had, so nothing becomes visible without their say.
- Copy updated: the Profile intro, the age help text, the welcome notification and the step names. The Settings "18+ attestation wording" text is no longer shown to members; its version number is still recorded.
- **Verification**, in a real local WordPress 7.1.2 (PHP 8.4, SQLite) via `tests/`:
  - new `tests/dob-e2e.sh` 33/33: sign-up refusals and success, turning 18 today, new-member defaults, existing member asked once, date not changeable from the member area, typed age cleared, under-18 lock (retry with an adult year stays locked, REST closed), wp-admin correction and unlock, a bad nonce, no-JS "filled field starts shown", switch off, photo switch on for a new photo, birth date never on the profile page, export and erase;
  - `e2e.sh` 59/59 (updated for the date step; adds missing and impossible dates and an audit check);
  - `account-e2e.sh` 64/64;
  - `profile-e2e.sh` 68 passed, with the 12 photo checks not runnable on this Mac (no ImageMagick `convert` to make test images; they failed the same way before this change);
  - `php -l` clean on every file.
  - Checked in the browser on the Hello Elementor theme at 375px: the date step, the profile switches (typing reveals a switch already on) and the sign-up form.
  - Known test-script limit, not a site bug: under Hello Elementor `e2e.sh`'s `restnonce()` picks up another script's nonce first, so its 5 REST checks fail there; they pass on the default theme.

## 0.3.0

**Phase 2 increment 2.1: member profiles**, plus site-wide sign-in controls the owner asked for on 2026-10-04.

- **Profile tab** (Home | Profile | Account), for full members.
  - Every field in the brief's profile field dictionary:
    - display name (edited on the Account tab);
    - about me (500 characters, with a live counter);
    - pronouns (40);
    - location as city, region and country only (80 each);
    - interests (up to 20), roles/capacities (10), orientation/identity (10, plus "Other" in their own words, 80) and looking for (10), all from admin-managed lists;
    - availability (defaults to "Not listed"; never worked out from sign-ins);
    - age, as either an exact 18–120 or one of the six ranges (never a date of birth, never changes by itself);
    - member since (month and year, automatic).
  - **Each item has its own "Who can see this"**: Only me, My connections or All members, and **everything starts as Only me**.
    - Only me means the owner alone; site administrators get no exception.
    - My connections behaves like Only me until connections arrive in 2.3, and the page says so.
    - Nothing is ever shown to signed-out visitors, search engines, public feeds or the public REST API.
  - Validation:
    - Errors are listed at the top with links to each field, and the fields are marked invalid.
    - Nothing is saved until everything is valid, and the member's entries are kept.
    - Unknown choices are ignored.
    - A retired choice a member already has is kept, but isn't offered to anyone new.
  - **"What other members see"** preview of the profile card.
  - Another member's profile opens at `?cmp_member=<ID>` and shows only what that viewer may see. A profile they may not see, or of an account that isn't a full member, looks exactly like one that doesn't exist. The member directory that links to profiles comes in 2.4.
  - "Include in member search" choices are stored per field (default off) but **not offered yet**: they only mean something once the directory exists (2.4), so until then nothing is searchable.
- **Profile photo and cover image**, stored in the database (owner decision, 2026-10-04), never in the media library.
  - Accepts JPEG, PNG, WebP or HEIC up to 5 MB. The browser checks type, size and minimum size (200 × 200; cover 600 × 200) before uploading.
  - Cropping happens in the browser: a square, or 3:1 for the cover. The member drags the photo, uses the arrow keys or zooms with a slider, and sees upload progress.
  - Without JavaScript, or for a HEIC the browser can't open, the server crops from the centre.
  - The server checks the file's actual content (not its name), then decodes and re-encodes every photo as a fixed-size JPEG (512 × 512; cover 1500 × 500). That removes camera data, including GPS, and comments.
  - HEIC is converted by Safari, or by the server if its image library can; otherwise the member is told what to do.
  - "Decorative image" checkbox, or a required description of up to 150 characters. Alt text is never invented.
  - Replace and Remove controls, and a "Who can see this" setting per photo.
  - Photos are served only through `?cmp_photo=<ID>-<avatar|cover>`, which applies the same visibility rule.
    - Anything the viewer may not see gets the same 404 as a missing photo.
    - Responses carry `private, no-cache` plus an ETag, so browsers re-check every time and hiding a photo takes effect at once. They also carry `nosniff` and `noindex`, and `no-store` on refusals.
- **Users → Member Profile Options**, for the new "Manage Member Fields" capability, which administrators get on upgrade (decision D3).
  - Edit the interests, roles/capacities, orientation/identity, availability and looking-for lists.
  - Rename, reorder, stop offering, or add several at once (one per line).
  - Each choice keeps a stable key; choices are never deleted, only retired.
  - Duplicate names, regardless of capitals, are refused.
  - Changes are audited.
- **Notifications and time zone** on the Account tab:
  - The member's own time zone (real place names, grouped by region), used for dates and quiet hours.
  - Per-category switches for notifications. Account and security notices always stay on.
- **Sign-in controls everywhere:**
  - **The site's menus now follow sign-in state** (staging owner report: no way to sign out). Once signed in, the menu link to the sign-in page becomes **Sign Out** and the "Create an Account" link disappears. The calendar managers' "Admin Login" link stays only for managers and administrators. This works with the existing Elementor menu unchanged, because items are recognised by where they link. Any other menu item can be limited with the CSS class `cmp-signed-in-only` or `cmp-signed-out-only`.
  - **New `[cmp_account_bar]` shortcode** for the top right of the header, as the owner asked.
    - Signed out, it shows Log In and Create an Account.
    - Signed in, it shows the profile photo or initial and "Hello, <first name>", with an unread-notification count.
    - Its menu has Member Area, My Profile, Account Settings, Calendar Admin (managers and administrators only), WordPress Dashboard (administrators only) and Sign Out.
    - It works with keyboard and touch without JavaScript, and Escape or clicking elsewhere closes it.
    - Later releases can add items (connections in 2.3) through the `cmp_account_bar_items` filter.
- **Privacy:** the personal-data export now includes the profile, with each item's visibility and photo details. Erasure removes profile values and photos.
- **Audit:**
  - Which profile fields changed, not what they now say, so the log never becomes a second copy of members' details.
  - Every visibility change (old → new).
  - Photo added, replaced or removed, with fingerprints.
  - Preference changes and option-list changes.
- **Database version 2:** new tables `cmp_profile_values` and `cmp_profile_images`, created automatically on update. Uninstall (when opted in) removes them, the option lists and the capability.
- **Verified** in the local WordPress 7.1.2 install:
  - New `tests/profile-e2e.sh`, 76/76. It covers the options admin and its permissions; validation and nothing-saved-on-error; visibility for owner, another member, a connection (through the 2.3 filter), a non-member, a signed-out visitor and an administrator; and photos (no description, fake image, over 5 MB, too small, EXIF/GPS and comment stripping, sizes and shape, the JSON upload path, serving headers, 304, 404s, hiding at once, removal, not in the media library).
  - It also covers preferences and delivery; export and erasure; menu items for signed-out visitors, members and administrators; and the account bar for every kind of visitor, including name escaping.
  - `tests/account-e2e.sh` 64/64, `tests/e2e.sh` 57/57, `tests/updater-e2e.sh` 46/46.
  - Browser at 375/1440 px: no horizontal scrolling, and the cropper works.

## 0.2.0

**Front-end account management: members never need the WordPress dashboard.** On the owner's request (staging, 2026-10-04), the account part of increment 2.1 ships ahead of profiles.

- **New Account tab** on the member area (`?cmp_tab=account`), with Home | Account tabs and a Sign out link. It's open to every signed-in account, including one that hasn't confirmed its email yet, so a mistyped address can be fixed before verifying.
  - **Your details:** first name, last name and display name (required; up to 80 characters each). The username is shown but can't be changed.
  - **Email address:** shows the current address and whether it's confirmed. A change needs the current password. A single-use link goes to the *new* address (256-bit token, only its hash stored, 24-hour expiry, one request per 5 minutes, cancellable), and the address changes only when the link is opened. Opening it also confirms the new address for the member area, so members aren't asked again. WordPress emails the old address about the change. An address another account uses is refused.
  - **Password:** needs the current password; at least 10 characters, not the username or email, typed twice, with a "Show passwords" option. Changing it signs out every other device and keeps this browser signed in, and WordPress emails a confirmation.
  - **Signed-in devices:** shows how many other devices are signed in, with "Sign out everywhere else" and "Sign out".
  - **Your data:** "Email me a copy of my data" and "Ask to delete my account" use WordPress's own privacy requests. The person confirms by email, then an administrator completes the request under Tools → Export / Erase Personal Data.
  - Wrong current passwords are limited to 5 per 15 minutes per account, across the email and password forms.
- **Members are kept out of wp-admin** (new setting under Settings → Member Planning, on by default; needs the member page to be set). Accounts that can't write posts, manage calendar events or administer the site are sent from `wp-admin/profile.php`, or a sign-in aimed at it, to the Account tab, and WordPress's "Edit profile" links point there too. The rest of wp-admin was already closed to them by the events plugin (and by WordPress, which answers other screens with "not allowed"); without the events plugin, this plugin sends them from any wp-admin screen to the member area and hides the admin bar. Editors, calendar managers and administrators are unaffected. Form posts and background requests (`admin-post.php`, `admin-ajax.php`) keep working.
- **The "Wrong address?" link** on the confirm-email step now opens the Account tab instead of wp-admin's profile screen.
- **Privacy exporter and eraser** registered with WordPress. The export includes the member-area access record (verified address, attestation time and wording version) and notifications. Erasure removes notifications and member-area account data; the security audit log is kept, and the erasure is logged.
- **Audit log:** account details changed, email change requested, cancelled or confirmed (as `email_verified` via `email_change`), password changed, other sessions ended, privacy request made, personal data erased.
- Status notices now appear under the tabs.
- **Verified** in the local WordPress 7.1.2 install with Community Events Calendar 1.26.2:
  - New `tests/account-e2e.sh`, 64/64 over real HTTP. It covers signed-out access; the lockout for subscriber, editor, calendar manager and administrator, and with the setting off; every form's validation, nonces, rate and wrong-password limits; email-change tokens (tampered, another account's, reused, cancelled, opened signed out); session handling across devices; the privacy requests; and the exporter and eraser.
  - Existing `tests/e2e.sh`: 57/57.
  - Browser at 320/375/1440 px: no horizontal scrolling, 44 px inputs at 16 px text, and "Show passwords" works.
  - `php -l` and `node --check` pass.

## 0.1.2

- The administrator-only "this page is not set as the member area page" note no longer appears by mistake in Elementor's editor. The editor renders the shortcode outside the normal page request, so the check now also recognises the page ID Elementor passes (`elementor-preview`, `editor_post_id`) and `get_the_ID()`.

## 0.1.1

Fixes from the first run on the staging site (Elementor Cloud, Hello Elementor theme).
- **Looks like the rest of the site.** Headings now use the site's display font (Archivo Black, uppercase, lime; section titles pink), buttons match the calendar's uppercase pill buttons, and cards have the site's pink border. Also fixes an invalid `font:` shorthand on buttons that browsers ignored, which let the theme's button font show through.
- **Styled in the Elementor editor too.** The stylesheet now also loads in Elementor's preview, and in the page `<head>` on the member page so it never flashes unstyled.
- **Signing in no longer risks showing the signed-out page.** Elementor Cloud replaces the member page's `Cache-Control: no-store` with `public, max-age=300` for signed-out visitors (Hosting Check report, 2026-10-04), so a browser could show its stored "Sign in" copy for up to 5 minutes after the visitor signed in. The page now also sends `Vary: Cookie`, and the sign-in link returns to a unique member-page address. Signed-in responses were already uncached (`private, no-store` survives, Cloudflare `DYNAMIC`).

## 0.1.0

**Phase 2 increment 2.0: plugin foundation and the member-area access gate.** Built to the approved plan (decisions D1: plugin name, D2: email verification).

- **Access gate (`CMP_Access`).** A member is an account that is signed in, has verified its *current* email address, and has accepted the 18+ self-attestation, in that order. Checked on the server for the member page and every `cmp/v1` REST route. WordPress user ID is the only identity; no second login.
- **Email verification (`CMP_Email_Verification`).** The site's existing registration logs people in without verifying their address, so the member area sends its own link: 256-bit token from `random_bytes()`, only the SHA-256 hash stored, single use, 7-day expiry, bound to the address it was sent to, resend limited to once per 5 minutes. Works when opened on another device or while signed out. Verification is tied to the address itself, so changing an email requires verifying the new one; any outstanding link for the old address stops working.
- **18+ self-attestation.** Stores only a UTC timestamp and the wording version (never a date of birth or ID). The wording is editable under Settings → Member Planning and ships as clearly marked placeholder text until the owner approves it (decision D8). Changing it creates a new version; members aren't asked again.
- **In-app notifications (`CMP_Notifications`).** Inbox on the member home with mark-one and mark-all-as-read. Quiet hours (10:00 p.m.–8:00 a.m. in the member's timezone, site timezone until members can set their own) hold a notification until 8:00 a.m. Per-category opt-out is stored and enforced (its settings screen comes with profiles). Categories: account, invitation, access_change, assignment, due_reminder, submission, review_decision.
- **Append-only audit log (`CMP_Audit`).** Actor, action, object, old/new value (JSON), reason, UTC timestamp. No update or delete method exists. Logged so far: verification sent, email verified, email changed, age attested, settings changed.
- **REST API `cmp/v1`:** `GET /notifications`, `POST /notifications/{id}/read`, `POST /notifications/read-all`. Every route requires a full member; someone else's notification ID behaves exactly like a missing one (404). All `cmp/v1` responses, errors included, are sent `Cache-Control: private, no-store` and `X-Robots-Tag: noindex`.
- **Member page protection:** the configured page gets no-store headers, `DONOTCACHEPAGE` (honoured by the common page-cache plugins), robots `noindex, nofollow`, and is removed from the WordPress sitemap and site search.
- **Settings → Member Planning:** member page, attestation wording, privacy notice link, and an off-by-default "delete all member data when the plugin is deleted". Deactivating never deletes anything.
- **UI:** step indicator, notices with icon + text (never colour alone), named error states (validation, expired session, rate limit, mail failure, offline, permission denied, server error with retry), 44px buttons, scoped and `!important`-hardened against the theme per the events plugin's rule. Reuses the events plugin's colour variables with fallbacks.
- **Verified in a local WordPress 7.1.2 install** (PHP 8.3, SQLite) with Community Events Calendar 1.26.0, over real HTTP with real logins: 57/57 end-to-end checks covering logged-out, unverified, unattested, member and a second unrelated member (cross-account access, tampered/expired/reused/old-address tokens, rate limiting, nonces, headers, sitemap/search exclusion, quiet hours, audit trail). Deactivation and uninstall (setting off and on) checked; the events plugin's data and output are untouched. Browser check at 320/375/768/1440 px: no horizontal scrolling, mark-as-read works. `php -l` and `node --check` pass; zero PHP warnings in `debug.log`.
