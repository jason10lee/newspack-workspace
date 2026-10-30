#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh; . ./setup.sh
FX=../../autofix/tests/fixtures
R=../bin/forge-run
ME=56b3262a-bf16-4c9f-8c0f-8580fc5f6fea
INPROG='{id: "f00ac312-3e9b-4fc2-a119-1a7cd68ece00", name: "In Progress", type: "started"}'
fr() { bash "$R" "$@" 2>/dev/null; }
mock() { # rid issue-json-file — Linear mocks for this run
  M="$(mktemp -d)"; export AUTOFIX_LINEAR_MOCK_DIR="$M"
  cp "$FX/viewer.json" "$FX/states.json" "$FX/issueUpdate.json" "$M/"
  cp "$2" "$M/issue.json"
  sed "s/RUNID/$1/" "$FX/issue_postclaim_ok.json" > "$M/issue_postclaim.json"
}
issue_with() { local f; f="$(mktemp)"; jq "$1" "$FX/issue_ok.json" > "$f"; printf '%s' "$f"; }
updates() { [ -f "$M/requests.log" ] || { echo 0; return; }; grep -c issueUpdate "$M/requests.log" || true; }
requests() { cat "$M/requests.log" 2>/dev/null || true; }
# Each run gets its own state root: they all work NPPM-2993, and autofix's
# same-issue guard rightly refuses a claim while another run of it is open.
run() {
  AUTOFIX_ROOT="$(mktemp -d)"; export AUTOFIX_ROOT
  bash "$L" init "$1" NPPM-2993 forge >/dev/null
}
confirmed() { run "$1"; bash "$L" set "$1" '.launch = "confirmed"'; }

# state
run f-st
out="$(fr state f-st)"
assert_contains "$out" "RUN=f-st" "state names the run"
assert_contains "$out" "ISSUE=NPPM-2993" "and its issue"
assert_contains "$out" "CLAIM=none" "a run that never claimed has no claim"
assert_contains "$out" "FRESH_TEST_IN_PROGRESS=false" "and no fresh test under way"
bash "$L" history f-st intake claimed x
assert_contains "$(fr state f-st)" "CLAIM=held" "a claim is held"
bash "$L" history f-st release restored x
assert_contains "$(fr state f-st)" "CLAIM=released" "and released by a release"
bash "$L" set f-st '.fresh_test_in_progress = true | .decisions += [{key: "rework", value: "use the smaller fix"}]'
out="$(fr state f-st)"
assert_contains "$out" "FRESH_TEST_IN_PROGRESS=true" "state shows the fresh-test mark"
assert_contains "$out" "REWORK=use the smaller fix" "and the operator's rework"
bash "$L" init autofix-x NPPM-1 operator-named >/dev/null
fr state autofix-x >/dev/null; assert_eq 1 $? "state refuses a run that is not a forge run"

# the launch handshake
run f-ab; bash "$L" set f-ab '.launch = "abandoned"'; mock f-ab "$FX/issue_ok.json"
out="$(fr claim f-ab)"; rc=$?
assert_eq 3 "$rc" "an abandoned launch exits 3"
assert_contains "$out" "ABANDONED f-ab" "and says so"
assert_eq "" "$(requests)" "and reads and writes nothing in Linear"

run f-ex; mock f-ex "$FX/issue_ok.json"
out="$(FORGE_LAUNCH_WAIT=0 bash "$R" claim f-ex 2>/dev/null)"; rc=$?
assert_eq 4 "$rc" "a launch never confirmed exits 4 when the wait runs out"
assert_contains "$out" "WAIT-EXPIRED f-ex" "and says so"
assert_eq wait-expired "$(bash "$L" get f-ex '.stage_history[-1].outcome')" "and records it"
assert_eq "" "$(requests)" "with nothing read or written"

run f-late; mock f-late "$FX/issue_ok.json"
( sleep 1; bash "$L" set f-late '.launch = "confirmed"' ) &
out="$(FORGE_LAUNCH_WAIT=20 FORGE_POLL_INTERVAL=0.2 bash "$R" claim f-late 2>/dev/null)"; rc=$?
wait
assert_eq 0 "$rc" "a launch confirmed while the run waits is claimed"
assert_contains "$out" "CLAIMED NPPM-2993" "and says so"
assert_eq 1 "$(updates)" "through one update"
assert_eq "" "$(grep commentCreate "$M/requests.log" || true)" "with no comment"

# what the issue says
confirmed f-sec; mock f-sec "$FX/issue_security.json"
out="$(fr claim f-sec)"; rc=$?
assert_eq 2 "$rc" "a Security label exits 2"
assert_contains "$out" "SECURITY" "and says so"
assert_eq 0 "$(updates)" "and claims nothing"

confirmed f-pr; mock f-pr "$FX/issue_pr.json"
out="$(fr claim f-pr)"; rc=$?
assert_eq 5 "$rc" "an existing PR exits 5"
assert_contains "$out" "EXISTING-PR" "and says so"

confirmed f-oth; mock f-oth "$(issue_with '.data.issue.assignee = {id: "someone-else"}')"
out="$(fr claim f-oth)"; rc=$?
assert_eq 6 "$rc" "an issue assigned to someone else exits 6"
assert_contains "$out" "HELD-BY-OTHER" "and says so"
assert_eq 0 "$(updates)" "and is not reassigned"

# a rework
confirmed f-held; bash "$L" history f-held intake claimed x
mock f-held "$(issue_with ".data.issue.assignee = {id: \"$ME\"} | .data.issue.state = $INPROG")"
out="$(fr claim f-held)"; rc=$?
assert_eq 0 "$rc" "a rework whose claim still holds goes on"
assert_contains "$out" "HELD NPPM-2993" "and says the claim holds"
assert_eq 0 "$(updates)" "without claiming again"
assert_eq claim-verified "$(bash "$L" get f-held '.stage_history[-1].outcome')" "and records the check"

confirmed f-lost; bash "$L" history f-lost intake claimed x; mock f-lost "$FX/issue_ok.json"
out="$(fr claim f-lost)"; rc=$?
assert_eq 7 "$rc" "a held claim someone changed exits 7"
assert_contains "$out" "CLAIM-LOST" "and says so"
assert_eq 1 "$(bash "$L" get f-lost '.drift_log | length')" "and records the drift"
assert_eq 0 "$(updates)" "and writes nothing"

confirmed f-rel; bash "$L" history f-rel intake claimed x; bash "$L" history f-rel release restored x
mock f-rel "$FX/issue_ok.json"
out="$(fr claim f-rel)"; rc=$?
assert_eq 0 "$rc" "a rework of a stopped run claims again"
assert_contains "$out" "CLAIMED" "and says so"
assert_eq 1 "$(updates)" "through one update"


# a run the operator retired does not hold the issue against a new one
run f-old; bash "$L" set f-old '.delivery = {kind: "delivered", at: "2026-10-29T00:00:00Z"} | .retired_at = "2026-10-29T01:00:00Z"'
bash "$L" init f-new NPPM-2993 forge >/dev/null; bash "$L" set f-new '.launch = "confirmed"'; mock f-new "$FX/issue_ok.json"
out="$(fr claim f-new)"; rc=$?
assert_eq 0 "$rc" "a retired run of the same issue does not block a new claim"
assert_contains "$out" "CLAIMED NPPM-2993" "and the new run claims"

# every exit-1 path names itself
run f-if; bash "$L" set f-if '.launch = "confirmed"'; mock f-if "$FX/issue_ok.json"; rm "$M/issue.json"
out="$(fr claim f-if)"; rc=$?
assert_eq 1 "$rc" "an intake failure exits 1"
assert_contains "$out" "CLAIM-FAILED" "with the token the skill knows"

finish
