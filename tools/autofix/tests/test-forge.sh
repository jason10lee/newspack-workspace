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

finish
