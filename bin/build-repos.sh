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

# Translate a container path under /newspack-{plugins,themes}/<name> to the
# pnpm filter selector (the workspace package's directory name).
package_filter_for_dir() {
    basename "$1"
}

# Build a standalone repo using its own toolchain (it isn't part of the
# pnpm workspace, so `pnpm --filter` won't find it). Respects lockfiles,
# treats missing package.json as a no-op success.
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
    pnpm install --frozen-lockfile
else
    pnpm install
fi

case $WHAT_TO_BUILD in
    all)
        # Composer install per workspace project that ships its own composer.json.
        # Skip standalone repos here — build_standalone_repo handles their PHP
        # and JS install/build below.
        while IFS= read -r dir; do
            [ -d "$dir" ] || continue
            [ -f "$dir/composer.json" ] || continue
            is_standalone_repo "$(basename "$dir")" && continue
            composer install --working-dir "$dir"
        done < <(get_all_project_dirs)
        # Build pnpm workspace packages and standalone repos. Track failures so a
        # broken build doesn't silently pass CI through the loop.
        rc=0
        pnpm run build || rc=1
        for r in "${newspack_standalone_repos[@]}"; do
            dir=$(resolve_project_path "$r")
            [ -n "$dir" ] && { build_standalone_repo "$dir" || rc=1; }
        done
        exit "$rc"
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
