#!/bin/bash

source /var/scripts/repos.sh
source /var/scripts/resolve-project-path.sh

MONOREPO_ROOT="/newspack-monorepo"

# pnpm is provided by Node 20's bundled corepack. Enable on first use; the
# pnpm version is pinned via the `packageManager` field in package.json.
if ! command -v pnpm >/dev/null 2>&1; then
    corepack enable pnpm >/dev/null
fi

find_project() {
    local path=$(resolve_project_path "$1")
    if [ -z "$path" ]; then path=$(resolve_project_path "newspack-$1"); fi
    if [ -z "$path" ]; then echo "Project $1 not found" >&2; exit 1; fi
    echo "$path"
}

# Translate a container path under /newspack-{plugins,themes}/<name> to the pnpm
# filter selector for a workspace package: the package's actual `name` from its
# package.json. The name often differs from the directory (plugins/newspack-plugin
# is named "newspack"), and a bare `--filter <dirname>` matches no workspace
# project, so pnpm silently runs nothing. Falls back to the basename.
package_filter_for_dir() {
    local name
    name=$(sed -n 's/.*"name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$1/package.json" 2>/dev/null | head -n1)
    if [ -n "$name" ]; then echo "$name"; else basename "$1"; fi
}

# Run a standalone repos/ checkout's own JS test script. These live outside the
# pnpm workspace, so `pnpm --filter` can't see them; install + run their declared
# `test` script in-place with their own package manager. A missing package.json
# or test script is a no-op (matching pnpm --filter's silent skip for a package
# with no test script).
test_standalone_repo() {
    local dir="$1"
    if [ ! -f "$dir/package.json" ]; then
        echo "No package.json in $dir; nothing to test" >&2
        return 0
    fi
    if ! grep -q '"test"[[:space:]]*:' "$dir/package.json" 2>/dev/null; then
        echo "No \"test\" script in $(basename "$dir")/package.json; nothing to test" >&2
        return 0
    fi
    # Detect package manager from lockfile; default pnpm (monorepo convention).
    local pm="pnpm"
    if [ -f "$dir/pnpm-lock.yaml" ]; then
        pm="pnpm"
    elif [ -f "$dir/yarn.lock" ]; then
        pm="yarn"
    elif [ -f "$dir/package-lock.json" ]; then
        pm="npm"
    fi
    ( cd "$dir" && "$pm" install ) || return 1
    ( cd "$dir" && "$pm" run test )
}

if [ $# -eq 0 ]; then
	echo "No arguments provided"
	echo "Possible arguments: theme, block-theme, or any plugin slug"
	exit 1
fi

PROJECT_DIR=$(find_project "$1")

if [[ "$PROJECT_DIR" == "$REPOS_PATH"/* ]]; then
    # Standalone repo: outside the pnpm workspace -- run its own test toolchain.
    test_standalone_repo "$PROJECT_DIR"
else
    # Monorepo package: filter by its real package name within the workspace.
    PKG=$(package_filter_for_dir "$PROJECT_DIR")
    cd "$MONOREPO_ROOT"
    pnpm install
    pnpm --filter "$PKG" run test
fi
