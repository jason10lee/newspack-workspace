#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh; . ./setup.sh
FX=../../autofix/tests/fixtures
C=../../autofix/bin/claim.sh
forge() { bash "$F" "$@" 2>/dev/null; }
now() { date -u +%Y-%m-%dT%H:%M:%SZ; }
mkrun() { # rid — a confirmed forge run with a session and recent progress
  bash "$L" init "$1" NPPM-2993 forge >/dev/null
  bash "$L" set "$1" '.launch = "confirmed" | .session = {id: "1a2b3c4d", launched_at: $t}' --arg t "$(now)"
}
mock() { # rid — Linear mocks for a claim by this run
  M="$(mktemp -d)"; export AUTOFIX_LINEAR_MOCK_DIR="$M"
  cp "$FX/viewer.json" "$FX/states.json" "$FX/issueUpdate.json" "$FX/commentCreate.json" "$M/"
  cp "$FX/issue_ok.json" "$M/issue.json"
  sed "s/RUNID/$1/" "$FX/issue_postclaim_ok.json" > "$M/issue_postclaim.json"
}
fresh() { AUTOFIX_ROOT="$(mktemp -d)"; export AUTOFIX_ROOT; }
updates() { [ -f "$M/requests.log" ] || { echo 0; return; }; grep -c issueUpdate "$M/requests.log" || true; }

assert_eq NO-RUNS "$(forge check)" "nothing running → NO-RUNS"

fresh; bash "$L" init forge-p NPPM-1 forge >/dev/null
assert_contains "$(forge check)" "PENDING forge-p" "an unconfirmed launch is left alone"

fresh; mkrun forge-a; alive; : > "$STUB_LOG"
assert_contains "$(forge check)" "ALIVE forge-a" "a live, progressing run is ALIVE"
assert_eq "" "$(grep stop "$STUB_LOG" || true)" "and is not stopped"

fresh; mkrun forge-g; gone; mock forge-g
out="$(forge check)"
assert_contains "$out" "BAILED-DEAD forge-g" "a gone session is bailed-dead"
assert_contains "$out" "nothing claimed" "with no Linear write when nothing was claimed"
assert_eq bailed-dead "$(bash "$L" get forge-g .terminal)" "terminal recorded"
assert_eq 0 "$(updates)" "no Linear update"

fresh; mkrun forge-c; mock forge-c
bash "$C" claim NPPM-2993 forge-c >/dev/null 2>&1
assert_eq 1 "$(updates)" "the run claimed (one update)"
sed "s/RUNID/forge-c/" "$FX/issue_release_unchanged.json" > "$M/issue_release.json"
gone
out="$(forge check)"
assert_contains "$out" "BAILED-DEAD forge-c" "a gone claimed run is bailed-dead"
assert_contains "$out" "restored" "and its Linear fields are restored"
assert_eq 2 "$(updates)" "through one more update"
assert_eq "" "$(grep commentCreate "$M/requests.log" || true)" "with no comment"

: > "$STUB_LOG"
fresh; mkrun forge-s; alive
bash "$L" history forge-s stage2 started ""
bash "$L" set forge-s '.stage_history[-1].at = "2026-01-01T00:00:00Z"'
out="$(FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 bash "$F" check 2>/dev/null)"
assert_contains "$out" "STALE-MARKED forge-s" "a first stale reading only marks the run"
assert_eq null "$(bash "$L" get forge-s .terminal)" "and changes nothing else"
assert_eq "" "$(grep stop "$STUB_LOG" || true)" "and stops nothing"
out="$(FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 bash "$F" check 2>/dev/null)"
assert_contains "$out" "BAILED-DEAD forge-s" "a second stale reading stops and bails the run"
assert_contains "$out" "stalled" "and the reason says stalled"

fresh; mkrun forge-u; alive; bash "$L" set forge-u '.stage_history = [{stage:"x", outcome:"y", at:"2026-01-01T00:00:00Z"}]'
FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 bash "$F" check >/dev/null 2>&1
out="$(FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 STUB_STOP_KILLS=0 bash "$F" check 2>/dev/null)"
assert_contains "$out" "STOP-UNCONFIRMED forge-u" "a stop that does not take is reported"
assert_eq null "$(bash "$L" get forge-u .terminal)" "and changes nothing"

fresh; mkrun forge-r; alive; bash "$L" set forge-r '.stage_history = [{stage:"x", outcome:"y", at:"2026-01-01T00:00:00Z"}]'
R="$(mktemp)"; printf 'bash %q set forge-r %q\n' "$(cd "$(dirname "$L")" && pwd)/ledger.sh" '.delivery = {kind:"stopped", at:"2026-10-29T00:00:00Z"}' > "$R"
FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 bash "$F" check >/dev/null 2>&1
out="$(FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 STUB_STOP_RUN="$R" bash "$F" check 2>/dev/null)"
assert_contains "$out" "SETTLED forge-r" "a run that settled during the stop is left as it is"
assert_eq null "$(bash "$L" get forge-r .terminal)" "no bailed-dead over a delivery"

fresh; mkrun forge-t; alive; bash "$L" set forge-t '.stage_history = [{stage:"x", outcome:"y", at:"garbage"}]'
out="$(forge check)"
assert_contains "$out" "UNKNOWN-PROGRESS forge-t" "an unusable timestamp is reported"
assert_contains "$out" "ALIVE forge-t" "and never read as stalled"

# claude agents failing is not a dead session
fresh; mkrun forge-x; alive
out="$(STUB_AGENTS_FAIL=1 bash "$F" check 2>/dev/null)"
assert_contains "$out" "UNKNOWN-STATE forge-x" "a failing claude agents is reported, not read as gone"
assert_eq null "$(bash "$L" get forge-x .terminal)" "and changes nothing"
fresh; mkrun forge-y; alive; bash "$L" set forge-y '.stage_history = [{stage:"x", outcome:"y", at:"2026-01-01T00:00:00Z"}]'
FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 bash "$F" check >/dev/null 2>&1
out="$(FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 STUB_STOP_BREAKS_AGENTS=1 bash "$F" check 2>/dev/null)"
assert_contains "$out" "STOP-UNCONFIRMED forge-y" "a stop that cannot be confirmed is reported"
assert_eq null "$(bash "$L" get forge-y .terminal)" "and changes nothing"

# a launch the dispatcher never finished is abandoned once it is stale
fresh; bash "$L" init forge-st NPPM-1 forge >/dev/null
bash "$L" set forge-st '.launch_started_at = "2026-01-01T00:00:00Z" | .session = {id: "1a2b3c4d", launched_at: "2026-01-01T00:00:00Z"}'
alive; : > "$STUB_LOG"
out="$(forge check)"
assert_contains "$out" "STALE-LAUNCH forge-st" "an unfinished launch is reported stale"
assert_eq bailed-stale "$(bash "$L" get forge-st .terminal)" "and made terminal"
assert_eq abandoned "$(bash "$L" get forge-st .launch)" "with launch=abandoned"
assert_contains "$(cat "$STUB_LOG")" "stop 1a2b3c4d" "and its session is stopped"
fresh; bash "$L" init forge-nw NPPM-1 forge >/dev/null
bash "$L" set forge-nw '.launch_started_at = $t' --arg t "$(now)"
assert_contains "$(forge check)" "PENDING forge-nw" "a launch still in its window is left alone"

# the second check must come at least FORGE_STALE_RECHECK after the first
fresh; mkrun forge-w; alive; bash "$L" set forge-w '.stage_history = [{stage:"x", outcome:"y", at:"2026-01-01T00:00:00Z"}]'
FORGE_STAGE_BOUND=60 bash "$F" check >/dev/null 2>&1
out="$(FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=600 bash "$F" check 2>/dev/null)"
assert_contains "$out" "STALE-WAITING forge-w" "a second reading inside the recheck window waits"
assert_eq null "$(bash "$L" get forge-w .terminal)" "and changes nothing"

# progress between checks clears the mark
fresh; mkrun forge-p; alive; bash "$L" set forge-p '.stage_history = [{stage:"x", outcome:"y", at:"2026-01-01T00:00:00Z"}]'
FORGE_STAGE_BOUND=60 bash "$F" check >/dev/null 2>&1
bash "$L" history forge-p stage3 started ""
out="$(FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 bash "$F" check 2>/dev/null)"
assert_contains "$out" "ALIVE forge-p" "progress between checks clears the mark"
assert_eq null "$(bash "$L" get forge-p .liveness)" "and the mark is gone"

# new progress that is itself stale renews the mark instead of stopping
fresh; mkrun forge-q; alive; bash "$L" set forge-q '.stage_history = [{stage:"x", outcome:"y", at:"2026-01-01T00:00:00Z"}]'
FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 bash "$F" check >/dev/null 2>&1
bash "$L" set forge-q '.stage_history += [{stage:"x", outcome:"z", at:"2026-01-02T00:00:00Z"}]'
out="$(FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 bash "$F" check 2>/dev/null)"
assert_contains "$out" "STALE-MARKED forge-q" "new progress that is itself stale renews the mark"
assert_eq "2026-01-02T00:00:00Z" "$(bash "$L" get forge-q .liveness.last_progress)" "against the newer progress"

fresh; mkrun forge-v; gone; mock forge-v
bash "$L" set forge-v '.linear_prior = {assigneeId: null, stateId: "7fad47f0-3b4a-42ca-bf01-41a979e4cbbe", claimed_state_id: "f00ac312-3e9b-4fc2-a119-1a7cd68ece00"}'
sed "s/RUNID/forge-v/" "$FX/issue_release_unchanged.json" > "$M/issue_release.json"
out="$(forge check)"
assert_contains "$out" "restored" "a claim that wrote but never recorded is restored by check"

finish
