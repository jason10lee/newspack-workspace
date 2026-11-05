#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ./helpers.sh
E=../bin/env.sh; L=../bin/ledger.sh
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
export AUTOFIX_WORKSPACE_ROOT; AUTOFIX_WORKSPACE_ROOT="$(mktemp -d)"
STUB="$(mktemp -d)"
# Resolve the real git binary BEFORE the stub dir goes on PATH, so the stub's
# pass-through fallback (for calls it doesn't specifically simulate, e.g. the
# destroy-path anchor-tag/ls-remote calls against a real repo below) invokes
# the actual tool rather than recursing into itself.
REAL_GIT="$(command -v git)"; export REAL_GIT
export PATH="$STUB:$PATH"
cat > "$STUB/n" <<'EOF'
#!/bin/bash
echo "n $*" >> "${N_LOG:?}"
exit "${N_EXIT:-0}"
EOF
chmod +x "$STUB/n"
export N_LOG="$STUB/n.log"; : > "$N_LOG"

# Base-ref discipline stub: `env.sh create` now fetches origin/main and
# pre-creates the run branch from it (see the fork-trunk leak guard, PR #723
# incident) BEFORE invoking `n env create`. Simulate that narrowly — only the
# exact calls env.sh's new create-path code makes — and pass everything else
# (worktree setup/teardown below, the real destroy-path git calls) through to
# the real git so those tests are unaffected.
cat > "$STUB/git" <<'EOF'
#!/bin/bash
echo "git $*" >> "${GIT_LOG:?}"
case "$*" in
  *"rev-parse -q --verify refs/heads/"*)
    exit "${STUB_BRANCH_EXISTS_EXIT:-1}" ;;  # default: branch doesn't exist yet
  *"rev-parse -q --verify origin/main")
    exit "${STUB_REVPARSE_EXIT:-0}" ;;
  *"fetch origin main")
    exit "${STUB_FETCH_EXIT:-0}" ;;
  *" branch "*" origin/main")
    exit 0 ;;
esac
exec "${REAL_GIT:?}" "$@"
EOF
chmod +x "$STUB/git"
export GIT_LOG="$STUB/git.log"; : > "$GIT_LOG"

bash "$L" init run-a NPPM-2993 operator-named >/dev/null
bash "$L" set run-a '.decisions += [{key:"branch_stem", value:"nppm-2993-bug-jetpack"}]'

bash "$E" create run-a newspack-multibranded-site -- --block-theme
assert_contains "$(cat "$N_LOG")" "env create autofix-nppm-2993-" "n env create called with derived name"
assert_contains "$(cat "$N_LOG")" "--worktree newspack-multibranded-site:nppm-2993-bug-jetpack-" "worktree branch carries 4hex suffix"
assert_contains "$(cat "$N_LOG")" "setup --env autofix-nppm-2993-" "n setup called"
assert_contains "$(bash "$L" get run-a '.env.name')" autofix-nppm-2993 "ledger records env"
assert_eq 1 "$(bash "$L" get run-a '.attempts.provisioning')" "attempt counted"
assert_eq "$AUTOFIX_WORKSPACE_ROOT/worktrees/$(bash "$L" get run-a .branch | tr '/' '-')" \
  "$(bash "$L" get run-a .worktree)" "create records .worktree"
# base-ref discipline (fork-trunk leak guard, PR #723 incident): the run
# branch is fetched-then-cut from upstream origin/main, BEFORE `n env
# create` runs — never from this machine's local trunk `main`.
assert_contains "$(cat "$GIT_LOG")" "fetch origin main" "origin/main fetched before worktree creation"
assert_contains "$(cat "$GIT_LOG")" "branch nppm-2993-bug-jetpack-a origin/main" \
  "run branch pre-created from origin/main, not local HEAD"
fetch_line="$(grep -n "fetch origin main" "$GIT_LOG" | head -1 | cut -d: -f1)"
branch_line="$(grep -n "branch nppm-2993-bug-jetpack-a origin/main" "$GIT_LOG" | head -1 | cut -d: -f1)"
assert_eq true "$([ "$fetch_line" -lt "$branch_line" ] && echo true || echo false)" \
  "fetch precedes branch pre-creation in the git log"

# A branch_stem rewritten after Stage 1 (how a secure run used to neutralize
# its branch name) leaves two decisions. The latest wins.
bash "$L" init run-dup NPPM-2996 operator-named >/dev/null
bash "$L" set run-dup '.decisions += [{key:"branch_stem", value:"nppm-2996-old-title-slug"}]'
bash "$L" set run-dup '.decisions += [{key:"branch_stem", value:"nppm-2996"}]'
: > "$N_LOG"
bash "$E" create run-dup newspack-multibranded-site >/dev/null 2>&1
assert_contains "$(cat "$N_LOG")" "--worktree newspack-multibranded-site:nppm-2996-dup " "duplicate branch_stem: the latest decision names the branch"
assert_eq "" "$(grep old-title-slug "$N_LOG" || true)" "duplicate branch_stem: the superseded stem is not used"

# failure path: N_EXIT=1 twice more → third create attempt dies at cap
export N_EXIT=1
bash "$E" create run-a newspack-multibranded-site >/dev/null 2>&1 || true
bash "$E" create run-a newspack-multibranded-site >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "provisioning cap enforced"
assert_eq 3 "$(bash "$L" get run-a '.attempts.provisioning')" "attempts capped at 3"

# regression: at cap, create dies BEFORE running any n command (even if n would succeed)
unset N_EXIT
bash "$L" init run-b NPPM-2993 operator-named >/dev/null
bash "$L" set run-b '.decisions += [{key:"branch_stem", value:"nppm-2993-bug-jetpack"}]'
bash "$L" set run-b '.attempts.provisioning = 3'
lines_before="$(wc -l < "$N_LOG")"
bash "$E" create run-b newspack-multibranded-site >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "at-cap create dies even when n would succeed"
assert_eq "$lines_before" "$(wc -l < "$N_LOG")" "at-cap create runs no n command"

# regression: destroy with recorded branch but missing worktree dir dies, no n env destroy
log_before="$(cat "$N_LOG")"
bash "$E" destroy run-a >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "destroy dies when worktree dir missing for recorded branch"
assert_eq "$log_before" "$(cat "$N_LOG")" "missing-worktree destroy runs no n command"

# CRITICAL 1 regression: a slashed branch's worktree lives at the SANITIZED
# path (n's safe_branch=$(tr '/' '-')) — destroy must find it there and run
# the anchor-tag + push-check safeguard (RAW branch ref for ls-remote), not
# take the missing-worktree death path.
ORIGIN="$(mktemp -d)"; git init --bare -q "$ORIGIN"
SLASH_WT="$AUTOFIX_WORKSPACE_ROOT/worktrees/jason-nppm-1-fix"
mkdir -p "$SLASH_WT"
( cd "$SLASH_WT" && git init -q \
    && git -c user.email=t@example.com -c user.name=t commit --allow-empty -qm init \
    && git remote add origin "$ORIGIN" \
    && git push -q origin HEAD:refs/heads/jason/nppm-1-fix )

bash "$L" init run-slash NPPM-9 operator-named >/dev/null
bash "$L" set run-slash '.env = {name:"autofix-env-slash"}'
bash "$L" set run-slash '.branch = "jason/nppm-1-fix"'
log_before="$(cat "$N_LOG")"
bash "$E" destroy run-slash >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 0 "$rc" "slashed branch: destroy succeeds (worktree found at sanitized path)"
assert_contains "$(git -C "$SLASH_WT" tag -l)" "autofix-anchor-run-slash" \
  "slashed branch: anchor tag created in the sanitized worktree dir"
assert_eq "n env destroy autofix-env-slash" "$(grep 'env destroy autofix-env-slash' "$N_LOG")" \
  "slashed branch: n env destroy invoked (push-check passed via RAW branch ls-remote)"
assert_eq "null" "$(bash "$L" get run-slash '.env')" \
  "destroy clears .env so sweeps don't retry a completed destroy"

# Squash merge deletes the remote branch. GitHub keeps refs/pull/<n>/head, so
# a tip contained there is preserved and destroy may proceed; before this every
# merged run read as "never pushed" and the sweep could not clean it up.
ORIGIN2="$(mktemp -d)"; git init --bare -q "$ORIGIN2"
MERGED_WT="$AUTOFIX_WORKSPACE_ROOT/worktrees/nppm-7-merged"
mkdir -p "$MERGED_WT"
( cd "$MERGED_WT" && git init -q \
    && git -c user.email=t@example.com -c user.name=t commit --allow-empty -qm fix \
    && git remote add origin "$ORIGIN2" \
    && git push -q origin HEAD:refs/pull/77/head )
bash "$L" init run-merged NPPM-7 operator-named >/dev/null
bash "$L" set run-merged '.env = {name:"autofix-env-merged"} | .branch = "nppm-7-merged" | .pr = {number:77}'
bash "$E" destroy run-merged >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 0 "$rc" "merged PR (remote branch deleted): destroy accepts a tip preserved in refs/pull/<n>/head"
assert_eq "n env destroy autofix-env-merged" "$(grep 'env destroy autofix-env-merged' "$N_LOG")" "merged PR: n env destroy invoked"

# ...but a commit made after the PR head was last pushed is still unpushed.
( cd "$MERGED_WT" && git -c user.email=t@example.com -c user.name=t commit --allow-empty -qm later )
bash "$L" set run-merged '.env = {name:"autofix-env-merged2"}'
bash "$E" destroy run-merged >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "tip ahead of the PR head: destroy still refuses"
assert_eq "" "$(grep 'autofix-env-merged2' "$N_LOG" || true)" "tip ahead of the PR head: no n env destroy"

# No branch and no PR number recorded: refuse, as before.
bash "$L" set run-merged '.pr = null'
bash "$E" destroy run-merged >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "no remote branch and no PR number: destroy refuses"

# base-ref discipline: a branch that already exists locally (e.g. a resumed
# run re-invoking create) is left alone — `n branch` is only run to CREATE
# it, never to reset an existing one.
bash "$L" init run-c NPPM-2994 operator-named >/dev/null
bash "$L" set run-c '.decisions += [{key:"branch_stem", value:"nppm-2994-bug-existing"}]'
: > "$GIT_LOG"
STUB_BRANCH_EXISTS_EXIT=0 bash "$E" create run-c newspack-multibranded-site -- --block-theme >/dev/null
assert_contains "$(cat "$GIT_LOG")" "fetch origin main" "existing-branch case still fetches origin/main"
assert_eq "" "$(grep 'branch nppm-2994-bug-existing-c origin/main' "$GIT_LOG" || true)" \
  "existing-branch case does NOT re-create an already-existing branch"

# base-ref discipline: origin/main fetch failure is tolerated only when
# origin/main already resolves locally; if it can't resolve at all, die
# rather than falling back to the local trunk HEAD.
bash "$L" init run-d NPPM-2995 operator-named >/dev/null
bash "$L" set run-d '.decisions += [{key:"branch_stem", value:"nppm-2995-bug-nofetch"}]'
: > "$GIT_LOG"
STUB_FETCH_EXIT=1 STUB_REVPARSE_EXIT=1 \
  bash "$E" create run-d newspack-multibranded-site >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "unresolvable origin/main (fetch fails, no cached ref) dies rather than guessing the base"
assert_eq "" "$(grep 'branch nppm-2995' "$GIT_LOG" || true)" \
  "unresolvable origin/main: branch never pre-created"
# --- profiles -----------------------------------------------------------------
PROF="$(mktemp)"; cat > "$PROF" <<'EOF'
{ "newspack-workspace": { "base": {"ref":"origin/main","fetch":"origin main"}, "env": true,
    "path_scope": ["plugins/{affected_repo}/"], "commands": [], "suite": null, "lint": "phpcs", "push_remote": "origin" },
  "noenv": { "base": {"ref":"main","fetch":null}, "env": false, "path_scope": ["tools/"],
    "commands": [], "suite": null, "lint": "none", "push_remote": "fork" } }
EOF
export AUTOFIX_PROFILES="$PROF"
bash "$L" init rune NPPM-7 forge >/dev/null
bash "$L" set rune '.decisions += [{key:"branch_stem", value:"nppm-7-x"}, {key:"profile", value:"noenv"}]'
: > "$N_LOG"
out="$(bash "$E" create rune newspack-plugin 2>&1)" && rc=0 || rc=$?
assert_eq 1 "$rc" "env.sh create refuses a profile without an env"
assert_contains "$out" "has no env" "refusal says the profile has no env"
assert_eq "" "$(cat "$N_LOG")" "no n env call for an env-less profile"
unset AUTOFIX_PROFILES
# A new env's worktree has no dev dependencies, so create installs them for the
# affected unit when it declares Composer packages and has none installed.
# `n composer` resolves its project from the cwd, so it runs from that unit.
cat > "$STUB/n" <<'EOF'
#!/bin/bash
echo "n $*" >> "${N_LOG:?}"
[ "$1" = composer ] && echo "$PWD" >> "${N_CWD_LOG:?}"
exit "${N_EXIT:-0}"
EOF
chmod +x "$STUB/n"
export N_CWD_LOG="$STUB/n-cwd.log"
bash "$L" init run-cmp NPPM-3001 operator-named >/dev/null
bash "$L" set run-cmp '.decisions += [{key:"branch_stem", value:"nppm-3001-x"}]'
P="$AUTOFIX_WORKSPACE_ROOT/worktrees/nppm-3001-x-cmp/plugins/my-plugin"; mkdir -p "$P"; echo '{}' > "$P/composer.json"
: > "$N_LOG"; : > "$N_CWD_LOG"
bash "$E" create run-cmp my-plugin >/dev/null 2>&1; rc=$?
assert_eq 0 "$rc" "create succeeds with a Composer unit"
assert_contains "$(cat "$N_LOG")" "n composer install" "and installs its dev dependencies"
assert_eq "$P" "$(cat "$N_CWD_LOG")" "from the unit's directory in the run's worktree"
assert_eq 2 "$(grep -c -E '^n (env create|setup)' "$N_LOG")" "after the env is created and set up"

# env.sh reads the dev-dependency check from the workspace it serves.
mkdir -p "$AUTOFIX_WORKSPACE_ROOT/bin"; cp ../../../bin/_common.sh "$AUTOFIX_WORKSPACE_ROOT/bin/"
bash "$L" init run-cmq NPPM-3002 operator-named >/dev/null
bash "$L" set run-cmq '.decisions += [{key:"branch_stem", value:"nppm-3002-x"}]'
Q="$AUTOFIX_WORKSPACE_ROOT/worktrees/nppm-3002-x-cmq/plugins/my-plugin"; mkdir -p "$Q/vendor/composer"; echo '{}' > "$Q/composer.json"
# Env up leaves a runtime-only vendor/; installed.json's top-level "dev" flag
# is what tells it apart from a complete one.
printf '{\n    "packages": [],\n    "dev": false,\n    "dev-package-names": []\n}\n' > "$Q/vendor/composer/installed.json"
: > "$N_LOG"; bash "$E" create run-cmq my-plugin >/dev/null 2>&1
assert_eq 1 "$(grep -c 'composer install' "$N_LOG")" "a runtime-only vendor/ still gets its dev dependencies"
printf '{\n    "packages": [],\n    "dev": true,\n    "dev-package-names": []\n}\n' > "$Q/vendor/composer/installed.json"
bash "$L" set run-cmq '.attempts.provisioning = 0'
: > "$N_LOG"; bash "$E" create run-cmq my-plugin >/dev/null 2>&1
assert_eq 0 "$(grep -c 'composer' "$N_LOG")" "a unit with dev dependencies installed is left alone"

# A forge run's pinned tooling holds only tools/ and .claude/skills/, so the
# dev-dependency check has to come from the workspace, not from beside env.sh.
PIN="$(mktemp -d)"; mkdir -p "$PIN/tools"; cp -R "$(cd .. && pwd)" "$PIN/tools/autofix"
bash "$L" set run-cmq '.attempts.provisioning = 0'
: > "$N_LOG"; bash "$PIN/tools/autofix/bin/env.sh" create run-cmq my-plugin >/dev/null 2>&1
assert_eq 0 "$(grep -c 'composer' "$N_LOG")" "pinned tooling reads the dev-dependency check from the workspace"
rm -f "$AUTOFIX_WORKSPACE_ROOT/bin/_common.sh"

bash "$L" init run-cmr NPPM-3003 operator-named >/dev/null
bash "$L" set run-cmr '.decisions += [{key:"branch_stem", value:"nppm-3003-x"}]'
mkdir -p "$AUTOFIX_WORKSPACE_ROOT/worktrees/nppm-3003-x-cmr/plugins/my-plugin"
: > "$N_LOG"; bash "$E" create run-cmr my-plugin >/dev/null 2>&1
assert_eq 0 "$(grep -c 'composer' "$N_LOG")" "so is a unit without composer.json"

bash "$L" init run-cms NPPM-3004 operator-named >/dev/null
bash "$L" set run-cms '.decisions += [{key:"branch_stem", value:"nppm-3004-x"}]'
S="$AUTOFIX_WORKSPACE_ROOT/worktrees/nppm-3004-x-cms/themes/my-theme"; mkdir -p "$S"; echo '{}' > "$S/composer.json"
cat > "$STUB/n" <<'EOF'
#!/bin/bash
echo "n $*" >> "${N_LOG:?}"
[ "$1" = composer ] && exit 3
exit 0
EOF
: > "$N_LOG"; out="$(bash "$E" create run-cms my-theme 2>&1)"; rc=$?
assert_eq 1 "$rc" "a failed install fails the create"
assert_contains "$out" "composer install failed" "and says so"
assert_eq true "$(bash "$L" get run-cms '.env != null')" "with the env recorded, so a destroy can find it"

finish
