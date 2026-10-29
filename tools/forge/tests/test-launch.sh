#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh; . ./setup.sh
FX=../../autofix/tests/fixtures
# tooling repo with the real hook, so the export carries it
REPO="$(mktemp -d)"; export FORGE_TOOLING_REPO="$REPO" AUTOFIX_WORKSPACE_ROOT="$REPO"
g() { git -C "$REPO" -c user.name=t -c user.email=t@example.com "$@"; }
g init -q -b main; mkdir -p "$REPO/tools/forge/hooks"
cp ../hooks/mcp-read-only.sh "$REPO/tools/forge/hooks/"; cp ../mcp-read-tools.txt "$REPO/tools/forge/"
g add -A; g commit -qm tooling
export FORGE_PLUGINS=""
M="$(mktemp -d)"; export AUTOFIX_LINEAR_MOCK_DIR="$M"
cp "$FX/viewer.json" "$FX/states.json" "$M/"; cp "$FX/issue_ok.json" "$M/issue.json"
forge() { bash "$F" "$@" 2>/dev/null; }
rid_of() { sed -n 's/^RUN_ID=//p' <<<"$1"; }
nruns() { local n=0 d; for d in "$RUNS_DIR"/forge-*; do [ -d "$d" ] && n=$((n + 1)); done; echo "$n"; }

# happy path
alive
out="$(forge launch NPPM-1)"; rc=$?
assert_eq 0 "$rc" "launch exits 0"
assert_contains "$out" "LAUNCHED" "launch reports LAUNCHED"
r1="$(rid_of "$out")"
assert_contains "$r1" "forge-nppm-1-" "run id is forge-<issue>-..."
assert_eq forge "$(bash "$L" get "$r1" .mode)" "ledger is in forge mode"
assert_eq confirmed "$(bash "$L" get "$r1" .launch)" "launch is confirmed"
assert_eq newspack-workspace "$(bash "$L" get "$r1" '.decisions[] | select(.key=="profile") | .value')" "default profile recorded"
assert_eq "$FORGE_POOL_ROOT/newspack-workspace/1" "$(bash "$L" get "$r1" .pool_dir)" "pool dir 1 taken"
assert_eq "$(g rev-parse main)" "$(bash "$L" get "$r1" .tooling.sha)" "tooling pinned at main"
assert_eq 1a2b3c4d "$(bash "$L" get "$r1" .session.id)" "session id recorded"
assert_eq yes "$([ -f "$RUNS_DIR/$r1/settings.json" ] && echo yes || echo no)" "settings written"
assert_contains "$(cat "$RUNS_DIR/$r1/brief.md")" "$(bash "$L" get "$r1" .tooling.dir)" "brief points at the pinned tooling"
assert_contains "$(grep -- '--bg' "$STUB_LOG" | tail -1)" "$FORGE_POOL_ROOT/newspack-workspace/1|" "session started in the pool dir"
assert_eq "" "$(grep issueUpdate "$M/requests.log" 2>/dev/null || true)" "the dispatcher claims nothing"

# slot busy → queued, nothing created
out="$(forge launch NPPM-2)"
assert_contains "$out" "QUEUED NPPM-2" "a busy slot queues the issue"
assert_eq 1 "$(nruns)" "a queued issue gets no ledger"
assert_eq "issue NPPM-2 newspack-workspace" "$(head -1 "$FORGE_STATE/queue")" "queue line recorded"

# same issue again → refused
bash "$L" set "$r1" '.delivery = {kind:"delivered", at:"2026-10-29T00:00:00Z"}'
out="$(forge launch nppm-1)"; rc=$?
assert_eq 5 "$rc" "an issue with an unretired run is refused"
assert_contains "$out" "ALREADY" "and says so"

# Security label → routed, nothing written
cp "$FX/issue_security.json" "$M/issue.json"
out="$(forge launch NPPM-3)"; rc=$?
assert_eq 2 "$rc" "a Security-labeled issue exits 2"
assert_contains "$out" "ROUTED-SECURE NPPM-3" "and is routed to autofix-secure"
assert_contains "$(cat "$FORGE_STATE/secure-queue")" NPPM-3 "and queued for a supervised session"
assert_eq 1 "$(nruns)" "and gets no ledger"

# existing PR
cp "$FX/issue_pr.json" "$M/issue.json"
out="$(forge launch NPPM-4)"; rc=$?
assert_eq 3 "$rc" "an existing PR exits 3"
cp "$FX/issue_ok.json" "$M/issue.json"

# untrusted pool dir
out="$(STUB_BG_OUT='Workspace not trusted. Run `claude` in /x once' bash "$F" launch NPPM-5 2>/dev/null)"; rc=$?
assert_eq 4 "$rc" "an untrusted dir abandons the launch"
assert_contains "$out" "not trusted" "and tells the operator what to do"
r5="$(basename "$RUNS_DIR"/forge-nppm-5-*)"
assert_eq bailed-untrusted "$(bash "$L" get "$r5" .terminal)" "the run is terminal"
assert_eq abandoned "$(bash "$L" get "$r5" .launch)" "launch=abandoned"
bash "$L" set "$r5" '.retired_at = "2026-10-29T00:00:00Z"'   # free the issue for the next case
out="$(STUB_BG_RC=1 STUB_BG_OUT='Workspace not trusted. Run `claude` in /x once' bash "$F" launch NPPM-5 2>/dev/null)"; rc=$?
assert_eq 4 "$rc" "an untrusted dir abandons the launch when claude exits non-zero"
assert_contains "$out" "not trusted" "and still tells the operator what to do"
r5b=""; for d in "$RUNS_DIR"/forge-nppm-5-*; do [ "$(basename "$d")" = "$r5" ] || r5b="$(basename "$d")"; done
assert_eq bailed-untrusted "$(bash "$L" get "$r5b" .terminal)" "and records it as untrusted"

# session never seen
echo '[]' > "$STUB_AGENTS"; : > "$STUB_LOG"
out="$(forge launch NPPM-6)"; rc=$?
assert_eq 4 "$rc" "an unseen session abandons the launch"
assert_contains "$(cat "$STUB_LOG")" "stop 1a2b3c4d" "and stops the session"

# bad input
forge launch 'NPPM-1;rm' >/dev/null; assert_eq 1 $? "a malformed issue id is refused"
forge launch NPPM-7 --profile drafted-thing >/dev/null; assert_eq 1 $? "an unkept profile is refused"

# a no-env profile gets its worktree inside the pool dir
alive
out="$(forge launch NPPM-8 --profile newspack-workspace-tooling)"; rc=$?
assert_eq 0 "$rc" "a tooling-profile launch succeeds"
r8="$(rid_of "$out")"
wt="$(bash "$L" get "$r8" .worktree)"
assert_eq "$FORGE_POOL_ROOT/newspack-workspace-tooling/1/wt" "$wt" "worktree inside the pool dir"
assert_eq "$(g rev-parse main)" "$(git -C "$wt" rev-parse HEAD)" "cut from the profile's base"
assert_eq "$(bash "$L" get "$r8" .branch)" "$(git -C "$wt" branch --show-current)" "on the recorded branch"

# a failed no-env launch removes the worktree it made
bash "$L" set "$r8" '.delivery = {kind:"delivered", at:"2026-10-29T00:00:00Z"}'
out="$(STUB_BG_OUT='Workspace not trusted.' bash "$F" launch NPPM-9 --profile newspack-workspace-tooling 2>/dev/null)"
r9="$(basename "$RUNS_DIR"/forge-nppm-9-*)"
b9="$(bash "$L" get "$r9" .branch)"
assert_eq no "$([ -e "$FORGE_POOL_ROOT/newspack-workspace-tooling/2/wt" ] && echo yes || echo no)" "the abandoned worktree is removed"
assert_eq "" "$(g branch --list "$b9")" "and its unused branch deleted"

# next launches the queue's head once the slot is free
alive
out="$(forge next)"
assert_contains "$out" "LAUNCHED" "next launches the queued issue"
assert_contains "$(rid_of "$out")" "forge-nppm-2-" "the one that was queued first"
assert_eq "" "$(cat "$FORGE_STATE/queue")" "and removes it from the queue"
out="$(forge next)"
assert_contains "$out" "SLOT-BUSY" "next waits while the slot is held"

# an unseen no-env session removes the worktree it made, so its dir is reusable
for lf in "$RUNS_DIR"/forge-*/ledger.json; do
  r="$(basename "$(dirname "$lf")")"
  [ "$(bash "$L" get "$r" '.terminal == null and .delivery == null')" = true ] \
    && bash "$L" set "$r" '.delivery = {kind:"delivered", at:"2026-10-29T00:00:00Z"}'
done
echo '[]' > "$STUB_AGENTS"
out="$(forge launch NPPM-10 --profile newspack-workspace-tooling)"; rc=$?
assert_eq 4 "$rc" "an unseen no-env session abandons the launch"
assert_eq no "$([ -e "$FORGE_POOL_ROOT/newspack-workspace-tooling/2/wt" ] && echo yes || echo no)" "and removes the worktree it made"
alive
out="$(forge launch NPPM-11 --profile newspack-workspace-tooling)"; rc=$?
assert_eq 0 "$rc" "so the next launch into that dir succeeds"
assert_eq "$FORGE_POOL_ROOT/newspack-workspace-tooling/2/wt" "$(bash "$L" get "$(rid_of "$out")" .worktree)" "in the same dir"
assert_contains "$(bash "$L" get "$(rid_of "$out")" '.launch_started_at // ""')" "T" "a launch records when it started"

finish
