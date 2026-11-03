# Changelog — COL&B Telegram Event Intake

Versions follow semver: PATCH = fixes, MINOR = features.

## 0.2.0

**Secrets can be pasted in Settings, and one call reports the bot's health** (owner, 2026-11-03: "Do settings boxes and fill them on this initial setup"; "I need you to be able to control and troubleshoot the bot").

- **Secrets boxes.**
  - Settings now has boxes for the **Telegram bot token** and the **Claude API key**. A saved value is never shown again: the page says "Saved ✓", and a new paste replaces it. **Remove** clears it.
  - A value that doesn't look right (a token not shaped like `123456789:AAE…`, a key not starting `sk-ant-`) is refused with a clear message.
  - Stored in the `cti_secrets` option, not autoloaded.
  - wp-config.php constants and environment variables still win; Settings then shows "Set in wp-config.php".
- **Webhook secret made automatically.** It's created when the plugin is activated, or on first connect: 64 random characters, made once. Nobody has to type it.
- **`cti_diagnostics()`** (and `wp colandb-intake diagnose`) reports, read-only:
  - the plugin and calendar versions;
  - where each secret comes from (never the value);
  - the settings;
  - what Telegram says about the bot and webhook: address, pending updates, last delivery error;
  - queue counts, the last 10 problems and log entries, and ignored channels.

  This is what a Claude session uses through the site connection to troubleshoot without ever seeing the token.
- The Connection tab shows where each secret comes from.

**Verified** in the local WordPress kit: `tests/telegram-intake-e2e.sh` 73/73. The 10 new checks:
- missing secrets reported;
- webhook secret made once, not autoloaded;
- a wrong token refused;
- token and key saved and used, an empty box keeping the saved value, Remove clearing it;
- the Settings page and diagnostics never containing a value;
- wp-config and environment values winning over Settings.

## 0.1.0

**First version** (owner, 2026-11-03: "a Telegram-to-calendar event intake bot … monitor a specific, administrator-approved list of Telegram channels … submit the results to the COL&B WordPress event calendar for editorial review. Do not publish events publicly without human approval.").

- **Receiving.**
  - An HTTPS webhook (`/wp-json/colandb-intake/v1/telegram`) that requires Telegram's secret-token header, records each update ID once, and keeps only posts from allowlisted channels.
  - Only `channel_post` and `edited_channel_post` updates are used. No user data is stored.
  - Albums are processed as one post.
  - Long polling through WP-CLI, for local development only.
- **Reading.** A replaceable reader (`cti_extractors` filter):
  - **Claude** reads the post text and flyer images through structured output (`claude-opus-5-5`, medium effort, server-side refusal fallback on, raw HTTP because these plugins ship without Composer dependencies). Post text is passed as untrusted data.
  - A **text-pattern** reader works without any outside service.
  - Images are checked by their actual bytes and size before reading.
- **Calendar rules** (`CTI_Normalizer`):
  - only the calendar's own values and existing Event Types;
  - "free" only with explicit wording; no made-up links; no guessed times or timezones; missing years flagged;
  - multi-day events kept as one event;
  - each field keeps its evidence, confidence and source (text or image), and uncertain fields are listed for the editor.
- **Submitting.** Through Community Events Calendar 1.47.0's intake API: **pending only**. Missing or uncertain titles or dates, and past dates, stay in the intake queue ("Needs details").
- **Duplicates.** Scored against every calendar event (published, pending, rejected, past, cancelled) and open intake entries:
  - link, flyer, text, date, title, venue and organizer signals, each documented in the README;
  - a title alone never matches, and another date of a recurring event is a new event;
  - checked under a lock so simultaneous posts can't both go through;
  - an editor links (another source on the same event, nothing overwritten), confirms a separate event, or dismisses.
- **Edits.** Unapproved events are updated except fields an editor has changed (noted). Published or reviewed events are never changed; an editor gets a review item with the differences.
- **Flyers** become the event image by default (owner, 2026-11-03: "Flyers always be used"). A channel can be marked "no".
- **Reliability.** Retries with back-off, failures kept and listed, admin alerts (at most once per 6 hours per problem), hourly submission limit, dry-run mode (on by default), one digest email per run to the editors.
- **Screens and tools.** Events → Telegram Intake (review queue with evidence, decisions, settings, connection) and `wp colandb-intake` commands.

**Verified** in the local WordPress kit:
- `tests/telegram-intake-e2e.sh`: 63/63 checks against a fake Telegram Bot API and a fake Claude API (`tests/mock-intake.php`), with Community Events Calendar 1.47.0;
- calendar regression suites: import 30, ends 18, series 13, recurrence 5, form-cache 36.

**Not yet tried:** a real bot, real channels or the real Claude API (see `TEST_PLAN.md`).
