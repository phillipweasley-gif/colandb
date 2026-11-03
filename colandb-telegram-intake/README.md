# COL&B Telegram Event Intake

Reads event announcements from an **approved list of Telegram channels and groups** (in a group, optionally only certain topics), pulls the details out of the post text and any flyer image, and puts each event in the Community Events Calendar's **review queue**. It never publishes: every event waits for a person to approve it in the usual places (Events list, Calendar Manager dashboard).

- Runs inside WordPress on colandb.com, since the host only runs WordPress. Telegram calls the site's webhook; WP-Cron does the work.
- Needs **Community Events Calendar 1.47.0** or later. That release adds the intake API this plugin uses: `cec_intake_create_event()` and related functions in `community-events-calendar/includes/class-cec-intake-api.php`.
- Starts in **dry-run mode**: posts are read and checked, but nothing is sent to the calendar and no emails go out.

## How it fits together

```
Telegram channel post ──► POST /wp-json/colandb-intake/v1/telegram   (secret header checked)
                              │  update ID recorded (a repeat is ignored)
                              │  channel checked against the allowlist
                              ▼
                        {prefix}cti_posts  (the intake queue)
                              │  WP-Cron every 5 min, or a minute after a new post
                              ▼
          flyer download + checks ─► reader (Claude, or text patterns) ─► calendar rules
                              │
                              ├─ missing/uncertain title or date ──► review queue "Needs details"
                              ├─ looks like an existing event ─────► review queue "Possible duplicate"
                              ├─ dry run ─────────────────────────► logged only
                              └─ otherwise ───────────────────────► cec_intake_create_event() → PENDING event
                                                                     + one email to editors
```

## 1. Create the bot (BotFather)

1. In Telegram, open **@BotFather** and send `/newbot`. Choose a name, e.g. "COL&B Calendar", and a username ending in `bot`.
2. BotFather replies with the **bot token**, e.g. `123456789:AA…`. Treat it like a password: it goes only into the site's configuration (step 3), never into this repository, an email or a chat.
3. Optional, recommended: `/setjoingroups` → **Disable**, so nobody can add the bot to random groups. `/setprivacy` doesn't matter for channels.

The bot uses only the official **Bot API**. No personal Telegram account or MTProto session is involved, and the bot cannot read channels it hasn't been added to.

## 2. Add the bot to each approved channel

For each channel on the approved list, a **channel administrator**:

1. Opens the channel → **Administrators** → **Add Admin** → searches for the bot's username.
2. **Turns off every permission Telegram allows** (post, edit and delete messages, invite users, manage the channel and so on). Telegram only delivers channel posts to bots that are administrators, but the bot never posts, edits, deletes or moderates anything.
3. Saves.

The bot sees new posts and edits from that point on. It does not see older posts, comments in the channel's discussion group, or private chats.

Then add the channel to the **allowlist** (section 4). Posts from a channel the bot is in but which isn't on the list are ignored. Its ID shows under Settings → "Recently ignored", so you can copy it.

## 2b. Groups (0.3.0)

In a group, members post events among other chat, so the bot reads **only the topics you list**.

**In @BotFather:**
1. Send `/setjoingroups`, choose the bot, then tap **Enable**. Otherwise the bot leaves any group it's added to.
2. Send `/setprivacy`, choose the bot, then tap **Disable**. Otherwise a bot that isn't an admin sees only commands, not posts.

**In the group:**
1. Add the bot as a member. It doesn't need to be an admin.
2. If the group has topics, find the topic numbers. In a topic's link `t.me/c/3923571403/275`, the topic is `275`.
3. Settings also lists "Topics skipped in listed groups" once messages arrive, which helps match a number to a topic.

**On the allowlist:**

```
-1003923571403 | yes | COL&B group | topics: 129, 275, 573
```

- The group ID is `-100` followed by the number in its links.
- Add `| admins` to read only the group's administrators.
- **Skipped and not stored:** messages in other topics or in General, service messages and other bots.
- **Never stored:** the sender's name or account.

## 3. Secrets

The plugin needs a **bot token** and a **Claude API key**. It also uses a **webhook secret**, which it makes itself.

**Easiest: paste them in wp-admin.**
1. Go to Events → Telegram Intake → **Settings**.
2. Paste the bot token in **Telegram bot token**.
3. Paste the Claude key in **Claude API key**.
4. Click **Save settings**. Each box then says "Saved ✓". The values are never shown again.

**Or in wp-config.php** (these win over Settings). Add above `/* That's all, stop editing! */`:

```php
define( 'COLANDB_TG_BOT_TOKEN', '123456789:AA…' );                  // from BotFather
define( 'COLANDB_TG_WEBHOOK_SECRET', '…' );                         // optional: openssl rand -hex 32
define( 'COLANDB_INTAKE_ANTHROPIC_KEY', 'sk-ant-…' );               // Claude API key (console.anthropic.com)
```

Environment variables with the same names also work (see `.env.example`). The secrets are never stored in this repository.

**Webhook secret:** 32–256 characters of `A-Z a-z 0-9 _ -` (the output of `openssl rand -hex 32` is fine). Telegram sends it with every delivery in the `X-Telegram-Bot-Api-Secret-Token` header, and the site refuses any request without it.
- **Claude API key:** create one under a workspace with a spend limit, used only for this. If it's missing, use the "Text patterns only" reader: no images, and everything waits for an editor.

**WordPress account:** no application password or extra account is needed, because the plugin runs inside WordPress and calls the calendar's PHP functions directly. Nothing can create events over HTTP: the only public route is the webhook, which only stores posts in the queue, and only with the secret. Optionally create a plain **Subscriber** user ("Telegram intake") and enter its ID as "Author for submitted events" so imported events are easy to filter. It needs no capabilities.

## 4. Settings (Events → Telegram Intake → Settings)

| Setting | What it does | Default |
|---|---|---|
| Approved channels | One per line: `channel ID or @username \| use flyers (yes/no) \| label`, e.g. `-1001234567890 \| yes \| Kink 101`. Prefer the numeric ID (usernames can change). | empty (nothing is read) |
| Dry run | Read and check posts, but send nothing to the calendar and email no one. | **on** |
| Reader | **Claude** (text and flyer images) or **Text patterns only** (no outside service; never submits on its own because its confidence is always low). | Claude |
| Model / Effort | Claude model and effort. | `claude-opus-5-5`, medium |
| Notify | Editors to email; empty means the site admin address. | admin email |
| Largest image | Images bigger than this are not downloaded. | 5 MB |
| Image types | Accepted after checking the actual bytes. | JPEG, PNG, WebP |
| Reader time limit | HTTP timeout for one reader request. | 90 s |
| Tries before giving up | Temporary failures are retried after 5 min, 15 min, 1 h, 3 h, 12 h. | 5 |
| Most events submitted per hour | Over the limit, entries wait and are retried. | 20 |
| Posts per run | How many posts each 5-minute run handles. | 5 |
| Confidence needed | Below this, a title or date keeps the entry in the queue; other fields are flagged for the editor. | 0.6 |
| Duplicate score threshold | See "Duplicates". | 50 |

## 5. Connect the webhook

1. Events → Telegram Intake → **Connection**. Check the secrets show "set" and the calendar shows "ready".
2. Click **Connect webhook**. Telegram is told to send `channel_post`, `edited_channel_post`, `message` and `edited_message` updates (channel posts and group messages) to `https://colandb.com/wp-json/colandb-intake/v1/telegram`, with the secret.
3. Post a test in an approved channel. Within a minute, "Waiting at Telegram" should be 0 and **Last delivery error** empty. A firewall or CDN block would show there.

**If the host can't take webhooks** (Telegram's requests blocked), there is no supported fallback on the live site. Long polling needs a process that keeps running, which WordPress hosting doesn't offer; WP-Cron runs too briefly and too irregularly. Polling is for local development only (next section).

## 6. Running locally

The repository's test kit (`tests/README.md`) is the place to try changes. It has no staging site.

```bash
export WPTEST=/tmp/wptest && bash tests/setup.sh
bash tests/telegram-intake-e2e.sh      # 63 checks against a fake Telegram and a fake Claude
```

With a real test bot and test channel (never the live bot):

```bash
cd $WPTEST/wordpress
export COLANDB_TG_BOT_TOKEN=… COLANDB_INTAKE_ANTHROPIC_KEY=…
W="php $WPTEST/wp-cli.phar --allow-root"
$W plugin activate colandb-telegram-intake
$W colandb-intake status
$W colandb-intake delete-webhook          # polling and a webhook can't be used together
$W colandb-intake poll                    # receives and processes posts until Ctrl-C (--once to stop after one batch)
$W colandb-intake extract --text="Leather social Sat Dec 6, 8-11pm, \$10 at the Toolbox" --image=flyer.jpg
                                          # shows the proposed calendar fields; creates nothing
$W colandb-intake process                 # work through the queue now
$W colandb-intake retry <post-id>         # put a failed post back in the queue
```

## 7. Deploying

1. Merge the pull request; the release workflow publishes `colandb-telegram-intake` and Community Events Calendar 1.47.0 as pre-releases.
2. The owner backs up the site, promotes **Community Events Calendar 1.47.0 first**, then this plugin, and installs both.
3. The first time, upload `dist/colandb-telegram-intake-<version>.zip` under Plugins → Add New → Upload. After that, the COL&B updater offers new versions; the plugin adds itself to the updater's list.
4. Add the secrets (section 3), the channels (section 4) and connect the webhook (section 5), with **dry run on**.
5. Watch the review queue for a few days. When the proposed events look right, turn dry run off.

## 8. Reviewing

**Pending events** appear in Events → All Events and on the Calendar Manager dashboard like any submission. Their preview shows a box, "Imported automatically from a Telegram post", listing:
- every uncertain field and the evidence for it;
- rules that were applied, e.g. "Marked free without clear wording; left as Price not posted";
- the source post links.

Approve, edit or reject as usual.

**Events → Telegram Intake → Review queue** (Administrators) has what could not become a pending event on its own:

| Status | Meaning | What to do |
|---|---|---|
| Needs details | No title or date, uncertain title or date, a date in the past, or the reader declined the post | Fill in the title and dates and click **Create pending event**, or **Dismiss** |
| Possible duplicate | Matches an existing event or another intake item (see below) | **Same event: link this post to it**, **This is a separate event: create pending event**, or **Dismiss** |
| Source edited — review | The post was edited after its event was published, reviewed or deleted | Compare the table, edit the event yourself if needed, **Mark reviewed** |
| Dry run | Would have been submitted | Click **Create pending event** to send it, or turn dry run off |
| Waiting | Over the hourly limit | Nothing; retried automatically |

Each item shows the post, every field with its value, **confidence**, **evidence** (the exact words), and whether it came from the text or the image. Uncertain rows are highlighted. Below is the full history.

**Posts that could not be processed** (failed after all tries) are listed at the bottom with the reason and a **Process this post again** button. Nothing is ever discarded silently; failures also email the site admin (at most once per 6 hours per problem).

## 9. Edited posts

- **The event is still pending or in review:** changed fields are updated, except any field an editor has already changed. Those keep the editor's value, and a note says what the post now says. A note lists what changed.
- **The event is published, scheduled, rejected or deleted, or the post was linked to an existing event:** the event is **not** touched. A "Source edited — review" item shows calendar now, post said, and post now says.
- **The edit removes the event** (e.g. the post is now "cancelled, sorry"): it goes to review as well. The bot never cancels or deletes an event.

## 10. Duplicates

Every new entry is compared, inside a lock so two posts processed together can't both get through, against:
- **every calendar event** except trashed ones: pending, in review, rejected, published, past and cancelled;
- **intake entries** not yet in the calendar.

| Signal | Points |
|---|---|
| Same official, ticket or online link (after removing `utm_*`, `fbclid` and similar, `www.`, `http`/`https`, a trailing `/`) | +60 |
| Same flyer image (identical file) | +40 |
| Post text nearly identical (≥ 70% of words) | +25 |
| Same start date | +30 |
| Date ranges overlap | +25 |
| Start dates one day apart | +10 |
| Title words overlap ≥ 80% (after lower-casing, `&`→and, common abbreviations like "mtg", "nite", "w/" expanded, filler words dropped) | +20 |
| Title words overlap ≥ 50% | +10 |
| Venue/address words overlap ≥ 60% | +15 |
| Organizer words overlap ≥ 60% | +10 |

A candidate is flagged when the score reaches the threshold (50) **and** at least one anchor holds: matching or nearby dates, a matching link, or the same flyer or text. So:
- **a title alone never matches** (20 points at most);
- **another date of a recurring event** (same title, venue and organizer, different date: 45 points, no date anchor) **is treated as a new event**, unless it shares a ticket link or flyer.

Each match lists its reasons ("same event/ticket link; same start date; title matches (100% of words)") and the existing event's status, including "published, cancelled".

**Resolving one:**
- **Link this post to it.** The post is added as another source on the existing event (`_cec_intake_source_url`; several are allowed). Any details where the new post differs are listed as a note. **Nothing on the event is changed**; make corrections yourself, and for a published event that is your review.
- **This is a separate event.** Creates a pending event anyway (e.g. a new date of a recurring event that shared a link).
- **Cancelled match.** You decide whether to reinstate the old event (edit its status) or treat the post as a new occurrence (create).

Telegram's own IDs are unique too: one queue row per channel + message, and an update ID is processed once.

## 11. Flyers, images and attribution

- A flyer and its text sent as **two messages** (by the same person, in the same chat and topic, within 90 seconds) are read as one post, like an album.
- Every flyer (photo, or an image sent as a file) is downloaded, checked and read by the reader:
  - the size is checked before downloading, using Telegram's figure, and again after;
  - the file must really be a JPEG, PNG or WebP, judged from its bytes, not its name;
  - at most 10,000 × 10,000 pixels;
  - at most 2 images per post or album.
- **Flyers are used as the event image by default** (owner's decision, 2026-11-03). A channel line ending in `| no` turns that off for that channel: its flyers are still read, but not copied, and a note points the editor to the source post. Use `no` for any channel that hasn't agreed to share its flyers.
- A copied flyer goes into the Media Library when the pending event is created. Like any upload, its file address is public even before the event is approved.
- **Attribution:** every event keeps its source post in the editorial `_cec_source_url` field and in `_cec_intake_source_url`, and its description ends with "Source: Telegram: \<channel\>" linking to the post. Private channels have no public link (`t.me/c/…` works only for members).
- Logs, emails and screens never show the bot token, the webhook secret, the API key or Telegram file download addresses (which contain the token).

## 12. Calendar rules the reader follows

- Fields map to the calendar's own model:
  - admission: `free_confirmed`, `paid`, `price_varies`, `not_posted`;
  - location: `in_person`, `online`, `hybrid`, `not_posted`;
  - existing Event Type terms only;
  - venues and organizers are text (the editor links Venue and Partner Organization terms).
- **Never free by default:** "free" needs words like *free*, *no cover* or *no charge* in the evidence. A missing or `$0` price becomes "Price not posted".
- **No made-up links:** a link must appear in the post's text or links. A link read from the flyer is kept but flagged.
- **No guessed times or timezones:** with no time, the time shows as "varies" and is flagged. The timezone is set only if stated; otherwise the calendar uses the venue's or the site's.
- **Missing year:** taken as the next occurrence after the post date, and flagged.
- **Multi-day events** stay one event with inclusive start and end dates. The calendar has no session records, so session times, prices or booking links become a note for the editor.
- **Several events in one post** (a weekly roundup) become separate entries, at most five.
- **Untrusted input:** post text and image text are data, never instructions. The reader's instructions say so, and whatever it returns goes through the same rules and the same human approval.

## 13. Troubleshooting

| Symptom | Check |
|---|---|
| Nothing arrives | Connection tab: "Last delivery error". Is the bot an admin of the channel? Is the channel on the allowlist (see "Recently ignored")? Is the webhook connected? |
| 401 in the delivery error | The webhook secret in `wp-config.php` changed; click **Connect webhook** again. |
| Posts stay "received" | Since 0.3.2 each post is processed about 90 seconds after it arrives (the site calls `/colandb-intake/v1/run` on itself). If that's blocked, WP-Cron is the backup: check "Next scheduled run" on the Connection tab, or click **Process the queue now**. |
| Not sure what's wrong | `cti_diagnostics()` (or `wp colandb-intake diagnose`): versions, secret sources, Telegram's view of the webhook, queue counts, recent problems. |
| Posts go to "retry" | The reason column: Claude overloaded (retries by itself), bad API key (fix, then **Process this post again**), calendar plugin inactive. |
| Everything is "Needs details" | The Text patterns reader is selected, or the API key is missing. |
| Duplicates not caught / too many | Duplicate threshold in Settings (higher = fewer flags). |

## Files

| File | What it does |
|---|---|
| `includes/class-cti-receiver.php` | Webhook route, secret check, idempotency, allowlist, album grouping |
| `includes/class-cti-telegram.php` | Bot API client (redacted errors, size-checked downloads) |
| `includes/class-cti-extractors.php` | Reader interface (`cti_extractors` filter), Claude reader, text-pattern reader |
| `includes/class-cti-normalizer.php` | Calendar field rules, evidence and editor notes |
| `includes/class-cti-duplicates.php` | Duplicate scoring |
| `includes/class-cti-processor.php` | Queue, retries, submission, edits, editor actions |
| `includes/class-cti-notify.php` | Editor digest and admin alerts |
| `includes/class-cti-admin.php` | Review queue, settings, connection screen |
| `includes/class-cti-cli.php` | `wp colandb-intake …` |
| `TEST_PLAN.md` | Manual test plan |
