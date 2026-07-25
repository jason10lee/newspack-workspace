#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")"; . ./helpers.sh
P=../bin/pr.sh; L=../bin/ledger.sh
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
export AUTOFIX_WORKSPACE_ROOT="$(mktemp -d)"
mkdir -p "$AUTOFIX_WORKSPACE_ROOT/worktrees/br-2"
STUB="$(mktemp -d)"; export PATH="$STUB:$PATH"
cat > "$STUB/git" <<'EOF'
#!/bin/bash
echo "git $* (pwd=$PWD)" >> "${STUB_LOG:?}"
case "$*" in
  "fetch origin main")
    exit "${STUB_FETCH_EXIT:-0}" ;;
  "rev-parse -q --verify origin/main")
    exit "${STUB_REVPARSE_EXIT:-0}" ;;
  "diff --name-only origin/main...HEAD")
    [ -n "${STUB_DIFF_FILES:-}" ] && printf '%s\n' "${STUB_DIFF_FILES}"
    exit 0 ;;
  "rev-list --count origin/main..HEAD")
    printf '%s\n' "${STUB_REVCOUNT:-2}"
    exit 0 ;;
esac
exit 0
EOF
cat > "$STUB/gh" <<'EOF'
#!/bin/bash
echo "gh $*" >> "${STUB_LOG:?}"
case "$1 $2" in
  "pr list") printf '%s\n' "${GH_PR_LIST_OUT:-}" ;;
  "pr create") echo "https://github.com/Automattic/newspack-workspace/pull/999" ;;
esac
exit 0
EOF
chmod +x "$STUB/git" "$STUB/gh"
export STUB_LOG="$STUB/log"; : > "$STUB_LOG"
# Scope-guard defaults: an in-prefix diff + a small commit count, so the
# happy-path tests below sail through the guard unchanged. Individual scope
# guard test cases below override these locally and restore them after.
export STUB_DIFF_FILES="plugins/newspack-plugin/includes/class-foo.php"
export STUB_REVCOUNT=2

bash "$L" init runp NPPM-1 operator-named >/dev/null
bash "$L" set runp '.branch = "br-2"'
bash "$L" set runp '.decisions += [{key:"affected_repo", value:"newspack-plugin"}]'
BODY="$(mktemp)"; echo "Fixes the bug. Evidence attached." > "$BODY"

bash "$P" create runp --title "fix(x): y (NPPM-1)" --body-file "$BODY"
assert_contains "$(cat "$STUB_LOG")" "push -u origin br-2" "branch pushed"
assert_contains "$(cat "$STUB_LOG")" "pr create --draft" "draft PR created"
assert_contains "$(cat "$STUB_LOG")" copilot-pull-request-reviewer "Copilot requested via REST"
assert_eq delivered "$(bash "$L" get runp .terminal)" "terminal=delivered"
assert_contains "$(bash "$L" get runp .pr.url)" /pull/999 "PR url recorded"
assert_eq 999 "$(bash "$L" get runp .pr.number)" "PR number derived from URL"

# redaction blocks before push
bash "$L" init runq NPPM-2 operator-named >/dev/null
bash "$L" set runq '.branch = "br-2"'
: > "$STUB_LOG"
echo "creds: https://mc.a8c.com/secret-store/?secret_id=1" > "$BODY"
bash "$P" create runq --title t --body-file "$BODY" >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "redaction finding aborts"
assert_eq "" "$(grep push "$STUB_LOG" || true)" "nothing pushed on redaction failure"

# adoption: an existing open PR for the branch is adopted, not re-created
bash "$L" init runr NPPM-3 operator-named >/dev/null
bash "$L" set runr '.branch = "br-2"'
bash "$L" set runr '.decisions += [{key:"affected_repo", value:"newspack-plugin"}]'
: > "$STUB_LOG"
echo "Fixes the bug. Evidence attached." > "$BODY"
export GH_PR_LIST_OUT='{"url":"https://github.com/Automattic/newspack-workspace/pull/321","number":321,"isDraft":true}'
bash "$P" create runr --title t --body-file "$BODY" >/dev/null
unset GH_PR_LIST_OUT
assert_contains "$(cat "$STUB_LOG")" "push -u origin br-2" "adoption still pushes first"
assert_eq "" "$(grep 'pr create' "$STUB_LOG" || true)" "existing PR not re-created"
assert_eq 321 "$(bash "$L" get runr .pr.number)" "existing PR number adopted"
assert_eq delivered "$(bash "$L" get runr .terminal)" "adoption reaches delivered"
assert_contains "$(bash "$L" get runr '.stage_history[-1].notes')" "adopted existing PR" "adoption noted in history"

# attempt cap: at 3 attempts, die before any push/create, terminal=escalated.
# The scope guard runs BEFORE the attempt cap (see pr.sh comment 2), so its
# git calls (fetch/diff/rev-list) DO run here — only push/gh must not.
bash "$L" init runs NPPM-4 operator-named >/dev/null
bash "$L" set runs '.branch = "br-2"'
bash "$L" set runs '.decisions += [{key:"affected_repo", value:"newspack-plugin"}]'
bash "$L" set runs '.attempts.pr = 3'
: > "$STUB_LOG"
bash "$P" create runs --title t --body-file "$BODY" >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "at-cap create dies"
assert_eq "" "$(grep -E 'push|^gh ' "$STUB_LOG" || true)" "at-cap create pushes nothing and calls no gh command"
assert_eq escalated "$(bash "$L" get runs .terminal)" "at-cap sets terminal=escalated"
assert_eq 3 "$(bash "$L" get runs '.attempts.pr')" "at-cap create does not increment attempts.pr further"

# missing worktree: fail fast, nothing pushed
bash "$L" init runt NPPM-5 operator-named >/dev/null
bash "$L" set runt '.branch = "br-gone"'
: > "$STUB_LOG"
bash "$P" create runt --title t --body-file "$BODY" >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "missing worktree dies"
assert_eq "" "$(grep push "$STUB_LOG" || true)" "missing worktree: nothing pushed"

# CRITICAL 1 regression: a slashed branch's worktree lives at the SANITIZED
# path on disk (n's safe_branch=$(tr '/' '-')), but the push/PR ops must
# still carry the RAW branch ref
SLASH_WT="$AUTOFIX_WORKSPACE_ROOT/worktrees/jason-nppm-1-fix"
mkdir -p "$SLASH_WT"
bash "$L" init runslash NPPM-6 operator-named >/dev/null
bash "$L" set runslash '.branch = "jason/nppm-1-fix"'
bash "$L" set runslash '.decisions += [{key:"affected_repo", value:"newspack-plugin"}]'
: > "$STUB_LOG"
echo "Fixes the bug. Evidence attached." > "$BODY"
bash "$P" create runslash --title t --body-file "$BODY" >/dev/null
assert_contains "$(cat "$STUB_LOG")" "push -u origin jason/nppm-1-fix" \
  "slashed branch: push carries the RAW branch ref, not the sanitized dir name"
assert_contains "$(cat "$STUB_LOG")" "(pwd=$SLASH_WT)" \
  "slashed branch: cd landed in the sanitized worktree dir"
assert_eq delivered "$(bash "$L" get runslash .terminal)" "slashed branch: create reaches delivered"

# PR-scope guard (fork-trunk leak guard, PR #723 incident): a diff carrying a
# path outside plugins/<affected_repo>/ or themes/<affected_repo>/ must die
# BEFORE the attempt cap is touched and BEFORE any push.
bash "$L" init runscope1 NPPM-7 operator-named >/dev/null
bash "$L" set runscope1 '.branch = "br-2"'
bash "$L" set runscope1 '.decisions += [{key:"affected_repo", value:"newspack-plugin"}]'
: > "$STUB_LOG"
echo "Fixes the bug. Evidence attached." > "$BODY"
export STUB_DIFF_FILES=$'plugins/newspack-plugin/includes/class-foo.php\ntools/autofix/bin/pr.sh'
out="$(bash "$P" create runscope1 --title t --body-file "$BODY" 2>&1)" && rc=0 || rc=$?
export STUB_DIFF_FILES="plugins/newspack-plugin/includes/class-foo.php"
assert_eq 1 "$rc" "scope guard: out-of-prefix path in diff dies"
assert_contains "$out" "fork-trunk leak guard (see PR #723 incident)" "scope guard: die message carries the incident marker"
assert_contains "$out" "tools/autofix/bin/pr.sh" "scope guard: die message names the offending path"
assert_eq "" "$(grep push "$STUB_LOG" || true)" "scope guard: nothing pushed on scope violation"
assert_eq 0 "$(bash "$L" get runscope1 '.attempts.pr')" "scope guard: attempts.pr NOT burned by a scope violation"

# PR-scope guard: commit-count sanity check (153 commits — the actual
# PR #723 fork-trunk delta size) dies before push, without burning an attempt.
bash "$L" init runscope2 NPPM-8 operator-named >/dev/null
bash "$L" set runscope2 '.branch = "br-2"'
bash "$L" set runscope2 '.decisions += [{key:"affected_repo", value:"newspack-plugin"}]'
: > "$STUB_LOG"
export STUB_REVCOUNT=153
out="$(bash "$P" create runscope2 --title t --body-file "$BODY" 2>&1)" && rc=0 || rc=$?
export STUB_REVCOUNT=2
assert_eq 1 "$rc" "scope guard: 153 commits ahead of origin/main dies"
assert_contains "$out" "153 commits" "scope guard: die message names the commit count"
assert_eq "" "$(grep push "$STUB_LOG" || true)" "commit-count guard: nothing pushed"
assert_eq 0 "$(bash "$L" get runscope2 '.attempts.pr')" "commit-count guard: attempts.pr NOT burned"

# PR-scope guard: missing affected_repo decision dies closed (never guesses
# at an allowed prefix).
bash "$L" init runscope3 NPPM-9 operator-named >/dev/null
bash "$L" set runscope3 '.branch = "br-2"'
: > "$STUB_LOG"
bash "$P" create runscope3 --title t --body-file "$BODY" >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "scope guard: missing affected_repo decision dies"
assert_eq "" "$(grep push "$STUB_LOG" || true)" "missing affected_repo: nothing pushed"
finish
