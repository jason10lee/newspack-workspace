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

# a session waiting on a question is not dead, however long it waits
fresh; mkrun forge-wq; waiting; bash "$L" set forge-wq '.stage_history = [{stage:"x", outcome:"y", at:"2026-01-01T00:00:00Z"}]'
: > "$STUB_LOG"
out="$(FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 bash "$F" check 2>/dev/null)"
out="$(FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 bash "$F" check 2>/dev/null)"
assert_contains "$out" "WAITING forge-wq" "a waiting session is reported"
assert_contains "$out" "claude attach 1a2b3c4d" "with how to answer it"
assert_eq null "$(bash "$L" get forge-wq .terminal)" "and is never bailed"
assert_eq "" "$(grep 'stop 1a2b3c4d' "$STUB_LOG")" "and never stopped"
bash "$L" set forge-wq '.liveness = {stale_seen_at: "2026-01-01T00:00:00Z", last_progress: "x"}'
FORGE_STAGE_BOUND=60 bash "$F" check >/dev/null 2>&1
assert_eq null "$(bash "$L" get forge-wq .liveness)" "and a stale mark from before it waited is cleared"

# a waiting session that still has its process is never judged stalled either
fresh; mkrun forge-wl; waiting_live; bash "$L" set forge-wl '.stage_history = [{stage:"x", outcome:"y", at:"2026-01-01T00:00:00Z"}]'
: > "$STUB_LOG"
FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 bash "$F" check >/dev/null 2>&1
out="$(FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 bash "$F" check 2>/dev/null)"
assert_contains "$out" "WAITING forge-wl" "a blocked session with its pid is reported waiting"
assert_eq null "$(bash "$L" get forge-wl .terminal)" "and is never bailed"
assert_eq "" "$(grep 'stop 1a2b3c4d' "$STUB_LOG")" "and never stopped"

# answering a waiting run restarts its stall clock
fresh; mkrun forge-ans; waiting; bash "$L" set forge-ans '.stage_history = [{stage:"x", outcome:"y", at:"2026-01-01T00:00:00Z"}]'
FORGE_STAGE_BOUND=60 bash "$F" check >/dev/null 2>&1
alive
out="$(FORGE_STAGE_BOUND=60 FORGE_STALE_RECHECK=0 bash "$F" check 2>/dev/null)"
assert_contains "$out" "ALIVE forge-ans" "a run answered after waiting is timed from when it was seen waiting"
assert_eq null "$(bash "$L" get forge-ans .liveness)" "and carries no stale mark"

# a state check does not know is named, and changes nothing
fresh; mkrun forge-us; printf '[{"id":"1a2b3c4d","kind":"background","state":"somethingnew"}]\n' > "$STUB_AGENTS"
out="$(bash "$F" check 2>/dev/null)"
assert_contains "$out" "UNKNOWN-STATE forge-us" "an unrecognised state is reported"
assert_contains "$out" "somethingnew" "by name"
assert_eq "" "$(printf '%s' "$out" | grep 'could not read')" "without claiming the session could not be read"
assert_eq null "$(bash "$L" get forge-us .terminal)" "and changes nothing"

# claude agents failing is not a dead session
fresh; mkrun forge-x; alive
out="$(STUB_AGENTS_FAIL=1 bash "$F" check 2>/dev/null)"
assert_contains "$out" "UNKNOWN-STATE forge-x" "a failing claude agents is reported, not read as gone"
assert_contains "$out" "could not read session 1a2b3c4d" "and says it could not be read"
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
bash "$L" history forge-v intake claimed x; bash "$L" history forge-v release restored x; bash "$L" history forge-v intake claiming ""
sed "s/RUNID/forge-v/" "$FX/issue_release_unchanged.json" > "$M/issue_release.json"
out="$(forge check)"
assert_contains "$out" "restored" "a claim that wrote but never recorded is restored by check"

# the recheck has to outlast a review round, which records no progress until it ends
assert_eq 2700 "$( unset FORGE_STALE_RECHECK; . ../bin/lib/forge.sh; echo "$FORGE_STALE_RECHECK" )" "the default recheck is 45 minutes"

# A run that lost its claim race is terminal, so it holds no slot, but its claim
# can still stand when every post-claim read was stale. check restores it once.
lost() { # rid, then the newest claim events
  local rid="$1"; shift
  mkrun "$rid"; mock "$rid"; gone
  for ev in "$@"; do bash "$L" history "$rid" "${ev%% *}" "${ev#* }" ""; done
  bash "$L" set "$rid" '.terminal = "bailed-lost-claim-race" | .linear_prior = {assigneeId: null, stateId: "7fad47f0-3b4a-42ca-bf01-41a979e4cbbe", stateName: "Backlog", labels: [], claimed_state_id: "f00ac312-3e9b-4fc2-a119-1a7cd68ece00"}'
  sed "s/RUNID/$rid/" "$FX/issue_release_unchanged.json" > "$M/issue_release.json"
}
fresh; lost forge-lr "intake claiming"
out="$(forge check)"
assert_contains "$out" "LOST-CLAIM forge-lr" "check restores the claim a lost race left standing"
assert_contains "$out" "restored" "and says the restore ran"
assert_eq 1 "$(updates)" "with one Linear update"
assert_eq restored "$(bash "$L" get forge-lr '.stage_history[-1].outcome')" "and records the release"
assert_eq bailed-lost-claim-race "$(bash "$L" get forge-lr .terminal)" "the run keeps its terminal state"
out="$(forge check)"
assert_eq 0 "$(printf '%s' "$out" | grep -c 'LOST-CLAIM')" "a second check leaves it alone"
assert_eq 1 "$(updates)" "and writes nothing more"

fresh; lost forge-lq "intake claiming" "release restored"
out="$(forge check)"
assert_eq 0 "$(printf '%s' "$out" | grep -c 'LOST-CLAIM')" "a lost race already released is left alone"
assert_eq 0 "$(updates)" "with no Linear write"

fresh; lost forge-lw "intake claiming"
sed "s/RUNID/forge-lw/" "$FX/issue_release_humanized.json" > "$M/issue_release.json"
out="$(forge check)"
assert_contains "$out" "drifted:[assigneeId stateId]" "a lost race leaves alone an issue the winner holds in progress"
assert_eq 0 "$(updates)" "with no Linear write"

fresh; lost forge-lo "intake claiming"; bash "$L" init forge-ln NPPM-2993 forge >/dev/null
assert_eq 0 "$(forge check | grep -c 'LOST-CLAIM')" "a lost race is left alone once a later run is open on the issue"
assert_eq 0 "$(updates)" "so that run's claim is not undone"

fresh; lost forge-le "intake claiming"; bash "$L" init autofix-le NPPM-2993 standard >/dev/null
bash "$L" history autofix-le intake claimed ""; bash "$L" set autofix-le '.terminal = "bailed-no-go"'
assert_eq 0 "$(forge check | grep -c 'LOST-CLAIM')" "or once a later run of any mode has ended on it"
assert_eq 0 "$(updates)" "with no Linear write"

fresh; bash "$L" init forge-lp NPPM-2993 forge >/dev/null
bash "$L" history forge-lp intake claimed ""
bash "$L" set forge-lp '.stage_history[-1].at = "2026-01-01T00:00:00Z" | .delivery = {kind: "delivered"} | .retired_at = "2026-01-02T00:00:00Z"'
lost forge-lb "intake claiming"
assert_contains "$(forge check)" "LOST-CLAIM forge-lb" "an earlier run delivered and retired does not hold the restore back"
assert_eq 1 "$(updates)" "so the claim is restored"

fresh; lost forge-la "intake claiming"; bash "$L" init forge-lz NPPM-2993 forge >/dev/null
bash "$L" history forge-lz launch abandoned ""; bash "$L" set forge-lz '.terminal = "bailed-launch"'
assert_contains "$(forge check)" "LOST-CLAIM forge-la" "nor does a later launch that never reached a claim"
assert_eq 1 "$(updates)" "so the claim is restored"

fresh; lost forge-lt "intake claiming"; bash "$L" set forge-lt '.retired_at = "2026-11-06T00:00:00Z"'
assert_eq 0 "$(forge check | grep -c 'LOST-CLAIM')" "a retired lost race is left alone"
assert_eq 0 "$(updates)" "with no Linear write"

finish
