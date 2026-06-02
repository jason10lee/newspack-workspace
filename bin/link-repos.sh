#!/bin/bash

source /var/scripts/repos.sh
source /var/scripts/resolve-project-path.sh

set -e

# Non-matching globs expand to nothing rather than a literal pattern. Without
# this, an empty/absent /newspack-plugins mount left the loop iterating the
# literal "/newspack-plugins/*/" and created a stray "*" symlink.
shopt -s nullglob

# Create a symlink at $link pointing to $src, or repoint it if it's a stale
# /newspack-repos mirror of a plugin/theme now authoritative under plugins/ or
# themes/. A symlink to any OTHER target is treated as a genuine slug collision
# and left alone. Non-symlink files are never clobbered.
link_or_repoint() {
	local src="$1" link="$2" name existing
	name=$(basename "$src")
	if [ -L "${link}" ]; then
		existing=$(readlink "$link")
		if [ "$existing" = "$src" ]; then
			echo "$name already symlinked"
		elif [ "${existing#"${REPOS_PATH}"/}" != "$existing" ]; then
			# Existing link points into /newspack-repos: a pre-migration mirror.
			# The monorepo copy is authoritative and (checked by the caller)
			# non-empty, so repoint to it.
			echo "Repointing $name: $existing -> $src"
			rm -f "$link"
			ln -s "$src" "$link" || true
		else
			echo "[link-repos] warning: slug collision on '$name'" >&2
			echo "[link-repos]   existing: $link -> $existing" >&2
			echo "[link-repos]   skipping: $src" >&2
		fi
	elif [ -e "${link}" ]; then
		echo "[link-repos] warning: $link exists and is not a symlink; skipping $src" >&2
	else
		echo "Symlinking $name"
		ln -s "$src" "$link" || true
	fi
}

# A migration stub: an empty placeholder dir under plugins/ or themes/ for a
# plugin not yet migrated out of repos/. Skip it so it never shadows the
# authoritative repos/ copy (which an existing symlink still points at).
is_empty_dir() {
	[ -z "$(ls -A "$1" 2>/dev/null)" ]
}

# Skip an empty migration stub, and self-heal: if a prior run linked wp-content
# straight INTO the empty stub (e.g. an older link-repos before this guard
# existed), drop that broken link. A link to the authoritative /newspack-repos
# copy is deliberately left intact.
skip_empty_stub() {
	local src="$1" link="$2" name existing; name=$(basename "$src")
	if [ -L "$link" ]; then
		existing=$(readlink "$link")
		# Compare ignoring a trailing slash (the loop's glob yields "…/name/",
		# but a link may have been written either way).
		if [ "${existing%/}" = "${src%/}" ]; then
			echo "[link-repos] removing stale empty-stub link $name"
			rm -f "$link"
		fi
	fi
	echo "[link-repos] skipping empty $name (migration stub; using repos/ copy)"
}

WP_PATH=$1
if [ -z "$WP_PATH" ]; then
	WP_PATH="/var/www/html/wp-content"
fi

if [ ! -d "${WP_PATH}" ]; then
	echo "$WP_PATH directory does not exist"
	exit 1
fi

# Symlink all plugins from /newspack-plugins/ into wp-content/plugins/.
# `/newspack-plugins/` covers both monorepo plugins and --worktreed standalone
# repos. Plain (non-worktreed) standalone checkouts under /newspack-repos/ are
# auto-linked separately by the repos/ loop further down.
# A migrated plugin whose wp-content link still points at the pre-migration
# /newspack-repos mirror is repointed to the authoritative plugins/ copy;
# empty migration stubs are skipped so they don't shadow that mirror.
for dir in "$PLUGINS_PATH"/*/; do
	name=$(basename "$dir")
	if is_empty_dir "$dir"; then
		skip_empty_stub "$dir" "$WP_PATH/plugins/$name"
		continue
	fi
	link_or_repoint "$dir" "$WP_PATH/plugins/$name"
done

# Symlink themes. The classic theme contains child themes as subdirectories.
for dir in "$THEMES_PATH"/*/; do
	name=$(basename "$dir")
	if is_empty_dir "$dir"; then
		skip_empty_stub "$dir" "$WP_PATH/themes/$name"
		continue
	fi
	if [ "$name" = "newspack-theme" ]; then
		# Classic theme: symlink each child theme directory.
		for child in "$dir"newspack-*/; do
			[ -d "$child" ] || continue
			link_or_repoint "$child" "$WP_PATH/themes/$(basename "$child")"
		done
		# Also symlink the base theme directory itself.
		link_or_repoint "${dir}newspack-theme" "$WP_PATH/themes/$name"
	else
		link_or_repoint "$dir" "$WP_PATH/themes/$name"
	fi
done

# Symlink standalone/local plugins and themes from the gitignored repos/ dir.
# These are separate checkouts that live outside the monorepo (e.g. private or
# customer-specific plugins, newspack-manager, licensed WooCommerce extensions),
# split by type so each lands in the right place:
#   repos/plugins/<name> -> wp-content/plugins/<name>
#   repos/themes/<name>  -> wp-content/themes/<name>
# Any directory works -- no registration needed. This runs after the monorepo
# loops above, so a name that also ships in plugins/ or themes/ is already
# linked and is skipped here: the tracked (monorepo) copy takes precedence.
# The mount is optional, so skip silently when a subdir is empty/absent.
REPOS_PATH="/newspack-repos"
for kind in plugins themes; do
	src_base="$REPOS_PATH/$kind"
	[ -d "$src_base" ] || continue
	for dir in "$src_base"/*/; do
		[ -d "$dir" ] || continue
		name=$(basename "$dir")
		link="$WP_PATH/$kind/$name"
		# -L also catches dangling symlinks that -e (which follows links) misses.
		if [ -L "${link}" ] || [ -e "${link}" ]; then
			# Distinguish a tracked monorepo copy (which takes precedence) from a
			# plain idempotent re-run where this same repos/ link already exists,
			# and from a stale pre-migration mirror that must be repointed.
			existing="$(readlink "${link}" 2>/dev/null)"
			case "$existing" in
				"$PLUGINS_PATH"/*|"$THEMES_PATH"/*)
					echo "skipping repos/$kind/$name: tracked monorepo copy takes precedence" ;;
				*)
					if [ "${existing%/}" = "${dir%/}" ]; then
						echo "$name already symlinked"
					elif [ "${existing#"${REPOS_PATH}"/}" != "$existing" ]; then
						# Existing link points elsewhere under /newspack-repos: a stale
						# pre-migration mirror (e.g. the flat repos/<name> before it
						# moved to repos/{plugins,themes}/<name>). Repoint so migrated
						# standalone checkouts self-heal instead of dangling.
						echo "Repointing standalone $kind $name: $existing -> $dir"
						rm -f "$link"
						ln -s "$dir" "$link" || true
					else
						echo "[link-repos] warning: slug collision on '$name'" >&2
						echo "[link-repos]   existing: $link -> $existing" >&2
						echo "[link-repos]   skipping: $dir" >&2
					fi ;;
			esac
		else
			echo "Symlinking standalone $kind $name"
			ln -s "$dir" "$link" || true
		fi
	done
done
