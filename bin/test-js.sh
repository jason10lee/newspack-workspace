#!/bin/bash

# Sourced relative to this script rather than by absolute container path, so
# bin/tests/ can run it on the host. In the container bin/ is mounted at
# /var/scripts, so this resolves to the same files.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/repos.sh"
source "$SCRIPT_DIR/resolve-project-path.sh"
source "$SCRIPT_DIR/test-js-deps.sh"

MONOREPO_ROOT="${MONOREPO_ROOT:-/newspack-monorepo}"

# pnpm is provided by Node's bundled corepack. Enable on first use; the
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
    # Read scripts.test through node rather than grepping the file: a *dependency*
    # named "test" matches the same `"test":` shape and would send us on to
    # `run test`, which then fails with "Missing script". Failing to read the file
    # at all is kept distinct from finding no script — an unreadable package.json
    # reported as "nothing to test" would skip a repo's whole suite and still exit 0.
    local has_test
    if ! has_test=$(node -p "require('$dir/package.json').scripts?.test ?? ''" 2>/dev/null); then
        echo "Could not read $(basename "$dir")/package.json" >&2
        return 1
    fi
    if [ -z "$has_test" ]; then
        echo "No \"test\" script in $(basename "$dir")/package.json; nothing to test" >&2
        return 0
    fi
    # Detect package manager from lockfile; default npm for a lockfile-less repo
    # (matches `n build` so the two never pick a different PM for one checkout).
    local pm="npm"
    if [ -f "$dir/pnpm-lock.yaml" ]; then
        pm="pnpm"
    elif [ -f "$dir/yarn.lock" ]; then
        pm="yarn"
    fi
    ( cd "$dir" && "$pm" install ) || return 1
    ( cd "$dir" && "$pm" run test )
}

if [ $# -eq 0 ]; then
	echo "No arguments provided"
	echo "Possible arguments: theme, block-theme, or any plugin slug"
	exit 1
fi

# `exit 1` inside find_project ends only the command-substitution subshell, so
# the status has to be propagated here — otherwise an unknown name leaves
# PROJECT_DIR empty and the workspace branch below installs and tests every
# package instead of reporting the typo.
PROJECT_DIR=$(find_project "$1") || exit 1

if [[ "$PROJECT_DIR" == "$REPOS_PATH"/* ]]; then
    # Standalone repo: outside the pnpm workspace -- run its own test toolchain.
    test_standalone_repo "$PROJECT_DIR"
else
    # Derive the pnpm package name from package.json — it can differ from the
    # directory name (e.g. plugins/newspack-plugin is the package "newspack"),
    # and pnpm --filter matches by package name, not directory.
    PKG=$(node -p "require('$PROJECT_DIR/package.json').name" 2>/dev/null)
    [ -z "$PKG" ] && PKG=$(basename "$PROJECT_DIR")
    # Guarded for the same reason find_project's status is propagated above: an
    # unreachable root would otherwise leave the shell wherever it started and run
    # `pnpm --filter` against that directory instead of the workspace.
    cd "$MONOREPO_ROOT" || exit 1

    # Every run gets a log directory, in both output modes, so an install is never
    # invisible. One directory per run, created atomically: every env mounts the
    # same logs/, and process IDs repeat across containers. Named after the project
    # directory, since a scoped package name (@scope/name) carries a slash.
    LOG_DIR="$MONOREPO_ROOT/logs/test-js"
    mkdir -p "$LOG_DIR"
    RUN_DIR=$(mktemp -d "$LOG_DIR/$(basename "$PROJECT_DIR")-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXX") || exit 1
    # mktemp makes it 0700; the host user has to be able to read what a root
    # container wrote.
    chmod 755 "$RUN_DIR"
    LOG="$RUN_DIR/output.log"
    COMPACT=0
    [ "${NEWSPACK_TEST_OUTPUT:-}" = "compact" ] && COMPACT=1
    # A line for the log only; say() also prints it.
    note() { echo "test-js: $*" >> "$LOG"; }
    say() { echo "$*"; echo "$*" >> "$LOG"; }

    # The root's node_modules is shared by the main container and every isolated
    # env, and an install from an env resolves that env's branch manifests, so it
    # would rewrite the shared install to follow one branch. Only the main container
    # installs, and only when the check says it must.
    BRANCH_ROOT=""
    ISOLATED=0
    if in_isolated_env; then
        ISOLATED=1
        BRANCH_ROOT=$(branch_root_for "$PROJECT_DIR")
    fi
    WS_DIR="${BRANCH_ROOT:-$MONOREPO_ROOT}"
    PNPM_PIN=$(root_pnpm_cmd "$MONOREPO_ROOT") || PNPM_PIN="pnpm"
    CHECK_OUT=$(deps_check "$WS_DIR" "$WS_DIR/node_modules/.pnpm/lock.yaml" "$PNPM_PIN")
    CHECK=$?
    case "$CHECK" in
        0) note "check current" ;;
        1) note "check stale: $CHECK_OUT" ;;
        *) note "check error: $CHECK_OUT" ;;
    esac

    INSTALL_STATUS=0
    if [ "$CHECK" -ne 0 ] && [ "$ISOLATED" -eq 1 ]; then
        say "Error: $PKG needs dependencies the shared install does not have (cause: deps-stale)."
        say "  $CHECK_OUT"
        say "n test-js does not install from an isolated env, because that install would rewrite the"
        say "shared root for every env. Run the tests from the main container, or wait for a branch-private install."
        note "refused (cause: deps-stale)"
        exit 1
    elif [ "$CHECK" -ne 0 ]; then
        if [ "$CHECK" -eq 1 ]; then
            say "Root dependencies are stale; installing."
        else
            say "Could not check root dependencies ($CHECK_OUT); installing."
        fi
        # Full mode keeps the terminal: pnpm aborts a modules purge without a TTY.
        if [ "$COMPACT" -eq 1 ]; then
            pnpm install >> "$LOG" 2>&1
        else
            pnpm install
        fi
        INSTALL_STATUS=$?
        note "install ran (exit $INSTALL_STATUS)"
    fi

    # A branch-volume env tests the branch's own workspace, with the shared install
    # laid over it read-only, the way `n env up --build-worktrees` builds it.
    if [ -n "$BRANCH_ROOT" ]; then
        if [ "$COMPACT" -eq 1 ]; then
            prepare_branch_packages "$BRANCH_ROOT" "$MONOREPO_ROOT" "$PNPM_PIN" >> "$LOG" 2>&1
        else
            prepare_branch_packages "$BRANCH_ROOT" "$MONOREPO_ROOT" "$PNPM_PIN"
        fi || { say "Error: could not build the branch's workspace packages for $PKG."; exit 1; }
        run_test() {
            # shellcheck disable=SC2086 # PNPM_PIN is "corepack pnpm@<ver>".
            (cd "$BRANCH_ROOT" && NODE_PATH="$BRANCH_ROOT/node_modules/.pnpm/node_modules" \
                npm_config_manage_package_manager_versions=false $PNPM_PIN --filter "$PKG" run test)
        }
    else
        run_test() { pnpm --filter "$PKG" run test; }
    fi

    if [ "$COMPACT" -eq 0 ]; then
        run_test
        STATUS=$?
        note "test exit $STATUS"
        exit $STATUS
    fi

    # Compact mode for coding agents (set by `n`; see bin/test-php.sh): only the
    # summary prints. Full mode carries on after a failed install and tests
    # whatever node_modules already holds, so compact mode does too, and reports
    # the failure instead.
    echo "=== pnpm run test ===" >> "$LOG"
    run_test >> "$LOG" 2>&1
    STATUS=$?
    note "test exit $STATUS"
    bash "$SCRIPT_DIR/test-js-summary.sh" "$LOG" "$PROJECT_DIR" "$PKG" "$STATUS" "$MONOREPO_ROOT" "$INSTALL_STATUS"
    exit $STATUS
fi
