#!/usr/bin/env bash
# Checks and publishes plugin releases. Used by .github/workflows/release.yml.
#
#   bash tools/release.sh --check     # pull requests: fail if a plugin changed without a version bump
#   bash tools/release.sh --publish   # main: publish every plugin version that has no release yet
#
# Each plugin version is released once, as a GitHub pre-release tagged
# "<plugin-folder>-v<version>" with the zip "<plugin-folder>-<version>.zip".
# Pre-releases go to staging sites; "Promote release" marks one stable for live.
set -euo pipefail
MODE=${1:---check}
REPO=$(cd "$(dirname "$0")/.." && pwd)
GH=${GH:-gh}
cd "$REPO"

# Plugin folder : main file. Add new plugins here and in tools/build-dist.sh.
PLUGINS="
community-events-calendar:community-events-calendar.php
community-member-planning:community-member-planning.php
colandb-updater:colandb-updater.php
tools/cmp-hosting-check:cmp-hosting-check.php
"

git fetch --quiet --tags origin 2>/dev/null || true

plugin_version() {
	sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][0-9.]*\).*/\1/p' "$1" | head -1
}

# version_gt A B: true if A > B.
version_gt() {
	[ "$1" = "$2" ] && return 1
	[ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -1)" = "$1" ]
}

# The CHANGELOG section for one version (used as the release notes).
notes_for() {
	local changelog=$1 version=$2
	[ -f "$changelog" ] || { echo "See the plugin's CHANGELOG.md."; return; }
	awk -v v="## $version" '
		index($0, v) == 1 && (length($0) == length(v) || substr($0, length(v) + 1, 1) ~ /[ \t(]/) { on = 1; next }
		on && /^## / { exit }
		on { print }
	' "$changelog"
}

DEFAULT_NOTES="See the plugin's CHANGELOG.md."
problems=0
[ "$MODE" = "--publish" ] && bash tools/build-dist.sh >/dev/null

for entry in $PLUGINS; do
	dir=${entry%%:*}; main=${entry#*:}; slug=$(basename "$dir")
	[ -f "$dir/$main" ] || continue
	version=$(plugin_version "$dir/$main")
	tag="$slug-v$version"

	# release-hold.txt: one plugin folder name per line (# comments allowed).
	# A held plugin is checked but never published, e.g. while outside work
	# on it is being merged. Remove the line to release it.
	if [ -f release-hold.txt ] && sed 's/#.*//' release-hold.txt | grep -qxF "$slug"; then
		echo "held      $slug $version (listed in release-hold.txt; not published)"
		continue
	fi

	# Highest version already released for this plugin.
	highest=$(git tag -l "$slug-v*" | sed "s/^$slug-v//" | grep -E '^[0-9]+(\.[0-9]+)*$' | sort -V | tail -1 || true)

	if git rev-parse -q --verify "refs/tags/$tag" >/dev/null; then
		if ! git diff --quiet "$tag" HEAD -- "$dir"; then
			echo "::error::$slug changed since its $version release, but its Version is still $version. Raise the version in $dir/$main and add a CHANGELOG entry."
			problems=1
		else
			echo "ok        $slug $version (already released, unchanged)"
		fi
		continue
	fi

	if [ -n "$highest" ] && ! version_gt "$version" "$highest"; then
		echo "::error::$slug version $version is not higher than the latest release ($highest)."
		problems=1
		continue
	fi

	if [ "$MODE" = "--publish" ]; then
		zip="dist/$slug-$version.zip"
		[ -f "$zip" ] || { echo "::error::$zip was not built"; problems=1; continue; }
		name=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Plugin Name:[[:space:]]*//p' "$dir/$main" | head -1)
		notes=$(notes_for "$dir/CHANGELOG.md" "$version")
		"$GH" release create "$tag" "$zip" --prerelease --target "$(git rev-parse HEAD)" \
			--title "$name $version" --notes "${notes:-$DEFAULT_NOTES}"
		echo "released  $slug $version as a pre-release (staging installs it automatically)"
	else
		echo "new       $slug $version (will be released when merged to main)"
	fi
done

exit $problems
