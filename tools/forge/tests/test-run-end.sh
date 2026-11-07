#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh; . ./setup.sh
FX=../../autofix/tests/fixtures
R=../bin/forge-run
fr() { bash "$R" "$@" 2>/dev/null; }
mock() { # rid issue-json-file
  M="$(mktemp -d)"; export AUTOFIX_LINEAR_MOCK_DIR="$M"
  cp "$FX/viewer.json" "$FX/states.json" "$FX/issueUpdate.json" "$M/"
  cp "$2" "$M/issue.json"
  sed "s/RUNID/$1/" "$FX/issue_postclaim_ok.json" > "$M/issue_postclaim.json"
  sed "s/RUNID/$1/" "$FX/issue_release_unchanged.json" > "$M/issue_release.json"
}
updates() { [ -f "$M/requests.log" ] || { echo 0; return; }; grep -c issueUpdate "$M/requests.log" || true; }
# Each run gets its own state root: they all work NPPM-2993, and autofix's
# same-issue guard rightly refuses a claim while another run of it is open.
fresh() { AUTOFIX_ROOT="$(mktemp -d)"; export AUTOFIX_ROOT; RUNS_DIR="$AUTOFIX_ROOT/runs"; }
claimed() { # rid — a confirmed forge run holding its claim
  fresh; bash "$L" init "$1" NPPM-2993 forge >/dev/null; bash "$L" set "$1" '.launch = "confirmed"'
  mock "$1" "$FX/issue_ok.json"; bash ../../autofix/bin/claim.sh claim NPPM-2993 "$1" >/dev/null 2>&1
}
packet() { # rid heading... -> path of a packet in the run dir
  local p; p="$(dirname "$(bash "$L" path "$1")")/packet.md"; shift
  { echo "# Forge run"; for h in "$@"; do printf '%s\nx\n' "$h"; done; } > "$p"; printf '%s' "$p"
}
FULL=("## Contested decisions" "## What changed and why" "## Friction" "## Choices")

# security-check
claimed f-sc
out="$(fr security-check f-sc)"; rc=$?
assert_eq 0 "$rc" "security-check passes a clean issue"; assert_contains "$out" CLEAN "and says so"
cp "$FX/issue_security.json" "$M/issue.json"
out="$(fr security-check f-sc)"; rc=$?
assert_eq 2 "$rc" "security-check exits 2 on the label"; assert_contains "$out" SECURITY "and says so"

# stop
claimed f-s1
fr stop f-s1 "$(packet f-s1 "## Why it stopped")" scope grew >/dev/null; rc=$?
assert_eq 1 "$rc" "stop refuses a packet with no Friction section"
assert_eq null "$(bash "$L" get f-s1 .delivery)" "and changes nothing"
outside="$(mktemp)"; printf '## Friction\nx\n' > "$outside"
fr stop f-s1 "$outside" scope grew >/dev/null; rc=$?
assert_eq 1 "$rc" "stop refuses a packet outside the run directory"
printf '## Friction\nx\n' > "$RUNS_DIR/escape.md"
fr stop f-s1 "$(dirname "$(bash "$L" path f-s1)")/../escape.md" scope >/dev/null; rc=$?
assert_eq 1 "$rc" "and a path that climbs out of it"
p="$(packet f-s1 "## Why it stopped" "## Friction")"
real() { printf '%s/%s' "$(cd "$(dirname "$1")" && pwd -P)" "$(basename "$1")"; }
out="$(fr stop f-s1 "$p" scope grew past the spec)"; rc=$?
assert_eq 0 "$rc" "stop succeeds with a packet"
assert_contains "$out" "STOPPED f-s1" "and says so"
assert_eq stopped "$(bash "$L" get f-s1 .delivery.kind)" "the run is stopped"
assert_eq "scope grew past the spec" "$(bash "$L" get f-s1 .delivery.reason)" "with its reason"
assert_eq "$(real "$p")" "$(bash "$L" get f-s1 .delivery.packet)" "and its packet"
assert_eq null "$(bash "$L" get f-s1 .terminal)" "and is not terminal, so it can be reworked"
assert_eq 2 "$(updates)" "the claim is restored (claim and restore)"
assert_eq "" "$(grep commentCreate "$M/requests.log" || true)" "with no comment"
fr stop f-s1 "$p" again >/dev/null; rc=$?
assert_eq 1 "$rc" "a run that ended cannot stop again"

fresh; bash "$L" init f-s2 NPPM-2993 forge >/dev/null; mock f-s2 "$FX/issue_ok.json"
fr stop f-s2 "$(packet f-s2 "## Why it stopped" "## Friction")" held by someone else >/dev/null; rc=$?
assert_eq 0 "$rc" "a run that never claimed can stop"
assert_eq 0 "$(updates)" "with no Linear write"

# env.sh create escalates a run it can't provision; the stop must still
# release the claim, or the issue stays assigned and In Progress.
claimed f-se; bash "$L" set f-se '.terminal = "escalated"'
out="$(fr stop f-se "$(packet f-se "## Why it stopped" "## Friction")" build blocked)"; rc=$?
assert_eq 0 "$rc" "an escalated run can still stop"
assert_eq 2 "$(updates)" "and its claim is restored"
assert_eq stopped "$(bash "$L" get f-se .delivery.kind)" "and the stop is recorded"
fr stop f-se "$(packet f-se "## Why it stopped" "## Friction")" again >/dev/null; rc=$?
assert_eq 1 "$rc" "an escalated run that stopped cannot stop again"
# A run launched on the issue after this one escalated owns the claim now, and
# carries the same assignee and state. A late stop must leave it alone.
claimed f-sl; bash "$L" set f-sl '.terminal = "escalated"'
bash "$L" init f-sl2 NPPM-2993 forge >/dev/null
out="$(bash "$R" stop f-sl "$(packet f-sl "## Why it stopped" "## Friction")" build blocked 2>&1)"; rc=$?
assert_eq 0 "$rc" "a late stop of an escalated run still stops"
assert_eq 1 "$(updates)" "but leaves a later run's claim alone"
assert_contains "$out" "f-sl2" "and names the run that holds it"
assert_eq stopped "$(bash "$L" get f-sl .delivery.kind)" "and the stop is recorded"
claimed f-sb; bash "$L" set f-sb '.terminal = "bailed-dead"'
fr stop f-sb "$(packet f-sb "## Why it stopped" "## Friction")" x >/dev/null; rc=$?
assert_eq 1 "$rc" "a run that ended any other way cannot stop"

claimed f-s3; bash "$L" set f-s3 '.fresh_test_in_progress = true'
fr stop f-s3 "$(packet f-s3 "## Why it stopped" "## Friction")" x >/dev/null; rc=$?
assert_eq 1 "$rc" "stop refuses a run inside a fresh test"
assert_eq 1 "$(updates)" "and restores nothing"

# route-secure
claimed f-rs
out="$(fr route-secure f-rs "$(packet f-rs "## Friction")")"; rc=$?
assert_eq 0 "$rc" "route-secure succeeds"
assert_contains "$out" "ROUTED-SECURE NPPM-2993" "and says so"
assert_eq routed-secure "$(bash "$L" get f-rs .terminal)" "the run is terminal, so autofix-secure can claim"
assert_eq stopped "$(bash "$L" get f-rs .delivery.kind)" "with its packet recorded"
assert_contains "$(cat "$FORGE_STATE/secure-queue")" NPPM-2993 "the issue is queued for a supervised run"
assert_eq 2 "$(updates)" "and the claim is restored"

# deliver
W="$(mktemp -d)"; git -C "$W" init -q
git -C "$W" -c user.name=t -c user.email=t@example.com commit -q --allow-empty -m one
claimed f-d
bash "$L" set f-d '.worktree = $w | .prepared = {head: $h}' --arg w "$W" --arg h "$(git -C "$W" rev-parse HEAD)"
fr deliver f-d "$(packet f-d "## Friction" "## Choices")" >/dev/null; rc=$?
assert_eq 1 "$rc" "deliver refuses a packet with no Contested decisions"
cp "$FX/issue_security.json" "$M/issue.json"
out="$(fr deliver f-d "$(packet f-d "${FULL[@]}")")"; rc=$?
assert_eq 2 "$rc" "deliver refuses an issue that gained the Security label"
assert_contains "$out" SECURITY "and says so"
assert_eq null "$(bash "$L" get f-d .delivery)" "and delivers nothing"
cp "$FX/issue_ok.json" "$M/issue.json"
git -C "$W" -c user.name=t -c user.email=t@example.com commit -q --allow-empty -m two
fr deliver f-d "$(packet f-d "${FULL[@]}")" >/dev/null; rc=$?
assert_eq 1 "$rc" "deliver refuses a branch that moved since prepare"
bash "$L" set f-d '.prepared.head = $h' --arg h "$(git -C "$W" rev-parse HEAD)"
p="$(packet f-d "${FULL[@]}")"
out="$(fr deliver f-d "$p")"; rc=$?
assert_eq 0 "$rc" "deliver succeeds"
assert_contains "$out" "DELIVERED f-d" "and says so"
assert_eq delivered "$(bash "$L" get f-d .delivery.kind)" "the run is delivered"
assert_eq "$(real "$p")" "$(bash "$L" get f-d .delivery.packet)" "with its packet"
assert_eq 1 "$(updates)" "and keeps its claim"

fresh; bash "$L" init f-nc NPPM-2993 forge >/dev/null; mock f-nc "$FX/issue_ok.json"
bash "$L" set f-nc '.worktree = $w | .prepared = {head: $h}' --arg w "$W" --arg h "$(git -C "$W" rev-parse HEAD)"
fr deliver f-nc "$(packet f-nc "${FULL[@]}")" >/dev/null; rc=$?
assert_eq 1 "$rc" "deliver refuses a run that does not hold its claim"
claimed f-np
fr deliver f-np "$(packet f-np "${FULL[@]}")" >/dev/null; rc=$?
assert_eq 1 "$rc" "deliver refuses a run with no pr.sh prepare record"

# stop needs the heading the dispatcher reads first
claimed f-w
fr stop f-w "$(packet f-w "## Friction")" scope grew >/dev/null; rc=$?
assert_eq 1 "$rc" "stop refuses a packet with no Why it stopped section"

# deliver refuses a body changed since prepare
claimed f-b
B="$(dirname "$(bash "$L" path f-b)")/pr-body.md"; echo body > "$B"
bash "$L" set f-b '.worktree = $w | .prepared = {head: $h, files: [{role: "body", path: $b, sha256: $d}]}' \
  --arg w "$W" --arg h "$(git -C "$W" rev-parse HEAD)" --arg b "$B" --arg d "$(shasum -a 256 "$B" | awk '{print $1}')"
echo changed >> "$B"
out="$(bash "$R" deliver f-b "$(packet f-b "${FULL[@]}")" 2>&1)"; rc=$?
assert_eq 1 "$rc" "deliver refuses a body changed since prepare"
assert_contains "$out" "changed since pr.sh prepare" "and says so"

# a claim that wrote but never recorded itself is restored
fresh; bash "$L" init f-u NPPM-2993 forge >/dev/null; mock f-u "$FX/issue_ok.json"
# a rework: the earlier session claimed and restored, and the new session's
# claim wrote but failed before recording itself
bash "$L" set f-u '.launch = "confirmed" | .linear_prior = {assigneeId: null, stateId: "7fad47f0-3b4a-42ca-bf01-41a979e4cbbe", claimed_state_id: "f00ac312-3e9b-4fc2-a119-1a7cd68ece00"}'
bash "$L" history f-u intake claimed x; bash "$L" history f-u release restored x; bash "$L" history f-u intake claiming ""
fr stop f-u "$(packet f-u "## Why it stopped" "## Friction")" claim did not record >/dev/null
assert_eq 1 "$(updates)" "a claim that wrote but never recorded is restored by stop"

finish
