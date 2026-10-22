#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ./helpers.sh
L=../bin/ledger.sh
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
export AUTOFIX_WORKSPACE_ROOT; AUTOFIX_WORKSPACE_ROOT="$(mktemp -d)"

# ledger: forge mode
bash "$L" init frg1 NPPM-1 forge >/dev/null
assert_eq forge "$(bash "$L" get frg1 .mode)" "init forge → .mode=forge"
assert_eq false "$(bash "$L" get frg1 .secure)" "init forge → .secure=false"
assert_eq null "$(bash "$L" get frg1 .launch)" "init → .launch=null"
assert_eq null "$(bash "$L" get frg1 .worktree)" "init → .worktree=null"

# is_forge fails CLOSED
bash "$L" init base1 NPPM-2 operator-named >/dev/null
probe() { ( . ../bin/lib/common.sh; is_forge "$1" && echo forge || echo not-forge ) 2>/dev/null; }
assert_eq forge "$(probe frg1)" "is_forge: forge ledger → forge"
assert_eq not-forge "$(probe base1)" "is_forge: operator-named → not forge"
assert_eq forge "$(probe no-such-run)" "is_forge: missing ledger → forge (fail-closed)"
mkdir -p "$AUTOFIX_ROOT/runs/corrupt1"; echo '{not json' > "$AUTOFIX_ROOT/runs/corrupt1/ledger.json"
assert_eq forge "$(probe corrupt1)" "is_forge: corrupt ledger → forge (fail-closed)"

# run_wt: recorded worktree wins; otherwise the monorepo path from .branch
wtp() { ( . ../bin/lib/common.sh; run_wt "$1" ) 2>/dev/null; }
bash "$L" set base1 '.branch = "jason/nppm-2-fix"'
assert_eq "$AUTOFIX_WORKSPACE_ROOT/worktrees/jason-nppm-2-fix" "$(wtp base1)" "run_wt: monorepo path from branch"
bash "$L" set frg1 '.worktree = "/tmp/forge-dirs/reprise/1"'
assert_eq "/tmp/forge-dirs/reprise/1" "$(wtp frg1)" "run_wt: recorded worktree wins"

# --- claim.sh under the forge grant ------------------------------------------
C=../bin/claim.sh
mock() { # run_id — a fresh runs dir per case, so autofix's same-issue guard
  # does not see the earlier cases' ledgers for the same issue
  AUTOFIX_ROOT="$(mktemp -d)"
  M="$(mktemp -d)"; export AUTOFIX_LINEAR_MOCK_DIR="$M"
  cp fixtures/viewer.json fixtures/states.json fixtures/issueUpdate.json fixtures/commentCreate.json "$M/"
  cp fixtures/issue_ok.json "$M/issue.json"
  sed "s/RUNID/$1/" fixtures/issue_postclaim_ok.json > "$M/issue_postclaim.json"
}

# issue guard: every mode refuses another issue; case does not matter
mock g1; bash "$L" init g1 NPPM-2993 operator-named >/dev/null
bash "$C" claim NPPM-9999 g1 >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "claim refuses an issue that is not the ledger's"
assert_eq "" "$(grep issueUpdate "$M/requests.log" 2>/dev/null || true)" "refused claim wrote nothing"
mock g2; bash "$L" init g2 nppm-2993 operator-named >/dev/null
bash "$C" claim NPPM-2993 g2 >/dev/null 2>&1
assert_eq 0 $? "issue guard ignores case"

# forge: no claim before the dispatcher confirms the launch
mock f1; bash "$L" init f1 NPPM-2993 forge >/dev/null
bash "$C" claim NPPM-2993 f1 >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 8 "$rc" "forge claim exits 8 without launch=confirmed"
assert_eq "" "$(grep issueUpdate "$M/requests.log" 2>/dev/null || true)" "unconfirmed launch wrote nothing"
bash "$L" set f1 '.launch = "abandoned"'
bash "$C" claim NPPM-2993 f1 >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 8 "$rc" "forge claim exits 8 on launch=abandoned"

# forge: confirmed launch claims with no comment, verified by assignee
mock f2; bash "$L" init f2 NPPM-2993 forge >/dev/null
bash "$L" set f2 '.launch = "confirmed"'
bash "$C" claim NPPM-2993 f2 >/dev/null 2>&1
assert_eq 0 $? "forge claim succeeds once launch is confirmed"
assert_contains "$(cat "$M/requests.log")" issueUpdate "forge claim set assignee and state"
assert_eq claimed "$(bash "$L" get f2 '.stage_history[-1].outcome')" "forge claim recorded as claimed"
assert_eq "" "$(grep commentCreate "$M/requests.log" || true)" "forge claim posts no comment"
assert_eq "" "$(ls "$AUTOFIX_ROOT/runs/f2/previews" 2>/dev/null || true)" "forge claim writes no comment preview"

# forge: comment subcommand and commented/labelled releases are refused
BODYF="$(mktemp)"; echo "note" > "$BODYF"
out="$(bash "$C" comment NPPM-2993 f2 --body-file "$BODYF" 2>&1)" && rc=0 || rc=$?
assert_eq 1 "$rc" "forge refuses claim.sh comment"
assert_contains "$out" "outside the forge grant" "comment refusal names the grant"
out="$(bash "$C" release NPPM-2993 f2 --comment "why" 2>&1)" && rc=0 || rc=$?
assert_eq 1 "$rc" "forge refuses a release comment"
assert_contains "$out" "outside the forge grant" "release-comment refusal names the grant"
out="$(bash "$C" release NPPM-2993 f2 --fail-label 2>&1)" && rc=0 || rc=$?
assert_eq 1 "$rc" "forge refuses the failure label"
assert_contains "$out" "outside the forge grant" "label refusal names the grant"
assert_eq "" "$(grep -e commentCreate -e labelIds "$M/requests.log" || true)" "refusals wrote no comment or label"

finish
