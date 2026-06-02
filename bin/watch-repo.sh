#!/bin/bash

source /var/scripts/resolve-project-path.sh

find_project() {
    local path=$(resolve_project_path "$1")
    if [ -z "$path" ]; then path=$(resolve_project_path "newspack-$1"); fi
    if [ -z "$path" ]; then echo "Project $1 not found" >&2; exit 1; fi
    echo "$path"
}

if [ $# -eq 0 ]; then
	echo "No arguments provided"
	echo "Possible arguments: theme, block-theme, or any plugin slug"
	exit 1
fi

PROJECT_DIR=$(find_project "$1")

# Run from the monorepo mount. pnpm links workspace deps with relative symlinks
# (e.g. node_modules/newspack-scripts -> ../../../packages/scripts) that only
# resolve under /newspack-monorepo; the standalone /newspack-{plugins,themes}
# mounts make those symlinks escape their root, breaking the watch toolchain.
PROJECT_DIR="${PROJECT_DIR/#\/newspack-plugins//newspack-monorepo/plugins}"
PROJECT_DIR="${PROJECT_DIR/#\/newspack-themes//newspack-monorepo/themes}"
PROJECT_DIR="${PROJECT_DIR/#\/newspack-repos//newspack-monorepo/repos}"

cd "$PROJECT_DIR"
npm run watch
