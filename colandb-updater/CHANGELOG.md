# Changelog — COL&B Plugin Updater

## 1.0.6

- **Fix: updates on the staging site downloading something other than our release.** After 1.0.5, Settings → Plugin Updates on staging showed neither "Last install attempt" nor "Last failed download" after a failed update, so this plugin's download code was never called: the download address WordPress used for the update was not our GitHub release address (something on the host replaces it in WordPress's update list), WordPress fetched that address itself, and the result was not our zip ("PCLZIP_ERR_BAD_FORMAT"). The updater now hooks `upgrader_package_options`, the last point before WordPress downloads, and for its own plugins sets the address back to the GitHub release; its own download (with the token) then runs as designed.
- New **"Last update"** section on Settings → Plugin Updates, and a line on the update screen, showing the address WordPress had and whether it was replaced, so we can see what the host substitutes.
- Verified locally with a stand-in for the host that rewrites the address in WordPress's update list: 1.0.5 fails, 1.0.6 installs and reports the replacement.

## 1.0.5

- **Fix: updates failing on the staging site at "Unpacking the update…" with PclZip's "Unable to find End of Central Dir Record".** 1.0.4's checks showed the download itself is complete and valid every time (all four plugins, sizes matching, strict ZipArchive check passing), and the update screen skipped WordPress's own "Downloading update from…" line, so this plugin's download was being used; the file was being removed or altered on the host between download and unpack. The updater now keeps a second verified copy of each download in its own folder (`wp-content/colandb-updater-cache/`, random names, removed after the update and after an hour at most), and when WordPress starts unpacking it checks the file: if it was deleted or no longer matches the verified fingerprint, the verified copy is put back first. Only ever restores the exact file this updater downloaded.
- The update screen now says what happened when that repair is needed (deleted, or changed with sizes and first bytes), and so does "Last install attempt".
- Verified locally against the previous version: with the download deleted before unpacking (temporary folder set to WordPress's upgrade folder) and with it cut in half, 1.0.4 fails and 1.0.5 installs. Updater suite 34/34.

## 1.0.4

- **Install diagnostics.** On staging, 1.0.3's Test download succeeded (valid zip, nothing else hooked into downloads) but installing still failed in WordPress's unpack step with PclZip's error. Our release zips open cleanly with both ZipArchive (strict) and PclZip, so the file WordPress unpacks there must differ from the one downloaded. The updater now records the downloaded file (path, size, fingerprint) and, at the start of WordPress's `unzip_file()` (via `unzip_file_use_ziparchive`, which runs before either zip reader can fail), the file WordPress actually unpacks, whether ZipArchive is switched off by another plugin, and how both zip readers judge it. Shown under "Last install attempt" on Settings → Plugin Updates. Purely diagnostic.
- **Downloads are checked the way WordPress will open them:** the size received must match the size the server announced (a download that stops early still starts with the zip signature, so 1.0.2's signature check alone could pass it), and the zip is opened in ZipArchive's strict `CHECKCONS` mode, the same mode `unzip_file()` uses. Either failure stops the update with a message saying so.
- **Test download now tests every installed plugin's newest release**, not only the updater's own small zip.

## 1.0.3

- **Fix: updates failing with "PCLZIP_ERR_BAD_FORMAT … Unable to find End of Central Dir Record" on the staging site.** The failure came back with WordPress's own unzip message rather than 1.0.2's new zip check, so this plugin's download code was not the one fetching the file: another plugin hooked into WordPress's download step got there first and fetched the private GitHub address without the token, receiving GitHub's "Not Found" reply instead of the zip. The updater now handles its own GitHub packages before anything else (priority -1000) and regardless of what an earlier filter returned; every other download is left alone.

## 1.0.2

Diagnostics for a failed update on the staging site ("PCLZIP_ERR_BAD_FORMAT … Unable to find End of Central Dir Record": the file handed to WordPress was not a zip).
- Every download is now checked for the zip signature directly, so it no longer depends on PHP's ZipArchive being available. If something other than a zip comes back, the update stops with a message saying what arrived (each HTTP step, the content type and the start of the response) instead of WordPress's unzip error.
- Without ZipArchive, the folder check now uses WordPress's bundled PclZip instead of being skipped.
- New **Test download** button on Settings → Plugin Updates: downloads the newest updater release from GitHub without installing it, and lists each step, whether ZipArchive is available, and any other plugin hooked into WordPress's download step.
- The last failed download is shown on the settings page.
- Verified against the mock GitHub, including a storage response that is an HTML page: 30/30.

## 1.0.1

- **"Update now" buttons on Settings → Plugin Updates** for any plugin with a newer release. Each one refreshes WordPress's update information and then opens WordPress's own plugin updater screen, so the install itself is WordPress's standard process. Shown only to users who can update plugins, and not when file changes are disabled on the site.

## 1.0.0

First release. Updates the site's custom plugins from the GitHub repository's releases.
- Staging sites install new releases (including pre-releases) automatically through WordPress's background updates; the live site offers stable releases as a normal one-click "Update now".
- Reads the private repository with a read-only fine-grained token (Settings → Plugin Updates, or `COLANDB_GITHUB_TOKEN` in wp-config.php). The token is sent only to api.github.com, never to the storage server GitHub redirects downloads to, and is never shown again after saving.
- Checks every downloaded zip contains exactly the expected plugin folder before WordPress installs it.
- Verified in a local WordPress 7.1.2 install against a mock GitHub API: 27/27 checks (channels, tokens, install, background auto-update, rejected zips, token never leaked).
