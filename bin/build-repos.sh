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

# Translate a container path under /newspack-{plugins,themes}/<name> to the
# pnpm filter selector (the workspace package's directory name).
package_filter_for_dir() {
    basename "$1"
}

# Tier detection by name-membership (not path), since worktreed standalone
# repos land at /newspack-plugins/<name> and would otherwise look monorepo-y.
is_standalone_repo() {
    local name="$1"
    local r
    for r in "${newspack_standalone_repos[@]}"; do
        [ "$r" = "$name" ] && return 0
    done
    return 1
}

# Build a standalone repo using its own toolchain (it isn't part of the
# pnpm workspace, so `pnpm --filter` won't find it). Respects lockfiles,
# treats missing package.json as a no-op success.
build_standalone_repo() {
    local dir="$1"
    echo "Building standalone repo $dir"

    # PHP deps if present.
    if [ -f "$dir/composer.json" ]; then
        composer install --working-dir "$dir"
    fi

    # JS deps + build: no-op if there's no package.json.
    if [ ! -f "$dir/package.json" ]; then
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

    # Install (respecting MODE=ci for frozen-lockfile parity with the rest of build-repos).
    if [ "$MODE" = "ci" ]; then
        case "$pm" in
            pnpm) (cd "$dir" && pnpm install --frozen-lockfile) || return 1 ;;
            yarn) (cd "$dir" && yarn install --frozen-lockfile) || return 1 ;;
            npm)  (cd "$dir" && npm ci) || return 1 ;;
        esac
    else
        (cd "$dir" && "$pm" install) || return 1
    fi

    # Run build only if a build script is declared.
    if grep -q '"build"[[:space:]]*:' "$dir/package.json" 2>/dev/null; then
        (cd "$dir" && "$pm" run build) || return 1
    fi
}

if [ $# -eq 0 ]; then
    echo "No arguments provided"
    echo "Possible arguments: all, theme, block-theme, or any plugin slug"
    exit 1
fi

WHAT_TO_BUILD="$1"
MODE="$2"

cd "$MONOREPO_ROOT"

# Workspace install: pnpm resolves `workspace:*` deps (e.g. newspack-scripts)
# and links shared bins into each package's node_modules/.bin.
if [ "$MODE" = "ci" ]; then
    pnpm install --frozen-lockfile
else
    pnpm install
fi

case $WHAT_TO_BUILD in
    all)
        # Composer install per project that ships its own composer.json
        # (dev deps are hoisted to the root composer.json).
        while IFS= read -r dir; do
            [ -d "$dir" ] && [ -f "$dir/composer.json" ] && composer install --working-dir "$dir"
        done < <(get_all_project_dirs)
        # Build pnpm workspace packages.
        pnpm run build
        # Build standalone repos individually (they aren't workspace members).
        for r in "${newspack_standalone_repos[@]}"; do
            dir=$(resolve_project_path "$r")
            [ -n "$dir" ] && build_standalone_repo "$dir"
        done
        ;;
    *)
        dir="$(find_project "$WHAT_TO_BUILD")"
        # Resolve the name (may have been auto-prefixed with newspack-) to
        # decide tier; check both raw and prefixed forms.
        name="$(basename "$dir")"
        if is_standalone_repo "$name"; then
            build_standalone_repo "$dir"
        else
            pkg=$(package_filter_for_dir "$dir")
            echo "Building $dir (package: $pkg)"
            composer install --working-dir "$dir"
            pnpm --filter "$pkg" run build
        fi
        ;;
esac
