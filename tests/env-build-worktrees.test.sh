#!/bin/bash
# Unit tests for env.sh's build_env_worktrees: the copy step, one in-container
# build per monorepo worktree unit with no install, and the failure hints.
# Host-runnable; sources env.sh and stubs docker on PATH.
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }
has(){ case "$2" in *"$3"*) echo "  PASS  $1"; pass=$((pass+1)) ;; *) echo "  FAIL  $1 (no [$3] in [$2])"; fail=$((fail+1)) ;; esac; }

# The stub logs every call. A build call prints $DOCKER_OUT and exits
# $DOCKER_EXIT; anything else succeeds silently.
STUB="$FIX/stub"; mkdir -p "$STUB"
cat > "$STUB/docker" <<'EOF'
#!/bin/bash
echo "$*" >> "$DOCKER_LOG"
case "$*" in *"run build"*) printf '%s\n' "${DOCKER_OUT:-}"; exit "${DOCKER_EXIT:-0}" ;; esac
exit 0
EOF
chmod +x "$STUB/docker"
export PATH="$STUB:$PATH"

# A fixture workspace. _common.sh keeps an inherited NABSPATH only when it has
# an `n`, so give it one before env.sh is sourced.
W="$FIX/ws"; export NABSPATH="$W"
mkdir -p "$W"; : > "$W/n"
source "$BIN/env.sh"

# Fresh root units, worktree units and compose file for one case.
reset_fixture() {
    rm -rf "$W/plugins" "$W/themes" "$W/repos" "$W/worktrees" "$W/worktrees-repos"
    for u in plugins/newspack-popups themes/newspack-theme; do
        mkdir -p "$W/$u/node_modules" "$W/$u/dist" "$W/worktrees/feat/$u"
        : > "$W/$u/node_modules/m"; : > "$W/$u/dist/d"
    done
    printf '{\n  "name": "@automattic/np-popups-fixture"\n}\n' > "$W/worktrees/feat/plugins/newspack-popups/package.json"
    printf '{\n  "name": "newspack-theme"\n}\n' > "$W/worktrees/feat/themes/newspack-theme/package.json"
    mkdir -p "$W/repos/plugins/newspack-manager/node_modules" "$W/worktrees-repos/newspack-manager/feat"
    : > "$W/repos/plugins/newspack-manager/node_modules/m"
    COMPOSE="$FIX/c.yml"; cat > "$COMPOSE" <<'EOF'
    volumes:
      - ./worktrees/feat/plugins/newspack-popups:/newspack-plugins/newspack-popups
      - ./worktrees/feat/plugins/newspack-popups:/newspack-monorepo/plugins/newspack-popups
      - ./worktrees/feat/themes/newspack-theme:/newspack-themes/newspack-theme
      - ./worktrees/feat/themes/newspack-theme:/newspack-monorepo/themes/newspack-theme
      - ./worktrees-repos/newspack-manager/feat:/newspack-repos/plugins/newspack-manager
EOF
    export DOCKER_LOG="$FIX/docker.log"; : > "$DOCKER_LOG"
    export DOCKER_OUT="" DOCKER_EXIT=0
}

run() { OUT=$(build_env_worktrees newspack_env_t "$COMPOSE" 2>&1); RC=$?; }
builds() { grep -c 'run build' "$DOCKER_LOG"; }

reset_fixture
mkdir -p "$W/worktrees/feat/themes/newspack-theme/node_modules"; : > "$W/worktrees/feat/themes/newspack-theme/node_modules/keep"
DOCKER_OUT=compiled-marker run
ok "returns 0 when every build passes" "$RC" "0"
ok "copies built dirs into a monorepo unit" "$([ -f "$W/worktrees/feat/plugins/newspack-popups/dist/d" ] && echo y)" "y"
ok "copies built dirs into a repos worktree" "$([ -f "$W/worktrees-repos/newspack-manager/feat/node_modules/m" ] && echo y)" "y"
ok "skips a dir the worktree already has (kept)" "$([ -f "$W/worktrees/feat/themes/newspack-theme/node_modules/keep" ] && echo y)" "y"
ok "skips a dir the worktree already has (no nesting)" "$([ -e "$W/worktrees/feat/themes/newspack-theme/node_modules/node_modules" ] && echo y || echo n)" "n"
ok "builds once per monorepo unit" "$(builds)" "2"
has "builds popups by its package name" "$(grep 'run build' "$DOCKER_LOG" | head -1)" "--filter '@automattic/np-popups-fixture'"
has "builds the theme by its package name" "$(grep 'run build' "$DOCKER_LOG" | tail -1)" "--filter 'newspack-theme'"
ok "every build sets NODE_PATH to the root's hoist" "$(grep 'run build' "$DOCKER_LOG" | grep -c -- '-e NODE_PATH=/newspack-monorepo/node_modules/.pnpm/node_modules')" "2"
ok "every build runs from /newspack-monorepo" "$(grep 'run build' "$DOCKER_LOG" | grep -c 'cd /newspack-monorepo &&')" "2"
ok "never installs" "$(grep -c 'pnpm install' "$DOCKER_LOG")" "0"
ok "enables pnpm before the first build" "$(grep -n -m1 'corepack enable pnpm' "$DOCKER_LOG" | cut -d: -f1)" "1"
has "build output is shown" "$OUT" "compiled-marker"

reset_fixture
cat > "$COMPOSE" <<'EOF'
      - ./worktrees-repos/newspack-manager/feat:/newspack-repos/plugins/newspack-manager
EOF
run
ok "repos-only env returns 0" "$RC" "0"
ok "repos-only env runs no docker" "$(wc -l < "$DOCKER_LOG" | tr -d ' ')" "0"

reset_fixture
DOCKER_EXIT=1 DOCKER_OUT="Module not found: Error: Can't resolve 'x' in '/y'" run
ok "missing module fails" "$RC" "1"
has "missing module names the unit and the install hint" "$OUT" "Error: build failed for newspack-popups: the branch imports a module the root has not installed"
ok "stops at the first failed unit" "$(builds)" "1"

reset_fixture
DOCKER_OUT="export 'getContrastColor' (imported as 'getContrastColor') was not found in 'newspack-components'" run
ok "missing export fails a green build" "$RC" "1"
has "missing export names the root-behind cause" "$OUT" "the root checkout is behind the branch"

reset_fixture
DOCKER_EXIT=2 DOCKER_OUT="something else broke" run
ok "other failure fails" "$RC" "1"
has "other failure points at the output" "$OUT" "see the build output above"

# `n env up` option parsing, run as a script. An accepted option gets as far as
# the missing compose file; a rejected one stops at "Unknown option".
up_out() { NABSPATH="$W" bash "$BIN/env.sh" up "$@" 2>&1; }
has "up accepts --build-worktrees" "$(up_out nope --build-worktrees)" "environment 'nope' not found"
has "up keeps --build as an alias" "$(up_out nope --build)" "environment 'nope' not found"
has "up still rejects unknown options" "$(up_out nope --bogus)" "Unknown option: --bogus"
has "up help names the new flag" "$(up_out --help)" "--build-worktrees"
has "env help names the new flag" "$(NABSPATH="$W" bash "$BIN/env.sh" help 2>&1)" "--build-worktrees"
# The style.css warning needs a live container, so check its condition here;
# the real-env smoke covers it at run time.
ok "no style.css warning before a build that makes it" "$(grep -B3 'newspack-theme is not built (style.css missing)' "$BIN/env.sh" | grep -c 'auto_build" != true')" "1"

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
