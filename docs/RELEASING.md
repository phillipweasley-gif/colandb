# Releases and updates: owner's guide

## How a change reaches your site

There is one site: colandb.com. The staging site was retired on 2026-10-04, so nothing installs a release until you promote it.

```
Claude session ── tested in the local kit ── pull request ──► main ──► GitHub pre-release (nothing installs it)
                                                                            │
                                         you back up colandb.com, then run "Promote release to live"
                                                                            ▼
                                                     stable release ──► LIVE shows "Update available" → you click Update
                                                                            ▼
                                                     a Claude session checks the live site's public pages (read-only)
```

- Every plugin version is released exactly once, tagged `<plugin>-v<version>` (e.g. `community-events-calendar-v1.26.1`), with the zip attached.
- CI refuses a pull request that changes a plugin without raising its version, so two versions can never share a number again.

## One-time setup

### 1. Create the GitHub token (once)
1. GitHub → your profile picture → **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**.
2. **Name:** `colandb site updates`. **Expiration:** 1 year (put a reminder in your calendar to renew it).
3. **Repository access:** *Only select repositories* → `phillipweasley-gif/colandb`.
4. **Permissions → Repository permissions → Contents: Read-only.** Leave everything else at *No access*.
5. **Generate token**, and copy it (it starts with `github_pat_`). GitHub shows it only once.

The token can only *read* this one repository. It can't change code, see your other repositories, or touch your account.

### 2. Install the updater on colandb.com
1. **Plugins → Add New → Upload Plugin** → the `colandb-updater` zip → Install → Activate.
2. **Settings → Plugin Updates:** paste the token, choose **This site is: Live**, and click **Save**. The page should say **Connected to GitHub**. (Leave it on **Live**: a site set to Staging installs untested pre-releases automatically.)

After that, you never upload these plugins by hand again.

## Day to day

| When | What you do |
|---|---|
| A Claude session says a release is merged | Nothing installs it yet. Read what changed and how it was tested (the session's summary, or the plugin's `CHANGELOG.md`). |
| You want it live | 1. **Back up colandb.com** in your hosting panel (with no staging, this is your way back). 2. GitHub → **Actions → Promote release to live → Run workflow** → choose the plugin and type the version → **Run** (or ask the Claude session to do it). |
| Live site after promoting | **Settings → Plugin Updates → Check for updates now**, then **Plugins** → **Update now**. Then ask the Claude session to check the live site. |
| Want a copy of the zips on your Mac | `bash ~/colandb-repo/tools/sync-to-mac.sh` in Terminal. |

## If something goes wrong

- **"GitHub rejected the token (401)"**: the token expired or was revoked. Make a new one (step 1) and save it on colandb.com.
- **"returned 404"**: the token doesn't include the `colandb` repository. Edit the token's repository access.
- **A release broke the live site:** roll back right away by uploading the previous version's zip from the release list on GitHub (Plugins → Add New → Upload Plugin → **Replace current with uploaded**), or restore the backup you made before updating. Then tell a Claude session what broke; it fixes it as a new version.
- **Plugin folder names matter:** the updater only manages plugins installed in their normal folders (`community-events-calendar`, `community-member-planning`, …). A copy installed under another folder name is ignored.
