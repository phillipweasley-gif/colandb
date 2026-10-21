# Privacy and security plan for intimate photos

Owner request, 2026-10-21: "install 0.31.1 and start the end-to-end encryption and build a plan/address the other concerns until completed". This follows CMP 0.31.0/0.31.1, which added encryption at rest, the 90-day purge and private share links for chastity photos.

This is not legal advice. Items marked **(owner)** need the owner, a lawyer or the host; everything else is built in the plugins.

## Where things stand

| Concern | Status |
|---|---|
| Encryption at rest, chastity photos | Done, CMP 0.31.0 |
| 90-day purge, chastity photos | Done, CMP 0.31.0 |
| Share a photo without an account (not the daily code) | Done, CMP 0.31.0 |
| Host CDN never caches private photos | Done, CMP 0.31.1 |
| Explicit consent before the first intimate photo | CMP 0.32.0 |
| Homework proof photos: encryption and 90-day purge | CMP 0.32.0 |
| Members can download and delete their own private photos | CMP 0.32.0 |
| Report an intimate image (TAKE IT DOWN Act, 48-hour removal) | CMP 0.32.0, plus a public page on the site |
| Remove a private photo the site team can't see | CMP 0.32.0 (admin tool that works from the photo's link) |
| Child sexual abuse material: response plan | This document, below |
| Optional end-to-end encryption | CMP 0.33.0 |
| Dedicated photo key (`CMP_PHOTO_KEY` in wp-config.php) | **(owner)**: an automated session wasn't allowed to write secrets to wp-config.php |
| Backup retention, fill in privacy-page placeholders | **(owner)** confirms the plan's backup days. Hosting location and effective date filled in |
| Tell members about the changes | In-app notice after 0.33.0 is live |

## 1. Consent (CMP 0.32.0)

Intimate photos are "special category" data under GDPR (Art. 9, data about sex life) and "sensitive data" under several US state laws (Colorado, Connecticut, Virginia, Oregon, Texas and others), which require opt-in consent.

- Before a member's first chastity or homework photo, the upload form shows a required tick box. It says what's stored, who can see it, that it's encrypted and deleted after 90 days, and that they can delete it sooner.
- The server refuses the upload without it, then records the time (user meta `cmp_photo_consent_at`) and an audit-log row. The tick isn't shown again.
- **Withdrawing consent:** the member deletes their photos from "Your photos" (chastity) or the homework log. Deleting the account removes everything.

## 2. Homework proof photos (CMP 0.32.0)

These get the same treatment as chastity photos:
- sealed with `CMP_Photo_Vault`, in their own context (key and authenticated data bound to the program and member);
- older ones sealed on upgrade;
- deleted 90 days after the logged day (`cmp_retention_periods['homework_photos']`). The log entry stays and says "photo removed after 90 days".

## 3. Members control their own photos (CMP 0.32.0)

- **Download:** each photo in "Your photos" has a Download link (the same permission check; `Content-Disposition: attachment`). The data export lists photos and points to this, because WordPress's exporter only holds text.
- **Delete now:** the wearer can delete a chastity photo. Its share links stop and the history says "photo deleted". The member who logged homework can delete its proof photo the same way.

## 4. Reporting an intimate image (CMP 0.32.0)

The **TAKE IT DOWN Act** (signed May 19, 2025; platforms must comply by May 19, 2026) requires a site that hosts user content to:
- give people a clear process to report a non-consensual intimate image;
- remove it, and known copies, within **48 hours** of a valid request.

A valid request includes:
- a physical or electronic signature;
- enough information to find the image;
- a brief good-faith statement that it was shared without consent;
- contact information.

Built:
- **`[cmp_image_report]`**, a public form (no account needed) asking for:
  - contact email and optional name;
  - whether you're the person shown, reporting for them, or the image may show someone under 18;
  - where the image is (link or description) and details;
  - a good-faith statement tick, and a typed full name as the electronic signature.
- Spam protection: honeypot, a minimum time on the page, and 5 reports per address per day.
- Stored in `{prefix}cmp_image_reports` and kept 3 years, like member reports.
- On a new report:
  - the site's admin email gets an urgent email with the 48-hour deadline;
  - the reporter gets a confirmation;
  - a possible-minor report gets a different subject line and tells the team not to download or forward anything (see section 5).
- **Users → Image reports:**
  - lists reports with time left to the deadline;
  - lets the team mark each one **Removed** or **Not actioned**, with a note, and emails the reporter;
  - has a **Remove a private photo** tool. Paste the link to a chastity or homework photo, a share link, a feed photo or a profile photo, and the stored photo is deleted without ever being shown. Each removal is audit-logged.
- A public page on colandb.com holds the form, linked from the Privacy Policy and the Help & FAQ.

**(owner)** Someone must check the admin inbox at least daily so the 48-hour window can be met.

## 5. Child sexual abuse material: response plan

US law (18 U.S.C. § 2258A, as amended by the REPORT Act 2024) requires providers that learn of apparent child sexual abuse material on their service to report it to the **NCMEC CyberTipline** (report.cybertip.org), and to preserve the report's contents for **one year**.

If a report or anything else suggests an image shows someone under 18:
1. **Don't open, download, forward or screenshot it.** Viewing or copying it is itself illegal, even with good intentions.
2. Report it to the CyberTipline at report.cybertip.org, with the account, the photo link and when it was found, but not the image.
3. Stop access without destroying evidence:
   - suspend the account: Users → edit → change the role so they can't sign in (don't delete it);
   - revoke share links.
   - Ask NCMEC or law enforcement before deleting the stored photo. The law requires it to be preserved for a year, and the encrypted copy in the database is that record.
4. Record what was done and when, in the image-report notes, not in email.
5. Don't tell the account holder about the report.

The site's age check (date of birth at sign-up, under-18 lock) stays the first line of defence.

## 6. Backups, logs and keys

- **Backups (owner).** Elementor Hosting keeps automatic daily backups for 7 to 60 days, depending on the plan. Check the plan's number in the Elementor dashboard so the privacy page can state it. Purged or deleted photos stay in backups until those expire.
  - Full-site backups probably include wp-config.php, so a stolen full backup could include the key as well.
  - End-to-end mode (section 7) is the protection that holds even then.
- **Server logs.** The privacy page says the host and Cloudflare keep them under their own policies. We keep no copies.
- **Dedicated photo key (owner).** Add a long random value to wp-config.php, e.g. the output of `openssl rand -hex 32`, as a single line above `/* That's all, stop editing! */`:

  ```php
  define( 'CMP_PHOTO_KEY', '<64 hex characters>' );
  ```

  - Photos already stored keep opening (with the salts), and new photos use the new key.
  - After that, a security plugin resetting the site's login salts no longer affects photos.
  - Never change or delete the line once it's there.
- **Hosting location.** The site's outbound address belongs to Google Cloud europe-west1 (Belgium). Elementor Hosting runs on Google Cloud. The privacy page now says the site is hosted in the EU (Belgium).

## 7. Optional end-to-end encryption (CMP 0.33.0)

The goal: with the mode on, the server only ever holds data it cannot decrypt. Even someone with the database, wp-config.php and the backups can't see the photos. Only the wearer's and keyholder's browsers can.

**Keys**
- Each member who turns it on gets an ECDH P-256 key pair, made in their browser (WebCrypto).
- The private key is wrapped with AES-256-GCM under a key derived from a **photo passphrase** (PBKDF2-SHA-256, 600,000 iterations, random salt).
- The server stores:
  - the public key (`cmp_e2e_pub`);
  - the wrapped private key, salt, iterations and IV (`cmp_e2e_priv`).
- The passphrase never leaves the browser. **If the passphrase is lost, end-to-end photos can't be recovered.** The UI says so, and since photos are deleted after 90 days anyway, the loss is limited.
- After unlocking, the private key is kept as a non-extractable `CryptoKey` in that browser's IndexedDB. "Forget on this device" removes it, and so does a passphrase change on another device (the key-set id changes).

**Photos**
- With the mode on, the browser does the work the server did before:
  - it re-saves the photo through a canvas (strips EXIF/GPS, max 1600 px, JPEG quality 0.82);
  - it encrypts it with a random AES-256-GCM content key;
  - it wraps that content key for each recipient (the wearer, and the keyholder if they have a key) with ephemeral ECDH, HKDF-SHA-256 and AES-GCM.
- Envelope: `CMPE2E1` + JSON header length + header (version, recipients `[{uid, epk, iv, wk}]`, content IV) + ciphertext.
- The server checks the structure and recipients, then seals the whole envelope with `CMP_Photo_Vault` as well (defence in depth), stores it, and sets `photo_e2e = 1` (DB 19).
- Serving: the same permission check. Authorised viewers get the envelope as `application/octet-stream`; their browser decrypts it and shows it from a `blob:` URL.
- If the keyholder hasn't set up a key, the wearer is warned that the keyholder won't be able to see the photo. The keyholder sees "encrypted, not for you" and can still accept, or ask for another.
- A new keyholder can't see photos from before they held the key.
- While the mode is on, the optional photo on a new lock is hidden (send a verification photo instead).

**Share links**
- The wearer's browser unwraps the content key and adds it to the link as a URL fragment (`#k=…`). Browsers never send fragments to servers.
- The share page for these photos allows one inline script (CSP nonce). After **View photo** it decrypts the envelope in the page.
- The server keeps the same expiry, once-only, revoke and view-count rules.

**What it can't protect against:** someone who can see the photo taking a screenshot, or a compromised device or browser. It also needs a browser with WebCrypto (any current browser).

**Tests:** server-side envelope checks in `tests/chastity-e2e.sh`, and a Playwright run in real Chromium (`tests/e2e-photos.js`) covering:
- key setup;
- upload;
- wearer and keyholder decryption;
- a non-recipient refused;
- a share link with the fragment key;
- a wrong passphrase;
- "Forget on this device".

## 8. Remaining owner actions

1. Add `CMP_PHOTO_KEY` to wp-config.php (section 6).
2. Confirm how many days of backups the hosting plan keeps, so the privacy page can state it.
3. Make sure someone reads the admin inbox daily, for the 48-hour takedown window.
4. Ask a lawyer to review the Privacy Policy and the image-report process, particularly on:
   - age verification for adult content in your state;
   - whether 18 U.S.C. § 2257 record-keeping applies (it targets producers of sexually explicit material; members' private photos to their own keyholder are a different situation, but confirm).
