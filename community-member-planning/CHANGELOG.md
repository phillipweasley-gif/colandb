# Changelog — Community Member Planning

Private member area for the Community Events Calendar site (project brief Phases 2–3). Versions follow semver; 0.x releases are the Phase 2 increments from `docs/phase-2-integration-plan.md` (repo root), and 1.0.0 will be Phase 2 complete. Every release: bump `CMP_VERSION` (header + `define`), add an entry here, and bump `CMP_DB_VERSION` if `CMP_Install::schema()` changed.

---

## 0.37.0

**A notification for each new star or Locktober medal, and a way to share it** (owner, 2026-10-22: "We also want a notification for when they earn their new star and a way to share that via the social media options we currently provide as an announcement").

- **Notifications** (`CMP_Star_News`, new).
  - When a running lock's run reaches a new star (bronze, emerald, ruby, gold, sapphire, platinum, diamond), the wearer gets "You earned an emerald star: 2 weeks locked! Share your milestone from the Chastity tab." A member keyholder gets "<name> earned an emerald star: 2 weeks locked."
  - A newly completed Locktober sends "You completed Locktober 2025! 🏅".
  - Checked hourly (cron `cmp_cl_stars_check`, cleared on deactivation) and whenever the wearer opens the Chastity tab. What was last announced is kept in user meta (`cmp_cl_star_seen`, `cmp_cl_lt_seen`), so each is announced once.
  - The first check after this update only records where everyone is, so nobody gets a burst of old stars.
- **Share your milestone**, in **Your stars** on the Chastity tab (the notification opens it):
  - **Announce it on the member feed:** editable text, for all members or connections only (`CMP_Feed::announce()`, new).
  - **Or share it outside COL&B**, with the same options the events offer (WhatsApp, Telegram, text message, Facebook, X, email) plus Copy.
  - Text: "I just earned a gold star: 1 month locked! ⭐" (plus #Locktober in October), "I completed Locktober 2025! 🏅 #Locktober", or "My longest lock: …" when not locked.
  - Outside links carry only that text and colandb.com, never a member page, and nothing is shared until the member presses a button. The panel says to think about who will see it.
- No database change.
- **Verification** (local WordPress test kit, PHP 8.3):
  - `tests/chastity-e2e.sh` 217/217 (was 206). New checks:
    - the first check records without announcing;
    - reaching 2 weeks tells the wearer (with a share link) and the keyholder;
    - checking again, or the hourly run, doesn't repeat it; the hourly check is scheduled;
    - a completed Locktober is announced once;
    - the share panel opens from the notification with the text and the WhatsApp, Telegram, Facebook and X links;
    - outside links never include a member page;
    - the Locktober text;
    - posting to the feed with the chosen audience; an unknown audience is refused.
  - `tests/feed-e2e.sh` 41/41. `php -l`.

---

## 0.36.0

**Chastity: longest run with its stars, Locktober, and room for human error** (owner, 2026-10-22: "And what about longest run showing their star? Some people only do it for locktober. Please research Locktober as a lot of the features need to accommodate human error associated with that").

- **What Locktober is** (research): an annual October challenge in the kink and chastity community, locked for the month, alone or with a keyholder. It has no official rules: people set their own, many only take part in October, and slips are common (a late start, a missed check-in, an unlock by mistake or for cleaning, a backup key used). So the features here forgive those instead of resetting someone's progress.
- **Runs, not just locks.** A new lock started within a day of the last one ending continues the same **run** (`CMP_Chastity::runs()`, `RUN_GAP`). Stars on the timer, in "Locks you hold" and on the badges now count the whole run. One mistaken unlock, a long cleaning break, or ending and re-logging a lock no longer resets the stars.
- **Longest run.** A new **Your stars** panel on the Chastity tab shows the wearer's longest run with its stars and dates (and says when it's the current lock), plus their Locktober medals. It's private until they press **Show on my profile** (user meta `cmp_cl_show_stars`). After that, their profile shows "Longest lock: 1 month ★" and the medals, even when they're not locked, for people who only lock in October.
- **Locktober** (`locktober_years()`).
  - Completed when one run covers October 2 at 23:59 through October 31 at 00:00 (site time). That gives a day's grace at each end (locked on the 2nd, or out on Halloween morning), and breaks under a day are already forgiven.
  - Each completed year earns a 🏅 Locktober medal.
  - During October a **Locktober** card shows "Day 15 of 31" with a progress bar and whether the run is on track. If it started after October 2 it still earns stars every day, it just isn't a full Locktober. If you're not locked yet on October 1–2, it points to "I locked earlier".
  - After completion it says "Completed. Well done!" until November 7.
- **"I locked earlier (logging it late)."** Starting or accepting a lock can record a start up to **48 hours** back (`posted_start()`). It can't be in the future or before the last lock ended. The planned end counts from that start, and the history says "logged later".
- **Undo an end made by mistake**, for an hour (`undoable()`, `do_undo_end()`). After Emergency unlock, End lock or the keyholder's Unlock now, the wearer's tab offers **Undo, I'm still locked**. The lock carries on with the same start, the history shows "Ending undone", and the keyholder is told. Only the wearer can undo it, so nobody is put back in a lock without choosing it. Not offered after the site team ends a lock, or once another lock has started.
- **Self-locks catch up.** Without a member keyholder, the wearer can mark a past day of the lock they forgot to check in: "Forgot to check in?", or tap the day on the calendar. It gets its star and shows as "Checked in late (self-reported)". With a member keyholder, only the keyholder can (0.35.0).
- **Privacy:** erase removes the profile-stars setting. No database change.
- **Verification** (local WordPress test kit, PHP 8.3):
  - `tests/chastity-e2e.sh` 206/206 (was 190). New checks:
    - a 6-hour break joins two locks and a 2-day gap doesn't;
    - Locktober counts for 2025 (joined run) and 2022 (Oct 2 evening to Halloween morning), not 2024 (2-day gap) or 2023 (started Oct 3);
    - the longest run is the joined one;
    - Your stars shows the run and medals and is private until shown, then the profile shows them while not locked;
    - "I locked earlier" refuses more than 48 hours back and anything before the last lock ended; 5 hours back records the start, the planned end and "logged later", and joins the run;
    - the self-lock form and calendar links; a self-reported catch-up;
    - undo: offered, another member can't, the wearer can (locked again, history), not after an hour;
    - the Locktober card on October 15 (day 15 of 31) and not in June (a test filter sets the date).
  - `tests/member-admin-e2e.sh` 43/43, `tests/account-e2e.sh` 69/69. `php -l`.

---

## 0.35.1

**Gem stars for the first weeks, and a sapphire at 6 months** (owner, 2026-10-22: "Would it make sense to do week 1 bronze, 2 emerald, 3 ruby, and 4 gold? Also what about a 6 month mark? Can we make that something between gold and platinum?").

- The first weeks of a lock now show one star that changes: **bronze** at 1 week, **emerald** at 2 weeks, **ruby** at 3 weeks. Before this there were 1–3 bronze stars.
- **Gold** stays at 1 month rather than exactly 4 weeks, so every later gold star still lands on the same day of each month.
- **Sapphire** at 6 months sits between gold and platinum: 6 months = 1 sapphire; 7 months = sapphire + 1 gold; 1 year = platinum.
- Platinum (each year) and diamond (every 3 years) are unchanged.
- "Next: …" names the coming star, for example "Next: a ruby star at 3 weeks".
- **Verification** (local WordPress test kit, PHP 8.3): `tests/chastity-e2e.sh` 190/190. The star checks now cover 5 days (none), 8 days (bronze), 15 days (emerald), 22 days (ruby), 35 and 75 days (1 and 2 gold), 186 days (sapphire), 216 days (sapphire + gold), 400 days, 3 years 40 days and 7 years, plus the timer showing an emerald at 20 days with "Next: a ruby star at 3 weeks". I also rendered the colours to check they're distinct on the dark background. `php -l`.

---

## 0.35.0

**Chastity: correct any day of a lock, a star calendar beside Verification, and milestone stars** (owner, 2026-10-22: "When going back to correct a date it only seems to go back a week … It is vital that Keyholders have access to a calendar to select a specific date for correction. Also, under verification it does not organize them by date … Even better a visual guide on the right hand side such as a calendar with gold stars … We should also allow someone to earn stars based on how long they have been in chastity").

- **Correct any day.**
  - The keyholder's "Verified elsewhere" (now **Mark a day verified**) used to offer only today and the 6 days before. It now takes any day from the lock's first day to today, through a date picker, or by tapping a day on the calendar.
  - The form says it's also for a day missed by mistake.
  - A day before the lock started or in the future is refused. A day with a photo waiting for review still can't be marked (accept the photo instead), and the wearer still can't mark days.
- **In date order.** The lock history (and so the keyholder's latest photos and the data export) is sorted by the day things happened (`created_at`), not by when they were entered. A back-dated correction now sits on its own day instead of at the top.
- **A calendar of stars beside Verification**, on the right on wide screens and below on phones, for the wearer and the keyholder:
  - a **gold star** on each day that counts: an accepted photo, a "verified elsewhere" mark, or, without a member keyholder, any photo not sent back;
  - an **outline star** while a photo waits for review;
  - a dashed box on a locked day with nothing;
  - month arrows covering only the lock's months (`?cmp_cm=YYYY-MM`), and "18 of 22 days verified".
  - For the keyholder of a running lock, each day without a star is a link that opens Mark a day verified with that date filled in.
  - While a lock runs, this calendar replaces the old monthly one on the wearer's tab, which stays for the months without a lock.
- **Milestone stars** for one lock's unbroken time.
  - Research: loyalty programs usually climb bronze → silver → gold → platinum → diamond, with 3–5 levels. I used the owner's steps on that ladder:
    - a bronze star for each of the first 3 weeks;
    - a gold star for each month;
    - a platinum star for each year;
    - a diamond for every 3 years (4 years = 1 diamond + 1 platinum).
  - Shown under the timer, with "Next: a gold star at 2 months, on Nov 4". Also next to each lock under "Locks you hold" and "Past locks", and on the profile badges (wearer and keyholder) when the wearer has their badge on.
  - Each set of stars has a text label for screen readers ("Stars: 2 months locked").
- No database change.
- **Verification** (local WordPress test kit, PHP 8.3):
  - `tests/chastity-e2e.sh` 190/190 (was 176). New checks:
    - the date picker runs from the lock's first day to today;
    - the keyholder's calendar sits beside Verification, and missed days two weeks back link to the correction;
    - tapping a day opens the form with that date;
    - a day 14 days back is recorded on that day; before the start and future days are refused; the wearer can't mark days;
    - history is in date order, with the correction not on top;
    - the corrected day gets a gold star, with the "days verified" count;
    - months outside the lock are clamped; the wearer sees the calendar without links;
    - stars at 5 days (none), 8 days (1 bronze), 22 days (3 bronze), 35 days (1 gold), 75 days (2 gold), 400 days (1 platinum + 1 gold), 3 years 40 days (1 diamond + 1 gold), 7 years (2 diamonds + 1 platinum);
    - the timer shows 2 bronze stars at 20 days and "Next: a bronze star at 3 weeks".
  - Earlier checks updated for the date picker and the new calendar.
  - Screenshots in Chromium at 1280 px and 390 px (the calendar sits beside Verification, then stacks below it on a phone). `php -l`.

---

## 0.34.1

**"Need help?" passes only the page path, never the query string.** I found this checking 0.34.0 on colandb.com. The host caches whole pages, header included, and ignores some query strings: `/events/?a=…` was served with the "Need help?" link built for an earlier request (`?from=%2Fevents%2F%3Fr%3D…`). So a query string from one visitor could be shown to others, and query strings can carry private things, like an emailed edit or unsubscribe link's token.
- The 0.34.0 filter only removed a few known names. Now the link carries only the path (`/events/`), and nothing on the home page.
- **Verification:** `tests/feedback-e2e.sh` 20/20. The link check now runs with pretty permalinks and a page opened with `?guest=SECRET&x=1`: the link carries the encoded path only, no `SECRET` and no `x=1`. `php -l`.

---

## 0.34.0

**"Need help?" under the account box** (owner, 2026-10-21: "I also want a 'Need Help?' link below the member box in the upper right hand corner for people to submit feedback or report issues").

- **The link.** `[cmp_account_bar]` (the header's "Log In / Create an Account" or "Hello, <name>" box) now has a small underlined **Need help?** link under it, signed in or out.
  - It opens the page holding the new `[cmp_feedback]` form and passes along the page the visitor was on (`?from=`). (0.34.1: the path only.)
  - The link doesn't appear until a published page has the shortcode.
- **The form** (`CMP_Feedback`, new; table `cmp_feedback`, `CMP_DB_VERSION` 21):
  - What it's about: Something isn't working / I have a question / Feedback or an idea.
  - The message, and the page (prefilled from the link).
  - Visitors give an email (and optionally a name). Signed-in members aren't asked: their account email is used, and a posted email is ignored.
  - For "Something isn't working" the browser's user agent is kept, to help fix it. The form says so.
  - Spam limits as on the image report form: honeypot, signed timestamp (3 seconds to 8 days), 5 a day per address or email. The page is never cached (`no-store`, no CDN hint).
- **Delivery:**
  - each message is emailed to the site's admin address with `Reply-To:` the sender, so replying answers them;
  - the sender gets a copy;
  - **Users → Help requests** (with a count) lists them; **Mark done** can email a reply.
- **Privacy:**
  - kept 2 years (`cmp_retention_periods['feedback']`);
  - export and erase by email, for members and visitors alike.
  - Also fixed: an erase request's "items removed" flag now includes everything removed before the notifications step.
- **Verification** (local WordPress test kit, PHP 8.3):
  - `tests/feedback-e2e.sh` 20/20 (new). It covers:
    - the link signed out and signed in, the back-link without tokens, no back-link on the form page itself;
    - the form's fields and the prefilled page; never cached;
    - too fast, honeypot and bad email refused; a valid message stored with the browser for problems only;
    - the team email with Reply-To, and the sender's copy; the thank-you;
    - a member's account email used instead of a posted one; the daily limit;
    - the admin list, a non-admin unable to close, a reply emailed;
    - export, erase and the 2-year clean-up.
  - Regression suites: `tests/profile-e2e.sh` 84/84 (it covers the account bar), `tests/account-e2e.sh` 69/69, `tests/retention-e2e.sh` 17/17. `php -l` on every changed file; no plugin warnings in `debug.log`.

---

## 0.33.0

**Optional end-to-end encryption for chastity photos** (owner, 2026-10-21: encryption so nobody but the wearer and keyholder can see the photos; chose both server-side and end-to-end, then "start the end-to-end encryption … until completed"). Design: `docs/privacy-security-plan.md` §7.

- **What it does.** A member can turn on **End-to-end photos** on the Chastity tab.
  - Their verification photos are then encrypted in their own browser, for themselves and their keyholder, before being sent.
  - The site stores and serves data it can't decrypt. Someone holding the database, `wp-config.php` and the backups still can't see those photos.
  - Server-side encryption (0.31.0) still applies on top.
- **Keys** (`CMP_E2E`, new; `assets/js/e2e-photos.js`, new):
  - Each member's browser makes an ECDH P-256 key pair.
  - The private key is wrapped with AES-256-GCM under a key from their **photo passphrase** (PBKDF2-SHA-256, 600,000 rounds, random salt), and only that box is stored (`cmp_e2e_box`), with the public key (`cmp_e2e_pub`).
  - Passphrase fields have no `name`, so a passphrase is never posted, even without JavaScript.
  - Unlocking keeps the key in that browser's IndexedDB as a non-extractable `CryptoKey`. **Forget on this device** clears it.
  - **Change photo passphrase** re-boxes the same key.
  - **Forgot your passphrase?** removes the key; there's no recovery, and the UI says so.
- **Photos:**
  - the browser re-saves the photo through a canvas (drops EXIF/GPS, max 1600 px, JPEG 0.82);
  - it encrypts it with a random AES-256-GCM key (with associated data `cmp-e2e-v1`);
  - it wraps that key for the wearer and, if they've set up a key, the member keyholder (ephemeral ECDH + HKDF-SHA-256 + AES-GCM).
  - Envelope: `CMPE2E1` + header length + JSON header + ciphertext.
- **Server checks:**
  - `CMP_E2E::validate()` accepts only a well-formed envelope: sizes match P-256 and AES-GCM, it includes the wearer, recipients are only the lock's wearer and keyholder, and it's no bigger than 8 MB.
  - It's stored with `photo_e2e = 1` (`CMP_DB_VERSION` 20) and sealed by the vault as well.
  - While end-to-end is on, an unencrypted or malformed upload is refused ("Nothing was sent…"), so a broken script never silently downgrades. The optional photo when starting or accepting a lock is hidden (send it as a verification).
- **Seeing photos:**
  - Authorised viewers (wearer, current keyholder) get the envelope as `application/octet-stream`. Their browser decrypts it and shows it from a `blob:` URL, in Your photos and the keyholder's review.
  - Download decrypts in the browser.
  - A keyholder without a key sees "wasn't encrypted for you". The wearer is warned before sending if the keyholder hasn't set up a key.
- **Share links** for end-to-end photos:
  - The wearer's browser puts the photo's key in the link after `#k=`; browsers never send that part to a server.
  - The share page allows one inline script (CSP nonce, `img-src blob: data:`), which decrypts after **View photo** and then removes the key from the address bar.
  - Without the `#k=` part, the page explains and doesn't offer View, so the link isn't used up.
- **Privacy:** export says whether a key is set up; erase removes it.
- **Verification** (local WordPress test kit, PHP 8.3, plus real Chromium through Playwright):
  - `tests/e2e-photos-e2e.sh` 33/33 (new). It covers:
    - setup with in-page validation, passphrase fields never posted, and the box not containing the passphrase;
    - the keyholder's setup, and the verify form encrypting for both;
    - the stored envelope (also sealed; no JPEG or GPS bytes; exactly two recipients);
    - the wearer and keyholder seeing it decrypted; 1600 px; Download giving a clean JPEG;
    - Forget on this device; a wrong passphrase refused and the right one working;
    - another member getting 404; authorised viewers getting only the envelope;
    - unencrypted and malformed uploads refused;
    - share links: the key in the fragment, never stored; View decrypting in a fresh browser and removing the key from the URL; a link without its key explaining instead of offering View; a wrong key failing; the CSP nonce;
    - passphrase change (old fails on a new device, new works); turning it off.
  - Found and fixed while testing: the upload posted to `form.action`, which a hidden field named "action" hides; it now uses the form's `action` attribute.
  - Regression suites: `tests/chastity-e2e.sh` 176/176, `tests/image-report-e2e.sh` 24/24 (its test now clears a homework row an earlier run could leave behind), `tests/member-admin-e2e.sh` 43/43, `tests/account-e2e.sh` 69/69. `php -l` on every changed file; no plugin warnings in `debug.log`.

---

## 0.32.1

**The image report page is never cached.** I found this on colandb.com straight after 0.32.0 went live: the host's CDN (Elementor Hosting) cached `/report-an-image/` for up to 7 days (`s-maxage=604800`, `ec-cdn-status: hit`).
- The form carries a signed timestamp, and 0.32.0 refused one older than a day. So a day after caching, every real report would have been refused.
- A cached page could also show one visitor's "sent" message to the next.
- Fix:
  - Any page with `[cmp_image_report]` now sends `Cache-Control: no-store`, sets `DONOTCACHEPAGE`, and removes the CDN's `ec-cdn-cache-control` hint.
  - As a fallback, a form timestamp is accepted for up to 8 days. Spam protection still has the honeypot, the 3-second minimum and the daily limits.
- **Verification:** `tests/image-report-e2e.sh` 24/24. New checks: the report page has no-store and no CDN hint while another page keeps the hint (a test mu-plugin adds the host's header); a form opened 3 days ago still sends. `php -l`.

---

## 0.32.0

**Consent before intimate photos; homework photos encrypted and deleted after 90 days; members download and delete their own photos; a public way to report an intimate image** (owner, 2026-10-21: "start the end-to-end encryption and build a plan/address the other concerns until completed". Plan: `docs/privacy-security-plan.md`. End-to-end encryption is the next release.)

- **Consent** (`CMP_Photo_Consent`, new).
  - Before a member's first chastity or homework photo, the form shows a tick box: what's kept, who sees it, that it's encrypted and deleted after 90 days, and that they can delete it sooner. It links to the privacy statement.
  - The server refuses a photo without it ("Nothing was saved…"), then records the time (user meta `cmp_photo_consent_at`) and an audit-log row (`photo_consent_given`). It isn't asked again.
  - Why: intimate photos are special-category data under GDPR and "sensitive data" under several US state laws, which ask for opt-in consent.
  - Included in the data export; removed on erase.
- **Homework proof photos**, now treated like chastity photos:
  - encrypted at rest (`CMP_Photo_Vault`, new context `hwproof`, bound to the program and member). Older ones are encrypted on upgrade (`CMP_DB_VERSION` 19) and by the daily clean-up.
  - deleted 90 days after the logged day (`cmp_retention_periods['homework_photos']`). The log entry and its review stay, and say "Photo removed after 90 days."
- **Members control their own photos.**
  - Chastity "Your photos": **Download** (sent as a file) and **Delete photo** (confirm). Deleting stops its share links; the history says "photo deleted" and the keyholder sees "Photo deleted."
  - Homework: under the day's log form the member sees "kept, encrypted, until <date>" with **View**, **Download** and **Delete photo**. The lead can't delete it.
  - The export points to these downloads, because WordPress's exporter only carries text.
- **Report an intimate image** (`CMP_Image_Reports`, new table `cmp_image_reports`).
  - What it's for: the TAKE IT DOWN Act asks sites that host what users post to offer a clear reporting process and act on valid reports within 48 hours.
  - `[cmp_image_report]` is a public form; no account needed. It asks:
    - who the image shows (me, someone I'm reporting for, or possibly someone under 18);
    - where it is, and optional details;
    - email and optional name;
    - a good-faith statement, and a typed full name as the signature.
  - Spam protection: a honeypot field, a signed timestamp (at least 3 seconds on the page) and 5 reports a day per address or email.
  - On a report:
    - the site's admin email gets an urgent message with the 48-hour deadline;
    - the reporter gets a confirmation;
    - a possible-minor report gets its own subject line and "don't open, download or forward it; report it to the NCMEC CyberTipline" guidance.
  - **Users → Image reports:**
    - lists reports with time left (red under 12 hours, or overdue), and an admin notice while any are waiting;
    - **Removed** / **Not actioned** with a note, optionally emailed to the reporter;
    - **Remove a private photo**: paste a chastity, homework, share, feed or profile photo link and the stored photo is deleted without being shown. It's audit-logged and noted on the report.
  - Retention: reports are kept 3 years (`image_reports`), not erased on request (safety and legal record). The data export by email lists them, for reporters without accounts too.
- **Verification** (local WordPress test kit, PHP 8.3, SQLite):
  - `tests/image-report-e2e.sh` 22/22 (new): form fields; too fast, honeypot, tampered timestamp, no signature or no statement all refused with nothing stored; a valid report is stored and both emails sent; possible-minor wording; the daily limit; the admin list, time left, notice; a non-admin can't close; closing emails the reporter with the note; removing by share, chastity, homework and feed links (and nothing on a second try); audit and report notes; export by email; the 3-year clean-up.
  - `tests/chastity-e2e.sh`: new checks for the consent box, a photo refused without it, Download as an attachment, Download and Delete in Your photos, the keyholder unable to delete, and the wearer's delete (404, its links gone, others kept, "photo deleted" in history).
  - `tests/homework-e2e.sh`: new checks for the consent box shown once and recorded once; encryption bound to program and member; Download; View/Download/Delete for the member; the lead can't delete; the member's delete; the clean-up removing a 95-day-old photo and encrypting an older plain one; the removed/deleted wording; export and erase.
  - `tests/chastity-e2e.sh` 176/176, `tests/homework-e2e.sh` 55/55, `tests/retention-e2e.sh` 17/17, `tests/account-e2e.sh` 69/69, `tests/feed-e2e.sh` 41/41, `tests/profile-e2e.sh` 84/84, `tests/member-admin-e2e.sh` 43/43. `php -l` on every changed file; no plugin warnings in `debug.log`.
- **After this is live:** add a page with `[cmp_image_report]` and link it from the Privacy Policy, the Help & FAQ and the footer.

---

## 0.31.1

**Private photos never carry the host's "cache publicly" hint.** Checking 0.31.0 on colandb.com showed that the host's CDN (Elementor Hosting) adds its own header, `ec-cdn-cache-control: public, s-maxage=604800`, to every page, including private photo responses. The CDN does cache 200 pages with query strings, as a test on the public privacy page showed.

- Our responses already send `Cache-Control: no-store`. Signed-in requests (which every photo except a share link needs) aren't cached, and the photo behind a share link is only sent on a POST, which the CDN never caches. So no photo was exposed.
- To be safe anyway, every private image response now removes that header before sending: chastity photos, share-link pages, homework proof, feed photos and profile photos.
- **Verification** (local WordPress test kit, PHP 8.3):
  - `tests/chastity-e2e.sh` 170/170. The new check adds the same header the host does (a test mu-plugin) and confirms that a normal page keeps it, while the share page, the photo view and the wearer's photo do not.
  - `tests/homework-e2e.sh` 44/44, `tests/feed-e2e.sh` 41/41, `tests/profile-e2e.sh` 84/84.
  - `php -l` on the changed files.

---

## 0.31.0

**Chastity photos: encrypted, deleted after 90 days, and private share links** (owner, 2026-10-21: "Can we update it to purge every quarter and make sure that no one other than the keyholder or the caged person can see them via encryption tied to their username? Also, can the one time code be shared by the caged person if they want to show someone their photo without that person officially being a keyholder or account member?"; chose both server-side and optional end-to-end encryption. This release is the server-side part; end-to-end comes next).

- **Encrypted at rest** (`CMP_Photo_Vault`, new). Every verification and lock photo is sealed with XChaCha20-Poly1305 (libsodium; WordPress's sodium_compat if the extension is missing) before it's stored.
  - Each wearer's lock has its own key, derived from a secret in `wp-config.php` (`CMP_PHOTO_KEY` if defined, otherwise the `AUTH_KEY` and `SECURE_AUTH_KEY` salts) plus the lock id and the wearer's user id. The lock and wearer are also bound in as authenticated data, so a photo copied to another lock or member doesn't open.
  - The key is never in the database, so a copy of the database or a database backup on its own can't show any photo.
  - Who can see a photo is unchanged: the wearer, and their keyholder while the dynamic allows it. Admins have no screen that shows them.
  - Photos stored before this version are encrypted when the database upgrade runs (`CMP_DB_VERSION` 18), and the daily clean-up catches any left over. Live had none when this was written.
  - Limit, said plainly in the privacy statement: the site holds the keys so it can show the photo to the wearer and keyholder; it's not end-to-end. If the wp-config secrets are ever changed, older photos can no longer be opened (they're shown as not found and purged on schedule).
- **Deleted after 90 days.** The daily clean-up (`CMP_Retention`, period `lock_photos`, changeable with `cmp_retention_periods`) removes each photo 90 days after it was taken. The history entry stays and a verification still counts; it now reads "… · photo removed after 90 days", and the keyholder's review list says so. Links to it stop working.
- **Private share links** (`CMP_Photo_Shares`, new table `cmp_photo_shares`). The daily code is only for writing on paper in the photo; it opens nothing. To show a photo to someone without an account, the wearer makes a link for that one photo.
  - Only the wearer can make one, not the keyholder or anyone else. Choices: stops working after 1 hour, 1 day or 7 days; "Works once" (on by default). Up to 20 working links.
  - The wearer can copy the link again later, sees how many times it was viewed, and can **Turn off link** at any time. They get a notification the first time a link is opened.
  - Opening the link shows a page with an adults-only warning and a **View photo** button; the photo is only sent after that button. Chat apps that fetch a link to make a preview (Telegram, iMessage, Slack …) only ever see the first page, so they neither show the photo nor use up a one-time link.
  - The photo page sends `Cache-Control: no-store`, `noindex`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY` and a Content-Security-Policy that allows only the inline image. The photo is inside the page, not at a URL of its own.
  - No token is stored: the link is the share id plus a keyed hash of it (site secret), so the database alone can't produce working links.
  - Links stop when they expire, are used (once), are turned off, when the photo is purged, or when the lock or account is deleted. Rows are cleared a day after they stop working.
- **Chastity tab:** a new **Your photos** panel for the wearer (closed by default, so nothing shows the moment the tab opens): each photo with its deletion date, its working links, and **Share privately**. It covers the wearer's past locks too, so they can still turn links off after a lock ends.
- **Privacy:** export lists share links (when made, expiry, views; not the link itself) and says each kept photo is "kept, encrypted, until <date>". Erase removes the wearer's share links with their locks.
- **Verification** (local WordPress test kit, Linux, PHP 8.3, SQLite):
  - `tests/chastity-e2e.sh` 169/169 (was 134), `tests/member-admin-e2e.sh` 43/43, `tests/retention-e2e.sh` 17/17. New: the stored photo is `CMPv1:` with no JPEG bytes; it doesn't open as another wearer or lock, or when tampered with; adding `CMP_PHOTO_KEY` later still opens older photos while new ones use the new key; an older plain photo is served, then encrypted by `seal_existing()` and served identically; the wearer sees Your photos (closed), deletion dates and the share form; another member and the keyholder can't make a link; only the offered expiry lengths; a 1-hour one-time link is stored right with no token in the database; the wearer sees it to copy with views and Turn off; opening it with no account shows the View button and not the photo, with noindex / no-store / no-referrer / CSP / no-framing headers; GETs don't use up a one-time link; a wrong token is a 404; View photo returns the exact JPEG inside the page, counts the view and notifies the wearer; a once link fails the second time; a 1-day link can be viewed twice; another member can't turn it off, the wearer can (404 at once); a 7-day link stops when expired; used/off/expired links leave the list; export lists links without the URL and shows "kept, encrypted, until"; the daily code opens nothing; the clean-up removes a 91-day-old photo and only that one, the wearer gets a 404, its links stop and are cleared, the history keeps the entry marked "photo removed after 90 days"; the period can be changed by filter; erase removes the share links.
  - Found and fixed while testing: the new panel's image alt text first used the history wording, which would have shown the planned length to a wearer whose timer is hidden. It now says "Verification photo" / "Photo of the lock".
  - `php -l` on every changed file. No plugin warnings in `debug.log`.
- **Live site:** checked before release that colandb.com runs PHP 8.5 with libsodium (XChaCha20 available) and that `AUTH_KEY`/`SECURE_AUTH_KEY` are set. Recommended: add a long random `CMP_PHOTO_KEY` (32+ characters) to `wp-config.php`. Adding it later is safe (older photos still open with the salts), and afterwards a salt reset (some security plugins do this to log everyone out) no longer affects photos.

---

## 0.30.0

**Chastity: hand a running lock to a keyholder, and let the keyholder set its end date** (owner, 2026-10-21: "if someone self locks because their keyholder isn't on the website yet and the lock has already been established there's no way to change the date. We need a keyholder able to change the date and be assigned to a lock that a sub already has established.").

- Before, a lock started on your own (a self-lock, or a keyholder typed by name because they had no account) stayed that way: once the keyholder joined COL&B there was no way to give them that lock, so nobody could change its end date. Only the site team could (Users → Member Dynamics & Locks, 0.26.0/0.27.0).
- **Wearer, on their running lock's Settings:** a new "Give your keyholder this lock" part. Once they've agreed chastity in a dynamic with the keyholder holding the key, they choose the keyholder and press **Hand over the key** (with a confirm).
  - Only the wearer can do this, so nobody can take over someone's lock without the wearer choosing it. The keyholder can't claim it, and if they try to set up a new lock for someone already locked they're now told the wearer can hand it over from their lock's Settings.
  - It's the same lock: start date, end date and history are kept, the typed name is cleared. From then on it's an ordinary keyholder lock: the keyholder gets every control (time, end date, rules, hidden timer, verification), the wearer asks to be unlocked, and Emergency unlock stays available.
  - Recorded as a new history event `keyholder_set` ("Lock handed to a keyholder"), an audit-log row (`lock_keyholder_set`, noting only whether there was a typed name, not the name), and a notification to the keyholder.
  - Without a keyholder in a dynamic, the part explains how to get there instead of showing a form.
- **Keyholder, under Time:** besides Add time / Remove time, **Set the end date** takes a date and time (site time) and **Make it open-ended** clears it. Applies to every keyholder lock, not just handed-over ones. The date must be in the future (and within 5 years). Recorded as history event `end_set`; the wearer is notified unless the time left is hidden from them, and then it's also left out of their history, like time changes.
- No database change (`CMP_DB_VERSION` unchanged).
- **Verification** (local WordPress test kit, Linux, PHP 8.3):
  - `tests/chastity-e2e.sh` 134/134 (was 115). New: without a dynamic the wearer sees the explanation and no form, and handing over is refused; the keyholder can't take the lock, nor can another member; the wearer is offered their keyholder; handing over keeps the lock, its start date and status and clears the typed name; keyholder notified, history and audit recorded (name not in the audit); a lock that already has a keyholder can't be handed over; the wearer then sees a keyholder lock; the keyholder sees Set the end date; setting it stores the right UTC time and tells the wearer, and shows in their history; past and impossible dates refused; the wearer can't set it; open-ended works and tells the wearer; with the timer hidden the wearer isn't told and it's not in their history; Emergency unlock still ends it. The "no proposal while they're already locked" check now expects the new notice.
  - `tests/member-admin-e2e.sh` 43/43.
  - `php -l` on the changed file. No plugin warnings in `debug.log`.
- **After this is live:** the Help & FAQ Chastity answers can mention that a self-lock can be handed to a keyholder later, and that keyholders can set an end date (that page is edited on the site).

---

## 0.29.0

**Profile badges show when the lock was last verified, and how** (owner, 2026-10-13: "There should be a Lock last verified message with the date and how on the member's profile"; "Being locked and showing it off/proof is very important in chastity"; chose: part of the badge, method + where).

- The wearer's badge ("Locked · N days" / "Locked by … · N days") and the keyholder's ("Holds the key for … · N days") add **"· last verified today · photo"** or **"· last verified Oct 13 · elsewhere (Snapchat)"** once there's proof. Nothing is added until the lock has been verified.
  - What counts as proof: with a keyholder on COL&B, a photo they **accepted** or a day they marked "verified elsewhere" (0.28.0); an unreviewed photo isn't proof yet. Without one (self-lock, or a keyholder with no account), any photo the wearer sent that wasn't sent back.
  - "Where" is the keyholder's note on a "verified elsewhere" mark, cut to 40 characters. Photos themselves are never shown.
  - Part of the badge, as the owner chose: it shows whenever the wearer's badge is on. The badge switches now say so ("Show "Locked · N days · last verified" on my profile"), on the Chastity tab, when accepting a keyholder's lock, and on Users → Member Dynamics & Locks.
- `docs/privacy-statement.md`: the "Locked" badge row says what the badge now shows.
- **Tests:** `tests/chastity-e2e.sh` 115/115. New checks: both badges show "last verified today · elsewhere (Telegram)"; an unaccepted photo isn't proof with a keyholder; on a self-lock it is; an accepted photo shows "photo"; the photo is never on the badge; never verified shows nothing.
  - Also fixes the two badge checks that failed on macOS since 0.25.0 ("badge shown once the wearer turns it on…", "keyholder's profile: Holds the key…"). The site was fine: `IFS='|' read … <<< "$(lk …)"` leaks the `|` into the command inside on macOS's bash 3.2, so the test's rules save was sent blank and refused. The line now reads into a variable first; same result on Linux.
- `php -l` on the changed files; no plugin warnings in `debug.log`. No database change.
- **After this is live:** the Help & FAQ answer "Can other members see that I'm locked?" should say the badge now includes when and how the lock was last verified (that page is edited on the site).

---

## 0.28.0

**Chastity: "Verified elsewhere"** (owner, 2026-10-13: "How does someone mark if their sub sent a photo verification on another platform?" … "yes build it that way").

- Before, a day only counted as verified when the wearer uploaded a photo on COL&B. A verification sent by Telegram or text left the day unverified on the calendar.
- **On the keyholder's view of a lock, under Verification:** a "Verified elsewhere" section. Choose the day (today or up to 6 days back, never before the lock started), optionally say where ("Telegram"), and press **Mark verified**.
  - Recorded in the lock history as a new event type `verified_else` ("Verified elsewhere (marked by the keyholder; no photo kept)", with the note). Back-dated days are recorded at midday that day, site time, so they land on the right calendar day; the history shows the date without a time.
  - The day shows as Verified on the wearer's month calendar, and the wearer's Verification panel says "Your keyholder marked today as verified (sent outside COL&B)."
  - The wearer gets a notification.
  - Refused: a day that already counts (a "verified elsewhere" mark, or a photo that's accepted or still waiting for review: accept the photo instead); a day outside the window; anyone but the current keyholder (the wearer can't mark their own day). A mark isn't a photo, so it can't be accepted or sent back.
  - No photo or file is stored. The export lists marks with the rest of the lock history; erasure removes them with the lock.
- No database change (`CMP_DB_VERSION` unchanged).
- **Verification** (local WordPress test kit, macOS, PHP 8.4):
  - `tests/chastity-e2e.sh`: 12 new checks, all pass (106 passed, 2 failed). New: the keyholder sees the section with only the days allowed; the wearer can't mark; marking today stores the note and no photo; the wearer is notified; a day can't be marked twice; the wearer sees today marked and the history line; a lock started 3 days ago offers 4 days; a back-dated mark lands on yesterday and shows on the month calendar; a day with a photo waiting is refused; more than 6 days back is refused; a mark can't be reviewed like a photo.
  - **The 2 failures are not from this change:** "badge shown once the wearer turns it on, naming the keyholder (0.25.0)" and "keyholder's profile: Holds the key for the wearer, linked" fail the same way on unmodified `main` (0.27.0) in this setup (94 passed, 2 failed). The profile page the test fetches comes back without the badge; not investigated here.
  - `php -l` on the changed file. No plugin warnings in `debug.log`.
  - Mac setup: a local router script for PHP 8.4's built-in server and port 8898 (8899 is held by an old test server), in an uncommitted copy of the script.
- **After this is live:** the Help & FAQ page's Chastity answers ("What can a keyholder do?", "How does daily verification work?") can mention it; that page is edited on the site, not in this plugin.

---

## 0.27.0

**Full control for the site team, and quiet by default** (owner, 2026-10-12: "I need full access in case the member is confused or can't understand how to do it", "I don't need to notify the members when I make the changes I will manually notify them via email").

- **Lock settings on the admin screen.** A lock's page in Users → Dynamics & locks has a Settings form with everything the wearer and keyholder can set:
  - profile badge, and the keyholder's name on the badges
  - hidden timer and daily verification photo
  - hygiene allowance
  - option (including Custom with a name) and release rule
  - **keyholder:** move the lock to another member who holds the key, or make it a self-lock
- **Record a lock** gains "Show the badge", so a recorded lock can show on both profiles straight away.
- **Dynamics:** each member's "Show on my profile" switch can be turned on or off.
- **No notifications from admin changes** unless "Notify both members on the site" is ticked. That box is unticked by default and is under every Reason field. While it's unticked, nothing is sent, including knock-on messages such as "now a self-lock" when a dynamic is ended (`CMP_Notifications::$muted`). Lock corrections still add "Corrected by the site team: …" to the lock's history, and every change is still in the audit log with its reason and the old and new values.
- **Privacy statement** (`docs/privacy-statement.md`) updated to match: settings changes, history lines, and members contacted directly. The live Privacy Policy page needs the same text.
- **Verification** (local WordPress from GitHub sources, SQLite, PHP 8.3):
  - `tests/member-admin-e2e.sh` 43/43, with new checks for every setting, refused changes, keyholder moves, dynamic profile switches, and no notifications unless ticked.
  - `chastity-e2e.sh` 96/96, `dynamics-e2e.sh` 56/56, `homework-e2e.sh` 44/44, `messages-e2e.sh` 51/51, `account-e2e.sh` 69/69. `php -l`.
  - Screenshot of the lock page with the new Settings form checked at 1280 px.

---

## 0.26.0

**An admin screen for dynamics and locks, so the site team can fix problems and keep day counts accurate** (owner, 2026-10-12: "I'll need an admin screen in case users have trouble with management and to be able to manually set days for people if something goes wrong. It's very important to these users to have accurate numbers").

- **Users → Dynamics & locks** (administrators only):
  - Lists locks waiting to be accepted, running locks with days locked, and recently ended locks.
  - Find any member to see their dynamics, chastity and homework add-ons, and locks.
- **Fixing a lock:**
  - **Set days locked:** type days and hours, and the start is set so the lock shows exactly that much time. A running lock counts back from now, an ended one from its end.
  - **Start, end and planned end:** set by date and time in the site's time zone, or make the lock open-ended.
  - **End** a running lock, **remove** a stuck waiting lock, or **delete** a duplicate record.
- **Record a lock** that should exist but doesn't, for example when an Accept failed. It can be running or past, with or without a keyholder. A running lock needs a keyholder who holds the wearer's key now, and the keyholder then manages it as usual.
- **Dynamics:** end one, turn off an add-on, or cancel an add-on request. These go through the same path members use, so a lock becomes a self-lock and homework is archived, as usual.
- **Safeguards:**
  - Every change needs a reason, kept in the audit log with the old and new values.
  - Both members get a notification saying what changed, with old and new times and the new total. The lock history shows "Corrected by the site team: …".
  - A planned-end change stays hidden from a wearer whose timer is hidden: they're told only that a correction was made.
  - Starts and ends can't be in the future, and ends must come after starts.
  - The screen shows names, status and dates only: no photos, rules, notes or messages. Nothing can be accepted on a member's behalf.
  - Past locks ended this way show "ended by the site team".
- **Privacy statement** (`docs/privacy-statement.md`): "Site administrators" now describes what the site team can see and do here. The live Privacy Policy page needs the same update.
- **Profile badges:** the text now wraps as one line at phone width. Before, it split into three columns ("Locked by" / name / "· N days").
- **Verification** (local WordPress from GitHub sources, SQLite, PHP 8.3):
  - New `tests/member-admin-e2e.sh` 34/34.
  - `chastity-e2e.sh` 96/96, `dynamics-e2e.sh` 56/56, `homework-e2e.sh` 44/44, `account-e2e.sh` 69/69, `messages-e2e.sh` 51/51. `php -l`.
  - Screenshots checked: the admin member and lock screens at 1280 px; both profile badges at 375 px.

---

## 0.25.0

**Lock badges name both sides, with a lock and a key icon** (owner, 2026-10-12: "I would like a locked by and the keyholder's name as a badge and the opposite for the caged", "make it a lock icon and a key icon respectively").

- **The sub's profile:** a padlock badge, "Locked by FoxxyForce · N days", linking to the keyholder's profile.
- **The keyholder's profile:** a key badge, "Holds the key for gogootter24 · N days", one for each such lock, linking to the sub's profile.
- **Both people control it.**
  - Nothing shows on either profile unless the sub's badge switch is on. That switch is the sub's choice, as before.
  - The keyholder has their own switch ("Show 'Holds the key for them' on my profile, and my name on their badge") in Rules, and on the Set up a lock form. With it off, the sub's badge reads just "Locked · N days" and the keyholder's profile shows nothing.
  - Someone in a block with the person named sees only the plain badge.
- **Setting up a lock** gains "Ask them to show 'Locked by me' on their profile". This pre-ticks the sub's badge switch on the accept screen, and the sub can untick it. The switch labels now say exactly what will show.
- **Existing locks** start with the keyholder's name off (new column `locks.kh_show`, `CMP_DB_VERSION` 17), so no profile changes until a keyholder turns it on. New locks default to on.
- **Uploads too large for the server:** PHP drops every field, so the request used to end on a blank page with nothing saved. It now returns to the member area with "That was too large to send, so nothing was saved." This is a likely cause of a silently failed Accept with a phone photo.
- **Verification** (local WordPress from GitHub sources, SQLite, PHP 8.3):
  - `tests/chastity-e2e.sh` 96/96, with new badge checks: names, links, keyholder switch, block, badge request.
  - `dynamics-e2e.sh` 56/56, `profile-e2e.sh` 84/84, `directory-e2e.sh` 21/21.
  - Checked by hand: the DB 16 → 17 upgrade turns `kh_show` off on an existing lock; a 3 MB post against `post_max_size=1M` redirects with `upload_too_large`.
  - `php -l`. Not checked: a visual pass at phone widths.

---

## 0.24.0

**A keyholder can set up a lock for the person whose key they hold** (owner, 2026-10-12: "I need to be able to setup a lock for a submissive … right now it can only be done by the submissive").

- **Chastity tab → Locks you hold → Set up a lock for someone.** Offered to a member who holds someone's key (an active dynamic with the chastity add-on, them leading) when that person has no active lock and no lock waiting. The keyholder picks the wearer, option (or Custom), planned length, hygiene allowance, hidden timer, daily verification and an optional message.
- **The wearer accepts or declines.** Nothing is locked until they accept. They see the rules, length (or "Hidden by your keyholder"), verification and hygiene terms, and can add a lock photo and the profile badge when accepting. The timer starts at acceptance, with the keyholder's settings; from then on it's an ordinary keyholder lock. Emergency unlock is unchanged.
- **The keyholder can withdraw** a lock that's still waiting. Both sides are notified of each step (proposed, accepted, declined, withdrawn).
- **One waiting lock per wearer.** A waiting lock is removed if the dynamic ends or the chastity add-on is turned off, and when the keyholder's data is erased. It has no keyholder controls until it's accepted, and doesn't show on the calendar or profile badge.
- Stored as a `locks` row with status `proposed` (planned length on its `proposed` event); no schema change, so `CMP_DB_VERSION` is unchanged.
- **Help & FAQ page** (a WordPress page, not in this repository) still says only the wearer starts a lock; it needs the owner's update.
- **Verification** (local WordPress from GitHub sources, SQLite, PHP 8.3):
  - `tests/chastity-e2e.sh` 91/91, with 23 new checks for proposing, accepting, declining, withdrawing, access, and the dynamic ending.
  - `dynamics-e2e.sh` 56/56, `homework-e2e.sh` 44/44, `account-e2e.sh` 69/69. Erasing a keyholder with a waiting lock checked by hand. `php -l`.

---

## 0.23.1

- **Profile tab → Groups and titles:** "Run a group or hold a title? **Submit it to be listed**" links to the submission page (the published page with the slug `submit-organization`).
- **Verification:** `tests/org-e2e.sh` 128/128; `php -l`.

---

## 0.23.0

**A titleholder's events on their titleholder page** (owner, 2026-10-10: "Any events that I'm attending that are publicly available via my calendar as a member should show here". The owner chose a new Public choice).

- **My calendar gains "Public: my titleholder page"**, offered only to a member who is a titleholder. Anyone else who sends it gets All members instead.
- **What the titleholder page shows under Upcoming Events:**
  - Public events: to everyone, signed in or not.
  - All members events: to signed-in members too.
  - Dynamic-partner and Only-me events: never.
  - Nothing for someone in a block with the titleholder.
- The titleholder sees the page as visitors do. Public entries also count as "all members" everywhere else (their profile's Calendar, followers).
- **Verification** (local WordPress 7.1.2):
  - `tests/org-e2e.sh` 124/124, with the new titleholder-events checks.
  - `calendar-e2e.sh` 73/73, `profile-e2e.sh` 84/84. `follows-e2e.sh` fails the same 3 checks as on unchanged `main` (pre-existing). `php -l`.

---

## 0.22.0

**A titleholder listing belongs to one person, shown on their member profile** (owner, 2026-10-10: "the bottom should say my COL&B profile because only one person should be assigned to a title holder bio", "once the member is tied to the titleholder it should display on their member profile as well").

- **One person per titleholder listing.**
  - Assigning someone as Titleholder (from an approved submission or under Linked members) replaces whoever was there.
  - Members can't link themselves to a titleholder listing, and titleholder listings aren't offered under Add a group.
- **On a titleholder's page, signed-in members see "My COL&B profile"** with just that one person's card, instead of "Members on COL&B" and the Link my profile button.
- **On that member's profile in the members area, a Titleholder badge** shows the title line ("Great Lakes Handler 2026 · Produced by …") and a link to the titleholder page. Other groups stay on the Groups line.
- **Verification** (local WordPress 7.1.2):
  - `tests/org-e2e.sh` 115/115, with new checks: no self-linking to a titleholder listing and it isn't offered; "My COL&B profile" with only the titleholder; the badge on their profile; reassigning replaces the previous person.
  - `profile-e2e.sh` 84/84. `php -l`.

---

## 0.21.1

- **Profile tab → Groups and titles:** the button next to a group you're Titleholder or Organizer of now reads **Edit page & card**, since the homepage card is edited there too (Events Calendar 1.36.0).
- **Verification:** `tests/org-e2e.sh` 96/96; `php -l`.

---

## 0.21.0

**Titleholders and organizers can edit their group's page** (owner, 2026-10-10; works with Events Calendar 1.35.0).

- A full member linked to a group as **Titleholder** or **Organizer** may use "Edit this page" on the group's page (answers `cec_partner_org_can_edit`). Members linked as Member can't.
- **Profile tab → Groups and titles** shows an **Edit page** button next to those groups.
- **wp-admin → Events → Partners & Titleholders → Edit** gains **Linked members**:
  - change each member's role or remove the link;
  - link a member by username or email with a role (they're notified).
- **Verification:** `tests/org-e2e.sh` 90/90 (the editor checks plus admin link/role/remove); `profile-e2e.sh` 84/84; `account-e2e.sh` 69/69; `php -l`.

---

## 0.20.1

**Your own card on a group's page is easy to find, and the page is never shown stale after signing in** (owner, 2026-10-10: "I added my profile … but I'm not seeing it … All members should be able to see their own profiles … on these pages.").

- **Your card comes first and says "(you)"**, and the section says "Your profile is listed here as Member" (or Organizer/Titleholder), or that your profile isn't linked to this group. The button reads **Manage my groups** once you're linked, otherwise **Link my profile**. (The owner's display name is the same as a titleholder's, so their card wasn't recognisable.)
- **Signed-in visits to `/partner/<slug>/` are sent no-cache headers.** Signed-out copies of these pages tell browsers to keep them for 5 minutes, so a page opened before signing in could be shown again without the member list.
- **Verification** (local WordPress 7.1.2): `tests/org-e2e.sh` 64/64, with 4 new checks (own card first with "(you)", role sentence and button; no-cache signed in, cacheable signed out; "isn't linked" for someone not linked). `php -l` on the changed file.

---

## 0.20.0

**Members can link their profile to an organization or titleholder** (owner, 2026-10-10: "you should be able to link a member account to an existing group OR enter one when submitting the form so the member profile is visible").

- **Profile tab → Groups and titles:** pick any group listed on the site and **Link my profile**; remove a link at any time. A link you add yourself is always **Member**.
- **Organizer and Titleholder come only from an approved submission.** When the site approves an organization or titleholder listing (Events Calendar 1.34.0) that names a member by username or email, that member is linked with the matching role and gets a notification saying they can remove it. Adding the group yourself never downgrades that role.
- **On the group's page (`/partner/<slug>/`), signed-in full members see "Members on COL&B":** each linked member's photo, name and role, linking to their member profile. The public, signed-out visitors and people who haven't finished joining never see it, and a block either way hides the member, the same as everywhere else.
- **Member profiles show "Groups:"** with links to each group's page.
- Deleting a group removes its links. Data export lists your linked groups; erasure deletes them.
- **New table `cmp_group_links`** (`CMP_DB_VERSION` 16). Groups are read only through the events plugin (`CEC_Orgs`, its taxonomy); nothing here changes events data. Without Events Calendar 1.34.0 the panel and page section are simply absent.
- **Verification** (local WordPress 7.1.2): `tests/org-e2e.sh` 60/60, 20 of the checks for this: approval links with the right role and notifies; links by username and by email; no duplicate on re-approval; Profile panel; bad nonce; self-link is Member and never downgrades; unlisted groups refused; signed-out redirect; profile "Groups"; who sees the list on the group page (member yes, unfinished account no, blocked no); remove; export; erase; deleting the group. Also `account-e2e.sh` 69/69, `profile-e2e.sh` 84/84, `messages-e2e.sh` 51/51, `directory-e2e.sh` 21/21, `calendar-e2e.sh` 73/73. `follows-e2e.sh` fails the same 3 checks on unchanged `main` (pre-existing). `php -l` on every changed file.

---

## 0.19.2

**Chastity: a keyholder who doesn't have an account yet** (member request via the owner, 2026-10-08: "For those whose partners haven't created accounts yet, it would be nice to be able to identify the key holder manually by typing in a name.").

- **Start a lock → Keyholder → "Someone without an account…"**, then type their name (up to 60 characters). The name field appears when that choice is picked; without JavaScript it's always shown and only used for that choice.
- **It's a name for the wearer's own record.** The person can't sign in, so nobody is notified and the lock works exactly like a self-lock: the wearer keeps every control and can always end it. My lock shows "Keyholder: <name> (no account)".
- **The wearer can change or clear the name** under the lock's Settings while it's on. Cleared, it's a plain self-lock again.
- Choosing a member as keyholder ignores any typed name. Once the partner joins and they agree chastity in a dynamic, the wearer can choose them as a member for their next lock.
- **Privacy:** the name is someone else's personal detail, so the audit log records only that a name was given. The wearer's data export includes it, and erasing the wearer's data removes it with their locks.
- **Database version 15:** new `keyholder_name` column on locks, added automatically on update. Existing locks are unchanged.
- **Verification** (local WordPress 7.1.2, Community Events Calendar 1.32.1): `chastity-e2e.sh` 68/68, with 13 new checks. They cover the choice and field being offered; no name refused; the name stored cleaned; nobody notified; the label on My lock; self-lock controls kept; the name not in the audit log; changing it, exporting it, clearing it; ending the lock; another member can't change it; and a typed name ignored for a member keyholder. Also `dynamics-e2e.sh` 56/56, `account-e2e.sh` 69/69, `e2e.sh` 60/60.

---

## 0.19.1

**App icons are always square** (found checking 0.19.0 on the live site). colandb.com's site icon is the wide COL&B logo (873×327), so the app icon would have been squashed or cropped on phones.

- **When the site icon isn't square, the app uses it centred on a square** in the app's background colour (80% of the width), at 192 and 512 pixels. The squares are made once with GD and kept in `uploads/cmp-app/`; a changed icon makes new ones. A square site icon is used as it is.
- **iPhones get the square icon:** an `apple-touch-icon` (180×180) is printed after WordPress's own site-icon tags.
- **For colandb.com:** a purpose-made square COL&B icon was provided to set as the Site Icon (Appearance → Customize → Site Identity). That also fixes the browser-tab icon.
- **Verification:** `app-e2e.sh` 29/29, with 3 new checks: a wide icon becomes a true 512×512 square, iPhones get it, and a square icon is used as it is.

---

## 0.19.0

**Installable app and event reminders** (owner, 2026-10-05: "build the installable app with reminders"; release 1 of 2, with push notifications in release 2). Owner's choices: reminders the evening before (6 pm) and 2 hours before; in the member area, and by email only for members who turn it on; push will come from the site itself, not a third party.

- **The site installs as an app.** Home-screen icon, full screen, and an offline page.
  - The manifest, service worker and offline page are served from `wp-admin/admin-post.php`, which the host's CDN never caches. The worker is allowed the whole site (`Service-Worker-Allowed`).
  - The service worker stores only the offline page and the app icon. Member pages, form posts and wp-admin are never touched or stored.
  - Icons come from the site icon (Appearance → Customize → Site Identity), with bundled ones as a fallback.
  - The short name is made from the site name's initials ("Central Ohio Leather & Beyond" → COL&B); the `cmp_app_short_name` filter can change it.
  - Every page gets the manifest link, theme colour and home-screen tags.
- **Install prompt in the member area:**
  - an **Install** button where the browser offers one (Android, desktop Chrome/Edge);
  - the three **Add to Home Screen** steps on iPhone/iPad;
  - nothing once it's installed, and **Not now** hides it for 30 days on that device.
  - **Account → App & notifications** always has the install help.
- **Event reminders** for events on your calendar, checked every 15 minutes:
  - **the evening before**, from 6 pm in your time zone; **2 hours before**, for events with a set start time;
  - for Going events, and Interested ones too if you turn that on;
  - in your member-area notifications straight away (not held for quiet hours), linking to the event;
  - **by email** only if you turn it on. The subject says only "COL&B reminder: Tomorrow at 7:00 pm"; the event is in the body.
  - Each is sent once per start time, so a moved event is reminded again. Cancelled or postponed events, unpublished events and accounts that aren't members get nothing.
  - Settings are in Account → App & notifications, and there's a new "Reminders for events on my calendar" notification category.
- **Database version 14** adds `calendar.rem_evening_for` and `rem_2h_for`.
- **Privacy statement:** mentions reminders, email reminders and the app. Export lists reminder settings, and erase removes them.
- **Verification:**
  - New `app-e2e.sh` 26/26:
    - manifest, service worker and offline page, with their headers, and that the icons load;
    - the short name; tags on every page;
    - the banner for members only; the Account panel; that reminders are checked every 15 minutes;
    - reminders before and after 6 pm, timed and all-day, 2 hours before, Interested off and on, cancelled, non-members, sent once, a moved event, settings, a bad nonce, and email wording;
    - export and erase.
  - All other suites pass.
  - In Chrome on the local test site:
    - the service worker registered for the whole site and stored the offline page;
    - Chrome offered the app as installable, so the banner showed, and Not now hid it for 30 days;
    - with the server stopped, pages showed the offline page with its icon;
    - the Account panel showed the reminder settings.
    - The test service worker and its data were then removed from that browser.

---

## 0.18.1

**Private calendar feed moved out of the host's CDN cache.** Checked on the live site right after 0.18.0. Elementor Hosting's CDN may keep signed-out front-end responses for up to 7 days (`ec-cdn-cache-control: public, max-age=300, s-maxage=604800`, `ec-cdn-status: miss`), and calendar apps fetch the feed signed out. That could have shown members old events for a week and kept a reset link working.

- **The feed is now served from `wp-admin/admin-post.php?action=cmp_cal_feed&key=…`.** The CDN never caches wp-admin (`ec-cdn-status-reason: excluded uri` on the live site). The Events Calendar already lets visitors reach `admin-post.php` without being sent to the front end.
- **The old `?cmp_cal_feed=` address no longer serves a calendar.** Nobody had turned a link on before this release, so no subscriptions break.
- **Verification:** `calendar-e2e.sh` 57/57, with a new check that the old address no longer serves a calendar.

---

## 0.18.0

**Private calendar feed: your COL&B calendar in your phone's calendar app** (owner, 2026-10-05: "build the private calendar feed next"; the second calendar release, the roadmap's "private, revocable ICS"). Needs Community Events Calendar 1.32.0.

- **Calendar tab → "Add to your phone's calendar".** It's off until you choose **Get my calendar link**. Then you get:
  - **Subscribe (iPhone, Mac, Outlook):** a `webcal://` link the device's calendar opens directly;
  - **your private link** with **Copy link**, plus how to add it in Google Calendar (Other calendars → + → From URL);
  - **Get a new link** (the old one stops working) and **Turn off**, each with a confirmation.
- **What's in it:** everything on your calendar, including events only you can see (it's your own feed), upcoming plus the last 30 days. Interested events are marked tentative, and times, places and cancellations come from the event. Calendar apps are asked to refresh every 4 hours.
- **Privacy and safety:**
  - The link is a random 40-character key; the panel says anyone with it can see those events.
  - It works only while the account is a member. Unknown, reset or turned-off links, and non-members, get "Not found".
  - It's sent with private, no-store, no-index headers and marked as not for page caches.
  - Turning it on, resetting it and turning it off are audit-logged.
  - The export says whether it's on (never the link itself), and erasing an account removes it.
- **Copy link** uses the clipboard. Where a browser blocks that, the link is left selected and the button says "Link selected, copy it".
- **Privacy statement:** the private calendar link is now described (`docs/privacy-statement.md`).
- **Verification:** `calendar-e2e.sh` 56/56, with 11 new feed checks:
  - off by default; turning it on; the Subscribe and Copy controls;
  - headers; contents (everything on your calendar, nothing else, tentative and confirmed);
  - a new link kills the old one; Turn off; non-members refused;
  - audit entries; export without the link; erase.
  - In a browser on the local test site: the panel off and on, Copy link, and the link returning the calendar file without being signed in.

---

## 0.17.3

**One My calendar link, in the right places** (owner, 2026-10-05: "This all seems very redundant").

- **The site's account menu ("Hello, …") has My Calendar**, after My Profile, so it's one click from every page.
- **The events plugin's signed-in box now gets only My calendar** from this plugin. Member area is already in the menu, and accounts not yet set up see "Finish joining the member area" there, so the box shows nothing for them. Needs Community Events Calendar 1.31.1.
- **Verification:** `calendar-e2e.sh` 45/45, with a new check for the menu item; `profile-e2e.sh` 84/84.

---

## 0.17.2

**My calendar in the events plugin's quick links** (needs Community Events Calendar 1.31.0). When you're signed in, the login box on event pages such as Community Events shows **My calendar** and **Member area** first. An account that hasn't finished signing up gets "Finish setting up your account" instead.

- **Verification:** `calendar-e2e.sh` 44/44.

---

## 0.17.1

**"Browse events" on My calendar went to the home page** (owner, 2026-10-05). It picked the first page showing any calendar shortcode, and the home page shows a few upcoming events. It now prefers a page with the full calendar, then the event list, then an upcoming list, and never the home page. On colandb.com that's Community Events. The `cmp_events_url` filter can still set it.

- **Verification:** `calendar-e2e.sh` 40/40, with a new check using a home page with `[cec_upcoming]` and a calendar page.

---

## 0.17.0

**My calendar** (owner, 2026-10-05: "I don't have a way to 'Add to my calendar' or have a public, friends/dynamic, or private calendar for my profile. That was the whole goal of merging the two features."). Owner's choices:
- one calendar per member, with an audience per event;
- audiences: all signed-in members, dynamic partners (an active dynamic) or only me;
- "Going" counts as the RSVP;
- the profile's "Going to" becomes a Calendar tab, filtered by who's looking.

Needs Community Events Calendar 1.30.0 for the event-page box and RSVP sync. Without it, the tab and profile still work.

- **"Add to my calendar" on every event page**, under the Google Calendar and .ics links:
  - Going or Interested, and who can see it on your profile; tappable pills, starting from your default;
  - after saving: "On your calendar · Going · visible to …", with Change and Remove;
  - signed-out visitors get a sign-in link; accounts not yet set up are pointed to the member area.
- **Going = RSVP** on events that take RSVPs here:
  - the event's capacity applies, and a full event offers Interested;
  - Interested or Remove takes the RSVP away;
  - an RSVP made with the event's own form while signed in lands on your calendar as Going.
- **Calendar tab in the member area:**
  - upcoming events grouped by month, with who can see each (changing it saves straight away) and Remove;
  - recent past events;
  - the default for new events ("Dynamic partners" unless you change it);
  - a link to browse events.
- **Profile → Calendar tab** (was "Going to"): each viewer sees only what they're allowed to. Nothing hints that hidden events exist. Your own "as members see it" view shows only all-members events.
- **One rule for who sees what (`CMP_Calendar::can_see`):** you always; otherwise a signed-in member, not blocked either way, who is in the audience. "Dynamic partners" ends the moment the dynamic does.
- **Followers:**
  - "X is going to Y" goes only to followers allowed to see that event;
  - widening an event's audience tells only the newly included;
  - the "N people you follow are going" calendar marker counts only what the viewer may see;
  - the old "Show events I'm going to, to my followers" switch is replaced by a pointer to My calendar.
- **Upgrade (database version 13), run once:**
  - adds the `calendar` table;
  - RSVPs members made while signed in become Going: at All members for those who had the followers switch on (anyone could follow them), Only me for everyone else, so nothing becomes visible that wasn't before;
  - those who had it on keep All members as their default.
- **Retention:** calendar entries go 12 months after the event, like RSVPs (`cmp_retention_periods` key `calendar`). Export lists entries and your default, and erasing an account removes them.
- **Verification:** new `calendar-e2e.sh` 39/39 (event page states, Going/RSVP, capacity, Interested, Remove, validation, every audience, blocks, ended dynamics, notifications, widening, the marker, the tab, the default, form RSVPs, the upgrade, retention, export and erase). All other suites pass.

---

## 0.16.0

**Dynamics: several types at once, and chastity and homework as add-ons with any dynamic** (owner, 2026-10-05: "I should be able to select multiple. I should also be able to have the chastity option regardless of what the dynamic is providing the other party agrees to it. Same with Homework."). Owner's choices: the proposer picks who leads each add-on; add-ons can be added later with agreement; Keyholder / chastity wearer keeps chastity on automatically; existing dynamics keep only what they use.

- **Several types in one invitation.**
  - Types are tappable chips; each type with sides asks for your side once it's ticked.
  - The other member accepts or declines the whole invitation at once. It counts as one invitation in the tab badge and in the 10-pending limit.
  - Each type can still be ended on its own.
- **Chastity and homework are add-ons.**
  - They work with any type, including equal ones such as Partners.
  - Each has its own direction: "I hold the key / They hold the key", "I set it / They set it".
  - They're asked for in the invitation and start only when it's accepted.
- **On an active dynamic:**
  - **Ask to add** chastity or homework, either direction. The other member accepts or declines, and the asker can withdraw.
  - **Turn off** any add-on: either member, at any time, straight away. The other member is told.
  - **Add a type** links to the proposal form on their profile.
- **Keyholder / chastity wearer** always comes with chastity, the Keyholder holding the key. The form ticks it and locks the direction.
- **What homework and chastity check now:** only an agreed add-on, while at least one dynamic between the two is active.
  - Being the "leading side" no longer gives anything by itself.
  - When the last dynamic ends, add-ons end with it.
  - Blocking ends them too.
  - Homework is archived and locks become self-locks, as before.
- **Upgrade (database version 12), run once:**
  - Adds the `dynamic_addons` table and `dynamics.group_id`.
  - Each active pair keeps only what it uses: chastity for Keyholder dynamics or a lock in place, homework if a program is running.
  - Pending Keyholder invitations get their chastity request.
- Homework and Chastity tab wording follows the new rules. The data export lists add-ons, and erasing an account removes them.
- **Verification:**
  - `dynamics-e2e.sh` 56/56, with 22 new checks: several types and add-ons in one invitation, sides required, nothing allowed while pending, accepting starts everything, directions respected, turn off, ask again, duplicate and self-accept refused, no asking without a dynamic, ending one type versus the last, the Keyholder rule, withdrawing, and the upgrade.
  - Homework and chastity tests now create their add-on explicitly.
  - All 15 suites pass.
  - In a browser on the local test site: the chips, side questions, add-on rows, the Keyholder lock-in, sending an invitation, and the Dynamics tab with an active pair, an add-on request and Ask to add.

---

## 0.15.2

**"Leave site?" when sending a message or posting to the feed, and the dynamic proposal layout** (owner, 2026-10-05: "it shouldn't have the 'leave' that's confusing for users"; "I get the same leave message when I post to the feed as well"; screenshot of "Your side" with stretched buttons).

- **The real cause of "Leave site?" was Elementor's AI assistant (Angie), not our forms.** It loads for administrators on every front-end page inside a frame from Elementor's own site, and that frame makes the browser ask "Leave site?" on every link and form. A page can't switch off another site's frame, so 0.15.1's guard couldn't help. Taking the frame off the page made links and Send work straight away. Members who aren't administrators never had Angie, so they weren't affected. Now:
  - on the member page (feed, messages, profiles and the rest), Angie's scripts and styles aren't loaded at all;
  - Angie still works everywhere else, including the Elementor editor;
  - this also keeps an AI tool off private member pages, as the privacy statement promises;
  - the `cmp_leave_out_handle` filter can change which scripts are left out.
- **Propose a dynamic → "Your side":** the text-box rule (full width, 44px tall) also applied to the two radio buttons, stretching them across the form and pushing their labels outside. That rule now applies only to text boxes. Each side is a tappable row, and the whole row selects it.
- **"Message (optional)" boxes** are dark like the other fields, not bright white.
- **Verification:**
  - `messages-e2e.sh` 51/51, with new checks: Angie's scripts, inline setup and styles are left out of the member page; other scripts still load; Angie still loads on other pages; and the radio fix.
  - All other suites pass.
  - In a browser on the local test site, the proposal form showed normal radio rows (the whole row taps), with dark message boxes.
  - On the live site, removing Angie's frame let links and Send work without the pop-up.

---

## 0.15.1

**Messages and nods fixes** (owner, 2026-10-05: sending a message "just asks if I want to leave", the message wasn't received, "no way for me to see who I nodded to or the messages I sent", and "nods should be limited to once per receiver per 24 hours").

- **Sending works even when other scripts ask "Leave site?"** On the live site, Elementor and the push service add that warning to pages with forms. If someone chose to stay, our double-submit guard had already locked the form, so pressing Send again silently did nothing and the message was never saved. Now:
  - while one of our forms is submitting, a capturing `beforeunload` listener stops other scripts' warnings (only during our own submit);
  - a form unlocks itself after 6 seconds if the page didn't leave;
  - forms also unlock when you come back with the Back button.
- **Messages → Sent:** your requests to members you're not connected with, "waiting for them to accept", in their own list instead of mixed into the Inbox. The conversation's back link returns there.
- **Nods once per member every 24 hours.** Within 24 hours a second nod is refused ("You've already nodded at them today"). After 24 hours the profile button says **Nod again**, and nodding again tells them again.
- **Messages → Nods** now has two lists: **Nodded at you** and **You nodded at** (when, whether they nodded back, Nod again once allowed, Message).
- **Verification:** `nods-e2e.sh` 23/23 (new: the 24-hour rule, Nod again, You nodded at) and `messages-e2e.sh` 47/47 (new: Sent, not in Inbox). All other suites pass. In a browser, a message was sent with a "Leave site?" handler added to the page, the same situation as on the live site.

---

## 0.15.0

**Automatic data clean-up** (owner, 2026-10-05: "Yes"; it matches the retention table in `docs/privacy-statement.md`). New `CMP_Retention` runs once a day (WP-Cron `cmp_daily_retention`, cleared on deactivation):

- **Audit log:** entries older than 2 years are deleted.
- **Member reports:** deleted 3 years after they were made.
- **Accounts never verified:** deleted 60 days after sign-up. On purpose, this only applies to accounts that:
  - are plain subscribers;
  - were created after this version was installed (the `cmp_retention_since` option), so older accounts are never swept up;
  - have no posts or events of any kind and no RSVPs.
  Each removal is audit-logged.
- **Deletion requests:** administrators see a dashboard notice when a confirmed data deletion request has been waiting 20 days, since the statement promises 30.
- Periods can be changed with the `cmp_retention_periods` filter (days). The last run's counts are stored in the `cmp_retention_last` option.
- Verification: new `tests/retention-e2e.sh` 17/17; all other suites pass (setup 39, profile 84, dob 34, account 69, feed 41, messages 46, follows 27, directory 21, nods 20, dynamics 34, homework 44, chastity 55, e2e 60).

---

## 0.14.0

**Nods: a quiet way to show interest** (owner, 2026-10-05: "a way to indicate interest passively … somewhat neutral … related to kink but not aggressive", not another app's version). Named after the nod across the bar; our own word and our own mark (a small dot with a dip beneath), not another app's feature name or icon.

- **`CMP_Nods`**, new table `cmp_nods` (`CMP_DB_VERSION` 11). A **Nod** button in every profile header (beside Follow); tap again to take it back. One nod per member per member; at most 30 a day.
- **Messages → Nods** (badge for new ones, also counted in "Messages (N)"): who nodded at you, with **Nod back** and **Message**. Ignoring a nod tells nobody anything.
- **Mutual nods:** both members are told "You and <name> both nodded. Say hello?" with a link to message, and from then on messages between them go straight to the inbox (they count as connected, like an active dynamic).
- **Wording never assumes pronouns** (owner): every nod message uses names only.
- New notification category **Nods** (can be turned off). Blocks remove nods both ways and stop new ones. Export lists nods you sent; erase removes nods both ways.
- **Verification** (local WordPress 7.1.2): new `tests/nods-e2e.sh` 20/20 (incl. a check that no nod wording contains he / she / him / his / her); all other suites pass (setup 39, profile 84, dob 34, account 69, feed 41, messages 46, follows 27, directory 21, dynamics 34, homework 44, chastity 55, e2e 60). In a browser at 1440 px: the Nod button in a profile header and the Nods list.

---

## 0.13.1

**Wider member area on desktop** (owner, 2026-10-05: "why is it so thin on desktop?"). The member area was capped at 760px (sized for forms in 0.1). It's now up to 1200px, so profiles (Details beside About, buttons beside the name), Members (filters beside a 3-column grid) and messages use the screen. The Profile, Account and Setup tabs and the Feed keep a reading width of 860px for their content; the tab bar stays full width on every tab so it doesn't jump.

- Verification: all suites pass (setup 39, profile 84, dob 34, account 69, feed 41, messages 46, follows 27, directory 21, dynamics 34, homework 44, chastity 55, e2e 60); checked in a browser at 1440 px: a profile, Members, the Profile tab and the Feed.

---

## 0.13.0

**Facebook / FetLife-style profiles and member search** (owner, 2026-10-05: "Even though I have a cover image it doesn't show in my profile … We want a Facebook or FetLife style profile view. I also need a way to search for members or filter members … click on one of the items in their profile to get a list of members who have that item"). From `docs/mockups/profile-directory.html`, with the owner's answers: everyone listed with an opt-out, location by city / state for now, sections as tabs.

- **Cover image fixed.** 0.5.0's phone-first layout hid the cover (`.cmp-prof-cover { display: none }`). Profiles now show the cover across the top (3:1), with the profile photo overlapping it; no cover gives a plain band.
- **New profile layout:** name with the "54 M Dominant" tag, location · pronouns · expression, follower counts, and **Follow · Message · Block** in the header. Below, **tabs: About · Kinks · Going to · Posts** (only tabs with something in them; arrow keys move between tabs; without JavaScript every section shows in turn). About has a Details list beside About me, Looking for, Not looking for, Interests, Hard limits and Health. Propose a dynamic and Report sit under the profile.
- **Clickable items:** roles, orientation, gender, position, body type, how active, hosting, looking for, interests, kinks and the city link to Members filtered by that item, but only for answers shown to all members.
- **`CMP_Directory`: a Members tab** (after Feed). Filters: name, role, looking for, orientation, gender, position, body type, kinks, interests, how active, hosting, age range, city or state, with a profile photo, people I follow; sort by newest or name; 24 per page. Active filters show as chips you can remove. Result cards show the cover strip, photo, name, "age gender role · city" and two "Looking for" items.
- **Privacy:** only answers shown to all members are ever matched (an age filter only finds members who show their age). Full members only; never yourself, members who opted out, or anyone blocked either way. **"Hide me from member search"** on the Profile tab; export says whether you're hidden, erase removes the setting.
- **Fix:** member profile pages now load the member-area script (profile tabs, the Propose form's labels, confirmations).
- **Verification** (local WordPress 7.1.2): new `tests/directory-e2e.sh` 21/21; `setup-e2e.sh` 39/39 and `profile-e2e.sh` 84/84 (two checks updated for the new header), `dob-e2e.sh` 34/34, `dynamics-e2e.sh` 34/34, `homework-e2e.sh` 44/44, `chastity-e2e.sh` 55/55, `feed-e2e.sh` 41/41, `account-e2e.sh` 69/69, `messages-e2e.sh` 46/46, `follows-e2e.sh` 27/27, `e2e.sh` 60/60. In a browser at 1280 px and 375 px: the cover and photo, tabs, clicking "Keyholder" on a profile into a filtered Members list, and the Members grid.

---

## 0.12.1

**Signing in lands in the member area** (needs Community Events Calendar 1.28.1). Members who sign in with the site's Log In link (or `wp-login.php`) with no particular destination now go to their member area instead of Submit an Event. Administrators and event managers keep their usual destination; a requested destination (`redirect_to`) is still respected. Uses the new `cec_login_default_redirect` filter.

- Verification: `account-e2e.sh` 69/69, `dob-e2e.sh` 34/34, `setup-e2e.sh` 39/39, `e2e.sh` 60/60 (local WordPress 7.1.2).

---

## 0.12.0

**Follow / unfollow** (owner, 2026-10-05: "in case you don't want to be friends but want to engage and see where someone is attending"). Owner's choices: anyone can follow, no approval; event plans only if the member opts in; shown as a Following feed filter, a "Going to" profile card, a calendar marker and notifications. Needs Community Events Calendar 1.28.0 for the calendar marker and RSVP notifications.

- **`CMP_Follows`**, new table `cmp_follows` (`CMP_DB_VERSION` 10).
- **Follow / Following** button on member profiles, with "N followers · N following" (also on your own profile view and the Profile tab).
- **"Events I'm going to"** (Profile tab), **off by default**: when on, your followers see a **Going to** card on your profile (your next 5 upcoming, published events you RSVP'd to while signed in, with each event's own time setting), get a notification when you RSVP, and see the calendar marker. Non-followers see "Follow <name> to see the events they're going to". Your own profile view marks the card "Only your followers see this".
- **Feed → Everyone / Following**: just the posts of members you follow (and can see).
- **Calendar:** "1 person you follow is going" / "N people you follow are going" via `cec_event_social_label`: a dot on desktop, a line on the phone list.
- **Notifications** (new category "People I follow", can be turned off): a followed member's new post (only to followers who can see it) and, if they share their events, their RSVPs.
- **Blocks** remove follows both ways and stop new ones; nothing crosses a block.
- **Privacy:** export lists who you follow and your sharing choice; erase removes follows both ways and the setting.
- **Verification** (local WordPress 7.1.2): new `tests/follows-e2e.sh` 27/27; `setup-e2e.sh` 39/39, `profile-e2e.sh` 84/84, `dob-e2e.sh` 34/34, `dynamics-e2e.sh` 34/34, `homework-e2e.sh` 44/44, `chastity-e2e.sh` 55/55, `feed-e2e.sh` 41/41, `account-e2e.sh` 64/64, `messages-e2e.sh` 46/46, `e2e.sh` 60/60. In a browser: profile button and Going to card (375 px), the Following filter (moved into the Feed header card after it rendered on the page background), and the calendar marker at 1280 px and 375 px.

---

## 0.11.0

**Member messages, with block and report** (owner, 2026-10-05: "a way for members to send messages to one another … as well as block or report"). From `docs/mockups/messages.html`, with the owner's answers: anyone can write, as requests; text only for now; reports show the whole conversation.

- **`CMP_Messages`**, four new tables `cmp_conversations`, `cmp_messages`, `cmp_blocks`, `cmp_message_reports` (`CMP_DB_VERSION` 9), and a **Messages** tab right after Feed, with an unread count ("Messages (2)").
- **Writing:** "Message" on any member's profile. One conversation per pair of members; text up to 2,000 characters; Ctrl/⌘+Enter sends.
- **Requests:** between members who aren't connected (no active dynamic) the first message is a request. It waits under **Requests** (with a count) until accepted; replying accepts it too. The sender can send at most 3 messages until then. Connected members go straight to the inbox. Members can turn requests off ("Accept message requests from members I'm not connected with"); their profile then says "Not accepting message requests". At most 20 new conversations a day per member. New requests send an in-site notification (new category "New message requests", can be turned off).
- **Read state** is tracked by message, so unread dots and counts are exact even when messages share a second.
- **Delete** a conversation from your side; the other member keeps theirs. A later message brings it back with only the new messages.
- **Block** (from a conversation, a request or a profile): they can't message you or start a conversation, neither of you can see the other's profile or feed posts, the conversation disappears for both, dynamic proposals are refused, and any dynamic between you ends (which archives homework and turns a lock into a self-lock). They aren't told. **Messages → Blocked** lists who you blocked, with Unblock.
- **Report** (from a conversation or a profile): a reason (harassment or threats, unwanted sexual content, spam or scam, underage or not consenting, something else), an optional note, and "Also block them" (on by default). The site admin email gets a link, never the messages themselves. Administrators review on **Users → Member reports** ("Member reports (N)" while any are open): who, why, and the whole conversation; opening one is audit-logged; "Mark as reviewed" closes it. Otherwise nobody but the two members can read a conversation.
- **Privacy:** export lists the messages you sent and who you blocked; erase removes the member's conversations (both sides), blocks both ways and the reports they made; reports about them are kept.
- **Verification** (local WordPress 7.1.2): new `tests/messages-e2e.sh` 46/46; `setup-e2e.sh` 39/39, `profile-e2e.sh` 84/84, `dob-e2e.sh` 34/34, `dynamics-e2e.sh` 34/34 (its nonce helper now picks the dynamics form, since profiles carry more forms), `homework-e2e.sh` 44/44, `chastity-e2e.sh` 55/55, `feed-e2e.sh` 41/41, `account-e2e.sh` 64/64, `e2e.sh` 60/60. In a browser at 375 px: inbox, conversation and its menu, sending, a request, and profile buttons.

---

## 0.10.0

**Kink picker** (owner, 2026-10-05: the kinks list "becomes cumbersome"; approved from `docs/mockups/kink-picker.html`).

- **Only your picks are listed** on the Profile tab and setup step, each with Love it / Like it / Curious and Giving / Receiving / Both as tap-sized buttons (tap the chosen Giving / Receiving / Both again to clear it) and a × to remove it. A "N of 40 picked" count.
- **Add kinks** by typing (Enter adds the first match) or tapping a category; results are tap targets. Without JavaScript, "Browse all kinks" lists every kink by category with the same buttons, and saves the same way.
- **Categories** (11): Bondage & restraint · Impact · Chastity & control · Service & protocol · Pup & pet · Role & age play · Fetish & gear · Sensation · Body worship · Exhibition & voyeur · Other. The site team sets each kink's category on Users → Member Profile Options → Kinks (new Category column).
- **62 starter kinks** (the 30 from 0.5.0 plus 32 more). A one-time upgrade (`CMP_DB_VERSION` 8) gives existing kinks a category and adds the new ones; kinks the site team renamed or retired, and ones they added, are left as they are.
- **On profiles** kinks are grouped under Love it / Like it / Curious, with giving / receiving beside each.

**Fixes**

- **Show switches came back.** 0.9.1 accidentally removed the script that reveals a field's "Show to members" switch once it's filled in, so a newly filled field couldn't be switched off until after saving. Restored. A picker's empty radios don't count as "filled".
- **Single-choice fields start blank.** Body type, position, hosting and "how active" had no blank choice, so any Profile tab save quietly set them to their first option ("Slim", "Top", …). They now start on "Not set". Members who saved their profile since 0.5.0 may show a body type or position they never chose; they can set it back to "Not set".

**Verification** (local WordPress 7.1.2): `setup-e2e.sh` 39/39 (new: categories, upgrade, admin categories, picker markup, removing), `profile-e2e.sh` 84/84, `dob-e2e.sh` 34/34, `dynamics-e2e.sh` 34/34, `homework-e2e.sh` 44/44, `chastity-e2e.sh` 55/55, `feed-e2e.sh` 41/41, `account-e2e.sh` 64/64, `e2e.sh` 60/60. In a browser at 375 px: search, Enter to add, categories, levels, clearing a direction, removing, saving, the Show switch appearing and hiding, and the grouped profile view.

---

## 0.9.1

**Profile fixes from the owner's review of the live site (2026-10-05).**

- **Photo descriptions are optional.** No more "Describe the photo or tick Decorative image" error. The description sits behind "Add a description (optional)"; without one, a profile photo is read by screen readers as "Profile photo of <display name>" and a cover image is treated as decorative.
- **Profile photo and cover image can be uploaded together.** Saving one photo used to reload the page at once, dropping a photo already chosen (and cropped) in the other panel. Now one Save uploads every photo that's waiting, then reloads once; if one fails, the others stay saved and the failed one says why.
- **The display name can't be hidden.** It has no Show switch ("Always shown") and always counts as shown to members, including for existing members who had switched it off. It's already how members appear on posts, dynamics and homework.
- **Orientation is a fixed list** (no "Other, in your own words"), so members can be found by it. Earlier free-text answers are kept in the database but no longer shown.
- **"View my profile as members see it"** button on the Profile tab: opens your own member link (`?cmp_member=<your ID>`) showing exactly what other members see, your all-members posts, and an "Edit my profile" link. Author names in the feed now link to member profiles (yours included).
- **Tests:** `profile-e2e.sh` makes its test photos with PHP's GD instead of ImageMagick, so all 83 checks now run on a Mac (the 12 photo checks used to be skipped); new checks for the display name, orientation, own-profile view and photo alt text. `dob-e2e.sh` updated for the always-shown display name. Also checked in a browser: choosing both photos and pressing Save on one stores both (512×512 and 1500×500) with no description.
- **Verification** (local WordPress 7.1.2): `profile-e2e.sh` 83/83, `dob-e2e.sh` 34/34, `setup-e2e.sh` 32/32, `feed-e2e.sh` 41/41, `account-e2e.sh` 64/64, `e2e.sh` 60/60.

---

## 0.9.0

**Member feed** (owner, 2026-10-04: "there should also be an activity feed for members and a way for them to share text and image posts (like tagging an event for photos they took there etc.)"). Built overnight from `docs/mockups/feed.html`; **not yet approved by the owner.**

- **`CMP_Feed`**, three new tables `cmp_posts`, `cmp_post_photos`, `cmp_post_likes` (`CMP_DB_VERSION` 7), and a **Feed** tab right after Home.
- **Posts:** text up to 2,000 characters and/or up to 4 photos (JPEG/PNG/WebP under 5 MB, re-encoded to JPEG at most 1600 px, location data removed). Optional **event tag**: a published Community Events Calendar event from the last 60 days or the next 30.
- **Audience:** "All members" or "My connections" (members in an active dynamic with the author). Members only: signed-out visitors and accounts that aren't full members see nothing, and photos 404 for anyone who can't see the post. If the dynamic ends, connections-only posts disappear for that person at once.
- **Event filter:** the tag links to the feed filtered to that event (every member's posts and photos from it), with a link to the event page.
- **Likes** (♥ toggle). No comments yet.
- **Moderation:** any member can report a post once (reason optional, audit-logged); the site admin email gets a message with a link; administrators see "Hide (admin) · N reports" and can hide any post (the author still sees it, marked hidden); authors delete their own posts with their photos and likes.
- **Profiles:** a member's profile shows their 5 most recent posts that the viewer may see.
- **Privacy:** export lists the member's posts; erase deletes their posts, photos and likes.
- **Verification** (local WordPress 7.1.2):
  - new `tests/feed-e2e.sh` 41/41;
  - `chastity-e2e.sh` 55/55, `homework-e2e.sh` 44/44, `dynamics-e2e.sh` 34/34, `setup-e2e.sh` 32/32, `dob-e2e.sh` 34/34, `e2e.sh` 60/60, `account-e2e.sh` 64/64, `profile-e2e.sh` 68 (+12 photo checks needing ImageMagick); no PHP warnings;
  - rendered at 375 px and 1280 px under Hello Elementor; fixed avatar circles and author links that the member area's own link rule was overriding.

---

## 0.8.0

**Chastity tracking** (owner, 2026-10-04: "For tracking chastity we can use Chaster as an example"; the tracker's chastity options). Built overnight from `docs/mockups/chastity.html`; **not yet approved by the owner.**

- **`CMP_Chastity`**, two new tables `cmp_locks`, `cmp_lock_events` (`CMP_DB_VERSION` 6), a **Chastity** tab, and a `lock` notification category ("Chastity lock updates", members can turn it off).
- **Starting a lock (the wearer):** self-lock, or with a keyholder who leads them in an active directed dynamic (`CMP_Dynamics`). One active lock at a time. Options from the tracker: Full lockdown · One release a week (with permission) · Daily routine, no release · Custom (name, rule, release policy: none / with permission / allowed). Planned length 1 hour – 30 days or open-ended; optional photo.
- **Keyholder:** add or remove time (never below now), hide the time left, change the rules, daily verification on/off, hygiene allowance (none / 10 / 15 / 30 / 60 minutes), allow one release, review verification photos (accept / ask for another), unlock.
- **Wearer:** live "Locked for" timer; time left unless hidden (then neither the end date, time changes, nor the planned length are shown to them); verification photo with **today's code** (6 characters, different per lock and per day, keyed with the site salt), to write on paper in the photo, like Chaster; hygiene opening and relock (flagged when longer than allowed); log a release (recorded either way, flagged when the rule didn't allow it); ask to be unlocked; self-locks unlock once their time is up.
- **Safety:** "Emergency unlock" is always available to the wearer, ends the lock at once, is recorded, and tells the keyholder. The page says plainly that this tracks a lock and doesn't control a device.
- **Dynamic ends:** the keyholder loses access at once (controls and photos); the lock carries on as a self-lock, timer shown again, wearer told.
- **Photos:** re-encoded and resized like homework proof (no location data), stored privately, served only to the wearer and their current keyholder (`?cmp_lockpic=<id>`, 404 for anyone else).
- **Calendar:** this month's locked days and verified days (Chaster's activity calendar). Past locks listed with length and how they ended.
- **Profile:** a "🔒 Locked · N days" badge, only if the wearer turns it on (off by default).
- **Privacy:** export lists locks (as wearer or keyholder) and the wearer's full history; erase deletes the wearer's locks and history, and turns locks they held as keyholder into self-locks.
- **Verification** (local WordPress 7.1.2):
  - new `tests/chastity-e2e.sh` 55/55;
  - `homework-e2e.sh` 44/44, `dynamics-e2e.sh` 34/34, `setup-e2e.sh` 32/32, `dob-e2e.sh` 34/34, `e2e.sh` 60/60, `account-e2e.sh` 64/64, `profile-e2e.sh` 68 (+12 photo checks needing ImageMagick); no plugin PHP warnings;
  - rendered at 375 px under Hello Elementor (wearer and keyholder views).

---

## 0.7.0

**Homework programs** (owner, 2026-10-04: the Weekly Homework Tracker spreadsheet is "a great example of how a Dom might manage their submissive"; the core features should "enhance the tasks and calendar" and let members "manage their dynamics"). Built overnight from `docs/mockups/homework.html`; **not yet approved by the owner.**

- **`CMP_Homework`**, three new tables `cmp_programs`, `cmp_tasks`, `cmp_task_entries` (`CMP_DB_VERSION` 5).
- **Who can set homework:** only the leading side of an active directed dynamic (`CMP_Dynamics::lead_can_direct()`). When the dynamic ends, its programs are archived at once: no more entries, the leading side loses access to proof photos immediately, and the member keeps their history read-only.
- **A program** = title, notes to the member, and a consequence ladder (up to 8 rows: level, examples, correction), modelled on the tracker's "Consequences" sheet.
- **Tasks** (up to 30 per program), modelled on the tracker's task list:
  - category: daily ritual, body / grooming, domestic service, education, attention / check-in, reflection, chastity, fitness, other;
  - weekly minimum (1–7), "what counts", an optional standard phrase (e.g. "Good morning, Sir.");
  - proof: none, text, photo, photo + text, written report.
- **Logging (member):** done / moved / not applicable for today or the last 7 days, never future days; note and proof photo as the task asks. Photos are resized to ≤1600 px and re-encoded as JPEG, which strips EXIF/GPS; stored privately in the database and served only to the two members of the program (`?cmp_proof=<id>`, 404 for anyone else).
- **Review (lead):** accept, "not good enough" (doesn't count toward the quota), or mark a day missed; optional note back. Notifications for new assignments, submissions, and review decisions.
- **Week view:** Monday-start week with Mon–Sun cells per task, "quotas met", an overall status (On track / Needs work), entries waiting for review, and a month heat map.
- **Homework tab** in the member area: "Your homework" and "Homework you set". Export lists a member's programs, tasks and entries (without photo bytes); erase deletes them (before dynamics).
- **Index names:** MySQL 8 reserves `LEAD` and `MEMBER`, so every index added in 0.6.0 / 0.7.0 is now named `idx_*` (`idx_proposer`, `idx_partner` on `cmp_dynamics`). 0.6.0 has not shipped, so no live table has the old names.
- **Verification** (local WordPress 7.1.2):
  - new `tests/homework-e2e.sh` 44/44 (incl. EXIF marker stripped from proof photos, proof photo 404 for a third member, archive on dynamic end);
  - `dynamics-e2e.sh` 34/34, `setup-e2e.sh` 32/32, `dob-e2e.sh` 34/34, `e2e.sh` 60/60, `account-e2e.sh` 64/64, `profile-e2e.sh` 68 (+12 photo checks needing ImageMagick); no PHP warnings;
  - rendered at 375 px and 1280 px under Hello Elementor.

---

## 0.6.0

**Dynamics between members** (owner, 2026-10-04: FetLife's relationships and power dynamics "will be very important for the homework and chastity tasks"). Built overnight from `docs/mockups/dynamics.html`; **not yet approved by the owner.**

- **`CMP_Dynamics`**, a new table `cmp_dynamics` (`CMP_DB_VERSION` 4). A dynamic is a type between a proposer and a partner:
  - 12 directed types, each a pair of sides: Keyholder / chastity wearer · Dominant / submissive · Daddy / boy · Daddy / girl · Mommy / boy · Owner / property · Master / slave · Handler / pup · Trainer / trainee · Mentor / mentee · Top / bottom · Caregiver / little;
  - 5 equal types: Partners · Married · Play partners · Leather family · Friends.
- **Consent rules:**
  - nothing happens until the partner accepts (pending → active);
  - either member can end an active dynamic alone, at any time, and it takes effect immediately (`cmp_dynamic_ended` action);
  - the proposer can withdraw a pending invitation;
  - only the partner can accept or decline.
- **Who may direct whom:** `CMP_Dynamics::lead_can_direct( $lead, $member, $type = null )` is true only for the leading side of an active directed dynamic. Releases 0.7.0 (homework) and 0.8.0 (chastity) build on it. Equal types give nobody that.
- **Connections:** members in an active dynamic count as connected (`cmp_are_connected`), so "My connections" visibility now works.
- **Screens:**
  - a new **Dynamics** tab ("Dynamics (N)" when invitations are waiting) with Waiting for you / Active / Sent by you;
  - "Propose a dynamic" on member profiles (type, your side, optional message up to 500 characters);
  - a Dynamics card on profiles, shown only while active and only if both members leave "Show on my profile" on.
- **Limits:** at most 10 pending invitations sent at once; no second pending or active dynamic of the same type between the same two members; members only; not to yourself; nonce-checked; same "no longer available" answer for anything not yours.
- **Notifications** (invitation; accepted / declined / ended) and an audit trail (proposed, accepted, declined, withdrawn, ended). Export lists a member's dynamics; erase deletes them.
- **Verification** (local WordPress 7.1.2):
  - new `tests/dynamics-e2e.sh` 34/34;
  - `setup-e2e.sh` 32/32, `dob-e2e.sh` 34/34, `e2e.sh` 60/60, `account-e2e.sh` 64/64, `profile-e2e.sh` 68 (+12 photo checks needing ImageMagick);
  - one profile check now reads only the profile card, because "Leather family" is also a dynamic type in the Propose form on that page;
  - no PHP warnings;
  - checked on Hello Elementor at 375px: invitation, accept, active list and the profile Dynamics card.

## 0.5.0

**Profiles v2 and step-by-step profile setup** (owner requests 2026-10-04: profiles modeled on Sniffies for phones and FetLife for desktop, data points and "Looking for" from Sniffies / FetLife / Chaster, and "filling out a member profile in stages … with a bio" right after sign-up). Built overnight while the owner was away; the mockup is `docs/mockups/profiles-v2.html` and the research notes are linked from the PR. **Not yet approved by the owner.**

- **New fields**, grouped into sections (Basics, Identity, Stats, Scene, Kinks & limits, Health):
  - Basics: Hosting, How active (FetLife's scale).
  - Identity: Gender (up to 3, plus "other"), Position, Expression (own words). "Orientation / identity" is now labelled Orientation.
  - Stats: Height (list, stored in inches, shown 5'11"), Weight (lb).
  - Scene: Not looking for.
  - Kinks & limits: Kinks, rated Chaster-style (Love it / Like it / Curious, plus Giving / Receiving / Both); Hard limits.
  - Health: Safer-sex practices, Last tested (month), Substances.
  - Bio grows to 1,000 characters.
- **Starter lists** for every option list, trimmed from the research (e.g. 36 roles instead of FetLife's 813, 30 kinks, 16 "looking for"). `CMP_Profile_Fields::seed_defaults()` runs on upgrade (`CMP_DB_VERSION` 3) and only fills lists that have no options at all, so the site team's own lists are untouched.
- **Health starts hidden** even when filled in: the one exception to "a filled-in field starts shown" (`'sensitive'` in the dictionary, `data-cmp-sensitive` on the switch).
- **Left out on purpose** (easy to add if wanted): HIV status as its own field (practices + last tested cover it with less exposure), endowment.
- **Step-by-step setup** (`CMP_Onboarding`): six steps (Photo & bio · Basics · Identity & roles · Stats · Kinks & limits · Health).
  - Each step saves only its own fields through the normal profile save (`only[]`, `cmp_return`), so validation and visibility rules are shared. Errors return to the same step with answers kept.
  - "Skip for now", "Back", "Set up my profile later". "Skip" on the last step finishes.
  - A new member's home opens the steps and stays there until they finish or choose later, resuming where they left off. Members who already had something on their profile get a "Finish your profile" card instead.
  - The photo upload returns to the step (`cmp_return` on the photo form).
- **Profile display redesign** (`card_html()`), laid out by the profile's own width (CSS container queries):
  - narrow (phones), Sniffies-style: photo first, name with a FetLife-style "41 M Dominant" tag, an auto-built stat line (age · height · weight · body type · orientation · position · roles), the bio as a quote, then chip sections, kinks with level and direction, hard limits and health;
  - from 600px, FetLife-style: header band with a square photo and a details table (Gender, Pronouns, Orientation, Roles, Expression, Active, Looking for, Member since);
  - from 820px, a Stats column beside the About column.
- **Verification** in a local WordPress 7.1.2 (PHP 8.4, SQLite):
  - new `tests/setup-e2e.sh` 32/32 (starter lists, one-step saves, resume, rated kinks, height/weight/month validation, health hidden until switched on, finish/later/skip, nudge for existing members, display);
  - `profile-e2e.sh` 68 (+12 photo checks needing ImageMagick), `dob-e2e.sh` 34/34, `e2e.sh` 60/60, `account-e2e.sh` 64/64, no PHP warnings;
  - checked in the browser on Hello Elementor at 375px, 710px and 1215px wide.

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
