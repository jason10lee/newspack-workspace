#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ./helpers.sh
C=../bin/claim.sh; L=../bin/ledger.sh; A=../bin/autofix
# The post-claim check waits between re-reads; the mock answers at once.
export AUTOFIX_CLAIM_RECHECK_DELAY=0
setup() { # $1 = postclaim fixture, $2 = run id
  export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
  M="$(mktemp -d)"; export AUTOFIX_LINEAR_MOCK_DIR="$M"
  cp fixtures/viewer.json fixtures/states.json fixtures/issueUpdate.json fixtures/commentCreate.json "$M/"
  cp fixtures/issue_ok.json "$M/issue.json"
  # fixtures are templated on RUNID: the mock returns one static body per
  # opname, so the run-specific claim comment is substituted in at setup time
  sed "s/RUNID/$2/" "fixtures/$1" > "$M/issue_postclaim.json"
  bash "$L" init "$2" NPPM-2993 operator-named >/dev/null
}

setup issue_postclaim_ok.json run1
bash "$C" claim NPPM-2993 run1
assert_eq 0 $? "clean claim succeeds"
assert_eq Backlog "$(bash "$L" get run1 '.linear_prior.stateName')" "prior state recorded"
assert_eq true "$(bash "$L" get run1 '.issue_written')" "and records that the run wrote to the issue"

# A rework claims again over the ledger of a run that already wrote, and its
# claim stops before writing: at the viewer read, or with no In Progress state.
# The run still shows it once wrote (forge's later_run reads that), but the
# earlier launch's claimed state is not this one's: a teammate who then takes
# the issue In Progress keeps it when the stopped run releases.
for stop in viewer states; do
  setup issue_postclaim_ok.json "run1b-$stop"
  bash "$L" set "run1b-$stop" '.linear_prior = {assigneeId: null, stateId: "7fad47f0-3b4a-42ca-bf01-41a979e4cbbe", stateName: "Backlog", labels: [], claimed_state_id: "f00ac312-3e9b-4fc2-a119-1a7cd68ece00"}'
  case "$stop" in
    viewer) rm "$AUTOFIX_LINEAR_MOCK_DIR/viewer.json" ;;
    states) printf '{ "data": { "team": { "states": { "nodes": [ { "id": "7fad47f0-3b4a-42ca-bf01-41a979e4cbbe", "name": "Backlog" } ] } } } }' > "$AUTOFIX_LINEAR_MOCK_DIR/states.json" ;;
  esac
  bash "$C" claim NPPM-2993 "run1b-$stop" >/dev/null 2>&1 && rc=0 || rc=$?
  assert_eq 1 "$rc" "a rework claim stopped at the $stop read fails"
  assert_eq 0 "$(grep -c issueUpdate "$AUTOFIX_LINEAR_MOCK_DIR/requests.log" 2>/dev/null || true)" "with no Linear write"
  assert_eq true "$(bash "$L" get "run1b-$stop" '.issue_written')" "and the run still shows its earlier write"
  cp fixtures/viewer.json "$AUTOFIX_LINEAR_MOCK_DIR/"
  cp fixtures/issue_release_humanized.json "$AUTOFIX_LINEAR_MOCK_DIR/issue_release.json"
  bash "$C" release NPPM-2993 "run1b-$stop" >/dev/null 2>&1
  assert_eq 0 "$(grep issueUpdate "$AUTOFIX_LINEAR_MOCK_DIR/requests.log" 2>/dev/null | grep -c stateId || true)" \
    "and its release leaves a teammate's In Progress alone"
done

setup issue_postclaim_lost.json run2
bash "$C" claim NPPM-2993 run2 >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 5 "$rc" "lost race exits 5"
assert_contains "$(grep issueUpdate "$AUTOFIX_LINEAR_MOCK_DIR/requests.log" | tail -1)" \
  7fad47f0 "lost race restored prior state (back-off wrote Backlog stateId)"

# same-issue guard: second run against an already-active issue refuses
setup issue_postclaim_ok.json run3
bash "$C" claim NPPM-2993 run3 >/dev/null
bash "$L" init run4 NPPM-2993 operator-named >/dev/null
bash "$C" claim NPPM-2993 run4 >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 4 "$rc" "same-issue guard exits 4"

# IMPORTANT 2 regression: the guard must terminalize run4's OWN ledger
# (bailed-superseded) rather than leaving it terminal:null forever — a
# null-terminal ledger would itself trip the guard for every future run
# against this issue, wedging it permanently.
assert_eq bailed-superseded "$(bash "$L" get run4 '.terminal // "none"')" \
  "same-issue guard records terminal=bailed-superseded on the superseded run"

bash "$A" cleanup >/dev/null 2>&1
assert_eq 0 $? "cleanup sweep processes the bailed-superseded ledger cleanly (no env to clean, no crash)"

# terminalize run3 too (simulating it finishing normally) so a fresh claim
# isolates whether run4's ledger — now fixed — still wedges the issue
bash "$L" set run3 '.terminal = "delivered"'
sed "s/RUNID/run4c/" "fixtures/issue_postclaim_ok.json" > "$AUTOFIX_LINEAR_MOCK_DIR/issue_postclaim.json"
bash "$L" init run4c NPPM-2993 operator-named >/dev/null
bash "$C" claim NPPM-2993 run4c
assert_eq 0 $? "guard no longer trips once prior runs are terminal — run4's fixed ledger doesn't wedge future claims"

# conditional release: human took over mid-run → no restore, drift logged
setup issue_postclaim_ok.json run5
bash "$C" claim NPPM-2993 run5 >/dev/null
cp fixtures/issue_release_humanized.json "$AUTOFIX_LINEAR_MOCK_DIR/issue_release.json"
bash "$C" release NPPM-2993 run5 >/dev/null 2>&1
assert_contains "$(bash "$L" get run5 '.drift_log[0].field')" assignee "humanized assignee → drift, not overwrite"

# ownership is run-specific: assignee matches but the claim comment belongs
# to a DIFFERENT run → this run must treat the claim as lost
setup issue_postclaim_other.json run6
bash "$C" claim NPPM-2993 run6 >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 5 "$rc" "other run's claim comment fails ownership (exit 5)"

# conditional release: human moved the state mid-run (In Review) → release
# must NOT force it back; drift logged instead
setup issue_postclaim_ok.json run7
bash "$C" claim NPPM-2993 run7 >/dev/null
sed "s/RUNID/run7/" fixtures/issue_release_statemoved.json > "$AUTOFIX_LINEAR_MOCK_DIR/issue_release.json"
bash "$C" release NPPM-2993 run7 >/dev/null 2>&1
last_update="$(grep issueUpdate "$AUTOFIX_LINEAR_MOCK_DIR/requests.log" | tail -1)"
assert_eq 0 "$(printf '%s' "$last_update" | grep -c stateId)" "moved state not re-forced on release"
assert_contains "$(bash "$L" get run7 '.drift_log[0].field')" stateId "moved state → drift logged"

# IMPORTANT 3 regression: release --comment is redaction-gated BEFORE any
# Linear read/write — a finding must leave Linear completely untouched
# (no partial release), not just gate the eventual comment body.
setup issue_postclaim_ok.json run8
bash "$C" claim NPPM-2993 run8 >/dev/null
log_before="$(cat "$AUTOFIX_LINEAR_MOCK_DIR/requests.log")"
bash "$C" release NPPM-2993 run8 --comment "see https://mc.a8c.com/secret-store/?secret_id=1 for creds" \
  >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "redacted release comment aborts"
assert_eq "$log_before" "$(cat "$AUTOFIX_LINEAR_MOCK_DIR/requests.log")" \
  "requests.log unchanged after the failed release — redaction gate runs before any Linear read/write"
# FAIL CLOSED: explicitly empty --comment dies at parse, before any Linear I/O
log_before="$(cat "$AUTOFIX_LINEAR_MOCK_DIR/requests.log")"
out="$(bash "$C" release NPPM-2993 run8 --fail-label --comment "" 2>&1)" && rc=0 || rc=$?
assert_eq 1 "$rc" "empty --comment refuses the release"
assert_contains "$out" "empty" "empty --comment named in the error"
assert_eq "$log_before" "$(cat "$AUTOFIX_LINEAR_MOCK_DIR/requests.log")" \
  "requests.log unchanged — empty-comment die precedes any Linear read/write"
# Linear can serve a read that predates a write made a moment earlier, so the
# post-claim check re-reads before it calls a race lost. A sequence directory
# serves its files in order, repeating the last.
ME=56b3262a-bf16-4c9f-8c0f-8580fc5f6fea; BACKLOG=7fad47f0-3b4a-42ca-bf01-41a979e4cbbe; INPROG=f00ac312-3e9b-4fc2-a119-1a7cd68ece00
postclaim() { # assignee-json state-id
  printf '{ "data": { "issue": { "id": "abc-123", "identifier": "NPPM-2993", "assignee": %s, "state": { "id": "%s", "name": "x" }, "labels": { "nodes": [] }, "comments": { "nodes": [] } } } }\n' "$1" "$2"
}
forge_run() { # run id, then postclaim bodies in the order they are served
  local rid="$1" i=0; shift
  setup issue_postclaim_ok.json "$rid"; rm -f "$AUTOFIX_LINEAR_MOCK_DIR/issue_postclaim.json"
  bash "$L" init "$rid" NPPM-2993 forge >/dev/null 2>&1 || true
  bash "$L" set "$rid" '.mode = "forge" | .launch = "confirmed"'
  mkdir -p "$AUTOFIX_LINEAR_MOCK_DIR/issue_postclaim.seq"
  for body in "$@"; do i=$((i+1)); printf '%s\n' "$body" > "$AUTOFIX_LINEAR_MOCK_DIR/issue_postclaim.seq/$i.json"; done
}
reads() { grep -c '^issue_postclaim' "$AUTOFIX_LINEAR_MOCK_DIR/requests.log"; }

forge_run rf1 "$(postclaim null "$BACKLOG")" "$(postclaim "{\"id\":\"$ME\"}" "$INPROG")"
bash "$C" claim NPPM-2993 rf1 >/dev/null 2>&1; rc=$?
assert_eq 0 "$rc" "a stale first read does not lose a claim that landed"
assert_eq 2 "$(reads)" "it reads again until the claim shows"
assert_eq "" "$(bash "$L" get rf1 '.terminal // empty')" "and the run is not marked as bailed"

forge_run rf2 "$(postclaim null "$BACKLOG")"
bash "$C" claim NPPM-2993 rf2 >/dev/null 2>&1; rc=$?
assert_eq 5 "$rc" "a claim that never shows is still a lost race"
assert_eq 5 "$(reads)" "after every re-read"
# A read showing the prior values may be stale over someone else's write, so
# the back-off writes nothing: the claim's own update is the only one.
assert_eq 1 "$(grep -c '^issueUpdate' "$AUTOFIX_LINEAR_MOCK_DIR/requests.log")" \
  "and its back-off writes nothing it cannot see is still ours"

# A read that fails says nothing about the claim: the run dies open, with no
# back-off and no terminal state.
forge_run rf6
bash "$C" claim NPPM-2993 rf6 >/dev/null 2>&1; rc=$?
assert_eq 1 "$rc" "a failed post-claim read is not a lost race"
assert_eq "" "$(bash "$L" get rf6 '.terminal // empty')" "and the run is left open for a restore"
assert_eq 1 "$(grep -c '^issueUpdate' "$AUTOFIX_LINEAR_MOCK_DIR/requests.log")" "and nothing is backed off"

# A stale read of an issue that was already assigned shows the old assignee.
forge_run rf5 "$(postclaim '{"id":"teammate-x"}' "$BACKLOG")" "$(postclaim "{\"id\":\"$ME\"}" "$INPROG")"
jq '.data.issue.assignee = {"id":"teammate-x"}' fixtures/issue_ok.json > "$AUTOFIX_LINEAR_MOCK_DIR/issue.json"
bash "$C" claim NPPM-2993 rf5 >/dev/null 2>&1; rc=$?
assert_eq 0 "$rc" "a stale read showing the prior assignee does not lose the claim"
assert_eq 2 "$(reads)" "it reads again until the claim shows"

forge_run rf3 "$(postclaim '{"id":"somebody-else"}' "$INPROG")"
bash "$C" claim NPPM-2993 rf3 >/dev/null 2>&1; rc=$?
assert_eq 5 "$rc" "another assignee loses the race"
assert_eq 1 "$(reads)" "at once, without re-reading"
last="$(grep issueUpdate "$AUTOFIX_LINEAR_MOCK_DIR/requests.log" | tail -1)"
assert_eq 0 "$(printf '%s' "$last" | grep -c assigneeId)" "and the back-off leaves their assignment alone"

forge_run rf4 "$(postclaim null "$BACKLOG")"
out="$(bash "$C" claim NPPM-2993 rf4 2>&1)"
assert_eq 0 "$(printf '%s' "$out" | grep -c 'secure')" "a forge run's lost race is not logged as secure"

finish
