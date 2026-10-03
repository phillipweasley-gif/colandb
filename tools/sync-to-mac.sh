#!/usr/bin/env bash
# Run on your Mac. Pulls the latest plugin zips from GitHub and puts them in
# your folder, replacing any older version of the same plugin.
#
#   bash sync-to-mac.sh                      # uses ~/Claude Code
#   bash sync-to-mac.sh "/path/to/folder"    # or any folder you choose
#
# Only files named <plugin>-<version>.zip for these plugins are ever
# removed or replaced; nothing else in the folder is touched.
#
# Optional settings (environment variables):
#   COLANDB_REPO    where the repository copy lives   (default ~/colandb-repo)
#   COLANDB_BRANCH  which branch holds the releases   (default below)
set -euo pipefail

DEST=${1:-"$HOME/Claude Code"}
REPO=${COLANDB_REPO:-"$HOME/colandb-repo"}
BRANCH=${COLANDB_BRANCH:-claude/nice-edison-cbh5sw}
URL=${COLANDB_URL:-https://github.com/phillipweasley-gif/colandb.git}

if [ ! -d "$REPO/.git" ]; then
	echo "First run: downloading the repository to $REPO"
	git clone --quiet --branch "$BRANCH" "$URL" "$REPO"
else
	git -C "$REPO" fetch --quiet origin "$BRANCH"
	git -C "$REPO" checkout --quiet "$BRANCH"
	git -C "$REPO" reset --quiet --hard "origin/$BRANCH" # the copy is download-only; never edit it
fi

mkdir -p "$DEST"
changed=0
for zip in "$REPO"/dist/*.zip; do
	[ -e "$zip" ] || continue
	name=$(basename "$zip")       # e.g. community-events-calendar-1.26.0.zip
	slug=${name%-*}               # e.g. community-events-calendar

	for old in "$DEST/$slug"-[0-9]*.zip; do
		[ -e "$old" ] || continue
		[ "$(basename "$old")" = "$name" ] && continue
		rm -f "$old"
		echo "removed   $(basename "$old")"
		changed=1
	done

	if [ -e "$DEST/$name" ] && cmp -s "$zip" "$DEST/$name"; then
		echo "current   $name"
	else
		cp "$zip" "$DEST/$name"
		echo "updated   $name"
		changed=1
	fi
done

[ "$changed" = 1 ] && echo "Done: $DEST now has the latest versions." || echo "Done: everything in $DEST was already up to date."
