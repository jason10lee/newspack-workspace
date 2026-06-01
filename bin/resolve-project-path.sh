#!/bin/bash
#
# Resolves a project name (e.g. "newspack-plugin") to its container-side
# path. In the monorepo layout, plugins live at /newspack-plugins/<name>
# and themes at /newspack-themes/<name>. Standalone/local checkouts dropped
# into the gitignored repos/ dir resolve to /newspack-repos/{plugins,themes}/<name>.
#
# Monorepo paths are checked first, so a name that exists both in the monorepo
# and in repos/ resolves to the tracked monorepo copy (it takes precedence).
#
# WP activation (link-repos.sh) links any repos/{plugins,themes}/<name> by
# directory existence — no registration needed. The n-tooling surface here
# (build / test / watch), by contrast, gates standalone resolution on
# declaration in newspack_standalone_repos, so undeclared checkouts in repos/
# aren't silently picked up as build/test targets.
#
# Usage:
#   source /var/scripts/resolve-project-path.sh
#   path=$(resolve_project_path "newspack-plugin")
#

# is_standalone_repo (and newspack_standalone_repos) come from repos.sh.
source "$(dirname "${BASH_SOURCE[0]}")/repos.sh"

PLUGINS_PATH="/newspack-plugins"
THEMES_PATH="/newspack-themes"
REPOS_PATH="/newspack-repos"

resolve_project_path() {
    local name="$1"
    if [ -d "$PLUGINS_PATH/$name" ]; then
        echo "$PLUGINS_PATH/$name"
    elif [ -d "$THEMES_PATH/$name" ]; then
        echo "$THEMES_PATH/$name"
    elif is_standalone_repo "$name" && [ -d "$REPOS_PATH/plugins/$name" ]; then
        echo "$REPOS_PATH/plugins/$name"
    elif is_standalone_repo "$name" && [ -d "$REPOS_PATH/themes/$name" ]; then
        echo "$REPOS_PATH/themes/$name"
    else
        echo ""
    fi
}

# For scripts that iterate all monorepo projects (e.g. `n ci-build all`).
# Standalone repos/ checkouts are intentionally excluded here: build-repos.sh
# builds declared standalone repos in a separate pass (see its `all` branch).
get_all_project_dirs() {
    local dirs=()
    for d in "$PLUGINS_PATH"/*/; do
        [ -d "$d" ] && dirs+=("$d")
    done
    for d in "$THEMES_PATH"/*/; do
        [ -d "$d" ] && dirs+=("$d")
    done
    printf '%s\n' "${dirs[@]}"
}
