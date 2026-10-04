# Changelog — COL&B Plugin Updater

## 1.0.1

- **"Update now" buttons on Settings → Plugin Updates** for any plugin with a newer release. Each one refreshes WordPress's update information and then opens WordPress's own plugin updater screen, so the install itself is WordPress's standard process. Shown only to users who can update plugins, and not when file changes are disabled on the site.

## 1.0.0

First release. Updates the site's custom plugins from the GitHub repository's releases.
- Staging sites install new releases (including pre-releases) automatically through WordPress's background updates; the live site offers stable releases as a normal one-click "Update now".
- Reads the private repository with a read-only fine-grained token (Settings → Plugin Updates, or `COLANDB_GITHUB_TOKEN` in wp-config.php). The token is sent only to api.github.com, never to the storage server GitHub redirects downloads to, and is never shown again after saving.
- Checks every downloaded zip contains exactly the expected plugin folder before WordPress installs it.
- Verified in a local WordPress 7.1.2 install against a mock GitHub API: 27/27 checks (channels, tokens, install, background auto-update, rejected zips, token never leaked).
