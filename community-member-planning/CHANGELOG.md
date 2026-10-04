# Changelog — Community Member Planning

Private member area for the Community Events Calendar site (project brief Phases 2–3). Versions follow semver; 0.x releases are the Phase 2 increments from `docs/phase-2-integration-plan.md` (repo root), and 1.0.0 will be Phase 2 complete. Every release: bump `CMP_VERSION` (header + `define`), add an entry here, and bump `CMP_DB_VERSION` if `CMP_Install::schema()` changed.

---

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
