#!/usr/bin/env bash
# Run on your Mac. Pulls the latest plugin zips from GitHub and puts them in
# your folder, replacing older versions THAT THIS SCRIPT PUT THERE.
#
#   bash sync-to-mac.sh                      # uses ~/Claude Code
#   bash sync-to-mac.sh "/path/to/folder"    # or any folder you choose
#
# Safety rules:
# - It only ever replaces zips listed in its own record file
#   (.colandb-sync-manifest in the folder). Zips that came from anywhere
#   else (for example another developer's build) are never touched, even if
#   their names look like an older version.
# - Nothing is deleted: a replaced zip is moved to "Previous versions".
# - If the folder holds an unmanaged zip of a plugin with a HIGHER version
#   than the one being synced, it stops and warns instead of continuing.
#
# Optional settings (environment variables):
#   COLANDB_REPO    where the repository copy lives   (default ~/colandb-repo)
#   COLANDB_BRANCH  which branch to take zips from     (default: main, or the pre-main branch)
set -euo pipefail

DEST=${1:-"$HOME/Claude Code"}
REPO=${COLANDB_REPO:-"$HOME/colandb-repo"}
URL=${COLANDB_URL:-https://github.com/phillipweasley-gif/colandb.git}
# Releases live on main; before main existed they were on this branch.
BRANCH=${COLANDB_BRANCH:-}
if [ -z "$BRANCH" ]; then
	if git ls-remote --exit-code --heads "$URL" main >/dev/null 2>&1; then BRANCH=main; else BRANCH=claude/nice-edison-cbh5sw; fi
fi
MANIFEST="$DEST/.colandb-sync-manifest"
ARCHIVE="$DEST/Previous versions"

if [ ! -d "$REPO/.git" ]; then
	echo "First run: downloading the repository to $REPO"
	git clone --quiet --branch "$BRANCH" "$URL" "$REPO"
else
	git -C "$REPO" fetch --quiet origin "$BRANCH"
	# The copy is download-only; never edit it. -B also handles switching branches.
	git -C "$REPO" checkout --quiet --force -B "$BRANCH" "origin/$BRANCH"
fi

mkdir -p "$DEST"
touch "$MANIFEST"

managed() { grep -qxF -- "$1" "$MANIFEST"; }

# version_gt A B: true if version A is higher than version B (e.g. 1.25.3 > 1.25.1).
version_gt() {
	[ "$1" = "$2" ] && return 1
	[ "$(printf '%s\n%s\n' "$1" "$2" | sort -t. -k1,1n -k2,2n -k3,3n -k4,4n | tail -1)" = "$1" ]
}

# First pass: refuse to continue if something newer that we didn't make is present.
for zip in "$REPO"/dist/*.zip; do
	[ -e "$zip" ] || continue
	name=$(basename "$zip"); slug=${name%-*}; ours=${name#"$slug"-}; ours=${ours%.zip}
	for other in "$DEST/$slug"-[0-9]*.zip; do
		[ -e "$other" ] || continue
		oname=$(basename "$other")
		managed "$oname" && continue
		[ "$oname" = "$name" ] && continue
		theirs=${oname#"$slug"-}; theirs=${theirs%.zip}
		if version_gt "$theirs" "$ours"; then
			echo "STOPPED: $oname is newer than $name and was not put here by this script."
			echo "It is probably another developer's build. Send it to Claude so the changes can be merged,"
			echo "then run this again. Nothing was changed."
			exit 1
		fi
	done
done

changed=0
for zip in "$REPO"/dist/*.zip; do
	[ -e "$zip" ] || continue
	name=$(basename "$zip")       # e.g. community-events-calendar-1.26.0.zip
	slug=${name%-*}               # e.g. community-events-calendar

	for old in "$DEST/$slug"-[0-9]*.zip; do
		[ -e "$old" ] || continue
		oname=$(basename "$old")
		[ "$oname" = "$name" ] && continue
		if managed "$oname"; then
			mkdir -p "$ARCHIVE"
			mv -f "$old" "$ARCHIVE/$oname"
			grep -vxF -- "$oname" "$MANIFEST" > "$MANIFEST.tmp" || true
			mv "$MANIFEST.tmp" "$MANIFEST"
			echo "archived  $oname  (moved to \"Previous versions\")"
			changed=1
		else
			echo "kept      $oname  (not from this script; left untouched)"
		fi
	done

	if [ -e "$DEST/$name" ] && cmp -s "$zip" "$DEST/$name"; then
		echo "current   $name"
	else
		cp "$zip" "$DEST/$name"
		echo "updated   $name"
		changed=1
	fi
	managed "$name" || echo "$name" >> "$MANIFEST"
done

[ "$changed" = 1 ] && echo "Done: $DEST now has the latest versions." || echo "Done: everything in $DEST was already up to date."
