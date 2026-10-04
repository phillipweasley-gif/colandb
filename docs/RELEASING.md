# Releases and updates: owner's guide

## How a change reaches your sites

```
Claude session ── pull request ──► main ──► GitHub pre-release ──► STAGING installs it automatically
                                                    │
                          you check staging, then run "Promote release to live"
                                                    ▼
                                             stable release ──► LIVE shows "Update available" → you click Update
```

- Every plugin version is released exactly once, tagged `<plugin>-v<version>` (e.g. `community-events-calendar-v1.26.1`), with the zip attached.
- CI refuses a pull request that changes a plugin without raising its version, so two versions can never share a number again.

## One-time setup

### 1. Create the GitHub token (once, used by both sites)
1. GitHub → your profile picture → **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**.
2. **Name:** `colandb site updates`. **Expiration:** 1 year (put a reminder in your calendar to renew it).
3. **Repository access:** *Only select repositories* → `phillipweasley-gif/colandb`.
4. **Permissions → Repository permissions → Contents: Read-only.** Leave everything else at *No access*.
5. **Generate token**, and copy it (it starts with `github_pat_`). GitHub shows it only once.

The token can only *read* this one repository. It can't change code, see your other repositories, or touch your account.

### 2. Install the updater on staging, then live
On each site:
1. **Plugins → Add New → Upload Plugin** → `colandb-updater-1.0.0.zip` → Install → Activate.
2. **Settings → Plugin Updates:** paste the token, choose **This site is: Staging** (on staging) or **Live** (on colandb.com), and click **Save**. The page should say **Connected to GitHub**.

After that, you never upload these plugins by hand again.

## Day to day

| When | What you do |
|---|---|
| A Claude session says a release is merged | Nothing. Staging updates itself within a few hours (or go to **Settings → Plugin Updates → Check for updates now** to get it immediately). |
| You've checked it on staging and want it live | GitHub → **Actions → Promote release to live → Run workflow** → choose the plugin and type the version → **Run**. |
| Live site after promoting | **Plugins** shows "There is a new version…" → click **Update now** (or **Settings → Plugin Updates → Check for updates now** first). |
| Want a copy of the zips on your Mac | `bash ~/colandb-repo/tools/sync-to-mac.sh` in Terminal. |

## If something goes wrong

- **"GitHub rejected the token (401)"**: the token expired or was revoked. Make a new one (step 1) and save it on both sites.
- **"returned 404"**: the token doesn't include the `colandb` repository. Edit the token's repository access.
- **A release broke staging:** don't promote it. Tell a Claude session what broke; it fixes it as a new version, and staging moves on to that. To roll staging back right away, upload the previous zip from the release list on GitHub (Plugins → Add New → Upload → Replace current).
- **Plugin folder names matter:** the updater only manages plugins installed in their normal folders (`community-events-calendar`, `community-member-planning`, …). A copy installed under another folder name is ignored.
