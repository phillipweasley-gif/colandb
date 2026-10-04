# Changelog — COL&B Plugin Updater

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
