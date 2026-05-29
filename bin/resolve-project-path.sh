#!/bin/bash
#
# Resolves a project name (e.g. "newspack-plugin") to its container-side
# path. Three possible locations:
#   /newspack-plugins/<name> — monorepo plugins (incl. --worktreed standalone)
#   /newspack-themes/<name>  — monorepo themes
#   /newspack-repos/<name>   — standalone repos that aren't currently --worktreed
#
# Usage:
#   source /var/scripts/resolve-project-path.sh
#   path=$(resolve_project_path "newspack-plugin")
#

# is_standalone_repo (and newspack_standalone_repos) come from repos.sh.
# Sourcing here makes /newspack-repos/<name> resolution depend on declaration,
# not just on directory existence — so undeclared dirs in repos/ aren't
# silently picked up by build / test / watch.
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
    elif [ -d "$REPOS_PATH/$name" ] && is_standalone_repo "$name"; then
        echo "$REPOS_PATH/$name"
    else
        echo ""
    fi
}

# For scripts that iterate all projects:
get_all_project_dirs() {
    local dirs=()
    for d in "$PLUGINS_PATH"/*/; do
        [ -d "$d" ] && dirs+=("$d")
    done
    for d in "$THEMES_PATH"/*/; do
        [ -d "$d" ] && dirs+=("$d")
    done
    # Only declared standalone repos count; undeclared repos/<name>/
    # checkouts are excluded.
    for d in "$REPOS_PATH"/*/; do
        [ -d "$d" ] || continue
        is_standalone_repo "$(basename "$d")" && dirs+=("$d")
    done
    printf '%s\n' "${dirs[@]}"
}
