#!/bin/bash

source /var/scripts/repos.sh
source /var/scripts/resolve-project-path.sh

MONOREPO_ROOT="/newspack-monorepo"

# pnpm is provided by Node 20's bundled corepack. Enable on first use; the
# pnpm version is pinned via the `packageManager` field in package.json.
if ! command -v pnpm >/dev/null 2>&1; then
    corepack enable pnpm >/dev/null
fi

# Run pnpm non-interactively. Without this, `pnpm install` aborts with
# ERR_PNPM_ABORTED_REMOVE_MODULES_DIR_NO_TTY when it wants to purge a
# node_modules dir created by a different setup — there's no TTY to confirm, so
# the whole build bails before anything compiles. CI=true makes pnpm assume a
# non-interactive environment and proceed.
export CI=true

find_project() {
    local path=$(resolve_project_path "$1")
    if [ -z "$path" ]; then path=$(resolve_project_path "newspack-$1"); fi
    if [ -z "$path" ]; then echo "Project $1 not found" >&2; exit 1; fi
    echo "$path"
}

# Resolve the pnpm `--filter` selector for a project dir. pnpm filters by the
# package's *name*, which can differ from the directory name (e.g.
# plugins/newspack-plugin → "newspack", plugins/newspack-blocks →
# "@automattic/newspack-blocks"). Read the real name from package.json; fall
# back to the basename if it can't be read.
package_filter_for_dir() {
    local pj="$1/package.json" name=""
    if [ -f "$pj" ]; then
        name=$(node -p "require('$pj').name" 2>/dev/null)
    fi
    if [ -n "$name" ]; then
        echo "$name"
    else
        basename "$1"
    fi
}

# Build a standalone repos/ checkout using its own toolchain (it isn't part of
# the pnpm workspace, so `pnpm --filter` won't find it). Respects lockfiles,
# treats a missing composer.json/package.json as a no-op success.
build_standalone_repo() {
    local dir="$1"
    echo "Building standalone repo $dir"

    # PHP deps if present. CI mode skips dev deps for parity with the JS
    # path's --frozen-lockfile behaviour. Failures propagate so they don't
    # silently turn into green builds.
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
    # CI=true keeps pnpm non-interactive so a one-time node_modules re-layout
    # (e.g. after a hoist-pattern change in .npmrc) auto-purges instead of
    # blocking on a confirmation prompt -- `n` runs pnpm via `docker exec`
    # without a TTY in non-interactive contexts, where that prompt aborts.
    CI=true pnpm install --frozen-lockfile
else
    pnpm install
fi

case $WHAT_TO_BUILD in
    all)
        # Composer install per monorepo project that ships its own composer.json.
        # Standalone repos/ checkouts are not built here — they're external and
        # often distributed/pre-built; build one on demand with `n build <name>`.
        while IFS= read -r dir; do
            [ -d "$dir" ] || continue
            [ -f "$dir/composer.json" ] || continue
            composer install --working-dir "$dir"
        done < <(get_all_project_dirs)
        # Propagate a failed build so it doesn't silently pass CI.
        rc=0
        pnpm run build || rc=1
        exit "$rc"
        ;;
    *)
        dir="$(find_project "$WHAT_TO_BUILD")"
        # Standalone repos build with their own toolchain. find_project resolves
        # via resolve_project_path, which only yields a repos/ path for declared
        # standalone repos, so a repos/ path here is already registry-gated.
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
