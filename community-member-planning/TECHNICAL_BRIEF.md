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
│   ├── class-cmp-access.php        the gate: logged_out → unverified → unattested → member
│   ├── class-cmp-email-verification.php
│   ├── class-cmp-notifications.php in-app inbox, quiet hours, category opt-out
│   ├── class-cmp-rest.php          cmp/v1 routes + no-store headers
│   └── class-cmp-member-area.php   [cmp_member_area] page, form handlers, page protection
└── assets/{css/member.css, js/member.js}
```

## 3. Data

| Storage | Contents |
|---|---|
| `{prefix}cmp_audit_log` | id, actor_id, action, object_type, object_id, old_value (JSON), new_value (JSON), reason, created_at (UTC). Append-only. |
| `{prefix}cmp_notifications` | id, user_id, category, message, url, created_at, deliver_at (quiet hours), read_at. All UTC. |
| user meta `cmp_verified_email`, `cmp_email_verified_at` | The address that was verified. Verified ⇔ equals the current `user_email` (case-insensitive). |
| user meta `cmp_verify_token_hash` / `_expires` / `_email` | Outstanding verification link (SHA-256 of the token, expiry, address sent to). Cleared on use, expiry or email change. |
| user meta `cmp_age_attested_at`, `cmp_age_attestation_version` | 18+ attestation (UTC timestamp, wording version). Never a date of birth. |
| user meta `cmp_notify_disabled_categories`, `cmp_timezone` | Notification opt-outs; member timezone for quiet hours (UI arrives with profiles). |
| option `cmp_settings` | member_page_id, privacy_notice_url, attestation_text, attestation_version, delete_data_on_delete |
| option `cmp_db_version` | Schema version. Don't delete on a live site. |

Adding a table: add it to `CMP_Install::schema()` and `table_names()`, add it to `uninstall.php`, bump `CMP_DB_VERSION`.

## 4. Conventions

- **Audit:** call `CMP_Audit::log()` for every invitation/confirmation, permission change, profile privacy change, task status/review change, report edit and file upload/removal (brief §5). Updates pass old and new; deletes pass old and a reason.
- **Notifications:** `CMP_Notifications::add( $user_id, $category, $message, $url, $respect_quiet_hours = true )`. Pass `false` only for an immediate response to the member's own action.
- **REST:** new routes go under `cmp/v1` with `permission_callback => array( 'CMP_Rest', 'require_member' )` plus an ownership check in the handler. Return 404 (not 403) for records the user may not see, so IDs can't be probed. `no_store_headers()` already covers the whole namespace.
- **Forms:** `admin-post.php` + `_cmp_nonce` + `check_nonce()`, redirect back with `cmp_notice` (add the message to `notice_html()`).
- **CSS:** scope under `.cmp-member-area`, `!important` on the visual properties of every interactive element (same reason as CEC `TECHNICAL_BRIEF.md` §7). Icon + text for every status, never colour alone. Test at 320/375/768/1440 px.
- **Timestamps:** store UTC; display with `wp_date()` in the member's timezone (`CMP_Notifications::user_timezone()`).

## 5. Testing

No live PHP environment is needed: the repo's `tests/` folder has the local WordPress set-up and the end-to-end script used for every release (see `tests/README.md`). Re-run it, plus the CEC month-grid snapshot comparison, before each release.

## 6. Not built yet (by increment)

- 2.1 Profiles: field dictionary, per-field visibility, age rules, avatar/cover with private delivery, admin option lists, notification preferences UI, member timezone.
- 2.2 My Calendars: calendars, linked public events, responses + history, private ICS feeds.
- 2.3 Connections, two-sided confirmation, calendar sharing, block and report.
- 2.4 Member directory, own-data export, accessibility/permission test pass.
- Open owner decisions: D3–D8 in the integration plan.
