#!/bin/bash

# Monorepo layout: plugins live in plugins/, themes in themes/.
# The container mount point is /newspack-plugins/ and /newspack-themes/.

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

# Plugins that live in their own private repos outside this monorepo, so a fresh
# checkout has no copy of them. `n setup-repos` clones these into repos/plugins/
# (auth via gh) where the standard repos/ convention picks them up.
managed_plugins=(
	"newspack-manager"
	"newspack-manager-admin"
)

woocommerce_plugins=(
	"woocommerce"
	"woocommerce-gateway-stripe"
	"woocommerce-subscriptions"
	"woocommerce-memberships"
	"woocommerce-name-your-price"
)

# Maps a plugin/theme name to its host-side directory relative to the
# workspace root. Used by the n script for cwd detection and path translation.
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
	echo ""
}
