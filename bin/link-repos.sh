#!/bin/bash

source /var/scripts/repos.sh
source /var/scripts/resolve-project-path.sh

set -e

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
# repos. `/newspack-repos/` is mount-only (available but not auto-linked).
for dir in "$PLUGINS_PATH"/*/; do
	name=$(basename "$dir")
	link="$WP_PATH/plugins/$name"
	if [ -L "${link}" ]; then
		existing=$(readlink "$link")
		if [ "$existing" = "$dir" ]; then
			echo "$name already symlinked"
		else
			# Slug collision: another source already claims this name. Don't
			# overwrite or crash-loop; surface the conflict and move on.
			echo "[link-repos] warning: slug collision on '$name'" >&2
			echo "[link-repos]   existing: $link -> $existing" >&2
			echo "[link-repos]   skipping: $dir" >&2
		fi
	elif [ -e "${link}" ]; then
		echo "[link-repos] warning: $link exists and is not a symlink; skipping $dir" >&2
	else
		echo "Symlinking plugin $name"
		ln -s "$dir" "$link" || true
	fi
done

# Symlink themes. The classic theme contains child themes as subdirectories.
for dir in "$THEMES_PATH"/*/; do
	name=$(basename "$dir")
	if [ "$name" = "newspack-theme" ]; then
		# Classic theme: symlink each child theme directory.
		for child in "$dir"newspack-*/; do
			[ -d "$child" ] || continue
			child_name=$(basename "$child")
			link="$WP_PATH/themes/$child_name"
			if [ -L "${link}" ]; then
				echo "$child_name already symlinked"
			else
				echo "Symlinking theme $child_name"
				ln -s "$child" "$link" || true
			fi
		done
		# Also symlink the base theme directory itself.
		link="$WP_PATH/themes/$name"
		if [ -L "${link}" ]; then
			echo "$name already symlinked"
		else
			echo "Symlinking theme $name"
			ln -s "${dir}newspack-theme" "$link" || true
		fi
	else
		link="$WP_PATH/themes/$name"
		if [ -L "${link}" ]; then
			echo "$name already symlinked"
		else
			echo "Symlinking theme $name"
			ln -s "$dir" "$link" || true
		fi
	fi
done
