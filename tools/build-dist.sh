#!/usr/bin/env bash
# Rebuilds dist/ so it holds exactly one upload-ready zip per plugin, named
# <plugin-folder>-<version>.zip, with the version read from the plugin's own
# header. Older zips of the same plugin are deleted (git keeps the history).
# Run from anywhere: bash tools/build-dist.sh
set -euo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
DIST="$REPO/dist"
mkdir -p "$DIST"

# Plugin folder (relative to the repo) : main plugin file inside it.
PLUGINS="
community-events-calendar:community-events-calendar.php
community-member-planning:community-member-planning.php
colandb-updater:colandb-updater.php
tools/cmp-hosting-check:cmp-hosting-check.php
"

for entry in $PLUGINS; do
	dir=${entry%%:*}
	main=${entry#*:}
	slug=$(basename "$dir")
	version=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][0-9.]*\).*/\1/p' "$REPO/$dir/$main" | head -1)
	if [ -z "$version" ]; then
		echo "ERROR: no Version: header in $dir/$main" >&2
		exit 1
	fi

	for old in "$DIST/$slug"-[0-9]*.zip; do
		[ -e "$old" ] || continue
		[ "$(basename "$old")" = "$slug-$version.zip" ] && continue
		rm -f "$old"
		echo "removed  $(basename "$old")"
	done

	# A version's zip is built once: code can't change without a new version
	# (CI enforces it), and rebuilding would only change zip timestamps.
	# Use --force to rebuild anyway.
	if [ -f "$DIST/$slug-$version.zip" ] && [ "${1:-}" != "--force" ]; then
		echo "exists   $slug-$version.zip"
		continue
	fi

	# Zip from the parent folder so the zip's top folder is the plugin slug,
	# which is what WordPress's "Upload Plugin" expects.
	rm -f "$DIST/$slug-$version.zip"
	(cd "$REPO/$(dirname "$dir")" && zip -rqX "$DIST/$slug-$version.zip" "$slug" -x "*/.git/*" "*/.DS_Store")
	echo "built    $slug-$version.zip"
done
