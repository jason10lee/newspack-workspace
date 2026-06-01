#!/usr/bin/env bash
#
# Clone the non-monorepo "managed" plugins (newspack-manager,
# newspack-manager-admin) into repos/plugins/ for local development.
#
# These plugins live in private Automattic repos and can't be tracked in this
# monorepo, so a fresh checkout has no copy of them. This pulls them in one
# shot via the GitHub CLI (which carries your auth for the private repos),
# idempotently: an existing checkout is left untouched.
#
# Cloning only -- building and symlinking are handled by the caller
# (`n setup-repos`) through the standard build-repos.sh / link-repos.sh paths,
# the same ones `n build <name>` and container startup use.
#
# Usage: n setup-repos

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
source "$SCRIPT_DIR/repos.sh"

if ! command -v gh &>/dev/null; then
	echo "Error: the GitHub CLI (gh) is required to clone the private managed repos." >&2
	echo "Install it from https://cli.github.com/, then run 'gh auth login'." >&2
	exit 1
fi

DEST="$ROOT_DIR/repos/plugins"
mkdir -p "$DEST"

for name in "${managed_plugins[@]}"; do
	target="$DEST/$name"
	if [ -d "$target" ]; then
		echo "✓ $name already present in repos/plugins/, skipping clone."
		continue
	fi
	echo "Cloning Automattic/$name into repos/plugins/$name..."
	gh repo clone "Automattic/$name" "$target"
done
