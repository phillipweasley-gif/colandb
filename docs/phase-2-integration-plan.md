# Phase 2 (Member MVP): integration map, migration and rollback plan

**For owner review before any Phase 2 code is written** (required by the project brief, §4 "Storage recommendation").
Prepared 2026-10-03 against Community Events Calendar (CEC) 1.25.1 and the live site colandb.com.

## 1. What exists today

| Item | Finding | Source |
|---|---|---|
| WordPress / PHP | WordPress 7.1.2, PHP 8.5.11, MySQL 8.0 (Google Cloud), environment = `production` | site connection |
| Staging | **None known.** The brief requires staging tests before production. | open question D7 |
| Event plugin | CEC 1.25.1. `cec_event` post type, slug `/event/`, `show_in_rest: true` (public events are already readable at `/wp-json/wp/v2/cec_event`; drafts/pending are not). | `class-cec-post-types.php` |
| Login / registration | CEC's own `[cec_login]` / `[cec_register]` pages (`/log-in-3/`, `/create-an-account-3/`) on top of core WordPress accounts. **Registration logs the user in immediately; no email verification.** | `class-cec-auth.php` |
| Roles | Administrator, core roles, `cec_calendar_manager`. Submitters are plain Subscribers. | `class-cec-roles.php` |
| Integration hooks in CEC | **None.** No public PHP API, actions or filters. | grep of CEC source |
| Month calendar | `CEC_Shortcodes::render_month_html()` runs its own `WP_Query` and renders in one function, so it cannot be reused with member data as is. | `class-cec-shortcodes.php` |
| Caching/CDN, other plugins, MFA | **Unknown.** The site connection cannot list plugins. | open question D6 |

## 2. Integration map

```
 ┌─────────────────────────────┐         read-only, by event ID          ┌──────────────────────────────────┐
 │ Community Events Calendar   │ ◄─────────────────────────────────────── │ Member Planning plugin (new)     │
 │ (owns public events)        │   cec_get_public_event( $id )           │ (owns ALL member data)           │
 │                             │   CEC_Month_Grid::render( $events, … )   │                                  │
 │ public pages, REST, ICS,    │                                          │ profiles, My Calendars,          │
 │ RSS, sitemap, caches        │   ✗ nothing flows the other way          │ responses, connections, sharing, │
 └─────────────────────────────┘                                          │ directory, private ICS, audit    │
                                                                          └──────────────────────────────────┘
```

**Two small, additive CEC changes (released as CEC 1.26.0, separately from the member plugin):**
1. `cec_get_public_event( $id )`: returns the public fields (title, dates, timezone, time mode, place, admission, status incl. Cancelled, permalink) for a **published** event, or `null` for anything else. This is the only way the member plugin reads events. It stores the event ID only, never a copy of title/date/location.
2. Split the month grid into *query* and *render*: `CEC_Month_Grid::render( $month, $year, $events, $options )` takes a prepared list of items. The public calendar keeps its query and calls this; the member calendar passes its own permission-checked items. **Acceptance:** the public calendar's HTML must come out identical before and after the change (checked in the local WordPress test install).

**The new plugin** (working name *Community Member Planning*, prefix `CMP_`) holds Phase 2 now and Phase 3 later, as the brief specifies.
- Its own database tables: profile fields/values/options, calendars, calendar entries, entry notes, responses and response history, connections and settings versions, permission grants, blocks, reports, notifications, audit log, private files.
- Identity = the WordPress user ID only. No second login.
- Access gate: signed in + verified email + 18+ attestation, checked on the server for every page, REST route (`cmp/v1`, with permission callbacks), feed and image request.
- Every member response sends `Cache-Control: private, no-store` and `noindex`. Member pages stay out of sitemaps and the public REST API.
- Profile and cover images are stored outside the public media library and served only through a permission-checked route.

## 3. Delivery increments (each one testable and releasable on its own)

| # | Delivers | Brief section |
|---|---|---|
| 2.0 | CEC 1.26.0 integration API + month-grid split; member plugin skeleton, tables, access gate (email verification + 18+ attestation), audit log, in-app notification inbox | §2 accounts, §5 security |
| 2.1 | Member profile: fixed field dictionary, per-field visibility (Private / Connections / Members), age rules, avatar + cover with crop and private delivery, admin screens for option lists | §2 profile |
| 2.2 | My Calendars: multiple calendars, default, layer toggles, linked public events, responses + history, Attended after event end, private revocable ICS | §2 My Calendars |
| 2.3 | Connections: invite by username/email, per-scope consent, two-sided confirmation, settings versions, revoke; calendar sharing (Viewer/Editor, private notes, response visibility); Block and Report with admin cases | §2 sharing |
| 2.4 | Member directory: opt-in, searchable-field rules, filters, 24/page, sorting; export own data; WCAG and permission test pass | §2 directory, §5 |

Each increment will be tested in a local WordPress 7.1.2 install, signed in as a logged-out visitor, an unrelated member, a blocked member and an authorized member, as the brief requires.

## 4. Data migration

- **No existing data is changed.** The member plugin creates new tables only. CEC 1.26.0 adds functions and changes no stored data.
- Existing WordPress accounts become members once they sign in, verify their email (if not already verified) and accept the 18+ attestation. Profiles start empty, Private, and out of the directory.

## 5. Rollback

- **Member plugin:** deactivating it removes the member area immediately and leaves the public calendar untouched. Tables are kept; they are dropped only by an explicit "delete all member data on uninstall" setting (off by default).
- **CEC 1.26.0:** re-upload 1.25.1. Nothing else depends on the new functions except the member plugin, so deactivate that first.
- Take a database backup before each production install.

## 6. Decisions needed from the owner

| # | Question | Recommendation |
|---|---|---|
| D1 | Plugin name shown in wp-admin. | "Community Member Planning" |
| D2 | Email verification: registration doesn't verify today. | Member plugin emails a verification link and blocks the member area until it's clicked. Existing accounts verify once on first entry. |
| D3 | Admin-managed lists (interests, roles/capacities, orientation/identity, availability, looking-for, connection labels, report categories). | I build the admin screens; you supply the values before member search is switched on. |
| D4 | The brief's generic field builder and spreadsheet import (§4). Not listed in the Phase 2 delivery order. | Phase 2 ships the fixed profile fields with editable option lists; the full field builder and workbook import come after 2.4. |
| D5 | HEIC photos need server support (Imagick with HEIC), which hosts often lack. | Check the host; if unsupported, convert HEIC in the browser before upload. |
| D6 | Hosting details: web server, page cache or CDN, where private files can live outside the web root, which plugins are active, admin MFA. | Please share, or a host/admin contact. Needed before 2.1 (images). |
| D7 | Staging site. | **Decided 2026-10-04: no staging.** A staging site was set up and then retired because it was giving problems; the owner works on colandb.com only. All pre-live testing happens in the local WordPress kit (`tests/`). See `CLAUDE.md` → "Working on the live site". |
| D8 | Wording for the 18+ attestation and member privacy notice (brief: owner approval). | I'll use clearly marked placeholder text until you approve the final wording. |

## 7. Phase 1 items still open (for the record)

From CEC's own `TECHNICAL_BRIEF.md` §9 and the 1.25.1 testing:
- Keyword and admission-status filters are missing from the events filter bar (the brief lists both).
- Linked session records for multi-day events with per-day prices were deferred.
- "Past" labelling and the list/calendar date queries ignore the event's own timezone (can be off by a few hours around the event's end).
- The full manual pass through the five event forms in wp-admin and on the front end hasn't been done.
