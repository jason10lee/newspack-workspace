#!/bin/bash
#
# Helpers test-js.sh uses to decide whether the shared install can serve a test run.
#
# The root's node_modules is shared by the main container and every isolated env,
# so an install from any of them rewrites it for all of them, and its `prepare`
# rebuilds packages/* on every run. These helpers let test-js.sh install only when
# the install is stale, and only from the main container.
#
# Sourced, never run: defines functions and nothing else. Paths are overridable so
# bin/tests/ can point them at fixtures.

WORKTREES_PATH="${WORKTREES_PATH:-/newspack-worktrees}"

# deps_check <workspace_dir> <installed_lock> <pnpm_cmd>
#
# Exit 0 when the workspace's manifests match the specifiers recorded in the
# installed lockfile, 1 when they do not, 2 when the check could not run. Prints
# pnpm's first error line, or the reason for a 2, on stdout.
#
# The installed copy (node_modules/.pnpm/lock.yaml) is the reference rather than
# pnpm-lock.yaml, so a lockfile updated but never installed reads as stale too.
# pnpm's own verify-deps-before-run is not used: it skips manifests older than its
# last check, so it misses a branch whose manifest predates the install.
#
# Runs in a scratch copy, never in place: a passing frozen check replaces
# pnpm-lock.yaml by rename, and that file is the shared root's or a worktree's.
# Comparison is by specifier text, so a reworded range the installed version still
# satisfies reads as stale. That strictness was chosen over a semver check of our own.
deps_check() {
    local ws="$1" lock="$2" pnpm_cmd="$3"
    local scratch rc
    if [ ! -f "$lock" ]; then
        echo "no installed lockfile at $lock"
        return 2
    fi
    scratch=$(mktemp -d "${TMPDIR:-/tmp}/test-js-deps.XXXXXX") || { echo "could not create a scratch directory"; return 2; }
    _deps_copy "$ws" "$lock" "$scratch"
    rc=$?
    if [ "$rc" -eq 0 ]; then
        # Unquoted on purpose: pnpm_cmd is "corepack pnpm@<ver>" in the container.
        # shellcheck disable=SC2086
        (cd "$scratch" && npm_config_manage_package_manager_versions=false \
            $pnpm_cmd install --frozen-lockfile --lockfile-only --ignore-scripts --offline \
            > "$scratch/.deps-check.log" 2>&1)
        rc=$?
        if [ "$rc" -eq 0 ]; then
            :
        elif grep -q 'ERR_PNPM_OUTDATED_LOCKFILE' "$scratch/.deps-check.log"; then
            grep -m1 'ERR_PNPM_OUTDATED_LOCKFILE' "$scratch/.deps-check.log" | sed 's/^ *//'
            rc=1
        else
            echo "pnpm could not check: $(grep -m1 -E 'ERR|ENOENT|Error' "$scratch/.deps-check.log" | sed 's/^ *//')"
            rc=2
        fi
    fi
    rm -rf "$scratch"
    return "$rc"
}

# Copies what pnpm reads to decide whether the lockfile is current: the root
# manifest, the workspace file, .npmrc, patches/ (pnpm hashes patch files, and a
# missing one is an error rather than a stale result), and every workspace
# package.json. Prints the reason and returns 2 on any failure.
_deps_copy() {
    local ws="$1" lock="$2" scratch="$3" line pattern dir m rel
    cp "$ws/package.json" "$ws/pnpm-workspace.yaml" "$scratch/" 2>/dev/null \
        || { echo "could not copy the root manifests from $ws"; return 2; }
    if [ -e "$ws/.npmrc" ]; then cp "$ws/.npmrc" "$scratch/" || { echo "could not copy .npmrc"; return 2; }; fi
    if [ -e "$ws/patches" ]; then cp -R "$ws/patches" "$scratch/" || { echo "could not copy patches/"; return 2; }; fi
    cp "$lock" "$scratch/pnpm-lock.yaml" || { echo "could not copy $lock"; return 2; }
    while IFS= read -r line; do
        case "$line" in
            *-*) ;;
            *) continue ;;
        esac
        pattern=$(printf '%s' "$line" | sed -E "s/^[[:space:]]*-[[:space:]]*['\"]?([^'\"]*)['\"]?[[:space:]]*$/\1/")
        case "$pattern" in
            */\*) dir=${pattern%/\*} ;;
            *) echo "unsupported workspace pattern: $pattern"; return 2 ;;
        esac
        case "$dir" in
            *\**|*/*|'') echo "unsupported workspace pattern: $pattern"; return 2 ;;
        esac
        for m in "$ws/$dir"/*/package.json; do
            [ -e "$m" ] || continue
            rel=${m#"$ws"/}
            mkdir -p "$scratch/$(dirname "$rel")" && cp "$m" "$scratch/$rel" 2>/dev/null \
                || { echo "could not copy $rel"; return 2; }
        done
    done < <(sed -n '/^packages:/,/^[^[:space:]-]/p' "$ws/pnpm-workspace.yaml" | grep -E '^[[:space:]]*-')
    return 0
}

# in_isolated_env: true inside an isolated env's container. Every env compose file
# sets WP_CACHE_KEY_SALT=env_<name>_; the main container does not set it.
in_isolated_env() {
    case "${WP_CACHE_KEY_SALT:-}" in
        env_*) return 0 ;;
        *) return 1 ;;
    esac
}

# branch_root_for <project_dir>
#
# Prints the branch volume (/newspack-worktrees/<b>) that holds the unit mounted at
# <project_dir>, when the env carries one with the shared install laid over it.
# Matched by -ef rather than by name, because two branch volumes can hold a unit of
# the same name. Prints nothing for an env created before branch volumes.
branch_root_for() {
    local project="$1" name root kind
    name=$(basename "$project")
    for root in "$WORKTREES_PATH"/*; do
        [ -f "$root/node_modules/.pnpm/lock.yaml" ] || continue
        for kind in plugins themes; do
            if [ -d "$root/$kind/$name" ] && [ "$root/$kind/$name" -ef "$project" ]; then
                echo "$root"
                return 0
            fi
        done
    done
    return 0
}

# root_pnpm_cmd <monorepo_root>: prints "corepack pnpm@<version>" from the root's
# packageManager field, the version the shared install was made with.
root_pnpm_cmd() {
    local spec
    spec=$(node -p "require('$1/package.json').packageManager || ''" 2>/dev/null)
    case "$spec" in
        pnpm@?*) echo "corepack $spec" ;;
        *) return 1 ;;
    esac
}
