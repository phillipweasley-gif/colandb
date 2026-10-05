# Local test kit

Both plugins are tested in a real, throwaway WordPress install (SQLite, so no MySQL or Docker needed; PHP 8.1+ with `pdo_sqlite`, `curl`, `unzip`). This is the "real WordPress" check that `community-events-calendar/TECHNICAL_BRIEF.md` Appendix B says earlier releases never had.

```bash
export WPTEST=/tmp/wptest            # any empty folder outside the repo
bash tests/setup.sh                  # downloads WordPress + WP-CLI, installs both plugins from this repo
(cd $WPTEST/wordpress && php $WPTEST/wp-cli.phar --allow-root eval-file "$PWD/tests/seed.php")   # sample events
bash tests/e2e.sh                    # member plugin: 57 end-to-end checks over real HTTP
bash tests/account-e2e.sh            # member plugin Account tab + dashboard lockout: 64 checks
bash tests/profile-e2e.sh            # member plugin profiles, photos, preferences, menus, account bar: 80 checks
bash tests/dynamics-e2e.sh           # dynamics between members: 34 checks
bash tests/homework-e2e.sh           # homework programs: 44 checks
bash tests/chastity-e2e.sh           # chastity tracking: 55 checks
bash tests/feed-e2e.sh               # member feed: 41 checks
bash tests/messages-e2e.sh           # member messages: 46 checks
bash tests/follows-e2e.sh            # follow / unfollow: 27 checks
bash tests/directory-e2e.sh          # Members search and the profile view: 21 checks
bash tests/nods-e2e.sh               # nods: 20 checks
bash tests/setup-e2e.sh              # profiles v2 + step-by-step setup: 32 checks
bash tests/dob-e2e.sh                # date of birth at sign-up, one-time step, under-18 lock, Show switches: 33 checks
```

| File | What it does |
|---|---|
| `setup.sh` | Builds/refreshes the test site and copies the current plugin code into it. Re-run after every code change. |
| `capture-mail.php` | Must-use plugin: writes outgoing email to `wp-content/mail.log` instead of sending it. |
| `seed.php` | Creates sample events through the real wp-admin save path (all admission/location/time modes, multi-day, week-crossing, overlaps, a pending duplicate). |
| `e2e.sh` | Member plugin end-to-end: logged-out, unverified, unattested, member and a second member; tokens, rate limits, nonces, headers, sitemap/search exclusion, quiet hours, audit log. Prints `RESULT: N passed, M failed` and any plugin warnings from `debug.log`. |
| `account-e2e.sh` | Member plugin Account tab and dashboard lockout: details, email change by link, password, devices, privacy requests, exporter/eraser, who is kept out of wp-admin. Copies the plugin from this repo into the test site first. |
| `dynamics-e2e.sh` | Dynamics (CMP 0.6.0): propose / accept / decline / withdraw / end, who may direct whom, connections, the profile Dynamics card, the 10-pending limit, duplicates, access, export/erase. Empties the dynamics table before and after. |
| `homework-e2e.sh` | Homework (CMP 0.7.0): programs only from the leading side, tasks, member logging (date window, proof types), proof photos (EXIF stripped, private to the pair), lead review, archive when the dynamic ends, access, export/erase. Needs PHP GD. Empties the dynamics and homework tables before and after. |
| `chastity-e2e.sh` | Chastity (CMP 0.8.0): starting a lock (self / keyholder only from a dynamic), keyholder time and rules, hidden timer, verification codes and photo privacy, hygiene, releases, ask to unlock, profile badge, dynamic ending (self-lock), emergency unlock, export/erase. Needs PHP GD. Empties the lock and dynamics tables before and after. |
| `feed-e2e.sh` | Feed (CMP 0.9.0): posting rules (empty, length, audience, photos, event window), who sees what (members / connections / signed out / not yet members), photo privacy and re-encoding, likes, reports (admin email), admin hide, delete, profile posts, dynamic ending, export/erase. Needs PHP GD. Creates and removes two test events and an administrator "mod". |
| `messages-e2e.sh` | Messages (CMP 0.11.0): requests vs. inbox, the 3-message request limit, accepting, connected members, conversation privacy, requests off, the daily limit, deleting, blocking (messages, profiles, feed, dynamics), reports and the admin screen, export/erase. Creates and removes an administrator "mod". |
| `follows-e2e.sh` | Follow (CMP 0.12.0 + CEC 1.28.0): following, the opt-in Going to card (upcoming published RSVPs only), the calendar label, RSVP and post notifications, the Following feed filter, blocks, export/erase. Creates and removes three test events. |
| `directory-e2e.sh` | Members (CMP 0.13.0): who is listed (not self, opted out, blocked, unverified), only shown answers matched, each filter, sort, profile items linking to a filtered list, the cover on profiles, tabs, the opt-out, export/erase. Re-seeds the starter option lists first. |
| `nods-e2e.sh` | Nods (CMP 0.14.0): sending, taking back, the notification and Nods list, mutual nods (both told; messages go to the inbox), pronoun-free wording, the daily limit, blocks, export/erase. |
| `setup-e2e.sh` | Profiles v2 (CMP 0.5.0): starter lists, new field types (height, weight, month, rated kinks), health starting hidden, the six setup steps (one-step saves, resume, skip / later / finish), the home nudge for existing members, and the new profile display. Resets the profile option lists to the starter lists. |
| `dob-e2e.sh` | Date of birth (CMP 0.4.0 + CEC 1.27.1 sign-up hooks): `[cec_register]` refusals and success, existing member asked once, under-18 lock and site-team unlock, calculated age, "a filled-in field starts shown", export/erase. Creates its own `[cec_register]` page and turns on registration. |
| `profile-e2e.sh` | Member profiles: options admin, validation, who-sees-what, photos (upload checks, metadata stripping, permission-checked serving), preferences, export/erasure, sign-in-aware menus and `[cmp_account_bar]`. Starts the test server with 12 MB upload limits; test photos are made with PHP's GD. |
| `updater-e2e.sh` + `mock-github.php` | COL&B Plugin Updater: 27 checks against a local stand-in for GitHub (tokens, staging vs live channel, install, background auto-update, rejected zips, token never sent to the storage server). Run with `WPTEST` set, after `setup.sh`. The mock's release list expects member plugin releases 0.1.0/0.2.0; update `mock-github.php` if that test data needs to move. |
| `snap.php` | `wp eval-file tests/snap.php <dir>` saves the public month calendar's HTML for 12 cases. Run it before and after any change to `CEC_Month_Grid` and `diff -r` the two folders: they must be identical. |
| `shots.js` | `node tests/shots.js <outdir> <member-page-id>` (Playwright): screenshots of each member-area state and a horizontal-overflow check at 320/375/768/1440 px. Expects users `unv`, `att`, `mem` (password `pass1234`) in the matching states. |

The test site runs on `http://localhost:8899` via `php -S 127.0.0.1:8899 -t $WPTEST/wordpress` (e2e.sh starts and stops it).

## Running the kit on a Mac

The scripts assume Linux (GNU) tools. On macOS, two differences break them: `sed -i "expr" file` (BSD sed needs `sed -i '' ...`) in `setup.sh`, and `wc -l` padding its count with spaces, which fails the `[ "$(wc -l < file)" = 1 ]` checks. Without changing the scripts, put two small wrappers ahead of the system ones on `PATH` for the test run:

```bash
mkdir -p /tmp/gnu-shim
printf '#!/bin/bash\nif [ "$1" = "-i" ]; then shift; exec /usr/bin/sed -i "" "$@"; fi\nexec /usr/bin/sed "$@"\n' > /tmp/gnu-shim/sed
printf '#!/bin/bash\n/usr/bin/wc "$@" | sed -E "s/^[[:space:]]+//"\n' > /tmp/gnu-shim/wc
chmod +x /tmp/gnu-shim/*; export PATH=/tmp/gnu-shim:$PATH
```

PHP 8.1+ with `pdo_sqlite` is needed; a standalone build such as static-php-cli's `php-8.4.x-cli-macos-aarch64` works without Homebrew.
