# Local test kit

Both plugins are tested in a real, throwaway WordPress install (SQLite, so no MySQL or Docker needed; PHP 8.1+ with `pdo_sqlite`, `curl`, `unzip`). This is the "real WordPress" check that `community-events-calendar/TECHNICAL_BRIEF.md` Appendix B says earlier releases never had.

```bash
export WPTEST=/tmp/wptest            # any empty folder outside the repo
bash tests/setup.sh                  # downloads WordPress + WP-CLI, installs both plugins from this repo
(cd $WPTEST/wordpress && php $WPTEST/wp-cli.phar --allow-root eval-file "$PWD/tests/seed.php")   # sample events
bash tests/e2e.sh                    # member plugin: 57 end-to-end checks over real HTTP
```

| File | What it does |
|---|---|
| `setup.sh` | Builds/refreshes the test site and copies the current plugin code into it. Re-run after every code change. |
| `capture-mail.php` | Must-use plugin: writes outgoing email to `wp-content/mail.log` instead of sending it. |
| `seed.php` | Creates sample events through the real wp-admin save path (all admission/location/time modes, multi-day, week-crossing, overlaps, a pending duplicate). |
| `e2e.sh` | Member plugin end-to-end: logged-out, unverified, unattested, member and a second member; tokens, rate limits, nonces, headers, sitemap/search exclusion, quiet hours, audit log. Prints `RESULT: N passed, M failed` and any plugin warnings from `debug.log`. |
| `updater-e2e.sh` + `mock-github.php` | COL&B Plugin Updater: 27 checks against a local stand-in for GitHub (tokens, staging vs live channel, install, background auto-update, rejected zips, token never sent to the storage server). Run with `WPTEST` set, after `setup.sh`. The mock's release list expects member plugin releases 0.1.0/0.2.0; update `mock-github.php` if that test data needs to move. |
| `snap.php` | `wp eval-file tests/snap.php <dir>` saves the public month calendar's HTML for 12 cases. Run it before and after any change to `CEC_Month_Grid` and `diff -r` the two folders: they must be identical. |
| `shots.js` | `node tests/shots.js <outdir> <member-page-id>` (Playwright): screenshots of each member-area state and a horizontal-overflow check at 320/375/768/1440 px. Expects users `unv`, `att`, `mem` (password `pass1234`) in the matching states. |

The test site runs on `http://localhost:8899` via `php -S 127.0.0.1:8899 -t $WPTEST/wordpress` (e2e.sh starts and stops it).
