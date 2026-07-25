#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")"; . ./helpers.sh
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
assert_contains "$(cat "$N_LOG")" "env destroy autofix-env-slash --yes" \
  "slashed branch: n env destroy invoked (push-check passed via RAW branch ls-remote)"

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
finish
