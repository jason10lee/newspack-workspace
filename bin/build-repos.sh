#!/bin/bash

source /var/scripts/repos.sh
source /var/scripts/resolve-project-path.sh

MONOREPO_ROOT="/newspack-monorepo"

# pnpm is provided by Node 20's bundled corepack. Enable on first use; the
# pnpm version is pinned via the `packageManager` field in package.json.
if ! command -v pnpm >/dev/null 2>&1; then
    corepack enable pnpm >/dev/null
fi

# Run pnpm non-interactively for every pnpm call below. Without this, an install
# that wants to purge/re-layout node_modules (e.g. after a hoist-pattern change
# in .npmrc) blocks on a confirmation prompt -- `n` runs pnpm via `docker exec`
# without a TTY, where that prompt aborts the whole build. CI=true makes pnpm
# assume a non-interactive environment and proceed.
export CI=true

find_project() {
    local path=$(resolve_project_path "$1")
    if [ -z "$path" ]; then path=$(resolve_project_path "newspack-$1"); fi
    if [ -z "$path" ]; then echo "Project $1 not found" >&2; exit 1; fi
    echo "$path"
}

# Translate a container path under /newspack-{plugins,themes}/<name> to the
# pnpm filter selector for a workspace package: the package's actual `name`
# read from its package.json. We can't derive this from the folder, because a
# package's name often differs from its directory (plugins/newspack-plugin is
# named "newspack", plugins/newspack-blocks is "@automattic/newspack-blocks").
# A bare `--filter <dirname>` matches no projects there, so pnpm silently builds
# nothing and exits 0. Falls back to the basename if no name is found.
package_filter_for_dir() {
    local name
    name=$(sed -n 's/.*"name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$1/package.json" 2>/dev/null | head -n1)
    if [ -n "$name" ]; then echo "$name"; else basename "$1"; fi
}

# Build a standalone repos/ checkout with its own toolchain. These live outside
# the pnpm workspace, so `pnpm --filter` can't see them. In CI mode, installs use
# frozen-lockfile/--no-dev for reproducibility (parity with the workspace
# install); failures propagate so a broken standalone build can't silently pass.
build_standalone_repo() {
    local dir="$1"
    echo "Building standalone repo $dir"

    # PHP deps if present. CI mode skips dev deps for parity with the JS
    # path's --frozen-lockfile behaviour.
    if [ -f "$dir/composer.json" ]; then
        if [ "$MODE" = "ci" ]; then
            composer install --working-dir "$dir" --no-dev || return 1
        else
            composer install --working-dir "$dir" || return 1
        fi
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
        # Composer install per monorepo project (each plugin still has its own
        # composer.json for production deps; dev deps are hoisted to the root).
        # Standalone repos/ checkouts are not built here -- they're external and
        # often distributed/pre-built; build one on demand with `n build <name>`.
        while IFS= read -r dir; do
            [ -d "$dir" ] && composer install --working-dir "$dir"
        done < <(get_all_project_dirs)
        pnpm run build
        ;;
    *)
        dir="$(find_project "$WHAT_TO_BUILD")"
        if [[ "$dir" == "$REPOS_PATH"/* ]]; then
            build_standalone_repo "$dir"
        else
            pkg=$(package_filter_for_dir "$dir")
            echo "Building $dir (package: $pkg)"
            composer install --working-dir "$dir"
            pnpm --filter "$pkg" run build
        fi
        ;;
esac
