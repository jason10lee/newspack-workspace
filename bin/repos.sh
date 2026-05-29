#!/bin/bash

# Monorepo layout: plugins live in plugins/, themes in themes/, separately-
# checked-out repos live in repos/. Container mount points: /newspack-plugins/,
# /newspack-themes/, /newspack-repos/.

newspack_plugins=(
	"newspack-ads"
	"newspack-blocks"
	"newspack-listings"
	"newspack-newsletters"
	"newspack-plugin"
	"newspack-popups"
	"newspack-sponsors"
	"republication-tracker-tool"
	"super-cool-ad-inserter-plugin"
	"newspack-multibranded-site"
	"newspack-network"
	"newspack-story-budget"
)

newspack_themes=(
	"newspack-theme"
	"newspack-block-theme"
)

woocommerce_plugins=(
	"woocommerce"
	"woocommerce-gateway-stripe"
	"woocommerce-subscriptions"
	"woocommerce-memberships"
	"woocommerce-name-your-price"
)

# Standalone repos: separate git checkouts at repos/<name>/ (own history,
# own remote). Populated via bin/repos.local.sh; no canonical entries here.
newspack_standalone_repos=()

# Allow users to extend the arrays above via a local-only config file. Useful
# for adding non-canonical (private/custom) plugins that aren't part of the
# public workspace. The local file is gitignored — see bin/repos.local.sh.sample
# for the convention.
[[ -f "$(dirname "${BASH_SOURCE[0]}")/repos.local.sh" ]] && source "$(dirname "${BASH_SOURCE[0]}")/repos.local.sh"

# Warn if a name appears in more than one tier. First match in
# get_repo_host_path wins downstream; this surfaces the misconfiguration
# without blocking work. Bash 3.2-safe (macOS ships /bin/bash 3.2.57).
_check_array_collisions() {
	local dups
	dups=$(printf '%s\n' "${newspack_plugins[@]}" "${newspack_themes[@]}" "${newspack_standalone_repos[@]}" | sort | uniq -d)
	if [[ -n "$dups" ]]; then
		while IFS= read -r name; do
			echo "[repos.sh] warning: '$name' appears in multiple arrays (first match wins)" >&2
		done <<< "$dups"
	fi
}
_check_array_collisions
unset -f _check_array_collisions

# Maps a plugin/theme/standalone-repo name to its host-side directory relative
# to the workspace root. Used by the n script for cwd detection and path
# translation. Plugins-first precedence; cross-array collisions are warned at
# source time.
get_repo_host_path() {
	local name="$1"
	for p in "${newspack_plugins[@]}"; do
		if [[ "$p" == "$name" ]]; then
			echo "plugins/$name"
			return
		fi
	done
	for t in "${newspack_themes[@]}"; do
		if [[ "$t" == "$name" ]]; then
			echo "themes/$name"
			return
		fi
	done
	for r in "${newspack_standalone_repos[@]}"; do
		if [[ "$r" == "$name" ]]; then
			echo "repos/$name"
			return
		fi
	done
	echo ""
}
