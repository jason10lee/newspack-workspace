#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh; . ./setup.sh
REPO="$(mktemp -d)"; export FORGE_TOOLING_REPO="$REPO" AUTOFIX_WORKSPACE_ROOT="$REPO" FORGE_PLUGINS=""
g() { git -C "$REPO" -c user.name=t -c user.email=t@example.com "$@"; }
g init -q -b main; mkdir -p "$REPO/tools/forge/hooks"
cp ../hooks/mcp-read-only.sh "$REPO/tools/forge/hooks/"; cp ../mcp-read-tools.txt "$REPO/tools/forge/"
g add -A; g commit -qm tooling
FX=../../autofix/tests/fixtures
M="$(mktemp -d)"; export AUTOFIX_LINEAR_MOCK_DIR="$M"
cp "$FX/viewer.json" "$FX/states.json" "$M/"; cp "$FX/issue_ok.json" "$M/issue.json"
forge() { bash "$F" "$@" 2>/dev/null; }
deliver() { bash "$L" set "$1" '.delivery = {kind: "delivered", at: "2026-10-29T00:00:00Z"}'; }

alive
r="$(forge launch NPPM-1 --profile newspack-workspace-tooling | sed -n 's/^RUN_ID=//p')"
wt="$(bash "$L" get "$r" .worktree)"; br="$(bash "$L" get "$r" .branch)"

out="$(forge status)"
assert_contains "$out" "$r" "status lists the run"
assert_contains "$out" "working" "as working"

out="$(forge retire "$r")"; rc=$?
assert_eq 1 "$rc" "a working run cannot be retired"

deliver "$r"
out="$(forge rework "$r" use the smaller fix)"
assert_contains "$out" "REWORK-QUEUED $r" "rework queues the run"
assert_contains "$out" "LAUNCHED $r" "and relaunches it when the slot is free"
assert_eq "use the smaller fix" "$(bash "$L" get "$r" '[.decisions[] | select(.key == "rework") | .value] | last')" "the suggestion is recorded"
assert_eq null "$(bash "$L" get "$r" .delivery)" "the delivery is cleared"
assert_eq confirmed "$(bash "$L" get "$r" .launch)" "the new session's launch is confirmed"
assert_eq "$wt" "$(bash "$L" get "$r" .worktree)" "the run keeps its worktree"

# a rework that cannot launch leaves the run as it was
deliver "$r"
out="$(STUB_BG_OUT='Workspace not trusted.' bash "$F" rework "$r" try again 2>/dev/null)"; rc=$?
assert_eq 4 "$rc" "a rework that cannot launch exits 4"
assert_contains "$out" "REWORK-FAILED $r" "and says so"
assert_eq delivered "$(bash "$L" get "$r" .delivery.kind)" "the delivery is restored"
assert_eq null "$(bash "$L" get "$r" .terminal)" "the run is not made terminal"
assert_eq yes "$([ -d "$wt" ] && echo yes || echo no)" "its worktree is untouched"
assert_eq "$br" "$(git -C "$wt" branch --show-current)" "on its branch"

# a run whose PR is out cannot be reworked: its claim would stop on the PR
# attachment and restore the issue's fields under an open PR
deliver "$r"
cp "$FX/issue_pr.json" "$M/issue.json"
out="$(bash "$F" rework "$r" address review 2>&1)"; rc=$?
assert_eq 1 "$rc" "a run whose issue already has a PR cannot be reworked"
assert_contains "$out" "already has a PR" "and says why"
assert_eq delivered "$(bash "$L" get "$r" .delivery.kind)" "the run is left as it was"
cp "$FX/issue_ok.json" "$M/issue.json"

echo change > "$wt/dirty.txt"
out="$(forge retire "$r")"; rc=$?
assert_eq 1 "$rc" "retire refuses a worktree with uncommitted changes"
assert_eq null "$(bash "$L" get "$r" .retired_at)" "and does not retire"
rm "$wt/dirty.txt"
out="$(forge retire "$r")"; rc=$?
assert_eq 0 "$rc" "retire succeeds on a clean worktree"
assert_contains "$out" "RETIRED $r" "and says so"
assert_eq no "$([ -e "$wt" ] && echo yes || echo no)" "the worktree is removed"
assert_eq "$br" "$(g branch --list "$br" | tr -d ' *')" "the branch is kept"
assert_eq "" "$(forge status | grep "$r" | grep -v retired || true)" "status shows it retired"

out="$(forge rework "$r" again)"; rc=$?
assert_eq 1 "$rc" "a retired run cannot be reworked"
bash "$L" init forge-sec NPPM-5 forge >/dev/null; bash "$L" set forge-sec '.terminal = "routed-secure"'
out="$(forge rework forge-sec x)"; rc=$?
assert_eq 1 "$rc" "a run routed to autofix-secure cannot be reworked"
bash "$L" init forge-w NPPM-6 forge >/dev/null
out="$(forge rework forge-w x)"; rc=$?
assert_eq 1 "$rc" "a working run cannot be reworked"
bash "$L" init autofix-z NPPM-7 operator-named >/dev/null
out="$(forge rework autofix-z x)"; rc=$?
assert_eq 1 "$rc" "an autofix run cannot be reworked through the forge"

finish
